<?php
/**
 * Galerie de photos des produits. La photo « principale » est celle de produits.image (caisse, listes, ticket) ;
 * les autres complètent la fiche. Ordre réglable, légende, retouche (remplacement du fichier), suppression.
 */

const GALERIE_MAX = 12;
const GALERIE_EXT = ['jpg', 'jpeg', 'png', 'webp'];

function galerie_liste(PDO $db, $idprod)
{
    $st = $db->prepare('SELECT idphoto, fichier, legende, ordre, dateenr FROM produit_photos WHERE idprod = ? ORDER BY ordre, idphoto');
    $st->execute([(int)$idprod]);
    return array_map(function ($r) { $r['idphoto'] = (int)$r['idphoto']; $r['ordre'] = (int)$r['ordre']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Photo choisie dans le formulaire du produit : ajoutée en tête de galerie si elle n'y est pas encore. */
function galerie_ajouter_principale(PDO $db, array $row, $user)
{
    if (empty($row['image'])) return;
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM produit_photos WHERE idprod = ? AND fichier = ?'); $st->execute([$row['idprod'], $row['image']]);
        if ((int)$st->fetchColumn() > 0) return;
        $st = $db->prepare('SELECT COALESCE(MIN(ordre), 1) - 1 FROM produit_photos WHERE idprod = ?'); $st->execute([$row['idprod']]);
        $db->prepare('INSERT INTO produit_photos (idprod, fichier, ordre, idenr, dateenr) VALUES (?,?,?,?,?)')->execute([$row['idprod'], $row['image'], (int)$st->fetchColumn(), $user['id'] ?? null, gmdate('Y-m-d H:i:s')]);
    } catch (PDOException $e) { error_log('Galerie indisponible : ' . $e->getMessage()); }   // migration 011 non appliquée
}

function galerie_produit(PDO $db, $idprod)
{
    $st = $db->prepare('SELECT idprod, nom, image FROM produits WHERE idprod = ? AND supp = 0'); $st->execute([(int)$idprod]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) throw new ApiError(404, 'Produit introuvable');
    return $p;
}

function galerie_reponse(PDO $db, $idprod)
{
    return ['photos' => galerie_liste($db, $idprod), 'image' => galerie_produit($db, $idprod)['image']];
}

/** Effacement du fichier, sauf s'il sert encore ailleurs (autre photo, photo principale). */
function galerie_effacer_fichier(PDO $db, $fichier)
{
    $st = $db->prepare('SELECT (SELECT COUNT(*) FROM produit_photos WHERE fichier = :a) + (SELECT COUNT(*) FROM produits WHERE image = :b)');
    $st->execute(['a' => $fichier, 'b' => $fichier]);
    if ((int)$st->fetchColumn() === 0) @unlink(dirname(__DIR__, 2) . '/doc/produits/' . basename($fichier));
}

/** Plusieurs photos en un envoi (champ « photos[] »). La première devient principale si le produit n'en a pas. */
function galerie_televerser(PDO $db, array $user, $idprod, array $files)
{
    exiger($user, 'catalogue.ecrire', 'Seul le magasinier ou le gérant peut modifier les photos.');
    $p = galerie_produit($db, $idprod);
    $liste = [];
    if (isset($files['photos']['name']) && is_array($files['photos']['name'])) {
        foreach ($files['photos']['name'] as $i => $nom) $liste[] = ['name' => $nom, 'type' => $files['photos']['type'][$i], 'tmp_name' => $files['photos']['tmp_name'][$i], 'error' => $files['photos']['error'][$i], 'size' => $files['photos']['size'][$i]];
    } elseif (isset($files['photos']['name'])) $liste[] = $files['photos'];
    $liste = array_values(array_filter($liste, function ($f) { return $f['error'] !== UPLOAD_ERR_NO_FILE; }));
    if (!$liste) throw new ApiError(422, 'Données invalides', ['photos' => 'Choisissez au moins une photo']);
    $nb = count(galerie_liste($db, $idprod));
    if ($nb + count($liste) > GALERIE_MAX) throw new ApiError(422, 'Données invalides', ['photos' => 'La galerie est limitée à ' . GALERIE_MAX . ' photos (' . max(0, GALERIE_MAX - $nb) . ' place(s) restante(s))']);

    $st = $db->prepare('SELECT COALESCE(MAX(ordre), 0) FROM produit_photos WHERE idprod = ?'); $st->execute([$idprod]);
    $ordre = (int)$st->fetchColumn();
    $ins = $db->prepare('INSERT INTO produit_photos (idprod, fichier, ordre, idenr, dateenr) VALUES (?,?,?,?,?)');
    $noms = [];
    foreach ($liste as $f) {
        $nom = store_upload($f, 'produits', GALERIE_EXT);
        $ins->execute([$idprod, $nom, ++$ordre, $user['id'], gmdate('Y-m-d H:i:s')]);
        $noms[] = $nom;
    }
    if ($p['image'] === '') $db->prepare('UPDATE produits SET image = ? WHERE idprod = ?')->execute([$noms[0], $idprod]);
    journaliser($db, $user, 'photo', 'produits', $idprod, count($noms) . ' photo(s) ajoutée(s) à « ' . $p['nom'] . ' »', ['fichiers' => implode(', ', $noms)]);
    return galerie_reponse($db, $idprod);
}

/** Actions sur une photo : principale · legende · deplacer (sens -1 / +1) · remplacer (photo retouchée) · supprimer. */
function galerie_action(PDO $db, array $user, array $in, array $files)
{
    exiger($user, 'catalogue.ecrire', 'Seul le magasinier ou le gérant peut modifier les photos.');
    $st = $db->prepare('SELECT * FROM produit_photos WHERE idphoto = ?'); $st->execute([(int)($in['idphoto'] ?? 0)]);
    $ph = $st->fetch(PDO::FETCH_ASSOC);
    if (!$ph) throw new ApiError(404, 'Photo introuvable');
    $p = galerie_produit($db, $ph['idprod']);
    $id = (int)$ph['idphoto']; $idprod = (int)$ph['idprod'];
    $action = (string)($in['action'] ?? '');

    switch ($action) {
        case 'principale':
            $st = $db->prepare('SELECT COALESCE(MIN(ordre), 1) - 1 FROM produit_photos WHERE idprod = ?'); $st->execute([$idprod]);
            $db->prepare('UPDATE produit_photos SET ordre = ? WHERE idphoto = ?')->execute([(int)$st->fetchColumn(), $id]);
            $db->prepare('UPDATE produits SET image = ? WHERE idprod = ?')->execute([$ph['fichier'], $idprod]);
            journaliser($db, $user, 'photo', 'produits', $idprod, "Photo principale de « {$p['nom']} » changée", ['image' => [$p['image'], $ph['fichier']]]);
            break;
        case 'legende':
            $legende = mb_substr(trim((string)($in['legende'] ?? '')), 0, 150);
            $db->prepare('UPDATE produit_photos SET legende = ? WHERE idphoto = ?')->execute([$legende, $id]);
            break;
        case 'deplacer':
            $photos = galerie_liste($db, $idprod);
            $pos = array_search($id, array_column($photos, 'idphoto'), true);
            $cible = $pos + ((int)($in['sens'] ?? 1) < 0 ? -1 : 1);
            if ($pos === false || !isset($photos[$cible])) break;
            [$photos[$pos], $photos[$cible]] = [$photos[$cible], $photos[$pos]];
            $maj = $db->prepare('UPDATE produit_photos SET ordre = ? WHERE idphoto = ?');
            foreach ($photos as $i => $x) $maj->execute([$i + 1, $x['idphoto']]);
            break;
        case 'remplacer':
            if (empty($files['photo'])) throw new ApiError(422, 'Données invalides', ['photo' => 'Photo retouchée manquante']);
            $nom = store_upload($files['photo'], 'produits', GALERIE_EXT);
            $db->prepare('UPDATE produit_photos SET fichier = ? WHERE idphoto = ?')->execute([$nom, $id]);
            if ($p['image'] === $ph['fichier']) $db->prepare('UPDATE produits SET image = ? WHERE idprod = ?')->execute([$nom, $idprod]);
            galerie_effacer_fichier($db, $ph['fichier']);
            journaliser($db, $user, 'photo', 'produits', $idprod, "Photo de « {$p['nom']} » retouchée", ['fichier' => [$ph['fichier'], $nom]]);
            break;
        case 'supprimer':
            $db->prepare('DELETE FROM produit_photos WHERE idphoto = ?')->execute([$id]);
            if ($p['image'] === $ph['fichier']) {   // la suivante devient principale
                $suivante = galerie_liste($db, $idprod);
                $db->prepare('UPDATE produits SET image = ? WHERE idprod = ?')->execute([$suivante ? $suivante[0]['fichier'] : '', $idprod]);
            }
            galerie_effacer_fichier($db, $ph['fichier']);
            journaliser($db, $user, 'photo', 'produits', $idprod, "Photo retirée de « {$p['nom']} »", ['fichier' => $ph['fichier']]);
            break;
        default:
            throw new ApiError(422, 'Action inconnue');
    }
    return galerie_reponse($db, $idprod);
}
