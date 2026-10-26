<?php
// Journal d'activité : auteur figé, module, résumé lisible, index de consultation ; reprise des entrées existantes.
return function (Migrator $m) {
    $m->ensureColumn('audit_log', 'acteur', "VARCHAR(150) NOT NULL DEFAULT '' AFTER user_id");
    $m->ensureColumn('audit_log', 'module', "VARCHAR(20) NOT NULL DEFAULT '' AFTER action");
    $m->ensureColumn('audit_log', 'resume', "VARCHAR(255) NOT NULL DEFAULT '' AFTER entity_id");
    $m->db->exec('ALTER TABLE audit_log MODIFY action VARCHAR(30) NOT NULL');
    $m->ensureIndex('audit_log', 'idx_audit_user', ['user_id', 'created_at']);
    $m->ensureIndex('audit_log', 'idx_audit_module', ['module', 'created_at']);

    $modules = ['utilisateurs' => 'securite', 'configuration' => 'configuration', 'produits' => 'catalogue', 'categories' => 'catalogue', 'fournisseurs' => 'achats',
        'clients' => 'clients', 'sessions_caisse' => 'caisse', 'caisses' => 'caisse', 'zones-livraison' => 'commandes'];
    $entites = ['produits' => 'Produit', 'categories' => 'Catégorie', 'clients' => 'Client', 'fournisseurs' => 'Fournisseur', 'caisses' => 'Caisse', 'zones-livraison' => 'Tarif de livraison', 'utilisateurs' => 'Utilisateur'];
    $verbes = ['create' => 'créé', 'update' => 'modifié', 'delete' => 'supprimé'];
    $maj = $m->db->prepare('UPDATE audit_log SET module = ?, resume = ?, detail = ? WHERE id = ?');
    foreach ($m->db->query("SELECT id, action, entity, entity_id, detail FROM audit_log WHERE module = ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $d = $r['detail'] !== null ? json_decode($r['detail'], true) : null;
        if (is_array($d) && isset($d['pass'])) $d['pass'] = '••••';   // les anciennes entrées contenaient le haché du mot de passe
        $nom = is_array($d) ? (!empty($d['nomag']) ? trim($d['nomag'] . ' ' . ($d['prenom'] ?? '')) : ($d['nom'] ?? '')) : '';
        if ($r['action'] === 'cloture_forcee') $resume = 'Clôture forcée de la caisse' . (is_array($d) && !empty($d['caisse']) ? ' « ' . $d['caisse'] . ' »' : '');
        elseif ($r['entity'] === 'configuration') $resume = 'Configuration modifiée' . (is_array($d) && !empty($d['section']) ? ' : ' . $d['section'] : '');
        else $resume = trim(($entites[$r['entity']] ?? $r['entity']) . ($nom !== '' ? ' « ' . $nom . ' »' : ' n° ' . $r['entity_id']) . ' ' . ($verbes[$r['action']] ?? $r['action']));
        $maj->execute([$modules[$r['entity']] ?? 'autre', mb_substr($resume, 0, 255), $d !== null ? json_encode($d, JSON_UNESCAPED_UNICODE) : null, $r['id']]);
    }
};
