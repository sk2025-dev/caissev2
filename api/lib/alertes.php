<?php
/**
 * Alertes métier : ruptures et stocks bas, créances anciennes, écarts de caisse, licence.
 * Alimentent la cloche de l'application et le résumé envoyé par e-mail.
 */

function alertes_liste(PDO $db, array $reg)
{
    $types = array_filter(explode(',', $reg['alertes.types']));
    $a = [];

    // Produits suivis en stock : « suivi » = déjà mouvementé ou seuil défini (évite de signaler tout le catalogue jamais approvisionné)
    $suivi = "p.supp = 0 AND p.actif = 1 AND p.stockable = 1 AND (p.seuil_alerte > 0 OR EXISTS (SELECT 1 FROM mouvements_stock m WHERE m.idprod = p.idprod))";
    if (in_array('rupture', $types, true)) {
        foreach ($db->query("SELECT p.idprod, p.nom, p.unite FROM produits p WHERE $suivi AND p.stock_qty <= 0 ORDER BY p.nom LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a[] = ['type' => 'rupture', 'id' => (int)$r['idprod'], 'label' => $r['nom'], 'detail' => 'en rupture de stock', 'niveau' => 'danger', 'jours' => -1];
        }
    }
    if (in_array('stock_bas', $types, true)) {
        foreach ($db->query("SELECT p.idprod, p.nom, p.stock_qty, p.seuil_alerte, p.unite FROM produits p WHERE $suivi AND p.stock_qty > 0 AND p.seuil_alerte > 0 AND p.stock_qty <= p.seuil_alerte ORDER BY p.stock_qty / p.seuil_alerte LIMIT 100")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a[] = ['type' => 'stock_bas', 'id' => (int)$r['idprod'], 'label' => $r['nom'], 'detail' => 'reste ' . rtrim(rtrim(number_format($r['stock_qty'], 3, '.', ''), '0'), '.') . ' ' . $r['unite'] . ' (seuil ' . rtrim(rtrim(number_format($r['seuil_alerte'], 3, '.', ''), '0'), '.') . ')', 'niveau' => 'warning', 'jours' => 0];
        }
    }
    if (in_array('creances', $types, true)) {
        $limite = gmdate('Y-m-d H:i:s', time() - max(1, (int)$reg['alertes.jours_credit']) * 86400);
        $st = $db->prepare("SELECT c.idclient, c.nom, c.solde, MIN(v.date_vente) AS depuis FROM clients c JOIN ventes v ON v.idclient = c.idclient AND v.statut = 'validee' AND v.reste > 0 WHERE c.supp = 0 AND c.solde > 0 GROUP BY c.idclient, c.nom, c.solde HAVING MIN(v.date_vente) <= :l ORDER BY MIN(v.date_vente) LIMIT 100");
        $st->execute(['l' => $limite]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $j = (int)floor((time() - strtotime($r['depuis'])) / 86400);
            $a[] = ['type' => 'creances', 'id' => (int)$r['idclient'], 'label' => $r['nom'], 'detail' => 'doit ' . number_format($r['solde'], 0, ',', ' ') . ' depuis ' . $j . ' jours', 'niveau' => $j > 2 * (int)$reg['alertes.jours_credit'] ? 'danger' : 'warning', 'jours' => $j];
        }
    }
    if (in_array('ecart_caisse', $types, true)) {
        $st = $db->prepare("SELECT s.idsession, s.ecart, s.cloture_at, CONCAT(u.nomag, ' ', u.prenom) AS caissier FROM sessions_caisse s JOIN users u ON u.id_user = s.iduser WHERE s.statut = 'cloturee' AND s.cloture_at >= :d AND ABS(s.ecart) > :t ORDER BY s.cloture_at DESC LIMIT 20");
        $st->execute(['d' => gmdate('Y-m-d H:i:s', time() - 7 * 86400), 't' => (float)$reg['caisse.ecart_tolere']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $a[] = ['type' => 'ecart_caisse', 'id' => (int)$r['idsession'], 'label' => 'Caisse de ' . trim($r['caissier']), 'detail' => 'écart de ' . ($r['ecart'] > 0 ? '+' : '') . number_format($r['ecart'], 0, ',', ' ') . ' le ' . date('d/m', strtotime($r['cloture_at'])), 'niveau' => 'danger', 'jours' => 0];
        }
    }
    if (in_array('licence', $types, true)) {
        $l = licence_etat($reg);
        if ($l['defini'] && in_array($l['etat'], ['bientot', 'expiree'], true)) {
            $a[] = ['type' => 'licence', 'id' => 0, 'label' => "Licence d'utilisation", 'detail' => $l['etat'] === 'expiree' ? 'expirée depuis ' . (-$l['jours']) . ' j' : 'expire dans ' . $l['jours'] . ' j (' . date('d/m/Y', strtotime($l['fin'])) . ')', 'niveau' => $l['etat'] === 'expiree' ? 'danger' : 'warning', 'jours' => $l['jours']];
        }
    }
    return $a;
}
