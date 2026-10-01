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
list($c, $j) = $k->call('POST', 'operation-caisse', [], ['type' => 'sortie', 'montant' => 2000, 'motif' => 'Achat de sachets', 'categorie' => 'fournitures', 'reference' => 'RECU-88']); check('sortie de caisse enregistrée', $c === 201 && preg_match('/^MC-\d{4}-\d{6}$/', $j['operation']['numero']));
list($c, $j) = $k->call('POST', 'operation-caisse', [], ['type' => 'sortie', 'montant' => 100, 'motif' => 'Sans catégorie']); check('mouvement sans catégorie -> 422', $c === 422 && isset($j['errors']['categorie']));
list($c) = $k->call('POST', 'operation-caisse', [], ['type' => 'sortie', 'montant' => 100, 'motif' => 'Mauvaise catégorie', 'categorie' => 'apport_fonds']); check('catégorie d\'entrée refusée pour une sortie -> 422', $c === 422);
list($c) = $k->call('POST', 'operation-caisse', [], ['type' => 'entree', 'montant' => 1000, 'motif' => 'Apport de monnaie', 'categorie' => 'apport_fonds']); check('entrée de caisse enregistrée', $c === 201);
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
$tq = $dash['top_quantite'] ?? []; $tm = $dash['top_produits'];
check('performance des produits : classements par nombre et par montant', count($tq) > 0 && count($tq) === count($tm) && $tq[0]['quantite'] === max(array_column($tq, 'quantite')) && $tm[0]['ca'] === max(array_column($tm, 'ca')) && isset($tq[0]['unite'], $tq[0]['nb_ventes']), json_encode([$tq, $tm]));
list($c, $j) = $k->call('GET', 'dashboard'); check('tableau de bord du caissier : réduit à ses chiffres', $c === 200 && isset($j['moi']) && !isset($j['creances']) , json_encode(array_keys($j)));
list($c, $j) = $a->call('GET', 'dashboard-mensuel', ['mois' => gmdate('Y-m')]);
$caJournal = 0; foreach ((array)($j['ventes'] ?? []) as $parCaisse) foreach ($parCaisse as $v) $caJournal += $v['ca'];
check('journal du mois : tous les jours, ventes par caisse = CA du mois', $c === 200 && count($j['jours']) === (int)gmdate('t') && count($j['caisses']) > 0 && abs($caJournal - $dash['ventes']['mois']['ca']) < 0.01, json_encode($j));
list($c, $j) = $k->call('GET', 'dashboard-mensuel'); check('journal du mois : refusé au caissier', $c === 403, json_encode($j));
list($c, $j) = $a->call('GET', 'stock-balance', ['mois' => gmdate('Y-m')]);
$okBal = $c === 200 && count($j['produits']) > 0; $valFin = 0;
foreach ($j['produits'] ?? [] as $pb) { $okBal = $okBal && abs($pb['initial'] + $pb['entrees'] - $pb['sorties'] + $pb['ajustements'] - $pb['final']) < 0.001; $valFin += $pb['final'] > 0 ? $pb['final'] * $pb['prix_achat'] : 0; }
check('balance des stocks : initial + entrées − sorties ± ajustements = final, final du mois = valeur du stock', $okBal && abs($valFin - $dash['stock']['valeur']) < 0.01, json_encode($j));
list($c, $j) = $a->call('GET', 'stock-balance', ['mois' => '2000-01']); $vide = $c === 200; foreach ($j['produits'] ?? [] as $pb) $vide = $vide && $pb['nb'] === 0 && $pb['entrees'] == 0;
check('balance des stocks : mois sans mouvement', $vide, json_encode($j));
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
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['ticket_entete' => "Bienvenue\nOuvert de 8h à 18h", 'ticket_pied' => "Merci\nÀ bientôt", 'ticket_largeur' => '58', 'ticket_contact' => '0']]);
check('ticket : messages sur plusieurs lignes, largeur et informations configurables', $c === 200 && $j['caisse']['ticket_entete'] === "Bienvenue\nOuvert de 8h à 18h" && $j['caisse']['ticket_pied'] === "Merci\nÀ bientôt" && $j['caisse']['ticket_contact'] === '0');
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['ticket_entete' => str_repeat('x', 1001)]]); check('ticket : limite de texte validée côté serveur', $c === 422 && isset($j['errors']['ticket_entete']));
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['ticket_largeur' => '100']]); check('ticket : largeur invalide refusée', $c === 422 && isset($j['errors']['ticket_largeur']));
list($c, $j) = $k->call('POST', 'vente-creer', [], ['lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('caisse clôturée : toujours refusé (409)', $c === 409);
list($c, $j) = $k2->call('POST', 'vente-creer', [], ['cle' => 'nv', 'lignes' => [['idprod' => $eau, 'quantite' => 1, 'remise' => 250]], 'paiements' => [['mode' => 'especes', 'montant' => 250]]]); check('nouvelle remise max (60 %) appliquée au caissier ; nouveau préfixe de numéro', $c === 201 && strpos($j['vente']['numero'], 'TK-') === 0, json_encode($j));
check('ticket de vente : mêmes messages et choix d’en-tête que les commandes', $j['vente']['ticket']['entete'] === "Bienvenue\nOuvert de 8h à 18h" && $j['vente']['ticket']['pied'] === "Merci\nÀ bientôt" && $j['vente']['ticket']['largeur'] === 58 && $j['vente']['ticket']['afficher_contact'] === false);
$S->call('POST', 'config', [], ['section' => 'caisse', 'values' => ['remise_max' => 10, 'prefixe' => 'V']]);
list($c) = $S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => gmdate('Y-m-d', strtotime('-400 days')), 'duree_mois' => 6, 'apres' => 'lecture_seule']]);
list($c, $j) = $a->call('POST', 'vente-creer', [], ['cle' => 'lic', 'lignes' => [['idprod' => $eau, 'quantite' => 1]], 'paiements' => [['mode' => 'especes', 'montant' => 500]]]); check('licence expirée (lecture seule) : vente refusée, code licence', $c === 403 && $j['code'] === 'licence', json_encode($j));
list($c) = $a->call('GET', 'ventes'); check('licence expirée : consultation possible', $c === 200);
list($c) = $a->call('POST', 'export-create', [], ['kind' => 'ventes', 'format' => 'csv']); check('licence expirée : exports possibles', $c === 202);
$S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => gmdate('Y-m-d', strtotime('-400 days')), 'duree_mois' => 6, 'apres' => 'bloque']]);
list($c, $j) = $a->call('GET', 'ventes'); check('licence expirée (blocage) : accès suspendu', $c === 403 && $j['code'] === 'licence');
$S->call('POST', 'config', [], ['section' => 'licence', 'values' => ['debut' => '', 'duree_mois' => '']]);
list($c) = $a->call('GET', 'ventes'); check('licence supprimée : accès rétabli', $c === 200);

