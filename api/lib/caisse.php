<?php
/** Sessions de caisse : ouverture avec fond de caisse, mouvements d'espèces, clôture avec comptage et écart. */

function session_courante(PDO $db, $userId)
{
    $st = $db->prepare("SELECT s.*, c.nom AS caisse FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse WHERE s.iduser = :u AND s.statut = 'ouverte' ORDER BY s.idsession DESC LIMIT 1");
    $st->execute(['u' => $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Caisses actuellement ouvertes (écran d'ouverture) : qui les occupe et depuis quand. */
function caisses_occupation(PDO $db, array $user)
{
    exiger($user, 'caisse.vendre');
    $st = $db->query("SELECT s.idsession, s.idcaisse, s.iduser, s.ouverture_at, CONCAT(u.nomag, ' ', u.prenom) AS caissier, g.coden AS role FROM sessions_caisse s JOIN users u ON u.id_user = s.iduser LEFT JOIN table_gpe_users g ON g.idgpe = u.gpe WHERE s.statut = 'ouverte' ORDER BY s.idsession");
    return array_map(function ($r) use ($user) {
        return ['idsession' => (int)$r['idsession'], 'idcaisse' => (int)$r['idcaisse'], 'caissier' => trim($r['caissier']), 'ouverture_at' => $r['ouverture_at'], 'soi' => (int)$r['iduser'] === (int)$user['id'], 'super' => $r['role'] === 'superadmin'];
    }, $st->fetchAll(PDO::FETCH_ASSOC));
}

function session_ouvrir(PDO $db, array $user, array $in)
{
    exiger($user, 'caisse.vendre');
    if (session_courante($db, $user['id'])) throw new ApiError(409, 'Vous avez déjà une caisse ouverte.');
    $fond = round(nombre($in['fond_initial'] ?? 0, 'fond_initial', 0), 2);
    $idcaisse = (int)($in['idcaisse'] ?? 0);
    if (!$idcaisse) $idcaisse = (int)$db->query('SELECT idcaisse FROM caisses WHERE actif = 1 AND supp = 0 ORDER BY idcaisse LIMIT 1')->fetchColumn();
    $st = $db->prepare('SELECT * FROM caisses WHERE idcaisse = :i AND actif = 1 AND supp = 0'); $st->execute(['i' => $idcaisse]);
    $caisse = $st->fetch(PDO::FETCH_ASSOC);
    if (!$caisse) throw new ApiError(422, 'Données invalides', ['idcaisse' => 'Caisse inconnue ou désactivée']);
    $st = $db->prepare("SELECT CONCAT(u.nomag, ' ', u.prenom) FROM sessions_caisse s JOIN users u ON u.id_user = s.iduser WHERE s.idcaisse = :c AND s.statut = 'ouverte' LIMIT 1"); $st->execute(['c' => $idcaisse]);
    if ($autre = $st->fetchColumn()) throw new ApiError(409, 'Cette caisse est déjà ouverte par ' . trim($autre) . '.', null, 'caisse_occupee');
    $db->prepare('INSERT INTO sessions_caisse (idcaisse, iduser, ouverture_at, fond_initial, statut) VALUES (?,?,?,?,?)')->execute([$idcaisse, $user['id'], gmdate('Y-m-d H:i:s'), $fond, 'ouverte']);
    return session_courante($db, $user['id']);
}

/** État de la caisse (rapport « Z ») : ventes par mode de paiement, mouvements d'espèces, espèces attendues. */
function session_rapport(PDO $db, $idsession)
{
    $st = $db->prepare("SELECT s.*, c.nom AS caisse, CONCAT(u.nomag, ' ', u.prenom) AS caissier FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse JOIN users u ON u.id_user = s.iduser WHERE s.idsession = :i");
    $st->execute(['i' => $idsession]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) throw new ApiError(404, 'Session introuvable');
    $s['caissier'] = trim($s['caissier']);

    $v = $db->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(total),0) AS ca, COALESCE(SUM(tva),0) AS tva, COALESCE(SUM(remise),0) AS remises, COALESCE(SUM(reste),0) AS credit, COALESCE(SUM(rendu),0) AS rendu, COALESCE(SUM(cout_total),0) AS cout FROM ventes WHERE idsession = :i AND statut = 'validee'");
    $v->execute(['i' => $idsession]); $ventes = $v->fetch(PDO::FETCH_ASSOC);
    $a = $db->prepare("SELECT COUNT(*) AS nb, COALESCE(SUM(total),0) AS montant FROM ventes WHERE idsession = :i AND statut = 'annulee'");
    $a->execute(['i' => $idsession]); $annulees = $a->fetch(PDO::FETCH_ASSOC);

    $m = $db->prepare("SELECT p.mode, COALESCE(mp.libelle, p.mode) AS libelle, COALESCE(mp.type, 'autre') AS type, SUM(p.montant) AS montant, COUNT(*) AS nb FROM vente_paiements p JOIN ventes v ON v.idvente = p.idvente LEFT JOIN modes_paiement mp ON mp.code = p.mode WHERE v.idsession = :i AND v.statut = 'validee' GROUP BY p.mode, mp.libelle, mp.type, mp.ordre ORDER BY mp.ordre");
    $m->execute(['i' => $idsession]); $modes = $m->fetchAll(PDO::FETCH_ASSOC);
    $especesVentes = 0;
    foreach ($modes as &$x) { $x['montant'] = $x['montant'] + 0; if ($x['type'] === 'especes') { $x['montant'] -= $ventes['rendu']; $especesVentes += $x['montant']; } }   // le rendu de monnaie sort du tiroir
    unset($x);

    $o = $db->prepare('SELECT type, COALESCE(SUM(montant),0) AS total FROM caisse_operations WHERE idsession = :i GROUP BY type'); $o->execute(['i' => $idsession]);
    $ops = ['entree' => 0, 'sortie' => 0]; foreach ($o->fetchAll(PDO::FETCH_ASSOC) as $r) $ops[$r['type']] = $r['total'] + 0;
    $r = $db->prepare('SELECT COALESCE(SUM(montant),0) FROM reglements WHERE idsession = :i AND tiers_type = \'client\''); $r->execute(['i' => $idsession]);
    $reglements = $r->fetchColumn() + 0;
    $liste = $db->prepare("SELECT o.*, CONCAT(u.nomag, ' ', u.prenom) AS auteur FROM caisse_operations o JOIN users u ON u.id_user = o.iduser WHERE o.idsession = :i ORDER BY o.idop"); $liste->execute(['i' => $idsession]);
    $operations = array_map(function ($x) { $x['auteur'] = trim($x['auteur']); $x['categorie_libelle'] = mouvement_libelle($x['type'], $x['categorie']); $x['montant'] += 0; return $x; }, $liste->fetchAll(PDO::FETCH_ASSOC));

    // Ventilation de la TVA par taux (les lignes sont ramenées au total réellement facturé : remise globale incluse)
    $tv = $db->prepare("SELECT l.tva_taux AS taux, SUM(l.total * v.total / v.sous_total) AS ttc FROM vente_lignes l JOIN ventes v ON v.idvente = l.idvente WHERE v.idsession = :i AND v.statut = 'validee' AND v.sous_total > 0 GROUP BY l.tva_taux ORDER BY l.tva_taux");
    $tv->execute(['i' => $idsession]);
    $tvaTaux = array_map(function ($x) { $ttc = round($x['ttc'], 2); $tva = $x['taux'] > 0 ? round($ttc * $x['taux'] / (100 + $x['taux']), 2) : 0; return ['taux' => $x['taux'] + 0, 'ttc' => $ttc, 'tva' => $tva, 'ht' => round($ttc - $tva, 2)]; }, $tv->fetchAll(PDO::FETCH_ASSOC));
    $nu = $db->prepare("SELECT MIN(numero), MAX(numero) FROM ventes WHERE idsession = :i"); $nu->execute(['i' => $idsession]); $plage = $nu->fetch(PDO::FETCH_NUM);
    $reg = settings_all($db);

    $attendu = round($s['fond_initial'] + $especesVentes + $reglements + $ops['entree'] - $ops['sortie'], 2);
    return [
        'session' => $s,
        'ventes' => ['nb' => (int)$ventes['nb'], 'ca' => $ventes['ca'] + 0, 'tva' => $ventes['tva'] + 0, 'remises' => $ventes['remises'] + 0, 'credit' => $ventes['credit'] + 0, 'marge' => round($ventes['ca'] - $ventes['tva'] - $ventes['cout'], 2)],
        'annulees' => ['nb' => (int)$annulees['nb'], 'montant' => $annulees['montant'] + 0],
        'modes' => $modes, 'operations' => $operations, 'entrees_caisse' => $ops['entree'], 'sorties_caisse' => $ops['sortie'],
        'numero_z' => sprintf('Z-%06d', $idsession), 'entreprise' => settings_section($reg, 'entreprise'),
        'ventes_ht' => round($ventes['ca'] - $ventes['tva'], 2), 'panier_moyen' => $ventes['nb'] > 0 ? round($ventes['ca'] / $ventes['nb'], 2) : 0,
        'tickets' => ['premier' => $plage[0], 'dernier' => $plage[1]], 'tva_taux' => $tvaTaux, 'rendu' => $ventes['rendu'] + 0,
        'reglements_clients' => $reglements, 'especes_ventes' => round($especesVentes, 2), 'attendu_especes' => $attendu,
    ];
}

function session_cloturer(PDO $db, array $user, array $in)
{
    exiger($user, 'caisse.vendre');
    $id = (int)($in['idsession'] ?? 0);
    $s = $id ? null : session_courante($db, $user['id']);
    if ($id) { $st = $db->prepare("SELECT * FROM sessions_caisse WHERE idsession = :i AND statut = 'ouverte'"); $st->execute(['i' => $id]); $s = $st->fetch(PDO::FETCH_ASSOC); }
    if (!$s) throw new ApiError(404, 'Aucune caisse ouverte');
    if ((int)$s['iduser'] !== (int)$user['id'] && !role_peut($user['role'], 'ventes.lire_toutes')) throw new ApiError(403, "Seul le caissier ou le gérant peut clôturer cette caisse.");
    $compte = round(nombre($in['compte_especes'] ?? '', 'compte_especes', 0), 2);
    $notes = trim((string)($in['notes'] ?? ''));

    return stock_transaction($db, function () use ($db, $s, $compte, $notes) {
        $rap = session_rapport($db, $s['idsession']);
        $ecart = round($compte - $rap['attendu_especes'], 2);
        $tolere = (float)(settings_all($db)['caisse.ecart_tolere']);
        if (abs($ecart) > $tolere && $notes === '') throw new ApiError(422, 'Données invalides', ['notes' => 'Écart de ' . number_format($ecart, 0, ',', ' ') . " : expliquez-le dans la note avant de clôturer"]);
        $db->prepare("UPDATE sessions_caisse SET statut = 'cloturee', cloture_at = ?, compte_especes = ?, attendu_especes = ?, ecart = ?, notes = ? WHERE idsession = ?")
            ->execute([gmdate('Y-m-d H:i:s'), $compte, $rap['attendu_especes'], $ecart, mb_substr($notes, 0, 500), $s['idsession']]);
        return session_rapport($db, $s['idsession']);
    });
}

/**
 * Clôture forcée d'une caisse restée ouverte (caissier absent, poste abandonné) : gérant ou super administrateur ;
 * la session d'un super administrateur ne peut être forcée que par un super administrateur.
 * Sans comptage : les espèces attendues sont figées, l'écart reste inconnu (à constater au prochain comptage). Motif obligatoire, tracé.
 */
function session_forcer_cloture(PDO $db, array $user, array $in)
{
    if (!is_admin_role($user['role'])) throw new ApiError(403, 'Seul le gérant ou le super administrateur peut forcer la clôture d\'une caisse.');
    $id = (int)($in['idsession'] ?? 0);
    $motif = trim((string)($in['motif'] ?? ''));
    if (mb_strlen($motif) < 3) throw new ApiError(422, 'Données invalides', ['motif' => 'Indiquez le motif de la clôture forcée']);
    return stock_transaction($db, function () use ($db, $user, $id, $motif) {
        $st = $db->prepare("SELECT s.*, c.nom AS caisse, CONCAT(u.nomag, ' ', u.prenom) AS caissier, g.coden AS caissier_role FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse JOIN users u ON u.id_user = s.iduser LEFT JOIN table_gpe_users g ON g.idgpe = u.gpe WHERE s.idsession = ? FOR UPDATE");
        $st->execute([$id]); $s = $st->fetch(PDO::FETCH_ASSOC);
        if (!$s) throw new ApiError(404, 'Session introuvable');
        if ($s['statut'] !== 'ouverte') throw new ApiError(409, 'Cette caisse est déjà clôturée.');
        if ($s['caissier_role'] === 'superadmin' && !is_super_role($user['role'])) throw new ApiError(403, 'Cette caisse est tenue par le super administrateur : lui seul peut la libérer.');
        $rap = session_rapport($db, $id);
        $motif = mb_substr($motif, 0, 200);
        $db->prepare("UPDATE sessions_caisse SET statut = 'cloturee', cloture_at = ?, compte_especes = NULL, attendu_especes = ?, ecart = NULL, notes = ?, forcee_par = ?, forcee_motif = ? WHERE idsession = ?")
            ->execute([gmdate('Y-m-d H:i:s'), $rap['attendu_especes'], mb_substr('Clôture forcée par ' . $user['nom'] . ' : ' . $motif, 0, 500), $user['id'], $motif, $id]);
        try {
            $db->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$user['id'], 'cloture_forcee', 'sessions_caisse', $id, json_encode(['caisse' => $s['caisse'], 'caissier' => trim($s['caissier']), 'attendu_especes' => $rap['attendu_especes'], 'motif' => $motif], JSON_UNESCAPED_UNICODE), client_ip(), gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) { error_log('audit_log indisponible : ' . $e->getMessage()); }
        return session_rapport($db, $id);
    });
}

function sessions_liste(PDO $db, array $user, array $q)
{
    $where = ['1=1']; $p = [];
    if (!role_peut($user['role'], 'ventes.lire_toutes')) { $where[] = 's.iduser = :u'; $p['u'] = $user['id']; }
    if (!empty($q['statut'])) { $where[] = 's.statut = :st'; $p['st'] = $q['statut'] === 'ouverte' ? 'ouverte' : 'cloturee'; }
    if (!empty($q['from'])) { $where[] = 's.ouverture_at >= :f'; $p['f'] = $q['from'] . ' 00:00:00'; }
    if (!empty($q['to'])) { $where[] = 's.ouverture_at <= :t'; $p['t'] = $q['to'] . ' 23:59:59'; }
    $w = implode(' AND ', $where);
    $page = max(1, (int)($q['page'] ?? 1)); $per = min(100, max(1, (int)($q['per_page'] ?? 25)));
    $st = $db->prepare("SELECT COUNT(*) FROM sessions_caisse s WHERE $w"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT s.*, c.nom AS caisse, CONCAT(u.nomag, ' ', u.prenom) AS caissier, CONCAT(fu.nomag, ' ', fu.prenom) AS forcee_par_nom, (SELECT coden FROM table_gpe_users WHERE idgpe = u.gpe) AS caissier_role, (SELECT COUNT(*) FROM ventes v WHERE v.idsession = s.idsession AND v.statut = 'validee') AS nb_ventes, (SELECT COALESCE(SUM(v.total),0) FROM ventes v WHERE v.idsession = s.idsession AND v.statut = 'validee') AS ca FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse JOIN users u ON u.id_user = s.iduser LEFT JOIN users fu ON fu.id_user = s.forcee_par WHERE $w ORDER BY s.idsession DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['caissier'] = trim($r['caissier']); $r['forcee_par_nom'] = trim((string)$r['forcee_par_nom']); }
    return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}
