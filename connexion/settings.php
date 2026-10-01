<?php
/**
 * Paramètres de l'application (table settings) : valeurs par défaut, lecture, état de la licence.
 * Partagé par l'API et l'ancienne interface.
 */

const SETTINGS_DEFAUTS = [
    'entreprise.nom' => '', 'entreprise.forme' => '', 'entreprise.adresse' => '', 'entreprise.ville' => '', 'entreprise.pays' => "Côte d'Ivoire",
    'entreprise.telephone' => '', 'entreprise.email' => '', 'entreprise.site' => '', 'entreprise.rccm' => '', 'entreprise.nif' => '', 'entreprise.devise' => 'FCFA', 'entreprise.logo' => '',
    'copyright.nom' => "Dav'Holding Group", 'copyright.url' => '',
    'theme.palette' => 'violet', 'theme.mode' => 'auto',
    'licence.debut' => '', 'licence.duree_mois' => '', 'licence.apres' => 'lecture_seule', 'licence.note' => '',
    'session.minutes' => '120',
    'mail.actif' => '0', 'mail.transport' => 'mail', 'mail.smtp_hote' => '', 'mail.smtp_port' => '587', 'mail.smtp_securite' => 'tls', 'mail.smtp_user' => '', 'mail.smtp_pass' => '',
    'mail.expediteur' => '', 'mail.expediteur_nom' => 'Caisse', 'mail.destinataires' => '',
    'alertes.types' => 'rupture,stock_bas,creances,ecart_caisse,licence', 'alertes.jours_credit' => '30', 'alertes.frequence' => 'quotidien', 'alertes.derniere_execution' => '',
    'caisse.tva_defaut' => '0', 'caisse.prefixe' => 'V', 'caisse.remise_max' => '10', 'caisse.stock_negatif' => '0', 'caisse.prix_libre' => '0',
    'caisse.ticket_entete' => '', 'caisse.ticket_pied' => 'Merci de votre visite !', 'caisse.ticket_largeur' => '80', 'caisse.ecart_tolere' => '500',
];

const PALETTES = ['violet' => 'Violet', 'ocean' => 'Océan', 'emeraude' => 'Émeraude', 'sunset' => 'Coucher de soleil', 'rose' => 'Rose', 'ardoise' => 'Ardoise'];

/** Tous les paramètres à plat ("section.cle" => valeur), défauts inclus. */
function settings_all(PDO $db)
{
    $out = SETTINGS_DEFAUTS;
    try {
        foreach ($db->query('SELECT cle, valeur FROM settings')->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (array_key_exists($r['cle'], SETTINGS_DEFAUTS)) $out[$r['cle']] = (string)$r['valeur'];
        }
    } catch (PDOException $e) { /* table absente : migration 011 non appliquée, on reste sur les défauts */ }
    return $out;
}

function settings_section(array $all, $section)
{
    $o = [];
    foreach ($all as $k => $v) if (strpos($k, $section . '.') === 0) $o[substr($k, strlen($section) + 1)] = $v;
    return $o;
}

function settings_set(PDO $db, $cle, $valeur, $userId = null)
{
    $st = $db->prepare('UPDATE settings SET valeur = :v, updated_at = :t, updated_by = :u WHERE cle = :c');
    $st->execute(['v' => $valeur, 't' => gmdate('Y-m-d H:i:s'), 'u' => $userId, 'c' => $cle]);
    if ($st->rowCount() === 0) {
        $chk = $db->prepare('SELECT COUNT(*) FROM settings WHERE cle = ?'); $chk->execute([$cle]);
        if ((int)$chk->fetchColumn() === 0) $db->prepare('INSERT INTO settings (cle, valeur, updated_at, updated_by) VALUES (?,?,?,?)')->execute([$cle, $valeur, gmdate('Y-m-d H:i:s'), $userId]);
    }
}

/** Licence : période d'utilisation définie par le super administrateur. */
function licence_etat(array $all)
{
    $debut = $all['licence.debut']; $duree = (int)$all['licence.duree_mois'];
    $r = ['defini' => false, 'debut' => null, 'fin' => null, 'jours' => null, 'etat' => 'illimitee', 'apres' => $all['licence.apres'], 'bloque' => false, 'lecture_seule' => false];
    if (!$debut || $duree < 1) return $r;
    $fin = date('Y-m-d', strtotime($debut . ' +' . $duree . ' months -1 day'));
    $jours = (int)floor((strtotime($fin) - strtotime(gmdate('Y-m-d'))) / 86400);
    $r = ['defini' => true, 'debut' => $debut, 'fin' => $fin, 'jours' => $jours, 'apres' => $all['licence.apres']] + $r;
    if ($debut > gmdate('Y-m-d')) $r['etat'] = 'avenir';
    elseif ($jours < 0) { $r['etat'] = 'expiree'; $r['bloque'] = $all['licence.apres'] === 'bloque'; $r['lecture_seule'] = !$r['bloque']; }
    elseif ($jours <= 30) $r['etat'] = 'bientot';
    else $r['etat'] = 'ok';
    return $r;
}

/** Inactivité : la session a-t-elle dépassé le délai autorisé ? (0 minute = jamais) */
function session_inactive($derniereActivite, $minutes, $maintenant = null)
{
    $minutes = (int)$minutes;
    if ($minutes <= 0 || !$derniereActivite) return false;
    return (($maintenant ?: time()) - (int)$derniereActivite) > $minutes * 60;
}
