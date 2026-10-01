<?php
/**
 * Mouvements d'espèces du tiroir-caisse (entrées / sorties) : pièces numérotées, classées par catégorie,
 * avec référence du justificatif. Sert au rapport de caisse, à l'état des mouvements et à l'export comptable.
 */
const MOUVEMENTS_CATEGORIES_DEFAUT = [
    'entree' => ['apport_fonds' => 'Apport de fonds / monnaie', 'retrait_banque' => 'Retrait bancaire', 'remboursement' => 'Remboursement reçu', 'autre_entree' => 'Autre entrée'],
    'sortie' => ['achat_marchandises' => 'Achat de marchandises', 'transport' => 'Transport et livraison', 'fournitures' => 'Fournitures et petites dépenses', 'charges' => 'Charges (eau, électricité, loyer…)', 'salaires' => 'Salaires et avances', 'depot_banque' => 'Versement en banque', 'prelevement' => 'Prélèvement du gérant', 'autre_sortie' => 'Autre sortie'],
];

/** Catégories par sens (table categories_mouvement, complétée par le gérant), lues une fois par requête. */
function mouvements_categories($recharger = false)
{
    static $cache = null;
    if ($cache !== null && !$recharger) return $cache;
    try {
        $cache = ['entree' => [], 'sortie' => []];
        foreach ($GLOBALS['bdd']->query('SELECT type, code, libelle FROM categories_mouvement WHERE actif = 1 ORDER BY ordre, libelle')->fetchAll(PDO::FETCH_ASSOC) as $r) $cache[$r['type']][$r['code']] = $r['libelle'];
    } catch (PDOException $e) {
        $cache = MOUVEMENTS_CATEGORIES_DEFAUT;   // migration 007 non appliquée
    }
    return $cache;
}

/** Ajoute une catégorie (gérant) ; un libellé déjà existant (casse et accents ignorés) renvoie la catégorie existante. */
function categorie_mouvement_creer(PDO $db, array $user, array $in)
{
    exiger($user, 'depenses.gerer', 'Seul le gérant peut ajouter une catégorie.');
    $type = (isset($in['type']) && $in['type'] === 'entree') ? 'entree' : 'sortie';
    $libelle = trim(preg_replace('/\s+/u', ' ', (string)($in['libelle'] ?? '')));
    if (mb_strlen($libelle) < 2) throw new ApiError(422, 'Données invalides', ['libelle' => 'Nom de catégorie trop court']);
    $libelle = mb_substr($libelle, 0, 80);
    // Accents retirés par table (iconv //TRANSLIT varie selon le système : « é » donne « 'e » sous macOS)
    $slug = function ($t) {
        $t = strtr(mb_strtolower($t), ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae']);
        return trim(preg_replace('/[^a-z0-9]+/', '_', $t), '_');
    };
    foreach (mouvements_categories(true)[$type] as $code => $lib) if ($slug($lib) === $slug($libelle)) return ['type' => $type, 'code' => $code, 'libelle' => $lib, 'existait' => true];
    $base = substr($slug($libelle) ?: 'categorie', 0, 24); $code = $base; $n = 1;
    $existe = $db->prepare('SELECT COUNT(*) FROM categories_mouvement WHERE type = ? AND code = ?');
    while (true) { $existe->execute([$type, $code]); if (!$existe->fetchColumn()) break; $code = $base . '_' . (++$n); }
    $ordre = (int)$db->query("SELECT COALESCE(MAX(ordre), 0) FROM categories_mouvement WHERE type = " . $db->quote($type))->fetchColumn() + 10;
    $db->prepare('INSERT INTO categories_mouvement (type, code, libelle, ordre, idenr, dateenr) VALUES (?,?,?,?,?,?)')->execute([$type, $code, $libelle, $ordre, $user['id'], gmdate('Y-m-d H:i:s')]);
    mouvements_categories(true);
    return ['type' => $type, 'code' => $code, 'libelle' => $libelle, 'existait' => false];
}

/* ---- Pièces justificatives : dossier privé (jamais servi directement), lecture par l'API après contrôle des droits ---- */
const JUSTIFICATIF_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

function justificatifs_dir()
{
    $d = dirname(__DIR__, 2) . '/justificatifs';
    if (!is_dir($d)) mkdir($d, 0755, true);
    if (!is_file("$d/.htaccess")) file_put_contents("$d/.htaccess", "Require all denied\n");
    return $d;
}

/** Contrôle (5 Mo, PDF ou image réellement lisible) et enregistre sous un nom aléatoire ; renvoie ce nom. */
function justificatif_enregistrer(array $f)
{
    if ($f['error'] === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK) throw new ApiError(422, 'Données invalides', ['justificatif' => 'Téléversement impossible']);
    if ($f['size'] > 5 * 1024 * 1024) throw new ApiError(422, 'Données invalides', ['justificatif' => 'Fichier trop lourd (5 Mo maximum)']);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset(JUSTIFICATIF_EXT[$ext])) throw new ApiError(422, 'Données invalides', ['justificatif' => 'Formats acceptés : PDF, JPG, PNG, WEBP']);
    $debut = (string)file_get_contents($f['tmp_name'], false, null, 0, 5);
    $ok = $ext === 'pdf' ? $debut === '%PDF-' : @getimagesize($f['tmp_name']) !== false;
    if (!$ok) throw new ApiError(422, 'Données invalides', ['justificatif' => 'Le fichier est illisible ou ne correspond pas à son extension']);
    $nom = bin2hex(random_bytes(16)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], justificatifs_dir() . '/' . $nom)) throw new ApiError(500, 'Enregistrement du justificatif impossible');
    return $nom;
}

