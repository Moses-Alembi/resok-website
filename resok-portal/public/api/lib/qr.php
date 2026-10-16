<?php
declare(strict_types=1);

/**
 * A small QR code encoder for badge codes in email (versions 1 to 3, error correction M).
 *
 * The host has no image library and nothing installable, and the email queue stores HTML
 * only, so the code is drawn as an HTML table of black and white cells: it shows without
 * the reader allowing images, which most mail apps block by default.
 *
 * Follows ISO/IEC 18004 the way Project Nayuki's reference generator does. Badge codes
 * (RSK- plus 16 characters) use alphanumeric mode and fit version 1. Anything longer than
 * version 3 holds returns null, and the email simply goes without a picture.
 */

const QR_ALNUM = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ $%*+-./:';
// Version => [data codewords, error-correction codewords] at level M, single block.
const QR_CAPACITY_M = [1 => [16, 10], 2 => [28, 16], 3 => [44, 26]];

/** @return list<list<bool>>|null rows of modules, true = dark */
function qrMatrix(string $text): ?array
{
    $alnum = $text !== '' && strspn($text, QR_ALNUM) === strlen($text);
    foreach (QR_CAPACITY_M as $version => [$dataLen, $eccLen]) {
        $bits = qrDataBits($text, $alnum);
        if (count($bits) <= $dataLen * 8) return qrBuild($version, $bits, $dataLen, $eccLen);
    }
    return null;
}

/** @return list<int> */
function qrDataBits(string $text, bool $alnum): array
{
    $bits = [];
    $put = static function (int $value, int $length) use (&$bits): void {
        for ($i = $length - 1; $i >= 0; $i--) $bits[] = ($value >> $i) & 1;
    };
    if ($alnum) {
        $put(0b0010, 4);
        $put(strlen($text), 9);
        for ($i = 0, $n = strlen($text); $i + 1 < $n; $i += 2) {
            $put(strpos(QR_ALNUM, $text[$i]) * 45 + strpos(QR_ALNUM, $text[$i + 1]), 11);
        }
        if (strlen($text) % 2) $put(strpos(QR_ALNUM, $text[strlen($text) - 1]), 6);
    } else {
        $put(0b0100, 4);
        $put(strlen($text), 8);
        for ($i = 0, $n = strlen($text); $i < $n; $i++) $put(ord($text[$i]), 8);
    }
    return $bits;
}

function qrGfMul(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
        $z ^= (($y >> $i) & 1) * $x;
    }
    return $z;
}

/** @param list<int> $data @return list<int> */
function qrReedSolomon(array $data, int $degree): array
{
    $divisor = array_fill(0, $degree - 1, 0);
    $divisor[] = 1;
    $root = 1;
    for ($i = 0; $i < $degree; $i++) {
        for ($j = 0; $j < $degree; $j++) {
            $divisor[$j] = qrGfMul($divisor[$j], $root);
            if ($j + 1 < $degree) $divisor[$j] ^= $divisor[$j + 1];
        }
        $root = qrGfMul($root, 0x02);
    }
    $result = array_fill(0, $degree, 0);
    foreach ($data as $b) {
        $factor = $b ^ array_shift($result);
        $result[] = 0;
        for ($i = 0; $i < $degree; $i++) $result[$i] ^= qrGfMul($divisor[$i], $factor);
    }
    return $result;
}

