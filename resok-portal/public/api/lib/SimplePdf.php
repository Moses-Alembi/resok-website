<?php
declare(strict_types=1);

/**
 * Minimal dependency-free PDF writer (single page, text, rects, lines, JPEG images).
 * No Composer/vendor libraries are available on the target shared host, so this
 * hand-rolled writer replaces FPDF for the small set of documents the portal needs
 * (welcome letter, membership card, Academy certificates). Coordinates are top-left origin in
 * points, matching how the existing SVG templates in server/utils/email.js were authored.
 *
 * Text is drawn in the standard Helvetica fonts. Every PDF reader has those built in, so they
 * cost nothing to include - but in the encoding written here they carry only plain ASCII. A
 * document that may print names with diacritics or from other scripts (Wanjirũ, Müller, Ọ̀ṣun)
 * registers TrueType fonts with useUnicodeFonts(). Any string containing a character outside
 * ASCII is then drawn in that font, embedded in the file; plain ASCII strings still use
 * Helvetica, so a document that never needs the font never carries it.
 */
class SimplePdf
{
    private float $width;
    private float $height;
    private string $content = '';
    private array $fill = [0, 0, 0];
    private array $textColor = [0, 0, 0];
    private array $stroke = [0, 0, 0];
    private array $images = [];
    /** @var array<string,array{path:string,ttf:?SimpleTtf,resource:string,glyphs:array<int,int>,failed:bool}> */
    private array $unicodeFonts = [];

    public function __construct(float $width = 595, float $height = 842)
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setFillColor(int $r, int $g, int $b): void { $this->fill = [$r, $g, $b]; }
    public function setTextColor(int $r, int $g, int $b): void { $this->textColor = [$r, $g, $b]; }
    public function setStrokeColor(int $r, int $g, int $b): void { $this->stroke = [$r, $g, $b]; }

    private function c(array $rgb): string
    {
        return sprintf('%.3F %.3F %.3F', $rgb[0] / 255, $rgb[1] / 255, $rgb[2] / 255);
    }

    public function rect(float $x, float $y, float $w, float $h, string $style = 'F'): void
    {
        $py = $this->height - $y - $h;
        if ($style === 'S') {
            $this->content .= sprintf("%s RG\n%.2F %.2F %.2F %.2F re S\n", $this->c($this->stroke), $x, $py, $w, $h);
        } else {
            $this->content .= sprintf("%s rg\n%.2F %.2F %.2F %.2F re f\n", $this->c($this->fill), $x, $py, $w, $h);
        }
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $widthPt = 1): void
    {
        $py1 = $this->height - $y1;
        $py2 = $this->height - $y2;
        $this->content .= sprintf("%s RG\n%.2F w\n%.2F %.2F m %.2F %.2F l S\n", $this->c($this->stroke), $widthPt, $x1, $py1, $x2, $py2);
    }

