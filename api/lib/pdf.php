<?php
/** Générateur PDF minimal (polices Helvetica standard, A4 paysage) : texte, traits, rectangles et images (via GD). Aucune dépendance externe. */
class Pdf
{
    const W = 842, H = 595;
    private $pages = [];
    private $cur = -1;
    private $images = [];   // [données JPEG, largeur px, hauteur px]
    private $parFichier = [];

    public function page() { $this->pages[] = ''; $this->cur = count($this->pages) - 1; return $this->cur + 1; }
    public function select($n) { $this->cur = $n - 1; }
    public function pageCount() { return count($this->pages); }

    private static function color($hex) { return sprintf('%.3f %.3f %.3f', hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255); }

    /** Largeur approchée d'un texte Helvetica (chiffres, espaces et ponctuation exacts ; lettres moyennées). */
    public static function width($s, $size, $bold = false)
    {
        $w = 0;
        foreach (preg_split('//u', self::latin($s), -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (ctype_digit($ch)) $w += 556;
            elseif (strpos(" ,.:;/!", $ch) !== false) $w += 278;
            elseif (strpos("-()", $ch) !== false) $w += 333;
            elseif ($ch === '%') $w += 889;
            elseif (strpos('ijl', $ch) !== false) $w += 222;
            elseif (strpos('ft', $ch) !== false) $w += 278;
            elseif ($ch === 'r') $w += 333;
            elseif ($ch === 'm') $w += 833;
            elseif ($ch === 'w') $w += 722;
            elseif ($ch === 'I') $w += 278;
            elseif ($ch === 'M' || $ch === 'W') $w += 833;
            elseif (ctype_upper($ch)) $w += 667;
            else $w += 520;
        }
        return $w / 1000 * $size * ($bold ? 1.05 : 1);
    }

    private static function latin($s)
    {
        $s = str_replace(["\u{00A0}", "\u{202F}", '–', '—', '’', '…', '→'], [' ', ' ', '-', '-', "'", '...', '>'], (string)$s);
        $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $r === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $r;
    }

    public function text($x, $y, $s, $size = 9, $bold = false, $align = 'L', $boxW = 0, $hex = '2A2340')
    {
        $s = self::latin($s);
        if ($boxW) {   // tronque proprement si le texte dépasse la cellule
            while ($s !== '' && self::width($s, $size, $bold) > $boxW) $s = substr($s, 0, -1);
        }
        $w = self::width($s, $size, $bold);
        if ($align === 'R') $x = $x + $boxW - $w; elseif ($align === 'C') $x = $x + ($boxW - $w) / 2;
        $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
        $this->pages[$this->cur] .= sprintf("BT /%s %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, self::color($hex), $x, self::H - $y, $esc);
    }

    public function rect($x, $y, $w, $h, $fill)
    {
        $this->pages[$this->cur] .= sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", self::color($fill), $x, self::H - $y - $h, $w, $h);
    }

    public function line($x1, $y1, $x2, $y2, $hex = 'D9D4E8', $width = 0.6)
    {
        $this->pages[$this->cur] .= sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S\n", self::color($hex), $width, $x1, self::H - $y1, $x2, self::H - $y2);
    }

    /**
     * Image (PNG, JPEG, WEBP, GIF) ajustée dans la boîte x, y, w, h sans déformation ; aplatie sur fond blanc et intégrée en JPEG.
     * Renvoie la largeur réellement occupée (0 si l'image est illisible ou GD absent).
     */
    public function image($file, $x, $y, $w, $h)
    {
        if (!function_exists('imagecreatefromstring') || !is_file($file)) return 0;
        $cle = realpath($file);
        if (!isset($this->parFichier[$cle])) {   // une image répétée sur chaque page n'est intégrée qu'une fois
            $src = @imagecreatefromstring((string)file_get_contents($file));
            if (!$src) return 0;
            $sw = imagesx($src); $sh = imagesy($src);
            $f = min(1, 300 / max($sw, $sh));   // 300 px suffisent pour un logo d'en-tête : fichier léger
            $pw = max(1, (int)round($sw * $f)); $ph = max(1, (int)round($sh * $f));
            $img = imagecreatetruecolor($pw, $ph);
            imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
            imagecopyresampled($img, $src, 0, 0, 0, 0, $pw, $ph, $sw, $sh);
            ob_start(); imagejpeg($img, null, 90); $jpeg = ob_get_clean();
            $this->parFichier[$cle] = count($this->images); $this->images[] = [$jpeg, $pw, $ph];
        }
        $k = $this->parFichier[$cle]; list(, $pw, $ph) = $this->images[$k];
        $r = min($w / $pw, $h / $ph); $dw = $pw * $r; $dh = $ph * $r;
        $this->pages[$this->cur] .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q\n", $dw, $dh, $x, self::H - $y - ($h + $dh) / 2, $k);
        return $dw;
    }

    public function save($path)
    {
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        $n = count($this->pages);
        for ($i = 0; $i < $n; $i++) $kids[] = (5 + $i * 2) . ' 0 R';
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        // Images : objets placés après les pages, partagés par toutes les pages
        $xobj = '';
        foreach ($this->images as $k => $im) {
            $o = 5 + $n * 2 + $k;
            $objs[$o] = "<< /Type /XObject /Subtype /Image /Width {$im[1]} /Height {$im[2]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($im[0]) . " >>\nstream\n" . $im[0] . "\nendstream";
            $xobj .= "/Im$k $o 0 R ";
        }
        $res = '<< /Font << /F1 3 0 R /F2 4 0 R >>' . ($xobj ? " /XObject << $xobj>>" : '') . ' >>';
        foreach ($this->pages as $i => $content) {
            $p = 5 + $i * 2;
            $objs[$p] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources ' . $res . ' /Contents ' . ($p + 1) . ' 0 R >>';
            $objs[$p + 1] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        }
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $off = [];
        ksort($objs);
        foreach ($objs as $k => $body) { $off[$k] = strlen($out); $out .= "$k 0 obj\n$body\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
        foreach ($objs as $k => $_) $out .= sprintf("%010d 00000 n \n", $off[$k]);
        $out .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        if (file_put_contents($path, $out) === false) throw new RuntimeException("Écriture impossible : $path");
    }
}
