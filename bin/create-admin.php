<?php
/** Crée un compte administrateur :  php bin/create-admin.php email "Nom" "motdepasse" [--super]   (--super : super administrateur, accès à la configuration) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/conn.php';

$super = in_array('--super', $argv, true);
$argv = array_values(array_filter($argv, function ($a) { return $a !== '--super'; })); $argc = count($argv);
if ($argc < 4) { fwrite(STDERR, "Usage : php bin/create-admin.php email \"Nom\" \"motdepasse\"\n"); exit(1); }
list(, $email, $nom, $pwd) = $argv;
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pwd) < 8) { fwrite(STDERR, "E-mail invalide ou mot de passe < 8 caracteres.\n"); exit(1); }

$groupe = $super ? 'superadmin' : 'admin';
$st = $bdd->prepare('SELECT idgpe FROM table_gpe_users WHERE coden = ?'); $st->execute([$groupe]);
$gpe = $st->fetchColumn();
if (!$gpe) { fwrite(STDERR, "Groupe '$groupe' absent : lancez d'abord php bin/migrate.php\n"); exit(1); }
$st = $bdd->prepare('SELECT COUNT(*) FROM users WHERE emailag = ?');
$st->execute([$email]);
if ((int)$st->fetchColumn() > 0) { fwrite(STDERR, "Cet e-mail existe deja.\n"); exit(1); }

$bdd->prepare('INSERT INTO users (nomag, prenom, emailag, telag, pass, gpe, user_status, dateenr) VALUES (?,?,?,?,?,?,1,?)')
    ->execute([$nom, '', $email, '', pwd_pour_stockage($pwd), $gpe, gmdate('Y-m-d H:i:s')]);
echo ($super ? "Super administrateur" : "Administrateur") . " cree : $email\n";