    private function escapeText(string $text): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
    }

    // Rough average glyph width for Helvetica at size 1 (good enough for wrapping/centering, not typesetting).
    private function widthOf(string $text, float $size, bool $bold): float
    {
        return strlen($text) * $size * ($bold ? 0.58 : 0.5);
    }

    /**
     * Registers TrueType fonts for text outside plain ASCII. Returns false when either file is
     * missing, in which case text is drawn in Helvetica exactly as before. The files are only
     * read if a string actually needs them.
     */
    public function useUnicodeFonts(string $regularPath, string $boldPath): bool
    {
        if (!is_file($regularPath) || !is_file($boldPath)) return false;
        $this->unicodeFonts = [
            'regular' => ['path' => $regularPath, 'ttf' => null, 'resource' => 'FU1', 'glyphs' => [], 'failed' => false],
            'bold'    => ['path' => $boldPath,    'ttf' => null, 'resource' => 'FU2', 'glyphs' => [], 'failed' => false],
        ];
        return true;
    }

    private function unicodeFont(bool $bold): ?SimpleTtf
    {
        $key = $bold ? 'bold' : 'regular';
        if (!isset($this->unicodeFonts[$key]) || $this->unicodeFonts[$key]['failed']) return null;
        if ($this->unicodeFonts[$key]['ttf'] === null) {
            try {
                $this->unicodeFonts[$key]['ttf'] = new SimpleTtf($this->unicodeFonts[$key]['path']);
            } catch (Throwable $e) {
                // An unreadable font costs the accents, not the document.
                error_log('SimplePdf: ' . $e->getMessage());
                $this->unicodeFonts[$key]['failed'] = true;
                return null;
            }
        }
        return $this->unicodeFonts[$key]['ttf'];
    }

    private static function isAscii(string $text): bool
    {
        return !preg_match('/[^\x20-\x7E]/', $text);
    }

    /** @return list<int> */
    private static function codepoints(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) $chars = str_split($text); // not valid UTF-8: treat as bytes
        return array_map(fn($ch) => mb_ord($ch, 'UTF-8') ?: ord($ch[0]), $chars);
    }

    /** The width of a string in points: from the font's own metrics when embedded, estimated for Helvetica. */
    public function measure(string $text, float $size, bool $bold = false): float
    {
        if (!self::isAscii($text) && ($font = $this->unicodeFont($bold))) {
            $units = 0;
            foreach (self::codepoints($text) as $cp) {
                $units += $font->advance($font->glyph($cp));
            }
            return $units * $size / $font->unitsPerEm();
        }
        return $this->widthOf($text, $size, $bold);
    }

    public function text(float $x, float $y, string $text, float $size = 12, bool $bold = false, string $align = 'L'): void
    {
        $py = $this->height - $y;
        $textWidth = $align === 'L' ? 0.0 : $this->measure($text, $size, $bold);
        $tx = $align === 'C' ? $x - $textWidth / 2 : ($align === 'R' ? $x - $textWidth : $x);

        $font = self::isAscii($text) ? null : $this->unicodeFont($bold);
        if ($font) {
            // Two-byte glyph ids against an Identity-H encoding: the embedded font's own glyphs,
            // addressed directly, so any character the font has can be drawn.
            $key = $bold ? 'bold' : 'regular';
            $hex = '';
            foreach (self::codepoints($text) as $cp) {
                $gid = $font->glyph($cp);
                $this->unicodeFonts[$key]['glyphs'][$gid] = $cp;
                $hex .= sprintf('%04X', $gid);
            }
            $this->content .= sprintf(
                "BT\n%s rg\n/%s %.2F Tf\n%.2F %.2F Td\n<%s> Tj\nET\n",
                $this->c($this->textColor), $this->unicodeFonts[$key]['resource'], $size, $tx, $py, $hex
            );
            return;
        }

        $this->content .= sprintf(
            "BT\n%s rg\n%s %.2F Tf\n%.2F %.2F Td\n(%s) Tj\nET\n",
            $this->c($this->textColor), $bold ? '/F2' : '/F1', $size, $tx, $py, $this->escapeText($text)
        );
    }

    /** Word-wraps text to $maxWidth, drawing each line, and returns the Y position after the last line. */
    public function multilineText(float $x, float $y, float $maxWidth, string $text, float $size = 11, float $lineHeight = 16, bool $bold = false): float
    {
        $paragraphs = preg_split('/\r?\n/', trim($text));
        $cursorY = $y;
        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/', trim($paragraph));
            $line = '';
            foreach ($words as $word) {
                $test = $line === '' ? $word : $line . ' ' . $word;
                if ($this->measure($test, $size, $bold) > $maxWidth && $line !== '') {
                    $this->text($x, $cursorY, $line, $size, $bold);
                    $cursorY += $lineHeight;
                    $line = $word;
                } else {
                    $line = $test;
                }
            }
            if ($line !== '') {
                $this->text($x, $cursorY, $line, $size, $bold);
                $cursorY += $lineHeight;
            }
            $cursorY += $lineHeight * 0.35;
        }
        return $cursorY;
    }


    /**
     * Places a baseline JPEG. Only JPEG, and only baseline: the file is embedded verbatim
     * with the DCTDecode filter, which is what lets a writer this small carry an image at
     * all - it hands the compressed bytes straight to the PDF reader rather than needing an
     * encoder here. A progressive JPEG will not render, so templates must be saved baseline.
     */
    public function image(string $path, float $x, float $y, float $w, float $h): bool
    {
        if (!is_file($path)) return false;
        $data = file_get_contents($path);
        if ($data === false || strncmp($data, "\xFF\xD8", 2) !== 0) return false;

        $size = @getimagesize($path);
        if (!$size || $size[2] !== IMAGETYPE_JPEG) return false;
        // Greyscale and CMYK JPEGs need a different colour space; rejecting them is better
        // than emitting a PDF that renders with inverted or missing colour.
        $channels = (int)($size['channels'] ?? 3);
        if ($channels !== 3) return false;

        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = [
            'name' => $name,
            'data' => $data,
            'width' => (int)$size[0],
            'height' => (int)$size[1],
        ];

        // PDF places an image by scaling the unit square, so the matrix carries the size.
        $py = $this->height - $y - $h;
        $this->content .= sprintf("q
%.2F 0 0 %.2F %.2F %.2F cm
/%s Do
Q
", $w, $h, $x, $py, $name);
        return true;
    }

    public function output(): string
    {
        // Objects 1-3 are the catalogue, the page tree and the page. Their bodies are written
        // last, once the fonts, images and content they point at have numbers.
        $objects = ['', '', ''];
        $add = static function (string $body) use (&$objects): int {
            $objects[] = $body;
            return count($objects);
        };

        $fonts = sprintf('/F1 %d 0 R /F2 %d 0 R',
            $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'),
            $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>'));
        foreach ($this->unicodeFonts as $font) {
            if ($font['ttf'] === null || !$font['glyphs']) continue;
            $fonts .= sprintf(' /%s %d 0 R', $font['resource'], $this->embedFont($add, $font['ttf'], $font['glyphs']));
        }

        $xobjects = '';
        foreach ($this->images as $image) {
            $ref = $add(sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB '
                . '/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                $image['width'], $image['height'], strlen($image['data'])
            ) . "\nstream\n" . $image['data'] . "\nendstream");
            $xobjects .= sprintf('/%s %d 0 R ', $image['name'], $ref);
        }

        $contents = $add('<< /Length ' . strlen($this->content) . " >>\nstream\n" . $this->content . 'endstream');
        $resources = '/Font << ' . $fonts . ' >>'
            . ($xobjects !== '' ? ' /XObject << ' . trim($xobjects) . ' >>' : '');

        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[2] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents %d 0 R >>',
            $this->width, $this->height, $resources, $contents
        );

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xrefStart = strlen($pdf);
        $count = count($objects) + 1;
        $pdf .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";
        return $pdf;
    }

    /**
     * Embeds a TrueType font as a Type0 font with Identity-H encoding.
     *
     * The whole font file is embedded, compressed, rather than a subset: subsetting means
     * rewriting the glyph tables, which is a font editor's job and not something to hand-roll.
     * Only the widths of the glyphs actually drawn are written, and a ToUnicode map is included
     * so the text in the PDF can still be searched and copied.
     *
     * @param array<int,int> $glyphs Glyph id => the code point it was drawn for.
     */
    private function embedFont(callable $add, SimpleTtf $font, array $glyphs): int
    {
        $name = $font->postscriptName();
        $scale = 1000 / $font->unitsPerEm();
        $data = $font->data();
        $packed = function_exists('gzcompress') ? gzcompress($data, 6) : false;
        $stream = $packed !== false ? $packed : $data;
        $file = $add('<< /Length ' . strlen($stream) . ' /Length1 ' . strlen($data)
            . ($packed !== false ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $stream . "\nendstream");

        [$xMin, $yMin, $xMax, $yMax] = $font->bbox();
        $descriptor = $add(sprintf(
            '<< /Type /FontDescriptor /FontName /%s /Flags 32 /FontBBox [%d %d %d %d] /ItalicAngle 0 '
            . '/Ascent %d /Descent %d /CapHeight %d /StemV 80 /FontFile2 %d 0 R >>',
            $name, round($xMin * $scale), round($yMin * $scale), round($xMax * $scale), round($yMax * $scale),
            round($font->ascent() * $scale), round($font->descent() * $scale), round($font->capHeight() * $scale), $file
        ));

        ksort($glyphs);
        $widths = '';
        foreach (array_keys($glyphs) as $gid) {
            $widths .= sprintf('%d [%d] ', $gid, round($font->advance($gid) * $scale));
        }
        $cidFont = $add(sprintf(
            '<< /Type /Font /Subtype /CIDFontType2 /BaseFont /%s /CIDSystemInfo << /Registry (Adobe) '
            . '/Ordering (Identity) /Supplement 0 >> /FontDescriptor %d 0 R /W [%s] /CIDToGIDMap /Identity >>',
            $name, $descriptor, trim($widths)
        ));

        $entries = [];
        foreach ($glyphs as $gid => $cp) {
            if ($gid === 0) continue;
            $utf16 = mb_convert_encoding(mb_chr($cp, 'UTF-8'), 'UTF-16BE', 'UTF-8');
            $entries[] = sprintf('<%04X> <%s>', $gid, strtoupper(bin2hex($utf16)));
        }
        $map = '';
        foreach (array_chunk($entries, 100) as $chunk) {
            $map .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }
        $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
            . "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
            . $map
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";
        $toUnicode = $add('<< /Length ' . strlen($cmap) . " >>\nstream\n" . $cmap . "\nendstream");

        return $add(sprintf(
            '<< /Type /Font /Subtype /Type0 /BaseFont /%s /Encoding /Identity-H /DescendantFonts [%d 0 R] /ToUnicode %d 0 R >>',
            $name, $cidFont, $toUnicode
        ));
    }
}

