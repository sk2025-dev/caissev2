<?php
/**
 * Exports asynchrones : journal des ventes, état du stock valorisé (Excel, CSV, PDF) et sauvegarde de la base.
 * Une tâche est créée (table export_jobs) puis exécutée après l'envoi de la réponse HTTP : l'utilisateur n'attend pas.
 */
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/pdf.php';
require_once __DIR__ . '/dump.php';

const MOIS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

function export_dir()
{
    $d = dirname(__DIR__, 2) . '/exports';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

function pdf_num($n, $zero = '-')
{
    if (abs($n) < 0.005) return $zero;
    return (($n < 0) ? '-' : '') . number_format(abs($n), floor($n) == $n ? 0 : 2, ',', ' ');
}
function dfr($dt) { return date('d/m/Y', strtotime($dt)); }
function dhfr($dt) { return date('d/m/Y H:i', strtotime($dt)); }

/* ================= Données ================= */

function ventes_export_data(PDO $db, $from, $to)
{
    $p = ['a' => $from . ' 00:00:00', 'b' => $to . ' 23:59:59'];
    $st = $db->prepare("SELECT v.*, CONCAT(u.nomag, ' ', u.prenom) AS caissier, c.nom AS client, (SELECT GROUP_CONCAT(COALESCE(m.libelle, pp.mode) ORDER BY pp.idpaie SEPARATOR ', ') FROM vente_paiements pp LEFT JOIN modes_paiement m ON m.code = pp.mode WHERE pp.idvente = v.idvente) AS modes FROM ventes v JOIN users u ON u.id_user = v.iduser LEFT JOIN clients c ON c.idclient = v.idclient WHERE v.date_vente >= :a AND v.date_vente <= :b ORDER BY v.date_vente, v.idvente LIMIT 100000");
    $st->execute($p);
    $ventes = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ventes as &$v) { $v['caissier'] = trim($v['caissier']); $v['marge'] = round($v['total'] - $v['tva'] - $v['cout_total'], 2); }
    unset($v);

    $st = $db->prepare("SELECT v.numero, v.date_vente, v.statut, c.nom AS client, l.designation, l.type, l.quantite, l.prix_unitaire, l.remise, l.tva_taux, l.total, l.cout_unitaire, cat.nom AS categorie FROM vente_lignes l JOIN ventes v ON v.idvente = l.idvente LEFT JOIN clients c ON c.idclient = v.idclient LEFT JOIN produits pr ON pr.idprod = l.idprod LEFT JOIN categories cat ON cat.idcat = pr.idcat WHERE v.date_vente >= :a AND v.date_vente <= :b ORDER BY v.date_vente, v.idvente, l.idligne LIMIT 300000");
    $st->execute($p);
    $lignes = $st->fetchAll(PDO::FETCH_ASSOC);

    $jours = $db->prepare("SELECT SUBSTR(date_vente, 1, 10) AS jour, COUNT(*) AS nb, SUM(total) AS ca, SUM(tva) AS tva, SUM(remise) AS remises, SUM(cout_total) AS cout FROM ventes WHERE statut = 'validee' AND date_vente >= :a AND date_vente <= :b GROUP BY SUBSTR(date_vente, 1, 10) ORDER BY jour");
    $jours->execute($p);
    $modes = $db->prepare("SELECT p.mode, COALESCE(m.libelle, p.mode) AS libelle, COALESCE(m.type, 'autre') AS type, SUM(p.montant) AS montant, COUNT(*) AS nb FROM vente_paiements p JOIN ventes v ON v.idvente = p.idvente LEFT JOIN modes_paiement m ON m.code = p.mode WHERE v.statut = 'validee' AND v.date_vente >= :a AND v.date_vente <= :b GROUP BY p.mode, m.libelle, m.type, m.ordre ORDER BY m.ordre");
    $modes->execute($p);
    $modes = $modes->fetchAll(PDO::FETCH_ASSOC);
    $rendu = 0; foreach ($ventes as $v) if ($v['statut'] === 'validee') $rendu += $v['rendu'];
    foreach ($modes as &$m) { $m['montant'] += 0; if ($m['type'] === 'especes') $m['montant'] = round($m['montant'] - $rendu, 2); }
    unset($m);

    $tot = ['nb' => 0, 'ca' => 0, 'tva' => 0, 'remises' => 0, 'credit' => 0, 'marge' => 0, 'annulees' => 0];
    foreach ($ventes as $v) {
        if ($v['statut'] === 'validee') { $tot['nb']++; $tot['ca'] += $v['total']; $tot['tva'] += $v['tva']; $tot['remises'] += $v['remise']; $tot['credit'] += $v['reste']; $tot['marge'] += $v['marge']; }
        else $tot['annulees']++;
    }
    return ['ventes' => $ventes, 'lignes' => $lignes, 'jours' => $jours->fetchAll(PDO::FETCH_ASSOC), 'modes' => $modes, 'totaux' => array_map(function ($x) { return round($x, 2); }, $tot), 'from' => $from, 'to' => $to];
}

