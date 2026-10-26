<?php
// Fiche produit détaillée (marque, origine, caractéristiques…), galerie de photos et avis clients (Facebook, Google, TikTok, SMS…).
return function (Migrator $m) {
    foreach (['marque' => 80, 'modele' => 80, 'origine' => 80, 'couleur' => 60, 'dimensions' => 80, 'poids' => 40, 'garantie' => 80] as $col => $n) {
        $m->ensureColumn('produits', $col, "VARCHAR($n) NOT NULL DEFAULT ''");
    }
    $m->ensureColumn('produits', 'caracteristiques', 'TEXT NULL');
    $m->db->exec('ALTER TABLE produits MODIFY description TEXT NULL');   // description longue (500 caractères ne suffisaient pas)

    $m->db->exec("CREATE TABLE IF NOT EXISTS produit_photos (
        idphoto INT AUTO_INCREMENT PRIMARY KEY,
        idprod INT NOT NULL,
        fichier VARCHAR(255) NOT NULL,
        legende VARCHAR(150) NOT NULL DEFAULT '',
        ordre INT NOT NULL DEFAULT 0,
        idenr INT NULL, dateenr DATETIME NULL,
        INDEX idx_photo_prod (idprod, ordre)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // La photo actuelle de chaque produit devient la première de sa galerie
    $m->db->exec("INSERT INTO produit_photos (idprod, fichier, ordre, dateenr)
        SELECT p.idprod, p.image, 0, COALESCE(p.datemodif, p.dateenr, UTC_TIMESTAMP()) FROM produits p
        WHERE p.image <> '' AND NOT EXISTS (SELECT 1 FROM produit_photos x WHERE x.idprod = p.idprod AND x.fichier = p.image)");

    $m->db->exec("CREATE TABLE IF NOT EXISTS avis (
        idavis INT AUTO_INCREMENT PRIMARY KEY,
        idprod INT NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'magasin',
        note TINYINT NOT NULL DEFAULT 5,
        auteur VARCHAR(120) NOT NULL DEFAULT '',
        telephone VARCHAR(40) NOT NULL DEFAULT '',
        date_avis DATE NULL,
        commentaire TEXT NULL,
        lien VARCHAR(300) NOT NULL DEFAULT '',
        capture VARCHAR(255) NOT NULL DEFAULT '',
        reponse TEXT NULL,
        supp TINYINT NOT NULL DEFAULT 0,
        idenr INT NULL, dateenr DATETIME NULL,
        idmodif INT NULL, datemodif DATETIME NULL,
        INDEX idx_avis_prod (idprod, supp),
        INDEX idx_avis_source (source, date_avis)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
};