echo "Commandes et livraisons\n";
$zAngre = (int)$pdo->query("SELECT idzone FROM zones_livraison WHERE nom = 'Angré'")->fetchColumn(); $zBouake = (int)$pdo->query("SELECT idzone FROM zones_livraison WHERE nom = 'Bouaké'")->fetchColumn();
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Jus pour commandes', 'prix_vente' => 700, 'prix_achat' => 400, 'stock_initial' => 500]); $jus = $j['data']['idprod'];
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'client_nom' => 'Awa', 'lignes' => [['idprod' => $jus, 'quantite' => 3]]]); check('livraison sans adresse -> 422', $c === 422 && isset($j['errors']['adresse']));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'abidjan', 'idzone' => $zAngre, 'client_nom' => 'Awa', 'adresse' => 'Rue 12, Cocody', 'lignes' => [['idprod' => $jus, 'quantite' => 3]]]); check('livraison sans téléphone -> 422', $c === 422 && isset($j['errors']['client_tel']));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'abidjan', 'idzone' => $zAngre, 'client_nom' => 'Awa', 'client_tel' => '0102030405', 'adresse' => 'Rue 12, Cocody', 'frais_livraison' => 1500, 'lignes' => [['idprod' => $jus, 'quantite' => 3, 'prix_unitaire' => 1]]]); check('caissier : prix modifié refusé', $c === 403);
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'abidjan', 'idzone' => $zAngre, 'client_nom' => 'Awa', 'client_tel' => '0102030405', 'adresse' => 'Rue 12, Cocody', 'lignes' => [['idprod' => $jus, 'quantite' => 3]]]);
check('commande créée : tarif de la zone appliqué (Angré 1 500), numéro, total = articles + frais', $c === 201 && (float)$j['commande']['frais_livraison'] === 1500.0 && (float)$j['commande']['frais_tarif'] === 1500.0 && $j['commande']['lieu_complet'] === 'Cocody — Angré' && $j['commande']['zone_libelle'] === 'Abidjan' && preg_match('/^CMD-\d{4}-\d{6}$/', $j['commande']['numero']) && (float)$j['commande']['total'] === 3600.0 && $j['commande']['statut'] === 'nouvelle', json_encode($j));
$cmd = $j['commande']['idcommande'];
check('stock déduit à la prise de commande, sortie tracée sans vente', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 497.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE type = 'sortie_commande' AND ref_type = 'commande' AND ref_id = $cmd AND quantite = -3")->fetchColumn() === 1 && $j['commande']['idvente'] === null);
check('ticket de commande disponible avant livraison avec les réglages communs', $j['commande']['ticket']['entete'] === "Bienvenue\nOuvert de 8h à 18h" && $j['commande']['ticket']['pied'] === "Merci\nÀ bientôt" && $j['commande']['ticket']['largeur'] === 58 && $j['commande']['ticket']['afficher_contact'] === false);
list($c, $j) = $k->call('POST', 'commande-statut', [], ['idcommande' => $cmd, 'statut' => 'en_livraison']); check('livreur obligatoire pour partir en livraison', $c === 422 && isset($j['errors']['livreur']));
list($c, $j) = $m->call('POST', 'commande-statut', [], ['idcommande' => $cmd, 'statut' => 'preparation']); check('magasinier : peut passer en préparation', $c === 200 && $j['commande']['statut'] === 'preparation');
list($c) = $m->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'X', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('magasinier : création de commande refusée', $c === 403);
list($c, $j) = $m->call('POST', 'commande-statut', [], ['idcommande' => $cmd, 'statut' => 'prete']); check('commande prête', $c === 200 && $j['commande']['statut'] === 'prete');
list($c, $j) = $k->call('POST', 'commande-statut', [], ['idcommande' => $cmd, 'statut' => 'en_livraison', 'livreur' => 'Moussa']); check('en livraison avec livreur', $c === 200 && $j['commande']['livreur'] === 'Moussa' && count($j['commande']['historique']) === 4);
list($c) = $m->call('POST', 'commande-livrer', [], ['idcommande' => $cmd]); check('magasinier : encaissement refusé', $c === 403);
// Le gérant encaisse la remise (sa caisse 2 est rouverte au besoin)
list($c, $j) = $a->call('GET', 'session-courante'); if (empty($j['session'])) $a->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisse2, 'fond_initial' => 0]);
list($c, $j) = $a->call('POST', 'commande-livrer', [], ['idcommande' => $cmd, 'receptionnaire' => 'Mme Awa Koné', 'observations' => 'Colis remis en bon état', 'paiements' => [['mode' => 'especes', 'montant' => 4000]]]);
check('livraison encaissée : vente créée, rendu de monnaie, commande livrée', $c === 200 && $j['commande']['statut'] === 'livree' && (float)$j['vente']['total'] === 3600.0 && (float)$j['vente']['rendu'] === 400.0 && $j['commande']['vente_numero'] === $j['vente']['numero'], json_encode($j));
check('fiche de livraison : réceptionnaire et observations enregistrés', $pdo->query("SELECT CONCAT(receptionnaire, '|', observations) FROM commandes WHERE idcommande = $cmd")->fetchColumn() === 'Mme Awa Koné|Colis remis en bon état');
check('livraison sans deuxième sortie de stock', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 497.0);
check('frais de livraison : une ligne de service, sans stock', (int)$pdo->query("SELECT COUNT(*) FROM vente_lignes l JOIN produits p ON p.idprod = l.idprod WHERE l.idvente = {$j['vente']['idvente']} AND p.sku = 'FRAIS-LIV' AND l.total = 1500")->fetchColumn() === 1);
list($c, $j2) = $a->call('POST', 'commande-livrer', [], ['idcommande' => $cmd, 'paiements' => [['mode' => 'especes', 'montant' => 4000]]]); check('livrer deux fois : réponse complète sans doublon ni sortie supplémentaire', $c === 200 && isset($j2['commande'], $j2['vente']) && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 497.0 && (int)$pdo->query("SELECT COUNT(*) FROM ventes WHERE note = 'Commande {$j['commande']['numero']}'")->fetchColumn() === 1);
list($c, $j) = $k->call('POST', 'commande-annuler', [], ['idcommande' => $cmd, 'motif' => 'test']); check('commande livrée non annulable', $c === 409);
list($c, $j) = $k->call('GET', 'pos-catalogue'); check('frais de livraison absents du catalogue de caisse', $c === 200 && !in_array('FRAIS-LIV', array_column($j['produits'], 'sku'), true));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Paul', 'lignes' => [['idprod' => $jus, 'quantite' => 2]]]); check('retrait : ni adresse ni frais', $c === 201 && (float)$j['commande']['total'] === 1400.0 && $j['commande']['sequence'] === ['nouvelle', 'preparation', 'prete', 'livree']); $cmd2 = $j['commande']['idcommande'];
check('retrait : stock sorti dès la création', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 495.0);
list($c) = $k->call('POST', 'commande-statut', [], ['idcommande' => $cmd2, 'statut' => 'en_livraison', 'livreur' => 'X']); check('retrait : pas d\'étape « en livraison »', $c === 422);
list($c, $j) = $k->call('POST', 'commande-annuler', [], ['idcommande' => $cmd2]); check('annulation sans motif -> 422', $c === 422);
list($c, $j) = $k->call('POST', 'commande-annuler', [], ['idcommande' => $cmd2, 'motif' => 'Client injoignable']); check('commande annulée', $c === 200 && $j['commande']['statut'] === 'annulee');
check('annulation de commande : stock restitué et mouvement tracé', (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 497.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE type = 'annulation_commande' AND ref_type = 'commande' AND ref_id = $cmd2 AND quantite = 2")->fetchColumn() === 1);
list($c) = $k->call('POST', 'commande-annuler', [], ['idcommande' => $cmd2, 'motif' => 'Double clic']); check('double annulation : aucune deuxième restitution', $c === 409 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === 497.0);
list($c, $j) = $k->call('POST', 'commande-statut', [], ['idcommande' => $cmd2, 'statut' => 'prete']); check('commande annulée : plus de changement de statut', $c === 409);
list($c, $j) = $k->call('GET', 'commandes', ['statut' => 'actives']); check('liste : commandes actives et compteurs', $c === 200 && $j['meta']['total'] === 0 && $j['meta']['comptes']['livree'] === 1 && $j['meta']['comptes']['annulee'] === 1, json_encode($j['meta'] ?? $j));
list($c, $j) = $k->call('GET', 'commandes', ['q' => 'Cocody']); check('recherche par adresse ou commune', $j['meta']['total'] === 1);
list($c, $j) = $k->call('GET', 'commandes', ['zone' => 'abidjan']); check('filtre par zone', $j['meta']['total'] === 1);
list($c, $j) = $k->call('GET', 'commandes', ['zone' => 'interieur']); check('filtre par zone : intérieur vide', $j['meta']['total'] === 0);
$stock0 = (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn();
$nbCmdAvant = (int)$pdo->query('SELECT COUNT(*) FROM commandes')->fetchColumn();
list($c, $j) = $a->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Rupture', 'lignes' => [['idprod' => $jus, 'quantite' => 9999]]]);
check('stock insuffisant : commande refusée dès la création, sans effet', $c === 422 && $j['code'] === 'stock_insuffisant' && (int)$pdo->query('SELECT COUNT(*) FROM commandes')->fetchColumn() === $nbCmdAvant && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === $stock0);
list($c, $j) = $a->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Lignes répétées', 'lignes' => [['idprod' => $jus, 'quantite' => 300], ['idprod' => $jus, 'quantite' => 300]]]);
check('stock contrôlé sur la somme des lignes du même produit', $c === 422 && $j['code'] === 'stock_insuffisant' && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $jus")->fetchColumn() === $stock0);

list($c, $j) = $k->call('GET', 'lookups'); check('grille de tarifs de livraison fournie à la caisse', $c === 200 && count($j['zones_livraison']) > 50 && count(array_filter($j['zones_livraison'], function ($z) { return $z['zone'] === 'interieur'; })) > 10);
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'abidjan', 'idzone' => $zAngre, 'frais_livraison' => 2500, 'client_nom' => 'Ibrahim', 'client_tel' => '0707070707', 'adresse' => 'Villa 4', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]);
check('prix de livraison modifié : tarif d\'origine conservé pour le contrôle', $c === 201 && (float)$j['commande']['frais_livraison'] === 2500.0 && (float)$j['commande']['frais_tarif'] === 1500.0, json_encode($j));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'interieur', 'idzone' => $zAngre, 'client_nom' => 'X', 'client_tel' => '0707070707', 'adresse' => 'Y', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('lieu d\'une autre zone refusé', $c === 422 && isset($j['errors']['idzone']));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'interieur', 'lieu' => 'Tengréla', 'client_nom' => 'X', 'client_tel' => '0707070707', 'adresse' => 'Y', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('ville hors grille sans prix -> 422 (prix à indiquer)', $c === 422 && isset($j['errors']['frais_livraison']));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'interieur', 'lieu' => 'Tengréla', 'frais_livraison' => 12000, 'client_nom' => 'X', 'client_tel' => '0707070707', 'adresse' => 'Y', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('ville hors grille avec prix saisi', $c === 201 && $j['commande']['idzone'] === null && $j['commande']['lieu_complet'] === 'Tengréla' && $j['commande']['frais_tarif'] === null);
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'interieur', 'idzone' => $zBouake, 'client_nom' => 'Y', 'client_tel' => '0707070707', 'adresse' => 'Quartier Air France', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('intérieur du pays : tarif de la ville (Bouaké 6 000)', $c === 201 && (float)$j['commande']['frais_livraison'] === 6000.0 && $j['commande']['zone_libelle'] === 'Intérieur du pays');
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'exterieur', 'frais_livraison' => 25000, 'client_nom' => 'Z', 'client_tel' => '+233201234567', 'adresse' => 'East Legon', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('extérieur : pays et ville obligatoires', $c === 422 && isset($j['errors']['lieu']));
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'livraison', 'zone' => 'exterieur', 'lieu' => 'Accra, Ghana', 'frais_livraison' => 25000, 'client_nom' => 'Z', 'client_tel' => '+233201234567', 'adresse' => 'East Legon', 'lignes' => [['idprod' => $jus, 'quantite' => 1]]]); check('extérieur : livraison à l\'étranger enregistrée', $c === 201 && strpos($j['commande']['zone_libelle'], 'Extérieur') === 0 && (float)$j['commande']['total'] === 25700.0);
list($c) = $k->call('POST', 'zones-livraison', [], ['zone' => 'abidjan', 'commune' => 'Cocody', 'nom' => 'Test', 'prix' => 1]); check('caissier : modification des tarifs refusée', $c === 403);
list($c, $j) = $a->call('POST', 'zones-livraison', [], ['zone' => 'abidjan', 'commune' => 'Cocody', 'nom' => 'Angré', 'prix' => 1]); check('tarif en double refusé', $c === 422);
list($c, $j) = $a->call('POST', 'zones-livraison', [], ['zone' => 'exterieur', 'commune' => '', 'nom' => 'Ghana', 'prix' => 25000, 'delai' => '3 à 5 jours']); check('gérant : ajoute un tarif (extérieur)', $c === 201 && $j['data']['nom'] === 'Ghana');
list($c, $j) = $k->call('GET', 'zones-livraison', ['zone' => 'exterieur']); check('caissier : lit la grille', $c === 200 && $j['meta']['total'] === 1);

