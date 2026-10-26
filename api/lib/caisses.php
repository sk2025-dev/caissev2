<?php
/**
 * Paramétrage de chaque caisse : produits vendus (toutes les références, ou une sélection de catégories et de produits),
 * préfixe de numérotation propre et ticket personnalisé (nom, adresse, téléphone, en-tête, pied, largeur).
 * L'assortiment est imposé par le serveur : un produit hors assortiment est refusé à la vente sur cette caisse.
 */

function caisse_ligne(PDO $db, $id)
{
    $st = $db->prepare('SELECT * FROM caisses WHERE idcaisse = ? AND supp = 0'); $st->execute([(int)$id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new ApiError(404, 'Caisse introuvable');
    return $c;
}

/** Condition SQL (alias « p ») des produits vendus sur la caisse ; null = tous les produits. */
function caisse_condition(array $caisse)
{
    if (($caisse['assortiment'] ?? 'tous') !== 'selection') return null;
    $id = (int)$caisse['idcaisse'];
    return "(p.idprod IN (SELECT idprod FROM caisse_produits WHERE idcaisse = $id) OR p.idcat IN (SELECT idcat FROM caisse_categories WHERE idcaisse = $id))";
}

function caisse_vend(PDO $db, array $caisse, $idprod)
{
    $w = caisse_condition($caisse);
    if ($w === null) return true;
    $st = $db->prepare("SELECT COUNT(*) FROM produits p WHERE p.idprod = ? AND $w"); $st->execute([(int)$idprod]);
    return (int)$st->fetchColumn() > 0;
}

/** Préfixe des numéros de vente : celui de la caisse s'il est défini, sinon le préfixe commun. */
function caisse_prefixe(array $reg, $caisse)
{
    return $caisse && $caisse['prefixe'] !== '' ? $caisse['prefixe'] : $reg['caisse.prefixe'];
}

/* ---------- Écran de paramétrage (gérant) ---------- */

function caisse_config(PDO $db, $id)
{
    $c = caisse_ligne($db, $id);
    $ids = function ($sql) use ($db, $id) { $st = $db->prepare($sql); $st->execute([(int)$id]); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); };
    $reg = settings_all($db);
    $w = caisse_condition($c);
    return [
        'caisse' => ['idcaisse' => (int)$c['idcaisse'], 'nom' => $c['nom'], 'emplacement' => $c['emplacement'], 'actif' => (int)$c['actif'], 'prefixe' => $c['prefixe'],
            'ticket_perso' => (int)$c['ticket_perso'], 'ticket_nom' => $c['ticket_nom'], 'ticket_adresse' => $c['ticket_adresse'], 'ticket_telephone' => $c['ticket_telephone'],
            'ticket_entete' => (string)$c['ticket_entete'], 'ticket_pied' => (string)$c['ticket_pied'], 'ticket_largeur' => $c['ticket_largeur']],
        'assortiment' => ['mode' => $c['assortiment'], 'categories' => $ids('SELECT idcat FROM caisse_categories WHERE idcaisse = ?'), 'produits' => $ids('SELECT idprod FROM caisse_produits WHERE idcaisse = ?')],
        'nb_vendus' => (int)$db->query("SELECT COUNT(*) FROM produits p WHERE p.supp = 0 AND p.actif = 1 AND p.sku <> 'FRAIS-LIV'" . ($w ? " AND $w" : ''))->fetchColumn(),
        'produits' => $db->query("SELECT idprod AS id, nom, sku, idcat, image, prix_vente FROM produits WHERE supp = 0 AND actif = 1 AND sku <> 'FRAIS-LIV' ORDER BY nom")->fetchAll(PDO::FETCH_ASSOC),
        'categories' => $db->query('SELECT idcat AS id, nom, couleur FROM categories WHERE supp = 0 ORDER BY ordre, nom')->fetchAll(PDO::FETCH_ASSOC),
        'ticket_commun' => ticket_reglages($reg),
        'prefixe_commun' => $reg['caisse.prefixe'],
        'ventes' => (int)$db->query('SELECT COUNT(*) FROM ventes WHERE idcaisse = ' . (int)$id)->fetchColumn(),
    ];
}

function caisse_config_enregistrer(PDO $db, array $user, $id, array $in)
{
    if (!is_admin_role($user['role'])) throw new ApiError(403, 'Seul le gérant peut paramétrer les caisses.');
    $avant = caisse_config($db, $id);
    $reg = settings_all($db);
    $g = isset($in['caisse']) && is_array($in['caisse']) ? $in['caisse'] : [];
    $a = isset($in['assortiment']) && is_array($in['assortiment']) ? $in['assortiment'] : [];
    $err = [];

    $nom = trim((string)($g['nom'] ?? ''));
    if ($nom === '') $err['nom'] = 'Le nom de la caisse est obligatoire';
    $prefixe = strtoupper(trim((string)($g['prefixe'] ?? '')));
    if ($prefixe !== '') {
        if (!preg_match('/^[A-Z0-9]{1,6}$/', $prefixe)) $err['prefixe'] = '1 à 6 lettres ou chiffres, sans espace';
        elseif ($prefixe === strtoupper($reg['caisse.prefixe'])) $err['prefixe'] = 'Identique au préfixe commun : laissez vide pour la numérotation commune';
        else {
            $st = $db->prepare('SELECT nom FROM caisses WHERE UPPER(prefixe) = ? AND idcaisse <> ? AND supp = 0'); $st->execute([$prefixe, (int)$id]);
            if ($autre = $st->fetchColumn()) $err['prefixe'] = "Déjà utilisé par la caisse « $autre »";
        }
    }
    $largeur = (string)($g['ticket_largeur'] ?? '');
    if (!in_array($largeur, ['', '58', '80'], true)) $err['ticket_largeur'] = 'Largeur 58 ou 80 mm';
    $mode = ($a['mode'] ?? 'tous') === 'selection' ? 'selection' : 'tous';
    $cats = array_values(array_unique(array_map('intval', (array)($a['categories'] ?? []))));
    $prods = array_values(array_unique(array_map('intval', (array)($a['produits'] ?? []))));
    if ($mode === 'selection' && !$cats && !$prods) $err['assortiment'] = 'Choisissez au moins une catégorie ou un produit, ou vendez tous les produits';
    if (empty($g['actif']) && (int)$avant['caisse']['actif'] === 1) {
        $st = $db->prepare("SELECT COUNT(*) FROM sessions_caisse WHERE idcaisse = ? AND statut = 'ouverte'"); $st->execute([(int)$id]);
        if ((int)$st->fetchColumn() > 0) $err['actif'] = 'Cette caisse est ouverte : clôturez-la avant de la désactiver';
    }
    if ($err) throw new ApiError(422, 'Données invalides', $err);

    $t = function ($k, $max) use ($g) { return mb_substr(trim((string)($g[$k] ?? '')), 0, $max); };
    return stock_transaction($db, function () use ($db, $user, $id, $g, $nom, $prefixe, $largeur, $mode, $cats, $prods, $t, $avant) {
        $db->prepare('UPDATE caisses SET nom = ?, emplacement = ?, actif = ?, prefixe = ?, assortiment = ?, ticket_perso = ?, ticket_nom = ?, ticket_adresse = ?, ticket_telephone = ?, ticket_entete = ?, ticket_pied = ?, ticket_largeur = ? WHERE idcaisse = ?')
            ->execute([mb_substr($nom, 0, 80), $t('emplacement', 150), empty($g['actif']) ? 0 : 1, $prefixe, $mode, empty($g['ticket_perso']) ? 0 : 1,
                $t('ticket_nom', 150), $t('ticket_adresse', 200), $t('ticket_telephone', 60), $t('ticket_entete', 1000), $t('ticket_pied', 1000), $largeur, (int)$id]);
        $db->prepare('DELETE FROM caisse_categories WHERE idcaisse = ?')->execute([(int)$id]);
        $db->prepare('DELETE FROM caisse_produits WHERE idcaisse = ?')->execute([(int)$id]);
        if ($mode === 'selection') {
            $ic = $db->prepare('INSERT INTO caisse_categories (idcaisse, idcat) SELECT ?, idcat FROM categories WHERE idcat = ? AND supp = 0');
            foreach ($cats as $c) $ic->execute([(int)$id, $c]);
            $ip = $db->prepare('INSERT INTO caisse_produits (idcaisse, idprod) SELECT ?, idprod FROM produits WHERE idprod = ? AND supp = 0');
            foreach ($prods as $p) $ip->execute([(int)$id, $p]);
        }
        $apres = caisse_config($db, $id);
        $diff = journal_diff($avant['caisse'], $apres['caisse']);
        if ($avant['assortiment'] != $apres['assortiment']) $diff['assortiment'] = [
            $avant['assortiment']['mode'] === 'tous' ? 'tous les produits' : count($avant['assortiment']['categories']) . ' catégorie(s), ' . count($avant['assortiment']['produits']) . ' produit(s)',
            $mode === 'tous' ? 'tous les produits' : count($cats) . ' catégorie(s), ' . count($prods) . ' produit(s)'];
        if ($diff) journaliser($db, $user, 'update', 'caisses', $id, "Caisse « $nom » paramétrée", $diff);
        return $apres;
    });
}
