<?php
/** Approvisionnements (réception de marchandises), coût moyen pondéré et règlements des clients / fournisseurs. */

function numero_suivant(PDO $db, $cle, $prefixe)
{
    $annee = gmdate('Y');
    $k = $cle . '-' . $annee;
    $db->prepare('INSERT INTO compteurs (cle, valeur) VALUES (?, 0) ON DUPLICATE KEY UPDATE valeur = valeur')->execute([$k]);
    $db->prepare('UPDATE compteurs SET valeur = LAST_INSERT_ID(valeur + 1) WHERE cle = ?')->execute([$k]);
    return sprintf('%s-%s-%06d', $prefixe, $annee, (int)$db->query('SELECT LAST_INSERT_ID()')->fetchColumn());
}

function modes_actifs(PDO $db)
{
    $out = [];
    foreach ($db->query('SELECT * FROM modes_paiement WHERE actif = 1 ORDER BY ordre')->fetchAll(PDO::FETCH_ASSOC) as $m) $out[$m['code']] = $m;
    return $out;
}

/**
 * Réception de marchandises : augmente le stock, recalcule le coût d'achat moyen pondéré de chaque produit
 * (CMUP = (stock × coût actuel + quantité reçue × coût d'achat) / nouveau stock) et enregistre la dette fournisseur.
 */
