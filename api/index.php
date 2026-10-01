<?php
/**
 * API JSON — point d'entrée unique.   GET/POST/DELETE  api/index.php?r=<route>[&id=N]
 *   auth/* · public-config · dashboard · lookups · search · alertes · exports · config*
 *   caisse : pos-catalogue · session-* · operation-caisse · vente-creer · ventes · vente · vente-annuler · paniers
 *   stock : stock-ajuster · inventaire · inventaires · mouvements-stock    achats : appro-creer · appros · appro · reglement-creer · reglements
 *   fiches : produit-detail · client-detail · fournisseur-detail
 *   ressources CRUD : produits · categories · clients · fournisseurs · caisses · utilisateurs
 */
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/settings.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/permissions.php';
require_once __DIR__ . '/lib/crud.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/stock.php';
require_once __DIR__ . '/lib/achats.php';
require_once __DIR__ . '/lib/caisse.php';
require_once __DIR__ . '/lib/ventes.php';
require_once __DIR__ . '/lib/dashboard.php';
require_once __DIR__ . '/lib/alertes.php';
require_once __DIR__ . '/lib/exports.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/alertes_mail.php';
require_once __DIR__ . '/lib/fiches.php';

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
    api_logout();
    json_out(['ok' => true]);
}

$q = $_GET;
$corps = $method === 'GET' ? [] : request_input();
$post = function () use ($method) { if ($method !== 'POST') throw new ApiError(405, 'Méthode non autorisée'); };