// Stock de commande : services, rollback, rupture après réservation, annulation de vente et compatibilité.
list($c, $j) = $a->call('POST', 'produits', [], ['type' => 'produit', 'nom' => 'Dernières unités commandes', 'prix_vente' => 100, 'prix_achat' => 50, 'stock_initial' => 2]); $dernier = $j['data']['idprod'];
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Stock zéro', 'lignes' => [['idprod' => $dernier, 'quantite' => 2], ['idprod' => $livraison, 'quantite' => 1]]]); $cmdZero = $j['commande']['idcommande']; $totalZero = (float)$j['commande']['total'];
check('commande mixte : stock à zéro, aucun mouvement pour le service', $c === 201 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 0.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE ref_type = 'commande' AND ref_id = $cmdZero")->fetchColumn() === 1);
list($c, $j) = $a->call('POST', 'commande-livrer', [], ['idcommande' => $cmdZero, 'paiements' => [['mode' => 'inconnu', 'montant' => $totalZero]]]);
check('paiement refusé : commande et stock inchangés, aucune vente partielle', $c === 422 && $pdo->query("SELECT statut FROM commandes WHERE idcommande = $cmdZero")->fetchColumn() === 'nouvelle' && (int)$pdo->query("SELECT COUNT(*) FROM ventes WHERE cle = 'cmd$cmdZero'")->fetchColumn() === 0 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 0.0);
list($c, $j) = $a->call('POST', 'commande-livrer', [], ['idcommande' => $cmdZero, 'paiements' => [['mode' => 'especes', 'montant' => $totalZero]]]); $venteZero = $j['vente']['idvente'];
check('livraison autorisée quand tout le stock est déjà sorti à la commande', $c === 200 && $j['commande']['statut'] === 'livree' && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 0.0 && (int)$pdo->query("SELECT COUNT(*) FROM mouvements_stock WHERE type = 'sortie_vente' AND ref_type = 'vente' AND ref_id = $venteZero")->fetchColumn() === 0);
list($c) = $a->call('POST', 'vente-annuler', [], ['idvente' => $venteZero, 'motif' => 'Retour après remise']);
check('annulation de vente issue de commande : stock restitué une fois', $c === 200 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 2.0);
list($c) = $a->call('POST', 'vente-annuler', [], ['idvente' => $venteZero, 'motif' => 'Double retour']); check('double retour de vente refusé, stock inchangé', $c === 409 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 2.0);
$nbCmdAvant = (int)$pdo->query('SELECT COUNT(*) FROM commandes')->fetchColumn();
list($c, $j) = $a->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Rollback articles', 'lignes' => [['idprod' => $jus, 'quantite' => 1], ['idprod' => $dernier, 'quantite' => 3]]]);
check('commande multi articles refusée : aucun stock ni commande partiels', $c === 422 && (int)$pdo->query('SELECT COUNT(*) FROM commandes')->fetchColumn() === $nbCmdAvant && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 2.0);
list($c, $j) = $k->call('POST', 'commande-creer', [], ['mode' => 'retrait', 'client_nom' => 'Produit archivé', 'lignes' => [['idprod' => $dernier, 'quantite' => 1]]]); $cmdArchive = $j['commande']['idcommande'];
$pdo->exec("UPDATE produits SET supp = 1 WHERE idprod = $dernier");
list($c) = $k->call('POST', 'commande-annuler', [], ['idcommande' => $cmdArchive, 'motif' => 'Article archivé']); check('article archivé depuis la commande : stock tout de même restitué', $c === 200 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 2.0);
$pdo->exec("UPDATE produits SET supp = 0 WHERE idprod = $dernier");
// Simuler une commande préexistante à la migration, sans sortie anticipée.
$oldId = 900001;
$pdo->prepare("INSERT INTO commandes (idcommande, numero, mode, statut, client_nom, sous_total, total, iduser, created_at, updated_at, stock_debite) VALUES (?, ?, 'retrait', 'nouvelle', 'Ancienne commande', 100, 100, ?, NOW(), NOW(), 0)")->execute([$oldId, 'CMD-ANCIENNE', $pdo->query("SELECT id_user FROM users WHERE emailag = 'admin@test.local'")->fetchColumn()]);
$pdo->prepare("INSERT INTO commande_lignes (idcommande, idprod, designation, quantite, prix_unitaire, total) VALUES (?, ?, 'Dernières unités commandes', 1, 100, 100)")->execute([$oldId, $dernier]);
list($c, $j) = $a->call('POST', 'commande-livrer', [], ['idcommande' => $oldId, 'paiements' => [['mode' => 'especes', 'montant' => 100]]]);
check('ancienne commande : sortie à la remise conservée', $c === 200 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 1.0);
$a->call('POST', 'vente-annuler', [], ['idvente' => $j['vente']['idvente'], 'motif' => 'Fin du test']);
// Une option envoyée depuis HTTP ne doit pas contourner le contrôle de stock d'une vente.
list($c, $j) = $a->call('POST', 'vente-creer', [], ['stockDejaSorti' => true, 'stock_debite' => 1, 'lignes' => [['idprod' => $dernier, 'quantite' => 3]], 'paiements' => [['mode' => 'especes', 'montant' => 300]]]);
check('vente directe : impossible de forger le contournement de stock des commandes', $c === 422 && $j['code'] === 'stock_insuffisant');

// Requêtes concurrentes avec des sessions HTTP distinctes.
$parallel = function (array $requests) use ($port) {
    $multi = curl_multi_init(); $handles = [];
    foreach ($requests as $r) {
        list($cl, $route, $body) = $r;
        $ch = curl_init("http://127.0.0.1:$port/api/index.php?r=$route");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cl->jar, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $cl->csrf], CURLOPT_POSTFIELDS => json_encode($body)]);
        curl_multi_add_handle($multi, $ch); $handles[] = $ch;
    }
    do { curl_multi_exec($multi, $run); if ($run) curl_multi_select($multi, 0.2); } while ($run > 0);
    $out = [];
    foreach ($handles as $h) { $out[] = [curl_getinfo($h, CURLINFO_HTTP_CODE), json_decode(curl_multi_getcontent($h), true)]; curl_multi_remove_handle($multi, $h); }
    curl_multi_close($multi);
    return $out;
};
$pdo->exec("UPDATE produits SET stock_qty = 1 WHERE idprod = $dernier");
$bodyRace = ['mode' => 'retrait', 'client_nom' => 'Dernière unité concurrente', 'lignes' => [['idprod' => $dernier, 'quantite' => 1]]];
$results = $parallel([[$a, 'commande-creer', $bodyRace], [$k2, 'commande-creer', $bodyRace]]);
$codes = array_column($results, 0); sort($codes);
check('deux commandes concurrentes : une seule peut sortir la dernière unité', $codes === [201, 422] && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 0.0, json_encode($results));
foreach ($results as $r) if ($r[0] === 201) $a->call('POST', 'commande-annuler', [], ['idcommande' => $r[1]['commande']['idcommande'], 'motif' => 'Fin du test']);
list(, $j) = $a->call('POST', 'commande-creer', [], $bodyRace); $cmdRace = $j['commande']['idcommande'];
$results = $parallel([[$a, 'commande-livrer', ['idcommande' => $cmdRace, 'paiements' => [['mode' => 'especes', 'montant' => 100]]]], [$k2, 'commande-annuler', ['idcommande' => $cmdRace, 'motif' => 'Annulation concurrente']]]);
$codes = array_column($results, 0); sort($codes);
$afterRace = $pdo->query("SELECT statut, idvente FROM commandes WHERE idcommande = $cmdRace")->fetch(PDO::FETCH_ASSOC);
$stockRace = (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn();
$nbVentesRace = (int)$pdo->query("SELECT COUNT(*) FROM ventes WHERE cle = 'cmd$cmdRace'")->fetchColumn();
check('livraison et annulation concurrentes : un seul résultat, stock et vente cohérents', $codes === [200, 409] && (($afterRace['statut'] === 'livree' && $stockRace === 0.0 && $nbVentesRace === 1) || ($afterRace['statut'] === 'annulee' && $stockRace === 1.0 && $nbVentesRace === 0)), json_encode($results));
if ($afterRace['statut'] === 'livree') $a->call('POST', 'vente-annuler', [], ['idvente' => $afterRace['idvente'], 'motif' => 'Fin du test']);
$aSecond = new Client(); $aSecond->login('admin@test.local');
list(, $j) = $a->call('POST', 'commande-creer', [], $bodyRace); $cmdDouble = $j['commande']['idcommande'];
$doubleBody = ['idcommande' => $cmdDouble, 'paiements' => [['mode' => 'especes', 'montant' => 100]]];
$results = $parallel([[$a, 'commande-livrer', $doubleBody], [$aSecond, 'commande-livrer', $doubleBody]]);
check('deux remises concurrentes : même vente et aucun deuxième mouvement', array_column($results, 0) === [200, 200] && $results[0][1]['vente']['idvente'] === $results[1][1]['vente']['idvente'] && (int)$pdo->query("SELECT COUNT(*) FROM ventes WHERE cle = 'cmd$cmdDouble'")->fetchColumn() === 1 && (float)$pdo->query("SELECT stock_qty FROM produits WHERE idprod = $dernier")->fetchColumn() === 0.0, json_encode($results));
$a->call('POST', 'vente-annuler', [], ['idvente' => $results[0][1]['vente']['idvente'], 'motif' => 'Fin du test']);

echo "Mouvements d'espèces : état et rapport de caisse\n";
list($c, $j) = $a->call('GET', 'mouvements-caisse', ['from' => $today, 'to' => $today]);
check('état des mouvements (gérant) : totaux, catégories, journal', $c === 200 && $j['totaux']['entrees'] === 1000 && $j['totaux']['sorties'] === 2000 && $j['totaux']['net'] === -1000 && count($j['par_categorie']) === 2 && $j['data'][0]['numero'] !== null, json_encode($j['totaux'] ?? $j));
list(, $j) = $a->call('GET', 'mouvements-caisse', ['type' => 'sortie', 'q' => 'RECU-88']); check('filtre par sens et recherche par justificatif', $j['totaux']['nb'] === 1 && $j['data'][0]['categorie_libelle'] === 'Fournitures et petites dépenses' && $j['data'][0]['reference'] === 'RECU-88');
list($c, $j) = $k->call('GET', 'mouvements-caisse'); check('caissier : voit ses propres mouvements', $c === 200 && $j['totaux']['nb'] >= 2);
list($c) = $m->call('GET', 'mouvements-caisse'); check('magasinier : état des mouvements refusé', $c === 403);
list($c, $j) = $k2->call('GET', 'mouvements-caisse'); check('un autre caissier ne voit pas ces mouvements', $c === 200 && $j['totaux']['nb'] === 0);
list(, $j) = $a->call('GET', 'session-rapport', ['id' => $sess1]);
check('rapport de caisse : n° de Z, mouvements détaillés avec catégorie et auteur, TVA par taux, plage de tickets', preg_match('/^Z-\d{6}$/', $j['numero_z']) && $j['operations'][0]['categorie_libelle'] !== '' && $j['operations'][0]['auteur'] !== '' && count($j['tva_taux']) >= 1 && $j['tickets']['premier'] !== null && $j['ventes_ht'] > 0, json_encode(array_intersect_key($j, array_flip(['numero_z', 'tva_taux', 'tickets', 'ventes_ht']))));
check('rapport : HT + TVA = TTC par taux', array_sum(array_map(function ($t) { return abs($t['ht'] + $t['tva'] - $t['ttc']) < 0.011 ? 0 : 1; }, $j['tva_taux'])) === 0);
foreach (['xlsx', 'csv', 'pdf'] as $fmt) {
    list($c, $jj) = $a->call('POST', 'export-create', [], ['kind' => 'mouvements', 'format' => $fmt, 'from' => $today, 'to' => $today]);
    check("export des mouvements ($fmt) lancé", $c === 202, json_encode($jj));
    $jid = $jj['job']['id'] ?? 0; $fin = null;
    for ($i = 0; $i < 60 && !$fin; $i++) { list(, $x) = $a->call('GET', 'exports'); foreach ($x['exports'] as $e) if ($e['id'] === $jid && in_array($e['status'], ['termine', 'echec'], true)) $fin = $e; usleep(200000); }
    list(, , $bin) = $a->call('GET', 'export-download', ['id' => $jid]);
    check("export des mouvements ($fmt) : fichier valide", $fin && $fin['status'] === 'termine' && strlen($bin) > 200 && ($fmt !== 'pdf' || substr($bin, 0, 4) === '%PDF') && ($fmt !== 'xlsx' || substr($bin, 0, 2) === 'PK') && ($fmt !== 'csv' || strpos($bin, 'RECU-88') !== false || strpos($bin, 'Achat de sachets') !== false), json_encode($fin));
}
list($c) = $k->call('POST', 'export-create', [], ['kind' => 'mouvements', 'format' => 'pdf']); check('export des mouvements réservé au gérant', $c === 403);
foreach (['journal' => 'Cumul', 'balance' => 'Stock initial'] as $kind => $motCle) foreach (['xlsx', 'csv', 'pdf'] as $fmt) {
    list($c, $jj) = $a->call('POST', 'export-create', [], ['kind' => $kind, 'format' => $fmt, 'mois' => gmdate('Y-m')]);
    $jid = $jj['job']['id'] ?? 0; $fin = null;
    for ($i = 0; $i < 60 && !$fin; $i++) { list(, $x) = $a->call('GET', 'exports'); foreach ($x['exports'] as $e) if ($e['id'] === $jid && in_array($e['status'], ['termine', 'echec'], true)) $fin = $e; usleep(200000); }
    list(, , $bin) = $a->call('GET', 'export-download', ['id' => $jid]);
    check("export $kind ($fmt) : fichier valide", $c === 202 && $fin && $fin['status'] === 'termine' && $fin['from'] === gmdate('Y-m-01') && strpos($fin['filename'], gmdate('Y-m')) !== false && strlen($bin) > 200 && ($fmt !== 'pdf' || substr($bin, 0, 4) === '%PDF') && ($fmt !== 'xlsx' || substr($bin, 0, 2) === 'PK') && ($fmt !== 'csv' || strpos($bin, $motCle) !== false), json_encode($fin));
}
list($c, $j) = $a->call('POST', 'export-create', [], ['kind' => 'journal', 'format' => 'pdf', 'mois' => '2026-13']); check('export du journal : mois invalide refusé', $c === 422 && isset($j['errors']['mois']), json_encode($j));
list($c) = $k->call('POST', 'export-create', [], ['kind' => 'journal', 'format' => 'xlsx']); check('export du journal réservé au gérant', $c === 403);
list($c) = $m->call('POST', 'export-create', [], ['kind' => 'balance', 'format' => 'csv']); check('export de la balance des stocks : autorisé au magasinier', $c === 202);

echo "Dépenses du gérant hors caisse\n";
list(, $avant) = $a->call('GET', 'session-rapport', ['id' => $sess1]);
list(, $j) = $a->call('GET', 'mouvements-caisse', ['from' => $today, 'to' => $today]); $sortiesAvant = $j['totaux']['sorties'];
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => 15000, 'categorie' => 'charges', 'source' => 'banque', 'motif' => 'Facture électricité', 'reference' => 'CIE-2026-09']);
check('gérant : dépense hors caisse sans session ouverte, pièce numérotée', $c === 201 && preg_match('/^MC-/', $j['operation']['numero']), json_encode($j));
list(, $j) = $a->call('GET', 'mouvements-caisse', ['from' => $today, 'to' => $today]);
check('dépense hors caisse : dans l\'état des mouvements, origine indiquée', $j['totaux']['sorties'] == $sortiesAvant + 15000 && $j['data'][0]['caisse'] === 'Hors caisse — banque' && $j['data'][0]['idsession'] === null, json_encode($j['data'][0]));
list(, $j) = $a->call('GET', 'mouvements-caisse', ['idcaisse' => 'hors']); check('filtre « hors caisse »', $j['totaux']['nb'] === 1 && $j['totaux']['sorties'] == 15000, json_encode($j['totaux']));
list(, $apres) = $a->call('GET', 'session-rapport', ['id' => $sess1]);
check('dépense hors caisse : sans effet sur le rapport et le comptage de la caisse', json_encode($avant['operations']) === json_encode($apres['operations']) && ($avant['attendu_especes'] ?? null) === ($apres['attendu_especes'] ?? null));
list(, $j) = $a->call('GET', 'dashboard-mensuel', ['mois' => gmdate('Y-m')]); check('journal du mois : colonne hors caisse', ($j['hors_caisse'][$today] ?? 0) == 15000, json_encode($j['hors_caisse']));
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => 5000, 'categorie' => 'transport', 'source' => 'coffre', 'motif' => 'Taxi', 'date' => gmdate('Y-m-d', strtotime('-3 days'))]);
check('dépense hors caisse antidatée (facture saisie après coup)', $c === 201, json_encode($j));
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => 5000, 'categorie' => 'transport', 'source' => 'coffre', 'motif' => 'Taxi', 'date' => gmdate('Y-m-d', strtotime('+2 days'))]);
check('dépense hors caisse : date future refusée', $c === 422 && isset($j['errors']['date']), json_encode($j));
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => 5000, 'categorie' => 'apport_fonds', 'source' => 'paypal', 'motif' => 'x']);
check('dépense hors caisse : catégorie de sortie et origine contrôlées', $c === 422 && isset($j['errors']['categorie'], $j['errors']['source']), json_encode($j));
list($c) = $k->call('POST', 'depense-creer', [], ['montant' => 5000, 'categorie' => 'transport', 'source' => 'coffre', 'motif' => 'Taxi']); check('dépense hors caisse refusée au caissier', $c === 403);

