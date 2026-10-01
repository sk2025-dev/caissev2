-- Commandes clients et livraisons. Le stock et la caisse ne bougent qu'à la remise (vente créée à ce moment-là).
CREATE TABLE IF NOT EXISTS commandes (
  idcommande INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL,
  mode VARCHAR(12) NOT NULL DEFAULT 'livraison',
  statut VARCHAR(14) NOT NULL DEFAULT 'nouvelle',
  idclient INT NULL,
  client_nom VARCHAR(150) NOT NULL DEFAULT '',
  client_tel VARCHAR(40) NOT NULL DEFAULT '',
  adresse VARCHAR(300) NOT NULL DEFAULT '',
  date_prevue DATETIME NULL,
  frais_livraison DECIMAL(15,2) NOT NULL DEFAULT 0,
  sous_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  livreur VARCHAR(80) NOT NULL DEFAULT '',
  note VARCHAR(300) NOT NULL DEFAULT '',
  iduser INT NOT NULL,
  idvente INT NULL,
  annule_motif VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  livree_at DATETIME NULL,
  UNIQUE KEY uq_commande_numero (numero),
  INDEX idx_commande_statut (statut, date_prevue),
  INDEX idx_commande_client (idclient)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commande_lignes (
  idligne INT AUTO_INCREMENT PRIMARY KEY,
  idcommande INT NOT NULL,
  idprod INT NOT NULL,
  designation VARCHAR(150) NOT NULL,
  unite VARCHAR(20) NOT NULL DEFAULT 'pièce',
  quantite DECIMAL(14,3) NOT NULL,
  prix_unitaire DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  INDEX idx_cligne_commande (idcommande)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS commande_historique (
  idhisto INT AUTO_INCREMENT PRIMARY KEY,
  idcommande INT NOT NULL,
  statut VARCHAR(14) NOT NULL,
  iduser INT NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  INDEX idx_chisto_commande (idcommande)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
