<?php
/** Authentification de l'API (session PHP + jeton CSRF par en-tête). */

const LOGIN_MAX_ECHECS = 5;
const LOGIN_FENETRE_MIN = 15;

function api_user_public()
{
    return [
        'id' => (int)$_SESSION['id'],
        'name' => $_SESSION['identite'],
        'prenom' => isset($_SESSION['prenom']) ? $_SESSION['prenom'] : '',
        'email' => $_SESSION['email'],
        'role' => $_SESSION['gpe'],
        'admin' => is_admin_role($_SESSION['gpe']),
        'droits' => array_combine($k = ['caisse', 'catalogue_ecriture', 'stock_ecriture', 'achats', 'ventes_toutes', 'clients_ecriture', 'fournisseurs', 'stock'], array_map(function ($d) { return role_peut($_SESSION['gpe'], $d); }, ['caisse.vendre', 'catalogue.ecrire', 'stock.ecrire', 'achats.lire', 'ventes.lire_toutes', 'clients.ecrire', 'fournisseurs.lire', 'stock.lire'])),
        'super' => is_super_role($_SESSION['gpe']),
        'csrf' => csrf_token(),
    ];
}

function api_current_user()
{
    if (!is_logged_in()) throw new ApiError(401, 'Session expirée, reconnectez-vous.');
    return ['id' => (int)$_SESSION['id'], 'role' => $_SESSION['gpe'], 'email' => $_SESSION['email'], 'nom' => isset($_SESSION['identite']) ? $_SESSION['identite'] : ''];
}

/** Toute requête qui modifie des données doit présenter le jeton CSRF (en-tête X-CSRF-Token). */
function api_check_csrf()
{
    $attendu = isset($_SESSION['csrf']) ? $_SESSION['csrf'] : '';
    $recu = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($attendu === '' || !hash_equals($attendu, $recu)) throw new ApiError(403, 'Jeton de sécurité invalide, rechargez la page.');
}

function api_login(PDO $db, $email, $pwd)
{
    $email = trim((string)$email);
    $ip = client_ip();
    $depuis = gmdate('Y-m-d H:i:s', time() - LOGIN_FENETRE_MIN * 60);

    $tentatives = null;
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = :e AND ip = :ip AND created_at >= :d');
        $st->execute(['e' => $email, 'ip' => $ip, 'd' => $depuis]);
        $tentatives = (int)$st->fetchColumn();
    } catch (PDOException $e) { /* table absente : migration 004 non appliquée */ }
    if ($tentatives !== null && $tentatives >= LOGIN_MAX_ECHECS) {
        throw new ApiError(429, 'Trop de tentatives. Réessayez dans ' . LOGIN_FENETRE_MIN . ' minutes.');
    }

    $st = $db->prepare('SELECT u.*, g.coden FROM users u JOIN table_gpe_users g ON g.idgpe = u.gpe WHERE u.emailag = :m AND u.user_status = 1');
    $st->execute(['m' => $email]);
    $trouves = [];
    while ($u = $st->fetch(PDO::FETCH_ASSOC)) {
        if (pwd_verifie((string)$pwd, $u['pass'])) $trouves[] = $u;
    }

    if (count($trouves) !== 1) {
        try {
            $db->prepare('INSERT INTO login_attempts (email, ip, created_at) VALUES (?,?,?)')->execute([$email, $ip, gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {}
        throw new ApiError(401, 'Identifiants incorrects ou compte inactif.');
    }
    $u = $trouves[0];

    if (pwd_a_migrer($u['pass'])) {
        try {
            $db->prepare('UPDATE users SET pass = :p WHERE id_user = :id')->execute(['p' => password_hash((string)$pwd, PASSWORD_DEFAULT), 'id' => $u['id_user']]);
        } catch (PDOException $e) { error_log('Rehash impossible : ' . $e->getMessage()); }
    }
    try {
        $db->prepare('DELETE FROM login_attempts WHERE email = :e AND ip = :ip')->execute(['e' => $email, 'ip' => $ip]);
    } catch (PDOException $e) {}

    session_regenerate_id(true);
    $_SESSION['id'] = $u['id_user'];
    $_SESSION['nom'] = $u['nomag'];
    $_SESSION['identite'] = trim($u['nomag'] . ' ' . $u['prenom']);
    $_SESSION['prenom'] = trim((string)$u['prenom']);
    $_SESSION['email'] = $u['emailag'];
    $_SESSION['gpe'] = $u['coden'];
    $_SESSION['pwd'] = 'ok';
    $_SESSION['photo'] = isset($u['photo_user']) ? $u['photo_user'] : '';
    unset($_SESSION['csrf']); // nouveau jeton pour la nouvelle session

    try {
        $db->prepare('INSERT INTO table_histoconnexion (ipaddress, user_email, datecon, statconn) VALUES (?,?,?,1)')->execute([$ip, $email, gmdate('Y-m-d H:i:s')]);
    } catch (PDOException $e) {}
}

function api_logout()
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
