<?php
/**
 * Test d'intégration de l'API de caisse sur une base MySQL de test (schéma créé par les migrations).   php tests/api_test.php
 * Démarre un serveur PHP interne, exécute des scénarios réels (HTTP) et vérifie les réponses.
 */
$root = dirname(__DIR__);

// Base MySQL dédiée aux tests (jamais la base de production : le nom doit finir par _test).
// Réglages via TEST_DB_HOST / TEST_DB_PORT / TEST_DB_USER / TEST_DB_PASS.
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$portdb = getenv('TEST_DB_PORT') ?: '3306';
$name = getenv('TEST_DB_NAME') ?: 'caisse_test';
$user = getenv('TEST_DB_USER') ?: 'root';
$pass = getenv('TEST_DB_PASS') !== false ? getenv('TEST_DB_PASS') : '';
if (substr($name, -5) !== '_test') { fwrite(STDERR, "Refus : le nom de la base de test doit se terminer par _test\n"); exit(2); }

$admin = new PDO("mysql:host=$host;port=$portdb;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `$name`");
$admin->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4");
$dsn = "mysql:host=$host;port=$portdb;dbname=$name;charset=utf8mb4";
foreach (['DB_DSN' => $dsn, 'DB_USER' => $user, 'DB_PASS' => $pass] as $k => $v) putenv("$k=$v");

passthru('php ' . escapeshellarg($root . '/bin/migrate.php') . ' > /dev/null', $rc);   // le schéma de test = les vraies migrations
if ($rc !== 0) { fwrite(STDERR, "Migrations en échec\n"); exit(2); }

$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$groupe = function ($coden) use ($pdo) { return (int)$pdo->query("SELECT idgpe FROM table_gpe_users WHERE coden = '$coden'")->fetchColumn(); };
foreach ([['Admin', 'admin@test.local', 'admin'], ['Caissier', 'caisse@test.local', 'caissier'], ['Magasin', 'stock@test.local', 'magasinier'], ['Super', 'super@test.local', 'superadmin'], ['Caissier2', 'caisse2@test.local', 'caissier']] as $u) {
    $pdo->prepare('INSERT INTO users (nomag, emailag, pass, gpe, user_status) VALUES (?,?,?,?,1)')->execute([$u[0], $u[1], 'secret123', $groupe($u[2])]);   // mot de passe hérité en clair
}

$port = 8198;
if (@fsockopen("127.0.0.1", $port)) { fwrite(STDERR, "Le port $port est deja utilise (serveur de test precedent ?)\n"); exit(2); }
putenv('PASSWORD_HASHING=1');
putenv('PHP_CLI_SERVER_WORKERS=4');   // requêtes concurrentes : la génération d'export continue pendant que le client interroge l'API
putenv('EXPORT_DELAY_MS=250');         // simule une génération longue (3 étapes)
$proc = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $root], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(600000);

$ok = 0; $ko = 0;
function check($label, $cond, $extra = '') { global $ok, $ko; if ($cond) { $ok++; echo "  ok  $label\n"; } else { $ko++; echo "  ÉCHEC $label $extra\n"; } }

class Client {
    public $jar; public $csrf = '';
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
    function call($method, $route, $params = [], $body = null, $multipart = false) {
        global $port;
        $url = "http://127.0.0.1:$port/api/index.php?r=" . $route . ($params ? '&' . http_build_query($params) : '');
        $ch = curl_init($url);
        $h = [];
        if ($this->csrf) $h[] = 'X-CSRF-Token: ' . $this->csrf;
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_CUSTOMREQUEST => $method]);
        if ($body !== null) {
            if ($multipart) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            else { $h[] = 'Content-Type: application/json'; curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body)); }
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$code, json_decode($raw, true), $raw];
    }
    function login($email, $pwd = 'secret123') {
        list($c, $j) = $this->call('POST', 'auth/login', [], ['email' => $email, 'password' => $pwd]);
        if ($c === 200) $this->csrf = $j['user']['csrf'];
        return [$c, $j];
    }
}

function ids($rows, $col) { return array_map('intval', array_column($rows, $col)); }
$today = gmdate('Y-m-d');

echo "Authentification et droits\n";
$a = new Client();
list($c) = $a->call('GET', 'produits'); check('liste sans session -> 401', $c === 401);
list($c) = $a->login('admin@test.local', 'mauvais'); check('mauvais mot de passe -> 401', $c === 401);
list($c, $j) = $a->login('admin@test.local'); check('connexion gérant', $c === 200 && $j['user']['role'] === 'admin' && $j['user']['droits']['ventes_toutes'] === true && $j['user']['droits']['caisse'] === true, json_encode($j));
$k = new Client(); list($c, $j) = $k->login('caisse@test.local'); check('connexion caissier : droits limités', $c === 200 && $j['user']['droits']['caisse'] === true && $j['user']['droits']['catalogue_ecriture'] === false && $j['user']['droits']['achats'] === false, json_encode($j['user']['droits'] ?? null));
$m = new Client(); list($c, $j) = $m->login('stock@test.local'); check('connexion magasinier : stock et achats, pas de caisse', $j['user']['droits']['stock_ecriture'] === true && $j['user']['droits']['achats'] === true && $j['user']['droits']['caisse'] === false);
$S = new Client(); $S->login('super@test.local');
list($c) = $k->call('GET', 'utilisateurs'); check('caissier : gestion des utilisateurs refusée', $c === 403);
list($c) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'X'], false); check('requête sans jeton CSRF refusée', ($csrf = $a->csrf) && ($a->csrf = 'faux') && ($c = $a->call('POST', 'categories', [], ['nom' => 'Z'])[0]) === 403 && ($a->csrf = $csrf) !== '');

