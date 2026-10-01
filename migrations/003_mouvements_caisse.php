<?php
// Migration 003 : mouvements d'espèces « comptables » — numéro de pièce, catégorie, référence du justificatif.
return function (Migrator $m) {
    $db = $m->db;
    $m->ensureColumn('caisse_operations', 'numero', 'VARCHAR(30) NULL');
    $m->ensureColumn('caisse_operations', 'categorie', "VARCHAR(30) NOT NULL DEFAULT 'autre'");
    $m->ensureColumn('caisse_operations', 'reference', "VARCHAR(80) NOT NULL DEFAULT ''");
    // Numérotation rétroactive, sans trou, par année (comme les tickets) ; les compteurs repartent après le dernier numéro attribué
    $n = [];
    foreach ($db->query("SELECT idop, created_at FROM caisse_operations WHERE numero IS NULL ORDER BY idop")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $an = substr($r['created_at'], 0, 4);
        if (!isset($n[$an])) {
            $st = $db->prepare('SELECT valeur FROM compteurs WHERE cle = ?'); $st->execute(['mouvement-' . $an]);
            $n[$an] = (int)$st->fetchColumn();
        }
        $n[$an]++;
        $db->prepare('UPDATE caisse_operations SET numero = ? WHERE idop = ?')->execute([sprintf('MC-%s-%06d', $an, $n[$an]), $r['idop']]);
    }
    foreach ($n as $an => $v) $db->prepare('INSERT INTO compteurs (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)')->execute(['mouvement-' . $an, $v]);
    $m->ensureIndex('caisse_operations', 'idx_op_date', ['created_at']);
    $m->ensureIndex('caisse_operations', 'idx_op_numero', ['numero']);
};
