<?php
declare(strict_types=1);

/**
 * Minimal dependency-free PDF writer (single page, Helvetica text, rects, lines).
 * No Composer/vendor libraries are available on the target shared host, so this
 * hand-rolled writer replaces FPDF for the small set of documents the portal needs
 * (welcome letter, membership card). Coordinates are top-left origin in points,
 * matching how the existing SVG templates in server/utils/email.js were authored.
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

    public function text(float $x, float $y, string $text, float $size = 12, bool $bold = false, string $align = 'L'): void
    {
        $font = $bold ? '/F2' : '/F1';
        $py = $this->height - $y;
        $tx = $x;
        if ($align !== 'L') {
            $textWidth = $this->widthOf($text, $size, $bold);
            if ($align === 'C') $tx = $x - $textWidth / 2;
            if ($align === 'R') $tx = $x - $textWidth;
        }
        $this->content .= sprintf(
            "BT\n%s rg\n%s %.2F Tf\n%.2F %.2F Td\n(%s) Tj\nET\n",
            $this->c($this->textColor), $font, $size, $tx, $py, $this->escapeText($text)
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
                if ($this->widthOf($test, $size, $bold) > $maxWidth && $line !== '') {
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
        if ($data === false || strncmp($data, "ÿØ", 2) !== 0) return false;

        $size = @getimagesize($path);
        if (!$size || ($size[2] ?? 0) !== IMAGETYPE_JPEG) return false;
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
        // Objects 1-6 are fixed (catalog, pages, page, two fonts, content); any images
        // follow from 7, and the page's /XObject dictionary points at them by name.
        $imageObjectStart = 7;
        $xobjects = '';
        foreach ($this->images as $i => $image) {
            $xobjects .= sprintf('/%s %d 0 R ', $image['name'], $imageObjectStart + $i);
        }
        $resources = '/Font << /F1 4 0 R /F2 5 0 R >>'
            . ($xobjects !== '' ? ' /XObject << ' . trim($xobjects) . ' >>' : '');

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = sprintf(
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents 6 0 R >>',
            $this->width, $this->height, $resources
        );
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        $stream = $this->content;
        $objects[] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream';

        foreach ($this->images as $image) {
            $objects[] = sprintf(
                '<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB '
                . '/BitsPerComponent 8 /Filter /DCTDecode /Length %d >>',
                $image['width'], $image['height'], strlen($image['data'])
            ) . "\nstream\n" . $image['data'] . "\nendstream";
        }

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
}