echo "Catégories de mouvements et justificatifs\n";
list($c, $j) = $a->call('POST', 'categorie-mouvement-creer', [], ['type' => 'sortie', 'libelle' => 'Entretien du véhicule']);
$catNouv = $j['categorie']['code'] ?? '';
check('gérant : nouvelle catégorie de dépense', $c === 201 && $catNouv === 'entretien_du_vehicule' && ($j['categories_mvt']['sortie'][$catNouv] ?? '') === 'Entretien du véhicule', json_encode($j));
list($c, $j) = $a->call('POST', 'categorie-mouvement-creer', [], ['type' => 'sortie', 'libelle' => '  ENTRETIEN du vehicule ']);
check('catégorie déjà existante (casse, accents, espaces) : pas de doublon', $c === 201 && $j['categorie']['existait'] === true && $j['categorie']['code'] === $catNouv && count(array_filter($j['categories_mvt']['sortie'], function ($l) { return stripos($l, 'entretien') !== false; })) === 1, json_encode($j['categorie']));
list(, $j) = $a->call('GET', 'lookups'); check('nouvelle catégorie dans les listes', isset($j['categories_mvt']['sortie'][$catNouv]) && isset($j['categories_mvt']['sortie']['transport']));
list($c) = $k->call('POST', 'categorie-mouvement-creer', [], ['type' => 'sortie', 'libelle' => 'Divers caissier']); check('ajout de catégorie refusé au caissier', $c === 403);
list($c, $j) = $a->call('POST', 'categorie-mouvement-creer', [], ['type' => 'sortie', 'libelle' => 'x']); check('catégorie : nom trop court refusé', $c === 422 && isset($j['errors']['libelle']));