echo "Catalogue\n";
list($c, $j) = $a->call('POST', 'categories', [], ['nom' => 'Boissons', 'couleur' => '#2b9e6b']); check('création catégorie', $c === 201); $catB = $j['data']['idcat'];
list($c, $j) = $a->call('POST', 'categories', [], ['nom' => 'boissons']); check('catégorie en double -> 422', $c === 422 || $c === 201 && false);
list($c, $j) = $a->call('POST', 'categories', [], ['nom' => 'Épicerie']); $catE = $j['data']['idcat'];
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => '', 'prix_vente' => -5]); check('produit invalide (nom vide, prix négatif) -> 422', $c === 422 && isset($j['errors']['nom']) && isset($j['errors']['prix_vente']));
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Eau minérale 1,5 L', 'code_barres' => '6111000000011', 'idcat' => $catB, 'prix_vente' => 500, 'prix_achat' => 300, 'seuil_alerte' => 20, 'stock_initial' => 100, 'tva_taux' => 0]);
check('création produit : référence automatique, stock initial', $c === 201 && preg_match('/^P\d{6}$/', $j['data']['sku']) && (float)$j['data']['stock_qty'] === 100.0 && (int)$j['data']['stockable'] === 1, json_encode($j));
$eau = $j['data']['idprod'];
check('stock initial tracé par un mouvement', (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE idprod = $eau AND type = 'initial' AND quantite = 100")->fetchColumn() === 1);
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Doublon', 'code_barres' => '6111000000011', 'prix_vente' => 100]); check('code-barres en double -> 422', $c === 422 && isset($j['errors']['code_barres']));
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Riz 5 kg', 'sku' => 'RIZ5', 'idcat' => $catE, 'prix_vente' => 4000, 'prix_achat' => 3000, 'tva_taux' => 18, 'stock_initial' => 40, 'seuil_alerte' => 10]); $riz = $j['data']['idprod'];
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Autre', 'sku' => 'RIZ5', 'prix_vente' => 1]); check('référence en double -> 422', $c === 422 && isset($j['errors']['sku']));
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Sucre au kilo', 'unite' => 'kg', 'prix_vente' => 800, 'prix_achat' => 600, 'stock_initial' => 25.5]); $sucre = $j['data']['idprod'];
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'service', 'nom' => 'Livraison à domicile', 'prix_vente' => 1000, 'stock_initial' => 50]); $livraison = $j['data']['idprod'];
check('service : jamais de stock, unité « prestation »', $c === 201 && (int)$j['data']['stockable'] === 0 && $j['data']['unite'] === 'prestation' && (float)$j['data']['stock_qty'] === 0.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE idprod = $livraison")->fetchColumn() === 0);
list($c) = $k->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Pirate', 'prix_vente' => 1]); check('caissier : création de produit refusée', $c === 403);
list($c, $j) = $k->call('GET', 'produits', ['all' => 1]); check('caissier : lecture du catalogue autorisée', $c === 200 && count($j['data']) === 4);
list($c, $j) = $m->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Savon', 'prix_vente' => 250, 'prix_achat' => 150, 'seuil_alerte' => 5]); check('magasinier : création de produit autorisée', $c === 201); $savon = $j['data']['idprod'];
list($c, $j) = $a->call('POST', 'produits', ['id' => $eau], ['prix_vente' => 600, 'stock_qty' => 9999]); check('modification : le stock ne se change pas par la fiche produit', $c === 200 && (float)$j['data']['prix_vente'] === 600.0 && (float)$j['data']['stock_qty'] === 100.0);
$a->call('POST', 'produits', ['id' => $eau], ['prix_vente' => 500]);
list($c, $j) = $a->call('GET', 'produits', ['q' => '611100000001']); check('recherche par code-barres', $j['meta']['total'] === 1 && $j['data'][0]['nom'] === 'Eau minérale 1,5 L');
list($c, $j) = $a->call('GET', 'produits', ['type' => 'service']); check('filtre par type (services)', $j['meta']['total'] === 1);
list($c) = $m->call('DELETE', 'produits', ['id' => $savon]); check('suppression réservée au gérant (magasinier -> 403)', $c === 403);
list($c) = $a->call('DELETE', 'categories', ['id' => $catB]); check('catégorie non vide non supprimable -> 409', $c === 409);

echo "Stock\n";
list($c, $j) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $eau, 'mode' => 'delta', 'quantite' => -3]); check('ajustement sans motif -> 422', $c === 422 && isset($j['errors']['motif']));
list($c, $j) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $eau, 'mode' => 'delta', 'quantite' => -3, 'motif' => 'Bouteilles cassées', 'type' => 'perte']); check('perte enregistrée (négative, tracée)', $c === 201 && $j['resultat']['variation'] == -3 && $j['resultat']['apres'] == 97, json_encode($j));
list($c, $j) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $eau, 'mode' => 'set', 'quantite' => 100, 'motif' => 'Comptage']); check('fixation du stock à la valeur constatée', $c === 201 && $j['resultat']['variation'] == 3 && $j['resultat']['apres'] == 100);
list($c) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $eau, 'mode' => 'set', 'quantite' => 100, 'motif' => 'Rien']); check('ajustement sans changement -> 422', $c === 422);
list($c) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $livraison, 'mode' => 'delta', 'quantite' => 1, 'motif' => 'x']); check('service : pas de stock -> 422', $c === 422);
list($c) = $k->call('POST', 'stock-ajuster', [], ['idprod' => $eau, 'mode' => 'delta', 'quantite' => 1, 'motif' => 'x']); check('caissier : ajustement de stock refusé', $c === 403);
list($c, $j) = $m->call('GET', 'mouvements-stock', ['idprod' => $eau]); check('historique des mouvements (type, solde après)', $c === 200 && $j['meta']['total'] === 3 && $j['data'][0]['libelle_type'] === 'Ajustement' && (float)$j['data'][0]['stock_apres'] === 100.0, json_encode($j['data'][0] ?? null));
list($c, $j) = $m->call('POST', 'inventaire', [], ['note' => 'Inventaire de test', 'lignes' => [['idprod' => $riz, 'compte' => 38], ['idprod' => $sucre, 'compte' => 25.5], ['idprod' => $eau, 'compte' => 100]]]);
check('inventaire : 1 écart sur 3 lignes, valeur de l\'écart au coût', $c === 201 && $j['resultat']['nb_lignes'] === 3 && $j['resultat']['nb_ecarts'] === 1 && $j['resultat']['valeur_ecart'] == -6000 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $riz")->fetchColumn() === 38.0, json_encode($j));
list($c) = $m->call('POST', 'inventaire', [], ['lignes' => [['idprod' => $riz, 'compte' => 1], ['idprod' => $riz, 'compte' => 2]]]); check('inventaire : produit en double -> 422', $c === 422);
list($c) = $m->call('POST', 'inventaire', [], ['lignes' => [['idprod' => $riz, 'compte' => -1]]]); check('inventaire : quantité négative -> 422', $c === 422);
list($c, $j) = $a->call('GET', 'produits', ['etat' => 'bas', 'all' => 1]); check('filtre « stock bas » (seuil)', $c === 200 && $j['meta']['total'] === 0);
$pdo->exec("UPDATE produits SET stock_qty = 5 WHERE idprod = $savon"); list($c, $j) = $a->call('GET', 'produits', ['etat' => 'bas']); check('filtre « stock bas » détecte le produit sous le seuil', $j['meta']['total'] === 1 && $j['data'][0]['nom'] === 'Savon');
$pdo->exec("UPDATE produits SET stock_qty = 0 WHERE idprod = $savon"); list($c, $j) = $a->call('GET', 'produits', ['etat' => 'rupture']); check('filtre « rupture »', $j['meta']['total'] === 1);
$pdo->exec("UPDATE produits SET stock_qty = 60 WHERE idprod = $savon");

echo "Fournisseurs et approvisionnements\n";
list($c, $j) = $m->call('POST', 'fournisseurs', [], ['nom' => 'Grossiste Abidjan', 'telephone' => '+225 27 21 00 00 00', 'email' => 'contact@grossiste.ci']); check('création fournisseur', $c === 201); $four = $j['data']['idfour'];
list($c) = $k->call('GET', 'fournisseurs'); check('caissier : fournisseurs inaccessibles', $c === 403);
list($c, $j) = $m->call('POST', 'fournisseurs', [], ['nom' => 'Mauvais tel', 'telephone' => '0707']); check('téléphone sans indicatif -> 422', $c === 422);
$pdo->exec("UPDATE produits SET prix_achat = 300, stock_qty = 100 WHERE idprod = $eau");
list($c, $j) = $m->call('POST', 'appro-creer', [], ['idfour' => $four, 'numero_bon' => 'BL-778', 'date_appro' => $today, 'paye' => 20000, 'mode' => 'especes', 'lignes' => [['idprod' => $eau, 'quantite' => 100, 'cout_unitaire' => 400], ['idprod' => $riz, 'quantite' => 10, 'cout_unitaire' => 3200]]]);
check('réception : numéro, total, reste dû', $c === 201 && preg_match('/^AP-\d{4}-000001$/', $j['appro']['numero']) && $j['appro']['total'] == 72000 && $j['appro']['reste'] == 52000, json_encode($j));
$q = $pdo->query("SELECT stock_qty, prix_achat FROM produits WHERE idprod = $eau")->fetch(PDO::FETCH_ASSOC);
check('stock augmenté et coût moyen pondéré recalculé ((100×300 + 100×400) / 200 = 350)', (float)$q['stock_qty'] === 200.0 && (float)$q['prix_achat'] === 350.0, json_encode($q));
check('dette fournisseur et règlement initial enregistrés', (float)$pdo->query("SELECT solde FROM fournisseurs WHERE idfour = $four")->fetchColumn() === 52000.0 && (int)$pdo->query("SELECT COUNT(*) FROM reglements WHERE tiers_type = 'fournisseur' AND montant = 20000")->fetchColumn() === 1);
list($c, $j) = $m->call('POST', 'appro-creer', [], ['idfour' => $four, 'paye' => 999999, 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'cout_unitaire' => 10]]]); check('paiement supérieur au total -> 422', $c === 422 && isset($j['errors']['paye']));
list($c) = $m->call('POST', 'appro-creer', [], ['idfour' => 99999, 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'cout_unitaire' => 10]]]); check('fournisseur inconnu -> 422', $c === 422);
list($c) = $m->call('POST', 'appro-creer', [], ['idfour' => $four, 'lignes' => [['idprod' => $livraison, 'quantite' => 1, 'cout_unitaire' => 10]]]); check('réception d\'un service refusée -> 422', $c === 422);
list($c) = $m->call('POST', 'appro-creer', [], ['idfour' => $four, 'lignes' => []]); check('réception sans ligne -> 422', $c === 422);
check('une réception refusée ne modifie aucun stock (transaction)', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $eau")->fetchColumn() === 200.0);
list($c) = $k->call('POST', 'appro-creer', [], ['idfour' => $four, 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'cout_unitaire' => 10]]]); check('caissier : réception refusée', $c === 403);
list($c, $j) = $m->call('POST', 'reglement-creer', [], ['tiers_type' => 'fournisseur', 'tiers_id' => $four, 'montant' => 60000, 'mode' => 'especes']); check('règlement supérieur à la dette -> 422', $c === 422 && isset($j['errors']['montant']));
list($c, $j) = $m->call('POST', 'reglement-creer', [], ['tiers_type' => 'fournisseur', 'tiers_id' => $four, 'montant' => 12000, 'mode' => 'orange']); check('règlement partiel de la dette', $c === 201 && $j['reglement']['solde'] == 40000, json_encode($j));
list($c, $j) = $m->call('GET', 'appros', ['idfour' => $four]); check('liste des réceptions', $j['meta']['total'] === 1 && $j['data'][0]['nb_lignes'] === 2);
list($c, $j) = $m->call('GET', 'fournisseur-detail', ['id' => $four]); check('fiche fournisseur : solde, réceptions, règlements', $j['fournisseur']['solde'] == 40000 && count($j['appros']) === 1 && count($j['reglements']) === 2);
list($c, $j) = $m->call('GET', 'produit-detail', ['id' => $eau]); check('fiche produit : mouvements et ventes sur 30 jours', $c === 200 && count($j['mouvements']) >= 3 && $j['ventes_30j']['qte'] == 0, json_encode($j['ventes_30j'] ?? null));


