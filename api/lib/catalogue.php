<?php
/**
 * Catalogue numérique public : la vitrine que le client ouvre en scannant le QR code (sans compte).
 * Ne sort que ce qu'un client peut voir : jamais le prix d'achat, la quantité exacte en stock, le téléphone d'un auteur d'avis
 * ni les captures d'écran des avis. Les réglages (prix, disponibilité, avis, WhatsApp) sont tenus par le gérant.
 */

const CATALOGUE_CHAMPS = ['actif', 'prix', 'dispo', 'ruptures', 'avis', 'whatsapp', 'message'];

function catalogue_reglages(array $a)
{
    $r = settings_section($a, 'catalogue');
    foreach (['actif', 'prix', 'dispo', 'ruptures', 'avis'] as $k) $r[$k] = $r[$k] === '1';
    return $r;
}

/** Produits visibles : actifs, non supprimés, cochés « catalogue » ; les ruptures sont masquées si le gérant le souhaite. */
function catalogue_condition(array $r)
{
    return 'p.supp = 0 AND p.actif = 1 AND p.catalogue = 1' . ($r['ruptures'] ? '' : ' AND (p.stockable = 0 OR p.stock_qty > 0)');
}

function catalogue_dispo(array $p)
{
    if (!(int)$p['stockable']) return 'service';
    $q = (float)$p['stock_qty'];
    return $q <= 0 ? 'rupture' : ((float)$p['seuil_alerte'] > 0 && $q <= (float)$p['seuil_alerte'] ? 'bas' : 'ok');
}

