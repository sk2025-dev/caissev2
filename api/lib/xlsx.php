<?php
/**
 * Écriture de classeurs Excel (.xlsx) sans dépendance : XML écrit à la main + archive ZIP « stockée » (sans compression).
 * Cellule : valeur scalaire, ou ['v' => valeur, 's' => style, 'f' => formule (sans « = »)].
 */
class Xl
{
    // Identifiants de styles (voir styles_xml)
    const NORMAL = 0, HEAD = 1, NUM = 2, TOTAL_NUM = 3, TOTAL_TXT = 4, DATE = 5, TITLE = 6, SUB = 7, PCT = 8, GROUP = 9, HEAD_LEFT = 10, NUM_BOLD = 11, TOTAL_PCT = 12, WRAP = 13, DATETIME = 14, NUM_DEC = 15;

    public static function col($i)   // 0 -> A, 26 -> AA
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    public static function dateSerial($ymd)
    {
        return intdiv(strtotime($ymd . ' 00:00:00 UTC'), 86400) + 25569;
    }

    /** Date + heure (AAAA-MM-JJ HH:MM:SS, UTC) -> numéro de série Excel avec fraction de jour. */
    public static function dateTimeSerial($dt)
    {
        return round(strtotime($dt . ' UTC') / 86400 + 25569, 6);
    }
}

class XlSheet
{
    public $name;
    public $rows = [];      // n° de ligne (1..) => [col => cellule]
    public $widths = [];
    public $merges = [];
    public $freeze = null;  // [ligne, colonne] : première cellule non figée (1-based)
    public $filter = null;  // "A5:G99"
    public $heights = [];
    public $entete = '';     // en-tête et pied de page d'impression (codes Excel &L &C &R &P &N)
    public $pied = '';
    private $cur = 0;