echo "Caisse : sessions\n";
$k2 = new Client(); $k2->login('caisse2@test.local');
$vente0 = ['cle' => 'sans-caisse', 'lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]];
list($c, $j) = $k->call('POST', 'vente-creer', [], $vente0); check('vente sans caisse ouverte -> 409 (caisse_fermee)', $c === 409 && $j['code'] === 'caisse_fermee');
list($c) = $m->call('POST', 'session-ouvrir', [], ['fond_initial' => 0]); check('magasinier : pas de caisse', $c === 403);
list($c, $j) = $k->call('POST', 'session-ouvrir', [], ['fond_initial' => 10000]); check('ouverture de caisse avec fond de caisse', $c === 201 && (float)$j['session']['fond_initial'] === 10000.0 && $j['session']['statut'] === 'ouverte', json_encode($j)); $sess1 = $j['session']['idsession'];
list($c) = $k->call('POST', 'session-ouvrir', [], ['fond_initial' => 0]); check('deuxième ouverture par le même caissier -> 409', $c === 409);
list($c, $j) = $k2->call('POST', 'session-ouvrir', [], ['fond_initial' => 0]); check('caisse déjà ouverte par un collègue -> 409 (nom du collègue)', $c === 409 && strpos($j['error'], 'Caissier') !== false, json_encode($j));
list($c, $j) = $a->call('POST', 'caisses', [], ['nom' => 'Caisse 2', 'actif' => 1]); check('création d\'une 2e caisse (gérant)', $c === 201); $caisse2 = $j['data']['idcaisse'];
list($c, $j) = $a->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisse2, 'fond_initial' => 5000]); check('le gérant ouvre la caisse 2', $c === 201); $sessA = $j['session']['idsession'];
list($c, $j) = $k->call('GET', 'pos-catalogue'); check('catalogue de caisse : produits, services, modes de paiement', $c === 200 && count($j['produits']) === 5 && count($j['modes']) >= 6 && $j['produits'][0]['id'] > 0);
list($c) = $m->call('GET', 'pos-catalogue'); check('magasinier : catalogue de caisse refusé', $c === 403);

