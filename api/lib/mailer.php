<?php
/**
 * Envoi d'e-mails (HTML + texte) sans dépendance : fonction mail() de PHP ou client SMTP (SSL, STARTTLS, AUTH LOGIN/PLAIN).
 */
class Mailer
{
    private $cfg;
    private $sock;
    public $journal = [];   // dialogue SMTP (utile au diagnostic, mot de passe masqué)

    public function __construct(array $cfg) { $this->cfg = $cfg; }

    private static function enc($s) { return '=?UTF-8?B?' . base64_encode($s) . '?='; }

    /**
     * Construit le message MIME multipart/alternative (texte + HTML).
     * $images : images intégrées au HTML, [cid => chemin du fichier], appelées par <img src="cid:...">  -> multipart/related.
     */
    public function build(array $to, $sujet, $html, $texte, array $images = [])
    {
        $fromMail = $this->cfg['expediteur'];
        $fromNom = $this->cfg['expediteur_nom'] ?: 'Caisse';
        $b = 'b_' . bin2hex(random_bytes(8));
        $h = [
            'Date: ' . date('r'),
            'From: ' . self::enc($fromNom) . " <$fromMail>",
            'To: ' . implode(', ', $to),
            'Subject: ' . self::enc($sujet),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . (substr(strrchr($fromMail, '@'), 1) ?: 'caisse.local') . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Caisse',
        ];
        $part = function ($type, $contenu) use ($b) {
            return "--$b\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($contenu)) . "\r\n";
        };
        $alt = $part('text/plain', $texte) . $part('text/html', $html) . "--$b--\r\n";
        $mimes = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        $images = array_filter($images, function ($f) use ($mimes) { return is_file($f) && isset($mimes[strtolower(pathinfo($f, PATHINFO_EXTENSION))]); });
        if (!$images) { $h[] = "Content-Type: multipart/alternative; boundary=\"$b\""; return ['headers' => $h, 'body' => $alt]; }

        $r = 'r_' . bin2hex(random_bytes(8));
        $h[] = "Content-Type: multipart/related; type=\"multipart/alternative\"; boundary=\"$r\"";
        $corps = "--$r\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n" . $alt;
        foreach ($images as $cid => $f) {
            $nom = basename($f);
            $corps .= "--$r\r\nContent-Type: " . $mimes[strtolower(pathinfo($f, PATHINFO_EXTENSION))] . "; name=\"$nom\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$nom\"\r\n\r\n" . chunk_split(base64_encode(file_get_contents($f))) . "\r\n";
        }
        return ['headers' => $h, 'body' => $corps . "--$r--\r\n"];
    }

    public function send(array $to, $sujet, $html, $texte, array $images = [])
    {
        if (!$to) throw new RuntimeException('Aucun destinataire');
        if (!filter_var($this->cfg['expediteur'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Adresse d'expédition invalide");
        $m = $this->build($to, $sujet, $html, $texte, $images);
        if ($this->cfg['transport'] === 'smtp') return $this->smtp($to, $m);
        // Transport « mail() » : l'hébergeur se charge de l'acheminement
        $hdr = array_values(array_filter($m['headers'], function ($l) { return stripos($l, 'To:') !== 0 && stripos($l, 'Subject:') !== 0; }));
        if (!@mail(implode(', ', $to), self::enc($sujet), $m['body'], implode("\r\n", $hdr))) throw new RuntimeException("La fonction mail() du serveur a refusé l'envoi");
        return true;
    }

    /* ---------- SMTP ---------- */

    private function lire()
    {
        $rep = '';
        while (($l = fgets($this->sock, 1024)) !== false) {
            $rep .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') break;
        }
        $this->journal[] = 'S: ' . trim($rep);
        return $rep;
    }

    private function cmd($c, array $attendu, $masque = false)
    {
        fwrite($this->sock, $c . "\r\n");
        $this->journal[] = 'C: ' . ($masque ? '***' : $c);
        $rep = $this->lire();
        if (!in_array((int)substr($rep, 0, 3), $attendu, true)) throw new RuntimeException('Serveur SMTP : ' . trim($rep));
        return $rep;
    }

    private function smtp(array $to, array $m)
    {
        $hote = $this->cfg['smtp_hote']; $port = (int)$this->cfg['smtp_port'] ?: 587; $sec = $this->cfg['smtp_securite'];
        if ($hote === '') throw new RuntimeException('Serveur SMTP non renseigné');
        $ctx = stream_context_create(['ssl' => ['verify_peer' => !in_array($hote, ['127.0.0.1', 'localhost'], true), 'verify_peer_name' => !in_array($hote, ['127.0.0.1', 'localhost'], true)]]);
        $this->sock = @stream_socket_client(($sec === 'ssl' ? 'ssl://' : 'tcp://') . "$hote:$port", $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) throw new RuntimeException("Connexion SMTP impossible ($hote:$port) : $errstr");
        stream_set_timeout($this->sock, 15);
        try {
            $acc = $this->lire();
            if ((int)substr($acc, 0, 3) !== 220) throw new RuntimeException('Serveur SMTP : ' . trim($acc));
            $nom = gethostname() ?: 'caisse';
            $ehlo = $this->cmd("EHLO $nom", [250]);
            if ($sec === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Échec du chiffrement STARTTLS');
                $ehlo = $this->cmd("EHLO $nom", [250]);
            }
            if ($this->cfg['smtp_user'] !== '') {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'LOGIN') === false && stripos($ehlo, 'PLAIN') !== false) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $this->cfg['smtp_user'] . "\0" . $this->cfg['smtp_pass']), [235], true);
                } else {
                    $this->cmd('AUTH LOGIN', [334]);
                    $this->cmd(base64_encode($this->cfg['smtp_user']), [334]);
                    $this->cmd(base64_encode($this->cfg['smtp_pass']), [235], true);
                }
            }
            $this->cmd('MAIL FROM:<' . $this->cfg['expediteur'] . '>', [250]);
            foreach ($to as $d) $this->cmd("RCPT TO:<$d>", [250, 251]);
            $this->cmd('DATA', [354]);
            $data = implode("\r\n", $m['headers']) . "\r\n\r\n" . $m['body'];
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], "\n", $data));   // protection du « . » en début de ligne
            fwrite($this->sock, str_replace("\n", "\r\n", $data) . "\r\n.\r\n");
            $rep = $this->lire();
            if ((int)substr($rep, 0, 3) !== 250) throw new RuntimeException('Message refusé : ' . trim($rep));
            $this->cmd('QUIT', [221]);
        } finally {
            if (is_resource($this->sock)) fclose($this->sock);
        }
        return true;
    }
}
