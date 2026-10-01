-- Migration 000 : schéma complet de l'application de caisse (MySQL 5.7+ / MariaDB 10.3+, InnoDB, utf8mb4).

-- ---------- Utilisateurs et sécurité ----------
CREATE TABLE IF NOT EXISTS table_gpe_users (
  idgpe INT AUTO_INCREMENT PRIMARY KEY,
  coden VARCHAR(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id_user INT AUTO_INCREMENT PRIMARY KEY,
  nomag VARCHAR(100) NOT NULL,
  prenom VARCHAR(100) NOT NULL DEFAULT '',
  emailag VARCHAR(150) NOT NULL,
  telag VARCHAR(50) NOT NULL DEFAULT '',
  pass VARCHAR(255) NOT NULL,
  gpe INT NOT NULL,
  user_status TINYINT NOT NULL DEFAULT 1,
  photo_user VARCHAR(255) NOT NULL DEFAULT '',
  dateenr DATETIME NULL,
  datemodify DATETIME NULL,
  INDEX idx_users_email (emailag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS table_histoconnexion (
  idhisto INT AUTO_INCREMENT PRIMARY KEY,
  ipaddress VARCHAR(64) NULL,
  user_email VARCHAR(150) NULL,
  datecon DATETIME NULL,
  statconn TINYINT NOT NULL DEFAULT 0,
  INDEX idx_histo_email_date (user_email, datecon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(150) NOT NULL,
  ip VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_attempts (email, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(20) NOT NULL,
  entity VARCHAR(40) NOT NULL,
  entity_id INT NULL,
  detail TEXT NULL,
  ip VARCHAR(64) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_audit_entity (entity, entity_id),
  INDEX idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  cle VARCHAR(60) NOT NULL PRIMARY KEY,
  valeur TEXT NULL,
  updated_at DATETIME NULL,
  updated_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mail_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_at DATETIME NOT NULL,
  type VARCHAR(20) NOT NULL,
  destinataires VARCHAR(500) NOT NULL DEFAULT '',
  sujet VARCHAR(200) NOT NULL DEFAULT '',
  statut VARCHAR(12) NOT NULL,
  nb_alertes INT NOT NULL DEFAULT 0,
  erreur VARCHAR(500) NOT NULL DEFAULT '',
  INDEX idx_maillog_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS export_jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  format VARCHAR(10) NOT NULL,
  params TEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'en_attente',
  progress TINYINT NOT NULL DEFAULT 0,
  filename VARCHAR(200) NOT NULL DEFAULT '',
  stored VARCHAR(100) NOT NULL DEFAULT '',
  size INT NOT NULL DEFAULT 0,
  error VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  INDEX idx_export_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compteurs de numérotation sans trou (ventes, bons de réception…)
CREATE TABLE IF NOT EXISTS compteurs (
  cle VARCHAR(40) NOT NULL PRIMARY KEY,
  valeur INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Catalogue ----------
CREATE TABLE IF NOT EXISTS categories (
  idcat INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(100) NOT NULL,
  couleur VARCHAR(9) NOT NULL DEFAULT '',
  ordre INT NOT NULL DEFAULT 0,
  supp TINYINT NOT NULL DEFAULT 0,
  idenr INT NULL, dateenr DATETIME NULL,
  idmodif INT NULL, datemodif DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produits (
  idprod INT AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(10) NOT NULL DEFAULT 'produit',
  nom VARCHAR(150) NOT NULL,
  sku VARCHAR(50) NOT NULL DEFAULT '',
  code_barres VARCHAR(50) NOT NULL DEFAULT '',
  idcat INT NULL,
  unite VARCHAR(20) NOT NULL DEFAULT 'pièce',
  prix_vente DECIMAL(15,2) NOT NULL DEFAULT 0,
  prix_achat DECIMAL(15,2) NOT NULL DEFAULT 0,
  tva_taux DECIMAL(5,2) NOT NULL DEFAULT 0,
  stockable TINYINT NOT NULL DEFAULT 1,
  stock_qty DECIMAL(14,3) NOT NULL DEFAULT 0,
  seuil_alerte DECIMAL(14,3) NOT NULL DEFAULT 0,
  actif TINYINT NOT NULL DEFAULT 1,
  description VARCHAR(500) NOT NULL DEFAULT '',
  image VARCHAR(255) NOT NULL DEFAULT '',
  supp TINYINT NOT NULL DEFAULT 0,
  idenr INT NULL, dateenr DATETIME NULL,
  idmodif INT NULL, datemodif DATETIME NULL,
  INDEX idx_prod_cat (idcat),
  INDEX idx_prod_sku (sku),
  INDEX idx_prod_barres (code_barres),
  INDEX idx_prod_supp (supp, actif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Tiers ----------
CREATE TABLE IF NOT EXISTS clients (
  idclient INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  telephone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  adresse VARCHAR(250) NOT NULL DEFAULT '',
  plafond_credit DECIMAL(15,2) NOT NULL DEFAULT 0,
  solde DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  supp TINYINT NOT NULL DEFAULT 0,
  idenr INT NULL, dateenr DATETIME NULL,
  idmodif INT NULL, datemodif DATETIME NULL,
  INDEX idx_client_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fournisseurs (
  idfour INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(150) NOT NULL,
  contact VARCHAR(150) NOT NULL DEFAULT '',
  telephone VARCHAR(40) NOT NULL DEFAULT '',
  email VARCHAR(150) NOT NULL DEFAULT '',
  adresse VARCHAR(250) NOT NULL DEFAULT '',
  solde DECIMAL(15,2) NOT NULL DEFAULT 0,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  supp TINYINT NOT NULL DEFAULT 0,
  idenr INT NULL, dateenr DATETIME NULL,
  idmodif INT NULL, datemodif DATETIME NULL,
  INDEX idx_four_nom (nom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Caisse ----------
CREATE TABLE IF NOT EXISTS caisses (
  idcaisse INT AUTO_INCREMENT PRIMARY KEY,
  nom VARCHAR(80) NOT NULL,
  actif TINYINT NOT NULL DEFAULT 1,
  supp TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS modes_paiement (
  code VARCHAR(20) NOT NULL PRIMARY KEY,
  libelle VARCHAR(60) NOT NULL,
  type VARCHAR(10) NOT NULL DEFAULT 'autre',
  actif TINYINT NOT NULL DEFAULT 1,
  ordre INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sessions_caisse (
  idsession INT AUTO_INCREMENT PRIMARY KEY,
  idcaisse INT NOT NULL,
  iduser INT NOT NULL,
  ouverture_at DATETIME NOT NULL,
  fond_initial DECIMAL(15,2) NOT NULL DEFAULT 0,
  cloture_at DATETIME NULL,
  compte_especes DECIMAL(15,2) NULL,
  attendu_especes DECIMAL(15,2) NULL,
  ecart DECIMAL(15,2) NULL,
  notes VARCHAR(500) NOT NULL DEFAULT '',
  statut VARCHAR(10) NOT NULL DEFAULT 'ouverte',
  INDEX idx_sess_user (iduser, statut),
  INDEX idx_sess_caisse (idcaisse, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS caisse_operations (
  idop INT AUTO_INCREMENT PRIMARY KEY,
  idsession INT NOT NULL,
  type VARCHAR(10) NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  motif VARCHAR(200) NOT NULL DEFAULT '',
  iduser INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_op_session (idsession)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Ventes ----------
CREATE TABLE IF NOT EXISTS ventes (
  idvente INT AUTO_INCREMENT PRIMARY KEY,
  numero VARCHAR(30) NOT NULL,
  idsession INT NOT NULL,
  idcaisse INT NOT NULL,
  iduser INT NOT NULL,
  idclient INT NULL,
  date_vente DATETIME NOT NULL,
  sous_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  remise DECIMAL(15,2) NOT NULL DEFAULT 0,
  tva DECIMAL(15,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  paye DECIMAL(15,2) NOT NULL DEFAULT 0,
  rendu DECIMAL(15,2) NOT NULL DEFAULT 0,
  reste DECIMAL(15,2) NOT NULL DEFAULT 0,
  cout_total DECIMAL(15,2) NOT NULL DEFAULT 0,
  statut VARCHAR(10) NOT NULL DEFAULT 'validee',
  note VARCHAR(300) NOT NULL DEFAULT '',
  annule_at DATETIME NULL,
  annule_par INT NULL,
  annule_motif VARCHAR(200) NOT NULL DEFAULT '',
  cle VARCHAR(40) NULL,
  UNIQUE KEY uq_vente_numero (numero),
  UNIQUE KEY uq_vente_cle (cle),
  INDEX idx_vente_date (statut, date_vente),
  INDEX idx_vente_session (idsession),
  INDEX idx_vente_client (idclient),
  INDEX idx_vente_user (iduser, date_vente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vente_lignes (
  idligne INT AUTO_INCREMENT PRIMARY KEY,
  idvente INT NOT NULL,
  idprod INT NOT NULL,
  designation VARCHAR(150) NOT NULL,
  type VARCHAR(10) NOT NULL DEFAULT 'produit',
  quantite DECIMAL(14,3) NOT NULL,
  prix_unitaire DECIMAL(15,2) NOT NULL,
  remise DECIMAL(15,2) NOT NULL DEFAULT 0,
  tva_taux DECIMAL(5,2) NOT NULL DEFAULT 0,
  total DECIMAL(15,2) NOT NULL,
  cout_unitaire DECIMAL(15,2) NOT NULL DEFAULT 0,
  INDEX idx_ligne_vente (idvente),
  INDEX idx_ligne_prod (idprod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vente_paiements (
  idpaie INT AUTO_INCREMENT PRIMARY KEY,
  idvente INT NOT NULL,
  mode VARCHAR(20) NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  reference VARCHAR(80) NOT NULL DEFAULT '',
  INDEX idx_paie_vente (idvente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS paniers_attente (
  idpanier INT AUTO_INCREMENT PRIMARY KEY,
  iduser INT NOT NULL,
  libelle VARCHAR(80) NOT NULL DEFAULT '',
  contenu MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_panier_user (iduser)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Stock ----------
CREATE TABLE IF NOT EXISTS mouvements_stock (
  idmvt INT AUTO_INCREMENT PRIMARY KEY,
  idprod INT NOT NULL,
  type VARCHAR(20) NOT NULL,
  quantite DECIMAL(14,3) NOT NULL,
  stock_apres DECIMAL(14,3) NOT NULL,
  cout_unitaire DECIMAL(15,2) NOT NULL DEFAULT 0,
  ref_type VARCHAR(20) NOT NULL DEFAULT '',
  ref_id INT NULL,
  motif VARCHAR(200) NOT NULL DEFAULT '',
  iduser INT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_mvt_prod (idprod, created_at),
  INDEX idx_mvt_date (created_at),
  INDEX idx_mvt_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approvisionnements (
  idappro INT AUTO_INCREMENT PRIMARY KEY,
  idfour INT NOT NULL,
  numero VARCHAR(30) NOT NULL,
  numero_bon VARCHAR(60) NOT NULL DEFAULT '',
  date_appro DATE NOT NULL,
  total DECIMAL(15,2) NOT NULL DEFAULT 0,
  paye DECIMAL(15,2) NOT NULL DEFAULT 0,
  reste DECIMAL(15,2) NOT NULL DEFAULT 0,
  note VARCHAR(300) NOT NULL DEFAULT '',
  statut VARCHAR(10) NOT NULL DEFAULT 'recue',
  iduser INT NULL,
  created_at DATETIME NOT NULL,
  UNIQUE KEY uq_appro_numero (numero),
  INDEX idx_appro_four (idfour),
  INDEX idx_appro_date (date_appro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS appro_lignes (
  idligne INT AUTO_INCREMENT PRIMARY KEY,
  idappro INT NOT NULL,
  idprod INT NOT NULL,
  designation VARCHAR(150) NOT NULL,
  quantite DECIMAL(14,3) NOT NULL,
  cout_unitaire DECIMAL(15,2) NOT NULL,
  total DECIMAL(15,2) NOT NULL,
  INDEX idx_aligne_appro (idappro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reglements (
  idreg INT AUTO_INCREMENT PRIMARY KEY,
  tiers_type VARCHAR(12) NOT NULL,
  tiers_id INT NOT NULL,
  montant DECIMAL(15,2) NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'especes',
  reference VARCHAR(80) NOT NULL DEFAULT '',
  note VARCHAR(200) NOT NULL DEFAULT '',
  idsession INT NULL,
  iduser INT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_reg_tiers (tiers_type, tiers_id),
  INDEX idx_reg_session (idsession)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaires (
  idinv INT AUTO_INCREMENT PRIMARY KEY,
  date_inv DATE NOT NULL,
  note VARCHAR(300) NOT NULL DEFAULT '',
  nb_lignes INT NOT NULL DEFAULT 0,
  nb_ecarts INT NOT NULL DEFAULT 0,
  valeur_ecart DECIMAL(15,2) NOT NULL DEFAULT 0,
  iduser INT NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS inventaire_lignes (
  idligne INT AUTO_INCREMENT PRIMARY KEY,
  idinv INT NOT NULL,
  idprod INT NOT NULL,
  designation VARCHAR(150) NOT NULL,
  attendu DECIMAL(14,3) NOT NULL,
  compte DECIMAL(14,3) NOT NULL,
  ecart DECIMAL(14,3) NOT NULL,
  cout_unitaire DECIMAL(15,2) NOT NULL DEFAULT 0,
  INDEX idx_iligne_inv (idinv)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
