<?php
/**
 * Commandes clients et livraisons.
 * Le stock sort à la prise de commande et revient si elle est annulée. La remise crée la vente et encaisse
 * dans la caisse ouverte, sans deuxième sortie de stock. Les prix sont figés à la prise de commande.
 *   livraison : nouvelle → preparation → prete → en_livraison → livree
 *   retrait   : nouvelle → preparation → prete → livree
 *   annulee possible tant que la commande n'est pas livrée.
 */
const CMD_ZONES = ['abidjan' => 'Abidjan', 'interieur' => 'Intérieur du pays', 'exterieur' => "Extérieur (hors Côte d'Ivoire)"];
const CMD_STATUTS = ['nouvelle', 'preparation', 'prete', 'en_livraison', 'livree', 'annulee'];

function cmd_sequence($mode)
{
    return $mode === 'livraison' ? ['nouvelle', 'preparation', 'prete', 'en_livraison', 'livree'] : ['nouvelle', 'preparation', 'prete', 'livree'];
}

/** Produit de service « Frais de livraison », créé au besoin ; absent du catalogue de caisse (sku réservé). */
function produit_frais_livraison(PDO $db)
{
    $st = $db->query("SELECT idprod FROM produits WHERE sku = 'FRAIS-LIV' AND supp = 0 LIMIT 1");
    if ($id = $st->fetchColumn()) return (int)$id;
    $db->prepare("INSERT INTO produits (type, nom, sku, unite, prix_vente, stockable, actif, dateenr) VALUES ('service', 'Frais de livraison', 'FRAIS-LIV', 'pièce', 0, 0, 1, ?)")->execute([gmdate('Y-m-d H:i:s')]);
    return (int)$db->lastInsertId();
}

function cmd_historiser(PDO $db, $idcommande, $statut, $iduser, $note = '')
{
    $db->prepare('INSERT INTO commande_historique (idcommande, statut, iduser, note, created_at) VALUES (?,?,?,?,?)')->execute([$idcommande, $statut, $iduser, mb_substr($note, 0, 200), gmdate('Y-m-d H:i:s')]);
}

function cmd_date_prevue($v)
{
    $v = trim((string)$v);
    if ($v === '') return null;
    $t = strtotime(str_replace('T', ' ', $v));
    if ($t === false) throw new ApiError(422, 'Données invalides', ['date_prevue' => 'Date invalide']);
    return date('Y-m-d H:i:s', $t);   // heure locale saisie, conservée telle quelle
}