    public function __construct($name) { $this->name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', mb_substr($name, 0, 31)); }

    /** Ajoute une ligne (renvoie son numéro). */
    public function row(array $cells, $height = null)
    {
        $this->cur++;
        $this->rows[$this->cur] = array_values($cells);
        if ($height) $this->heights[$this->cur] = $height;
        return $this->cur;
    }
    public function skip($n = 1) { $this->cur += $n; }
    public function current() { return $this->cur; }
    public function merge($r1, $c1, $r2, $c2) { $this->merges[] = Xl::col($c1) . $r1 . ':' . Xl::col($c2) . $r2; }

    private function cellXml($ref, $cell)
    {
        if (!is_array($cell)) $cell = ['v' => $cell];
        $s = isset($cell['s']) ? (int)$cell['s'] : 0;
        $v = array_key_exists('v', $cell) ? $cell['v'] : null;
        $style = $s ? ' s="' . $s . '"' : '';
        if (isset($cell['f'])) {
            $f = htmlspecialchars($cell['f'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            return '<c r="' . $ref . '"' . $style . '><f>' . $f . '</f>' . ($v !== null ? '<v>' . $v . '</v>' : '') . '</c>';
        }
        if ($v === null || $v === '') return '<c r="' . $ref . '"' . $style . '/>';
        if (is_int($v) || is_float($v)) return '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
        $t = htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        return '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . $t . '</t></is></c>';
    }

    public function xml()
    {
        $maxCol = 0;
        foreach ($this->rows as $r) $maxCol = max($maxCol, count($r));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $x .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
        if ($this->freeze) {
            list($fr, $fc) = $this->freeze;
            $tl = Xl::col($fc - 1) . $fr;
            $x .= '<pane' . ($fc > 1 ? ' xSplit="' . ($fc - 1) . '"' : '') . ($fr > 1 ? ' ySplit="' . ($fr - 1) . '"' : '') . ' topLeftCell="' . $tl . '" activePane="' . ($fc > 1 && $fr > 1 ? 'bottomRight' : ($fr > 1 ? 'bottomLeft' : 'topRight')) . '" state="frozen"/>';
        }
        $x .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="16"/>';
        if ($this->widths) {
            $x .= '<cols>';
            foreach ($this->widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
            $x .= '</cols>';
        }
        $x .= '<sheetData>';
        ksort($this->rows);
        foreach ($this->rows as $n => $cells) {
            $x .= '<row r="' . $n . '"' . (isset($this->heights[$n]) ? ' ht="' . $this->heights[$n] . '" customHeight="1"' : '') . '>';
            foreach ($cells as $i => $c) $x .= $this->cellXml(Xl::col($i) . $n, $c);
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if ($this->filter) $x .= '<autoFilter ref="' . $this->filter . '"/>';
        if ($this->merges) {
            $x .= '<mergeCells count="' . count($this->merges) . '">';
            foreach ($this->merges as $m) $x .= '<mergeCell ref="' . $m . '"/>';
            $x .= '</mergeCells>';
        }
        $x .= '<pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup paperSize="9" orientation="landscape" fitToHeight="0"/>';
        if ($this->entete !== '' || $this->pied !== '') {
            $e = function ($t) { return htmlspecialchars($t, ENT_XML1 | ENT_QUOTES, 'UTF-8'); };
            $x .= '<headerFooter>' . ($this->entete !== '' ? '<oddHeader>' . $e($this->entete) . '</oddHeader>' : '') . ($this->pied !== '' ? '<oddFooter>' . $e($this->pied) . '</oddFooter>' : '') . '</headerFooter>';
        }
        $x .= '</worksheet>';
        return $x;
    }
}

class XlBook
{
    private $sheets = [];
    private $c;              // couleurs (hexadécimal sans #) : principale, douce, pale, bordure
    private $entete = ''; private $pied = '';

    public function __construct(array $couleurs = [], $entete = '', $pied = '')
    {
        $this->c = $couleurs + ['principale' => '7048E8', 'douce' => 'E7DFFF', 'pale' => 'F3F0FA', 'bordure' => 'D9D4E8'];
        $this->entete = $entete; $this->pied = $pied;
    }

    public function sheet($name) { $s = new XlSheet($name); $s->entete = $this->entete; $s->pied = $this->pied; return $this->sheets[] = $s; }

    /** Texte libre utilisable dans un en-tête / pied de page Excel (« & » y est un code de mise en forme). */
    public static function texteEntete($t) { return str_replace('&', '&&', (string)$t); }

    private function stylesXml()
    {
        $c = $this->c; $bd = 'FF' . $c['bordure'];
        $thin = '<left style="thin"><color rgb="' . $bd . '"/></left><right style="thin"><color rgb="' . $bd . '"/></right><top style="thin"><color rgb="' . $bd . '"/></top><bottom style="thin"><color rgb="' . $bd . '"/></bottom><diagonal/>';
        $xf = function ($num, $font, $fill, $border, $align = '') {
            return '<xf numFmtId="' . $num . '" fontId="' . $font . '" fillId="' . $fill . '" borderId="' . $border . '" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"' . ($align ? ' applyAlignment="1">' . $align . '</xf>' : '/>');
        };
        $center = '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        $left = '<alignment horizontal="left" vertical="center"/>';
        $wrap = '<alignment vertical="top" wrapText="1"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="5"><numFmt numFmtId="164" formatCode="#,##0;[Red]\-#,##0;&quot;–&quot;"/><numFmt numFmtId="165" formatCode="dd/mm/yyyy"/><numFmt numFmtId="166" formatCode="0.0%"/><numFmt numFmtId="167" formatCode="dd/mm/yyyy hh:mm"/><numFmt numFmtId="168" formatCode="#,##0.###"/></numFmts>'
            . '<fonts count="5"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="15"/><color rgb="FF2A2340"/><name val="Calibri"/></font><font><i/><sz val="10"/><color rgb="FF7B7393"/><name val="Calibri"/></font></fonts>'
            . '<fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF' . $c['principale'] . '"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF' . $c['douce'] . '"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FF' . $c['pale'] . '"/></patternFill></fill></fills>'
            . '<borders count="3"><border><left/><right/><top/><bottom/><diagonal/></border><border>' . $thin . '</border><border><left/><right/><top style="medium"><color rgb="FF' . $c['principale'] . '"/></top><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="16">'
            . $xf(0, 0, 0, 0)                   // 0 normal
            . $xf(0, 1, 2, 1, $center)          // 1 en-tête
            . $xf(164, 0, 0, 1)                 // 2 nombre
            . $xf(164, 2, 4, 2)                 // 3 total nombre
            . $xf(0, 2, 4, 2, $left)            // 4 total libellé
            . $xf(165, 0, 0, 1, $left)          // 5 date
            . $xf(0, 3, 0, 0)                   // 6 titre
            . $xf(0, 4, 0, 0)                   // 7 sous-titre
            . $xf(166, 0, 0, 1)                 // 8 pourcentage
            . $xf(0, 2, 3, 1, $center)         // 9 groupe
            . $xf(0, 1, 2, 1, $left)            // 10 en-tête à gauche
            . $xf(164, 2, 0, 1)                 // 11 nombre gras
            . $xf(166, 2, 4, 2)                 // 12 total pourcentage
            . $xf(0, 0, 0, 1, $wrap)            // 13 texte
            . $xf(167, 0, 0, 1, $left)          // 14 date et heure
            . $xf(168, 0, 0, 1)                 // 15 quantité (décimales utiles)
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    public function save($path)
    {
        $n = count($this->sheets);
        $files = [];
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><bookViews><workbookView/></bookViews><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $defined = '';
        foreach ($this->sheets as $i => $sh) {
            $k = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . htmlspecialchars($sh->name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $files['xl/worksheets/sheet' . $k . '.xml'] = $sh->xml();
            if ($sh->filter) $defined .= '<definedName name="_xlnm._FilterDatabase" localSheetId="' . $i . '" hidden="1">\'' . str_replace("'", "''", $sh->name) . '\'!' . preg_replace('/([A-Z]+)(\d+)/', '$$$1$$$2', $sh->filter) . '</definedName>';
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $ct .= '</Types>';
        $wb .= '</sheets>' . ($defined ? '<definedNames>' . $defined . '</definedNames>' : '') . '<calcPr fullCalcOnLoad="1"/></workbook>';

        $files['[Content_Types].xml'] = $ct;
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
        $files['xl/workbook.xml'] = $wb;
        $files['xl/_rels/workbook.xml.rels'] = $rels;
        $files['xl/styles.xml'] = $this->stylesXml();
        self::zip($files, $path);
    }

    /** Archive ZIP sans compression (méthode 0) : suffisant pour des fichiers XML de quelques Ko. */
    private static function zip(array $files, $path)
    {
        $out = ''; $central = ''; $count = 0;
        $t = getdate(); $time = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
        $date = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        foreach ($files as $name => $data) {
            $crc = crc32($data); $len = strlen($data); $off = strlen($out);
            $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0) . $name . $data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $count++;
        }
        $out .= $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($out), 0);
        if (file_put_contents($path, $out) === false) throw new RuntimeException("Écriture impossible : $path");
    }
}