/**
 * Reads the handful of TrueType tables a PDF needs: character-to-glyph mapping, advance widths
 * and the vertical metrics for the font descriptor. Nothing is rewritten.
 */
final class SimpleTtf
{
    private string $path;
    private string $data;
    /** @var array<string,array{0:int,1:int}> tag => [offset, length] */
    private array $tables = [];
    private int $unitsPerEm;
    private array $bbox;
    private int $ascent;
    private int $descent;
    private int $capHeight;
    private int $numberOfHMetrics;
    private ?array $cmap;
    private array $glyphCache = [];

    public function __construct(string $path)
    {
        $data = @file_get_contents($path);
        if ($data === false || strlen($data) < 12) throw new RuntimeException('Font not readable: ' . $path);
        $this->path = $path;
        $this->data = $data;

        $numTables = $this->u16(4);
        for ($i = 0; $i < $numTables; $i++) {
            $record = 12 + 16 * $i;
            $this->tables[substr($data, $record, 4)] = [$this->u32($record + 8), $this->u32($record + 12)];
        }
        foreach (['head', 'hhea', 'hmtx', 'cmap'] as $required) {
            if (!isset($this->tables[$required])) throw new RuntimeException("Font {$path} has no {$required} table");
        }

        $head = $this->tables['head'][0];
        $this->unitsPerEm = $this->u16($head + 18) ?: 1000;
        $this->bbox = [$this->s16($head + 36), $this->s16($head + 38), $this->s16($head + 40), $this->s16($head + 42)];

        $hhea = $this->tables['hhea'][0];
        $this->ascent = $this->s16($hhea + 4);
        $this->descent = $this->s16($hhea + 6);
        $this->numberOfHMetrics = max(1, $this->u16($hhea + 34));

        $this->capHeight = $this->ascent;
        if (isset($this->tables['OS/2'])) {
            [$os2, $length] = $this->tables['OS/2'];
            if ($this->u16($os2) >= 2 && $length >= 90) $this->capHeight = $this->s16($os2 + 88);
        }
        $this->cmap = $this->pickCmap();
    }

