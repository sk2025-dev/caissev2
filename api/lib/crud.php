<?php
/**
 * CRUD générique piloté par la configuration (api/resources.php).
 * Toutes les valeurs passent par des paramètres liés ; les noms de tables/colonnes viennent uniquement de la config.
 */
class Crud
{
    private $db;
    private $cfg;
    private $user;
    private $name;

    public function __construct(PDO $db, $name, array $cfg, array $user)
    {
        $this->db = $db;
        $this->name = $name;
        $this->cfg = $cfg;
        $this->user = $user;
    }

    /* ---------- Lecture ---------- */

    public function listing()
    {
        $c = $this->cfg;
        $where = [$c['where'] ?? '1=1'];
        if (!empty($c['scope'])) $where[] = $c['scope']($this->user);   // ex. un administrateur ne voit pas les super administrateurs
        $params = [];

        if (isset($_GET['q']) && trim($_GET['q']) !== '' && !empty($c['search'])) {
            $likes = [];
            foreach ($c['search'] as $i => $col) {
                $likes[] = "$col LIKE :q$i";
                $params["q$i"] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim($_GET['q'])) . '%';
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }
        // Ressources avec départ (chauffeurs, propriétaires) : par défaut seulement les personnes en poste ; ?statut=parti | tous
        if (!empty($c['soft_status'])) {
            $mode = isset($_GET['statut']) ? $_GET['statut'] : 'actifs';
            if ($mode === 'parti') $where[] = "{$c['soft_status']} IS NOT NULL";
            elseif ($mode !== 'tous') $where[] = "{$c['soft_status']} IS NULL";
        }
        foreach (($c['custom_filters'] ?? []) as $param => $fn) {   // filtres calculés (ex. état du stock) : le callback renvoie un fragment SQL sûr ou null
            if (isset($_GET[$param]) && $_GET[$param] !== '' && ($frag = $fn((string)$_GET[$param])) !== null) $where[] = $frag;
        }
        foreach (($c['filters'] ?? []) as $param => $col) {
            if (isset($_GET[$param]) && $_GET[$param] !== '') {
                $where[] = "$col = :f_$param";
                $params["f_$param"] = $_GET[$param];
            }
        }
        if (!empty($c['date_col'])) {
            foreach (['from' => '>=', 'to' => '<='] as $p => $op) {
                if (!empty($_GET[$p]) && $this->validDate($_GET[$p])) {
                    $where[] = "{$c['date_col']} $op :d_$p";
                    $params["d_$p"] = $_GET[$p];
                }
            }
        }
        $whereSql = implode(' AND ', $where);

        $order = $c['default_sort'];
        if (isset($_GET['sort'], $c['sort'][$_GET['sort']])) {
            $dir = (isset($_GET['dir']) && strtolower($_GET['dir']) === 'asc') ? 'ASC' : 'DESC';
            $order = $c['sort'][$_GET['sort']] . ' ' . $dir . ', ' . $c['default_sort'];
        }

        $all = !empty($_GET['all']);
        $perPage = $all ? 1000 : query_int('per_page', 25, 1, 100);
        $page = $all ? 1 : query_int('page', 1, 1);
        $offset = ($page - 1) * $perPage;

        $st = $this->db->prepare("SELECT COUNT(*) FROM {$c['from']} WHERE $whereSql");
        $st->execute($params);
        $total = (int)$st->fetchColumn();

        $st = $this->db->prepare("SELECT {$c['select']} FROM {$c['from']} WHERE $whereSql ORDER BY $order LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset);
        $st->execute($params);

        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($c['transform'])) $rows = array_map($c['transform'], $rows);

        return [
            'data' => $rows,
            'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => max(1, (int)ceil($total / $perPage))],
        ];
    }

    public function find($id)
    {
        $c = $this->cfg;
        $st = $this->db->prepare("SELECT {$c['select']} FROM {$c['from']} WHERE {$c['pk_col']} = :id AND " . ($c['where'] ?? '1=1') . (!empty($c['scope']) ? ' AND ' . $c['scope']($this->user) : ''));
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ApiError(404, 'Élément introuvable');
        return !empty($c['transform']) ? $c['transform']($row) : $row;
    }

    /* ---------- Écriture ---------- */

