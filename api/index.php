<?php
/**
 * API JSON — point d'entrée unique.   GET/POST/DELETE  api/index.php?r=<route>[&id=N]
 *   auth/* · public-config · dashboard · dashboard-mensuel · lookups · search · alertes · exports · config*
 *   caisse : pos-catalogue · mouvements-caisse · session-* · operation-caisse · depense-creer · categorie-mouvement-creer · justificatif · vente-creer · ventes · vente · vente-annuler · paniers
 *   commandes : commandes · commande · commande-creer · commande-statut · commande-livrer · commande-annuler
 *   stock : stock-ajuster · inventaire · inventaires · mouvements-stock · stock-balance    achats : appro-creer · appros · appro · reglement-creer · reglements
 *   fiches : produit-detail · client-detail · fournisseur-detail    traçabilité : journal
 *   caisses : caisse-config (produits vendus, numérotation, ticket propres à chaque caisse)
 *   catalogue numérique (public, sans compte) : catalogue · catalogue-produit · catalogue-image    réglages : catalogue-config
 *   produits : produit-photos (envoi multiple) · produit-photo (principale, légende, ordre, retouche, suppression) · avis-synthese
 *   ressources CRUD : produits · categories · clients · fournisseurs · caisses · utilisateurs
 */
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/settings.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/permissions.php';
require_once __DIR__ . '/lib/journal.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/stock.php';
require_once __DIR__ . '/lib/achats.php';
require_once __DIR__ . '/lib/mouvements.php';
require_once __DIR__ . '/lib/caisse.php';
require_once __DIR__ . '/lib/ventes.php';
require_once __DIR__ . '/lib/commandes.php';
require_once __DIR__ . '/lib/dashboard.php';
require_once __DIR__ . '/lib/alertes.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/alertes_mail.php';
require_once __DIR__ . '/lib/fiches.php';
require_once __DIR__ . '/lib/utilisateurs.php';
require_once __DIR__ . '/lib/galerie.php';
require_once __DIR__ . '/lib/avis.php';
require_once __DIR__ . '/lib/catalogue.php';
require_once __DIR__ . '/lib/caisses.php';

// Le flux d'erreurs PHP ne doit jamais polluer le JSON
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
    if ($e instanceof ApiError) {
        json_out(['error' => $e->getMessage(), 'errors' => $e->errors, 'code' => $e->code], $e->status);
    }
    error_log('API : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'Erreur interne du serveur'], 500);
});

secure_session_start();
require __DIR__ . '/../connexion/conn.php'; // fournit $bdd

$method = $_SERVER['REQUEST_METHOD'];
$r = isset($_GET['r']) ? trim($_GET['r'], '/') : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ---- Authentification et informations publiques ---- */
if ($r === 'auth/login') {
    if ($method !== 'POST') throw new ApiError(405, 'Méthode non autorisée');
    $in = request_input();
    if (empty($in['email']) || empty($in['password'])) throw new ApiError(422, 'E-mail et mot de passe requis');
    api_login($bdd, $in['email'], $in['password']);
    json_out(['user' => api_user_public()]);
}
if ($r === 'auth/me') {
    api_current_user();
    // Hébergement sans tâche planifiée : la première connexion de la journée déclenche l'envoi du résumé d'alertes (après la réponse)
    json_then(['user' => api_user_public()]);
    alertes_si_besoin($bdd);
    exit;
}
if ($r === 'public-config') {
    json_out(config_publique($bdd));
}
/* ---- Catalogue numérique : ouvert à tous (QR code) ---- */
if ($r === 'catalogue') json_out(catalogue_liste($bdd));
if ($r === 'catalogue-produit') json_out(catalogue_produit($bdd, $id));
if ($r === 'catalogue-image') catalogue_image($bdd, isset($_GET['f']) ? $_GET['f'] : '', isset($_GET['d']) ? $_GET['d'] : 'produits');
if ($r === 'config-logo' && $method === 'GET') {
    $nom = settings_all($bdd)['entreprise.logo'];
    $chemin = dirname(__DIR__) . '/doc/logo/' . basename($nom);
    if ($nom === '' || !is_file($chemin)) throw new ApiError(404, 'Pas de logo');
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg')));
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    readfile($chemin);
    exit;
}