    public function data(): string { return $this->data; }
    public function unitsPerEm(): int { return $this->unitsPerEm; }
    public function bbox(): array { return $this->bbox; }
    public function ascent(): int { return $this->ascent; }
    public function descent(): int { return $this->descent; }
    public function capHeight(): int { return $this->capHeight; }

    public function postscriptName(): string
    {
        return preg_replace('/[^A-Za-z0-9-]/', '', pathinfo($this->path, PATHINFO_FILENAME)) ?: 'EmbeddedFont';
    }

    public function advance(int $gid): int
    {
        // Glyphs past the last full metric share its advance width - that is how the table is
        // compacted for monospaced runs.
        $index = min($gid, $this->numberOfHMetrics - 1);
        return $this->u16($this->tables['hmtx'][0] + 4 * $index);
    }

    /** The glyph for a code point, or 0 (.notdef) when the font has none. */
    public function glyph(int $cp): int
    {
        if (isset($this->glyphCache[$cp])) return $this->glyphCache[$cp];
        $gid = 0;
        if ($this->cmap !== null) {
            $gid = $this->cmap['format'] === 12 ? $this->glyphFormat12($cp) : $this->glyphFormat4($cp);
        }
        return $this->glyphCache[$cp] = $gid;
    }

    private function pickCmap(): ?array
    {
        $base = $this->tables['cmap'][0];
        $count = $this->u16($base + 2);
        $subtables = [];
        for ($i = 0; $i < $count; $i++) {
            $record = $base + 4 + 8 * $i;
            $offset = $base + $this->u32($record + 4);
            $subtables[] = [$this->u16($record), $this->u16($record + 2), $offset, $this->u16($offset)];
        }
        // Full Unicode first, then the Basic Multilingual Plane.
        foreach ([[3, 10, 12], [0, 4, 12], [3, 1, 4], [0, 3, 4]] as [$platform, $encoding, $format]) {
            foreach ($subtables as [$p, $e, $offset, $f]) {
                if ($p === $platform && $e === $encoding && $f === $format) return ['offset' => $offset, 'format' => $f];
            }
        }
        foreach ($subtables as [, , $offset, $f]) {
            if ($f === 12 || $f === 4) return ['offset' => $offset, 'format' => $f];
        }
        return null;
    }

