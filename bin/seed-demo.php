<?php
/**
 * Jeu de données de démonstration (boutique généraliste) : catalogue, fournisseurs, clients, réceptions de marchandises
 * et 3 semaines d'historique de ventes réparties entre deux caissiers.
 *   php bin/seed-demo.php            (refuse de s'exécuter si des ventes existent déjà)
 * Comptes créés (mot de passe : demo1234) : gerant@demo.local · caissier@demo.local · magasin@demo.local
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/conn.php';
require_once __DIR__ . '/../connexion/settings.php';
require_once __DIR__ . '/../api/lib/http.php';
require_once __DIR__ . '/../api/lib/permissions.php';
require_once __DIR__ . '/../api/lib/stock.php';
require_once __DIR__ . '/../api/lib/achats.php';

if ((int)$bdd->query('SELECT COUNT(*) FROM ventes')->fetchColumn() > 0) { fwrite(STDERR, "Des ventes existent déjà : la démo ne s'installe que sur une base vierge.\n"); exit(1); }
mt_srand(2026);
$now = gmdate('Y-m-d H:i:s');

/* ---- Comptes ---- */
$groupe = function ($c) use ($bdd) { $st = $bdd->prepare('SELECT idgpe FROM table_gpe_users WHERE coden = ?'); $st->execute([$c]); return (int)$st->fetchColumn(); };
$users = [];
foreach ([['Kouassi', 'Aya', 'gerant@demo.local', 'admin'], ['Traoré', 'Moussa', 'caissier@demo.local', 'caissier'], ['Bamba', 'Fanta', 'caisse2@demo.local', 'caissier'], ['Diallo', 'Seydou', 'magasin@demo.local', 'magasinier']] as $u) {
    $st = $bdd->prepare('SELECT id_user FROM users WHERE emailag = ?'); $st->execute([$u[2]]);
    if ($id = $st->fetchColumn()) { $users[$u[3] . $u[0]] = (int)$id; continue; }
    $bdd->prepare('INSERT INTO users (nomag, prenom, emailag, telag, pass, gpe, user_status, dateenr) VALUES (?,?,?,?,?,?,1,?)')->execute([$u[0], $u[1], $u[2], '', pwd_pour_stockage('demo1234'), $groupe($u[3]), $now]);
    $users[$u[3] . $u[0]] = (int)$bdd->lastInsertId();
}
$gerant = $users['adminKouassi']; $caissiers = [$users['caissierTraoré'], $users['caissierBamba']]; $magasinier = $users['magasinierDiallo'];
$userGerant = ['id' => $gerant, 'role' => 'admin'];