/** Envoie le justificatif d'une pièce : au gérant, ou à l'auteur de la pièce. */
function justificatif_envoyer(PDO $db, array $user, $idop)
{
    $st = $db->prepare('SELECT numero, iduser, justificatif FROM caisse_operations WHERE idop = ?'); $st->execute([(int)$idop]);
    $op = $st->fetch(PDO::FETCH_ASSOC);
    if (!$op || $op['justificatif'] === '') throw new ApiError(404, 'Aucun justificatif pour cette pièce');
    if ((int)$op['iduser'] !== (int)$user['id'] && !role_peut($user['role'], 'ventes.lire_toutes')) throw new ApiError(403, "Votre rôle ne permet pas cette action.");
    $chemin = justificatifs_dir() . '/' . basename($op['justificatif']);
    if (!is_file($chemin)) throw new ApiError(404, 'Fichier du justificatif introuvable');
    $ext = strtolower(pathinfo($chemin, PATHINFO_EXTENSION));
    header('Content-Type: ' . (JUSTIFICATIF_EXT[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($chemin));
    header('Content-Disposition: inline; filename="justificatif-' . preg_replace('/[^A-Za-z0-9-]/', '', $op['numero']) . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    header('Cache-Control: private, no-store');
    readfile($chemin);
    exit;
}

/** Origine des fonds : le tiroir d'une caisse (session ouverte) ou, pour le gérant, une source hors caisse. */
function mouvements_sources()
{
    return ['coffre' => 'Coffre / espèces hors caisse', 'banque' => 'Banque', 'mobile_money' => 'Mobile money'];
}

function mouvement_libelle($type, $categorie)
{
    $c = mouvements_categories();
    return isset($c[$type][$categorie]) ? $c[$type][$categorie] : 'Non classé';
}

function operation_caisse(PDO $db, array $user, array $in)
{
    exiger($user, 'caisse.vendre');
    $s = session_courante($db, $user['id']);
    if (!$s) throw new ApiError(409, "Ouvrez la caisse avant d'enregistrer un mouvement d'espèces.");
    $type = (isset($in['type']) && $in['type'] === 'sortie') ? 'sortie' : 'entree';
    $montant = round(nombre($in['montant'] ?? '', 'montant', 0.01), 2);
    $motif = trim((string)($in['motif'] ?? ''));
    if ($motif === '') throw new ApiError(422, 'Données invalides', ['motif' => 'Le motif est obligatoire']);
    $categorie = (string)($in['categorie'] ?? '');
    if (!isset(mouvements_categories()[$type][$categorie])) throw new ApiError(422, 'Données invalides', ['categorie' => 'Choisissez une catégorie']);
    $reference = mb_substr(trim((string)($in['reference'] ?? '')), 0, 80);
    return stock_transaction($db, function () use ($db, $user, $s, $type, $montant, $motif, $categorie, $reference) {
        $numero = numero_suivant($db, 'mouvement', 'MC');
        $db->prepare('INSERT INTO caisse_operations (numero, idsession, type, categorie, montant, motif, reference, iduser, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$numero, $s['idsession'], $type, $categorie, $montant, mb_substr($motif, 0, 200), $reference, $user['id'], gmdate('Y-m-d H:i:s')]);
        return ['idop' => (int)$db->lastInsertId(), 'numero' => $numero, 'type' => $type, 'montant' => $montant];
    });
}

/**
 * Dépense saisie par le gérant hors session de caisse : réglée par le coffre, la banque ou le mobile money.
 * Elle n'entre pas dans le comptage du tiroir ; date de la pièce modifiable (saisie a posteriori d'une facture).
 */
function depense_creer(PDO $db, array $user, array $in, array $files = [])
{
    exiger($user, 'depenses.gerer', 'Seul le gérant peut saisir une dépense hors caisse.');
    $err = [];
    $montant = round(nombre($in['montant'] ?? '', 'montant', 0.01), 2);
    $motif = trim((string)($in['motif'] ?? ''));
    if ($motif === '') $err['motif'] = 'Le motif est obligatoire';
    $categorie = (string)($in['categorie'] ?? '');
    if (!isset(mouvements_categories()['sortie'][$categorie])) $err['categorie'] = 'Choisissez une catégorie';
    $source = (string)($in['source'] ?? '');
    if (!isset(mouvements_sources()[$source])) $err['source'] = 'Indiquez comment la dépense a été réglée';
    $jour = (string)($in['date'] ?? '');
    $maintenant = gmdate('Y-m-d H:i:s');
    if ($jour === '' || $jour === gmdate('Y-m-d')) $quand = $maintenant;
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour) || !strtotime($jour) || $jour > gmdate('Y-m-d')) $err['date'] = 'Date invalide (pas de date future)';
    else $quand = $jour . ' 12:00:00';
    if ($err) throw new ApiError(422, 'Données invalides', $err);
    $reference = mb_substr(trim((string)($in['reference'] ?? '')), 0, 80);
    $fichier = !empty($files['justificatif']) ? justificatif_enregistrer($files['justificatif']) : '';
    try {
        return stock_transaction($db, function () use ($db, $user, $montant, $motif, $categorie, $source, $reference, $quand, $fichier) {
            $numero = numero_suivant($db, 'mouvement', 'MC');
            $db->prepare("INSERT INTO caisse_operations (numero, idsession, source, type, categorie, montant, motif, reference, justificatif, iduser, created_at) VALUES (?,NULL,?,'sortie',?,?,?,?,?,?,?)")
                ->execute([$numero, $source, $categorie, $montant, mb_substr($motif, 0, 200), $reference, $fichier, $user['id'], $quand]);
            return ['idop' => (int)$db->lastInsertId(), 'numero' => $numero, 'type' => 'sortie', 'montant' => $montant, 'source' => $source, 'justificatif' => $fichier !== ''];
        });
    } catch (Throwable $e) {
        if ($fichier !== '') @unlink(justificatifs_dir() . '/' . $fichier);   // pas de fichier orphelin
        throw $e;
    }
}

/** Filtres communs : période (jours UTC comme le reste de l'application), type, catégorie, caisse, caissier, recherche. */
function mouvements_filtre(array $user, array $q)
{
    $where = ['1=1']; $p = [];
    if (!role_peut($user['role'], 'ventes.lire_toutes')) { $where[] = 'o.iduser = :u'; $p['u'] = $user['id']; }
    elseif (!empty($q['iduser'])) { $where[] = 'o.iduser = :u'; $p['u'] = (int)$q['iduser']; }
    if (!empty($q['type']) && in_array($q['type'], ['entree', 'sortie'], true)) { $where[] = 'o.type = :t'; $p['t'] = $q['type']; }
    if (!empty($q['categorie'])) { $where[] = 'o.categorie = :cat'; $p['cat'] = (string)$q['categorie']; }
    if (!empty($q['idcaisse'])) { if ($q['idcaisse'] === 'hors') $where[] = 'o.idsession IS NULL'; else { $where[] = 's.idcaisse = :ca'; $p['ca'] = (int)$q['idcaisse']; } }
    if (!empty($q['idsession'])) { $where[] = 'o.idsession = :se'; $p['se'] = (int)$q['idsession']; }
    if (!empty($q['from'])) { $where[] = 'o.created_at >= :f'; $p['f'] = $q['from'] . ' 00:00:00'; }
    if (!empty($q['to'])) { $where[] = 'o.created_at <= :to'; $p['to'] = $q['to'] . ' 23:59:59'; }
    if (!empty($q['q'])) { $where[] = '(o.motif LIKE :q OR o.numero LIKE :q2 OR o.reference LIKE :q3)'; $like = '%' . trim($q['q']) . '%'; $p += ['q' => $like, 'q2' => $like, 'q3' => $like]; }
    return [implode(' AND ', $where), $p];
}

const MVT_FROM = 'FROM caisse_operations o LEFT JOIN sessions_caisse s ON s.idsession = o.idsession LEFT JOIN caisses c ON c.idcaisse = s.idcaisse JOIN users u ON u.id_user = o.iduser';
// Caisse de la pièce, ou origine des fonds pour une dépense hors caisse
const MVT_CAISSE = "COALESCE(c.nom, CASE o.source WHEN 'banque' THEN 'Hors caisse — banque' WHEN 'mobile_money' THEN 'Hors caisse — mobile money' ELSE 'Hors caisse — coffre' END) AS caisse";

function mouvements_caisse_synthese(PDO $db, $w, array $p)
{
    $st = $db->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(CASE WHEN o.type = 'entree' THEN o.montant END), 0) AS entrees, COALESCE(SUM(CASE WHEN o.type = 'sortie' THEN o.montant END), 0) AS sorties " . MVT_FROM . " WHERE $w");
    $st->execute($p); $t = $st->fetch(PDO::FETCH_ASSOC);
    $totaux = ['nb' => (int)$t['nb'], 'entrees' => $t['entrees'] + 0, 'sorties' => $t['sorties'] + 0, 'net' => round($t['entrees'] - $t['sorties'], 2)];
    $st = $db->prepare('SELECT o.type, o.categorie, COUNT(*) AS nb, SUM(o.montant) AS total ' . MVT_FROM . " WHERE $w GROUP BY o.type, o.categorie ORDER BY o.type, total DESC");
    $st->execute($p);
    $cat = array_map(function ($r) { return ['type' => $r['type'], 'categorie' => $r['categorie'], 'libelle' => mouvement_libelle($r['type'], $r['categorie']), 'nb' => (int)$r['nb'], 'total' => $r['total'] + 0]; }, $st->fetchAll(PDO::FETCH_ASSOC));
    $st = $db->prepare("SELECT SUBSTR(o.created_at, 1, 10) AS jour, SUM(CASE WHEN o.type = 'entree' THEN o.montant ELSE 0 END) AS entrees, SUM(CASE WHEN o.type = 'sortie' THEN o.montant ELSE 0 END) AS sorties, COUNT(*) AS nb " . MVT_FROM . " WHERE $w GROUP BY jour ORDER BY jour");
    $st->execute($p);
    $jours = array_map(function ($r) { return ['jour' => $r['jour'], 'nb' => (int)$r['nb'], 'entrees' => $r['entrees'] + 0, 'sorties' => $r['sorties'] + 0, 'net' => round($r['entrees'] - $r['sorties'], 2)]; }, $st->fetchAll(PDO::FETCH_ASSOC));
    return [$totaux, $cat, $jours];
}

function mouvements_caisse_liste(PDO $db, array $user, array $q)
{
    list($w, $p) = mouvements_filtre($user, $q);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(1000, max(1, (int)($q['per_page'] ?? 50)));
    list($totaux, $cat, $jours) = mouvements_caisse_synthese($db, $w, $p);
    $st = $db->prepare("SELECT o.idop, o.numero, o.type, o.categorie, o.montant, o.motif, o.reference, (o.justificatif <> '') AS a_justificatif, o.created_at, o.idsession, " . MVT_CAISSE . ", CONCAT(u.nomag, ' ', u.prenom) AS auteur " . MVT_FROM . " WHERE $w ORDER BY o.created_at DESC, o.idop DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $rows = array_map(function ($r) { $r['auteur'] = trim($r['auteur']); $r['categorie_libelle'] = mouvement_libelle($r['type'], $r['categorie']); $r['montant'] += 0; $r['a_justificatif'] = (bool)$r['a_justificatif']; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    $reg = settings_all($db);
    return ['data' => $rows, 'meta' => ['total' => $totaux['nb'], 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($totaux['nb'] / $per))],
        'totaux' => $totaux, 'par_categorie' => $cat, 'par_jour' => $jours, 'entreprise' => settings_section($reg, 'entreprise')];
}

/** Données d'export : toutes les pièces de la période, en ordre chronologique (journal comptable). */
function mouvements_export_data(PDO $db, $from, $to)
{
    $q = ['from' => $from, 'to' => $to];
    list($w, $p) = mouvements_filtre(['role' => 'admin', 'id' => 0], $q);
    $st = $db->prepare("SELECT o.numero, o.type, o.categorie, o.montant, o.motif, o.reference, o.created_at, o.idsession, " . MVT_CAISSE . ", CONCAT(u.nomag, ' ', u.prenom) AS auteur " . MVT_FROM . " WHERE $w ORDER BY o.created_at, o.idop");
    $st->execute($p);
    $rows = array_map(function ($r) { $r['auteur'] = trim($r['auteur']); $r['categorie_libelle'] = mouvement_libelle($r['type'], $r['categorie']); $r['montant'] += 0; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    list($totaux, $cat, $jours) = mouvements_caisse_synthese($db, $w, $p);
    return ['rows' => $rows, 'totaux' => $totaux, 'par_categorie' => $cat, 'par_jour' => $jours, 'from' => $from, 'to' => $to];
}