function appro_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'achats.ecrire', "Seul le magasinier ou le gérant peut enregistrer une réception.");
    $idfour = (int)($in['idfour'] ?? 0);
    $st = $db->prepare('SELECT * FROM fournisseurs WHERE idfour = :i AND supp = 0'); $st->execute(['i' => $idfour]);
    $four = $st->fetch(PDO::FETCH_ASSOC);
    if (!$four) throw new ApiError(422, 'Données invalides', ['idfour' => 'Choisissez un fournisseur']);
    $date = isset($in['date_appro']) && $in['date_appro'] !== '' ? (string)$in['date_appro'] : gmdate('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date > gmdate('Y-m-d')) throw new ApiError(422, 'Données invalides', ['date_appro' => 'Date invalide']);
    $lignesIn = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    if (!$lignesIn) throw new ApiError(422, 'Données invalides', ['lignes' => 'Ajoutez au moins un produit']);

    $lignes = [];
    foreach ($lignesIn as $l) {
        $id = (int)($l['idprod'] ?? 0);
        $lignes[] = ['idprod' => $id, 'q' => nombre($l['quantite'] ?? '', 'lignes', 0.001), 'cout' => nombre($l['cout_unitaire'] ?? '', 'lignes', 0)];
    }
    $total = round(array_sum(array_map(function ($l) { return $l['q'] * $l['cout']; }, $lignes)), 2);
    $paye = round(nombre($in['paye'] ?? 0, 'paye', 0), 2);
    if ($paye > $total + 0.004) throw new ApiError(422, 'Données invalides', ['paye' => 'Le montant payé dépasse le total']);
    $mode = (string)($in['mode'] ?? 'especes');
    if ($paye > 0 && !isset(modes_actifs($db)[$mode])) throw new ApiError(422, 'Données invalides', ['mode' => 'Mode de paiement inconnu']);

    return stock_transaction($db, function () use ($db, $user, $four, $date, $lignes, $total, $paye, $mode, $in) {
        $numero = numero_suivant($db, 'appro', 'AP');
        $reste = round($total - $paye, 2);
        $db->prepare('INSERT INTO approvisionnements (idfour, numero, numero_bon, date_appro, total, paye, reste, note, statut, iduser, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$four['idfour'], $numero, mb_substr(trim((string)($in['numero_bon'] ?? '')), 0, 60), $date, $total, $paye, $reste, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), 'recue', $user['id'], gmdate('Y-m-d H:i:s')]);
        $idappro = (int)$db->lastInsertId();

        // Verrouillage dans l'ordre des identifiants (évite les blocages croisés), traitement dans l'ordre saisi
        $verrous = [];
        $ids = array_unique(array_column($lignes, 'idprod')); sort($ids);
        foreach ($ids as $id) { $verrous[$id] = produit_verrouille($db, $id); if (!(int)$verrous[$id]['stockable']) throw new ApiError(422, 'Données invalides', ['lignes' => $verrous[$id]['nom'] . ' est un service : pas de réception de stock']); }
        foreach ($lignes as $l) {
            $p = produit_verrouille($db, $l['idprod']);     // état à jour (plusieurs lignes du même produit)
            $base = max(0, (float)$p['stock_qty']);
            $nouveauCout = ($base + $l['q']) > 0 ? round(($base * $p['prix_achat'] + $l['q'] * $l['cout']) / ($base + $l['q']), 2) : $l['cout'];
            $db->prepare('UPDATE produits SET prix_achat = ? WHERE idprod = ?')->execute([$nouveauCout, $p['idprod']]);
            $db->prepare('INSERT INTO appro_lignes (idappro, idprod, designation, quantite, cout_unitaire, total) VALUES (?,?,?,?,?,?)')->execute([$idappro, $p['idprod'], $p['nom'], $l['q'], $l['cout'], round($l['q'] * $l['cout'], 2)]);
            stock_bouger($db, $user['id'], $p, 'entree_achat', $l['q'], ['cout' => $l['cout'], 'ref_type' => 'appro', 'ref_id' => $idappro, 'motif' => 'Réception ' . $numero]);
        }
        if ($reste > 0) $db->prepare('UPDATE fournisseurs SET solde = solde + ? WHERE idfour = ?')->execute([$reste, $four['idfour']]);
        if ($paye > 0) $db->prepare('INSERT INTO reglements (tiers_type, tiers_id, montant, mode, reference, note, iduser, created_at) VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['fournisseur', $four['idfour'], $paye, $mode, $numero, 'Paiement à la réception', $user['id'], gmdate('Y-m-d H:i:s')]);
        return appro_detail($db, $idappro);
    });
}

function appro_detail(PDO $db, $id)
{
    $st = $db->prepare("SELECT a.*, f.nom AS fournisseur, CONCAT(u.nomag, ' ', u.prenom) AS utilisateur FROM approvisionnements a JOIN fournisseurs f ON f.idfour = a.idfour LEFT JOIN users u ON u.id_user = a.iduser WHERE a.idappro = :i");
    $st->execute(['i' => $id]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) throw new ApiError(404, 'Réception introuvable');
    $l = $db->prepare('SELECT * FROM appro_lignes WHERE idappro = :i ORDER BY idligne'); $l->execute(['i' => $id]);
    $a['lignes'] = $l->fetchAll(PDO::FETCH_ASSOC);
    $a['utilisateur'] = trim((string)$a['utilisateur']);
    return $a;
}

function appros_liste(PDO $db, array $q)
{
    $where = ['1=1']; $p = [];
    if (!empty($q['idfour'])) { $where[] = 'a.idfour = :f'; $p['f'] = (int)$q['idfour']; }
    if (!empty($q['from'])) { $where[] = 'a.date_appro >= :d1'; $p['d1'] = $q['from']; }
    if (!empty($q['to'])) { $where[] = 'a.date_appro <= :d2'; $p['d2'] = $q['to']; }
    if (!empty($q['q'])) { $where[] = '(a.numero LIKE :q OR a.numero_bon LIKE :q2 OR f.nom LIKE :q3)'; $like = '%' . trim($q['q']) . '%'; $p['q'] = $like; $p['q2'] = $like; $p['q3'] = $like; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $st = $db->prepare("SELECT COUNT(*) FROM approvisionnements a JOIN fournisseurs f ON f.idfour = a.idfour WHERE $w"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT a.*, f.nom AS fournisseur, (SELECT COUNT(*) FROM appro_lignes l WHERE l.idappro = a.idappro) AS nb_lignes FROM approvisionnements a JOIN fournisseurs f ON f.idfour = a.idfour WHERE $w ORDER BY a.date_appro DESC, a.idappro DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    return ['data' => $st->fetchAll(PDO::FETCH_ASSOC), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}

/** Règlement d'une dette fournisseur ou d'une créance client (paiement partiel possible, jamais supérieur au solde). */
function reglement_creer(PDO $db, array $user, array $in)
{
    $type = (isset($in['tiers_type']) && $in['tiers_type'] === 'fournisseur') ? 'fournisseur' : 'client';
    exiger($user, $type === 'client' ? 'clients.ecrire' : 'achats.ecrire');
    $id = (int)($in['tiers_id'] ?? 0);
    $montant = round(nombre($in['montant'] ?? '', 'montant', 0.01), 2);
    $mode = (string)($in['mode'] ?? 'especes');
    $mp = modes_actifs($db);
    if (!isset($mp[$mode])) throw new ApiError(422, 'Données invalides', ['mode' => 'Mode de paiement inconnu']);

    return stock_transaction($db, function () use ($db, $user, $type, $id, $montant, $mode, $mp, $in) {
        $table = $type === 'client' ? 'clients' : 'fournisseurs'; $pk = $type === 'client' ? 'idclient' : 'idfour';
        $st = $db->prepare("SELECT * FROM $table WHERE $pk = :i AND supp = 0 FOR UPDATE"); $st->execute(['i' => $id]);
        $t = $st->fetch(PDO::FETCH_ASSOC);
        if (!$t) throw new ApiError(404, $type === 'client' ? 'Client introuvable' : 'Fournisseur introuvable');
        if ($montant > $t['solde'] + 0.004) throw new ApiError(422, 'Données invalides', ['montant' => 'Supérieur au solde dû (' . number_format($t['solde'], 0, ',', ' ') . ')']);
        $idsession = null;
        if ($type === 'client' && $mp[$mode]['type'] === 'especes') { $s = session_courante($db, $user['id']); $idsession = $s ? (int)$s['idsession'] : null; }   // espèces encaissées : comptées dans la caisse ouverte
        $db->prepare("UPDATE $table SET solde = solde - ? WHERE $pk = ?")->execute([$montant, $id]);
        $db->prepare('INSERT INTO reglements (tiers_type, tiers_id, montant, mode, reference, note, idsession, iduser, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$type, $id, $montant, $mode, mb_substr(trim((string)($in['reference'] ?? '')), 0, 80), mb_substr(trim((string)($in['note'] ?? '')), 0, 200), $idsession, $user['id'], gmdate('Y-m-d H:i:s')]);
        return ['idreg' => (int)$db->lastInsertId(), 'tiers' => $t['nom'], 'montant' => $montant, 'solde' => round($t['solde'] - $montant, 2), 'dans_la_caisse' => $idsession !== null];
    });
}

function reglements_liste(PDO $db, $type, $id, $limite = 20)
{
    $st = $db->prepare("SELECT r.*, CONCAT(u.nomag, ' ', u.prenom) AS utilisateur FROM reglements r LEFT JOIN users u ON u.id_user = r.iduser WHERE r.tiers_type = :t AND r.tiers_id = :i ORDER BY r.idreg DESC LIMIT " . (int)$limite);
    $st->execute(['t' => $type, 'i' => $id]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
