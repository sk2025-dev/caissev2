<?php
/**
 * Définition des ressources CRUD de l'application de caisse.
 * 'roles' : rôles autorisés en lecture (absent = tout utilisateur connecté) ; 'roles_ecriture' : rôles autorisés à créer / modifier ; la suppression est réservée aux administrateurs.
 * 'virtuel' : champ validé mais non stocké dans la table (transmis à after_save).
 */

$audit = ['enr_by' => 'idenr', 'enr_at' => 'dateenr', 'mod_by' => 'idmodif', 'mod_at' => 'datemodif'];
$gestion = ['admin', 'superadmin', 'magasinier'];

function unique_ou_erreur(PDO $db, $table, $colonne, $valeur, $pk, $id, $champ, $message)
{
    if ($valeur === '' || $valeur === null) return;
    $st = $db->prepare("SELECT COUNT(*) FROM $table WHERE $colonne = :v AND supp = 0 AND $pk <> :i");
    $st->execute(['v' => $valeur, 'i' => $id]);
    if ((int)$st->fetchColumn() > 0) throw new ApiError(422, 'Données invalides', [$champ => $message]);
}

return [

    'categories' => [
        'table' => 'categories', 'pk' => 'idcat', 'pk_col' => 'c.idcat',
        'select' => 'c.*, (SELECT COUNT(*) FROM produits p WHERE p.idcat = c.idcat AND p.supp = 0) AS nb_produits',
        'from' => 'categories c', 'where' => 'c.supp = 0',
        'search' => ['c.nom'], 'sort' => ['nom' => 'c.nom', 'ordre' => 'c.ordre'], 'default_sort' => 'c.ordre ASC, c.nom ASC',
        'audit' => $audit, 'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles_ecriture' => $gestion,
        'fields' => [
            'nom' => ['type' => 'string', 'required' => true, 'max' => 100],
            'couleur' => ['type' => 'string', 'max' => 9],
            'ordre' => ['type' => 'int', 'min' => 0, 'max' => 9999],
            'image' => ['type' => 'file', 'dir' => 'categories', 'allow' => ['jpg', 'jpeg', 'png', 'webp']],
        ],
        'before_save' => function (PDO $db, array $d, $existing, $user) {
            if (isset($d['nom'])) unique_ou_erreur($db, 'categories', 'nom', $d['nom'], 'idcat', $existing ? $existing['idcat'] : 0, 'nom', 'Cette catégorie existe déjà');
            return $d;
        },
        'before_delete' => function (PDO $db, $id, $user) {
            $st = $db->prepare('SELECT COUNT(*) FROM produits WHERE idcat = :i AND supp = 0'); $st->execute(['i' => $id]);
            if ((int)$st->fetchColumn() > 0) throw new ApiError(409, 'Cette catégorie contient des produits : déplacez-les d\'abord.');
        },
    ],

    'zones-livraison' => [
        'table' => 'zones_livraison', 'pk' => 'idzone', 'pk_col' => 'z.idzone',
        'select' => 'z.*', 'from' => 'zones_livraison z', 'where' => 'z.supp = 0',
        'search' => ['z.nom', 'z.commune'], 'filters' => ['zone' => 'z.zone'],
        'sort' => ['nom' => 'z.nom', 'commune' => 'z.commune', 'prix' => 'z.prix', 'zone' => 'z.zone'], 'default_sort' => "FIELD(z.zone, 'abidjan', 'interieur', 'exterieur'), z.commune ASC, z.nom ASC",
        'audit' => $audit, 'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles_ecriture' => ['admin', 'superadmin'],
        'fields' => [
            'zone' => ['type' => 'enum', 'values' => ['abidjan', 'interieur', 'exterieur'], 'required' => true],
            'commune' => ['type' => 'string', 'max' => 80],
            'nom' => ['type' => 'string', 'required' => true, 'max' => 100],
            'prix' => ['type' => 'number', 'required' => true],
            'delai' => ['type' => 'string', 'max' => 40],
            'actif' => ['type' => 'enum', 'values' => [0, 1]],
        ],
        'before_save' => function (PDO $db, array $d, $existing, $user) {
            $zone = $d['zone'] ?? ($existing['zone'] ?? ''); $nom = $d['nom'] ?? ($existing['nom'] ?? ''); $commune = $d['commune'] ?? ($existing['commune'] ?? '');
            $st = $db->prepare('SELECT COUNT(*) FROM zones_livraison WHERE supp = 0 AND zone = :z AND commune = :c AND nom = :n AND idzone <> :i');
            $st->execute(['z' => $zone, 'c' => $commune, 'n' => $nom, 'i' => $existing ? $existing['idzone'] : 0]);
            if ((int)$st->fetchColumn() > 0) throw new ApiError(422, 'Données invalides', ['nom' => 'Ce lieu a déjà un tarif']);
            return $d;
        },
    ],

    'produits' => [
        'table' => 'produits', 'pk' => 'idprod', 'pk_col' => 'p.idprod',
        'select' => 'p.*, c.nom AS categorie, ROUND(p.stock_qty * p.prix_achat, 2) AS valeur_stock, ROUND(p.prix_vente - p.prix_achat, 2) AS marge',
        'from' => 'produits p LEFT JOIN categories c ON c.idcat = p.idcat', 'where' => 'p.supp = 0',
        'search' => ['p.nom', 'p.sku', 'p.code_barres', 'c.nom'],
        'filters' => ['type' => 'p.type', 'idcat' => 'p.idcat', 'actif' => 'p.actif'],
        'custom_filters' => ['etat' => function ($v) {
            if ($v === 'rupture') return '(p.stockable = 1 AND p.stock_qty <= 0)';
            if ($v === 'bas') return '(p.stockable = 1 AND p.stock_qty > 0 AND p.seuil_alerte > 0 AND p.stock_qty <= p.seuil_alerte)';
            if ($v === 'ok') return '(p.stockable = 0 OR p.stock_qty > GREATEST(p.seuil_alerte, 0))';
            return null;
        }],
        'sort' => ['nom' => 'p.nom', 'prix_vente' => 'p.prix_vente', 'stock_qty' => 'p.stock_qty', 'valeur_stock' => 'valeur_stock', 'categorie' => 'c.nom', 'marge' => 'marge'],
        'default_sort' => 'p.nom ASC',
        'audit' => $audit, 'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles_ecriture' => $gestion,
        'fields' => [
            'type' => ['type' => 'enum', 'values' => ['produit', 'service'], 'required' => true],
            'nom' => ['type' => 'string', 'required' => true, 'max' => 150],
            'sku' => ['type' => 'string', 'max' => 50],
            'code_barres' => ['type' => 'string', 'max' => 50],
            'idcat' => ['type' => 'ref', 'table' => 'categories', 'pk' => 'idcat', 'where' => 'supp = 0'],
            'unite' => ['type' => 'string', 'max' => 20],
            'prix_vente' => ['type' => 'number', 'required' => true],
            'prix_achat' => ['type' => 'number'],
            'tva_taux' => ['type' => 'number', 'max' => 50],
            'stockable' => ['type' => 'enum', 'values' => [0, 1]],
            'seuil_alerte' => ['type' => 'number'],
            'actif' => ['type' => 'enum', 'values' => [0, 1]],
            'description' => ['type' => 'string', 'max' => 500],
            'image' => ['type' => 'file', 'dir' => 'produits', 'allow' => ['jpg', 'jpeg', 'png', 'webp']],
            'stock_initial' => ['type' => 'number', 'virtuel' => true],   // enregistré comme premier mouvement de stock
        ],
        'before_save' => function (PDO $db, array $d, $existing, $user) {
            $id = $existing ? $existing['idprod'] : 0;
            $type = isset($d['type']) ? $d['type'] : ($existing ? $existing['type'] : 'produit');
            if ($type === 'service') {
                if ($existing && $existing['type'] !== 'service' && $existing['stock_qty'] > 0) throw new ApiError(422, 'Données invalides', ['type' => 'Ce produit a du stock : videz-le avant de le passer en service']);
                $d['stockable'] = 0; $d['unite'] = isset($d['unite']) && $d['unite'] !== '' ? $d['unite'] : 'prestation';
            } elseif (!$existing && !isset($d['stockable'])) $d['stockable'] = 1;
            if (!$existing && (!isset($d['unite']) || $d['unite'] === '')) $d['unite'] = 'pièce';
            if (isset($d['sku'])) unique_ou_erreur($db, 'produits', 'sku', $d['sku'], 'idprod', $id, 'sku', 'Cette référence existe déjà');
            if (isset($d['code_barres'])) unique_ou_erreur($db, 'produits', 'code_barres', $d['code_barres'], 'idprod', $id, 'code_barres', 'Ce code-barres est déjà utilisé');
            return $d;
        },
        'after_save' => function (PDO $db, $row, array $virtuel, $user, $creation) {
            if (!$creation) return;
            if ($row['sku'] === '') $db->prepare('UPDATE produits SET sku = ? WHERE idprod = ?')->execute([sprintf('P%06d', $row['idprod']), $row['idprod']]);
            $q = isset($virtuel['stock_initial']) ? (float)$virtuel['stock_initial'] : 0;
            if ($q > 0 && (int)$row['stockable']) stock_transaction($db, function () use ($db, $user, $row, $q) {
                stock_bouger($db, $user['id'], produit_verrouille($db, $row['idprod']), 'initial', $q, ['motif' => 'Stock initial', 'ref_type' => 'produit', 'ref_id' => $row['idprod']]);
            });
        },
    ],

    'clients' => [
        'table' => 'clients', 'pk' => 'idclient', 'pk_col' => 'c.idclient',
        'select' => "c.*, (SELECT COUNT(*) FROM ventes v WHERE v.idclient = c.idclient AND v.statut = 'validee') AS nb_achats, (SELECT COALESCE(SUM(v.total), 0) FROM ventes v WHERE v.idclient = c.idclient AND v.statut = 'validee') AS total_achats, (SELECT MAX(v.date_vente) FROM ventes v WHERE v.idclient = c.idclient AND v.statut = 'validee') AS derniere_visite",
        'from' => 'clients c', 'where' => 'c.supp = 0',
        'search' => ['c.nom', 'c.telephone', 'c.email'],
        'custom_filters' => ['etat' => function ($v) { return $v === 'debiteur' ? '(c.solde > 0)' : null; }],
        'sort' => ['nom' => 'c.nom', 'solde' => 'c.solde', 'total_achats' => 'total_achats'], 'default_sort' => 'c.nom ASC',
        'audit' => $audit, 'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles_ecriture' => ['admin', 'superadmin', 'caissier'],
        'fields' => [
            'nom' => ['type' => 'string', 'required' => true, 'max' => 150],
            'telephone' => ['type' => 'phone'],
            'email' => ['type' => 'email', 'max' => 150],
            'adresse' => ['type' => 'string', 'max' => 250],
            'plafond_credit' => ['type' => 'number'],
            'notes' => ['type' => 'string', 'max' => 500],
        ],
        'before_delete' => function (PDO $db, $id, $user) {
            $st = $db->prepare('SELECT solde FROM clients WHERE idclient = :i'); $st->execute(['i' => $id]);
            if ($st->fetchColumn() > 0) throw new ApiError(409, 'Ce client a une dette en cours : encaissez-la avant de le supprimer.');
        },
    ],

    'fournisseurs' => [
        'table' => 'fournisseurs', 'pk' => 'idfour', 'pk_col' => 'f.idfour',
        'select' => "f.*, (SELECT COUNT(*) FROM approvisionnements a WHERE a.idfour = f.idfour) AS nb_appros, (SELECT COALESCE(SUM(a.total), 0) FROM approvisionnements a WHERE a.idfour = f.idfour) AS total_achats",
        'from' => 'fournisseurs f', 'where' => 'f.supp = 0',
        'search' => ['f.nom', 'f.contact', 'f.telephone'],
        'custom_filters' => ['etat' => function ($v) { return $v === 'debiteur' ? '(f.solde > 0)' : null; }],
        'sort' => ['nom' => 'f.nom', 'solde' => 'f.solde'], 'default_sort' => 'f.nom ASC',
        'audit' => $audit, 'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles' => $gestion, 'roles_ecriture' => $gestion,
        'fields' => [
            'nom' => ['type' => 'string', 'required' => true, 'max' => 150],
            'contact' => ['type' => 'string', 'max' => 150],
            'telephone' => ['type' => 'phone'],
            'email' => ['type' => 'email', 'max' => 150],
            'adresse' => ['type' => 'string', 'max' => 250],
            'notes' => ['type' => 'string', 'max' => 500],
        ],
        'before_delete' => function (PDO $db, $id, $user) {
            $st = $db->prepare('SELECT solde FROM fournisseurs WHERE idfour = :i'); $st->execute(['i' => $id]);
            if ($st->fetchColumn() > 0) throw new ApiError(409, 'Une dette envers ce fournisseur est en cours : réglez-la avant de le supprimer.');
        },
    ],

    'caisses' => [
        'table' => 'caisses', 'pk' => 'idcaisse', 'pk_col' => 'k.idcaisse',
        'select' => 'k.*', 'from' => 'caisses k', 'where' => 'k.supp = 0', 'search' => ['k.nom'], 'sort' => ['nom' => 'k.nom'], 'default_sort' => 'k.idcaisse ASC',
        'on_create' => ['supp' => 0], 'soft_delete' => ['supp' => 1], 'roles' => ['admin', 'superadmin'], 'roles_ecriture' => ['admin', 'superadmin'],
        'fields' => ['nom' => ['type' => 'string', 'required' => true, 'max' => 80], 'actif' => ['type' => 'enum', 'values' => [0, 1]]],
        'before_delete' => function (PDO $db, $id, $user) {
            $st = $db->prepare("SELECT COUNT(*) FROM sessions_caisse WHERE idcaisse = :i AND statut = 'ouverte'"); $st->execute(['i' => $id]);
            if ((int)$st->fetchColumn() > 0) throw new ApiError(409, 'Cette caisse est actuellement ouverte.');
        },
    ],

    // Réservé aux administrateurs ; le mot de passe n'est jamais renvoyé.
    'utilisateurs' => [
        'roles' => ['admin', 'superadmin'], 'roles_ecriture' => ['admin', 'superadmin'],
        'scope' => function ($user) { return is_super_role($user['role']) ? '1=1' : "(g.coden IS NULL OR g.coden <> 'superadmin')"; },
        'table' => 'users', 'pk' => 'id_user', 'pk_col' => 'u.id_user',
        'select' => 'u.id_user, u.nomag, u.prenom, u.emailag, u.telag, u.gpe, u.user_status, u.photo_user, u.dateenr, g.coden AS groupe',
        'from' => 'users u LEFT JOIN table_gpe_users g ON g.idgpe = u.gpe',
        'where' => '1=1',
        'search' => ['u.nomag', 'u.prenom', 'u.emailag'],
        'sort' => ['nomag' => 'u.nomag', 'emailag' => 'u.emailag', 'groupe' => 'g.coden'],
        'default_sort' => 'u.id_user DESC',
        'audit' => ['enr_at' => 'dateenr', 'mod_at' => 'datemodify'], 'soft_delete' => ['user_status' => 0],
        'fields' => [
            'nomag' => ['type' => 'string', 'required' => true, 'max' => 100],
            'prenom' => ['type' => 'string', 'max' => 100],
            'emailag' => ['type' => 'email', 'required' => true, 'max' => 150],
            'telag' => ['type' => 'phone'],
            'pass' => ['type' => 'password', 'required' => true],
            'gpe' => ['type' => 'ref', 'required' => true, 'table' => 'table_gpe_users', 'pk' => 'idgpe'],
            'user_status' => ['type' => 'enum', 'values' => [0, 1]],
            'photo_user' => ['type' => 'file', 'dir' => 'utilisateurs', 'allow' => ['jpg', 'jpeg', 'png', 'webp']],
        ],
        'before_save' => function (PDO $db, array $d, $existing, $user) {
            // Seul un super administrateur peut attribuer ce rôle
            if (isset($d['gpe']) && !is_super_role($user['role'])) {
                $st = $db->prepare('SELECT coden FROM table_gpe_users WHERE idgpe = :g'); $st->execute(['g' => $d['gpe']]);
                if ($st->fetchColumn() === 'superadmin') throw new ApiError(422, 'Données invalides', ['gpe' => 'Réservé au super administrateur']);
            }
            if (isset($d['emailag'])) {
                $st = $db->prepare('SELECT COUNT(*) FROM users WHERE emailag = :e AND id_user <> :id');
                $st->execute(['e' => $d['emailag'], 'id' => $existing ? $existing['id_user'] : 0]);
                if ((int)$st->fetchColumn() > 0) throw new ApiError(422, 'Données invalides', ['emailag' => 'Cet e-mail est déjà utilisé']);
            }
            if ($existing && (int)$existing['id_user'] === (int)$user['id'] && isset($d['user_status']) && (int)$d['user_status'] === 0) {
                throw new ApiError(422, 'Données invalides', ['user_status' => 'Vous ne pouvez pas désactiver votre propre compte']);
            }
            if (!$existing && !isset($d['user_status'])) $d['user_status'] = 1;
            return $d;
        },
        'before_delete' => function (PDO $db, $id, $user) {
            if ((int)$id === (int)$user['id']) throw new ApiError(409, 'Vous ne pouvez pas désactiver votre propre compte.');
        },
    ],
];
