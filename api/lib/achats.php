<?php
/**
 * Approvisionnements (réception de marchandises), coût moyen pondéré, factures et règlements des fournisseurs, règlements des clients.
 * Confidentialité : une réception n'est rattachée à aucun fournisseur. Ce qu'on doit à un fournisseur vient de ses factures.
 */

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
 * Réception de marchandises : augmente le stock et recalcule le coût d'achat moyen pondéré de chaque produit
 * (CMUP = (stock × coût actuel + quantité reçue × coût d'achat) / nouveau stock). Ni fournisseur, ni paiement, ni dette.
 */
function appro_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'achats.ecrire', "Seul le magasinier ou le gérant peut enregistrer une réception.");
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

    return stock_transaction($db, function () use ($db, $user, $date, $lignes, $total, $in) {
        $numero = numero_suivant($db, 'appro', 'AP');
        $db->prepare('INSERT INTO approvisionnements (idfour, numero, numero_bon, date_appro, total, paye, reste, note, statut, iduser, created_at) VALUES (NULL,?,?,?,?,0,0,?,?,?,?)')
            ->execute([$numero, mb_substr(trim((string)($in['numero_bon'] ?? '')), 0, 60), $date, $total, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), 'recue', $user['id'], gmdate('Y-m-d H:i:s')]);
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
        return appro_detail($db, $idappro);
    });
}

const APPRO_CHAMPS = 'a.idappro, a.numero, a.numero_bon, a.date_appro, a.total, a.note, a.statut, a.created_at';   // jamais le fournisseur ni le paiement

function appro_detail(PDO $db, $id)
{
    $st = $db->prepare('SELECT ' . APPRO_CHAMPS . ", CONCAT(u.nomag, ' ', u.prenom) AS utilisateur FROM approvisionnements a LEFT JOIN users u ON u.id_user = a.iduser WHERE a.idappro = :i");
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
    if (!empty($q['from'])) { $where[] = 'a.date_appro >= :d1'; $p['d1'] = $q['from']; }
    if (!empty($q['to'])) { $where[] = 'a.date_appro <= :d2'; $p['d2'] = $q['to']; }
    if (!empty($q['q'])) { $where[] = '(a.numero LIKE :q OR a.numero_bon LIKE :q2 OR a.note LIKE :q3)'; $like = '%' . trim($q['q']) . '%'; $p['q'] = $like; $p['q2'] = $like; $p['q3'] = $like; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $st = $db->prepare("SELECT COUNT(*) FROM approvisionnements a WHERE $w"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare('SELECT ' . APPRO_CHAMPS . ", CONCAT(u.nomag, ' ', u.prenom) AS utilisateur, (SELECT COUNT(*) FROM appro_lignes l WHERE l.idappro = a.idappro) AS nb_lignes FROM approvisionnements a LEFT JOIN users u ON u.id_user = a.iduser WHERE $w ORDER BY a.date_appro DESC, a.idappro DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    return ['data' => $st->fetchAll(PDO::FETCH_ASSOC), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}

/* ---------- Factures fournisseurs : ce qu'on lui doit (indépendant des réceptions) ---------- */

function facture_fournisseur_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'achats.ecrire', "Seul le magasinier ou le gérant peut enregistrer une facture fournisseur.");
    $err = [];
    $date = trim((string)($in['date_facture'] ?? '')) ?: gmdate('Y-m-d');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $date > gmdate('Y-m-d')) $err['date_facture'] = 'Date invalide';
    $montant = round(nombre($in['montant'] ?? '', 'montant', 0.01), 2);
    if ($err) throw new ApiError(422, 'Données invalides', $err);
    $id = (int)($in['idfour'] ?? 0);
    return stock_transaction($db, function () use ($db, $user, $in, $id, $date, $montant) {
        $st = $db->prepare('SELECT * FROM fournisseurs WHERE idfour = ? AND supp = 0 FOR UPDATE'); $st->execute([$id]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) throw new ApiError(404, 'Fournisseur introuvable');
        $db->prepare('INSERT INTO fournisseur_factures (idfour, reference, date_facture, montant, note, iduser, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id, mb_substr(trim((string)($in['reference'] ?? '')), 0, 60), $date, $montant, mb_substr(trim((string)($in['note'] ?? '')), 0, 300), $user['id'], gmdate('Y-m-d H:i:s')]);
        $idfacture = (int)$db->lastInsertId();
        $db->prepare('UPDATE fournisseurs SET solde = solde + ? WHERE idfour = ?')->execute([$montant, $id]);
        return ['idfacture' => $idfacture, 'fournisseur' => $f['nom'], 'montant' => $montant, 'solde' => round($f['solde'] + $montant, 2)];
    });
}

/** Facture saisie par erreur : retirée par le gérant, tant qu'elle n'a pas déjà été payée (le solde dû la couvre encore). */
function facture_fournisseur_annuler(PDO $db, array $user, $idfacture)
{
    if (!is_admin_role($user['role'])) throw new ApiError(403, "Seul le gérant peut retirer une facture fournisseur.");
    return stock_transaction($db, function () use ($db, $idfacture) {
        $st = $db->prepare('SELECT x.*, f.nom, f.solde FROM fournisseur_factures x JOIN fournisseurs f ON f.idfour = x.idfour WHERE x.idfacture = ? AND x.supp = 0 FOR UPDATE'); $st->execute([(int)$idfacture]);
        $x = $st->fetch(PDO::FETCH_ASSOC);
        if (!$x) throw new ApiError(404, 'Facture introuvable');
        if ((float)$x['solde'] < (float)$x['montant'] - 0.004) throw new ApiError(409, 'Cette facture a déjà été réglée en partie : le solde dû au fournisseur est inférieur à son montant.');
        $db->prepare('UPDATE fournisseur_factures SET supp = 1 WHERE idfacture = ?')->execute([(int)$idfacture]);
        $db->prepare('UPDATE fournisseurs SET solde = solde - ? WHERE idfour = ?')->execute([$x['montant'], $x['idfour']]);
        return ['idfour' => (int)$x['idfour'], 'fournisseur' => $x['nom'], 'montant' => (float)$x['montant'], 'reference' => $x['reference']];
    });
}

function factures_fournisseur(PDO $db, $idfour, $limite = 20)
{
    $st = $db->prepare("SELECT x.idfacture, x.reference, x.date_facture, x.montant, x.note, x.created_at, CONCAT(u.nomag, ' ', u.prenom) AS utilisateur FROM fournisseur_factures x LEFT JOIN users u ON u.id_user = x.iduser WHERE x.idfour = ? AND x.supp = 0 ORDER BY x.date_facture DESC, x.idfacture DESC LIMIT " . (int)$limite);
    $st->execute([(int)$idfour]);
    return array_map(function ($r) { $r['utilisateur'] = trim((string)$r['utilisateur']); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
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
