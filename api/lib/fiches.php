<?php
/** Fiches détaillées (produit, client, fournisseur) et recherche globale. */

function produit_detail(PDO $db, array $user, $id, array $resources)
{
    if (!$id) throw new ApiError(400, 'Identifiant requis');
    $p = (new Crud($db, 'produits', $resources['produits'], $user))->find($id);
    $st = $db->prepare("SELECT COALESCE(SUM(l.quantite), 0) AS qte, COALESCE(SUM(l.total), 0) AS ca, COUNT(DISTINCT l.idvente) AS nb_ventes FROM vente_lignes l JOIN ventes v ON v.idvente = l.idvente WHERE l.idprod = :i AND v.statut = 'validee' AND v.date_vente >= :d");
    $st->execute(['i' => $id, 'd' => gmdate('Y-m-d', strtotime('-29 days')) . ' 00:00:00']);
    $ventes30 = array_map(function ($x) { return $x + 0; }, $st->fetch(PDO::FETCH_ASSOC));
    $mv = mouvements_liste($db, ['idprod' => $id, 'per_page' => 15]);
    // Jours de stock restant au rythme des 30 derniers jours
    $rythme = $ventes30['qte'] / 30;
    return ['produit' => $p, 'ventes_30j' => $ventes30, 'mouvements' => $mv['data'], 'jours_restants' => ($p['stockable'] && $rythme > 0) ? (int)floor($p['stock_qty'] / $rythme) : null];
}

function client_detail(PDO $db, array $user, $id, array $resources)
{
    if (!$id) throw new ApiError(400, 'Identifiant requis');
    $c = (new Crud($db, 'clients', $resources['clients'], $user))->find($id);
    $ventes = ventes_liste($db, $user, ['idclient' => $id, 'per_page' => 15]);
    return ['client' => $c, 'ventes' => $ventes['data'], 'reglements' => reglements_liste($db, 'client', $id)];
}

function fournisseur_detail(PDO $db, array $user, $id, array $resources)
{
    if (!$id) throw new ApiError(400, 'Identifiant requis');
    $f = (new Crud($db, 'fournisseurs', $resources['fournisseurs'], $user))->find($id);
    $appros = appros_liste($db, ['idfour' => $id, 'per_page' => 15]);
    return ['fournisseur' => $f, 'appros' => $appros['data'], 'reglements' => reglements_liste($db, 'fournisseur', $id)];
}

function recherche_globale(PDO $db, array $user, $q)
{
    $q = trim((string)$q);
    $out = ['produits' => [], 'clients' => [], 'ventes' => [], 'fournisseurs' => []];
    if (mb_strlen($q) < 2) return $out;
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
    $st = $db->prepare('SELECT idprod AS id, nom AS label, CONCAT(COALESCE(NULLIF(sku, \'\'), \'\'), \' \', code_barres) AS sub FROM produits WHERE supp = 0 AND (nom LIKE :a OR sku LIKE :b OR code_barres LIKE :c) ORDER BY nom LIMIT 5');
    $st->execute(['a' => $like, 'b' => $like, 'c' => $like]);
    $out['produits'] = array_map(function ($r) { $r['sub'] = trim($r['sub']); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $db->prepare('SELECT idclient AS id, nom AS label, telephone AS sub FROM clients WHERE supp = 0 AND (nom LIKE :a OR telephone LIKE :b) ORDER BY nom LIMIT 5');
    $st->execute(['a' => $like, 'b' => $like]);
    $out['clients'] = $st->fetchAll(PDO::FETCH_ASSOC);
    if (role_peut($user['role'], 'fournisseurs.lire')) {
        $st = $db->prepare('SELECT idfour AS id, nom AS label, telephone AS sub FROM fournisseurs WHERE supp = 0 AND (nom LIKE :a OR telephone LIKE :b) ORDER BY nom LIMIT 5');
        $st->execute(['a' => $like, 'b' => $like]);
        $out['fournisseurs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    if (role_peut($user['role'], 'ventes.lire_toutes') || role_peut($user['role'], 'ventes.lire_siennes')) {
        $w = role_peut($user['role'], 'ventes.lire_toutes') ? '' : ' AND iduser = ' . (int)$user['id'];
        $st = $db->prepare("SELECT idvente AS id, numero AS label, CONCAT(DATE_FORMAT(date_vente, '%d/%m/%Y %H:%i'), ' — ', FORMAT(total, 0)) AS sub FROM ventes WHERE numero LIKE :a$w ORDER BY idvente DESC LIMIT 5");
        $st->execute(['a' => $like]);
        $out['ventes'] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}