/** « Awa Kouassi » → « Awa K. » : un avis public ne montre pas le nom complet. */
function catalogue_auteur($nom)
{
    $parts = preg_split('/\s+/u', trim((string)$nom), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) return 'Client';
    return $parts[0] . (isset($parts[1]) ? ' ' . mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.' : '');
}

function catalogue_entete(array $a, array $r)
{
    return [
        'entreprise' => ['nom' => $a['entreprise.nom'], 'logo' => $a['entreprise.logo'] !== '', 'telephone' => $a['entreprise.telephone'], 'email' => $a['entreprise.email'],
            'adresse' => trim($a['entreprise.adresse'] . ($a['entreprise.ville'] !== '' ? ', ' . $a['entreprise.ville'] : ''), ', '), 'site' => $a['entreprise.site'], 'devise' => $a['entreprise.devise']],
        'reglages' => ['prix' => $r['prix'], 'dispo' => $r['dispo'], 'avis' => $r['avis'], 'whatsapp' => preg_replace('/\D/', '', $r['whatsapp']), 'message' => $r['message']],
    ];
}

/** $apercu : le gérant consulte (ou imprime les étiquettes) même si le catalogue est désactivé. */
function catalogue_liste(PDO $db, $apercu = false)
{
    $a = settings_all($db); $r = catalogue_reglages($a);
    if (!$r['actif'] && !$apercu) throw new ApiError(404, "Le catalogue n'est pas disponible pour le moment.");
    $w = catalogue_condition($r);
    $rows = $db->query("SELECT p.idprod, p.nom, p.idcat, p.prix_vente, p.image, p.marque, p.origine, p.stockable, p.stock_qty, p.seuil_alerte, p.unite, p.dateenr,
            (SELECT COUNT(*) FROM produit_photos x WHERE x.idprod = p.idprod) AS nb_photos,
            (SELECT ROUND(AVG(v.note), 1) FROM avis v WHERE v.idprod = p.idprod AND v.supp = 0) AS note,
            (SELECT COUNT(*) FROM avis v WHERE v.idprod = p.idprod AND v.supp = 0) AS nb_avis
        FROM produits p WHERE $w ORDER BY p.nom")->fetchAll(PDO::FETCH_ASSOC);
    $produits = array_map(function ($p) use ($r) {
        return ['id' => (int)$p['idprod'], 'nom' => $p['nom'], 'idcat' => $p['idcat'] !== null ? (int)$p['idcat'] : null, 'image' => $p['image'], 'marque' => trim($p['marque']), 'origine' => trim($p['origine']),
            'nouveau' => $p['dateenr'] !== null && strtotime($p['dateenr']) > time() - 30 * 86400, 'ajout' => $p['dateenr'],
            'prix' => $r['prix'] ? (float)$p['prix_vente'] : null, 'unite' => (int)$p['stockable'] ? $p['unite'] : '', 'dispo' => $r['dispo'] ? catalogue_dispo($p) : null,
            'note' => $r['avis'] && $p['nb_avis'] ? (float)$p['note'] : null, 'nb_avis' => $r['avis'] ? (int)$p['nb_avis'] : 0, 'nb_photos' => (int)$p['nb_photos']];
    }, $rows);
    $ids = array_values(array_unique(array_filter(array_column($produits, 'idcat'))));
    $categories = $ids ? $db->query('SELECT idcat AS id, nom, couleur, image FROM categories WHERE supp = 0 AND idcat IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY ordre, nom')->fetchAll(PDO::FETCH_ASSOC) : [];
    return catalogue_entete($a, $r) + ['categories' => array_map(function ($c) { $c['id'] = (int)$c['id']; return $c; }, $categories), 'produits' => $produits, 'actif' => $r['actif']];
}

function catalogue_produit(PDO $db, $id)
{
    $a = settings_all($db); $r = catalogue_reglages($a);
    if (!$r['actif']) throw new ApiError(404, "Le catalogue n'est pas disponible pour le moment.");
    $st = $db->prepare('SELECT p.*, c.nom AS categorie FROM produits p LEFT JOIN categories c ON c.idcat = p.idcat WHERE p.idprod = :i AND ' . catalogue_condition($r));
    $st->execute(['i' => (int)$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new ApiError(404, "Ce produit n'est plus présenté dans le catalogue.");
    $photos = array_map(function ($x) { return ['fichier' => $x['fichier'], 'legende' => $x['legende']]; }, galerie_liste($db, $p['idprod']));
    if (!$photos && $p['image'] !== '') $photos = [['fichier' => $p['image'], 'legende' => '']];
    $avis = [];
    if ($r['avis']) {
        $st = $db->prepare('SELECT source, note, auteur, date_avis, commentaire, reponse FROM avis WHERE idprod = ? AND supp = 0 ORDER BY date_avis DESC, idavis DESC LIMIT 30');
        $st->execute([$p['idprod']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $avis[] = ['source' => $v['source'], 'source_libelle' => AVIS_SOURCES[$v['source']] ?? $v['source'], 'note' => (int)$v['note'],
            'auteur' => catalogue_auteur($v['auteur']), 'date' => $v['date_avis'], 'commentaire' => (string)$v['commentaire'], 'reponse' => (string)$v['reponse']];
    }
    $produit = ['id' => (int)$p['idprod'], 'nom' => $p['nom'], 'categorie' => $p['categorie'], 'idcat' => $p['idcat'] !== null ? (int)$p['idcat'] : null, 'service' => !(int)$p['stockable'],
        'prix' => $r['prix'] ? (float)$p['prix_vente'] : null, 'tva' => (float)$p['tva_taux'], 'unite' => $p['unite'], 'dispo' => $r['dispo'] ? catalogue_dispo($p) : null,
        'description' => (string)$p['description'], 'caracteristiques' => (string)$p['caracteristiques'], 'reference' => $p['sku']];
    foreach (['marque', 'modele', 'origine', 'couleur', 'dimensions', 'poids', 'garantie'] as $k) $produit[$k] = $p[$k];
    return catalogue_entete($a, $r) + ['produit' => $produit, 'photos' => $photos, 'avis' => $avis, 'avis_synthese' => $r['avis'] ? avis_synthese($db, $p['idprod']) : null];
}

/**
 * Photo publique : uniquement un fichier rattaché à un produit visible dans le catalogue, ou la photo d'une catégorie
 * qui contient un tel produit (aucun autre fichier de doc/ n'est accessible).
 */
function catalogue_image(PDO $db, $nom, $dossier = 'produits')
{
    $nom = basename((string)$nom);
    $r = catalogue_reglages(settings_all($db));
    $w = catalogue_condition($r);
    if ($dossier === 'categories') {
        $st = $db->prepare("SELECT COUNT(*) FROM categories c WHERE c.supp = 0 AND c.image = :a AND EXISTS (SELECT 1 FROM produits p WHERE p.idcat = c.idcat AND $w)");
        $st->execute(['a' => $nom]);
    } else {
        $dossier = 'produits';
        $st = $db->prepare("SELECT COUNT(*) FROM produits p WHERE $w AND (p.image = :a OR EXISTS (SELECT 1 FROM produit_photos x WHERE x.idprod = p.idprod AND x.fichier = :b))");
        $st->execute(['a' => $nom, 'b' => $nom]);
    }
    $chemin = dirname(__DIR__, 2) . '/doc/' . $dossier . '/' . $nom;
    if (!$r['actif'] || $nom === '' || !(int)$st->fetchColumn() || !is_file($chemin)) throw new ApiError(404, 'Photo introuvable');
    $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) throw new ApiError(404, 'Photo introuvable');
    session_write_close();
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($chemin);
    exit;
}

/* ---------- Réglages (gérant) ---------- */

function catalogue_config(PDO $db)
{
    $r = catalogue_reglages(settings_all($db));
    $st = $db->query('SELECT COUNT(*) AS total, SUM(' . catalogue_condition($r) . ') AS visibles FROM produits p WHERE p.supp = 0');
    $n = $st->fetch(PDO::FETCH_ASSOC);
    return ['reglages' => $r, 'produits_visibles' => (int)$n['visibles'], 'produits_total' => (int)$n['total']];
}

function catalogue_config_enregistrer(PDO $db, array $user, array $in)
{
    exiger($user, 'catalogue.ecrire', 'Seul le gérant peut régler le catalogue.');
    if (!is_admin_role($user['role'])) throw new ApiError(403, 'Seul le gérant peut régler le catalogue.');
    $avant = settings_all($db);
    $v = [];
    foreach (['actif', 'prix', 'dispo', 'ruptures', 'avis'] as $k) if (array_key_exists($k, $in)) $v["catalogue.$k"] = !empty($in[$k]) && $in[$k] !== '0' ? '1' : '0';
    if (array_key_exists('whatsapp', $in)) {
        $num = preg_replace('/[^\d+]/', '', (string)$in['whatsapp']);
        $chiffres = strlen(preg_replace('/\D/', '', $num));
        if ($num !== '' && ($num[0] !== '+' || $chiffres < 8 || $chiffres > 15)) throw new ApiError(422, 'Données invalides', ['whatsapp' => 'Numéro au format international, ex. +225 07 12 34 56 78']);
        $v['catalogue.whatsapp'] = (string)$in['whatsapp'];
    }
    if (array_key_exists('message', $in)) $v['catalogue.message'] = mb_substr(trim((string)$in['message']), 0, 300);
    foreach ($v as $cle => $val) settings_set($db, $cle, $val, $user['id']);
    $diff = journal_diff($avant, $v);
    if ($diff) journaliser($db, $user, 'update', 'configuration', null, 'Catalogue numérique modifié', $diff);
    return catalogue_config($db);
}
