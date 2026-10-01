<?php
/**
 * Envoi du résumé d'alertes par e-mail — à planifier chaque jour (cron) :
 *   0 7 * * *  php /chemin/vers/caisse/bin/send-alerts.php
 * Sans tâche planifiée, l'application l'envoie automatiquement à la première connexion de la journée.
 * Options : --force (envoie même s'il n'y a rien à signaler, sans tenir compte du jour déjà traité)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/conn.php';
require_once __DIR__ . '/../connexion/settings.php';
foreach (['http', 'permissions', 'mailer', 'config', 'alertes', 'alertes_mail'] as $f) require_once __DIR__ . "/../api/lib/$f.php";

try {
    if (in_array('--force', $argv, true)) { $r = alertes_envoyer($bdd, true, 'cron'); echo $r['envoye'] ? "Envoyé ({$r['nb']} alerte(s)).\n" : "Rien à signaler.\n"; exit(0); }
    echo alertes_si_besoin($bdd) ? "Traité.\n" : "Rien à faire (désactivé, déjà traité aujourd'hui ou jour hors fréquence).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Échec : ' . $e->getMessage() . "\n"); exit(1);
}