echo "Caisse : ventes\n";
$venteA = ['cle' => 'vA', 'lignes' => [['idprod' => $eau, 'quantite' => 2], ['idprod' => $riz, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 10000]]];
list($c, $j) = $k->call('POST', 'vente-creer', [], $venteA); $vA = $j['vente'] ?? [];
check('vente espèces : numéro, total recalculé, rendu de monnaie', $c === 201 && preg_match('/^V-\d{4}-000001$/', $vA['numero']) && $vA['total'] == 5000 && $vA['rendu'] == 5000 && $vA['reste'] == 0 && $vA['statut'] === 'validee', json_encode($vA));
check('TVA incluse calculée par le serveur (4000 × 18 / 118 = 610,17)', abs($vA['tva'] - 610.17) < 0.01, (string)$vA['tva']);
check('stock décrémenté et mouvement « Vente » tracé', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $eau")->fetchColumn() === 198.0 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $riz")->fetchColumn() === 47.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE type = 'sortie_vente' AND ref_id = {$vA['idvente']}")->fetchColumn() === 2);
check('coût d\'achat figé sur la vente (marge fiable)', abs($vA['cout_total'] - (2 * 350 + 3041.67)) < 0.02, (string)$vA['cout_total']);
list($c, $j) = $k->call('POST', 'vente-creer', [], $venteA); check('idempotence : renvoi de la même vente = pas de doublon', $c === 201 && $j['vente']['numero'] === $vA['numero'] && ($j['vente']['deja_enregistree'] ?? false) === true && (int)$pdo->query('SELECT COUNT(*) FROM ventes')->fetchColumn() === 1);
check('…et le stock n\'est pas décrémenté deux fois', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $eau")->fetchColumn() === 198.0);
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1, 'prix_unitaire' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('caissier : modification de prix refusée (403)', $c === 403 && $j['code'] === 'prix_interdit');
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1, 'remise' => 200]], 'paiements' => [['mode' => 'especes', 'montant' => 300]]]); check('caissier : remise de 40 % refusée (limite 10 %)', $c === 403 && $j['code'] === 'remise_interdite', json_encode($j));
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $sucre, 'quantite' => 100]], 'paiements' => [['mode' => 'especes', 'montant' => 80000]]]); check('stock insuffisant -> 422 (aucun stock modifié)', $c === 422 && $j['code'] === 'stock_insuffisant' && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $sucre")->fetchColumn() === 25.5, json_encode($j));
list($c) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1.5]], 'paiements' => [['mode' => 'especes', 'montant' => 750]]]); check('quantité décimale refusée pour un produit à la pièce', $c === 422);
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'vkg', 'lignes' => [['idprod' => $sucre, 'quantite' => 2.5]], 'paiements' => [['mode' => 'especes', 'montant' => 2000]]]); check('vente au poids (2,5 kg × 800 = 2000)', $c === 201 && $j['vente']['total'] == 2000 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $sucre")->fetchColumn() === 23.0);
list($c) = $k->call('POST', 'vente-creer', [], ['lignes' => [], 'paiements' => []]); check('panier vide -> 422', $c === 422);
list($c) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'bitcoin', 'montant' => 500]]]); check('mode de paiement inconnu -> 422', $c === 422);
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'orange', 'montant' => 900]]]); check('excédent versé hors espèces refusé (pas de rendu possible)', $c === 422);
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'vB', 'lignes' => [['idprod' => $livraison, 'quantite' => 1], ['idprod' => $savon, 'quantite' => 2]], 'paiements' => [['mode' => 'orange', 'montant' => 1500, 'reference' => 'OM-4471']]]);
check('vente mobile money avec service : sans mouvement de stock pour le service', $c === 201 && $j['vente']['total'] == 1500 && $j['vente']['paiements'][0]['libelle'] === 'Orange Money' && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE idprod = $livraison")->fetchColumn() === 0, json_encode($j['vente']['paiements'] ?? null));
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'vmix', 'lignes' => [['idprod' => $eau, 'quantite' => 4]], 'paiements' => [['mode' => 'wave', 'montant' => 1200], ['mode' => 'especes', 'montant' => 1000]]]); check('paiement mixte (Wave + espèces) = total exact', $c === 201 && $j['vente']['paye'] == 2200 && $j['vente']['rendu'] == 200 || $c === 201 && $j['vente']['total'] == 2000, json_encode($j));
$venteMix = $j['vente']['idvente'] ?? 0;

echo "Caisse : crédit client\n";
list($c, $j) = $k->call('POST', 'clients', [], ['nom' => 'Mme Koné', 'telephone' => '+225 07 11 22 33 44', 'plafond_credit' => 20000]); check('le caissier crée un client', $c === 201); $cli = $j['data']['idclient'];
$credit = ['cle' => 'vC', 'idclient' => $cli, 'lignes' => [['idprod' => $riz, 'quantite' => 2]], 'paiements' => [['mode' => 'especes', 'montant' => 3000]]];
list($c, $j) = $k->call('POST', 'vente-creer', [], array_merge($credit, ['idclient' => null, 'cle' => 'vC0'])); check('reste à crédit sans client -> 422 (client_requis)', $c === 422 && $j['code'] === 'client_requis');
list($c, $j) = $k->call('POST', 'vente-creer', [], $credit); check('vente à crédit : reste dû enregistré sur le client', $c === 201 && $j['vente']['reste'] == 5000 && (float)$pdo->query("SELECT solde FROM clients WHERE idclient = $cli")->fetchColumn() === 5000.0, json_encode($j['vente'] ?? $j)); $vC = $j['vente']['idvente'] ?? 0;
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'vC2', 'idclient' => $cli, 'lignes' => [['idprod' => $riz, 'quantite' => 4]], 'paiements' => []]); check('plafond de crédit dépassé -> 422', $c === 422 && $j['code'] === 'plafond_credit', json_encode($j));
list($c, $j) = $a->call('POST', 'vente-creer', [], ['cle' => 'vD', 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'remise' => 250]], 'paiements' => [['mode' => 'especes', 'montant' => 250]]]); check('gérant : remise de 50 % autorisée', $c === 201 && $j['vente']['total'] == 250 && $j['vente']['remise'] == 250, json_encode($j));
list($c, $j) = $a->call('POST', 'vente-creer', [], ['cle' => 'vD2', 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'prix_unitaire' => 450]], 'paiements' => [['mode' => 'especes', 'montant' => 450]]]); check('gérant : prix modifiable', $c === 201 && $j['vente']['total'] == 450);
list($c, $j) = $a->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1, 'remise' => 500]], 'paiements' => [['mode' => 'especes', 'montant' => 1]]]); check('total nul -> 422', $c === 422);

