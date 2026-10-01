<?php
/**
 * Stock : toute variation passe par un mouvement horodaté (traçabilité) et met à jour la quantité en stock
 * dans la même transaction, sur une ligne verrouillée (pas de vente simultanée qui dépasse le stock).
 */

const TYPES_MOUVEMENT = [
    'initial' => 'Stock initial', 'entree_achat' => "Entrée d'achat", 'sortie_vente' => 'Vente', 'annulation_vente' => 'Annulation de vente',
    'sortie_commande' => 'Commande', 'annulation_commande' => 'Annulation de commande',
    'ajustement' => 'Ajustement', 'perte' => 'Perte / casse', 'inventaire' => 'Inventaire',
];

function stock_transaction(PDO $db, callable $fn)
{
    if ($db->inTransaction()) return $fn();   // déjà dans une transaction (vente, réception…)
    $db->beginTransaction();
    try { $r = $fn(); $db->commit(); return $r; }
    catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

/** Verrouille et renvoie un produit actif ou non (lève 404 s'il n'existe pas). */
function produit_verrouille(PDO $db, $idprod)
{
    $st = $db->prepare('SELECT * FROM produits WHERE idprod = :i AND supp = 0 FOR UPDATE');
    $st->execute(['i' => $idprod]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new ApiError(404, 'Produit introuvable');
    return $p;
}

/**
 * Enregistre un mouvement de stock (delta signé). Sans effet pour un service.
 * Retourne le produit tel qu'il était avant le mouvement, avec 'stock_apres'.
 */
function stock_bouger(PDO $db, $userId, array $produit, $type, $delta, array $o = [])
{
    if (!(int)$produit['stockable']) return $produit + ['stock_apres' => null];
    $apres = round($produit['stock_qty'] + $delta, 3);
    $db->prepare('UPDATE produits SET stock_qty = :q WHERE idprod = :i')->execute(['q' => $apres, 'i' => $produit['idprod']]);
    $db->prepare('INSERT INTO mouvements_stock (idprod, type, quantite, stock_apres, cout_unitaire, ref_type, ref_id, motif, iduser, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$produit['idprod'], $type, $delta, $apres, isset($o['cout']) ? $o['cout'] : $produit['prix_achat'], $o['ref_type'] ?? '', $o['ref_id'] ?? null, mb_substr((string)($o['motif'] ?? ''), 0, 200), $userId, gmdate('Y-m-d H:i:s')]);
    return $produit + ['stock_apres' => $apres];
}

function nombre($v, $champ, $min = null, $max = null)
{
    $n = str_replace([' ', "\xC2\xA0"], '', str_replace(',', '.', (string)$v));
    if ($n === '' || !is_numeric($n)) throw new ApiError(422, 'Données invalides', [$champ => 'Nombre attendu']);
    $n = (float)$n;
    if (($min !== null && $n < $min) || ($max !== null && $n > $max)) throw new ApiError(422, 'Données invalides', [$champ => $max !== null ? "Valeur entre $min et $max" : "Valeur minimale : $min"]);
    return $n;
}

/** Ajustement manuel : correction (+/-), perte ou casse, ou fixation du stock à une valeur constatée. */
function stock_ajuster(PDO $db, array $user, array $in)
{
    exiger($user, 'stock.ecrire', 'Seul le magasinier ou le gérant peut modifier le stock.');
    $idprod = (int)($in['idprod'] ?? 0);
    $mode = (isset($in['mode']) && $in['mode'] === 'set') ? 'set' : 'delta';
    $type = (isset($in['type']) && $in['type'] === 'perte') ? 'perte' : 'ajustement';
    $motif = trim((string)($in['motif'] ?? ''));
    if ($motif === '') throw new ApiError(422, 'Données invalides', ['motif' => 'Le motif est obligatoire (traçabilité du stock)']);
    $q = nombre($in['quantite'] ?? '', 'quantite', $mode === 'set' ? 0 : null);

    return stock_transaction($db, function () use ($db, $user, $idprod, $mode, $type, $motif, $q) {
        $p = produit_verrouille($db, $idprod);
        if (!(int)$p['stockable']) throw new ApiError(422, 'Données invalides', ['idprod' => 'Un service n\'a pas de stock']);
        $delta = $mode === 'set' ? round($q - $p['stock_qty'], 3) : $q;
        if ($type === 'perte') $delta = -abs($delta);
        if ($delta == 0) throw new ApiError(422, 'Données invalides', ['quantite' => 'Aucun changement de stock']);
        if ($p['stock_qty'] + $delta < -0.0004 && settings_all($db)['caisse.stock_negatif'] !== '1') throw new ApiError(422, 'Données invalides', ['quantite' => 'Stock insuffisant : il ne reste que ' . rtrim(rtrim(number_format($p['stock_qty'], 3, '.', ''), '0'), '.') . ' en stock']);
        $r = stock_bouger($db, $user['id'], $p, $type, $delta, ['motif' => $motif, 'ref_type' => 'manuel']);
        return ['idprod' => $idprod, 'nom' => $p['nom'], 'avant' => (float)$p['stock_qty'], 'variation' => $delta, 'apres' => $r['stock_apres']];
    });
}

/** Inventaire : on saisit les quantités comptées, l'application génère un mouvement pour chaque écart. */
function inventaire_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'stock.ecrire', 'Seul le magasinier ou le gérant peut valider un inventaire.');
    $lignes = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    if (!$lignes) throw new ApiError(422, 'Données invalides', ['lignes' => 'Saisissez au moins un produit compté']);
    $date = isset($in['date']) && $in['date'] !== '' ? (string)$in['date'] : gmdate('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date > gmdate('Y-m-d')) throw new ApiError(422, 'Données invalides', ['date' => 'Date invalide']);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 300);

    $comptes = [];
    foreach ($lignes as $l) {
        $id = (int)($l['idprod'] ?? 0);
        if (isset($comptes[$id])) throw new ApiError(422, 'Données invalides', ['lignes' => 'Un produit apparaît deux fois']);
        $comptes[$id] = nombre($l['compte'] ?? '', 'lignes', 0);
    }
    ksort($comptes);   // ordre de verrouillage constant

    return stock_transaction($db, function () use ($db, $user, $comptes, $date, $note) {
        $db->prepare('INSERT INTO inventaires (date_inv, note, nb_lignes, iduser, created_at) VALUES (?,?,?,?,?)')->execute([$date, $note, count($comptes), $user['id'], gmdate('Y-m-d H:i:s')]);
        $idinv = (int)$db->lastInsertId();
        $nb = 0; $valeur = 0; $detail = [];
        foreach ($comptes as $id => $compte) {
            $p = produit_verrouille($db, $id);
            if (!(int)$p['stockable']) throw new ApiError(422, 'Données invalides', ['lignes' => $p['nom'] . ' est un service : pas de stock']);
            $ecart = round($compte - $p['stock_qty'], 3);
            $db->prepare('INSERT INTO inventaire_lignes (idinv, idprod, designation, attendu, compte, ecart, cout_unitaire) VALUES (?,?,?,?,?,?,?)')->execute([$idinv, $id, $p['nom'], $p['stock_qty'], $compte, $ecart, $p['prix_achat']]);
            if ($ecart != 0) {
                stock_bouger($db, $user['id'], $p, 'inventaire', $ecart, ['ref_type' => 'inventaire', 'ref_id' => $idinv, 'motif' => 'Inventaire du ' . date('d/m/Y', strtotime($date))]);
                $nb++; $valeur += $ecart * $p['prix_achat'];
                $detail[] = ['idprod' => $id, 'nom' => $p['nom'], 'attendu' => (float)$p['stock_qty'], 'compte' => $compte, 'ecart' => $ecart];
            }
        }
        $db->prepare('UPDATE inventaires SET nb_ecarts = ?, valeur_ecart = ? WHERE idinv = ?')->execute([$nb, round($valeur, 2), $idinv]);
        return ['idinv' => $idinv, 'nb_lignes' => count($comptes), 'nb_ecarts' => $nb, 'valeur_ecart' => round($valeur, 2), 'ecarts' => $detail];
    });
}

/** Historique des mouvements (filtres : produit, type, période) avec pagination. */
function mouvements_liste(PDO $db, array $q)
{
    $where = ['1=1']; $p = [];
    if (!empty($q['idprod'])) { $where[] = 'm.idprod = :prod'; $p['prod'] = (int)$q['idprod']; }
    if (!empty($q['type']) && isset(TYPES_MOUVEMENT[$q['type']])) { $where[] = 'm.type = :type'; $p['type'] = $q['type']; }
    if (!empty($q['from'])) { $where[] = 'm.created_at >= :f'; $p['f'] = $q['from'] . ' 00:00:00'; }
    if (!empty($q['to'])) { $where[] = 'm.created_at <= :t'; $p['t'] = $q['to'] . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $st = $db->prepare("SELECT COUNT(*) FROM mouvements_stock m WHERE $w"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT m.*, pr.nom AS produit, pr.unite, CONCAT(u.nomag, ' ', u.prenom) AS utilisateur FROM mouvements_stock m JOIN produits pr ON pr.idprod = m.idprod LEFT JOIN users u ON u.id_user = m.iduser WHERE $w ORDER BY m.created_at DESC, m.idmvt DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['libelle_type'] = TYPES_MOUVEMENT[$r['type']] ?? $r['type']; $r['utilisateur'] = trim((string)$r['utilisateur']); }
    return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}

/**
 * Balance des stocks d'un mois, par produit stockable : stock initial + entrées − sorties ± ajustements = stock final.
 * Les soldes sont reconstitués depuis le stock actuel en remontant les mouvements (stock final = actuel − mouvements postérieurs).
 * Valorisation au prix d'achat actuel.
 */
function stock_balance(PDO $db, $mois)
{
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$mois)) $mois = gmdate('Y-m');
    $debut = $mois . '-01 00:00:00'; $fin = gmdate('Y-m-t', strtotime($mois . '-01')) . ' 23:59:59';
    $st = $db->prepare("SELECT pr.idprod, pr.nom, pr.unite, pr.prix_achat, pr.stock_qty, c.nom AS categorie,
            COALESCE(SUM(CASE WHEN m.created_at > :fin1 THEN m.quantite END), 0) AS apres,
            COALESCE(SUM(CASE WHEN m.created_at >= :deb1 AND m.created_at <= :fin2 AND m.type IN ('initial', 'entree_achat') THEN m.quantite END), 0) AS entrees,
            COALESCE(SUM(CASE WHEN m.created_at >= :deb2 AND m.created_at <= :fin3 AND m.type IN ('sortie_vente', 'annulation_vente', 'sortie_commande', 'annulation_commande') THEN m.quantite END), 0) AS sorties,
            COALESCE(SUM(CASE WHEN m.created_at >= :deb3 AND m.created_at <= :fin4 AND m.type IN ('ajustement', 'perte', 'inventaire') THEN m.quantite END), 0) AS ajustements,
            COUNT(CASE WHEN m.created_at >= :deb4 AND m.created_at <= :fin5 THEN 1 END) AS nb
        FROM produits pr LEFT JOIN categories c ON c.idcat = pr.idcat LEFT JOIN mouvements_stock m ON m.idprod = pr.idprod
        WHERE pr.supp = 0 AND pr.stockable = 1 AND pr.type = 'produit'
        GROUP BY pr.idprod, pr.nom, pr.unite, pr.prix_achat, pr.stock_qty, c.nom ORDER BY c.nom, pr.nom");
    $st->execute(['fin1' => $fin, 'fin2' => $fin, 'fin3' => $fin, 'fin4' => $fin, 'fin5' => $fin, 'deb1' => $debut, 'deb2' => $debut, 'deb3' => $debut, 'deb4' => $debut]);
    $rows = []; $tot = ['valeur_initiale' => 0, 'valeur_entrees' => 0, 'valeur_sorties' => 0, 'valeur_ajustements' => 0, 'valeur_finale' => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $final = round($r['stock_qty'] - $r['apres'], 3);
        $e = $r['entrees'] + 0; $s = -($r['sorties'] + 0); $a = $r['ajustements'] + 0;
        $initial = round($final - $e + $s - $a, 3);
        $pa = $r['prix_achat'] + 0;
        $ligne = ['idprod' => (int)$r['idprod'], 'nom' => $r['nom'], 'categorie' => $r['categorie'] ?: 'Sans catégorie', 'unite' => $r['unite'], 'prix_achat' => $pa,
            'initial' => $initial, 'entrees' => $e, 'sorties' => $s, 'ajustements' => $a, 'final' => $final, 'nb' => (int)$r['nb'], 'valeur_finale' => round($final * $pa, 2)];
        $tot['valeur_initiale'] += $initial * $pa; $tot['valeur_entrees'] += $e * $pa; $tot['valeur_sorties'] += $s * $pa; $tot['valeur_ajustements'] += $a * $pa; $tot['valeur_finale'] += $final * $pa;
        $rows[] = $ligne;
    }
    return ['mois' => $mois, 'produits' => $rows, 'totaux' => array_map(function ($v) { return round($v, 2); }, $tot)];
}