function commande_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'commandes.ecrire');
    $mode = (string)($in['mode'] ?? 'livraison');
    if (!in_array($mode, ['livraison', 'retrait'], true)) throw new ApiError(422, 'Données invalides', ['mode' => 'Mode inconnu']);
    $adresse = mb_substr(trim((string)($in['adresse'] ?? '')), 0, 300);
    if ($mode === 'livraison' && $adresse === '') throw new ApiError(422, 'Données invalides', ['adresse' => "L'adresse de livraison est obligatoire"]);
    $zone = (string)($in['zone'] ?? 'abidjan');
    if (!isset(CMD_ZONES[$zone])) throw new ApiError(422, 'Données invalides', ['zone' => 'Zone de livraison inconnue']);
    $datePrevue = cmd_date_prevue($in['date_prevue'] ?? '');
    $lignesIn = isset($in['lignes']) && is_array($in['lignes']) ? $in['lignes'] : [];
    if (!$lignesIn) err_lignes('Ajoutez au moins un article');
    if (count($lignesIn) > 200) err_lignes('200 lignes maximum par commande');
    $reg = settings_all($db);
    $libre = $reg['caisse.prix_libre'] === '1' || role_peut($user['role'], 'ventes.lire_toutes');

    return stock_transaction($db, function () use ($db, $user, $in, $mode, $adresse, $zone, $datePrevue, $lignesIn, $libre, $reg) {
        // Lieu et prix de la livraison : tarif de la grille (zone + quartier/ville), prix modifiable, ou prix saisi pour un lieu hors grille
        $idzone = null; $commune = ''; $lieu = ''; $tarif = null; $frais = 0;
        if ($mode === 'livraison') {
            if (!empty($in['idzone'])) {
                $st = $db->prepare('SELECT * FROM zones_livraison WHERE idzone = :i AND supp = 0 AND actif = 1'); $st->execute(['i' => (int)$in['idzone']]);
                $z = $st->fetch(PDO::FETCH_ASSOC);
                if (!$z || $z['zone'] !== $zone) throw new ApiError(422, 'Données invalides', ['idzone' => 'Lieu de livraison inconnu pour cette zone']);
                $idzone = (int)$z['idzone']; $commune = $z['commune']; $lieu = $z['nom']; $tarif = (float)$z['prix'];
            } else {
                $lieu = mb_substr(trim((string)($in['lieu'] ?? '')), 0, 120); $commune = mb_substr(trim((string)($in['commune'] ?? '')), 0, 80);
                if ($lieu === '') throw new ApiError(422, 'Données invalides', ['lieu' => $zone === 'abidjan' ? 'Indiquez le quartier' : ($zone === 'interieur' ? 'Indiquez la ville' : 'Indiquez le pays et la ville')]);
            }
            if (isset($in['frais_livraison']) && $in['frais_livraison'] !== '') $frais = round(nombre($in['frais_livraison'], 'frais_livraison', 0), 2);
            elseif ($tarif !== null) $frais = $tarif;
            else throw new ApiError(422, 'Données invalides', ['frais_livraison' => 'Indiquez le prix de la livraison (aucun tarif enregistré pour ce lieu)']);
        } else $zone = 'abidjan';

        $client = null;
        if (!empty($in['idclient'])) {
            $st = $db->prepare('SELECT * FROM clients WHERE idclient = :i AND supp = 0'); $st->execute(['i' => (int)$in['idclient']]);
            $client = $st->fetch(PDO::FETCH_ASSOC);
            if (!$client) throw new ApiError(422, 'Données invalides', ['idclient' => 'Client inconnu']);
        }
        $nom = $client ? $client['nom'] : mb_substr(trim((string)($in['client_nom'] ?? '')), 0, 150);
        $tel = $client && $client['telephone'] !== '' && empty($in['client_tel']) ? $client['telephone'] : mb_substr(trim((string)($in['client_tel'] ?? '')), 0, 40);
        if ($nom === '') throw new ApiError(422, 'Données invalides', ['client_nom' => 'Indiquez le client']);
        if ($mode === 'livraison' && $tel === '') throw new ApiError(422, 'Données invalides', ['client_tel' => 'Un téléphone est nécessaire pour joindre le client']);

        $ids = array_unique(array_map(function ($l) { return (int)($l['idprod'] ?? 0); }, $lignesIn)); sort($ids);
        $produits = [];
        foreach ($ids as $idprod) $produits[$idprod] = produit_verrouille($db, $idprod);
        $lignes = []; $sous = 0; $quantites = [];
        foreach ($lignesIn as $l) {
            $p = $produits[(int)($l['idprod'] ?? 0)] ?? null;
            if (!$p || $p['sku'] === 'FRAIS-LIV') err_lignes('Produit inconnu dans la commande');
            if (!(int)$p['actif']) err_lignes($p['nom'] . ' est désactivé');
            $q = nombre($l['quantite'] ?? '', 'lignes', 0.001, 100000);
            if ($p['type'] === 'produit' && $p['unite'] === 'pièce' && floor($q) != $q) err_lignes('Quantité entière attendue pour ' . $p['nom']);
            $pu = (float)$p['prix_vente'];
            if (isset($l['prix_unitaire']) && $l['prix_unitaire'] !== '' && abs((float)$l['prix_unitaire'] - $pu) > 0.004) {
                if (!$libre) throw new ApiError(403, 'Seul un responsable peut modifier un prix de vente.', null, 'prix_interdit');
                $pu = round(nombre($l['prix_unitaire'], 'lignes', 0), 2);
            }
            $tot = round($pu * $q, 2); $sous += $tot;
            $lignes[] = [$p['idprod'], $p['nom'], $p['unite'], $q, $pu, $tot];
            if ((int)$p['stockable']) $quantites[$p['idprod']] = ($quantites[$p['idprod']] ?? 0) + $q;
        }
        foreach ($quantites as $idprod => $q) {
            if ($reg['caisse.stock_negatif'] !== '1' && $produits[$idprod]['stock_qty'] < $q - 0.0004) {
                err_lignes('Stock insuffisant pour ' . $produits[$idprod]['nom'] . ' (disponible : ' . (float)$produits[$idprod]['stock_qty'] . ').', 'stock_insuffisant');
            }
        }
        $sous = round($sous, 2);
        $now = gmdate('Y-m-d H:i:s');
        $numero = numero_suivant($db, 'commande', 'CMD');
        $db->prepare('INSERT INTO commandes (numero, mode, zone, idzone, commune, lieu, frais_tarif, statut, idclient, client_nom, client_tel, adresse, date_prevue, frais_livraison, sous_total, total, note, iduser, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$numero, $mode, $zone, $idzone, $commune, $lieu, $tarif, 'nouvelle', $client ? $client['idclient'] : null, $nom, $tel, $adresse, $datePrevue, $frais, $sous, round($sous + $frais, 2), mb_substr(trim((string)($in['note'] ?? '')), 0, 300), $user['id'], $now, $now]);
        $id = (int)$db->lastInsertId();
        $ins = $db->prepare('INSERT INTO commande_lignes (idcommande, idprod, designation, unite, quantite, prix_unitaire, total) VALUES (?,?,?,?,?,?,?)');
        foreach ($lignes as $l) $ins->execute(array_merge([$id], $l));
        foreach ($quantites as $idprod => $q) stock_bouger($db, $user['id'], $produits[$idprod], 'sortie_commande', -$q, ['ref_type' => 'commande', 'ref_id' => $id, 'motif' => 'Commande ' . $numero]);
        $db->prepare('UPDATE commandes SET stock_debite = 1 WHERE idcommande = ?')->execute([$id]);
        cmd_historiser($db, $id, 'nouvelle', $user['id']);
        return commande_detail($db, $user, $id);
    });
}