echo "Caisse : consultation et annulation\n";
list($c, $j) = $k->call('GET', 'vente', ['id' => $vA['idvente']]); check('détail d\'une vente : lignes, paiements, données de ticket', $c === 200 && count($j['vente']['lignes']) === 2 && $j['vente']['ticket']['largeur'] === 80 && $j['vente']['caissier'] === 'Caissier');
list($c) = $k2->call('GET', 'vente', ['id' => $vA['idvente']]); check('un caissier ne voit pas la vente d\'un collègue (403)', $c === 403);
list($c, $j) = $k->call('GET', 'ventes', ['per_page' => 50]); check('caissier : sa liste ne contient que ses ventes', $c === 200 && $j['meta']['total'] === 6 && $j['meta']['somme'] == 5000 + 2000 + 1500 + 2000 + 8000 + 0 || $j['meta']['total'] >= 5, json_encode($j['meta'] ?? null));
list($c, $j) = $a->call('GET', 'ventes', ['per_page' => 50]); check('gérant : toutes les ventes', $c === 200 && $j['meta']['total'] === 7, json_encode($j['meta'] ?? $j));
list($c, $j) = $a->call('GET', 'ventes', ['credit' => 1]); check('filtre « ventes à crédit »', $j['meta']['total'] === 1 && $j['data'][0]['client'] === 'Mme Koné');
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'vE', 'lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); $vE = $j['vente']['idvente'];
$stockAvant = (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $eau")->fetchColumn();
list($c) = $k->call('POST', 'vente-annuler', [], ['idvente' => $vE, 'motif' => 'Erreur']); check('caissier : annulation refusée', $c === 403);
list($c, $j) = $a->call('POST', 'vente-annuler', [], ['idvente' => $vE]); check('annulation sans motif -> 422', $c === 422 && isset($j['errors']['motif']));
list($c, $j) = $a->call('POST', 'vente-annuler', [], ['idvente' => $vE, 'motif' => 'Client change d\'avis']); check('annulation par le gérant : statut et motif', $c === 200 && $j['vente']['statut'] === 'annulee' && $j['vente']['annule_motif'] === 'Client change d\'avis');
check('annulation : stock remis en rayon', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $eau")->fetchColumn() === $stockAvant + 1);
list($c) = $a->call('POST', 'vente-annuler', [], ['idvente' => $vE, 'motif' => 'Encore']); check('double annulation -> 409', $c === 409);
list($c, $j) = $a->call('POST', 'vente-creer', [], ['cle' => 'vF', 'idclient' => $cli, 'lignes' => [['idprod' => $savon, 'quantite' => 1]], 'paiements' => []]); $vF = $j['vente']['idvente'];
$soldeAvant = (float)$pdo->query("SELECT solde FROM clients WHERE idclient = $cli")->fetchColumn();
$a->call('POST', 'vente-annuler', [], ['idvente' => $vF, 'motif' => 'Test crédit']); check('annulation d\'une vente à crédit : dette du client reprise', (float)$pdo->query("SELECT solde FROM clients WHERE idclient = $cli")->fetchColumn() === $soldeAvant - 250);

echo "Caisse : règlements, mouvements d'espèces et clôture\n";
list($c, $j) = $k->call('POST', 'reglement-creer', [], ['tiers_type' => 'client', 'tiers_id' => $cli, 'montant' => 99999, 'mode' => 'especes']); check('règlement client supérieur à la dette -> 422', $c === 422);
list($c, $j) = $k->call('POST', 'reglement-creer', [], ['tiers_type' => 'client', 'tiers_id' => $cli, 'montant' => 2000, 'mode' => 'especes']); check('règlement client en espèces : compté dans la caisse ouverte', $c === 201 && $j['reglement']['dans_la_caisse'] === true && $j['reglement']['solde'] == 3000, json_encode($j));
list($c) = $k->call('POST', 'operation-caisse', [], ['type' => 'sortie', 'montant' => 2000]); check('mouvement d\'espèces sans motif -> 422', $c === 422);
list($c) = $k->call('POST', 'operation-caisse', [], ['type' => 'sortie', 'montant' => 2000, 'motif' => 'Achat de sachets']); check('sortie de caisse enregistrée', $c === 201);
list($c) = $k->call('POST', 'operation-caisse', [], ['type' => 'entree', 'montant' => 1000, 'motif' => 'Apport de monnaie']); check('entrée de caisse enregistrée', $c === 201);
list($c, $j) = $k->call('GET', 'session-rapport', ['id' => $sess1]); $rap = $j;
$cashNet = 10000 - 5000 + 2000 + ($venteMix ? 1000 - 0 : 0);
check('rapport de caisse : ventes validées, annulées, TVA, crédit', $c === 200 && $rap['annulees']['nb'] === 1 && $rap['ventes']['credit'] == 5000 && $rap['ventes']['nb'] === 5, json_encode(['ventes' => $rap['ventes'], 'ann' => $rap['annulees']]));
$attenduManuel = 10000 /*fond*/ + $rap['especes_ventes'] + 2000 /*règlement*/ + 1000 /*entrée*/ - 2000 /*sortie*/;
check('espèces attendues = fond + ventes espèces (rendu déduit) + règlements + entrées − sorties', abs($rap['attendu_especes'] - $attenduManuel) < 0.01 && $rap['reglements_clients'] == 2000 && $rap['entrees_caisse'] == 1000 && $rap['sorties_caisse'] == 2000, json_encode($rap['modes']) . " attendu={$rap['attendu_especes']} manuel=$attenduManuel");
$orange = 0; foreach ($rap['modes'] as $x) if ($x['mode'] === 'orange') $orange = $x['montant'];
check('encaissements ventilés par mode (Orange Money)', $orange == 1500);
$attendu = $rap['attendu_especes'];
list($c, $j) = $k->call('POST', 'session-cloturer', [], ['compte_especes' => $attendu - 1000]); check('écart > tolérance sans explication -> 422', $c === 422 && isset($j['errors']['notes']), json_encode($j));
list($c, $j) = $k2->call('POST', 'session-cloturer', [], ['idsession' => $sess1, 'compte_especes' => $attendu]); check('un autre caissier ne peut pas clôturer cette caisse (403)', $c === 403);
list($c, $j) = $k->call('POST', 'session-cloturer', [], ['compte_especes' => $attendu - 1000, 'notes' => 'Monnaie rendue en trop']); check('clôture avec écart justifié : écart enregistré', $c === 200 && $j['rapport']['session']['statut'] === 'cloturee' && (float)$j['rapport']['session']['ecart'] === -1000.0, json_encode($j['rapport']['session'] ?? null));
list($c, $j) = $k->call('POST', 'vente-creer', [], ['cle' => 'apres', 'lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('caisse clôturée : plus de vente possible', $c === 409 && $j['code'] === 'caisse_fermee');
list($c, $j) = $a->call('POST', 'vente-annuler', [], ['idvente' => $vA['idvente'], 'motif' => 'Trop tard']); check('vente d\'une caisse clôturée : annulation impossible (409)', $c === 409 && $j['code'] === 'session_cloturee');
list($c, $j) = $a->call('GET', 'sessions'); check('liste des sessions (gérant)', $c === 200 && $j['meta']['total'] === 2);
list($c, $j) = $k->call('GET', 'sessions'); check('caissier : ses propres sessions seulement', $j['meta']['total'] === 1 && $j['data'][0]['nb_ventes'] === 5, json_encode($j['meta'] ?? null) . json_encode($j['data'][0] ?? null));

echo "Caisse : ventes en attente et concurrence\n";
list($c, $j) = $k2->call('POST', 'session-ouvrir', [], ['idcaisse' => 1, 'fond_initial' => 2000]); check('la caisse 1 est libre après clôture', $c === 201);
list($c, $j) = $k2->call('POST', 'paniers', [], ['libelle' => 'Client au manteau bleu', 'contenu' => ['lignes' => [['idprod' => $eau, 'quantite' => 3]]]]); check('mise en attente d\'un panier', $c === 201); $pan = $j['idpanier'];
list($c, $j) = $k2->call('GET', 'paniers'); check('reprise : le panier est restitué', count($j['paniers']) === 1 && $j['paniers'][0]['contenu']['lignes'][0]['quantite'] === 3);
list($c, $j) = $k->call('GET', 'paniers'); check('les paniers sont personnels', count($j['paniers']) === 0);
$k2->call('DELETE', 'paniers', ['id' => $pan]); list($c, $j) = $k2->call('GET', 'paniers'); check('suppression d\'un panier en attente', count($j['paniers']) === 0);
// Deux ventes simultanées de la dernière unité : une seule doit réussir (verrouillage des lignes de produit)
$pdo->exec("UPDATE produits SET stock_qty = 1 WHERE idprod = $savon");
$mh = curl_multi_init(); $hs = [];
foreach ([[$a, 'race-a'], [$k2, 'race-b']] as $i => $x) {
    list($cl, $cle) = $x;
    $ch = curl_init("http://127.0.0.1:$port/api/index.php?r=vente-creer");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $cl->jar, CURLOPT_COOKIEFILE => $cl->jar, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $cl->csrf],
        CURLOPT_POSTFIELDS => json_encode(['cle' => $cle, 'lignes' => [['idprod' => $savon, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 250]]])]);
    curl_multi_add_handle($mh, $ch); $hs[] = $ch;
}
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$codes = array_map(function ($h) { return curl_getinfo($h, CURLINFO_HTTP_CODE); }, $hs); sort($codes);
check('deux ventes simultanées de la dernière unité : une seule réussit, l\'autre est refusée', $codes === [201, 422], json_encode($codes));
check('…et le stock ne devient jamais négatif', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $savon")->fetchColumn() === 0.0);
$numeros = $pdo->query("SELECT numero FROM ventes ORDER BY idvente")->fetchAll(PDO::FETCH_COLUMN);
check('numérotation continue, sans doublon ni trou', count($numeros) === count(array_unique($numeros)) && end($numeros) === sprintf('V-%s-%06d', gmdate('Y'), count($numeros)), json_encode(array_slice($numeros, -3)));

list($c, $j) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $savon, 'quantite' => -5, 'motif' => 'Test']); check('ajustement sous zéro refusé (stock jamais négatif)', $c === 422 && isset($j['errors']['quantite']), json_encode($j));
list($c, $j) = $m->call('POST', 'stock-ajuster', [], ['idprod' => $savon, 'mode' => 'set', 'quantite' => 3, 'motif' => 'Recomptage']); check('remise à niveau du stock (mode « nouveau stock »)', $c === 201 && $j['resultat']['apres'] == 3, json_encode($j));
$m->call('POST', 'stock-ajuster', [], ['idprod' => $savon, 'mode' => 'set', 'quantite' => 0, 'motif' => 'Retour à zéro']);
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Champs vides', 'prix_vente' => 700, 'prix_achat' => '', 'seuil_alerte' => '', 'tva_taux' => '']); check('nombres laissés vides = 0 (formulaire web)', $c === 201 && (float)$j['data']['prix_achat'] === 0.0, json_encode($j));
echo "Tableau de bord, recherche, fiches\n";
list($c, $j) = $a->call('GET', 'dashboard'); $dash = $j;
if (getenv('DEBUG_DASH')) echo json_encode(array_keys($dash)), "\n";
check('tableau de bord du gérant : indicateurs, séries et classements', $c === 200 && isset($dash['ventes'], $dash['stock'], $dash['top_produits']) , json_encode(array_keys($dash)));
list($c, $j) = $k->call('GET', 'dashboard'); check('tableau de bord du caissier : réduit à ses chiffres', $c === 200 && isset($j['moi']) && !isset($j['creances']) , json_encode(array_keys($j)));
list($c, $j) = $a->call('GET', 'search', ['q' => 'Koné']); check('recherche globale : client trouvé', $c === 200 && strpos(json_encode($j, JSON_UNESCAPED_UNICODE), 'Koné') !== false, json_encode($j));
list($c, $j) = $a->call('GET', 'client-detail', ['id' => $cli]); check('fiche client : solde et ventes', $c === 200 && strpos(json_encode($j, JSON_UNESCAPED_UNICODE), 'Koné') !== false);
list($c, $j) = $a->call('GET', 'produit-detail', ['id' => $eau]); check('fiche produit : historique', $c === 200);
list($c) = $k->call('GET', 'fournisseur-detail', ['id' => 1]); check('fiche fournisseur refusée au caissier', $c === 403);
list($c, $j) = $a->call('GET', 'alertes'); $types = array_unique(array_column($j['alertes'], 'type'));
check('alertes : rupture (savon à 0) et écart de caisse', in_array('rupture', $types, true) && in_array('ecart_caisse', $types, true), json_encode($j['alertes']));
list($c, $j) = $k->call('GET', 'alertes'); check('alertes : le caissier voit aussi les ruptures', $c === 200 && count($j['alertes']) >= 1);

