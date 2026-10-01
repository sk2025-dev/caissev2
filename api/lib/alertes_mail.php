<?php
/**
 * Alertes par e-mail : résumé quotidien ou hebdomadaire des échéances et des retards.
 * Déclenchement : tâche planifiée (php bin/send-alerts.php) ou, à défaut, à la première connexion de la journée.
 */
require_once __DIR__ . '/mailer.php';

function alertes_destinataires($txt)
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$txt, -1, PREG_SPLIT_NO_EMPTY) as $m) if (filter_var($m, FILTER_VALIDATE_EMAIL)) $out[strtolower($m)] = $m;
    return array_values($out);
}

/** Rassemble les alertes activées par rubrique : [ [titre, lignes[[label, detail, niveau]]] ]. */
function alertes_collecter(PDO $db, array $all)
{
    $titres = ['rupture' => 'Produits en rupture de stock', 'stock_bas' => 'Stocks sous le seuil d\'alerte', 'creances' => 'Créances clients anciennes', 'ecart_caisse' => 'Écarts de caisse (7 derniers jours)', 'licence' => "Licence d'utilisation"];
    $par = [];
    foreach (alertes_liste($db, $all) as $x) $par[$x['type']][] = ['label' => $x['label'], 'detail' => $x['detail'], 'niveau' => $x['niveau']];
    $sections = [];
    foreach ($titres as $type => $titre) if (!empty($par[$type])) $sections[] = ['titre' => $titre . ' (' . count($par[$type]) . ')', 'lignes' => array_slice($par[$type], 0, 15)];
    return $sections;
}

function alertes_message(array $sections, array $all)
{
    $ent = $all['entreprise.nom'] ?: 'Caisse';
    $n = array_sum(array_map(function ($s) { return count($s['lignes']); }, $sections));
    $sujet = "[$ent] $n alerte" . ($n > 1 ? 's' : '') . ' à traiter';
    $couleur = ['danger' => '#e64980', 'warning' => '#e8890c'];
    $h = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:620px;margin:auto;color:#2a2340"><div style="background:#7048e8;color:#fff;padding:22px 26px;border-radius:16px 16px 0 0"><div style="font-size:13px;opacity:.85">' . htmlspecialchars($ent) . '</div><div style="font-size:21px;font-weight:bold;margin-top:4px">' . $n . ' point' . ($n > 1 ? 's' : '') . " à surveiller</div><div style=\"font-size:13px;opacity:.85;margin-top:2px\">Résumé du " . date('d/m/Y') . '</div></div><div style="border:1px solid #e2def0;border-top:0;border-radius:0 0 16px 16px;padding:8px 26px 22px">';
    $t = "$ent — résumé du " . date('d/m/Y') . "\n\n";
    foreach ($sections as $s) {
        $h .= '<h3 style="margin:22px 0 8px;font-size:15px">' . htmlspecialchars($s['titre']) . '</h3><table style="width:100%;border-collapse:collapse">';
        $t .= strtoupper($s['titre']) . "\n";
        foreach ($s['lignes'] as $l) {
            $h .= '<tr><td style="padding:8px 0;border-bottom:1px solid #eee;font-weight:bold">' . htmlspecialchars($l['label']) . '</td><td style="padding:8px 0;border-bottom:1px solid #eee;text-align:right;color:' . $couleur[$l['niveau']] . ';font-weight:bold">' . htmlspecialchars($l['detail']) . '</td></tr>';
            $t .= ' - ' . $l['label'] . ' : ' . $l['detail'] . "\n";
        }
        $h .= '</table>'; $t .= "\n";
    }
    $h .= '<p style="color:#7b7393;font-size:12px;margin:22px 0 0">Message automatique de l\'application de caisse. Les seuils et destinataires se règlent dans Configuration → Alertes e-mail.</p></div></div>';
    return [$sujet, $h, $t];
}

function mail_journaliser(PDO $db, $type, array $to, $sujet, $statut, $nb, $erreur = '')
{
    try {
        $db->prepare('INSERT INTO mail_log (created_at, type, destinataires, sujet, statut, nb_alertes, erreur) VALUES (?,?,?,?,?,?,?)')
            ->execute([gmdate('Y-m-d H:i:s'), $type, mb_substr(implode(', ', $to), 0, 500), mb_substr($sujet, 0, 200), $statut, $nb, mb_substr($erreur, 0, 500)]);
    } catch (PDOException $e) { error_log('mail_log indisponible : ' . $e->getMessage()); }
}

