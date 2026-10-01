<?php
/**
 * Ventes au comptoir. Le serveur recalcule TOUT (prix, remises, TVA, totaux, rendu de monnaie) à partir du catalogue :
 * l'écran de caisse n'envoie que des produits, des quantités et des paiements.
 */

function err_lignes($msg, $code = null) { throw new ApiError(422, $msg, ['lignes' => $msg], $code); }

/** Options internes à la remise d'une commande : prix figés et stock déjà sorti (jamais lus dans les données HTTP). */
function vente_creer(PDO $db, array $user, array $in, $prixFiges = false, $stockDejaSorti = false)
{
    exiger($user, 'caisse.vendre');
    $reg = settings_all($db);

    // Idempotence : si l'écran renvoie la même vente (réseau instable, double clic), on retourne la vente déjà enregistrée
    $cle = isset($in['cle']) ? substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string)$in['cle']), 0, 40) : '';
    if ($cle !== '') {
        $st = $db->prepare('SELECT idvente FROM ventes WHERE cle = :c'); $st->execute(['c' => $cle]);
        if ($deja = $st->fetchColumn()) return vente_detail($db, $user, (int)$deja) + ['deja_enregistree' => true];
    }
    $session = session_courante($db, $user['id']);
    if (!$session) throw new ApiError(409, 'Ouvrez votre caisse avant de vendre.', null, 'caisse_fermee');

    $lignesIn = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    if (!$lignesIn) err_lignes('Le panier est vide');
    if (count($lignesIn) > 200) err_lignes('200 lignes maximum par vente');
    $remiseGlobale = round(nombre($in['remise'] ?? 0, 'remise', 0), 2);
    $paiementsIn = isset($in['paiements']) && is_array($in['paiements']) ? $in['paiements'] : [];
    $modes = modes_actifs($db);
    $admin = role_peut($user['role'], 'ventes.lire_toutes');
    $idclient = !empty($in['idclient']) ? (int)$in['idclient'] : null;

    return stock_transaction($db, function () use ($db, $user, $reg, $cle, $session, $lignesIn, $remiseGlobale, $paiementsIn, $modes, $admin, $idclient, $in, $prixFiges, $stockDejaSorti) {
        // 1. Produits verrouillés (ordre croissant) puis lignes recalculées depuis le catalogue
        $ids = array_unique(array_map(function ($l) { return (int)($l['idprod'] ?? 0); }, $lignesIn)); sort($ids);
        $produits = [];
        foreach ($ids as $id) $produits[$id] = produit_verrouille($db, $id);

        $lignes = []; $brutTotal = 0; $remisesLignes = 0; $sousTotal = 0; $tvaBrute = 0; $coutTotal = 0; $qteParProduit = [];
        foreach ($lignesIn as $l) {
            $p = $produits[(int)($l['idprod'] ?? 0)] ?? null;
            if (!$p) err_lignes('Produit inconnu dans le panier');
            if (!(int)$p['actif']) err_lignes($p['nom'] . ' est désactivé');
            $q = nombre($l['quantite'] ?? '', 'lignes', 0.001, 100000);
            if ($p['type'] === 'produit' && $p['unite'] === 'pièce' && floor($q) != $q) err_lignes('Quantité entière attendue pour ' . $p['nom']);
            $pu = (float)$p['prix_vente'];
            if (isset($l['prix_unitaire']) && $l['prix_unitaire'] !== '' && abs((float)$l['prix_unitaire'] - $pu) > 0.004) {
                if ($reg['caisse.prix_libre'] !== '1' && !$admin && !$prixFiges) throw new ApiError(403, 'Seul un responsable peut modifier un prix de vente.', null, 'prix_interdit');
                $pu = round(nombre($l['prix_unitaire'], 'lignes', 0), 2);
            }
            $brut = round($pu * $q, 2);
            $remise = round(nombre($l['remise'] ?? 0, 'lignes', 0), 2);
            if ($remise > $brut + 0.004) err_lignes('Remise supérieure au prix de ' . $p['nom']);
            $total = round($brut - $remise, 2);
            $taux = (float)$p['tva_taux'];
            $lignes[] = ['p' => $p, 'q' => $q, 'pu' => $pu, 'remise' => $remise, 'total' => $total, 'taux' => $taux];
            $brutTotal += $brut; $remisesLignes += $remise; $sousTotal += $total;
            $tvaBrute += $taux > 0 ? $total * $taux / (100 + $taux) : 0;
            $coutTotal += $q * (float)$p['prix_achat'];
            if ((int)$p['stockable']) $qteParProduit[$p['idprod']] = ($qteParProduit[$p['idprod']] ?? 0) + $q;
        }
        $sousTotal = round($sousTotal, 2);
        if ($remiseGlobale > $sousTotal + 0.004) throw new ApiError(422, 'Remise supérieure au total', ['remise' => 'Remise supérieure au total']);
        $total = round($sousTotal - $remiseGlobale, 2);
        if ($total <= 0) throw new ApiError(422, 'Le total de la vente doit être supérieur à zéro.', ['lignes' => 'Total nul']);
        $pctRemise = $brutTotal > 0 ? ($remisesLignes + $remiseGlobale) / $brutTotal * 100 : 0;
        if (!$admin && $pctRemise > (float)$reg['caisse.remise_max'] + 0.001) throw new ApiError(403, 'Remise limitée à ' . $reg['caisse.remise_max'] . ' % pour un caissier (demandée : ' . round($pctRemise, 1) . ' %).', null, 'remise_interdite');
        $tva = $sousTotal > 0 ? round($tvaBrute * ($total / $sousTotal), 2) : 0;

        // 2. Stock disponible
        foreach ($stockDejaSorti ? [] : $qteParProduit as $id => $q) {
            if ($reg['caisse.stock_negatif'] !== '1' && $produits[$id]['stock_qty'] < $q - 0.0004) {
                throw new ApiError(422, 'Stock insuffisant pour ' . $produits[$id]['nom'] . ' (disponible : ' . rtrim(rtrim(number_format($produits[$id]['stock_qty'], 3, '.', ''), '0'), '.') . ').', ['lignes' => 'Stock insuffisant pour ' . $produits[$id]['nom']], 'stock_insuffisant');
            }
        }

        // 3. Paiements : plusieurs modes possibles ; seul l'excédent versé en espèces est rendu
        $paye = 0; $especes = 0; $paiements = [];
        foreach ($paiementsIn as $pa) {
            $mode = (string)($pa['mode'] ?? '');
            if (!isset($modes[$mode])) throw new ApiError(422, 'Données invalides', ['paiements' => 'Mode de paiement inconnu : ' . $mode]);
            $m = round(nombre($pa['montant'] ?? '', 'paiements', 0.01), 2);
            $paye += $m; if ($modes[$mode]['type'] === 'especes') $especes += $m;
            $paiements[] = ['mode' => $mode, 'montant' => $m, 'reference' => mb_substr(trim((string)($pa['reference'] ?? '')), 0, 80)];
        }
        $paye = round($paye, 2); $rendu = 0;
        if ($paye > $total + 0.004) {
            $exces = round($paye - $total, 2);
            if ($exces > $especes + 0.004) throw new ApiError(422, 'Le montant payé dépasse le total', ['paiements' => 'Le montant payé dépasse le total']);
            $rendu = $exces;
        }
        $reste = round($total - ($paye - $rendu), 2);
        if ($reste < 0.005) $reste = 0;
        $client = null;
        if ($idclient) {
            $st = $db->prepare('SELECT * FROM clients WHERE idclient = :i AND supp = 0 FOR UPDATE'); $st->execute(['i' => $idclient]);
            $client = $st->fetch(PDO::FETCH_ASSOC);
            if (!$client) throw new ApiError(422, 'Données invalides', ['idclient' => 'Client inconnu']);
        }
        if ($reste > 0) {
            if (!$client) throw new ApiError(422, 'Choisissez un client pour enregistrer le reste à crédit (' . number_format($reste, 0, ',', ' ') . ').', ['idclient' => 'Client obligatoire pour une vente à crédit'], 'client_requis');
            if ($client['plafond_credit'] > 0 && $client['solde'] + $reste > $client['plafond_credit'] + 0.004) {
                throw new ApiError(422, 'Plafond de crédit dépassé pour ' . $client['nom'] . ' (plafond ' . number_format($client['plafond_credit'], 0, ',', ' ') . ', dû ' . number_format($client['solde'], 0, ',', ' ') . ').', ['idclient' => 'Plafond de crédit dépassé'], 'plafond_credit');
            }
        }

        // 4. Enregistrement
        $numero = numero_suivant($db, 'vente', $reg['caisse.prefixe']);
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare('INSERT INTO ventes (numero, idsession, idcaisse, iduser, idclient, date_vente, sous_total, remise, tva, total, paye, rendu, reste, cout_total, statut, note, cle) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$numero, $session['idsession'], $session['idcaisse'], $user['id'], $idclient, $now, $sousTotal, round($remisesLignes + $remiseGlobale, 2), $tva, $total, $paye, $rendu, $reste, round($coutTotal, 2), 'validee', mb_substr(trim((string)($in['note'] ?? '')), 0, 300), $cle !== '' ? $cle : null]);
        $idvente = (int)$db->lastInsertId();
        $insL = $db->prepare('INSERT INTO vente_lignes (idvente, idprod, designation, type, quantite, prix_unitaire, remise, tva_taux, total, cout_unitaire) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($lignes as $l) {
            $insL->execute([$idvente, $l['p']['idprod'], $l['p']['nom'], $l['p']['type'], $l['q'], $l['pu'], $l['remise'], $l['taux'], $l['total'], $l['p']['prix_achat']]);
            if (!$stockDejaSorti) {
                $courant = $db->prepare('SELECT * FROM produits WHERE idprod = ?'); $courant->execute([$l['p']['idprod']]);
                stock_bouger($db, $user['id'], $courant->fetch(PDO::FETCH_ASSOC), 'sortie_vente', -$l['q'], ['ref_type' => 'vente', 'ref_id' => $idvente, 'motif' => 'Vente ' . $numero]);
            }
        }
        $insP = $db->prepare('INSERT INTO vente_paiements (idvente, mode, montant, reference) VALUES (?,?,?,?)');
        foreach ($paiements as $pa) $insP->execute([$idvente, $pa['mode'], $pa['montant'], $pa['reference']]);
        if ($reste > 0) $db->prepare('UPDATE clients SET solde = solde + ? WHERE idclient = ?')->execute([$reste, $idclient]);
        return vente_detail($db, $user, $idvente);
    });
}

