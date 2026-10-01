<?php
/** Carte virtuelle d'un utilisateur (badge du personnel) et photo de profil. */

const ROLES_LIBELLES = ['superadmin' => 'Super administrateur', 'admin' => 'Gérant', 'caissier' => 'Caissier', 'magasinier' => 'Magasinier'];

/** Utilisateur visé : soi-même, ou un autre pour le gérant (le compte super administrateur reste réservé au super administrateur). */
function utilisateur_cible(PDO $db, array $user, $id)
{
    $id = (int)$id ?: (int)$user['id'];
    $st = $db->prepare('SELECT u.*, g.coden AS groupe FROM users u LEFT JOIN table_gpe_users g ON g.idgpe = u.gpe WHERE u.id_user = ?');
    $st->execute([$id]); $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) throw new ApiError(404, 'Utilisateur introuvable');
    if ($id !== (int)$user['id']) {
        if (!is_admin_role($user['role'])) throw new ApiError(403, 'Vous ne pouvez consulter que votre propre carte.');
        if ($u['groupe'] === 'superadmin' && !is_super_role($user['role'])) throw new ApiError(403, 'Réservé au super administrateur.');
    }
    return $u;
}

function utilisateur_carte(PDO $db, array $user, $id)
{
    $u = utilisateur_cible($db, $user, $id);
    $e = settings_section(settings_all($db), 'entreprise');
    return [
        'id' => (int)$u['id_user'], 'nom' => $u['nomag'], 'prenom' => $u['prenom'], 'email' => $u['emailag'], 'telephone' => $u['telag'],
        'role' => $u['groupe'], 'role_libelle' => ROLES_LIBELLES[$u['groupe']] ?? (string)$u['groupe'],
        'matricule' => sprintf('EMP-%05d', $u['id_user']), 'depuis' => $u['dateenr'], 'actif' => (int)$u['user_status'] === 1,
        'photo' => $u['photo_user'], 'soi' => (int)$u['id_user'] === (int)$user['id'],
        'entreprise' => ['nom' => $e['nom'], 'forme' => $e['forme'], 'telephone' => $e['telephone'], 'site' => $e['site'], 'ville' => $e['ville'], 'logo' => $e['logo'] !== ''],
    ];
}

/** Dépose (ou retire) la photo : la sienne, ou celle d'un collaborateur pour le gérant. */
function utilisateur_photo(PDO $db, array $user, $id, array $files, array $in)
{
    $u = utilisateur_cible($db, $user, $id);
    $retirer = !empty($in['retirer']);
    if (!$retirer && empty($files['photo'])) throw new ApiError(422, 'Données invalides', ['photo' => 'Choisissez une photo']);
    $nom = $retirer ? '' : store_upload($files['photo'], 'utilisateurs', ['jpg', 'jpeg', 'png', 'webp']);
    $db->prepare('UPDATE users SET photo_user = ?, datemodify = ? WHERE id_user = ?')->execute([$nom, gmdate('Y-m-d H:i:s'), $u['id_user']]);
    if ($u['photo_user'] !== '' && $u['photo_user'] !== $nom) @unlink(dirname(__DIR__, 2) . '/doc/utilisateurs/' . basename($u['photo_user']));
    if ((int)$u['id_user'] === (int)$user['id']) $_SESSION['photo'] = $nom;   // l'avatar de la barre se met à jour
    return utilisateur_carte($db, $user, $u['id_user']);
}
