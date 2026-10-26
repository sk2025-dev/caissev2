<?php
/**
 * Journal d'activité (traçabilité) : qui a fait quoi, quand, depuis quelle adresse, avec les valeurs avant / après.
 * Le journal ne se modifie ni ne se supprime par l'API : il n'est qu'écrit, puis consulté par le gérant.
 */

/** Module de rattachement de chaque entité (filtre de l'écran Journal). */
const JOURNAL_MODULES = [
    'utilisateurs' => 'securite', 'configuration' => 'configuration',
    'produits' => 'catalogue', 'categories' => 'catalogue',
    'stock' => 'stock', 'inventaires' => 'stock',
    'approvisionnements' => 'achats', 'fournisseurs' => 'achats', 'reglements' => 'achats',
    'clients' => 'clients',
    'ventes' => 'ventes', 'commandes' => 'commandes',
    'sessions_caisse' => 'caisse', 'caisse_operations' => 'caisse', 'categories_mouvement' => 'caisse', 'caisses' => 'caisse',
    'zones-livraison' => 'commandes', 'exports' => 'exports', 'avis' => 'clients',
];

/** Nom des entités du CRUD dans les résumés (« Produit « Riz 5 kg » modifié »). */
const JOURNAL_ENTITES = [
    'produits' => 'Produit', 'categories' => 'Catégorie', 'clients' => 'Client', 'fournisseurs' => 'Fournisseur',
    'caisses' => 'Caisse', 'zones-livraison' => 'Tarif de livraison', 'utilisateurs' => 'Utilisateur', 'avis' => 'Avis',
];

/** Champs jamais recopiés en clair dans le journal. */
const JOURNAL_SECRETS = ['pass', 'password', 'mail.smtp_pass', 'smtp_pass'];

function journal_masquer(array $d)
{
    foreach ($d as $k => $v) {
        if (in_array($k, JOURNAL_SECRETS, true)) $d[$k] = is_array($v) ? ['••••', '••••'] : '••••';
        elseif (is_array($v) && !array_key_exists(0, $v)) $d[$k] = journal_masquer($v);
    }
    return $d;
}

/**
 * Inscrit une action. $user : utilisateur connecté (ou null : tentative de connexion, ligne de commande) ;
 * $acteur : nom affiché quand il n'y a pas d'utilisateur (e-mail saisi, « Ligne de commande »).
 * Ne lève jamais d'exception : l'action métier a déjà réussi, le journal ne doit pas la faire échouer.
 */