function vente_detail(PDO $db, array $user, $id)
{
    $st = $db->prepare("SELECT v.*, CONCAT(u.nomag, ' ', u.prenom) AS caissier, c.nom AS client, c.telephone AS client_tel, ca.nom AS caisse FROM ventes v JOIN users u ON u.id_user = v.iduser JOIN caisses ca ON ca.idcaisse = v.idcaisse LEFT JOIN clients c ON c.idclient = v.idclient WHERE v.idvente = :i");
    $st->execute(['i' => $id]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new ApiError(404, 'Vente introuvable');
    if (!role_peut($user['role'], 'ventes.lire_toutes') && (int)$v['iduser'] !== (int)$user['id']) throw new ApiError(403, 'Cette vente a été faite par un autre caissier.');
    $v['caissier'] = trim($v['caissier']);
    $l = $db->prepare('SELECT * FROM vente_lignes WHERE idvente = :i ORDER BY idligne'); $l->execute(['i' => $id]);
    $v['lignes'] = $l->fetchAll(PDO::FETCH_ASSOC);
    $p = $db->prepare('SELECT p.*, COALESCE(m.libelle, p.mode) AS libelle FROM vente_paiements p LEFT JOIN modes_paiement m ON m.code = p.mode WHERE p.idvente = :i ORDER BY p.idpaie'); $p->execute(['i' => $id]);
    $v['paiements'] = $p->fetchAll(PDO::FETCH_ASSOC);
    $reg = settings_all($db);
    $v['ticket'] = ticket_reglages($reg);
    return $v;
}

function vente_annuler(PDO $db, array $user, array $in)
{
    exiger($user, 'ventes.annuler', "Seul le gérant peut annuler une vente.");
    $id = (int)($in['idvente'] ?? 0);
    $motif = trim((string)($in['motif'] ?? ''));
    if ($motif === '') throw new ApiError(422, 'Données invalides', ['motif' => "Le motif d'annulation est obligatoire"]);

    return stock_transaction($db, function () use ($db, $user, $id, $motif) {
        $st = $db->prepare('SELECT v.*, s.statut AS statut_session FROM ventes v JOIN sessions_caisse s ON s.idsession = v.idsession WHERE v.idvente = :i FOR UPDATE'); $st->execute(['i' => $id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) throw new ApiError(404, 'Vente introuvable');
        if ($v['statut'] !== 'validee') throw new ApiError(409, 'Cette vente est déjà annulée.');
        if ($v['statut_session'] !== 'ouverte') throw new ApiError(409, "La caisse de cette vente est déjà clôturée : elle ne peut plus être annulée.", null, 'session_cloturee');
        $l = $db->prepare('SELECT * FROM vente_lignes WHERE idvente = :i ORDER BY idprod'); $l->execute(['i' => $id]);
        foreach ($l->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
            $st2 = $db->prepare('SELECT * FROM produits WHERE idprod = :i FOR UPDATE'); $st2->execute(['i' => $ligne['idprod']]);
            if ($p = $st2->fetch(PDO::FETCH_ASSOC)) stock_bouger($db, $user['id'], $p, 'annulation_vente', (float)$ligne['quantite'], ['cout' => $ligne['cout_unitaire'], 'ref_type' => 'vente', 'ref_id' => $id, 'motif' => 'Annulation ' . $v['numero']]);
        }
        if ($v['reste'] > 0 && $v['idclient']) $db->prepare('UPDATE clients SET solde = solde - ? WHERE idclient = ?')->execute([$v['reste'], $v['idclient']]);
        $db->prepare("UPDATE ventes SET statut = 'annulee', annule_at = ?, annule_par = ?, annule_motif = ? WHERE idvente = ?")->execute([gmdate('Y-m-d H:i:s'), $user['id'], mb_substr($motif, 0, 200), $id]);
        return vente_detail($db, $user, $id);
    });
}

function ventes_liste(PDO $db, array $user, array $q)
{
    $where = ['1=1']; $p = [];
    if (!role_peut($user['role'], 'ventes.lire_toutes')) { $where[] = 'v.iduser = :u'; $p['u'] = $user['id']; }
    elseif (!empty($q['iduser'])) { $where[] = 'v.iduser = :u'; $p['u'] = (int)$q['iduser']; }
    if (!empty($q['statut']) && in_array($q['statut'], ['validee', 'annulee'], true)) { $where[] = 'v.statut = :st'; $p['st'] = $q['statut']; }
    if (!empty($q['from'])) { $where[] = 'v.date_vente >= :f'; $p['f'] = $q['from'] . ' 00:00:00'; }
    if (!empty($q['to'])) { $where[] = 'v.date_vente <= :t'; $p['t'] = $q['to'] . ' 23:59:59'; }
    if (!empty($q['idclient'])) { $where[] = 'v.idclient = :c'; $p['c'] = (int)$q['idclient']; }
    if (!empty($q['idsession'])) { $where[] = 'v.idsession = :s'; $p['s'] = (int)$q['idsession']; }
    if (!empty($q['credit'])) $where[] = 'v.reste > 0';
    if (!empty($q['q'])) { $where[] = '(v.numero LIKE :q OR c.nom LIKE :q2)'; $like = '%' . trim($q['q']) . '%'; $p['q'] = $like; $p['q2'] = $like; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $from = "FROM ventes v JOIN users u ON u.id_user = v.iduser LEFT JOIN clients c ON c.idclient = v.idclient WHERE $w";
    $st = $db->prepare("SELECT COUNT(*), COALESCE(SUM(CASE WHEN v.statut = 'validee' THEN v.total END), 0) $from"); $st->execute($p);
    list($total, $somme) = $st->fetch(PDO::FETCH_NUM);
    $st = $db->prepare("SELECT v.idvente, v.numero, v.date_vente, v.total, v.paye, v.rendu, v.reste, v.statut, v.remise, CONCAT(u.nomag, ' ', u.prenom) AS caissier, c.nom AS client, (SELECT COUNT(*) FROM vente_lignes l WHERE l.idvente = v.idvente) AS nb_lignes, (SELECT GROUP_CONCAT(DISTINCT p.mode ORDER BY p.mode SEPARATOR ',') FROM vente_paiements p WHERE p.idvente = v.idvente) AS modes $from ORDER BY v.date_vente DESC, v.idvente DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['caissier'] = trim($r['caissier']);
    return ['data' => $rows, 'meta' => ['total' => (int)$total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per)), 'somme' => $somme + 0]];
}

/** Catalogue allégé pour l'écran de caisse (produits et services actifs + catégories). */
function pos_catalogue(PDO $db)
{
    $prod = $db->query("SELECT idprod AS id, type, nom, sku, code_barres, idcat, unite, prix_vente, tva_taux, stockable, stock_qty, seuil_alerte, image FROM produits WHERE supp = 0 AND actif = 1 AND sku <> 'FRAIS-LIV' ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prod as &$p) { $p['id'] = (int)$p['id']; $p['idcat'] = $p['idcat'] === null ? null : (int)$p['idcat']; $p['prix_vente'] += 0; $p['tva_taux'] += 0; $p['stock_qty'] += 0; $p['stockable'] = (int)$p['stockable']; $p['seuil_alerte'] += 0; }
    $cats = $db->query('SELECT idcat AS id, nom, couleur, image FROM categories WHERE supp = 0 ORDER BY ordre, nom')->fetchAll(PDO::FETCH_ASSOC);
    return ['produits' => $prod, 'categories' => $cats, 'modes' => array_values(modes_actifs($db))];
}

/* ---------- Ventes en attente (le client revient plus tard, on sert quelqu'un d'autre) ---------- */
function paniers_lister(PDO $db, array $user)
{
    $st = $db->prepare('SELECT idpanier, libelle, contenu, created_at FROM paniers_attente WHERE iduser = :u ORDER BY idpanier DESC LIMIT 20'); $st->execute(['u' => $user['id']]);
    return array_map(function ($r) { $r['contenu'] = json_decode($r['contenu'], true); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}
function panier_mettre_en_attente(PDO $db, array $user, array $in)
{
    exiger($user, 'caisse.vendre');
    if (empty($in['contenu']) || !is_array($in['contenu'])) throw new ApiError(422, 'Le panier est vide');
    $json = json_encode($in['contenu'], JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 200000) throw new ApiError(422, 'Panier trop volumineux');
    $db->prepare('INSERT INTO paniers_attente (iduser, libelle, contenu, created_at) VALUES (?,?,?,?)')->execute([$user['id'], mb_substr(trim((string)($in['libelle'] ?? '')), 0, 80), $json, gmdate('Y-m-d H:i:s')]);
    return ['idpanier' => (int)$db->lastInsertId()];
}
function panier_supprimer(PDO $db, array $user, $id)
{
    $db->prepare('DELETE FROM paniers_attente WHERE idpanier = ? AND iduser = ?')->execute([$id, $user['id']]);
}