function stock_export_data(PDO $db)
{
    $rows = $db->query("SELECT p.idprod, p.sku, p.code_barres, p.nom, c.nom AS categorie, p.unite, p.stock_qty, p.seuil_alerte, p.prix_achat, p.prix_vente, p.tva_taux, p.actif FROM produits p LEFT JOIN categories c ON c.idcat = p.idcat WHERE p.supp = 0 AND p.stockable = 1 ORDER BY c.nom, p.nom")->fetchAll(PDO::FETCH_ASSOC);
    $valeur = 0; $valeurVente = 0; $ruptures = 0; $bas = 0;
    foreach ($rows as &$r) {
        $r['valeur'] = round($r['stock_qty'] * $r['prix_achat'], 2);
        $r['etat'] = $r['stock_qty'] <= 0 ? 'Rupture' : (($r['seuil_alerte'] > 0 && $r['stock_qty'] <= $r['seuil_alerte']) ? 'Stock bas' : 'OK');
        if ($r['stock_qty'] > 0) { $valeur += $r['valeur']; $valeurVente += $r['stock_qty'] * $r['prix_vente']; }
        if ($r['etat'] === 'Rupture') $ruptures++; elseif ($r['etat'] === 'Stock bas') $bas++;
    }
    return ['produits' => $rows, 'valeur' => round($valeur, 2), 'valeur_vente' => round($valeurVente, 2), 'ruptures' => $ruptures, 'bas' => $bas];
}

/* ================= Excel ================= */

function xl_entete(XlSheet $s, $titre, $edite)
{
    $s->row([['v' => $titre, 's' => Xl::TITLE]], 24);
    $s->row([['v' => $edite, 's' => Xl::SUB]]);
    $s->skip();
}