$pdf = tempnam(sys_get_temp_dir(), 'pj'); file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => '8000', 'categorie' => $catNouv, 'source' => 'mobile_money', 'motif' => 'Vidange', 'justificatif' => new CURLFile($pdf, 'application/pdf', 'facture garage.pdf')], true);
$opPj = $j['operation']['idop'] ?? 0;
check('dépense avec justificatif PDF et nouvelle catégorie', $c === 201 && $j['operation']['justificatif'] === true, json_encode($j));
list(, $j) = $a->call('GET', 'mouvements-caisse', ['q' => 'Vidange']);
check('liste : justificatif signalé, libellé de la nouvelle catégorie', $j['data'][0]['a_justificatif'] === true && $j['data'][0]['categorie_libelle'] === 'Entretien du véhicule', json_encode($j['data'][0] ?? null));
list($c, , $bin) = $a->call('GET', 'justificatif', ['id' => $opPj]); check('gérant : téléchargement du justificatif', $c === 200 && substr($bin, 0, 5) === '%PDF-');
list($c) = $k->call('GET', 'justificatif', ['id' => $opPj]); check('justificatif inaccessible à un caissier qui n\'en est pas l\'auteur', $c === 403);
check('justificatif non servi directement par le serveur web', !is_file(__DIR__ . '/../doc/justificatifs') && count(glob(__DIR__ . '/../justificatifs/*.pdf')) >= 1);
$faux = tempnam(sys_get_temp_dir(), 'pj'); file_put_contents($faux, '<?php echo 1; ?>');
list($c, $j) = $a->call('POST', 'depense-creer', [], ['montant' => '100', 'categorie' => 'transport', 'source' => 'coffre', 'motif' => 'Faux', 'justificatif' => new CURLFile($faux, 'application/pdf', 'faux.pdf')], true);
check('justificatif falsifié (contenu ≠ extension) refusé, aucune pièce créée', $c === 422 && isset($j['errors']['justificatif']), json_encode($j));
list(, $j) = $a->call('GET', 'mouvements-caisse', ['q' => 'Faux']); check('… et aucune pièce enregistrée', $j['totaux']['nb'] === 0);
list($c, $j) = $a->call('GET', 'justificatif', ['id' => $opPj - 1]); check('pièce sans justificatif : 404', $c === 404);
@unlink($pdf); @unlink($faux);
$nomPj = $pdo->query('SELECT justificatif FROM caisse_operations WHERE idop = ' . (int)$opPj)->fetchColumn(); if ($nomPj) @unlink(__DIR__ . '/../justificatifs/' . basename($nomPj));   // fichier créé par le test uniquement

