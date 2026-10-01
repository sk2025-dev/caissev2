<?php
// Migration 001 : données de départ — rôles, modes de paiement, première caisse, compteurs.
return function (Migrator $m) {
    $db = $m->db;
    // Rôles : superadmin (configuration) > admin (gérant) > magasinier (stock, achats) / caissier (caisse, clients)
    foreach (['admin', 'caissier', 'magasinier', 'superadmin'] as $g) {
        $db->prepare('INSERT INTO table_gpe_users (coden) SELECT ? FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM table_gpe_users WHERE coden = ?)')->execute([$g, $g]);
    }
    $modes = [['especes', 'Espèces', 'especes', 1], ['orange', 'Orange Money', 'mobile', 2], ['mtn', 'MTN Mobile Money', 'mobile', 3], ['wave', 'Wave', 'mobile', 4], ['moov', 'Moov Money', 'mobile', 5], ['carte', 'Carte bancaire', 'carte', 6], ['cheque', 'Chèque', 'autre', 7]];
    foreach ($modes as $x) {
        $db->prepare('INSERT INTO modes_paiement (code, libelle, type, actif, ordre) SELECT ?,?,?,1,? FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM modes_paiement WHERE code = ?)')->execute([$x[0], $x[1], $x[2], $x[3], $x[0]]);
    }
    $db->exec("INSERT INTO caisses (nom, actif) SELECT 'Caisse 1', 1 FROM (SELECT 1) t WHERE NOT EXISTS (SELECT 1 FROM caisses)");
};