/* ---- Tout le reste exige une session ---- */
$user = api_current_user();
$resources = require __DIR__ . '/resources.php';
$reglages = settings_all($bdd);

// Déconnexion après inactivité (durée réglée par le super administrateur)
if (session_inactive(isset($_SESSION['derniere_activite']) ? $_SESSION['derniere_activite'] : null, $reglages['session.minutes'])) {
    journaliser($bdd, $user, 'deconnexion', 'utilisateurs', $user['id'], 'Déconnexion automatique après inactivité');
    api_logout();
    throw new ApiError(401, 'Session expirée après une période d\'inactivité. Reconnectez-vous.', null, 'inactivite');
}
$_SESSION['derniere_activite'] = time();

// Licence expirée : lecture seule (les exports restent possibles) ou accès bloqué. Le super administrateur garde la main pour renouveler.
$licence = licence_etat($reglages);
if ($licence['etat'] === 'expiree' && !is_super_role($user['role'])) {
    $libres = ['auth/logout', 'export-create', 'exports', 'export-download'];
    if ($licence['bloque'] && !in_array($r, $libres, true)) throw new ApiError(403, "La licence d'utilisation a expiré : l'accès est suspendu. Contactez votre administrateur.", null, 'licence');
    if ($licence['lecture_seule'] && $method !== 'GET' && !in_array($r, $libres, true)) throw new ApiError(403, "La licence d'utilisation a expiré : l'application est en lecture seule.", null, 'licence');
}
if ($method !== 'GET') api_check_csrf();

if ($r === 'auth/logout') {
    if ($method !== 'POST') throw new ApiError(405, 'Méthode non autorisée');
    journaliser($bdd, $user, 'deconnexion', 'utilisateurs', $user['id'], 'Déconnexion');
    api_logout();
    json_out(['ok' => true]);
}

$q = $_GET;
$corps = $method === 'GET' ? [] : request_input();
$post = function () use ($method) { if ($method !== 'POST') throw new ApiError(405, 'Méthode non autorisée'); };
$devise = ' ' . $reglages['entreprise.devise'];
$statutsCommande = ['nouvelle' => 'reçue', 'preparation' => 'en préparation', 'prete' => 'prête', 'en_livraison' => 'en livraison', 'livree' => 'livrée', 'annulee' => 'annulée'];

