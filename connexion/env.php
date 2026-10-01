<?php
/** Charge le fichier .env (racine du projet) une seule fois. */
function env($cle, $defaut = null)
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $fichier = dirname(__DIR__) . '/.env';
        if (is_readable($fichier)) {
            foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
                $ligne = trim($ligne);
                if ($ligne === '' || $ligne[0] === '#' || strpos($ligne, '=') === false) continue;
                list($k, $v) = explode('=', $ligne, 2);
                $vars[trim($k)] = trim($v);
            }
        }
    }
    $e = getenv($cle);   // une vraie variable d'environnement l'emporte sur le fichier .env
    if ($e !== false) return $e;
    return array_key_exists($cle, $vars) ? $vars[$cle] : $defaut;
}