    private function glyphFormat12(int $cp): int
    {
        $offset = $this->cmap['offset'];
        $low = 0;
        $high = $this->u32($offset + 12) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            $group = $offset + 16 + 12 * $mid;
            $start = $this->u32($group);
            if ($cp < $start) { $high = $mid - 1; continue; }
            if ($cp > $this->u32($group + 4)) { $low = $mid + 1; continue; }
            return $this->u32($group + 8) + ($cp - $start);
        }
        return 0;
    }

    private function glyphFormat4(int $cp): int
    {
        if ($cp > 0xFFFF) return 0;
        $offset = $this->cmap['offset'];
        $segX2 = $this->u16($offset + 6);
        $ends = $offset + 14;
        $starts = $ends + $segX2 + 2;
        $deltas = $starts + $segX2;
        $ranges = $deltas + $segX2;
        for ($i = 0, $segments = intdiv($segX2, 2); $i < $segments; $i++) {
            if ($cp > $this->u16($ends + 2 * $i)) continue;
            $start = $this->u16($starts + 2 * $i);
            if ($cp < $start) return 0;
            $delta = $this->u16($deltas + 2 * $i);
            $rangeOffset = $this->u16($ranges + 2 * $i);
            if ($rangeOffset === 0) return ($cp + $delta) & 0xFFFF;
            $glyph = $this->u16($ranges + 2 * $i + $rangeOffset + 2 * ($cp - $start));
            return $glyph === 0 ? 0 : ($glyph + $delta) & 0xFFFF;
        }
        return 0;
    }

    private function u16(int $at): int { return unpack('n', substr($this->data, $at, 2))[1] ?? 0; }
    private function s16(int $at): int { $v = $this->u16($at); return $v >= 0x8000 ? $v - 0x10000 : $v; }
    private function u32(int $at): int { return unpack('N', substr($this->data, $at, 4))[1] ?? 0; }
}