function mailer_depuis(array $all)
{
    return new Mailer([
        'transport' => $all['mail.transport'], 'expediteur' => $all['mail.expediteur'], 'expediteur_nom' => $all['mail.expediteur_nom'],
        'smtp_hote' => $all['mail.smtp_hote'], 'smtp_port' => $all['mail.smtp_port'], 'smtp_securite' => $all['mail.smtp_securite'], 'smtp_user' => $all['mail.smtp_user'], 'smtp_pass' => $all['mail.smtp_pass'],
    ]);
}

/** Envoie le résumé. $force : envoie même s'il n'y a rien à signaler (bouton « Envoyer maintenant »). */
function alertes_envoyer(PDO $db, $force = false, $type = 'alertes')
{
    $all = settings_all($db);
    $to = alertes_destinataires($all['mail.destinataires']);
    if (!$to) throw new ApiError(422, 'Données invalides', ['destinataires' => 'Aucun destinataire valide']);
    $sections = alertes_collecter($db, $all);
    $n = array_sum(array_map(function ($s) { return count($s['lignes']); }, $sections));
    if ($n === 0 && !$force) { mail_journaliser($db, $type, $to, '', 'rien', 0); return ['envoye' => false, 'nb' => 0, 'message' => 'Rien à signaler']; }
    if ($n === 0) {   // envoi manuel sans alerte : message de confirmation
        $ent = $all['entreprise.nom'] ?: 'Caisse';
        $sujet = "[$ent] Aucune alerte à signaler";
        $html = '<p style="font-family:Arial">Aucune échéance ni retard à signaler au ' . date('d/m/Y') . '. ✅</p>'; $texte = "Aucune échéance ni retard à signaler au " . date('d/m/Y') . ".\n";
    } else {
        list($sujet, $html, $texte) = alertes_message($sections, $all);
    }
    try {
        mailer_depuis($all)->send($to, $sujet, $html, $texte);
        mail_journaliser($db, $type, $to, $sujet, 'envoye', $n);
        return ['envoye' => true, 'nb' => $n, 'destinataires' => $to];
    } catch (Throwable $e) {
        mail_journaliser($db, $type, $to, $sujet, 'echec', $n, $e->getMessage());
        throw new ApiError(502, "Envoi impossible : " . $e->getMessage());
    }
}

function alertes_test(PDO $db)
{
    $all = settings_all($db);
    $to = alertes_destinataires($all['mail.destinataires']);
    if (!$to) throw new ApiError(422, 'Données invalides', ['destinataires' => 'Aucun destinataire valide']);
    $ent = $all['entreprise.nom'] ?: 'Caisse';
    $sujet = "[$ent] E-mail de test";
    $mailer = mailer_depuis($all);
    try {
        $mailer->send($to, $sujet, '<div style="font-family:Arial"><h3>Configuration des e-mails ✅</h3><p>Ce message confirme que l\'application de caisse peut envoyer les alertes à cette adresse.</p></div>', "Configuration des e-mails OK.\nL\'application de caisse peut envoyer les alertes à cette adresse.\n");
        mail_journaliser($db, 'test', $to, $sujet, 'envoye', 0);
        return ['envoye' => true, 'destinataires' => $to];
    } catch (Throwable $e) {
        mail_journaliser($db, 'test', $to, $sujet, 'echec', 0, $e->getMessage());
        throw new ApiError(502, "Envoi impossible : " . $e->getMessage());
    }
}

/**
 * Déclenchement paresseux (hébergement sans tâche planifiée) : au plus une fois par jour (ou par semaine, le lundi).
 * La réservation du créneau est atomique : deux requêtes simultanées n'envoient qu'un seul e-mail.
 */
function alertes_si_besoin(PDO $db)
{
    $all = settings_all($db);
    if ($all['mail.actif'] !== '1' || !alertes_destinataires($all['mail.destinataires'])) return false;
    $aujourdhui = gmdate('Y-m-d');
    if ($all['alertes.derniere_execution'] === $aujourdhui) return false;
    if ($all['alertes.frequence'] === 'hebdomadaire' && gmdate('N') !== '1') return false;
    $db->exec("INSERT INTO settings (cle, valeur) SELECT 'alertes.derniere_execution', '' FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM settings WHERE cle = 'alertes.derniere_execution')");   // ligne garantie, sans écraser la valeur
    $st = $db->prepare("UPDATE settings SET valeur = :t WHERE cle = 'alertes.derniere_execution' AND (valeur IS NULL OR valeur <> :t2)");
    $st->execute(['t' => $aujourdhui, 't2' => $aujourdhui]);
    if ($st->rowCount() !== 1) return false;
    try { alertes_envoyer($db, false, 'alertes'); } catch (Throwable $e) { error_log('Alertes e-mail : ' . $e->getMessage()); }
    return true;
}