function build_ventes_xlsx(array $d, array $per, $path)
{
    $book = new XlBook();
    $edite = (!empty($per['entreprise']) ? $per['entreprise'] . ' — ' : '') . 'Édité le ' . date('d/m/Y à H:i') . ' par ' . $per['qui'] . ' — montants en ' . $per['devise'];
    $titre = 'Journal des ventes du ' . dfr($d['from']) . ' au ' . dfr($d['to']);

    // Feuille 1 : une ligne par vente
    $s = $book->sheet('Ventes'); xl_entete($s, $titre, $edite);
    $h = $s->row([['v' => 'N°', 's' => Xl::HEAD_LEFT], ['v' => 'Date', 's' => Xl::HEAD_LEFT], ['v' => 'Caissier', 's' => Xl::HEAD_LEFT], ['v' => 'Client', 's' => Xl::HEAD_LEFT], ['v' => 'Paiement', 's' => Xl::HEAD_LEFT],
        ['v' => 'Total TTC', 's' => Xl::HEAD], ['v' => 'Remise', 's' => Xl::HEAD], ['v' => 'TVA', 's' => Xl::HEAD], ['v' => 'Marge', 's' => Xl::HEAD], ['v' => 'Reste à crédit', 's' => Xl::HEAD], ['v' => 'Statut', 's' => Xl::HEAD_LEFT]], 20);
    $first = $s->current() + 1;
    foreach ($d['ventes'] as $v) {
        $ok = $v['statut'] === 'validee';
        $s->row([['v' => $v['numero'], 's' => Xl::WRAP], ['v' => Xl::dateTimeSerial($v['date_vente']), 's' => Xl::DATETIME], ['v' => $v['caissier'], 's' => Xl::WRAP], ['v' => (string)$v['client'], 's' => Xl::WRAP], ['v' => (string)$v['modes'], 's' => Xl::WRAP],
            ['v' => $v['total'] + 0, 's' => Xl::NUM], ['v' => $v['remise'] + 0, 's' => Xl::NUM], ['v' => $v['tva'] + 0, 's' => Xl::NUM], ['v' => $v['marge'], 's' => Xl::NUM], ['v' => $v['reste'] + 0, 's' => Xl::NUM], ['v' => $ok ? 'Validée' : 'Annulée', 's' => Xl::WRAP]]);
    }
    $last = $s->current();
    if ($d['ventes']) {
        $t = $d['totaux'];
        $row = [['v' => 'TOTAL (ventes validées)', 's' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT]];
        foreach ([['F', $t['ca']], ['G', $t['remises']], ['H', $t['tva']], ['I', $t['marge']], ['J', $t['credit']]] as $x) $row[] = ['v' => $x[1], 's' => Xl::TOTAL_NUM, 'f' => "SUMIF(\$K$first:\$K$last,\"Validée\",{$x[0]}$first:{$x[0]}$last)"];
        $row[] = ['v' => $t['nb'] . ' ventes', 's' => Xl::TOTAL_TXT];
        $s->row($row, 20);
        $s->filter = "A$h:K$last";
    }
    $s->widths = [18, 17, 22, 22, 26, 14, 12, 12, 12, 14, 11]; $s->freeze = [$h + 1, 2];

    // Feuille 2 : détail des lignes
    $l = $book->sheet('Lignes'); xl_entete($l, 'Détail des lignes — ' . $titre, $edite);
    $hl = $l->row([['v' => 'N° vente', 's' => Xl::HEAD_LEFT], ['v' => 'Date', 's' => Xl::HEAD_LEFT], ['v' => 'Statut', 's' => Xl::HEAD_LEFT], ['v' => 'Client', 's' => Xl::HEAD_LEFT], ['v' => 'Désignation', 's' => Xl::HEAD_LEFT], ['v' => 'Catégorie', 's' => Xl::HEAD_LEFT],
        ['v' => 'Quantité', 's' => Xl::HEAD], ['v' => 'Prix unitaire', 's' => Xl::HEAD], ['v' => 'Remise', 's' => Xl::HEAD], ['v' => 'TVA %', 's' => Xl::HEAD], ['v' => 'Total TTC', 's' => Xl::HEAD], ['v' => 'Coût unitaire', 's' => Xl::HEAD]], 20);
    foreach ($d['lignes'] as $x) {
        $l->row([['v' => $x['numero'], 's' => Xl::WRAP], ['v' => Xl::dateTimeSerial($x['date_vente']), 's' => Xl::DATETIME], ['v' => $x['statut'] === 'validee' ? 'Validée' : 'Annulée', 's' => Xl::WRAP], ['v' => (string)$x['client'], 's' => Xl::WRAP], ['v' => $x['designation'], 's' => Xl::WRAP], ['v' => (string)$x['categorie'], 's' => Xl::WRAP],
            ['v' => $x['quantite'] + 0, 's' => Xl::NUM_DEC], ['v' => $x['prix_unitaire'] + 0, 's' => Xl::NUM], ['v' => $x['remise'] + 0, 's' => Xl::NUM], ['v' => $x['tva_taux'] + 0, 's' => Xl::NUM_DEC], ['v' => $x['total'] + 0, 's' => Xl::NUM], ['v' => $x['cout_unitaire'] + 0, 's' => Xl::NUM]]);
    }
    if ($d['lignes']) $l->filter = 'A' . $hl . ':L' . $l->current();
    $l->widths = [18, 17, 11, 22, 32, 18, 11, 13, 11, 8, 14, 13]; $l->freeze = [$hl + 1, 1];

    // Feuille 3 : synthèse par jour et par mode de paiement
    $y = $book->sheet('Synthèse'); xl_entete($y, 'Synthèse — ' . $titre, $edite);
    $hy = $y->row([['v' => 'Jour', 's' => Xl::HEAD_LEFT], ['v' => 'Ventes', 's' => Xl::HEAD], ['v' => 'Chiffre d\'affaires', 's' => Xl::HEAD], ['v' => 'TVA', 's' => Xl::HEAD], ['v' => 'Remises', 's' => Xl::HEAD], ['v' => 'Marge brute', 's' => Xl::HEAD]], 20);
    $f1 = $y->current() + 1;
    foreach ($d['jours'] as $j) $y->row([['v' => Xl::dateSerial($j['jour']), 's' => Xl::DATE], ['v' => (int)$j['nb'], 's' => Xl::NUM], ['v' => $j['ca'] + 0, 's' => Xl::NUM], ['v' => $j['tva'] + 0, 's' => Xl::NUM], ['v' => $j['remises'] + 0, 's' => Xl::NUM], ['v' => round($j['ca'] - $j['tva'] - $j['cout'], 2), 's' => Xl::NUM]]);
    if ($d['jours']) {
        $l2 = $y->current(); $t = $d['totaux'];
        $y->row([['v' => 'TOTAL', 's' => Xl::TOTAL_TXT], ['v' => $t['nb'], 's' => Xl::TOTAL_NUM, 'f' => "SUM(B$f1:B$l2)"], ['v' => $t['ca'], 's' => Xl::TOTAL_NUM, 'f' => "SUM(C$f1:C$l2)"], ['v' => $t['tva'], 's' => Xl::TOTAL_NUM, 'f' => "SUM(D$f1:D$l2)"], ['v' => $t['remises'], 's' => Xl::TOTAL_NUM, 'f' => "SUM(E$f1:E$l2)"], ['v' => $t['marge'], 's' => Xl::TOTAL_NUM, 'f' => "SUM(F$f1:F$l2)"]], 20);
    }
    $y->skip();
    $y->row([['v' => 'Encaissements par mode de paiement', 's' => Xl::TITLE]], 22);
    $y->row([['v' => 'Mode', 's' => Xl::HEAD_LEFT], ['v' => 'Opérations', 's' => Xl::HEAD], ['v' => 'Montant encaissé', 's' => Xl::HEAD]], 20);
    $m1 = $y->current() + 1;
    foreach ($d['modes'] as $m) $y->row([['v' => $m['libelle'], 's' => Xl::WRAP], ['v' => (int)$m['nb'], 's' => Xl::NUM], ['v' => $m['montant'], 's' => Xl::NUM]]);
    if ($d['modes']) { $m2 = $y->current(); $y->row([['v' => 'TOTAL ENCAISSÉ', 's' => Xl::TOTAL_TXT], ['v' => array_sum(array_column($d['modes'], 'nb')), 's' => Xl::TOTAL_NUM, 'f' => "SUM(B$m1:B$m2)"], ['v' => round(array_sum(array_column($d['modes'], 'montant')), 2), 's' => Xl::TOTAL_NUM, 'f' => "SUM(C$m1:C$m2)"]], 20); }
    $y->widths = [24, 14, 20, 14, 14, 16];
    $book->save($path);
}

