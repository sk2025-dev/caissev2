<?php
/** Tableau de bord : chiffre d'affaires, marge, stock, créances, graphiques. Les agrégations se font en base. */

function dash_scalaire(PDO $db, $sql, array $p = [])
{
    $st = $db->prepare($sql); $st->execute($p);
    $v = $st->fetchColumn();
    return $v === null ? 0 : $v + 0;
}

function dashboard_data(PDO $db, array $user)
{
    $reg = settings_all($db);
    $aujourdhui = gmdate('Y-m-d'); $debutMois = gmdate('Y-m-01');
    $out = ['role' => $user['role'], 'session' => session_courante($db, $user['id'])];

    // Mes ventes du jour (tous les rôles de caisse)
    $st = $db->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(total), 0) AS ca FROM ventes WHERE iduser = :u AND statut = 'validee' AND date_vente >= :d");
    $st->execute(['u' => $user['id'], 'd' => $aujourdhui . ' 00:00:00']);
    $out['moi'] = array_map(function ($x) { return $x + 0; }, $st->fetch(PDO::FETCH_ASSOC));

    // Stock (magasinier, gérant)
    if (role_peut($user['role'], 'stock.lire')) {
        $suivi = "supp = 0 AND actif = 1 AND stockable = 1 AND (seuil_alerte > 0 OR EXISTS (SELECT 1 FROM mouvements_stock m WHERE m.idprod = produits.idprod))";
        $out['stock'] = [
            'valeur' => dash_scalaire($db, "SELECT COALESCE(SUM(stock_qty * prix_achat), 0) FROM produits WHERE supp = 0 AND stockable = 1 AND stock_qty > 0"),
            'valeur_vente' => dash_scalaire($db, "SELECT COALESCE(SUM(stock_qty * prix_vente), 0) FROM produits WHERE supp = 0 AND stockable = 1 AND stock_qty > 0"),
            'nb_produits' => (int)dash_scalaire($db, 'SELECT COUNT(*) FROM produits WHERE supp = 0 AND type = \'produit\''),
            'nb_services' => (int)dash_scalaire($db, 'SELECT COUNT(*) FROM produits WHERE supp = 0 AND type = \'service\''),
            'ruptures' => (int)dash_scalaire($db, "SELECT COUNT(*) FROM produits WHERE $suivi AND stock_qty <= 0"),
            'bas' => (int)dash_scalaire($db, "SELECT COUNT(*) FROM produits WHERE $suivi AND stock_qty > 0 AND seuil_alerte > 0 AND stock_qty <= seuil_alerte"),
        ];
        $out['stock']['a_surveiller'] = $db->query("SELECT idprod AS id, nom, unite, stock_qty, seuil_alerte FROM produits WHERE $suivi AND stock_qty <= GREATEST(seuil_alerte, 0) ORDER BY stock_qty, nom LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!role_peut($user['role'], 'ventes.lire_toutes')) return $out;

    // Ventes (gérant)
    $periode = function ($a, $b) use ($db) {
        $st = $db->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(total), 0) AS ca, COALESCE(SUM(tva), 0) AS tva, COALESCE(SUM(remise), 0) AS remises, COALESCE(SUM(cout_total), 0) AS cout FROM ventes WHERE statut = 'validee' AND date_vente >= :a AND date_vente <= :b");
        $st->execute(['a' => $a . ' 00:00:00', 'b' => $b . ' 23:59:59']);
        $r = array_map(function ($x) { return $x + 0; }, $st->fetch(PDO::FETCH_ASSOC));
        $r['marge'] = round($r['ca'] - $r['tva'] - $r['cout'], 2);
        $r['panier_moyen'] = $r['nb'] ? round($r['ca'] / $r['nb']) : 0;
        return $r;
    };
    $jour = $periode($aujourdhui, $aujourdhui);
    $hier = $periode(gmdate('Y-m-d', strtotime('-1 day')), gmdate('Y-m-d', strtotime('-1 day')));
    $mois = $periode($debutMois, $aujourdhui);
    // Mois précédent à nombre de jours écoulés égal (comparaison honnête)
    $jEcoules = (int)gmdate('j');
    $moisPrec = $periode(gmdate('Y-m-01', strtotime('first day of last month')), gmdate('Y-m-d', strtotime('first day of last month +' . ($jEcoules - 1) . ' days')));
    $delta = function ($cur, $prev) { return $prev > 0 ? round(($cur - $prev) * 100 / $prev, 1) : null; };
    $out['ventes'] = ['jour' => $jour, 'hier' => $hier, 'mois' => $mois, 'delta_jour' => $delta($jour['ca'], $hier['ca']), 'delta_mois' => $delta($mois['ca'], $moisPrec['ca'])];
    $out['tiers'] = [
        'creances' => dash_scalaire($db, 'SELECT COALESCE(SUM(solde), 0) FROM clients WHERE supp = 0 AND solde > 0'),
        'nb_debiteurs' => (int)dash_scalaire($db, 'SELECT COUNT(*) FROM clients WHERE supp = 0 AND solde > 0'),
        'dettes' => dash_scalaire($db, 'SELECT COALESCE(SUM(solde), 0) FROM fournisseurs WHERE supp = 0 AND solde > 0'),
    ];

    // 30 derniers jours
    $serie = [];
    for ($i = 29; $i >= 0; $i--) { $d = gmdate('Y-m-d', strtotime("-$i days")); $serie[$d] = ['jour' => $d, 'ca' => 0, 'nb' => 0, 'marge' => 0]; }
    $st = $db->prepare("SELECT SUBSTR(date_vente, 1, 10) AS jour, COUNT(*) AS nb, SUM(total) AS ca, SUM(total - tva - cout_total) AS marge FROM ventes WHERE statut = 'validee' AND date_vente >= :d GROUP BY SUBSTR(date_vente, 1, 10)");
    $st->execute(['d' => gmdate('Y-m-d', strtotime('-29 days')) . ' 00:00:00']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($serie[$r['jour']])) $serie[$r['jour']] = ['jour' => $r['jour'], 'ca' => $r['ca'] + 0, 'nb' => (int)$r['nb'], 'marge' => round($r['marge'], 2)];
    $out['serie'] = array_values($serie);

    $p = ['a' => $debutMois . ' 00:00:00'];
    $st = $db->prepare("SELECT l.idprod, MIN(l.designation) AS nom, SUM(l.quantite) AS quantite, SUM(l.total) AS ca FROM vente_lignes l JOIN ventes v ON v.idvente = l.idvente WHERE v.statut = 'validee' AND v.date_vente >= :a GROUP BY l.idprod ORDER BY ca DESC LIMIT 6");
    $st->execute($p);
    $out['top_produits'] = array_map(function ($r) { return ['id' => (int)$r['idprod'], 'nom' => $r['nom'], 'quantite' => $r['quantite'] + 0, 'ca' => $r['ca'] + 0]; }, $st->fetchAll(PDO::FETCH_ASSOC));

    $st = $db->prepare("SELECT COALESCE(c.nom, 'Sans catégorie') AS label, SUM(l.total) AS montant FROM vente_lignes l JOIN ventes v ON v.idvente = l.idvente LEFT JOIN produits pr ON pr.idprod = l.idprod LEFT JOIN categories c ON c.idcat = pr.idcat WHERE v.statut = 'validee' AND v.date_vente >= :a GROUP BY COALESCE(c.nom, 'Sans catégorie') ORDER BY montant DESC");
    $st->execute($p);
    $cats = $st->fetchAll(PDO::FETCH_ASSOC); $somme = 0; foreach ($cats as $c) $somme += $c['montant'];
    $items = []; $reste = 0;
    foreach ($cats as $i => $c) { if ($i < 5) $items[] = ['label' => $c['label'], 'montant' => $c['montant'] + 0]; else $reste += $c['montant']; }
    if ($reste > 0) $items[] = ['label' => 'Autres', 'montant' => $reste + 0];
    foreach ($items as &$it) $it['pct'] = $somme > 0 ? round($it['montant'] * 100 / $somme, 1) : 0;
    unset($it);
    $out['categories'] = ['total' => $somme + 0, 'items' => $items];

    $st = $db->prepare("SELECT COALESCE(m.libelle, p.mode) AS label, COALESCE(m.type, 'autre') AS type, SUM(p.montant) AS montant FROM vente_paiements p JOIN ventes v ON v.idvente = p.idvente LEFT JOIN modes_paiement m ON m.code = p.mode WHERE v.statut = 'validee' AND v.date_vente >= :a GROUP BY COALESCE(m.libelle, p.mode), COALESCE(m.type, 'autre') ORDER BY montant DESC");
    $st->execute($p);
    $rendu = dash_scalaire($db, "SELECT COALESCE(SUM(rendu), 0) FROM ventes WHERE statut = 'validee' AND date_vente >= :a", $p);
    $modes = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $m = $r['montant'] + 0; if ($r['type'] === 'especes') $m -= $rendu; $modes[] = ['label' => $r['label'], 'montant' => $m]; }
    $somme = array_sum(array_column($modes, 'montant'));
    foreach ($modes as &$m) $m['pct'] = $somme > 0 ? round($m['montant'] * 100 / $somme, 1) : 0;
    unset($m);
    $out['modes'] = ['total' => $somme, 'items' => $modes];

    $st = $db->prepare("SELECT CAST(SUBSTR(date_vente, 12, 2) AS UNSIGNED) AS h, COUNT(*) AS nb, SUM(total) AS ca FROM ventes WHERE statut = 'validee' AND date_vente >= :d GROUP BY CAST(SUBSTR(date_vente, 12, 2) AS UNSIGNED)");
    $st->execute(['d' => gmdate('Y-m-d', strtotime('-29 days')) . ' 00:00:00']);
    $heures = array_fill(0, 24, ['nb' => 0, 'ca' => 0]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $heures[(int)$r['h']] = ['nb' => (int)$r['nb'], 'ca' => $r['ca'] + 0];
    $out['heures'] = array_map(function ($h, $k) { return ['h' => $k] + $h; }, $heures, array_keys($heures));

    $st = $db->query("SELECT v.idvente, v.numero, v.date_vente, v.total, v.reste, c.nom AS client, CONCAT(u.nomag, ' ', u.prenom) AS caissier FROM ventes v JOIN users u ON u.id_user = v.iduser LEFT JOIN clients c ON c.idclient = v.idclient WHERE v.statut = 'validee' ORDER BY v.idvente DESC LIMIT 8");
    $out['dernieres'] = array_map(function ($r) { $r['caissier'] = trim($r['caissier']); $r['total'] += 0; $r['reste'] += 0; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));

    // Performance des caissiers (mois)
    $st = $db->prepare("SELECT CONCAT(u.nomag, ' ', u.prenom) AS caissier, COUNT(*) AS nb, SUM(v.total) AS ca FROM ventes v JOIN users u ON u.id_user = v.iduser WHERE v.statut = 'validee' AND v.date_vente >= :a GROUP BY v.iduser, u.nomag, u.prenom ORDER BY ca DESC LIMIT 6");
    $st->execute($p);
    $out['caissiers'] = array_map(function ($r) { return ['caissier' => trim($r['caissier']), 'nb' => (int)$r['nb'], 'ca' => $r['ca'] + 0]; }, $st->fetchAll(PDO::FETCH_ASSOC));
    return $out;
}
