<?php
/**
 * Runner de migrations.
 *   php bin/migrate.php            applique les migrations en attente
 *   php bin/migrate.php status     liste l'etat des migrations
 * Les migrations sont dans migrations/ : NNN_nom.sql (plusieurs requetes separees par ;) ou NNN_nom.php (closure(Migrator)).
 * Les migrations appliquees sont enregistrees dans la table schema_migrations.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../connexion/conn.php';

class Migrator
{
    public $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public function columnExists($table, $col)
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $st->execute([$table, $col]);
        return (int)$st->fetchColumn() > 0;
    }

    public function ensureColumn($table, $col, $definition)
    {
        if (!$this->columnExists($table, $col)) {
            $this->db->exec("ALTER TABLE $table ADD COLUMN $col $definition");
            echo "    + colonne $table.$col\n";
        }
    }

    public function ensureIndex($table, $name, array $cols)
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
        $st->execute([$table, $name]);
        if ((int)$st->fetchColumn() === 0) {
            $this->db->exec("CREATE INDEX $name ON $table (" . implode(',', $cols) . ')');
            echo "    + index $name\n";
        }
    }
}

$db = $bdd;
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(100) PRIMARY KEY, applied_at VARCHAR(20) NOT NULL)');
$done = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);

$files = glob(__DIR__ . '/../migrations/*.{sql,php}', GLOB_BRACE);
sort($files);
$statusOnly = isset($argv[1]) && $argv[1] === 'status';
$m = new Migrator($db);
$appliquees = 0;

foreach ($files as $f) {
    $version = basename($f);
    $fait = in_array($version, $done, true);
    if ($statusOnly) { echo ($fait ? '[x] ' : '[ ] ') . $version . "\n"; continue; }
    if ($fait) continue;

    echo "-> $version\n";
    try {
        if (substr($f, -4) === '.sql') {
            $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($f));
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) $db->exec($stmt);
        } else {
            $fn = require $f;
            $fn($m);
        }
        $db->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')->execute([$version, gmdate('Y-m-d H:i:s')]);
        $appliquees++;
    } catch (Throwable $e) {
        fwrite(STDERR, "ECHEC $version : " . $e->getMessage() . "\n");
        exit(1);
    }
}
if (!$statusOnly) echo $appliquees ? "$appliquees migration(s) appliquee(s).\n" : "Base a jour.\n";