/** @param list<int> $bits */
function qrBuild(int $version, array $bits, int $dataLen, int $eccLen): array
{
    // Terminator, byte alignment, then the standard pad bytes.
    $capacity = $dataLen * 8;
    for ($i = 0; $i < 4 && count($bits) < $capacity; $i++) $bits[] = 0;
    while (count($bits) % 8) $bits[] = 0;
    $bytes = [];
    foreach (array_chunk($bits, 8) as $chunk) $bytes[] = (int)bindec(implode('', $chunk));
    for ($pad = 0xEC; count($bytes) < $dataLen; $pad ^= 0xEC ^ 0x11) $bytes[] = $pad;
    $codewords = array_merge($bytes, qrReedSolomon($bytes, $eccLen));

    $size = 17 + 4 * $version;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = $m;
    $set = static function (int $x, int $y, bool $dark) use (&$m, &$fn): void { $m[$y][$x] = $dark; $fn[$y][$x] = true; };

    for ($i = 0; $i < $size; $i++) { $set(6, $i, $i % 2 === 0); $set($i, 6, $i % 2 === 0); }
    foreach ([[3, 3], [$size - 4, 3], [3, $size - 4]] as [$cx, $cy]) {
        for ($dy = -4; $dy <= 4; $dy++) for ($dx = -4; $dx <= 4; $dx++) {
            $x = $cx + $dx; $y = $cy + $dy;
            if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) continue;
            $d = max(abs($dx), abs($dy));
            $set($x, $y, $d !== 2 && $d !== 4);
        }
    }
    if ($version > 1) {
        $c = $size - 7; // the one alignment pattern of versions 2 and 3
        for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $set($c + $dx, $c + $dy, max(abs($dx), abs($dy)) !== 1);
    }
    qrFormatBits($set, $size, 0); // reserve the format areas

    $i = 0;
    $total = count($codewords) * 8;
    for ($right = $size - 1; $right >= 1; $right -= 2) {
        if ($right === 6) $right = 5;
        for ($vert = 0; $vert < $size; $vert++) {
            for ($j = 0; $j < 2; $j++) {
                $x = $right - $j;
                $y = (($right + 1) & 2) === 0 ? $size - 1 - $vert : $vert;
                if (!$fn[$y][$x] && $i < $total) {
                    $m[$y][$x] = (($codewords[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                    $i++;
                }
            }
        }
    }

    $best = null;
    $bestScore = PHP_INT_MAX;
    for ($mask = 0; $mask < 8; $mask++) {
        $try = $m;
        $setTry = static function (int $x, int $y, bool $dark) use (&$try): void { $try[$y][$x] = $dark; };
        for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
            if (!$fn[$y][$x] && qrMaskBit($mask, $x, $y)) $try[$y][$x] = !$try[$y][$x];
        }
        qrFormatBits($setTry, $size, $mask);
        $score = qrPenalty($try);
        if ($score < $bestScore) { $bestScore = $score; $best = $try; }
    }
    return $best;
}

function qrMaskBit(int $mask, int $x, int $y): bool
{
    switch ($mask) {
        case 0: return ($x + $y) % 2 === 0;
        case 1: return $y % 2 === 0;
        case 2: return $x % 3 === 0;
        case 3: return ($x + $y) % 3 === 0;
        case 4: return (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0;
        case 5: return $x * $y % 2 + $x * $y % 3 === 0;
        case 6: return ($x * $y % 2 + $x * $y % 3) % 2 === 0;
        default: return (($x + $y) % 2 + $x * $y % 3) % 2 === 0;
    }
}

/** Level M (format bits 00) with the mask, BCH-protected, in both copies, plus the dark module. */
function qrFormatBits(callable $set, int $size, int $mask): void
{
    $data = (0 << 3) | $mask;
    $rem = $data;
    for ($i = 0; $i < 10; $i++) $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
    $bits = (($data << 10) | $rem) ^ 0x5412;
    $bit = static fn(int $i): bool => (($bits >> $i) & 1) === 1;
    for ($i = 0; $i <= 5; $i++) $set(8, $i, $bit($i));
    $set(8, 7, $bit(6));
    $set(8, 8, $bit(7));
    $set(7, 8, $bit(8));
    for ($i = 9; $i < 15; $i++) $set(14 - $i, 8, $bit($i));
    for ($i = 0; $i < 8; $i++) $set($size - 1 - $i, 8, $bit($i));
    for ($i = 8; $i < 15; $i++) $set(8, $size - 15 + $i, $bit($i));
    $set(8, $size - 8, true);
}

/** The standard's four penalty rules, so the clearest mask is used. */
function qrPenalty(array $m): int
{
    $size = count($m);
    $score = 0;
    $dark = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $row = []; $col = [];
        for ($j = 0; $j < $size; $j++) { $row[] = $m[$i][$j]; $col[] = $m[$j][$i]; }
        $lines[] = $row; $lines[] = $col;
    }
    foreach ($lines as $line) {
        // Rule 1: runs of five or more of one colour.
        $run = 1;
        for ($j = 1; $j <= $size; $j++) {
            if ($j < $size && $line[$j] === $line[$j - 1]) { $run++; continue; }
            if ($run >= 5) $score += $run - 2;
            $run = 1;
        }
        // Rule 3: finder-like 1011101 with four light modules on a side.
        $s = implode('', array_map(fn($b) => $b ? '1' : '0', $line));
        $score += 40 * preg_match_all('/(?=00001011101|10111010000)/', '0000' . $s . '0000');
    }
    for ($y = 0; $y < $size; $y++) for ($x = 0; $x < $size; $x++) {
        if ($m[$y][$x]) $dark++;
        // Rule 2: 2x2 blocks of one colour.
        if ($x + 1 < $size && $y + 1 < $size && $m[$y][$x] === $m[$y][$x + 1] && $m[$y][$x] === $m[$y + 1][$x] && $m[$y][$x] === $m[$y + 1][$x + 1]) $score += 3;
    }
    // Rule 4: balance of dark and light.
    $score += 10 * intdiv(abs($dark * 20 - $size * $size * 10), $size * $size);
    return $score;
}

/**
 * The code as an HTML table for email: white quiet zone, black cells, no images. Marked
 * light-only so dark-mode mail apps leave its colours alone where they honour that.
 */
function qrEmailTable(string $text, int $cell = 5): ?string
{
    $m = qrMatrix($text);
    if (!$m) return null;
    $quiet = 4;
    $size = count($m) + 2 * $quiet;
    $px = $size * $cell;
    $html = '<table role="img" aria-label="QR code ' . htmlspecialchars($text, ENT_QUOTES) . '" cellpadding="0" cellspacing="0" border="0" width="' . $px . '"'
          . ' style="border-collapse:collapse;background:#ffffff;color-scheme:light only;margin:0 auto">';
    for ($y = 0; $y < $size; $y++) {
        $html .= '<tr>';
        for ($x = 0; $x < $size; $x++) {
            $mx = $x - $quiet; $my = $y - $quiet;
            $darkCell = $mx >= 0 && $my >= 0 && $mx < count($m) && $my < count($m) && $m[$my][$mx];
            $c = $darkCell ? '#000000' : '#ffffff';
            $html .= '<td width="' . $cell . '" height="' . $cell . '" bgcolor="' . $c . '" style="width:' . $cell . 'px;height:' . $cell . 'px;background:' . $c . ';padding:0;line-height:0;font-size:0"></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}
