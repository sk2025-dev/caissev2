<?php
/**
 * Avis clients : recueillis sur les réseaux (Facebook, Google, TikTok…), par SMS ou WhatsApp, ou en magasin, puis saisis
 * avec la note, le texte, le lien d'origine et une capture d'écran comme preuve. Rattachés à un produit ou à la boutique.
 */

const AVIS_SOURCES = [
    'facebook' => 'Facebook', 'google' => 'Google', 'tiktok' => 'TikTok', 'instagram' => 'Instagram',
    'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'magasin' => 'En magasin', 'autre' => 'Autre',
];

/** Note moyenne, nombre d'avis, répartition par étoiles et par source. $idprod null = tous les avis. */
function avis_synthese(PDO $db, $idprod = null)
{
    $w = 'supp = 0' . ($idprod ? ' AND idprod = ' . (int)$idprod : '');
    $out = ['nb' => 0, 'moyenne' => null, 'etoiles' => [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0], 'sources' => []];
    try {
        foreach ($db->query("SELECT note, COUNT(*) AS nb FROM avis WHERE $w GROUP BY note") as $r) $out['etoiles'][(int)$r['note']] = (int)$r['nb'];
        foreach ($db->query("SELECT source, COUNT(*) AS nb, ROUND(AVG(note), 1) AS moyenne FROM avis WHERE $w GROUP BY source ORDER BY nb DESC") as $r) {
            $out['sources'][] = ['source' => $r['source'], 'libelle' => AVIS_SOURCES[$r['source']] ?? $r['source'], 'nb' => (int)$r['nb'], 'moyenne' => (float)$r['moyenne']];
        }
    } catch (PDOException $e) { return $out; }   // migration 011 non appliquée
    $out['nb'] = array_sum($out['etoiles']);
    if ($out['nb']) $out['moyenne'] = round(array_sum(array_map(function ($n, $c) { return $n * $c; }, array_keys($out['etoiles']), $out['etoiles'])) / $out['nb'], 1);
    return $out;
}