    public function create(array $in, array $files)
    {
        $c = $this->cfg;
        $data = $this->validate($in, false);
        $data = $this->handleFiles($data, $files, null);
        $virtuel = [];   // champs « virtuels » : validés mais non stockés dans la table (ex. stock initial), transmis à after_save
        foreach ($c['fields'] as $nom => $f) if (!empty($f['virtuel'])) { if (array_key_exists($nom, $data)) $virtuel[$nom] = $data[$nom]; unset($data[$nom]); }
        if (!empty($c['before_save'])) $data = $c['before_save']($this->db, $data, null, $this->user);

        $a = $c['audit'] ?? [];
        if (!empty($a['enr_by'])) $data[$a['enr_by']] = $this->user['id'];
        if (!empty($a['enr_at'])) $data[$a['enr_at']] = gmdate('Y-m-d H:i:s');
        foreach (($c['on_create'] ?? []) as $k => $v) $data[$k] = $v;

        $cols = array_keys($data);
        $st = $this->db->prepare('INSERT INTO ' . $c['table'] . ' (' . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')');
        $st->execute($data);
        $id = (int)$this->db->lastInsertId();
        $this->audit('create', $id, $data);
        $row = $this->find($id);
        if (!empty($c['after_save'])) { $c['after_save']($this->db, $row, $virtuel, $this->user, true); $row = $this->find($id); }   // relue : after_save peut avoir complété la fiche
        return $row;
    }

    public function update($id, array $in, array $files)
    {
        $c = $this->cfg;
        $existing = $this->find($id);
        $data = $this->validate($in, true);
        $data = $this->handleFiles($data, $files, $existing);
        foreach ($c['fields'] as $nom => $f) if (!empty($f['virtuel'])) unset($data[$nom]);   // non modifiable après création
        if (!empty($c['before_save'])) $data = $c['before_save']($this->db, $data, $existing, $this->user);

        $a = $c['audit'] ?? [];
        if (!empty($a['mod_by'])) $data[$a['mod_by']] = $this->user['id'];
        if (!empty($a['mod_at'])) $data[$a['mod_at']] = gmdate('Y-m-d H:i:s');
        if (!$data) return $existing;

        $sets = [];
        foreach (array_keys($data) as $col) $sets[] = "$col = :$col";
        $data['_id'] = $id;
        $st = $this->db->prepare('UPDATE ' . $c['table'] . ' SET ' . implode(', ', $sets) . " WHERE {$c['pk']} = :_id");
        $st->execute($data);
        unset($data['_id']);
        $this->audit('update', $id, $data);
        $row = $this->find($id);
        if (!empty($c['after_save'])) { $c['after_save']($this->db, $row, [], $this->user, false); $row = $this->find($id); }
        return $row;
    }

    public function delete($id)
    {
        $c = $this->cfg;
        $this->find($id);
        if (!empty($c['before_delete'])) $c['before_delete']($this->db, $id, $this->user);
        $sets = [];
        $params = ['_id' => $id];
        foreach ($c['soft_delete'] as $col => $val) { $sets[] = "$col = :s_$col"; $params["s_$col"] = $val; }
        $st = $this->db->prepare('UPDATE ' . $c['table'] . ' SET ' . implode(', ', $sets) . " WHERE {$c['pk']} = :_id");
        $st->execute($params);
        $this->audit('delete', $id, null);
    }

    /* ---------- Validation ---------- */

    private function validDate($s)
    {
        $d = DateTime::createFromFormat('Y-m-d', (string)$s);
        return $d && $d->format('Y-m-d') === $s;
    }

