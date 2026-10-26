<?php
/** Réinitialise le mot de passe d'un compte :  php bin/reset-password.php email "nouveaumotdepasse"   (réactive le compte et efface les tentatives échouées) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/conn.php';

if ($argc < 3) { fwrite(STDERR, "Usage : php bin/reset-password.php email \"nouveaumotdepasse\"\n"); exit(1); }
list(, $email, $pwd) = $argv;
if (strlen($pwd) < 8) { fwrite(STDERR, "Mot de passe < 8 caracteres.\n"); exit(1); }

$st = $bdd->prepare('SELECT COUNT(*) FROM users WHERE emailag = ?');
$st->execute([$email]);
if ((int)$st->fetchColumn() === 0) { fwrite(STDERR, "Aucun compte avec cet e-mail.\n"); exit(1); }

$bdd->prepare('UPDATE users SET pass = ?, user_status = 1 WHERE emailag = ?')->execute([pwd_pour_stockage($pwd), $email]);
try { $bdd->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]); } catch (PDOException $e) { /* table absente : migration 004 non appliquée */ }
echo "Mot de passe reinitialise : $email\n";