/* ---- Catalogue ---- */
$cats = [];
foreach (['Boissons', 'Épicerie', 'Produits frais', 'Hygiène & entretien', 'Papeterie', 'Services'] as $i => $nom) {
    $bdd->prepare('INSERT INTO categories (nom, ordre, supp) VALUES (?,?,0)')->execute([$nom, $i]); $cats[$nom] = (int)$bdd->lastInsertId();
}
// [catégorie, nom, unité, prix de vente, prix d'achat, TVA, seuil, code-barres]
$catalogue = [
    ['Boissons', 'Eau minérale 1,5 L', 'pièce', 500, 320, 0, 24, '6001000000011'], ['Boissons', 'Jus d\'orange 1 L', 'pièce', 1200, 850, 0, 12, '6001000000028'],
    ['Boissons', 'Soda cola 33 cl', 'pièce', 400, 270, 0, 24, '6001000000035'], ['Boissons', 'Bière 65 cl', 'pièce', 800, 560, 18, 24, '6001000000042'],
    ['Boissons', 'Lait UHT 1 L', 'pièce', 900, 680, 0, 12, '6001000000059'],
    ['Épicerie', 'Riz parfumé 5 kg', 'pièce', 4500, 3600, 0, 8, '6002000000010'], ['Épicerie', 'Huile végétale 1 L', 'pièce', 1500, 1150, 0, 10, '6002000000027'],
    ['Épicerie', 'Sucre en poudre', 'kg', 800, 620, 0, 15, '6002000000034'], ['Épicerie', 'Pâtes spaghetti 500 g', 'pièce', 450, 320, 0, 20, '6002000000041'],
    ['Épicerie', 'Concentré de tomate 400 g', 'pièce', 600, 430, 0, 15, '6002000000058'], ['Épicerie', 'Sel fin 1 kg', 'pièce', 250, 150, 0, 15, '6002000000065'],
    ['Épicerie', 'Café soluble 200 g', 'pièce', 2800, 2200, 18, 6, '6002000000072'],
    ['Produits frais', 'Œufs (plateau de 30)', 'pièce', 2500, 2050, 0, 6, '6003000000017'], ['Produits frais', 'Yaourt nature', 'pièce', 350, 240, 0, 24, '6003000000024'],
    ['Produits frais', 'Beurre 250 g', 'pièce', 1300, 1000, 0, 8, '6003000000031'],
    ['Hygiène & entretien', 'Savon de ménage', 'pièce', 250, 160, 18, 20, '6004000000012'], ['Hygiène & entretien', 'Lessive en poudre 1 kg', 'pièce', 1800, 1350, 18, 8, '6004000000029'],
    ['Hygiène & entretien', 'Papier toilette (4 rouleaux)', 'pièce', 1000, 720, 18, 12, '6004000000036'], ['Hygiène & entretien', 'Dentifrice 75 ml', 'pièce', 900, 640, 18, 10, '6004000000043'],
    ['Papeterie', 'Cahier 96 pages', 'pièce', 400, 260, 18, 20, '6005000000017'], ['Papeterie', 'Stylo à bille', 'pièce', 150, 80, 18, 40, '6005000000024'],
    ['Services', 'Photocopie A4', 'page', 50, 0, 0, 0, ''], ['Services', 'Livraison à domicile', 'prestation', 1500, 0, 0, 0, ''], ['Services', 'Recharge téléphonique (frais)', 'prestation', 100, 0, 0, 0, ''],
];
$prod = []; $n = 0;
foreach ($catalogue as $c) {
    $service = $c[0] === 'Services'; $n++;
    $bdd->prepare('INSERT INTO produits (type, nom, sku, code_barres, idcat, unite, prix_vente, prix_achat, tva_taux, stockable, stock_qty, seuil_alerte, actif, supp, dateenr) VALUES (?,?,?,?,?,?,?,?,?,?,0,?,1,0,?)')
        ->execute([$service ? 'service' : 'produit', $c[1], sprintf('P%06d', $n), $c[7], $cats[$c[0]], $c[2], $c[3], $c[4], $c[5], $service ? 0 : 1, $c[6], $now]);
    $prod[] = ['id' => (int)$bdd->lastInsertId(), 'nom' => $c[1], 'pv' => $c[3], 'pa' => $c[4], 'tva' => $c[5], 'service' => $service, 'unite' => $c[2]];
}

/* ---- Fournisseurs et clients ---- */
$fours = [];
foreach ([['Grossiste Soleil', 'M. Koffi', '+225 27 21 00 11 22'], ['Distribution Atlantique', 'Mme Yao', '+225 07 08 09 10 11'], ['Papeterie Moderne', 'M. Sylla', '+225 05 44 33 22 11']] as $f) {
    $bdd->prepare('INSERT INTO fournisseurs (nom, contact, telephone, solde, supp, dateenr) VALUES (?,?,?,0,0,?)')->execute([$f[0], $f[1], $f[2], $now]); $fours[] = (int)$bdd->lastInsertId();
}
$clients = [];
foreach ([['Mme Koné', '+225 07 11 22 33 44', 50000], ['Restaurant Chez Awa', '+225 05 55 66 77 88', 150000], ['M. Ouattara', '+225 01 02 03 04 05', 20000], ['École Les Palmiers', '+225 27 22 44 55 66', 100000]] as $c) {
    $bdd->prepare('INSERT INTO clients (nom, telephone, plafond_credit, solde, supp, dateenr) VALUES (?,?,?,0,0,?)')->execute([$c[0], $c[1], $c[2], $now]); $clients[] = (int)$bdd->lastInsertId();
}

