<?php
// Détail des factures fournisseurs : produits (ou libellés libres), quantités et prix. Sans effet sur le stock :
// les entrées de marchandises restent des réceptions anonymes.
return function (Migrator $m) {
    $m->db->exec("CREATE TABLE IF NOT EXISTS fournisseur_facture_lignes (
      idligne INT AUTO_INCREMENT PRIMARY KEY,
      idfacture INT NOT NULL,
      idprod INT NULL,
      designation VARCHAR(150) NOT NULL,
      quantite DECIMAL(14,3) NOT NULL,
      prix_unitaire DECIMAL(15,2) NOT NULL,
      total DECIMAL(15,2) NOT NULL,
      INDEX idx_ffl_facture (idfacture)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