function build_stock_xlsx(array $d, array $per, $path)
{
    $book = new XlBook();
    $edite = (!empty($per['entreprise']) ? $per['entreprise'] . ' — ' : '') . 'Édité le ' . date('d/m/Y à H:i') . ' par ' . $per['qui'] . ' — montants en ' . $per['devise'];
    $s = $book->sheet('Stock valorisé'); xl_entete($s, 'État du stock valorisé au ' . date('d/m/Y'), $edite);
    $h = $s->row([['v' => 'Référence', 's' => Xl::HEAD_LEFT], ['v' => 'Désignation', 's' => Xl::HEAD_LEFT], ['v' => 'Catégorie', 's' => Xl::HEAD_LEFT], ['v' => 'Unité', 's' => Xl::HEAD_LEFT], ['v' => 'Stock', 's' => Xl::HEAD], ['v' => 'Seuil', 's' => Xl::HEAD],
        ['v' => 'Coût moyen', 's' => Xl::HEAD], ['v' => 'Valeur du stock', 's' => Xl::HEAD], ['v' => 'Prix de vente', 's' => Xl::HEAD], ['v' => 'Marge unitaire', 's' => Xl::HEAD], ['v' => 'État', 's' => Xl::HEAD_LEFT]], 20);
    $first = $s->current() + 1;
    foreach ($d['produits'] as $p) {
        $r = $s->current() + 1;
        $s->row([['v' => $p['sku'] ?: $p['code_barres'], 's' => Xl::WRAP], ['v' => $p['nom'], 's' => Xl::WRAP], ['v' => (string)$p['categorie'], 's' => Xl::WRAP], ['v' => $p['unite'], 's' => Xl::WRAP], ['v' => $p['stock_qty'] + 0, 's' => Xl::NUM_DEC], ['v' => $p['seuil_alerte'] + 0, 's' => Xl::NUM_DEC],
            ['v' => $p['prix_achat'] + 0, 's' => Xl::NUM], ['v' => $p['valeur'], 's' => Xl::NUM_BOLD, 'f' => "E$r*G$r"], ['v' => $p['prix_vente'] + 0, 's' => Xl::NUM], ['v' => round($p['prix_vente'] - $p['prix_achat'], 2), 's' => Xl::NUM, 'f' => "I$r-G$r"], ['v' => $p['etat'], 's' => Xl::WRAP]]);
    }
    if ($d['produits']) {
        $last = $s->current();
        $s->row([['v' => 'VALEUR TOTALE DU STOCK', 's' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT],
            ['v' => $d['valeur'], 's' => Xl::TOTAL_NUM, 'f' => "SUMIF(E$first:E$last,\">0\",H$first:H$last)"], ['s' => Xl::TOTAL_TXT], ['s' => Xl::TOTAL_TXT], ['v' => $d['ruptures'] . ' rupture(s)', 's' => Xl::TOTAL_TXT]], 20);
        $s->filter = "A$h:K$last";
    }
    $s->widths = [16, 34, 18, 9, 11, 10, 13, 16, 14, 14, 11]; $s->freeze = [$h + 1, 3];
    $book->save($path);
}

