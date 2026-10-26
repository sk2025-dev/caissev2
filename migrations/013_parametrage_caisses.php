<?php
// Paramétrage par caisse : emplacement, produits vendus (assortiment), numérotation et ticket propres.
return function (Migrator $m) {
    $m->ensureColumn('caisses', 'emplacement', "VARCHAR(150) NOT NULL DEFAULT ''");
    $m->ensureColumn('caisses', 'prefixe', "VARCHAR(6) NOT NULL DEFAULT ''");             // vide = numérotation commune
    $m->ensureColumn('caisses', 'assortiment', "VARCHAR(12) NOT NULL DEFAULT 'tous'");    // tous | selection
    $m->ensureColumn('caisses', 'ticket_perso', 'TINYINT NOT NULL DEFAULT 0');            // 0 = ticket de la Configuration
    foreach (['ticket_nom' => 150, 'ticket_adresse' => 200, 'ticket_telephone' => 60] as $col => $n) $m->ensureColumn('caisses', $col, "VARCHAR($n) NOT NULL DEFAULT ''");
    $m->ensureColumn('caisses', 'ticket_entete', 'TEXT NULL');
    $m->ensureColumn('caisses', 'ticket_pied', 'TEXT NULL');
    $m->ensureColumn('caisses', 'ticket_largeur', "VARCHAR(3) NOT NULL DEFAULT ''");      // vide = largeur commune

    $m->db->exec('CREATE TABLE IF NOT EXISTS caisse_categories (
        idcaisse INT NOT NULL, idcat INT NOT NULL, PRIMARY KEY (idcaisse, idcat)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $m->db->exec('CREATE TABLE IF NOT EXISTS caisse_produits (
        idcaisse INT NOT NULL, idprod INT NOT NULL, PRIMARY KEY (idcaisse, idprod)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
};
