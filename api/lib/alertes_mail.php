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

/** Rassemble les alertes activées par rubrique : [ [titre, nb, lignes[[label, detail, niveau]]] ]. */
function alertes_collecter(PDO $db, array $all)
{
    $titres = ['rupture' => 'Produits en rupture de stock', 'stock_bas' => 'Stocks sous le seuil d\'alerte', 'creances' => 'Créances clients anciennes', 'ecart_caisse' => 'Écarts de caisse (7 derniers jours)', 'licence' => "Licence d'utilisation"];
    $par = [];
    foreach (alertes_liste($db, $all) as $x) $par[$x['type']][] = ['label' => $x['label'], 'detail' => $x['detail'] !== '' ? $x['detail'] : ($x['type'] === 'rupture' ? 'en rupture' : ''), 'niveau' => $x['niveau']];
    $sections = [];
    foreach ($titres as $type => $titre) if (!empty($par[$type])) $sections[] = ['titre' => $titre, 'nb' => count($par[$type]), 'lignes' => array_slice($par[$type], 0, 15)];
    return $sections;
}

/* ---------- Gabarit HTML aux couleurs de l'application ---------- */

/** Couleurs de la palette choisie (mêmes valeurs que l'interface, mode clair). */
function mail_couleurs(array $all)
{
    $secondaire = ['violet' => '9775FA', 'ocean' => '4DABF7', 'emeraude' => '38D9A9', 'sunset' => 'FF922B', 'rose' => 'F06595', 'ardoise' => '748095'];
    $pal = isset(PALETTES_COULEURS[$all['theme.palette']]) ? $all['theme.palette'] : 'violet';
    $c = PALETTES_COULEURS[$pal];
    return ['p1' => '#' . $c['principale'], 'p2' => '#' . $secondaire[$pal], 'douce' => '#' . $c['douce'], 'pale' => '#' . $c['pale'], 'trait' => '#' . $c['trait'], 'bordure' => '#' . $c['bordure'],
        'texte' => '#2a2340', 'muet' => '#7b7393', 'fond' => '#f5eee7',
        'danger' => '#e64980', 'danger_doux' => '#fde6ee', 'warning' => '#e8890c', 'warning_doux' => '#fff0d4', 'succes' => '#2b9e6b', 'succes_doux' => '#ddf4e8'];
}

/**
 * Habille un contenu HTML : en-tête dégradé avec logo, carte blanche, pied avec les coordonnées de l'entreprise.
 * Mise en page en tableaux et styles en ligne (compatibles Gmail, Outlook, Apple Mail).
 * Renvoie [html, images intégrées (cid => fichier)].
 */