switch ($r) {
    case 'dashboard': json_out(dashboard_data($bdd, $user));
    case 'dashboard-mensuel': json_out(dashboard_mensuel($bdd, $user, isset($q['mois']) ? $q['mois'] : ''));
    case 'alertes': json_out(['alertes' => role_peut($user['role'], 'stock.lire') ? alertes_liste($bdd, $reglages) : []]);

    case 'lookups':
        $t = function ($sql) use ($bdd) { return $bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
        json_out([
            'categories' => $t('SELECT idcat AS id, nom, couleur, image FROM categories WHERE supp = 0 ORDER BY ordre, nom'),
            'fournisseurs' => role_peut($user['role'], 'fournisseurs.lire') ? $t('SELECT idfour AS id, nom FROM fournisseurs WHERE supp = 0 ORDER BY nom') : [],
            'modes' => array_values(modes_actifs($bdd)),
            'categories_mvt' => mouvements_categories(),
            'sources_depense' => role_peut($user['role'], 'depenses.gerer') ? mouvements_sources() : [],
            'zones_livraison' => role_peut($user['role'], 'commandes.lire') ? $t('SELECT idzone AS id, zone, commune, nom, prix, delai FROM zones_livraison WHERE supp = 0 AND actif = 1 ORDER BY zone, commune, nom') : [],
            'caisses' => $t('SELECT idcaisse AS id, nom FROM caisses WHERE supp = 0 AND actif = 1 ORDER BY idcaisse'),
            'produits' => role_peut($user['role'], 'catalogue.ecrire') ? $t('SELECT idprod AS id, nom FROM produits WHERE supp = 0 ORDER BY nom') : [],   // choix du produit d'un avis
            'caissiers' => role_peut($user['role'], 'ventes.lire_toutes') ? $t("SELECT id_user AS id, CONCAT(nomag, ' ', prenom) AS nom FROM users WHERE user_status = 1 ORDER BY nomag") : [],
            'groupes' => is_admin_role($user['role']) ? $t('SELECT idgpe AS id, coden AS nom FROM table_gpe_users' . (is_super_role($user['role']) ? '' : " WHERE coden <> 'superadmin'") . ' ORDER BY coden') : [],
            'reglages' => ['tva_defaut' => (float)$reglages['caisse.tva_defaut'], 'prix_libre' => $reglages['caisse.prix_libre'] === '1', 'remise_max' => (float)$reglages['caisse.remise_max'], 'stock_negatif' => $reglages['caisse.stock_negatif'] === '1'],
        ]);

    case 'carte': json_out(utilisateur_carte($bdd, $user, $id));
    case 'carte-photo':
        $post();
        $carte = utilisateur_photo($bdd, $user, $id, $_FILES, $corps);
        journaliser($bdd, $user, 'update', 'utilisateurs', $carte['id'], 'Photo de « ' . trim($carte['prenom'] . ' ' . $carte['nom']) . ' » ' . ($carte['photo'] ? 'modifiée' : 'retirée'), ['photo_user' => [null, $carte['photo']]]);
        json_out($carte);

    case 'search': json_out(recherche_globale($bdd, $user, isset($q['q']) ? $q['q'] : ''));

    /* ---- Caisse ---- */
    case 'pos-catalogue': exiger($user, 'caisse.vendre'); json_out(pos_catalogue($bdd, $user));
    case 'session-courante': json_out(['session' => session_courante($bdd, $user['id'])]);
    case 'caisses-occupation': json_out(['occupees' => caisses_occupation($bdd, $user)]);
    case 'session-ouvrir':
        $post();
        $s = session_ouvrir($bdd, $user, $corps);
        journaliser($bdd, $user, 'ouverture', 'sessions_caisse', $s['idsession'], "Ouverture de la caisse « {$s['caisse']} » · fond " . journal_montant($s['fond_initial']) . $devise, ['fond_initial' => $s['fond_initial'] + 0]);
        json_out(['session' => $s], 201);
    case 'session-cloturer':
        $post();
        $rap = session_cloturer($bdd, $user, $corps); $s = $rap['session'];
        $ecart = (float)$s['ecart'];
        journaliser($bdd, $user, 'cloture', 'sessions_caisse', $s['idsession'], "Clôture de la caisse « {$s['caisse']} » ({$rap['numero_z']}) · " . ($ecart == 0 ? 'sans écart' : 'écart ' . ($ecart > 0 ? '+' : '') . journal_montant($ecart) . $devise),
            ['caissier' => $s['caissier'], 'attendu_especes' => $s['attendu_especes'] + 0, 'compte_especes' => $s['compte_especes'] + 0, 'ecart' => $ecart, 'ventes' => $rap['ventes']['nb'], 'chiffre_affaires' => $rap['ventes']['ca'], 'note' => $s['notes']]);
        json_out(['rapport' => $rap]);
    case 'session-forcer-cloture': $post(); json_out(['rapport' => session_forcer_cloture($bdd, $user, $corps)]);
    case 'operation-caisse':
        $post();
        $op = operation_caisse($bdd, $user, $corps);
        journaliser($bdd, $user, $op['type'], 'caisse_operations', $op['idop'], ($op['type'] === 'entree' ? "Entrée d'espèces " : "Sortie d'espèces ") . $op['numero'] . ' · ' . journal_montant($op['montant']) . $devise,
            ['categorie' => mouvement_libelle($op['type'], (string)$corps['categorie']), 'motif' => (string)$corps['motif'], 'justificatif' => (string)($corps['reference'] ?? '')]);
        json_out(['operation' => $op], 201);
    case 'depense-creer':
        $post();
        $op = depense_creer($bdd, $user, $corps, $_FILES);
        journaliser($bdd, $user, 'depense', 'caisse_operations', $op['idop'], "Dépense hors caisse {$op['numero']} · " . journal_montant($op['montant']) . $devise,
            ['categorie' => mouvement_libelle('sortie', (string)$corps['categorie']), 'motif' => (string)$corps['motif'], 'reglee_par' => mouvements_sources()[$op['source']], 'piece_jointe' => $op['justificatif'] ? 'oui' : 'non']);
        json_out(['operation' => $op], 201);
    case 'categorie-mouvement-creer':
        $post();
        $cat = categorie_mouvement_creer($bdd, $user, $corps);
        if (!$cat['existait']) journaliser($bdd, $user, 'create', 'categories_mouvement', null, "Catégorie de " . ($cat['type'] === 'entree' ? 'recette' : 'dépense') . " « {$cat['libelle']} » créée");
        json_out(['categorie' => $cat, 'categories_mvt' => mouvements_categories()], 201);
    case 'justificatif': justificatif_envoyer($bdd, $user, $id);
    case 'session-rapport':
        $rap = session_rapport($bdd, $id);
        if ((int)$rap['session']['iduser'] !== (int)$user['id'] && !role_peut($user['role'], 'ventes.lire_toutes')) throw new ApiError(403, 'Cette caisse appartient à un autre caissier.');
        json_out($rap);
    case 'sessions': json_out(sessions_liste($bdd, $user, $q));
    case 'mouvements-caisse':
        if (!role_peut($user['role'], 'ventes.lire_toutes') && !role_peut($user['role'], 'caisse.vendre')) throw new ApiError(403, "Votre rôle ne permet pas cette action.");
        json_out(mouvements_caisse_liste($bdd, $user, $q));

    case 'vente-creer':
        $post();
        $v = vente_creer($bdd, $user, $corps);
        if (empty($v['deja_enregistree'])) journaliser($bdd, $user, 'vente', 'ventes', $v['idvente'], "Vente {$v['numero']} · " . journal_montant($v['total']) . $devise . ($v['client'] ? " · {$v['client']}" : '') . ($v['reste'] > 0 ? ' · crédit ' . journal_montant($v['reste']) . $devise : ''),
            ['caisse' => $v['caisse'], 'articles' => count($v['lignes']), 'remise' => $v['remise'] + 0, 'paiements' => implode(', ', array_map(function ($p) { return $p['libelle'] . ' ' . journal_montant($p['montant']); }, $v['paiements']))]);
        json_out(['vente' => $v], 201);
    case 'ventes': json_out(ventes_liste($bdd, $user, $q));
    case 'vente': if (!$id) throw new ApiError(400, 'Identifiant requis'); json_out(['vente' => vente_detail($bdd, $user, $id)]);
    case 'vente-annuler':
        $post();
        $v = vente_annuler($bdd, $user, $corps);
        journaliser($bdd, $user, 'annulation', 'ventes', $v['idvente'], "Annulation de la vente {$v['numero']} · " . journal_montant($v['total']) . $devise, ['caissier' => $v['caissier'], 'motif' => $v['annule_motif']]);
        json_out(['vente' => $v]);
    case 'paniers':
        exiger($user, 'caisse.vendre');
        if ($method === 'GET') json_out(['paniers' => paniers_lister($bdd, $user)]);
        if ($method === 'POST') json_out(panier_mettre_en_attente($bdd, $user, $corps), 201);
        if ($method === 'DELETE') { panier_supprimer($bdd, $user, $id); json_out(['ok' => true]); }
        throw new ApiError(405, 'Méthode non autorisée');

    /* ---- Commandes et livraisons ---- */
    case 'commandes': json_out(commandes_liste($bdd, $user, $q));
    case 'commande': if (!$id) throw new ApiError(400, 'Identifiant requis'); json_out(['commande' => commande_detail($bdd, $user, $id)]);
    case 'commande-creer':
        $post();
        $c = commande_creer($bdd, $user, $corps);
        journaliser($bdd, $user, 'create', 'commandes', $c['idcommande'], "Commande {$c['numero']} · " . journal_montant($c['total']) . $devise . ($c['client_nom'] !== '' ? " · {$c['client_nom']}" : ''), ['mode' => $c['mode'], 'adresse' => $c['adresse'], 'articles' => count($c['lignes'])]);
        json_out(['commande' => $c], 201);
    case 'commande-statut':
        $post();
        $c = commande_statut($bdd, $user, $corps);
        journaliser($bdd, $user, 'statut', 'commandes', $c['idcommande'], "Commande {$c['numero']} " . ($statutsCommande[$c['statut']] ?? $c['statut']), $c['statut'] === 'en_livraison' ? ['livreur' => $c['livreur']] : null);
        json_out(['commande' => $c]);
    case 'commande-livrer':
        $post();
        $st = $bdd->prepare('SELECT statut FROM commandes WHERE idcommande = ?'); $st->execute([(int)($corps['idcommande'] ?? 0)]);
        $dejaLivree = $st->fetchColumn() === 'livree';   // renvoi réseau : la remise est déjà journalisée
        $res = commande_livrer($bdd, $user, $corps); $c = $res['commande'];
        if (!$dejaLivree) journaliser($bdd, $user, 'livraison', 'commandes', $c['idcommande'], "Commande {$c['numero']} remise au client · vente " . ($res['vente']['numero'] ?? '') . ' · ' . journal_montant($res['vente']['total'] ?? 0) . $devise);
        json_out($res);
    case 'commande-annuler':
        $post();
        $c = commande_annuler($bdd, $user, $corps);
        journaliser($bdd, $user, 'annulation', 'commandes', $c['idcommande'], "Annulation de la commande {$c['numero']}", ['motif' => $c['annule_motif']]);
        json_out(['commande' => $c]);

    /* ---- Stock et achats ---- */
    case 'stock-ajuster':
        $post();
        $res = stock_ajuster($bdd, $user, $corps);
        $perte = ($corps['type'] ?? '') === 'perte';
        journaliser($bdd, $user, $perte ? 'perte' : 'ajustement', 'stock', $res['idprod'], ($perte ? 'Perte' : 'Ajustement') . " de stock « {$res['nom']} » : " . journal_qte($res['avant']) . ' → ' . journal_qte($res['apres']),
            ['stock' => [$res['avant'], $res['apres']], 'motif' => (string)($corps['motif'] ?? '')]);
        json_out(['resultat' => $res], 201);
    case 'inventaire':
        $post();
        $res = inventaire_creer($bdd, $user, $corps);
        journaliser($bdd, $user, 'inventaire', 'inventaires', $res['idinv'], "Inventaire validé · {$res['nb_lignes']} article(s) compté(s), {$res['nb_ecarts']} écart(s) · " . journal_montant($res['valeur_ecart']) . $devise,
            ['ecarts' => array_slice($res['ecarts'], 0, 100)]);
        json_out(['resultat' => $res], 201);
    case 'mouvements-stock': exiger($user, 'stock.lire'); json_out(mouvements_liste($bdd, $q));
    case 'stock-balance': exiger($user, 'stock.lire'); json_out(stock_balance($bdd, isset($q['mois']) ? $q['mois'] : ''));
    case 'appro-creer':
        $post();
        $a = appro_creer($bdd, $user, $corps);
        journaliser($bdd, $user, 'reception', 'approvisionnements', $a['idappro'], "Réception {$a['numero']} · {$a['fournisseur']} · " . journal_montant($a['total']) . $devise, ['bon' => $a['numero_bon'], 'articles' => count($a['lignes']), 'paye' => $a['paye'] + 0, 'reste' => $a['reste'] + 0]);
        json_out(['appro' => $a], 201);
    case 'appros': exiger($user, 'achats.lire'); json_out(appros_liste($bdd, $q));
    case 'appro': exiger($user, 'achats.lire'); json_out(['appro' => appro_detail($bdd, $id)]);
    case 'reglement-creer':
        $post();
        $reg = reglement_creer($bdd, $user, $corps);
        $fournisseur = ($corps['tiers_type'] ?? '') === 'fournisseur';
        journaliser($bdd, $user, 'reglement', $fournisseur ? 'fournisseurs' : 'clients', (int)$corps['tiers_id'], ($fournisseur ? 'Paiement au fournisseur' : 'Règlement du client') . " « {$reg['tiers']} » · " . journal_montant($reg['montant']) . $devise,
            ['mode' => (string)($corps['mode'] ?? ''), 'reference' => (string)($corps['reference'] ?? ''), 'solde_restant' => $reg['solde']]);
        json_out(['reglement' => $reg], 201);

    /* ---- Fiches détaillées ---- */
    case 'produit-detail': json_out(produit_detail($bdd, $user, $id, $resources));
    case 'produit-photos': $post(); json_out(galerie_televerser($bdd, $user, $id, $_FILES), 201);
    case 'produit-photo': $post(); json_out(galerie_action($bdd, $user, $corps, $_FILES));
    case 'caisse-config':
        if (!is_admin_role($user['role'])) throw new ApiError(403, 'Réservé au gérant.');
        if ($method === 'POST') json_out(caisse_config_enregistrer($bdd, $user, $id, $corps));
        json_out(caisse_config($bdd, $id));
    case 'catalogue-config':
        if ($method === 'POST') json_out(catalogue_config_enregistrer($bdd, $user, $corps));
        exiger($user, 'catalogue.lire'); json_out(catalogue_config($bdd));
    case 'catalogue-apercu': exiger($user, 'catalogue.lire'); json_out(catalogue_liste($bdd, true));
    case 'avis-synthese': json_out(['synthese' => avis_synthese($bdd), 'sources' => AVIS_SOURCES]);
    case 'client-detail': json_out(client_detail($bdd, $user, $id, $resources));
    case 'fournisseur-detail': exiger($user, 'fournisseurs.lire'); json_out(fournisseur_detail($bdd, $user, $id, $resources));

    /* ---- Traçabilité ---- */
    case 'journal': if ($method !== 'GET') throw new ApiError(405, 'Le journal est en lecture seule.'); json_out(journal_liste($bdd, $user, $q));

    /* ---- Exports asynchrones : la tâche est créée, la réponse part tout de suite, le fichier se prépare en arrière-plan ---- */
    case 'export-create':
        $post();
        $job = export_create($bdd, $user, $corps);
        $pub = export_public($job);
        journaliser($bdd, $user, $pub['kind'] === 'database' ? 'sauvegarde' : 'export', 'exports', $pub['id'], $pub['kind'] === 'database' ? 'Sauvegarde complète de la base' : 'Export « ' . $pub['kind'] . ' » (' . strtoupper($pub['format']) . ')' . ($pub['from'] ? " du {$pub['from']} au {$pub['to']}" : ''));
        json_then(['job' => export_public($job)], 202);
        export_run($bdd, (int)$job['id']);
        exit;
    case 'exports': json_out(['exports' => export_list($bdd, $user['id'])]);
    case 'export-download':
        $j = export_get($bdd, $id, $user['id']);
        if ($j['status'] !== 'termine') throw new ApiError(409, 'Export pas encore prêt');
        $fichier = export_dir() . '/' . basename($j['stored']);
        if (!is_file($fichier)) throw new ApiError(410, 'Fichier expiré, relancez l\'export');
        $types = ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf', 'sql.gz' => 'application/gzip'];
        session_write_close();
        header('Content-Type: ' . $types[$j['format']]);
        header('Content-Disposition: attachment; filename="' . $j['filename'] . '"');
        header('Content-Length: ' . filesize($fichier));
        header('X-Content-Type-Options: nosniff');
        readfile($fichier);
        exit;

    /* ---- Images des produits (réservées aux utilisateurs connectés) ---- */
    case 'fichier':
        $dir = isset($q['d']) ? $q['d'] : ''; $nom = isset($q['f']) ? basename($q['f']) : '';
        if (!in_array($dir, ['produits', 'utilisateurs', 'categories', 'avis'], true) || $nom === '') throw new ApiError(404, 'Fichier introuvable');
        $chemin = dirname(__DIR__) . '/doc/' . $dir . '/' . $nom;
        if (!is_file($chemin)) throw new ApiError(404, 'Fichier introuvable');
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
        session_write_close();
        header('Content-Type: ' . (isset($types[$ext]) ? $types[$ext] : 'application/octet-stream'));
        header('Cache-Control: private, max-age=86400');
        header('X-Content-Type-Options: nosniff');
        readfile($chemin);
        exit;
}

/* ---- Configuration : réservée au super administrateur ---- */
if (strpos($r, 'config') === 0) {
    if (!is_super_role($user['role'])) throw new ApiError(403, 'Réservé au super administrateur.');
    if ($r === 'config' && $method === 'GET') json_out(config_complete($bdd));
    if ($r === 'config' && $method === 'POST') {
        $section = isset($corps['section']) ? (string)$corps['section'] : '';
        $valeurs = config_valider($section, isset($corps['values']) && is_array($corps['values']) ? $corps['values'] : [], $reglages);
        foreach ($valeurs as $cle => $v) settings_set($bdd, $cle, $v, $user['id']);
        $titres = ['entreprise' => "Informations de l'entreprise", 'copyright' => 'Mention de copyright', 'theme' => 'Thème', 'documents' => 'En-têtes des documents', 'licence' => "Licence d'utilisation",
            'session' => "Délai d'inactivité", 'mail' => 'E-mail (SMTP)', 'alertes' => 'Alertes', 'caisse' => 'Règles de caisse et ticket'];
        $diff = journal_diff($reglages, $valeurs);
        if ($diff) journaliser($bdd, $user, 'update', 'configuration', null, 'Configuration modifiée : ' . ($titres[$section] ?? $section), $diff);
        json_out(config_complete($bdd));
    }
    if ($r === 'config-logo' && $method === 'POST') {
        if (empty($_FILES['logo'])) throw new ApiError(422, 'Données invalides', ['logo' => 'Choisissez une image']);
        $f = $_FILES['logo'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 2 * 1024 * 1024) throw new ApiError(422, 'Fichier trop volumineux', ['logo' => '2 Mo maximum']);
        $nom = store_upload($f, 'logo', ['png', 'jpg', 'jpeg', 'webp']);
        settings_set($bdd, 'entreprise.logo', $nom, $user['id']);
        journaliser($bdd, $user, 'update', 'configuration', null, "Logo de l'entreprise modifié", ['entreprise.logo' => [$reglages['entreprise.logo'], $nom]]);
        json_out(config_complete($bdd));
    }
    if ($r === 'config-mail-test' && $method === 'POST') json_out(alertes_test($bdd));
    if ($r === 'config-alertes-envoyer' && $method === 'POST') { journaliser($bdd, $user, 'envoi', 'configuration', null, 'Envoi manuel du résumé des alertes'); json_out(alertes_envoyer($bdd, true, 'manuel')); }
    throw new ApiError(404, 'Route de configuration inconnue');
}

/* ---- Ressources CRUD ---- */
if (!isset($resources[$r])) throw new ApiError(404, 'Route inconnue');
$cfg = $resources[$r];
$ecriture = $method !== 'GET';
$roles = $ecriture ? ($cfg['roles_ecriture'] ?? $cfg['roles'] ?? null) : ($cfg['roles'] ?? null);
if ($roles !== null && !in_array($user['role'], $roles, true)) throw new ApiError(403, 'Accès non autorisé');

$crud = new Crud($bdd, $r, $cfg, $user);
switch ($method) {
    case 'GET':
        json_out($id ? ['data' => $crud->find($id)] : $crud->listing());
    case 'POST':      // POST sans id = création ; POST avec id = mise à jour (compatible envoi de fichiers multipart)
        json_out(['data' => $id ? $crud->update($id, $corps, $_FILES) : $crud->create($corps, $_FILES)], $id ? 200 : 201);
    case 'PUT':
        if (!$id) throw new ApiError(400, 'Identifiant requis');
        json_out(['data' => $crud->update($id, $corps, [])]);
    case 'DELETE':
        if (!$id) throw new ApiError(400, 'Identifiant requis');
        if (!is_admin_role($user['role'])) throw new ApiError(403, 'Seul un administrateur peut supprimer.');
        $crud->delete($id);
        json_out(['ok' => true]);
    default:
        throw new ApiError(405, 'Méthode non autorisée');
}