function journaliser(PDO $db, $user, $action, $entite, $entiteId = null, $resume = '', $detail = null, $acteur = '')
{
    try {
        $ip = PHP_SAPI === 'cli' ? 'cli' : client_ip();
        $nom = $user ? (trim((string)($user['nom'] ?? '')) ?: (string)($user['email'] ?? '')) : (string)$acteur;
        $db->prepare('INSERT INTO audit_log (user_id, acteur, action, module, entity, entity_id, resume, detail, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute([
            $user ? (int)$user['id'] : null, mb_substr($nom, 0, 150), $action, JOURNAL_MODULES[$entite] ?? 'autre', $entite, $entiteId !== null ? (int)$entiteId : null,
            mb_substr((string)$resume, 0, 255), $detail ? json_encode(journal_masquer($detail), JSON_UNESCAPED_UNICODE) : null, $ip, gmdate('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        error_log('Journal indisponible : ' . $e->getMessage());   // migration 010 non appliquée, base injoignable…
    }
}

/** Champs réellement modifiés : [champ => [avant, après]] ; 1500 et « 1500.00 » sont considérés égaux. */
function journal_diff(array $avant, array $apres, array $ignorer = [])
{
    $out = [];
    foreach ($apres as $k => $v) {
        if (in_array($k, $ignorer, true)) continue;
        $a = array_key_exists($k, $avant) ? $avant[$k] : null;
        if (in_array($k, JOURNAL_SECRETS, true)) { $out[$k] = ['••••', '••••']; continue; }   // hachés différents à chaque fois : toujours « modifié »
        $egal = is_numeric($a) && is_numeric($v) ? abs((float)$a - (float)$v) < 0.000001 : (string)$a === (string)$v;
        if (!$egal) $out[$k] = [$a, $v];
    }
    return $out;
}

/** Libellé lisible d'une fiche : nom, prénom + nom, numéro… */
function journal_libelle(array $row)
{
    if (!empty($row['nomag'])) return trim($row['nomag'] . ' ' . ($row['prenom'] ?? ''));
    foreach (['nom', 'numero', 'libelle', 'auteur', 'emailag'] as $k) if (!empty($row[$k])) return (string)$row[$k];
    return '';
}

function journal_montant($v)
{
    return number_format((float)$v, 0, ',', ' ');
}

function journal_qte($v)
{
    return rtrim(rtrim(number_format((float)$v, 3, ',', ' '), '0'), ',');
}

/* ---------- Consultation ---------- */

function journal_filtre(array $user, array $q)
{
    $where = ['1=1']; $p = [];
    // Même règle que la liste des utilisateurs : le gérant ne voit pas les comptes super administrateur
    if (!is_super_role($user['role'])) $where[] = "(j.user_id IS NULL OR g.coden IS NULL OR g.coden <> 'superadmin')";
    if (!empty($q['from'])) { $where[] = 'j.created_at >= :f'; $p['f'] = $q['from'] . ' 00:00:00'; }
    if (!empty($q['to'])) { $where[] = 'j.created_at <= :t'; $p['t'] = $q['to'] . ' 23:59:59'; }
    if (!empty($q['iduser'])) { $where[] = 'j.user_id = :u'; $p['u'] = (int)$q['iduser']; }
    if (!empty($q['module'])) { $where[] = 'j.module = :m'; $p['m'] = (string)$q['module']; }
    if (!empty($q['action'])) { $where[] = 'j.action = :a'; $p['a'] = (string)$q['action']; }
    if (!empty($q['entite'])) {   // une ou plusieurs entités : « produits,stock » réunit la fiche produit et ses mouvements de stock
        $in = [];
        foreach (array_slice(explode(',', (string)$q['entite']), 0, 5) as $i => $e) { $in[] = ":e$i"; $p["e$i"] = trim($e); }
        $where[] = 'j.entity IN (' . implode(', ', $in) . ')';
    }
    if (!empty($q['entite_id'])) { $where[] = 'j.entity_id = :ei'; $p['ei'] = (int)$q['entite_id']; }
    if (!empty($q['q'])) {
        $where[] = '(j.resume LIKE :q OR j.acteur LIKE :q2 OR j.ip LIKE :q3 OR j.detail LIKE :q4)';
        $like = '%' . trim($q['q']) . '%'; $p['q'] = $like; $p['q2'] = $like; $p['q3'] = $like; $p['q4'] = $like;
    }
    return [implode(' AND ', $where), $p];
}

function journal_liste(PDO $db, array $user, array $q)
{
    exiger($user, 'journal.lire', "Le journal d'activité est réservé au gérant.");
    list($w, $p) = journal_filtre($user, $q);
    $from = "FROM audit_log j LEFT JOIN users u ON u.id_user = j.user_id LEFT JOIN table_gpe_users g ON g.idgpe = u.gpe WHERE $w";
    $st = $db->prepare("SELECT COUNT(*) $from"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 50)));
    $st = $db->prepare("SELECT j.id, j.created_at, j.user_id, COALESCE(NULLIF(TRIM(CONCAT(u.nomag, ' ', u.prenom)), ''), NULLIF(j.acteur, ''), 'Système') AS acteur, g.coden AS role,
        j.action, j.module, j.entity, j.entity_id, j.resume, j.detail, j.ip
        $from ORDER BY j.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($p);
    $rows = array_map(function ($r) { $r['detail'] = $r['detail'] !== null ? json_decode($r['detail'], true) : null; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}