function commande_detail(PDO $db, array $user, $id)
{
    exiger($user, 'commandes.lire');
    $st = $db->prepare("SELECT c.*, CONCAT(u.nomag, ' ', u.prenom) AS createur, v.numero AS vente_numero FROM commandes c JOIN users u ON u.id_user = c.iduser LEFT JOIN ventes v ON v.idvente = c.idvente WHERE c.idcommande = :i");
    $st->execute(['i' => $id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new ApiError(404, 'Commande introuvable');
    $c['createur'] = trim($c['createur']);
    $l = $db->prepare('SELECT * FROM commande_lignes WHERE idcommande = :i ORDER BY idligne'); $l->execute(['i' => $id]);
    $c['lignes'] = $l->fetchAll(PDO::FETCH_ASSOC);
    $h = $db->prepare("SELECT h.statut, h.note, h.created_at, CONCAT(u.nomag, ' ', u.prenom) AS par FROM commande_historique h JOIN users u ON u.id_user = h.iduser WHERE h.idcommande = :i ORDER BY h.idhisto"); $h->execute(['i' => $id]);
    $c['historique'] = array_map(function ($r) { $r['par'] = trim($r['par']); return $r; }, $h->fetchAll(PDO::FETCH_ASSOC));
    $c['sequence'] = cmd_sequence($c['mode']);
    $c['zone_libelle'] = CMD_ZONES[$c['zone']] ?? $c['zone'];
    $c['lieu_complet'] = $c['mode'] === 'livraison' ? trim(($c['commune'] !== '' ? $c['commune'] . ' — ' : '') . $c['lieu']) : '';
    $reg = settings_all($db);
    $c['entreprise'] = settings_section($reg, 'entreprise');
    $c['ticket'] = ticket_reglages($reg);
    return $c;
}

function commandes_liste(PDO $db, array $user, array $q)
{
    exiger($user, 'commandes.lire');
    $where = ['1=1']; $p = [];
    if (!empty($q['statut']) && $q['statut'] === 'actives') $where[] = "c.statut NOT IN ('livree','annulee')";
    elseif (!empty($q['statut']) && in_array($q['statut'], CMD_STATUTS, true)) { $where[] = 'c.statut = :st'; $p['st'] = $q['statut']; }
    if (!empty($q['mode']) && in_array($q['mode'], ['livraison', 'retrait'], true)) { $where[] = 'c.mode = :m'; $p['m'] = $q['mode']; }
    if (!empty($q['zone']) && isset(CMD_ZONES[$q['zone']])) { $where[] = "c.mode = 'livraison' AND c.zone = :z"; $p['z'] = $q['zone']; }
    if (!empty($q['idclient'])) { $where[] = 'c.idclient = :c'; $p['c'] = (int)$q['idclient']; }
    if (!empty($q['q'])) { $where[] = '(c.numero LIKE :q OR c.client_nom LIKE :q2 OR c.client_tel LIKE :q3 OR c.adresse LIKE :q4 OR c.lieu LIKE :q5 OR c.commune LIKE :q6)'; $like = '%' . trim($q['q']) . '%'; $p += ['q' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'q6' => $like]; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $st = $db->prepare("SELECT COUNT(*) FROM commandes c WHERE $w"); $st->execute($p); $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT c.idcommande, c.numero, c.mode, c.statut, c.client_nom, c.client_tel, c.adresse, c.zone, c.commune, c.lieu, c.frais_livraison, c.date_prevue, c.total, c.livreur, c.created_at, (SELECT COUNT(*) FROM commande_lignes l WHERE l.idcommande = c.idcommande) AS nb_lignes
        FROM commandes c WHERE $w ORDER BY (c.statut IN ('livree','annulee')), COALESCE(c.date_prevue, c.created_at), c.idcommande DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $compte = [];
    foreach ($db->query('SELECT statut, COUNT(*) AS n FROM commandes GROUP BY statut')->fetchAll(PDO::FETCH_ASSOC) as $r) $compte[$r['statut']] = (int)$r['n'];
    return ['data' => $st->fetchAll(PDO::FETCH_ASSOC), 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per)), 'comptes' => $compte]];
}