echo "Clôture forcée d'une caisse (gérant, super administrateur)\n";
list(, $j) = $k2->call('GET', 'session-courante');
if (!$j['session']) {
    list($c, $jc) = $a->call('POST', 'caisses', [], ['nom' => 'Caisse forcée', 'actif' => 1]);
    list(, $j) = $k2->call('POST', 'session-ouvrir', [], ['idcaisse' => $jc['data']['idcaisse'] ?? 0, 'fond_initial' => 3000]);
}
$sessF = $j['session']['idsession'] ?? 0; $caisseF = $j['session']['idcaisse'] ?? 0;
list(, $rapF) = $a->call('GET', 'session-rapport', ['id' => $sessF]);
check('une caisse reste ouverte par un caissier', $sessF > 0 && isset($rapF['attendu_especes']), json_encode($j));
list($c) = $k2->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessF, 'motif' => 'x x x']); check('clôture forcée refusée au caissier', $c === 403);
list($c, $j) = $S->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessF, 'motif' => '']); check('clôture forcée : motif obligatoire', $c === 422 && isset($j['errors']['motif']));
list($c, $j) = $a->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessF, 'motif' => 'Caissier parti sans clôturer']);
$sf = $j['rapport']['session'] ?? [];
check('gérant : caisse clôturée sans comptage, attendu figé, auteur et motif tracés', $c === 200 && $sf['statut'] === 'cloturee' && $sf['compte_especes'] === null && $sf['ecart'] === null && abs($sf['attendu_especes'] - $rapF['attendu_especes']) < 0.01 && (int)$sf['forcee_par'] > 0 && $sf['forcee_motif'] === 'Caissier parti sans clôturer', json_encode($sf));
list(, $j) = $k2->call('GET', 'session-courante'); check('le caissier n\'a plus de caisse ouverte', $j['session'] === null);
list($c) = $S->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessF, 'motif' => 'encore']); check('caisse déjà clôturée : 409', $c === 409);
check('clôture forcée inscrite au journal d\'audit', (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'cloture_forcee' AND entity_id = " . (int)$sessF)->fetchColumn() === 1);
list(, $j) = $a->call('GET', 'sessions', ['statut' => 'cloturee']); $lf = array_values(array_filter($j['data'], function ($x) use ($sessF) { return (int)$x['idsession'] === (int)$sessF; }));
check('liste des sessions : clôture forcée et son auteur', $lf && $lf[0]['forcee_par_nom'] === 'Admin', json_encode($lf));
list($c) = $k2->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisseF, 'fond_initial' => 0]); check('la caisse peut être rouverte', $c === 201);
list(, $j) = $k2->call('GET', 'session-courante'); $k2->call('POST', 'session-cloturer', [], ['compte_especes' => 0]);
list($c, $j) = $k2->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisseF, 'fond_initial' => 0]); $sessK2 = $j['session']['idsession'] ?? 0;
list($c, $j) = $S->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisseF, 'fond_initial' => 0]);
check('caisse occupée : 409 avec code dédié et nom du collègue', $c === 409 && $j['code'] === 'caisse_occupee' && strpos($j['error'], 'Caissier2') !== false, json_encode($j));
list($c, $j) = $S->call('GET', 'caisses-occupation'); $occ = array_values(array_filter($j['occupees'], function ($o) use ($caisseF) { return $o['idcaisse'] === (int)$caisseF; }));
check('occupation des caisses : session, caissier, heure', $c === 200 && $occ && $occ[0]['idsession'] === $sessK2 && $occ[0]['caissier'] === 'Caissier2' && $occ[0]['soi'] === false, json_encode($j));
list($c) = $m->call('GET', 'caisses-occupation'); check('occupation des caisses : réservée aux rôles de caisse', $c === 403);
list($c) = $S->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessK2, 'motif' => 'Reprise de la caisse par Super']);
list($c2, $j) = $S->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisseF, 'fond_initial' => 0]);
check('super administrateur : libère la caisse puis la reprend à son nom', $c === 200 && $c2 === 201 && (int)$j['session']['idcaisse'] === (int)$caisseF, json_encode($j));
list(, $j) = $S->call('GET', 'session-courante'); $sessSuper = $j['session']['idsession'] ?? 0;
list($c, $j) = $a->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessSuper, 'motif' => 'Reprise par le gérant']);
check('gérant : session du super administrateur non forçable', $c === 403 && strpos($j['error'], 'super administrateur') !== false, json_encode($j));
list(, $j) = $a->call('GET', 'caisses-occupation'); $os = array_values(array_filter($j['occupees'], function ($o) use ($sessSuper) { return $o['idsession'] === $sessSuper; }));
check('occupation : session tenue par le super administrateur signalée', $os && $os[0]['super'] === true, json_encode($j));
$S->call('POST', 'session-cloturer', [], ['compte_especes' => 0]);
list($c, $j) = $k2->call('POST', 'session-ouvrir', [], ['idcaisse' => $caisseF, 'fond_initial' => 0]); $sessK3 = $j['session']['idsession'] ?? 0;
list($c) = $a->call('POST', 'session-forcer-cloture', [], ['idsession' => $sessK3, 'motif' => 'Reprise de la caisse par Admin']);
check('gérant : libère la caisse d\'un caissier', $c === 200);

