<?php
/** Réinitialise le mot de passe d'un compte :  php bin/reset-password.php email "nouveaumotdepasse"   (réactive le compte et efface les tentatives échouées) */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../connexion/security.php';
require_once __DIR__ . '/../connexion/conn.php';
require_once __DIR__ . '/../api/lib/journal.php';

if ($argc < 3) { fwrite(STDERR, "Usage : php bin/reset-password.php email \"nouveaumotdepasse\"\n"); exit(1); }
list(, $email, $pwd) = $argv;
if (strlen($pwd) < 8) { fwrite(STDERR, "Mot de passe < 8 caracteres.\n"); exit(1); }

$st = $bdd->prepare('SELECT id_user FROM users WHERE emailag = ? ORDER BY id_user LIMIT 1');
$st->execute([$email]);
if (!($id = $st->fetchColumn())) { fwrite(STDERR, "Aucun compte avec cet e-mail.\n"); exit(1); }

$bdd->prepare('UPDATE users SET pass = ?, user_status = 1 WHERE emailag = ?')->execute([pwd_pour_stockage($pwd), $email]);
try { $bdd->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]); } catch (PDOException $e) { /* table absente : migration 004 non appliquée */ }
journaliser($bdd, null, 'update', 'utilisateurs', (int)$id, "Mot de passe de « $email » réinitialisé en ligne de commande", ['pass' => ['••••', '••••']], 'Ligne de commande');
echo "Mot de passe reinitialise : $email\n";
