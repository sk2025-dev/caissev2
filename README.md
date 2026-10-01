# Caisse

Application de **caisse, gestion des produits et services, et gestion de stock** pour un commerce généraliste.
Interface web moderne (Vue 3, application monopage), API JSON en PHP/PDO, base MySQL, migrations versionnées.
Aucune dépendance côté serveur (pas de Composer) : les exports Excel/PDF, l'envoi SMTP et la sauvegarde SQL sont écrits sans bibliothèque.

## Fonctions

| Domaine | Ce que fait l'application |
|---|---|
| **Caisse** | Écran tactile plein format : grille de produits par catégorie, recherche, lecture de code-barres (douchette), panier, remise, paiement **mixte** (espèces, Orange/MTN/Moov/Wave, carte, chèque), rendu de monnaie, **vente à crédit** pour un client, ticket imprimable (58 ou 80 mm), **ventes en attente**, raccourcis `F2` recherche · `F4` encaisser · `F8` mise en attente |
| **Sessions de caisse** | Ouverture avec fond de caisse, entrées/sorties d'espèces motivées, **clôture avec comptage**, écart calculé (note obligatoire au-delà de la tolérance), rapport de caisse imprimable |
| **Catalogue** | Produits et services, catégories, TVA incluse, prix d'achat (coût moyen), code-barres, image, seuil d'alerte, activation/désactivation |
| **Stock** | Niveaux, mouvements tracés (réception, vente, annulation, ajustement, perte, inventaire), **inventaire** avec régularisation des écarts, stock jamais négatif (réglable) |
| **Achats** | Fournisseurs, réceptions de marchandises (mise à jour du stock et du **coût moyen pondéré**), dettes fournisseurs et paiements partiels |
| **Clients** | Fichier clients, plafond de crédit, dettes et règlements partiels (encaissés dans la caisse ouverte si espèces) |
| **Pilotage** | Tableau de bord (chiffre d'affaires, marge, créances, top produits, répartitions, affluence par heure, performance des caissiers), alertes (ruptures, stocks bas, créances anciennes, écarts de caisse), recherche globale |
| **Exports** | Ventes et stock en Excel, CSV, PDF — **générés en arrière-plan**, suivis dans « Mes exports » ; sauvegarde complète de la base (`.sql.gz`) |
| **Configuration** *(super administrateur)* | Entreprise et logo, thème (6 palettes, mode sombre), durée d'utilisation (licence), règles de caisse et ticket, e-mail d'alertes (SMTP), sauvegarde |

### Rôles
| Rôle | Accès |
|---|---|
| **Caissier** | Caisse, ses propres ventes et sessions, clients, consultation du catalogue et du stock |
| **Magasinier** | Catalogue, stock, inventaires, fournisseurs, réceptions |
| **Gérant (admin)** | Tout, dont annulation de vente, remises/prix libres, exports, utilisateurs et caisses |
| **Super administrateur** | Gérant + configuration, licence et sauvegarde de la base |

### Règles de gestion garanties par le serveur
- Totaux, TVA, remises et rendu sont **recalculés côté serveur** (l'écran ne fait que proposer).
- Le stock bouge **uniquement par des mouvements tracés** ; les lignes de produits sont verrouillées en base (`SELECT … FOR UPDATE`) : deux ventes simultanées de la dernière unité → une seule réussit.
- Une vente a une **clé d'idempotence** : un double clic ou un renvoi réseau ne crée jamais de doublon.
- Numérotation des tickets continue, sans trou ni doublon.
- Une vente ne s'annule que tant que sa session de caisse est ouverte (ensuite, régularisez par un ajustement de stock).
- Sécurité : sessions durcies, jeton CSRF, mots de passe hachés, requêtes préparées, droits vérifiés à chaque route, journal d'audit.

## Installation locale (développement)

Prérequis : PHP 7.4+ (`pdo_mysql`, `mbstring`), MySQL/MariaDB, Node 18+.

```bash
# 1. Base de données et configuration
mysql -u root -e "CREATE DATABASE caisse CHARACTER SET utf8mb4"
cp .env.example .env            # renseigner DB_NAME, DB_USER, DB_PASS
php bin/migrate.php             # crée le schéma et les données de base
php bin/create-admin.php super@exemple.com "Mon Nom" "motdepasse8+" --super

# 2. (facultatif) données de démonstration : catalogue, clients, 3 semaines de ventes
php bin/seed-demo.php           # comptes : gerant@demo.local, caissier@demo.local, magasin@demo.local (mot de passe demo1234)

# 3. Lancer
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8000 -t .      # http://127.0.0.1:8000/app/
```
`PHP_CLI_SERVER_WORKERS` permet aux exports de se générer pendant que l'interface continue d'interroger l'API.

### Développer l'interface
```bash
cd frontend && npm install
npm run dev        # http://localhost:5173 (proxy /api vers le port 8000)
npm run build      # produit ../app/ (versionné : le serveur n'a pas besoin de Node)
```

### Tests
```bash
php tests/api_test.php     # ~170 vérifications sur une base dédiée « caisse_test » (jamais la vôtre)
```
Variables : `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_USER`, `TEST_DB_PASS`. Le test couvre droits, catalogue, stock, achats, ventes
(crédit, paiements mixtes, concurrence, idempotence, annulation), sessions de caisse, tableau de bord, exports, e-mail (faux serveur SMTP),
licence, sauvegarde **et restauration** dans une base vierge.

## Hébergement (mutualisé cPanel, VPS…)

Un seul dossier à publier, adresses relatives : fonctionne à la racine d'un domaine ou dans un sous-dossier (`https://exemple.com/caisse/`).

```
<dossier du site>/
├── index.php  .htaccess  .env     la racine redirige vers /app/ ; .env est créé sur le serveur
├── app/   api/                    interface compilée et API (appelée en interne)
├── connexion/  doc/  exports/     socle, images/logo téléversés, fichiers générés
└── migrations/ bin/               base de données et scripts en ligne de commande
```

1. **Prérequis** : Apache (`AllowOverride All`) ou nginx + PHP-FPM ; PHP 7.4+ avec `pdo_mysql`, `mbstring`, `ctype`, `json`, `zlib`, `openssl` ; MySQL 5.7+/MariaDB 10.3+ en `utf8mb4` ; HTTPS conseillé.
2. **Base** : créer la base et un utilisateur avec tous les privilèges (cPanel → *Bases de données MySQL*).
3. **Fichiers** : publier `app/ api/ connexion/ doc/ exports/ migrations/ bin/ index.php .htaccess .env.example`.
   *Ne pas* publier `frontend/`, `tests/`, `node_modules/`, `.git/`, ni votre `.env` local.
   Sans FTP : « Gestionnaire de fichiers » de cPanel (envoyer le `.zip` puis *Extraire*), ou *Git™ Version Control* de cPanel (cloner le dépôt, puis *Deploy HEAD commit*), ou `git pull` en SSH.
4. **Configuration** : copier `.env.example` en `.env` **sur le serveur**, renseigner `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `PASSWORD_HASHING=1`.
5. **Droits d'écriture** sur `exports/`, `doc/logo/` et `doc/produits/` (755, ou 775 selon l'hébergeur).
6. **Migrations et premier compte** (SSH ou *Terminal* cPanel) :
   ```bash
   php bin/migrate.php
   php bin/create-admin.php vous@exemple.com "Votre Nom" "motdepasse8+" --super
   ```
   Sans accès terminal, planifier une tâche cron ponctuelle exécutant `php /chemin/vers/caisse/bin/migrate.php`, puis la supprimer.
7. **Vérifier** : ouvrir `https://votre-domaine/…/`, se connecter, puis *Configuration* : nom de l'entreprise, logo, TVA par défaut, ticket, e-mail d'alertes.
8. **Alertes e-mail (facultatif)** : tâche cron quotidienne `0 7 * * * php /chemin/vers/caisse/bin/send-alerts.php`. Sans cron, le résumé part automatiquement à la première connexion de la journée.
9. **Sécurité avant ouverture** : changer tous les mots de passe de démonstration, vérifier que `https://…/.env`, `…/migrations/` et `…/connexion/` renvoient 403/404, activer HTTPS.

**Mise à jour** : sauvegarder (Configuration → Sauvegarde), remplacer les dossiers publiés (sauf `.env`, `doc/` et `exports/`), puis `php bin/migrate.php`.

## Structure
```
api/            routeur (index.php), ressources CRUD déclaratives (resources.php), logique métier (lib/)
connexion/      accès base, réglages, sécurité (sessions, CSRF), variables d'environnement
migrations/     schéma SQL puis migrations .sql/.php appliquées dans l'ordre (table de suivi)
frontend/src/   Vue 3 : views/ (écrans), components/ (composants), resources.js (écrans CRUD déclaratifs)
bin/            migrate · create-admin · seed-demo · send-alerts
tests/          test d'intégration HTTP complet + faux serveur SMTP
```

© Dav'Holding Group