switch ($r) {
    case 'dashboard': json_out(dashboard_data($bdd, $user));
    case 'alertes': json_out(['alertes' => role_peut($user['role'], 'stock.lire') ? alertes_liste($bdd, $reglages) : []]);

    case 'lookups':
        $t = function ($sql) use ($bdd) { return $bdd->query($sql)->fetchAll(PDO::FETCH_ASSOC); };
        json_out([
            'categories' => $t('SELECT idcat AS id, nom, couleur FROM categories WHERE supp = 0 ORDER BY ordre, nom'),
            'fournisseurs' => role_peut($user['role'], 'fournisseurs.lire') ? $t('SELECT idfour AS id, nom FROM fournisseurs WHERE supp = 0 ORDER BY nom') : [],
            'modes' => array_values(modes_actifs($bdd)),
            'caisses' => $t('SELECT idcaisse AS id, nom FROM caisses WHERE supp = 0 AND actif = 1 ORDER BY idcaisse'),
            'caissiers' => role_peut($user['role'], 'ventes.lire_toutes') ? $t("SELECT id_user AS id, CONCAT(nomag, ' ', prenom) AS nom FROM users WHERE user_status = 1 ORDER BY nomag") : [],
            'groupes' => is_admin_role($user['role']) ? $t('SELECT idgpe AS id, coden AS nom FROM table_gpe_users' . (is_super_role($user['role']) ? '' : " WHERE coden <> 'superadmin'") . ' ORDER BY coden') : [],
            'reglages' => ['tva_defaut' => (float)$reglages['caisse.tva_defaut'], 'prix_libre' => $reglages['caisse.prix_libre'] === '1', 'remise_max' => (float)$reglages['caisse.remise_max'], 'stock_negatif' => $reglages['caisse.stock_negatif'] === '1'],
        ]);

    case 'search': json_out(recherche_globale($bdd, $user, isset($q['q']) ? $q['q'] : ''));

    /* ---- Caisse ---- */
    case 'pos-catalogue': exiger($user, 'caisse.vendre'); json_out(pos_catalogue($bdd));
    case 'session-courante': json_out(['session' => session_courante($bdd, $user['id'])]);
    case 'session-ouvrir': $post(); json_out(['session' => session_ouvrir($bdd, $user, $corps)], 201);
    case 'session-cloturer': $post(); json_out(['rapport' => session_cloturer($bdd, $user, $corps)]);
    case 'operation-caisse': $post(); json_out(['operation' => operation_caisse($bdd, $user, $corps)], 201);
    case 'session-rapport':
        $rap = session_rapport($bdd, $id);
        if ((int)$rap['session']['iduser'] !== (int)$user['id'] && !role_peut($user['role'], 'ventes.lire_toutes')) throw new ApiError(403, 'Cette caisse appartient à un autre caissier.');
        json_out($rap);
    case 'sessions': json_out(sessions_liste($bdd, $user, $q));

    case 'vente-creer': $post(); json_out(['vente' => vente_creer($bdd, $user, $corps)], 201);
    case 'ventes': json_out(ventes_liste($bdd, $user, $q));
    case 'vente': if (!$id) throw new ApiError(400, 'Identifiant requis'); json_out(['vente' => vente_detail($bdd, $user, $id)]);
    case 'vente-annuler': $post(); json_out(['vente' => vente_annuler($bdd, $user, $corps)]);
    case 'paniers':
        exiger($user, 'caisse.vendre');
        if ($method === 'GET') json_out(['paniers' => paniers_lister($bdd, $user)]);
        if ($method === 'POST') json_out(panier_mettre_en_attente($bdd, $user, $corps), 201);
        if ($method === 'DELETE') { panier_supprimer($bdd, $user, $id); json_out(['ok' => true]); }
        throw new ApiError(405, 'Méthode non autorisée');

    /* ---- Stock et achats ---- */
    case 'stock-ajuster': $post(); json_out(['resultat' => stock_ajuster($bdd, $user, $corps)], 201);
    case 'inventaire': $post(); json_out(['resultat' => inventaire_creer($bdd, $user, $corps)], 201);
    case 'mouvements-stock': exiger($user, 'stock.lire'); json_out(mouvements_liste($bdd, $q));
    case 'appro-creer': $post(); json_out(['appro' => appro_creer($bdd, $user, $corps)], 201);
    case 'appros': exiger($user, 'achats.lire'); json_out(appros_liste($bdd, $q));
    case 'appro': exiger($user, 'achats.lire'); json_out(['appro' => appro_detail($bdd, $id)]);
    case 'reglement-creer': $post(); json_out(['reglement' => reglement_creer($bdd, $user, $corps)], 201);

    /* ---- Fiches détaillées ---- */
    case 'produit-detail': json_out(produit_detail($bdd, $user, $id, $resources));
    case 'client-detail': json_out(client_detail($bdd, $user, $id, $resources));
    case 'fournisseur-detail': exiger($user, 'fournisseurs.lire'); json_out(fournisseur_detail($bdd, $user, $id, $resources));

    /* ---- Exports asynchrones : la tâche est créée, la réponse part tout de suite, le fichier se prépare en arrière-plan ---- */
    case 'export-create':
        $post();
        $job = export_create($bdd, $user, $corps);
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
        if ($dir !== 'produits' || $nom === '') throw new ApiError(404, 'Fichier introuvable');
        $chemin = dirname(__DIR__) . '/doc/produits/' . $nom;
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
        try { $bdd->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)')->execute([$user['id'], 'update', 'configuration', null, json_encode(['section' => $section], JSON_UNESCAPED_UNICODE), client_ip(), gmdate('Y-m-d H:i:s')]); } catch (PDOException $e) {}
        json_out(config_complete($bdd));
    }
    if ($r === 'config-logo' && $method === 'POST') {
        if (empty($_FILES['logo'])) throw new ApiError(422, 'Données invalides', ['logo' => 'Choisissez une image']);
        $f = $_FILES['logo'];
        if ($f['error'] === UPLOAD_ERR_OK && $f['size'] > 2 * 1024 * 1024) throw new ApiError(422, 'Fichier trop volumineux', ['logo' => '2 Mo maximum']);
        $nom = store_upload($f, 'logo', ['png', 'jpg', 'jpeg', 'webp']);
        settings_set($bdd, 'entreprise.logo', $nom, $user['id']);
        json_out(config_complete($bdd));
    }
    if ($r === 'config-mail-test' && $method === 'POST') json_out(alertes_test($bdd));
    if ($r === 'config-alertes-envoyer' && $method === 'POST') json_out(alertes_envoyer($bdd, true, 'manuel'));
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