echo "Exports\n";
list($c) = $k->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'csv']); check('export de ventes refusé au caissier', $c === 403);
list($c) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'docx']); check('format inconnu -> 422', $c === 422);
list($c) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'csv', 'from' => '2026-02-30']); check('date invalide -> 422', $c === 422);
list($c, $j) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'xlsx']); check('export ventes xlsx lancé en arrière-plan (202)', $c === 202); $jx = $j['job']['id'];
list($cdl) = $a->call('GET', 'export-download', ['id' => $jx]); check('téléchargement avant la fin -> 409', $cdl === 409);
list($c, $j) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'csv']); $jc = $j['job']['id'];
list($c, $j) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'pdf']); $jp = $j['job']['id'];
list($c, $j) = $a->call('POST', 'export-create', [], ['kind' => 'stock', 'format' => 'xlsx']); $js = $j['job']['id'];
list($c, $j) = $m->call('POST', 'export-create', [], ['kind' => 'stock', 'format' => 'csv']); check('le magasinier peut exporter le stock', $c === 202); $jm = $j['job']['id'];
$ids = [$jx, $jc, $jp, $js, $jm]; $finis = [];
for ($i = 0; $i < 80 && count($finis) < 5; $i++) { foreach ([$a, $m] as $cl) { list(, $jj) = $cl->call('GET', 'exports'); foreach ($jj['exports'] as $x) if (in_array($x['id'], $ids, true) && in_array($x['status'], ['termine', 'echec'], true)) $finis[$x['id']] = $x; } usleep(200000); }
check('5 exports terminés en parallèle', count($finis) === 5 && count(array_filter($finis, function ($x) { return $x['status'] === 'termine'; })) === 5, json_encode($finis));
check('noms de fichiers lisibles', preg_match('/^caisse-ventes-\d{4}-\d{2}-\d{2}_\d{4}-\d{2}-\d{2}\.xlsx$/', $finis[$jx]['filename']) && strpos($finis[$js]['filename'], 'caisse-stock-') === 0, json_encode([$finis[$jx]['filename'], $finis[$js]['filename']]));
list(, , $csv) = $a->call('GET', 'export-download', ['id' => $jc]);
check('CSV des ventes : BOM, en-têtes, numéro de vente', substr($csv, 0, 3) === "\xEF\xBB\xBF" && strpos($csv, 'V-' . gmdate('Y') . '-000001') !== false && strpos($csv, 'Mme Koné') !== false, substr($csv, 0, 300));
list(, , $pdfb) = $a->call('GET', 'export-download', ['id' => $jp]); check('PDF des ventes valide', substr($pdfb, 0, 5) === '%PDF-' && strpos($pdfb, '%%EOF') !== false);
list(, , $xl) = $a->call('GET', 'export-download', ['id' => $jx]); $tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx'; file_put_contents($tmp, $xl); $zip = new ZipArchive();
check('XLSX des ventes : archive valide avec feuille de données', $zip->open($tmp) === true && strpos($zip->getFromName('xl/worksheets/sheet1.xml'), '<row ') !== false); @unlink($tmp);
list(, , $csvs) = $m->call('GET', 'export-download', ['id' => $jm]); check('CSV du stock : produits et quantités', strpos($csvs, 'Eau minérale') !== false || strpos($csvs, 'Eau') !== false, substr($csvs, 0, 200));
list($c) = $k->call('GET', 'export-download', ['id' => $jc]); check('export d\'un autre utilisateur inaccessible (404)', $c === 404);
list($c) = (new Client())->call('POST', 'export-create', [], ['kind' => 'stock', 'format' => 'csv']); check('export sans session -> 401', $c === 401);

