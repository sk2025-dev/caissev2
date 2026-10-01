<?php
/**
 * Sauvegarde complète de la base (structure + données) au format SQL, compressée en gzip.
 * Écrit en flux : la mémoire reste constante quelle que soit la taille des tables.
 */
const DUMP_EXCLURE = ['export_jobs', 'login_attempts'];   // données techniques sans intérêt dans une sauvegarde

function dump_database(PDO $db, $path, $progression = null)
{
    $gz = gzopen($path, 'wb6');
    if (!$gz) throw new RuntimeException("Écriture impossible : $path");
    $nomBase = $db->query('SELECT DATABASE()')->fetchColumn();
    $tables = [];
    foreach ($db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as $r) if (!in_array($r[0], DUMP_EXCLURE, true)) $tables[] = $r[0];
    $total = max(1, count($tables));

    gzwrite($gz, "-- Caisse — sauvegarde de la base « $nomBase »\n-- Générée le " . gmdate('d/m/Y à H:i') . " (UTC) — " . count($tables) . " tables\n-- Restauration : mysql -u utilisateur -p nom_de_la_base < fichier.sql  (après décompression)\n\n");
    gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n");

    foreach ($tables as $i => $t) {
        $q = '`' . str_replace('`', '``', $t) . '`';
        $creation = $db->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM)[1];
        gzwrite($gz, "-- ----------------------------\n-- Table $t\n-- ----------------------------\nDROP TABLE IF EXISTS $q;\n$creation;\n\n");

        $cols = array_map(function ($c) { return '`' . str_replace('`', '``', $c['Field']) . '`'; }, $db->query("SHOW COLUMNS FROM $q")->fetchAll(PDO::FETCH_ASSOC));
        $pk = $db->query("SHOW KEYS FROM $q WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        $ordre = $pk ? ' ORDER BY ' . implode(', ', array_map(function ($k) { return '`' . $k['Column_name'] . '`'; }, $pk)) : '';
        $lot = 500; $offset = 0;
        while (true) {
            $rows = $db->query("SELECT * FROM $q$ordre LIMIT $lot OFFSET $offset")->fetchAll(PDO::FETCH_NUM);
            if (!$rows) break;
            $valeurs = [];
            foreach ($rows as $row) $valeurs[] = '(' . implode(',', array_map(function ($v) use ($db) { return $v === null ? 'NULL' : $db->quote((string)$v); }, $row)) . ')';
            gzwrite($gz, "INSERT INTO $q (" . implode(',', $cols) . ") VALUES\n" . implode(",\n", $valeurs) . ";\n");
            $offset += $lot;
            if (count($rows) < $lot) break;
        }
        gzwrite($gz, "\n");
        if ($progression) $progression((int)round(($i + 1) / $total * 100));
    }
    gzwrite($gz, "SET FOREIGN_KEY_CHECKS = 1;\n-- Fin de la sauvegarde\n");
    gzclose($gz);
}