function mail_gabarit(array $all, $titre, $sousTitre, $contenu, $apercu = '')
{
    $c = mail_couleurs($all);
    $e = function ($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
    $ent = $all['entreprise.nom'] ?: 'Caisse';
    $police = "font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    $images = [];
    $logo = $all['entreprise.logo'] !== '' ? dirname(__DIR__, 2) . '/doc/logo/' . basename($all['entreprise.logo']) : '';
    if ($logo !== '' && is_file($logo)) {
        $images['logo'] = $logo;
        $embleme = '<img src="cid:logo" alt="' . $e($ent) . '" width="48" style="display:block;max-width:48px;max-height:48px;border:0">';
    } else {
        $embleme = '<span style="' . $police . ';font-size:24px;font-weight:700;color:' . $c['p1'] . '">' . $e(mb_strtoupper(mb_substr($ent, 0, 1))) . '</span>';
    }

    $coord = array_filter([trim($all['entreprise.adresse'] . ($all['entreprise.ville'] !== '' ? ', ' . $all['entreprise.ville'] : ''), ', '), $all['entreprise.telephone'] !== '' ? 'Tél. ' . $all['entreprise.telephone'] : '', $all['entreprise.email'], $all['entreprise.site']], 'strlen');

    $h = '<!DOCTYPE html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . $e($titre) . '</title></head>'
        . '<body style="margin:0;padding:0;background:' . $c['fond'] . '">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent">' . $e($apercu ?: $sousTitre) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $c['fond'] . '"><tr><td align="center" style="padding:28px 12px">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:22px;overflow:hidden;box-shadow:0 10px 30px rgba(74,48,120,.10)">'
        // En-tête
        . '<tr><td bgcolor="' . $c['p1'] . '" style="background:' . $c['p1'] . ';background-image:linear-gradient(135deg,' . $c['p1'] . ',' . $c['p2'] . ');padding:26px 30px 28px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . '<td width="64" height="64" align="center" valign="middle" bgcolor="#ffffff" style="width:64px;height:64px;background:#ffffff;border-radius:18px">' . $embleme . '</td>'
        . '<td style="padding-left:14px;' . $police . ';font-size:15px;font-weight:600;color:#ffffff;opacity:.92">' . $e($ent) . '</td>'
        . '</tr></table>'
        . '<div style="' . $police . ';font-size:24px;line-height:1.25;font-weight:700;color:#ffffff;margin-top:20px">' . $e($titre) . '</div>'
        . '<div style="' . $police . ';font-size:14px;color:#ffffff;opacity:.85;margin-top:6px">' . $e($sousTitre) . '</div>'
        . '</td></tr>'
        // Contenu
        . '<tr><td style="padding:26px 30px 8px;' . $police . ';font-size:14px;line-height:1.55;color:' . $c['texte'] . '">' . $contenu . '</td></tr>'
        // Pied
        . '<tr><td style="padding:18px 30px 26px;' . $police . '">'
        . '<div style="border-top:1px solid ' . $c['trait'] . ';padding-top:16px;font-size:12px;line-height:1.6;color:' . $c['muet'] . '">'
        . ($coord ? '<div style="font-weight:600;color:' . $c['texte'] . '">' . $e($ent) . '</div><div>' . $e(implode(' · ', $coord)) . '</div>' : '')
        . '<div style="margin-top:8px">Message automatique de l\'application de caisse. Destinataires et alertes : Configuration → Alertes e-mail.</div>'
        . '</div></td></tr>'
        . '</table>'
        . ($all['copyright.nom'] !== '' ? '<div style="' . $police . ';font-size:11px;color:' . $c['muet'] . ';margin-top:14px">© ' . date('Y') . ' ' . $e($all['copyright.nom']) . '</div>' : '')
        . '</td></tr></table></body></html>';
    return [$h, $images];
}

/** Encadré coloré (succès, information). */
function mail_encadre(array $all, $icone, $titre, $texte, $ton = 'succes')
{
    $c = mail_couleurs($all);
    $fond = $ton === 'succes' ? $c['succes_doux'] : $c['pale']; $coul = $ton === 'succes' ? $c['succes'] : $c['p1'];
    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $fond . ';border-radius:16px"><tr>'
        . '<td width="46" valign="top" style="padding:18px 0 18px 18px;font-size:24px;line-height:1">' . $icone . '</td>'
        . '<td style="padding:18px 18px 18px 12px"><div style="font-weight:700;font-size:15px;color:' . $coul . '">' . htmlspecialchars($titre) . '</div><div style="margin-top:4px">' . htmlspecialchars($texte) . '</div></td>'
        . '</tr></table><div style="height:18px;line-height:18px">&nbsp;</div>';
}

function alertes_message(array $sections, array $all)
{
    $c = mail_couleurs($all);
    $ent = $all['entreprise.nom'] ?: 'Caisse';
    $n = array_sum(array_column($sections, 'nb'));
    $pluriel = $n > 1 ? 's' : '';
    $sujet = "[$ent] $n alerte$pluriel à traiter";
    $titre = "$n point$pluriel à surveiller";
    $sous = 'Résumé du ' . date('d/m/Y');

    $html = '<p style="margin:0 0 20px">Voici les points qui demandent votre attention aujourd\'hui.</p>';
    $t = "$ent — résumé du " . date('d/m/Y') . "\n$titre\n\n";
    foreach ($sections as $s) {
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $c['bordure'] . ';border-radius:16px;border-collapse:separate;margin-bottom:16px">'
            . '<tr><td bgcolor="' . $c['pale'] . '" style="background:' . $c['pale'] . ';border-radius:16px 16px 0 0;padding:12px 16px;border-bottom:1px solid ' . $c['trait'] . '">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="font-weight:700;font-size:15px;color:' . $c['texte'] . '">' . htmlspecialchars($s['titre']) . '</td>'
            . '<td align="right"><span style="display:inline-block;background:' . $c['douce'] . ';color:' . $c['p1'] . ';font-weight:700;font-size:12px;padding:3px 10px;border-radius:999px">' . $s['nb'] . '</span></td></tr></table></td></tr>';
        $t .= mb_strtoupper($s['titre']) . ' (' . $s['nb'] . ")\n";
        $dernier = count($s['lignes']) - 1;
        foreach ($s['lignes'] as $i => $l) {
            $danger = $l['niveau'] === 'danger';
            $bord = $i < $dernier ? 'border-bottom:1px solid ' . $c['trait'] . ';' : '';
            $html .= '<tr><td style="padding:0 16px"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                . '<td style="' . $bord . 'padding:11px 0;font-weight:600;color:' . $c['texte'] . '"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . ($danger ? $c['danger'] : $c['warning']) . ';margin-right:8px;vertical-align:middle"></span>' . htmlspecialchars($l['label']) . '</td>'
                . '<td align="right" style="' . $bord . 'padding:11px 0 11px 10px">' . ($l['detail'] !== '' ? '<span style="display:inline-block;background:' . ($danger ? $c['danger_doux'] : $c['warning_doux']) . ';color:' . ($danger ? $c['danger'] : $c['warning']) . ';font-weight:600;font-size:12px;padding:4px 10px;border-radius:999px;white-space:nowrap">' . htmlspecialchars($l['detail']) . '</span>' : '') . '</td>'
                . '</tr></table></td></tr>';
            $t .= ' - ' . $l['label'] . ($l['detail'] !== '' ? ' : ' . $l['detail'] : '') . "\n";
        }
        if ($s['nb'] > count($s['lignes'])) $html .= '<tr><td style="padding:10px 16px;color:' . $c['muet'] . ';font-size:12px;border-top:1px solid ' . $c['trait'] . '">… et ' . ($s['nb'] - count($s['lignes'])) . ' autre(s) dans l\'application</td></tr>';
        $html .= '</table>';
        $t .= "\n";
    }
    list($h, $images) = mail_gabarit($all, $titre, $sous, $html, "$titre — " . implode(', ', array_map(function ($s) { return $s['titre'] . ' (' . $s['nb'] . ')'; }, $sections)));
    return [$sujet, $h, $t, $images];
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
    $n = array_sum(array_column($sections, 'nb'));
    if ($n === 0 && !$force) { mail_journaliser($db, $type, $to, '', 'rien', 0); return ['envoye' => false, 'nb' => 0, 'message' => 'Rien à signaler']; }
    if ($n === 0) {   // envoi manuel sans alerte : message de confirmation
        $ent = $all['entreprise.nom'] ?: 'Caisse';
        $sujet = "[$ent] Aucune alerte à signaler";
        list($html, $images) = mail_gabarit($all, 'Tout est en ordre', 'Résumé du ' . date('d/m/Y'), mail_encadre($all, '✅', 'Aucune alerte', 'Aucune échéance ni retard à signaler au ' . date('d/m/Y') . '.'));
        $texte = "Aucune échéance ni retard à signaler au " . date('d/m/Y') . ".\n";
    } else {
        list($sujet, $html, $texte, $images) = alertes_message($sections, $all);
    }
    try {
        mailer_depuis($all)->send($to, $sujet, $html, $texte, $images);
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
        list($html, $images) = mail_gabarit($all, 'E-mail de test', 'Envoyé le ' . date('d/m/Y à H:i'),
            mail_encadre($all, '✅', 'Configuration des e-mails réussie', "L'application de caisse peut envoyer les alertes à cette adresse.")
            . '<p style="margin:0 0 18px">Vous recevrez ici le résumé ' . ($all['alertes.frequence'] === 'hebdomadaire' ? 'de chaque lundi' : 'quotidien') . ' des ruptures, stocks bas, créances, écarts de caisse et échéances de licence.</p>');
        $mailer->send($to, $sujet, $html, "Configuration des e-mails OK.\nL'application de caisse peut envoyer les alertes à cette adresse.\n", $images);
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
