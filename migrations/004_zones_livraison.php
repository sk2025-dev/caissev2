<?php
// Migration 004 : lieux et tarifs de livraison (Abidjan par commune et quartier, intérieur du pays par ville, extérieur) + champs de la fiche de livraison.
return function (Migrator $m) {
    $db = $m->db;
    $db->exec("CREATE TABLE IF NOT EXISTS zones_livraison (
      idzone INT AUTO_INCREMENT PRIMARY KEY,
      zone VARCHAR(10) NOT NULL,
      commune VARCHAR(80) NOT NULL DEFAULT '',
      nom VARCHAR(100) NOT NULL,
      prix DECIMAL(15,2) NOT NULL DEFAULT 0,
      delai VARCHAR(40) NOT NULL DEFAULT '',
      actif TINYINT NOT NULL DEFAULT 1,
      supp TINYINT NOT NULL DEFAULT 0,
      idenr INT NULL, dateenr DATETIME NULL,
      idmodif INT NULL, datemodif DATETIME NULL,
      INDEX idx_zone_liv (zone, actif, supp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->ensureColumn('commandes', 'zone', "VARCHAR(10) NOT NULL DEFAULT 'abidjan'");
    $m->ensureColumn('commandes', 'idzone', 'INT NULL');
    $m->ensureColumn('commandes', 'commune', "VARCHAR(80) NOT NULL DEFAULT ''");
    $m->ensureColumn('commandes', 'lieu', "VARCHAR(120) NOT NULL DEFAULT ''");
    $m->ensureColumn('commandes', 'frais_tarif', 'DECIMAL(15,2) NULL');
    $m->ensureColumn('commandes', 'receptionnaire', "VARCHAR(120) NOT NULL DEFAULT ''");
    $m->ensureColumn('commandes', 'observations', "VARCHAR(300) NOT NULL DEFAULT ''");

    if ((int)$db->query('SELECT COUNT(*) FROM zones_livraison')->fetchColumn() > 0) return;
    // Grille de départ INDICATIVE (FCFA) : à ajuster par le gérant dans « Tarifs de livraison ».
    $abidjan = [
        'Plateau' => [1000, 'Le jour même', ['Plateau centre']],
        'Cocody' => [1500, 'Le jour même', ['Cocody centre', 'Deux-Plateaux', 'Riviera Golf', 'Riviera Palmeraie', 'Riviera Attoban', 'Riviera Bonoumin', 'Angré', 'Blockhaus', 'Danga', 'Saint-Jean', 'Ambassades', 'Mermoz', 'Vallons']],
        'Marcory' => [1000, 'Le jour même', ['Zone 4', 'Biétry', 'Marcory Résidentiel', 'Anoumabo', 'Remblais']],
        'Treichville' => [1000, 'Le jour même', ['Treichville centre', 'Arras', 'Avenue 16', 'Biafra']],
        'Koumassi' => [1500, 'Le jour même', ['Koumassi centre', 'Grand Campement', 'Sicogi', 'Prodomo', 'Soweto']],
        'Port-Bouët' => [2000, 'Le jour même', ['Port-Bouët centre', 'Vridi', 'Gonzagueville', 'Adjouffou', 'Aéroport', 'Jean-Folly']],
        'Adjamé' => [1000, 'Le jour même', ['Adjamé centre', 'Williamsville', 'Liberté', '220 Logements', 'Bracodi']],
        'Attécoubé' => [1500, 'Le jour même', ['Attécoubé centre', 'Locodjoro', 'Agban', 'Abattoir']],
        'Yopougon' => [2000, 'Le jour même', ['Yopougon Niangon', 'Yopougon Selmer', 'Yopougon Sicogi', 'Yopougon Maroc', 'Yopougon Toit Rouge', 'Yopougon Millionnaire', 'Yopougon Siporex', 'Yopougon Andokoi', 'Yopougon Wassakara']],
        'Abobo' => [2000, 'Le jour même', ['Abobo Gare', 'Abobo PK18', 'Abobo Sagbé', 'Abobo Avocatier', 'Abobo Anador', 'Abobo Samaké', 'Abobo Belleville', 'Abobo Dokui']],
        'Bingerville' => [2500, '24 h', ['Bingerville centre', 'Akandjé']],
        'Anyama' => [3000, '24 h', ['Anyama centre']],
        'Songon' => [3000, '24 h', ['Songon centre']],
    ];
    $ins = $db->prepare('INSERT INTO zones_livraison (zone, commune, nom, prix, delai, dateenr) VALUES (?,?,?,?,?,?)');
    $now = gmdate('Y-m-d H:i:s');
    foreach ($abidjan as $commune => $x) foreach ($x[2] as $q) $ins->execute(['abidjan', $commune, $q, $x[0], $x[1], $now]);
    $interieur = [['Grand-Bassam', 3000, '24 h'], ['Dabou', 3000, '24 h'], ['Jacqueville', 4000, '24 h'], ['Agboville', 4000, '24 h'], ['Adzopé', 4500, '24 à 48 h'], ['Aboisso', 5000, '24 à 48 h'], ['Yamoussoukro', 5000, '24 à 48 h'], ['Dimbokro', 5500, '24 à 48 h'],
        ['Divo', 5500, '24 à 48 h'], ['Bouaké', 6000, '24 à 48 h'], ['Abengourou', 6500, '48 h'], ['Gagnoa', 6500, '48 h'], ['Daloa', 7000, '48 h'], ['Sassandra', 7500, '48 à 72 h'], ['Soubré', 7500, '48 à 72 h'], ['San-Pédro', 8000, '48 à 72 h'],
        ['Man', 8000, '48 à 72 h'], ['Bondoukou', 8500, '48 à 72 h'], ['Séguéla', 8500, '48 à 72 h'], ['Korhogo', 9000, '48 à 72 h'], ['Ferkessédougou', 9500, '48 à 72 h'], ['Odienné', 10000, '72 h']];
    foreach ($interieur as $x) $ins->execute(['interieur', '', $x[0], $x[1], $x[2], $now]);
};
