<?php
/** Sessions de caisse : ouverture avec fond de caisse, mouvements d'espèces, clôture avec comptage et écart. */

function session_courante(PDO $db, $userId)
{
    $st = $db->prepare("SELECT s.*, c.nom AS caisse FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse WHERE s.iduser = :u AND s.statut = 'ouverte' ORDER BY s.idsession DESC LIMIT 1");
    $st->execute(['u' => $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
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
    if ($autre = $st->fetchColumn()) throw new ApiError(409, 'Cette caisse est déjà ouverte par ' . trim($autre) . '.');
    $db->prepare('INSERT INTO sessions_caisse (idcaisse, iduser, ouverture_at, fond_initial, statut) VALUES (?,?,?,?,?)')->execute([$idcaisse, $user['id'], gmdate('Y-m-d H:i:s'), $fond, 'ouverte']);
    return session_courante($db, $user['id']);
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
    $db->prepare('INSERT INTO caisse_operations (idsession, type, montant, motif, iduser, created_at) VALUES (?,?,?,?,?,?)')->execute([$s['idsession'], $type, $montant, mb_substr($motif, 0, 200), $user['id'], gmdate('Y-m-d H:i:s')]);
    return ['idop' => (int)$db->lastInsertId(), 'type' => $type, 'montant' => $montant];
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
    $liste = $db->prepare('SELECT * FROM caisse_operations WHERE idsession = :i ORDER BY idop'); $liste->execute(['i' => $idsession]);

    $attendu = round($s['fond_initial'] + $especesVentes + $reglements + $ops['entree'] - $ops['sortie'], 2);
    return [
        'session' => $s,
        'ventes' => ['nb' => (int)$ventes['nb'], 'ca' => $ventes['ca'] + 0, 'tva' => $ventes['tva'] + 0, 'remises' => $ventes['remises'] + 0, 'credit' => $ventes['credit'] + 0, 'marge' => round($ventes['ca'] - $ventes['tva'] - $ventes['cout'], 2)],
        'annulees' => ['nb' => (int)$annulees['nb'], 'montant' => $annulees['montant'] + 0],
        'modes' => $modes, 'operations' => $liste->fetchAll(PDO::FETCH_ASSOC), 'entrees_caisse' => $ops['entree'], 'sorties_caisse' => $ops['sortie'],
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
    $st = $db->prepare("SELECT s.*, c.nom AS caisse, CONCAT(u.nomag, ' ', u.prenom) AS caissier, (SELECT COUNT(*) FROM ventes v WHERE v.idsession = s.idsession AND v.statut = 'validee') AS nb_ventes, (SELECT COALESCE(SUM(v.total),0) FROM ventes v WHERE v.idsession = s.idsession AND v.statut = 'validee') AS ca FROM sessions_caisse s JOIN caisses c ON c.idcaisse = s.idcaisse JOIN users u ON u.id_user = s.iduser WHERE $w ORDER BY s.idsession DESC LIMIT " . (int)$per . ' OFFSET ' . (int)(($page - 1) * $per));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['caissier'] = trim($r['caissier']);
    return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => max(1, (int)ceil($total / $per))]];
}