function commande_verrouiller(PDO $db, $id)
{
    $st = $db->prepare('SELECT * FROM commandes WHERE idcommande = :i FOR UPDATE'); $st->execute(['i' => $id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new ApiError(404, 'Commande introuvable');
    return $c;
}

/** Étape suivante ou retour : préparation, prête, en livraison (le passage à « livrée » se fait par commande_livrer). */
function commande_statut(PDO $db, array $user, array $in)
{
    exiger($user, 'commandes.statut');
    $id = (int)($in['idcommande'] ?? 0); $nouveau = (string)($in['statut'] ?? '');
    return stock_transaction($db, function () use ($db, $user, $in, $id, $nouveau) {
        $c = commande_verrouiller($db, $id);
        if (in_array($c['statut'], ['livree', 'annulee'], true)) throw new ApiError(409, 'Cette commande est déjà ' . ($c['statut'] === 'livree' ? 'livrée' : 'annulée') . '.');
        $seq = cmd_sequence($c['mode']);
        if (!in_array($nouveau, $seq, true) || $nouveau === 'livree') throw new ApiError(422, 'Statut non valide pour cette commande', ['statut' => 'Statut non valide']);
        $livreur = $c['livreur'];
        if ($nouveau === 'en_livraison') {
            $livreur = mb_substr(trim((string)($in['livreur'] ?? $c['livreur'])), 0, 80);
            if ($livreur === '') throw new ApiError(422, 'Données invalides', ['livreur' => 'Indiquez qui livre la commande']);
        }
        $db->prepare('UPDATE commandes SET statut = ?, livreur = ?, updated_at = ? WHERE idcommande = ?')->execute([$nouveau, $livreur, gmdate('Y-m-d H:i:s'), $id]);
        cmd_historiser($db, $id, $nouveau, $user['id'], $nouveau === 'en_livraison' ? 'Livreur : ' . $livreur : '');
        return commande_detail($db, $user, $id);
    });
}

/** Remise et vente dans la même transaction : le verrou de commande exclut une annulation concurrente. */
function commande_livrer(PDO $db, array $user, array $in)
{
    exiger($user, 'caisse.vendre', "Seul un caissier ou le gérant peut encaisser et remettre une commande.");
    $id = (int)($in['idcommande'] ?? 0);
    return stock_transaction($db, function () use ($db, $user, $in, $id) {
        $c = commande_verrouiller($db, $id);
        if ($c['statut'] === 'annulee') throw new ApiError(409, 'Cette commande est annulée.');
        if ($c['statut'] === 'livree') {
            if (!$c['idvente']) throw new ApiError(409, 'Cette commande est déjà livrée.');
            return ['commande' => commande_detail($db, $user, $id), 'vente' => vente_detail($db, $user, $c['idvente'])];
        }

        $l = $db->prepare('SELECT * FROM commande_lignes WHERE idcommande = :i ORDER BY idligne'); $l->execute(['i' => $id]);
        $lignes = array_map(function ($r) { return ['idprod' => (int)$r['idprod'], 'quantite' => (float)$r['quantite'], 'prix_unitaire' => (float)$r['prix_unitaire']]; }, $l->fetchAll(PDO::FETCH_ASSOC));
        if ((float)$c['frais_livraison'] > 0) $lignes[] = ['idprod' => produit_frais_livraison($db), 'quantite' => 1, 'prix_unitaire' => (float)$c['frais_livraison']];

        $vente = vente_creer($db, $user, [
            'cle' => 'cmd' . $id,
            'idclient' => $c['idclient'],
            'lignes' => $lignes,
            'paiements' => isset($in['paiements']) && is_array($in['paiements']) ? $in['paiements'] : [],
            'note' => 'Commande ' . $c['numero'],
        ], true, (bool)$c['stock_debite']);

        $receptionnaire = mb_substr(trim((string)($in['receptionnaire'] ?? '')), 0, 120);
        $observations = mb_substr(trim((string)($in['observations'] ?? '')), 0, 300);
        $now = gmdate('Y-m-d H:i:s');
        $db->prepare("UPDATE commandes SET statut = 'livree', idvente = ?, livree_at = ?, updated_at = ?, receptionnaire = ?, observations = ? WHERE idcommande = ?")->execute([$vente['idvente'], $now, $now, $receptionnaire, $observations, $id]);
        cmd_historiser($db, $id, 'livree', $user['id'], 'Vente ' . $vente['numero']);
        return ['commande' => commande_detail($db, $user, $id), 'vente' => $vente];
    });
}

function commande_annuler(PDO $db, array $user, array $in)
{
    exiger($user, 'commandes.ecrire');
    $id = (int)($in['idcommande'] ?? 0); $motif = trim((string)($in['motif'] ?? ''));
    if ($motif === '') throw new ApiError(422, 'Données invalides', ['motif' => "Le motif d'annulation est obligatoire"]);
    return stock_transaction($db, function () use ($db, $user, $id, $motif) {
        $c = commande_verrouiller($db, $id);
        if ($c['statut'] === 'livree') throw new ApiError(409, 'Une commande livrée ne peut plus être annulée : annulez la vente associée.');
        if ($c['statut'] === 'annulee') throw new ApiError(409, 'Cette commande est déjà annulée.');
        // Restituer exactement les sorties enregistrées, y compris si le produit a été archivé depuis.
        if ((int)$c['stock_debite']) {
            $st = $db->prepare("SELECT idprod, -SUM(quantite) AS quantite, MAX(cout_unitaire) AS cout FROM mouvements_stock WHERE ref_type = 'commande' AND ref_id = ? AND type = 'sortie_commande' GROUP BY idprod ORDER BY idprod");
            $st->execute([$id]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ligne) {
                $pr = $db->prepare('SELECT * FROM produits WHERE idprod = ? FOR UPDATE'); $pr->execute([$ligne['idprod']]);
                $p = $pr->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new ApiError(409, 'Le produit à remettre en stock est introuvable.');
                $p['stockable'] = 1; // le mouvement de sortie atteste que cet article avait du stock
                stock_bouger($db, $user['id'], $p, 'annulation_commande', (float)$ligne['quantite'], ['cout' => $ligne['cout'], 'ref_type' => 'commande', 'ref_id' => $id, 'motif' => 'Annulation ' . $c['numero'] . ' : ' . $motif]);
            }
        }
        $db->prepare("UPDATE commandes SET statut = 'annulee', annule_motif = ?, updated_at = ? WHERE idcommande = ?")->execute([mb_substr($motif, 0, 200), gmdate('Y-m-d H:i:s'), $id]);
        cmd_historiser($db, $id, 'annulee', $user['id'], $motif);
        return commande_detail($db, $user, $id);
    });
}
