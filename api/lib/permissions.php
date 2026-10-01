<?php
/**
 * Droits par rôle. Le super administrateur et l'administrateur (gérant) ont tous les droits courants ;
 * le caissier vend et encaisse ; le magasinier gère le catalogue, le stock et les achats.
 */
const DROITS = [
    'superadmin' => ['*'],
    'admin' => ['*'],
    'caissier' => ['catalogue.lire', 'clients.lire', 'clients.ecrire', 'caisse.vendre', 'ventes.lire_siennes', 'stock.lire'],
    'magasinier' => ['catalogue.lire', 'catalogue.ecrire', 'stock.lire', 'stock.ecrire', 'achats.lire', 'achats.ecrire', 'fournisseurs.lire', 'fournisseurs.ecrire', 'clients.lire'],
];

function role_peut($role, $droit)
{
    $liste = DROITS[$role] ?? [];
    return in_array('*', $liste, true) || in_array($droit, $liste, true);
}

function exiger($user, $droit, $message = null)
{
    if (!role_peut($user['role'], $droit)) throw new ApiError(403, $message ?: "Votre rôle ne permet pas cette action.");
}
