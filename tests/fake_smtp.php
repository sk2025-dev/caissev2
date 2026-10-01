<?php
/**
 * Faux serveur SMTP pour les tests :  php tests/fake_smtp.php <port> <fichier-de-sortie>
 * Accepte EHLO / AUTH LOGIN|PLAIN / MAIL / RCPT / DATA / QUIT et écrit chaque message reçu (une ligne JSON) dans le fichier.
 */
$port = (int)$argv[1]; $out = $argv[2];
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "$errstr\n"); exit(1); }
$fin = time() + 90;
while (time() < $fin) {
    $c = @stream_socket_accept($srv, 1);
    if (!$c) continue;
    $msg = ['from' => null, 'rcpt' => [], 'auth' => null, 'data' => ''];
    fwrite($c, "220 fake.smtp ready\r\n");
    while (($l = fgets($c)) !== false) {
        $cmd = strtoupper(substr($l, 0, 4));
        if ($cmd === 'EHLO') fwrite($c, "250-fake.smtp\r\n250-AUTH LOGIN PLAIN\r\n250 8BITMIME\r\n");
        elseif (trim($l) === 'AUTH LOGIN') { fwrite($c, "334 VXNlcm5hbWU6\r\n"); $u = base64_decode(trim(fgets($c))); fwrite($c, "334 UGFzc3dvcmQ6\r\n"); $p = base64_decode(trim(fgets($c))); $msg['auth'] = [$u, $p]; fwrite($c, "235 ok\r\n"); }
        elseif ($cmd === 'MAIL') { $msg['from'] = trim(substr($l, 10), " <>\r\n"); fwrite($c, "250 ok\r\n"); }
        elseif ($cmd === 'RCPT') { $msg['rcpt'][] = trim(substr($l, 8), " <>\r\n"); fwrite($c, "250 ok\r\n"); }
        elseif ($cmd === 'DATA') {
            fwrite($c, "354 go\r\n");
            while (($d = fgets($c)) !== false && rtrim($d, "\r\n") !== '.') $msg['data'] .= $d;
            fwrite($c, "250 queued\r\n");
        }
        elseif ($cmd === 'QUIT') { fwrite($c, "221 bye\r\n"); break; }
        else fwrite($c, "250 ok\r\n");
    }
    fclose($c);
    if ($msg['data'] !== '') file_put_contents($out, json_encode($msg, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}
