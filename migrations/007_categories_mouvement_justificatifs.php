<?php
// Catégories de mouvements d'espèces modifiables (le gérant peut en ajouter) et pièce justificative jointe aux mouvements.
return function (Migrator $m) {
    $db = $m->db;
    $db->exec("CREATE TABLE IF NOT EXISTS categories_mouvement (
      idcatmvt INT AUTO_INCREMENT PRIMARY KEY,
      type VARCHAR(10) NOT NULL,
      code VARCHAR(30) NOT NULL,
      libelle VARCHAR(80) NOT NULL,
      ordre INT NOT NULL DEFAULT 0,
      actif TINYINT NOT NULL DEFAULT 1,
      idenr INT NULL, dateenr DATETIME NULL,
      UNIQUE KEY uq_catmvt (type, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $st = $db->prepare('INSERT IGNORE INTO categories_mouvement (type, code, libelle, ordre, dateenr) VALUES (?,?,?,?,?)');
    $defaut = [
        'entree' => ['apport_fonds' => 'Apport de fonds / monnaie', 'retrait_banque' => 'Retrait bancaire', 'remboursement' => 'Remboursement reçu', 'autre_entree' => 'Autre entrée'],
        'sortie' => ['achat_marchandises' => 'Achat de marchandises', 'transport' => 'Transport et livraison', 'fournitures' => 'Fournitures et petites dépenses', 'charges' => 'Charges (eau, électricité, loyer…)', 'salaires' => 'Salaires et avances', 'depot_banque' => 'Versement en banque', 'prelevement' => 'Prélèvement du gérant', 'autre_sortie' => 'Autre sortie'],
    ];
    foreach ($defaut as $type => $cats) { $i = 0; foreach ($cats as $code => $lib) $st->execute([$type, $code, $lib, ($i += 10), gmdate('Y-m-d H:i:s')]); }
    $m->ensureColumn('caisse_operations', 'justificatif', "VARCHAR(100) NOT NULL DEFAULT ''");
};