echo "Carte virtuelle et photo\n";
list($c, $j) = $k->call('GET', 'carte'); $idK = $j['id'] ?? 0;
check('caissier : sa propre carte (matricule, rôle, entreprise)', $c === 200 && $j['soi'] === true && $j['role_libelle'] === 'Caissier' && preg_match('/^EMP-\d{5}$/', $j['matricule']) && isset($j['entreprise']['nom']), json_encode($j));
list(, $ja) = $a->call('GET', 'carte'); list($c) = $k->call('GET', 'carte', ['id' => $ja['id']]); check('caissier : carte d\'un autre refusée', $c === 403);
list($c, $j) = $a->call('GET', 'carte', ['id' => $idK]); check('gérant : carte d\'un collaborateur', $c === 200 && $j['soi'] === false && $j['id'] === $idK);
list(, $js) = $S->call('GET', 'carte'); list($c) = $a->call('GET', 'carte', ['id' => $js['id']]); check('gérant : carte du super administrateur refusée', $c === 403);
$png = tempnam(sys_get_temp_dir(), 'ph') . '.png'; $im = imagecreatetruecolor(30, 40); imagepng($im, $png);
list($c, $j) = $k->call('POST', 'carte-photo', [], ['photo' => new CURLFile($png, 'image/png', 'moi.png')], true);
$photoK = $j['photo'] ?? '';
check('caissier : dépose sa photo', $c === 200 && $photoK !== '' && is_file(__DIR__ . '/../doc/utilisateurs/' . $photoK), json_encode($j));
list(, $j) = $k->call('GET', 'auth/me'); check('la photo suit la session (avatar)', ($j['user']['photo'] ?? '') === $photoK, json_encode($j['user'] ?? $j));
list($c, , $bin) = $a->call('GET', 'fichier', ['d' => 'utilisateurs', 'f' => $photoK]); check('photo servie par l\'API', $c === 200 && substr($bin, 1, 3) === 'PNG');
list($c) = $k->call('POST', 'carte-photo', ['id' => $ja['id']], ['photo' => new CURLFile($png, 'image/png', 'x.png')], true); check('caissier : photo d\'un autre refusée', $c === 403);
list($c, $j) = $a->call('POST', 'carte-photo', ['id' => $idK], ['retirer' => 1]);
check('gérant : retire la photo d\'un collaborateur (fichier supprimé)', $c === 200 && $j['photo'] === '' && !is_file(__DIR__ . '/../doc/utilisateurs/' . $photoK));
list($c) = $a->call('GET', 'fichier', ['d' => '../connexion', 'f' => 'conn.php']); check('route fichier : dossier non autorisé', $c === 404);
@unlink($png);