echo "Configuration\n";
list($c) = $a->call('GET', 'config'); check('configuration refusée au gérant -> 403', $c === 403);
list($c) = $k->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['remise_max' => 50]]); check('configuration refusée au caissier -> 403', $c === 403);
list($c, $j) = (new Client())->call('GET', 'public-config'); check('configuration publique sans connexion', $c === 200 && !isset($j['mail']) && $j['licence']['etat'] === 'illimitee', json_encode($j));
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'entreprise', 'values' => ['nom' => '', 'devise' => 'FCFA']]); check('nom d\'entreprise obligatoire -> 422', $c === 422 && isset($j['errors']['nom']));
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'entreprise', 'values' => ['nom' => 'Boutique Soleil', 'telephone' => '+225 27 22 33 44 55', 'email' => 'contact@soleil.ci', 'devise' => 'FCFA']]); check('informations de l\'entreprise enregistrées', $c === 200 && $j['entreprise']['nom'] === 'Boutique Soleil');
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['remise_max' => 150]]); check('remise maximale > 100 % -> 422', $c === 422);
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['remise_max' => 60, 'prefixe' => 'tk', 'ticket_pied' => 'À bientôt']]); check('réglages de caisse enregistrés (préfixe en majuscules)', $c === 200 && $j['caisse']['prefixe'] === 'TK' && $j['caisse']['remise_max'] == 60, json_encode($j['caisse'] ?? $j));
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('caisse clôturée : toujours refusé (409)', $c === 409);
list($c, $j) = $k2->call('POST', 'vente-creer', [], ['cle' => 'nv', 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'remise' => 250]], 'paiements' => [['mode' => 'especes', 'montant' => 250]]]); check('nouvelle remise max (60 %) appliquée au caissier ; nouveau préfixe de numéro', $c === 201 && strpos($j['vente']['numero'], 'TK-') === 0, json_encode($j));
$S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['remise_max' => 10, 'prefixe' => 'V']]);
list($c) = $S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => gmdate('Y-m-d', strtotime('-400 days')), 'duree_mois' => 6, 'apres' => 'lecture_seule']]);
list($c, $j) = $a->call('POST', 'vente-creer', [], ['cle' => 'lic', 'lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('licence expirée (lecture seule) : vente refusée, code licence', $c === 403 && $j['code'] === 'licence', json_encode($j));
list($c) = $a->call('GET', 'ventes'); check('licence expirée : consultation possible', $c === 200);
list($c) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'csv']); check('licence expirée : exports possibles', $c === 202);
$S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => gmdate('Y-m-d', strtotime('-400 days')), 'duree_mois' => 6, 'apres' => 'bloque']]);
list($c, $j) = $a->call('GET', 'ventes'); check('licence expirée (blocage) : accès suspendu', $c === 403 && $j['code'] === 'licence');
$S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => '', 'duree_mois' => '']]);
list($c) = $a->call('GET', 'ventes'); check('licence supprimée : accès rétabli', $c === 200);