    private function validate(array $in, $partial)
    {
        $errors = [];
        $out = [];
        foreach ($this->cfg['fields'] as $name => $f) {
            $type = $f['type'];
            $present = array_key_exists($name, $in);
            if (!$present) {
                if (!$partial && !empty($f['required']) && $type !== 'file') $errors[$name] = 'Champ obligatoire';
                continue;
            }
            $v = $in[$name];
            $v = is_string($v) ? trim($v) : $v;
            $empty = ($v === '' || $v === null);

            if ($type === 'password') {
                if ($empty) { if (!$partial) $errors[$name] = 'Champ obligatoire'; continue; }
                if (strlen($v) < 6) { $errors[$name] = '6 caractères minimum'; continue; }
                $out[$name] = pwd_pour_stockage($v);
                continue;
            }
            if ($type === 'file') continue;

            if ($empty) {
                if (!empty($f['required'])) { $errors[$name] = 'Champ obligatoire'; continue; }
                $out[$name] = in_array($type, ['number', 'int'], true) ? (!empty($f['nullable']) ? null : 0) : (in_array($type, ['date', 'ref'], true) ? null : '');   // un nombre laissé vide vaut 0 (colonnes NOT NULL)
                continue;
            }
            switch ($type) {
                case 'string':
                    if (!is_scalar($v)) { $errors[$name] = 'Valeur invalide'; break; }
                    $max = $f['max'] ?? 255;
                    if (mb_strlen((string)$v) > $max) { $errors[$name] = "$max caractères maximum"; break; }
                    $out[$name] = (string)$v;
                    break;
                case 'email':
                    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[$name] = 'E-mail invalide'; break; }
                    $out[$name] = $v;
                    break;
                case 'phone':
                    $n = preg_replace('/\s+/', ' ', (string)$v);
                    $chiffres = strlen(preg_replace('/\D/', '', $n));
                    if (!preg_match('/^\+[1-9][0-9 ]*$/', $n) || $chiffres < 8 || $chiffres > 15 || strlen($n) > 30) {
                        $errors[$name] = 'Numéro invalide (format international attendu, ex. +225 07 12 34 56 78)';
                        break;
                    }
                    $out[$name] = $n;
                    break;
                case 'int':
                    if (filter_var($v, FILTER_VALIDATE_INT) === false) { $errors[$name] = 'Entier attendu'; break; }
                    if ((isset($f['min']) && (int)$v < $f['min']) || (isset($f['max']) && (int)$v > $f['max'])) { $errors[$name] = 'Valeur entre ' . ($f['min'] ?? '…') . ' et ' . ($f['max'] ?? '…'); break; }
                    $out[$name] = (int)$v;
                    break;
                case 'number':
                    $n = str_replace([' ', "\xC2\xA0"], '', str_replace(',', '.', (string)$v));
                    if (!is_numeric($n) || $n < 0) { $errors[$name] = 'Montant invalide'; break; }
                    if (isset($f['max']) && $n > $f['max']) { $errors[$name] = 'Maximum ' . $f['max']; break; }
                    $out[$name] = $n;
                    break;
                case 'date':
                    if (!$this->validDate($v)) { $errors[$name] = 'Date invalide (AAAA-MM-JJ)'; break; }
                    $out[$name] = $v;
                    break;
                case 'enum':
                    if (!in_array($v, $f['values'], true) && !in_array((string)$v, array_map('strval', $f['values']), true)) { $errors[$name] = 'Valeur non autorisée'; break; }
                    $out[$name] = is_int($f['values'][0]) ? (int)$v : $v;
                    break;
                case 'ref':
                    if (filter_var($v, FILTER_VALIDATE_INT) === false) { $errors[$name] = 'Référence invalide'; break; }
                    if ((int)$v === 0 && !empty($f['allow_zero'])) { $out[$name] = 0; break; }
                    $st = $this->db->prepare("SELECT COUNT(*) FROM {$f['table']} WHERE {$f['pk']} = :v" . (isset($f['where']) ? ' AND ' . $f['where'] : ''));
                    $st->execute(['v' => (int)$v]);
                    if ((int)$st->fetchColumn() === 0) { $errors[$name] = 'Élément inexistant'; break; }
                    $out[$name] = (int)$v;
                    break;
            }
        }
        if ($errors) throw new ApiError(422, 'Données invalides', $errors);
        return $out;
    }

    /* ---------- Fichiers ---------- */

    private function handleFiles(array $data, array $files, $existing)
    {
        foreach ($this->cfg['fields'] as $name => $f) {
            if ($f['type'] !== 'file') continue;
            if (!isset($files[$name]) || $files[$name]['error'] === UPLOAD_ERR_NO_FILE) continue;
            $data[$name] = store_upload($files[$name], $f['dir'], $f['allow']);
        }
        return $data;
    }

    private function audit($action, $id, $detail)
    {
        try {
            $st = $this->db->prepare('INSERT INTO audit_log (user_id, action, entity, entity_id, detail, ip, created_at) VALUES (?,?,?,?,?,?,?)');
            $st->execute([$this->user['id'], $action, $this->name, $id, $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, client_ip(), gmdate('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            error_log('audit_log indisponible : ' . $e->getMessage()); // la migration 004 n'est peut-être pas appliquée
        }
    }
}

/** Enregistre un fichier téléversé dans doc/<dir>/ après contrôle de l'extension, de la taille et du type. */
function store_upload(array $file, $dir, array $allowedExt)
{
    if ($file['error'] !== UPLOAD_ERR_OK) throw new ApiError(422, 'Échec du téléversement', [$dir => 'Téléversement impossible']);
    if ($file['size'] > 5 * 1024 * 1024) throw new ApiError(422, 'Fichier trop volumineux', [$dir => '5 Mo maximum']);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) throw new ApiError(422, 'Type de fichier refusé', [$dir => 'Extensions autorisées : ' . implode(', ', $allowedExt)]);
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true) && @getimagesize($file['tmp_name']) === false) {
        throw new ApiError(422, 'Image invalide', [$dir => 'Le fichier n\'est pas une image valide']);
    }
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $base = substr($base, 0, 40) ?: 'fichier';
    $nom = $base . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $cible = dirname(__DIR__, 2) . '/doc/' . $dir;
    if (!is_dir($cible)) mkdir($cible, 0755, true);
    if (!move_uploaded_file($file['tmp_name'], $cible . '/' . $nom)) throw new ApiError(500, 'Enregistrement du fichier impossible');
    return $nom;
}