/* ---- Réceptions de marchandises (il y a 25 jours), via la logique réelle (coût moyen, dette fournisseur) ---- */
$parFour = [0 => [], 1 => [], 2 => []];
foreach ($prod as $p) { if ($p['service']) continue; $parFour[$p['nom'] === 'Cahier 96 pages' || $p['nom'] === 'Stylo à bille' ? 2 : (strpos($p['nom'], 'Eau') === 0 || strpos($p['nom'], 'Jus') === 0 || strpos($p['nom'], 'Soda') === 0 || strpos($p['nom'], 'Bière') === 0 || strpos($p['nom'], 'Lait') === 0 ? 1 : 0)][] = $p; }
foreach ($parFour as $i => $liste) {
    $lignes = array_map(function ($p) { return ['idprod' => $p['id'], 'quantite' => $p['unite'] === 'kg' ? 60 : mt_rand(40, 90), 'cout_unitaire' => $p['pa']]; }, $liste);
    $a = appro_creer($bdd, $userGerant + ['nom' => 'Gérant'], ['idfour' => $fours[$i], 'date_appro' => gmdate('Y-m-d', strtotime('-25 days')), 'lignes' => $lignes, 'paye' => $i === 0 ? 0 : round(array_sum(array_map(function ($l) { return $l['quantite'] * $l['cout_unitaire']; }, $lignes)) * 0.6), 'mode' => 'especes', 'numero_bon' => 'BL-' . (100 + $i)]);
}
$bdd->prepare("UPDATE mouvements_stock SET created_at = ? WHERE type = 'entree_achat'")->execute([gmdate('Y-m-d 08:00:00', strtotime('-25 days'))]);

