<?php
/**
 * Utilitaires de sécurité : échappement, CSRF, session, mots de passe, routage sûr.
 */

/** Échappe une valeur pour l'affichage HTML. */
function e($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Démarre la session avec des cookies durcis (à appeler à la place de session_start()). */
function secure_session_start()
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    // Chaque version a sa propre session : se connecter à l'une n'ouvre pas l'autre (l'ancienne version définit SESSION_NOM)
    session_name(defined('SESSION_NOM') ? SESSION_NOM : 'PARCAUTO');
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------- CSRF ---------- */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Refuse tout POST dont le jeton CSRF est absent ou faux. */
function csrf_verify()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $envoye = isset($_POST['_csrf']) ? (string)$_POST['_csrf'] : '';
    $attendu = isset($_SESSION['csrf']) ? (string)$_SESSION['csrf'] : '';
    if ($attendu === '' || !hash_equals($attendu, $envoye)) {
        http_response_code(403);
        die('Requête refusée (jeton de sécurité invalide). Rechargez la page et recommencez.');
    }
}

/** Callback ob_start : ajoute le jeton CSRF dans chaque formulaire POST, sans toucher aux pages. */
function csrf_inject_forms($html)
{
    $champ = '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    return preg_replace_callback('/<form\b[^>]*>/i', function ($m) use ($champ) {
        return preg_match('/method\s*=\s*["\']?post/i', $m[0]) ? $m[0] . $champ : $m[0];
    }, $html);
}

/* ---------- Authentification / autorisations ---------- */

function is_logged_in()
{
    return !empty($_SESSION['email']) && !empty($_SESSION['gpe']) && isset($_SESSION['pwd']);
}

/** Pour les endpoints AJAX (data/*) : 401 si non connecté. */
function require_login_json()
{
    if (!is_logged_in()) {
        http_response_code(401);
        exit('Session expirée.');
    }
}

/** Le super administrateur a tous les droits de l'administrateur, plus la configuration. */
function is_admin_role($role) { return in_array($role, ['admin', 'superadmin'], true); }
function is_super_role($role) { return $role === 'superadmin'; }

function require_role(array $roles)
{
    $role = $_SESSION['gpe'] ?? '';
    if (!in_array($role, $roles, true) && !(in_array('admin', $roles, true) && is_super_role($role))) {
        http_response_code(403);
        echo '<div class="page-content"><div class="alert alert-danger">Accès non autorisé.</div></div>';
        return false;
    }
    return true;
}

/* ---------- Routage sûr (?page=xxx) ---------- */

const PAGES_INTERDITES = [
    'index', 'accueil', 'Page_connect', 'Page_initpwd', 'register', 'Page_success', 'Page_success2',
    'Page_lock', 'Page_userlogin', 'page_user_login_1', 'logout', 'logout2', 'error',
    'menu', 'menu2', 'menuanc', 'milieuanc', 'Page_footer', 'autre',
];
const PAGES_ADMIN = ['Page_listeuser', 'ajuser', 'modifuser', 'Page_activeprofil', 'Page_modifprofil'];

/** Retourne le nom de fichier à inclure, ou null si la page demandée n'est pas autorisée. */
function resolve_page($page)
{
    if (!is_string($page) || !preg_match('/^[A-Za-z0-9_]+$/', $page)) return null;
    if (in_array($page, PAGES_INTERDITES, true)) return null;
    $base = defined('PAGES_DIR') ? PAGES_DIR : dirname(__DIR__);   // l'ancienne version définit PAGES_DIR = son dossier legacy/
    $fichier = $base . '/' . $page . '.php';
    return is_file($fichier) ? $fichier : null;
}

/* ---------- Mots de passe ---------- */

function pwd_est_hache($stocke)
{
    return is_string($stocke) && preg_match('/^\$(2y|argon2id?)\$/', $stocke) === 1;
}

/** Stocke un mot de passe : haché si PASSWORD_HASHING=1 (migration appliquée), sinon inchangé. */
function pwd_pour_stockage($clair)
{
    return env('PASSWORD_HASHING', '0') === '1' ? password_hash($clair, PASSWORD_DEFAULT) : $clair;
}

/** Vérifie un mot de passe contre la valeur stockée (hachée ou héritée en clair). */
function pwd_verifie($clair, $stocke)
{
    if (pwd_est_hache($stocke)) return password_verify($clair, $stocke);
    // Héritage : comparaison en clair, y compris l'ancienne forme htmlentities()
    return hash_equals((string)$stocke, (string)$clair)
        || hash_equals((string)$stocke, htmlentities((string)$clair, ENT_QUOTES, 'UTF-8'));
}

/** Indique si la valeur stockée doit être (re)hachée. */
function pwd_a_migrer($stocke)
{
    return env('PASSWORD_HASHING', '0') === '1'
        && (!pwd_est_hache($stocke) || password_needs_rehash($stocke, PASSWORD_DEFAULT));
}