/* ================= CSV ================= */

function csv_nombre($n) { return $n == 0 ? '0' : str_replace('.', ',', rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.')); }

function build_ventes_csv(array $d, $path)
{
    $f = fopen($path, 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['N° vente', 'Date', 'Statut', 'Client', 'Désignation', 'Catégorie', 'Quantité', 'Prix unitaire', 'Remise', 'TVA %', 'Total TTC'], ';', '"', '\\');
    foreach ($d['lignes'] as $x) fputcsv($f, [$x['numero'], dhfr($x['date_vente']), $x['statut'] === 'validee' ? 'Validée' : 'Annulée', (string)$x['client'], $x['designation'], (string)$x['categorie'], csv_nombre($x['quantite']), csv_nombre($x['prix_unitaire']), csv_nombre($x['remise']), csv_nombre($x['tva_taux']), csv_nombre($x['total'])], ';', '"', '\\');
    fclose($f);
}

function build_stock_csv(array $d, $path)
{
    $f = fopen($path, 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Référence', 'Code-barres', 'Désignation', 'Catégorie', 'Unité', 'Stock', 'Seuil', 'Coût moyen', 'Valeur du stock', 'Prix de vente', 'État'], ';', '"', '\\');
    foreach ($d['produits'] as $p) fputcsv($f, [$p['sku'], $p['code_barres'], $p['nom'], (string)$p['categorie'], $p['unite'], csv_nombre($p['stock_qty']), csv_nombre($p['seuil_alerte']), csv_nombre($p['prix_achat']), csv_nombre($p['valeur']), csv_nombre($p['prix_vente']), $p['etat']], ';', '"', '\\');
    fclose($f);
}

/* ================= PDF ================= */

/** Tableau paginé : $cols = [[titre, largeur, alignement]], $rows = lignes de textes, $total = ligne de totaux facultative. */
function pdf_tableau(array $cols, array $rows, $titre, $sous, $entreprise, $edite, $path, $total = null, $blocFinal = null)
{
    $pdf = new Pdf(); $M = 36; $W = Pdf::W - 2 * $M; $rowH = 14;
    $somme = array_sum(array_column($cols, 1)); foreach ($cols as &$c) $c[1] = $c[1] / $somme * $W; unset($c);
    $entete = function () use ($pdf, $titre, $sous, $entreprise, $M, $W, $cols) {
        $pdf->page();
        $pdf->rect(0, 0, Pdf::W, 64, '7048E8');
        $pdf->text($M, 30, $titre, 17, true, 'L', 0, 'FFFFFF');
        $pdf->text($M, 48, $sous, 9, false, 'L', 0, 'E7DFFF');
        if ($entreprise) $pdf->text($M, 30, $entreprise, 10, true, 'R', $W, 'FFFFFF');
        $pdf->rect($M, 80, $W, 22, 'F3F0FA'); $x = $M;
        foreach ($cols as $c) { $pdf->text($x + 4, 94, $c[0], 7.5, true, $c[2], $c[1] - 8); $x += $c[1]; }
        return 102;
    };
    $ligne = function ($y, array $cells, $gras = false, $fond = null) use ($pdf, $cols, $M, $W, $rowH) {
        if ($fond) $pdf->rect($M, $y, $W, $rowH, $fond);
        $x = $M;
        foreach ($cols as $i => $c) { $pdf->text($x + 4, $y + 10, (string)$cells[$i], 8, $gras, $c[2], $c[1] - 8); $x += $c[1]; }
        $pdf->line($M, $y + $rowH, $M + $W, $y + $rowH, 'ECE8F5', 0.4);
        return $y + $rowH;
    };
    $y = $entete(); $lim = Pdf::H - 40 - $rowH;
    foreach ($rows as $r) { if ($y > $lim) $y = $entete(); $y = $ligne($y, $r); }
    if ($total) { if ($y > $lim) $y = $entete(); $y = $ligne($y, $total, true, 'E7DFFF'); }
    if ($blocFinal) {
        $y += 18;
        if ($y + count($blocFinal) * 14 + 24 > Pdf::H - 40) $y = $entete();
        foreach ($blocFinal as $b) { $pdf->text($M, $y, $b[0], 9, !empty($b[2]), 'L', 0, '2A2340'); $pdf->text($M + 230, $y, $b[1], 9, !empty($b[2]), 'R', 120, '2A2340'); $y += 14; }
    }
    $nb = $pdf->pageCount();
    for ($i = 1; $i <= $nb; $i++) {
        $pdf->select($i);
        $pdf->line($M, Pdf::H - 34, $M + $W, Pdf::H - 34, 'D9D4E8');
        $pdf->text($M, Pdf::H - 22, $edite, 7.5, false, 'L', 0, '7B7393');
        $pdf->text($M, Pdf::H - 22, "Page $i / $nb", 7.5, false, 'R', $W, '7B7393');
    }
    $pdf->save($path);
}

function build_ventes_pdf(array $d, array $per, $path)
{
    $cols = [['N°', 70, 'L'], ['Date', 78, 'L'], ['Caissier', 100, 'L'], ['Client', 100, 'L'], ['Paiement', 110, 'L'], ['Total TTC', 70, 'R'], ['Reste', 60, 'R'], ['Statut', 55, 'L']];
    $rows = [];
    foreach ($d['ventes'] as $v) $rows[] = [$v['numero'], dhfr($v['date_vente']), $v['caissier'], (string)$v['client'], (string)$v['modes'], pdf_num($v['total']), pdf_num($v['reste']), $v['statut'] === 'validee' ? 'Validée' : 'Annulée'];
    $t = $d['totaux'];
    $bloc = [['Ventes validées', (string)$t['nb'], true], ["Chiffre d'affaires TTC", pdf_num($t['ca']) . ' ' . $per['devise'], true], ['dont TVA', pdf_num($t['tva'])], ['Remises accordées', pdf_num($t['remises'])], ['Marge brute (HT − coût d\'achat)', pdf_num($t['marge'])], ['Ventes à crédit (reste dû)', pdf_num($t['credit'])], ['Ventes annulées', (string)$t['annulees']], ['', '']];
    foreach ($d['modes'] as $m) $bloc[] = ['Encaissé — ' . $m['libelle'], pdf_num($m['montant'])];
    pdf_tableau($cols, $rows, 'Journal des ventes', 'Du ' . dfr($d['from']) . ' au ' . dfr($d['to']) . ' — montants en ' . $per['devise'], $per['entreprise'], 'Édité le ' . date('d/m/Y à H:i') . ' par ' . $per['qui'], $path, ['TOTAL VALIDÉES', '', '', '', '', pdf_num($t['ca']), pdf_num($t['credit']), $t['nb'] . ' ventes'], $bloc);
}

function build_stock_pdf(array $d, array $per, $path)
{
    $cols = [['Réf.', 62, 'L'], ['Désignation', 175, 'L'], ['Catégorie', 90, 'L'], ['Stock', 50, 'R'], ['Seuil', 42, 'R'], ['Coût moyen', 62, 'R'], ['Valeur', 75, 'R'], ['Prix vente', 62, 'R'], ['État', 55, 'L']];
    $rows = [];
    foreach ($d['produits'] as $p) $rows[] = [$p['sku'] ?: $p['code_barres'], $p['nom'], (string)$p['categorie'], pdf_num($p['stock_qty'], '0') . ' ' . mb_substr($p['unite'], 0, 4), pdf_num($p['seuil_alerte']), pdf_num($p['prix_achat']), pdf_num($p['valeur']), pdf_num($p['prix_vente']), $p['etat']];
    $bloc = [['Valeur du stock (au coût moyen)', pdf_num($d['valeur']) . ' ' . $per['devise'], true], ['Valeur de vente potentielle', pdf_num($d['valeur_vente'])], ['Produits en rupture', (string)$d['ruptures']], ['Produits sous le seuil', (string)$d['bas']]];
    pdf_tableau($cols, $rows, 'État du stock valorisé', 'Situation au ' . date('d/m/Y') . ' — montants en ' . $per['devise'], $per['entreprise'], 'Édité le ' . date('d/m/Y à H:i') . ' par ' . $per['qui'], $path, ['TOTAL', '', '', '', '', '', pdf_num($d['valeur']), '', ''], $bloc);
}

/* ================= Tâches ================= */

function export_public(array $j)
{
    $p = json_decode($j['params'], true) ?: [];
    return ['id' => (int)$j['id'], 'format' => $j['format'], 'status' => $j['status'], 'progress' => (int)$j['progress'], 'filename' => $j['filename'],
        'size' => (int)$j['size'], 'error' => $j['error'], 'created_at' => $j['created_at'], 'finished_at' => $j['finished_at'], 'kind' => $p['kind'] ?? 'ventes', 'from' => $p['from'] ?? null, 'to' => $p['to'] ?? null];
}

function export_create(PDO $db, array $user, array $in)
{
    $kind = isset($in['kind']) && in_array($in['kind'], ['ventes', 'stock', 'database'], true) ? $in['kind'] : 'ventes';
    if ($kind === 'database') {
        if (!is_super_role($user['role'])) throw new ApiError(403, 'La sauvegarde de la base est réservée au super administrateur.');
        $in['format'] = 'sql.gz';
    } elseif ($kind === 'ventes') {
        exiger($user, 'ventes.lire_toutes', 'Les exports de ventes sont réservés au gérant.');
    } else {
        exiger($user, 'stock.lire', "Vous n'avez pas accès à l'état du stock.");
    }
    $format = isset($in['format']) ? (string)$in['format'] : '';
    if (!in_array($format, ['xlsx', 'csv', 'pdf', 'sql.gz'], true) || ($format === 'sql.gz' && $kind !== 'database') || ($kind === 'database' && $format !== 'sql.gz')) {
        throw new ApiError(422, 'Données invalides', ['format' => 'Format inconnu (xlsx, csv ou pdf)']);
    }
    $from = $to = null;
    if ($kind === 'ventes') {
        $from = isset($in['from']) && $in['from'] !== '' ? (string)$in['from'] : gmdate('Y-m-01');
        $to = isset($in['to']) && $in['to'] !== '' ? (string)$in['to'] : gmdate('Y-m-d');
        foreach (['from' => $from, 'to' => $to] as $k => $v) { $d = DateTime::createFromFormat('Y-m-d', $v); if (!$d || $d->format('Y-m-d') !== $v) throw new ApiError(422, 'Données invalides', [$k => 'Date invalide']); }
        if ($to < $from) throw new ApiError(422, 'Données invalides', ['to' => 'La fin doit suivre le début']);
        if ((strtotime($to) - strtotime($from)) / 86400 > 400) throw new ApiError(422, 'Données invalides', ['to' => 'Période limitée à 13 mois']);
    }

    // Ménage : fichiers de plus de 7 jours, et tâches restées « en cours » trop longtemps (processus interrompu)
    foreach ($db->query("SELECT id, stored FROM export_jobs WHERE created_at < '" . gmdate('Y-m-d H:i:s', time() - 7 * 86400) . "'")->fetchAll(PDO::FETCH_ASSOC) as $old) {
        if ($old['stored'] && is_file(export_dir() . '/' . basename($old['stored']))) @unlink(export_dir() . '/' . basename($old['stored']));
        $db->prepare('DELETE FROM export_jobs WHERE id = ?')->execute([$old['id']]);
    }
    $db->exec("UPDATE export_jobs SET status = 'echec', error = 'Traitement interrompu' WHERE status IN ('en_attente','en_cours') AND created_at < '" . gmdate('Y-m-d H:i:s', time() - 600) . "'");

    $st = $db->prepare('INSERT INTO export_jobs (user_id, format, params, status, progress, created_at) VALUES (?,?,?,?,0,?)');
    $st->execute([$user['id'], $format, json_encode(['kind' => $kind, 'from' => $from, 'to' => $to, 'qui' => !empty($user['nom']) ? $user['nom'] : $user['email']]), 'en_attente', gmdate('Y-m-d H:i:s')]);
    return export_get($db, (int)$db->lastInsertId(), $user['id']);
}

function export_get(PDO $db, $id, $userId)
{
    $st = $db->prepare('SELECT * FROM export_jobs WHERE id = ? AND user_id = ?');
    $st->execute([$id, $userId]);
    $j = $st->fetch(PDO::FETCH_ASSOC);
    if (!$j) throw new ApiError(404, 'Export introuvable');
    return $j;
}

function export_list(PDO $db, $userId)
{
    $db->exec("UPDATE export_jobs SET status = 'echec', error = 'Traitement interrompu' WHERE status IN ('en_attente','en_cours') AND created_at < '" . gmdate('Y-m-d H:i:s', time() - 600) . "'");
    $st = $db->prepare('SELECT * FROM export_jobs WHERE user_id = ? ORDER BY id DESC LIMIT 10');
    $st->execute([$userId]);
    return array_map('export_public', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Exécute la tâche (appelée après l'envoi de la réponse HTTP). Toute erreur est enregistrée sur la tâche. */
function export_run(PDO $db, $id)
{
    $maj = function (array $champs) use ($db, $id) {
        $sets = []; foreach ($champs as $k => $_) $sets[] = "$k = :$k";
        $champs['_id'] = $id;
        $db->prepare('UPDATE export_jobs SET ' . implode(', ', $sets) . ' WHERE id = :_id')->execute($champs);
    };
    $pause = (int)env('EXPORT_DELAY_MS', 0);   // uniquement pour les tests : simule un traitement long
    try {
        $j = $db->query('SELECT * FROM export_jobs WHERE id = ' . (int)$id)->fetch(PDO::FETCH_ASSOC);
        if (!$j) return;
        $p = json_decode($j['params'], true);
        $maj(['status' => 'en_cours', 'progress' => 5]);
        $stored = bin2hex(random_bytes(8)) . '.' . $j['format'];
        $path = export_dir() . '/' . $stored;
        $fini = function ($nom) use ($maj, $path, $stored) { $maj(['status' => 'termine', 'progress' => 100, 'filename' => $nom, 'stored' => $stored, 'size' => filesize($path), 'finished_at' => gmdate('Y-m-d H:i:s')]); };

        if (($p['kind'] ?? '') === 'database') {
            dump_database($db, $path, function ($pct) use ($maj, $pause) { $maj(['progress' => max(6, min(95, $pct))]); if ($pause) usleep($pause * 1000); });
            return $fini('caisse-sauvegarde-' . gmdate('Y-m-d-Hi') . '.sql.gz');
        }
        $reg = settings_all($db);
        $per = ['entreprise' => $reg['entreprise.nom'], 'devise' => $reg['entreprise.devise'], 'qui' => $p['qui'] ?? ''];
        if ($pause) usleep($pause * 1000);
        if ($p['kind'] === 'stock') {
            $data = stock_export_data($db); $maj(['progress' => 45]); if ($pause) usleep($pause * 1000);
            if ($j['format'] === 'xlsx') build_stock_xlsx($data, $per, $path); elseif ($j['format'] === 'csv') build_stock_csv($data, $path); else build_stock_pdf($data, $per, $path);
            $maj(['progress' => 90]); if ($pause) usleep($pause * 1000);
            return $fini('caisse-stock-' . gmdate('Y-m-d') . '.' . $j['format']);
        }
        $data = ventes_export_data($db, $p['from'], $p['to']); $maj(['progress' => 45]); if ($pause) usleep($pause * 1000);
        if ($j['format'] === 'xlsx') build_ventes_xlsx($data, $per, $path); elseif ($j['format'] === 'csv') build_ventes_csv($data, $path); else build_ventes_pdf($data, $per, $path);
        $maj(['progress' => 90]); if ($pause) usleep($pause * 1000);
        $fini('caisse-ventes-' . $p['from'] . '_' . $p['to'] . '.' . $j['format']);
    } catch (Throwable $e) {
        error_log('Export #' . $id . ' : ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $maj(['status' => 'echec', 'error' => mb_substr('Génération impossible : ' . $e->getMessage(), 0, 480), 'finished_at' => gmdate('Y-m-d H:i:s')]);
    }
}