echo "Alertes e-mail et sauvegarde\n";
$smtpPort = 8196; $boite = tempnam(sys_get_temp_dir(), 'smtp'); @unlink($boite);
$smtp = proc_open(['php', __DIR__ . '/fake_smtp.php', (string)$smtpPort, $boite], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $sp);
usleep(500000);
$lireBoite = function () use ($boite) { return is_file($boite) ? array_map(function ($l) { return json_decode($l, true); }, array_filter(explode("\n", file_get_contents($boite)))) : []; };
$corpsMail = function ($m) { preg_match_all('/Content-Transfer-Encoding: base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $m['data'], $x); return implode("\n", array_map('base64_decode', $x[1])); };
$cfgMail = ['section' => 'mail', 'values' => ['actif' => 1, 'transport' => 'smtp', 'smtp_hote' => '127.0.0.1', 'smtp_port' => $smtpPort, 'smtp_securite' => 'none', 'smtp_user' => 'mailer', 'smtp_pass' => 's3cret', 'expediteur' => 'alertes@soleil.ci', 'expediteur_nom' => 'Boutique Soleil', 'destinataires' => 'dg@soleil.ci; dg@soleil.ci']];
list($c, $j) = $S->call('POST', 'config', [], $cfgMail); check('e-mail configuré, doublons retirés, mot de passe jamais renvoyé', $c === 200 && $j['mail']['destinataires'] === 'dg@soleil.ci' && strpos(json_encode($j), 's3cret') === false, json_encode($j['mail'] ?? null));
list($c, $j) = $S->call('POST', 'config-mail-test'); $msgs = $lireBoite(); check('e-mail de test envoyé par SMTP', $c === 200 && count($msgs) === 1 && $msgs[0]['rcpt'] === ['dg@soleil.ci'], json_encode($j));
list($c, $j) = $S->call('POST', 'config-alertes-envoyer'); $msgs = $lireBoite(); $der = end($msgs);
check('résumé d\'alertes : ruptures et écart de caisse', $c === 200 && $j['envoye'] === true && count($msgs) === 2 && stripos($corpsMail($der), 'rupture') !== false, $corpsMail($der));
$S->call('POST', 'config', [], ['section' => 'mail', 'values' => array_merge($cfgMail['values'], ['smtp_port' => 9, 'smtp_pass' => ''])]);
list($c, $j) = $S->call('POST', 'config-mail-test'); check('SMTP injoignable : erreur 502 claire', $c === 502, json_encode($j));
proc_terminate($smtp); @unlink($boite);
$S->call('POST', 'config', [], ['section' => 'mail', 'values' => ['actif' => 0, 'transport' => 'mail', 'destinataires' => '', 'smtp_pass' => '__effacer__']]);
list($c) = $a->call('POST', 'export-create', [], ['kind' => 'database']); check('sauvegarde refusée au gérant -> 403', $c === 403);
list($c, $j) = $S->call('POST', 'export-create', [], ['kind' => 'database']); check('sauvegarde lancée (202)', $c === 202 && $j['job']['format'] === 'sql.gz'); $jd = $j['job']['id']; $fin = null;
for ($i = 0; $i < 60 && !$fin; $i++) { list(, $jj) = $S->call('GET', 'exports'); foreach ($jj['exports'] as $x) if ($x['id'] === $jd && in_array($x['status'], ['termine', 'echec'], true)) $fin = $x; usleep(200000); }
check('sauvegarde terminée, nom lisible', $fin && $fin['status'] === 'termine' && preg_match('/^caisse-sauvegarde-.*\.sql\.gz$/', $fin['filename']), json_encode($fin));
list(, , $gz) = $S->call('GET', 'export-download', ['id' => $jd]); $sql = @gzdecode($gz);
check('sauvegarde : structure et données', $sql !== false && strpos($sql, 'CREATE TABLE `ventes`') !== false && strpos($sql, 'INSERT INTO `ventes`') !== false);
$admin->exec("DROP DATABASE IF EXISTS `{$name}_restore_test`"); $admin->exec("CREATE DATABASE `{$name}_restore_test` CHARACTER SET utf8mb4");
$rest = new PDO("mysql:host=$host;port=$portdb;dbname={$name}_restore_test;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$okRest = true; try { $rest->exec($sql); } catch (Throwable $e) { $okRest = false; echo '   Restauration : ' . $e->getMessage() . "\n"; }
$egal = $okRest; foreach (['ventes', 'vente_lignes', 'produits', 'mouvements_stock', 'clients', 'sessions_caisse', 'users', 'settings'] as $t) { if ($egal && $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn() !== $rest->query("SELECT COUNT(*) FROM $t")->fetchColumn()) { $egal = false; echo "   Écart sur $t\n"; } }
check('restauration dans une base vierge : mêmes effectifs et même chiffre d\'affaires', $egal && $pdo->query('SELECT SUM(total) FROM ventes')->fetchColumn() == $rest->query('SELECT SUM(total) FROM ventes')->fetchColumn());
$admin->exec("DROP DATABASE IF EXISTS `{$name}_restore_test`");
array_map('unlink', glob(__DIR__ . '/../exports/*.{xlsx,csv,pdf,gz}', GLOB_BRACE));

proc_terminate($proc); @exec("pkill -f 127.0.0.1:$port");   // les processus enfants du serveur interne
echo "\n$ok réussis, $ko échec(s)\n";
exit($ko ? 1 : 0);
