<?php
// Confidentialité : une réception de marchandises n'est plus rattachée à un fournisseur. Ce qu'on doit à un fournisseur
// passe par ses factures, saisies sur sa fiche. Les réceptions déjà enregistrées y sont reprises en factures (même date,
// même montant, n° de bon) ; leur ancien rattachement reste en base, sans plus être affiché nulle part.
return function (Migrator $m) {
    $m->db->exec('ALTER TABLE approvisionnements MODIFY idfour INT NULL');
    $m->db->exec("CREATE TABLE IF NOT EXISTS fournisseur_factures (
      idfacture INT AUTO_INCREMENT PRIMARY KEY,
      idfour INT NOT NULL,
      reference VARCHAR(60) NOT NULL DEFAULT '',
      date_facture DATE NOT NULL,
      montant DECIMAL(15,2) NOT NULL,
      note VARCHAR(300) NOT NULL DEFAULT '',
      supp TINYINT(1) NOT NULL DEFAULT 0,
      iduser INT NULL,
      created_at DATETIME NOT NULL,
      INDEX idx_ffact_four (idfour, date_facture)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if ((int)$m->db->query('SELECT COUNT(*) FROM fournisseur_factures')->fetchColumn() === 0) {
        $n = $m->db->exec("INSERT INTO fournisseur_factures (idfour, reference, date_facture, montant, note, iduser, created_at)
            SELECT idfour, numero_bon, date_appro, total, 'Reprise de l''historique', iduser, created_at FROM approvisionnements WHERE idfour IS NOT NULL AND total > 0 ORDER BY idappro");
        echo "    + $n facture(s) reprise(s) des réceptions\n";
    }
};