echo "Photo de catégorie\n";
$jpg = tempnam(sys_get_temp_dir(), 'cat') . '.jpg'; $im = imagecreatetruecolor(40, 30); imagejpeg($im, $jpg);
list($c, $j) = $a->call('POST', 'categories', [], ['nom' => 'Catégorie illustrée', 'ordre' => '5', 'image' => new CURLFile($jpg, 'image/jpeg', 'boissons.jpg')], true);
$imgCat = $j['data']['image'] ?? ''; $idCatImg = $j['data']['idcat'] ?? 0;
check('catégorie créée avec sa photo', $c === 201 && $imgCat !== '' && is_file(__DIR__ . '/../doc/categories/' . $imgCat), json_encode($j));
list(, $j) = $a->call('GET', 'lookups'); $lc = array_values(array_filter($j['categories'], function ($x) use ($idCatImg) { return (int)$x['id'] === (int)$idCatImg; }));
check('photo de catégorie dans les listes', $lc && $lc[0]['image'] === $imgCat);
list(, $j) = $k->call('GET', 'pos-catalogue'); $pc = array_values(array_filter($j['categories'] ?? [], function ($x) use ($idCatImg) { return (int)$x['id'] === (int)$idCatImg; }));
check('photo de catégorie sur les tuiles de la caisse', $pc && $pc[0]['image'] === $imgCat, json_encode(array_keys($j)));
list($c, , $bin) = $k->call('GET', 'fichier', ['d' => 'categories', 'f' => $imgCat]); check('photo de catégorie servie par l\'API', $c === 200 && substr($bin, 0, 2) === "\xFF\xD8");
$faux = tempnam(sys_get_temp_dir(), 'cat') . '.jpg'; file_put_contents($faux, 'pas une image');
list($c, $j) = $a->call('POST', 'categories', [], ['nom' => 'Catégorie piégée', 'image' => new CURLFile($faux, 'image/jpeg', 'x.jpg')], true); check('photo de catégorie invalide refusée', $c === 422, json_encode($j));
list($c) = $k->call('POST', 'categories', [], ['nom' => 'Par caissier', 'image' => new CURLFile($jpg, 'image/jpeg', 'b.jpg')], true); check('photo de catégorie : caissier refusé', $c === 403);
$a->call('DELETE', 'categories', ['id' => $idCatImg]); @unlink(__DIR__ . '/../doc/categories/' . $imgCat); @unlink($jpg); @unlink($faux);

echo "En-tête et pied de page des documents\n";
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'documents', 'values' => ['entete' => 'Votre partenaire', 'pied' => 'SARL au capital de 1 000 000 FCFA']]);
check('configuration : mention d\'en-tête et pied de page', $c === 200 && $j['documents']['entete'] === 'Votre partenaire' && $j['documents']['pied'] === 'SARL au capital de 1 000 000 FCFA', json_encode($j['documents'] ?? $j));
list($c, $j) = $S->call('POST', 'config', [], ['section' => 'documents', 'values' => ['pied' => str_repeat('x', 301)]]); check('pied de page : 300 caractères maximum', $c === 422 && isset($j['errors']['pied']));
list($c, $jj) = $a->call('POST', 'export-create', [], ['kind' => 'journal', 'format' => 'pdf', 'mois' => gmdate('Y-m')]);
$jid = $jj['job']['id'] ?? 0; $fin = null;
for ($i = 0; $i < 60 && !$fin; $i++) { list(, $x) = $a->call('GET', 'exports'); foreach ($x['exports'] as $e) if ($e['id'] === $jid && in_array($e['status'], ['termine', 'echec'], true)) $fin = $e; usleep(200000); }
list(, , $bin) = $a->call('GET', 'export-download', ['id' => $jid]);
check('export PDF : en-tête et pied de page de la configuration', $fin && $fin['status'] === 'termine' && strpos($bin, 'Votre partenaire') !== false && strpos($bin, 'SARL au capital') !== false, json_encode($fin));
$S->call('POST', 'config', [], ['section' => 'documents', 'values' => ['entete' => '', 'pied' => '']]);

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