/* ---- Historique : une session fermée par jour et par caissier, 4 à 9 ventes ---- */
$stock = []; foreach ($bdd->query('SELECT idprod, stock_qty FROM produits')->fetchAll(PDO::FETCH_NUM) as $r) $stock[(int)$r[0]] = (float)$r[1];
$vendables = array_values(array_filter($prod, function ($p) { return true; }));
$soldeClient = array_fill_keys($clients, 0.0); $nbVentes = 0;
for ($j = 21; $j >= 0; $j--) {
    $jour = strtotime("-$j days"); if (gmdate('N', $jour) == 7 && mt_rand(0, 1)) continue;
    foreach ($caissiers as $k => $uid) {
        if ($j === 0 && $k) continue;   // aujourd'hui : seulement la session du matin, terminée il y a quelques minutes
        $debut = gmdate('Y-m-d', $jour) . ($k ? ' 13:00:00' : ' 07:30:00'); $fin = gmdate('Y-m-d', $jour) . ($k ? ' 20:30:00' : ' 13:00:00');
        if ($j === 0) { $fin = gmdate('Y-m-d H:i:s', time() - 600); $debut = gmdate('Y-m-d H:i:s', time() - 600 - 4 * 3600); }
        $fond = 10000;
        $bdd->prepare("INSERT INTO sessions_caisse (idcaisse, iduser, ouverture_at, fond_initial, statut) VALUES (1,?,?,?,'ouverte')")->execute([$uid, $debut, $fond]);
        $ids = (int)$bdd->lastInsertId(); $especesNet = 0;
        $n = mt_rand(4, 9);
        for ($v = 0; $v < $n; $v++) {
            $h = (strtotime($debut) + (int)((strtotime($fin) - strtotime($debut)) * ($v + mt_rand(1, 8) / 10) / $n));
            $date = gmdate('Y-m-d H:i:s', $h);
            $lignes = []; $total = 0; $tva = 0; $cout = 0;
            foreach ((array)array_rand($vendables, mt_rand(1, 4)) as $ix) {
                $p = $vendables[$ix]; $q = $p['unite'] === 'kg' ? mt_rand(1, 6) / 2 : mt_rand(1, 3);
                if (!$p['service'] && $stock[$p['id']] < $q + 2) continue;
                $lt = round($p['pv'] * $q, 2); $total += $lt; $cout += $q * $p['pa']; $tva += $p['tva'] > 0 ? $lt * $p['tva'] / (100 + $p['tva']) : 0;
                $lignes[] = [$p, $q, $lt];
            }
            if (!$lignes) continue;
            $mode = mt_rand(1, 10); $idclient = null; $paye = $total; $reste = 0; $rendu = 0; $paiements = [];
            if ($mode <= 5) { $recu = ceil($total / 500) * 500 + (mt_rand(0, 3) ? 0 : 1000); $paiements[] = ['especes', $recu]; $rendu = $recu - $total; $paye = $recu; }
            elseif ($mode <= 7) $paiements[] = [['orange', 'mtn', 'wave'][mt_rand(0, 2)], $total];
            elseif ($mode === 8) { $paiements[] = ['especes', round($total / 2)]; $paiements[] = ['wave', $total - round($total / 2)]; }
            elseif ($mode === 9) $paiements[] = ['carte', $total];
            else { $idclient = $clients[mt_rand(0, 3)]; $acompte = mt_rand(0, 1) ? round($total * 0.3) : 0; $reste = $total - $acompte; if ($acompte > 0) $paiements[] = ['especes', $acompte]; $paye = $acompte; $soldeClient[$idclient] += $reste; }
            if (!$idclient && mt_rand(1, 6) === 1) $idclient = $clients[mt_rand(0, 3)];
            $numero = numero_suivant($bdd, 'vente', 'V');
            $bdd->prepare('INSERT INTO ventes (numero, idsession, idcaisse, iduser, idclient, date_vente, sous_total, remise, tva, total, paye, rendu, reste, cout_total, statut, note, cle) VALUES (?,?,1,?,?,?,?,0,?,?,?,?,?,?,?,?,NULL)')
                ->execute([$numero, $ids, $uid, $idclient, $date, $total, round($tva, 2), $total, $paye, $rendu, $reste, round($cout, 2), 'validee', '']);
            $idv = (int)$bdd->lastInsertId(); $nbVentes++;
            foreach ($lignes as $l) {
                list($p, $q, $lt) = $l;
                $bdd->prepare('INSERT INTO vente_lignes (idvente, idprod, designation, type, quantite, prix_unitaire, remise, tva_taux, total, cout_unitaire) VALUES (?,?,?,?,?,?,0,?,?,?)')->execute([$idv, $p['id'], $p['nom'], $p['service'] ? 'service' : 'produit', $q, $p['pv'], $p['tva'], $lt, $p['pa']]);
                if (!$p['service']) {
                    $stock[$p['id']] -= $q;
                    $bdd->prepare("INSERT INTO mouvements_stock (idprod, type, quantite, stock_apres, cout_unitaire, ref_type, ref_id, motif, iduser, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)")->execute([$p['id'], 'sortie_vente', -$q, $stock[$p['id']], $p['pa'], 'vente', $idv, 'Vente ' . $numero, $uid, $date]);
                }
            }
            foreach ($paiements as $pa) { $bdd->prepare('INSERT INTO vente_paiements (idvente, mode, montant, reference) VALUES (?,?,?,?)')->execute([$idv, $pa[0], $pa[1], '']); if ($pa[0] === 'especes') $especesNet += $pa[1]; }
            $especesNet -= $rendu;
        }
        $attendu = $fond + $especesNet; $ecart = mt_rand(1, 8) === 1 ? -mt_rand(1, 6) * 500 : 0;
        $bdd->prepare("UPDATE sessions_caisse SET statut = 'cloturee', cloture_at = ?, compte_especes = ?, attendu_especes = ?, ecart = ?, notes = ? WHERE idsession = ?")
            ->execute([$fin, $attendu + $ecart, $attendu, $ecart, $ecart ? 'Écart constaté à la clôture' : '', $ids]);
    }
}
foreach ($stock as $id => $q) $bdd->prepare('UPDATE produits SET stock_qty = ? WHERE idprod = ?')->execute([round($q, 3), $id]);
foreach ($soldeClient as $id => $s) $bdd->prepare('UPDATE clients SET solde = ? WHERE idclient = ?')->execute([$s, $id]);

/* ---- Quelques cas à signaler : stock bas et rupture ---- */
$bdd->prepare("UPDATE produits SET stock_qty = 3 WHERE nom = 'Café soluble 200 g'")->execute();
$bdd->prepare("UPDATE produits SET stock_qty = 0 WHERE nom = 'Beurre 250 g'")->execute();
echo "Démo installée : " . count($prod) . " articles, " . count($clients) . " clients, $nbVentes ventes sur 3 semaines (jusqu'à aujourd'hui).\n";
echo "Comptes (mot de passe demo1234) : gerant@demo.local · caissier@demo.local · caisse2@demo.local · magasin@demo.local\n";
