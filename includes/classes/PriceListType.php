<?php
/**
 * includes/classes/PriceListType.php
 * -----------------------------------------------------------------------------
 * OK Veggies. TrueType text for the PNG price list, drawn by PHP.
 *
 * The PNG renderer paints with GD, because GD is the one imaging extension the
 * repository already declares (PhpSpreadsheet requires it for the spreadsheet
 * export). A GD build on shared hosting frequently ships without FreeType, so
 * this class never calls imagettftext. It reads the glyph outlines directly
 * from the DejaVu faces that ship with dompdf in vendor/, flattens their
 * quadratic curves into polygons at 4x and downsamples, which gives clean
 * antialiased letterforms on any GD.
 *
 * Why these faces: the brand faces (Hanken Grotesk, JetBrains Mono) carry no
 * naira sign. The DejaVu family does, in every weight the price list needs,
 * and it is the same family a dompdf invoice already falls back to, so the
 * PDF and the PNG never disagree about a glyph. Owner approved, 24 September
 * 2026. The map below is the one place a face is named.
 *
 * Everything is a pure function of its input: same text, same size, same face,
 * same pixels. No random, no clock, no locale.
 * -----------------------------------------------------------------------------
 */

use FontLib\Font;
use FontLib\Glyph\OutlineComposite;
use FontLib\Glyph\OutlineSimple;

final class PriceListType
{
    /** Supersample factor. 4x keeps curves smooth and the tiles small. */
    public const SUPER_SAMPLE = 4;

    /** Fixed flattening steps per curve segment, so output never wobbles. */
    public const CURVE_STEPS = 8;

    /** The faces, as paths under the repository root. One map, one place. */
    private const FACES = [
        'sans'      => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf',
        'sans-bold' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf',
        'mono'      => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf',
        'mono-bold' => 'vendor/dompdf/dompdf/lib/fonts/DejaVuSansMono-Bold.ttf',
    ];

    /** Parsed fonts for this request, by face name. */
    private static array $fonts = [];

    /** Rendered glyph tiles for this request, by face, size and character. */
    private static array $tiles = [];

    /** The repository root the face paths hang from. */
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Absolute path of a face, checked once. Throws when the file is gone. */
    public static function facePath(string $face): string
    {
        $path = self::root() . '/' . (self::FACES[$face] ?? '');
        if ($path === self::root() . '/' || !is_file($path)) {
            throw new RuntimeException('The font file for ' . $face . ' is missing from vendor/.');
        }
        return $path;
    }

    private static function font(string $face)
    {
        if (!isset(self::$fonts[$face])) {
            $font = Font::load(self::facePath($face));
            $font->parse();
            self::$fonts[$face] = [
                'font'    => $font,
                'map'     => $font->getUnicodeCharMap() ?: [],
                'upem'    => (int) $font->getData('head')['unitsPerEm'],
                'hmtx'    => $font->getTableObject('hmtx')->data,
                'glyf'    => $font->getTableObject('glyf'),
            ];
        }
        return self::$fonts[$face];
    }

    /** The pen advance for one character, in pixels at the given size. */
    public static function advance(string $face, string $char, float $size): float
    {
        $f = self::font($face);
        $gid = self::glyphId($f, $char);
        $units = (int) ($f['hmtx'][$gid][0] ?? 0);
        return $units * $size / $f['upem'];
    }

    /** The width of a string, in pixels. Sum of advances; no kerning by design. */
    public static function width(string $face, string $text, float $size): float
    {
        $w = 0.0;
        foreach (self::graphemes($text) as $char) {
            if (!self::isCombining($char)) {
                $w += self::advance($face, $char, $size);
            }
        }
        return $w;
    }

    private static function glyphId(array $f, string $char): int
    {
        $cps = unpack('N', mb_convert_encoding($char, 'UTF-32BE', 'UTF-8'));
        $cp = $cps === false ? 0 : (int) $cps[1];
        return (int) ($f['map'][$cp] ?? 0);
    }

    /**
     * The precomposed pairs a product name can meet, base then combining
     * mark. Names arrive in NFC, but PHP's text handling can hand back a base
     * letter and a separate mark, and drawing those one codepoint at a time
     * prints the accent on the wrong letter. When the intl Normalizer is
     * loaded it does the whole job; this map is the fallback for the accents
     * Nigerian copy actually carries, plus the European ones a supplier name
     * might bring.
     */
    private const PRECOMPOSED = [
        "a\u{0300}" => "\u{00E0}", "a\u{0301}" => "\u{00E1}", "a\u{0302}" => "\u{00E2}", "a\u{0303}" => "\u{00E3}", "a\u{0308}" => "\u{00E4}", "a\u{030A}" => "\u{00E5}",
        "e\u{0300}" => "\u{00E8}", "e\u{0301}" => "\u{00E9}", "e\u{0302}" => "\u{00EA}", "e\u{0308}" => "\u{00EB}",
        "i\u{0300}" => "\u{00EC}", "i\u{0301}" => "\u{00ED}", "i\u{0302}" => "\u{00EE}", "i\u{0308}" => "\u{00EF}",
        "o\u{0300}" => "\u{00F2}", "o\u{0301}" => "\u{00F3}", "o\u{0302}" => "\u{00F4}", "o\u{0303}" => "\u{00F5}", "o\u{0308}" => "\u{00F6}",
        "u\u{0300}" => "\u{00F9}", "u\u{0301}" => "\u{00FA}", "u\u{0302}" => "\u{00FB}", "u\u{0308}" => "\u{00FC}",
        "A\u{0300}" => "\u{00C0}", "A\u{0301}" => "\u{00C1}", "A\u{0302}" => "\u{00C2}", "A\u{0303}" => "\u{00C3}", "A\u{0308}" => "\u{00C4}", "A\u{030A}" => "\u{00C5}",
        "E\u{0300}" => "\u{00C8}", "E\u{0301}" => "\u{00C9}", "E\u{0302}" => "\u{00CA}", "E\u{0308}" => "\u{00CB}",
        "I\u{0300}" => "\u{00CC}", "I\u{0301}" => "\u{00CD}", "I\u{0302}" => "\u{00CE}", "I\u{0308}" => "\u{00CF}",
        "O\u{0300}" => "\u{00D2}", "O\u{0301}" => "\u{00D3}", "O\u{0302}" => "\u{00D4}", "O\u{0303}" => "\u{00D5}", "O\u{0308}" => "\u{00D6}",
        "U\u{0300}" => "\u{00D9}", "U\u{0301}" => "\u{00DA}", "U\u{0302}" => "\u{00DB}", "U\u{0308}" => "\u{00DC}",
        "n\u{0303}" => "\u{00F1}",
        "N\u{0303}" => "\u{00D1}",
        "y\u{0301}" => "\u{00FD}",
        "y\u{0308}" => "\u{00FF}",
        "Y\u{0301}" => "\u{00DD}",
        "Y\u{0308}" => "\u{0178}",
        "c\u{0327}" => "\u{00E7}",
        "C\u{0327}" => "\u{00C7}",
    ];

    /**
     * The characters to draw for one string, composed where possible. Public
     * because the wrap and width maths and the callers share the one split.
     *
     * @return array<int, string>
     */
    public static function graphemes(string $text): array
    {
        if (class_exists('Normalizer')) {
            $text = normalizer_normalize($text, Normalizer::FORM_C);
        } else {
            $text = str_replace(array_keys(self::PRECOMPOSED), array_values(self::PRECOMPOSED), $text);
        }
        $out = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $out[] = mb_substr($text, $i, 1, 'UTF-8');
        }
        return $out;
    }

    /**
     * The codepoints that carry no ink of their own when they survive
     * composition. They advance nothing and draw nothing, so an exotic name
     * degrades to its base letters rather than overlapping.
     */
    private static function isCombining(string $char): bool
    {
        if ($char === '') {
            return false;
        }
        $cps = unpack('N', mb_convert_encoding($char, 'UTF-32BE', 'UTF-8'));
        $cp = $cps === false ? 0 : (int) $cps[1];
        return $cp >= 0x0300 && $cp <= 0x036F;
    }

    /**
     * Draw a string with its left edge at $x and its baseline at $baselineY.
     * Returns the pen advance, so a caller can continue a run. A character the
     * face cannot draw (or whitespace) advances the pen and draws nothing,
     * which is exactly what a print shop would do.
     */
    public static function draw($img, string $face, string $text, float $x, float $baselineY, float $size, array $colour): float
    {
        $pen = 0.0;
        foreach (self::graphemes($text) as $char) {
            if ($char !== ' ' && !self::isCombining($char)) {
                $tile = self::tile($face, $char, $size, $colour);
                if ($tile !== null) {
                    imagealphablending($img, true);
                    imagecopy(
                        $img,
                        $tile['img'],
                        (int) round($x + $pen),
                        (int) round($baselineY - $tile['ascent']),
                        0,
                        0,
                        imagesx($tile['img']),
                        imagesy($tile['img'])
                    );
                }
            }
            if (!self::isCombining($char)) {
                $pen += self::advance($face, $char, $size);
            }
        }
        return $pen;
    }

    /** Draw right-aligned so the string ends at $rightX. */
    public static function drawRight($img, string $face, string $text, float $rightX, float $baselineY, float $size, array $colour): float
    {
        return self::draw($img, $face, $text, $rightX - self::width($face, $text, $size), $baselineY, $size, $colour);
    }

    /**
     * Wrap a string to a pixel width, breaking on spaces. A single word longer
     * than the line is broken where it has to be. Returns the lines in order.
     *
     * @return array<int, string>
     */
    public static function wrap(string $face, string $text, float $size, float $maxWidth): array
    {
        $maxWidth = max(1.0, $maxWidth);
        $lines = [];
        foreach (preg_split('/\\n/', $text) as $paragraph) {
            $words = preg_split('/ +/', trim($paragraph));
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : $line . ' ' . $word;
                if (self::width($face, $candidate, $size) <= $maxWidth) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $lines[] = $line;
                    $line = '';
                }
                // The word alone may still be too wide: break it character by
                // character so a very long name still fits its column.
                while (self::width($face, $word, $size) > $maxWidth && mb_strlen($word, 'UTF-8') > 1) {
                    $cut = mb_strlen($word, 'UTF-8');
                    while ($cut > 1 && self::width($face, mb_substr($word, 0, $cut, 'UTF-8'), $size) > $maxWidth) {
                        $cut--;
                    }
                    $lines[] = mb_substr($word, 0, $cut, 'UTF-8');
                    $word = mb_substr($word, $cut, null, 'UTF-8');
                }
                $line = $word;
            }
            $lines[] = $line;
        }
        return $lines;
    }

    /**
     * One character as a small truecolor image with its ascent above the
     * baseline, cached for the request. null for a character with no outline.
     */
    private static function tile(string $face, string $char, float $size, array $colour): ?array
    {
        $key = $face . '|' . $size . '|' . $char . '|' . implode(',', $colour);
        if (array_key_exists($key, self::$tiles)) {
            return self::$tiles[$key];
        }

        $f = self::font($face);
        $gid = self::glyphId($f, $char);
        $contours = self::contours($f, $gid);
        if ($contours === []) {
            self::$tiles[$key] = null;
            return null;
        }

        $S = self::SUPER_SAMPLE;
        $upem = $f['upem'];
        $scale = $size / $upem * $S;

        $minX = $minY = PHP_FLOAT_MAX;
        $maxX = $maxY = PHP_FLOAT_MIN;
        foreach ($contours as $contour) {
            foreach ($contour as [$x, $y]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        $w = (int) ceil(($maxX - $minX) * $scale) + 2 * $S;
        $h = (int) ceil(($maxY - $minY) * $scale) + 2 * $S;
        if ($w < 1 || $h < 1) {
            self::$tiles[$key] = null;
            return null;
        }

        $big = imagecreatetruecolor($w, $h);
        imagesavealpha($big, true);
        imagefill($big, 0, 0, imagecolorallocatealpha($big, 0, 0, 0, 127));

        // Every contour of the glyph goes into one polygon call, so the holes
        // in an O, an e or a naira sign knock out the way they should.
        $alpha = isset($colour[3]) ? (int) $colour[3] : 0;
        $fill = imagecolorallocatealpha($big, (int) $colour[0], (int) $colour[1], (int) $colour[2], $alpha);
        $points = [];
        foreach ($contours as $contour) {
            foreach ($contour as [$x, $y]) {
                $points[] = (int) round(($x - $minX) * $scale + $S);
                $points[] = (int) round(($maxY - $y) * $scale + $S);
            }
        }
        imagefilledpolygon($big, $points, $fill);

        $w1 = max(1, (int) ceil($w / $S));
        $h1 = max(1, (int) ceil($h / $S));
        $small = imagecreatetruecolor($w1, $h1);
        imagesavealpha($small, true);
        imagefill($small, 0, 0, imagecolorallocatealpha($small, 0, 0, 0, 127));
        imagecopyresampled($small, $big, 0, 0, 0, 0, $w1, $h1, $w, $h);

        $tile = [
            'img'     => $small,
            'ascent'  => (int) ceil($maxY * $size / $upem),
        ];
        self::$tiles[$key] = $tile;
        return $tile;
    }

    /**
     * The outline of one glyph as polygons in font units: quadratic curves
     * flattened on their midpoints, composite components transformed by their
     * own matrix. An unknown glyph id returns no contours.
     */
    private static function contours(array $f, int $gid): array
    {
        if ($gid < 1) {
            return [];
        }
        $glyf = $f['glyf'];
        if (!isset($glyf->data[$gid])) {
            try {
                $glyf->getGlyphIDs([$gid]);
            } catch (Throwable $e) {
                return [];
            }
        }
        $glyph = $glyf->data[$gid] ?? null;
        if ($glyph instanceof OutlineSimple) {
            $glyph->parseData();
            return self::flattenSimple($glyph->points ?? []);
        }
        if ($glyph instanceof OutlineComposite) {
            $glyph->parseData();
            $out = [];
            foreach ($glyph->components as $component) {
                foreach (self::contours($f, (int) $component->glyphIndex) as $contour) {
                    $points = [];
                    foreach ($contour as [$x, $y]) {
                        $points[] = [
                            $component->a * $x + $component->c * $y + $component->e,
                            $component->b * $x + $component->d * $y + $component->f,
                        ];
                    }
                    $out[] = $points;
                }
            }
            return $out;
        }
        return [];
    }

    /** Split a simple glyph's point list into contours and curve them. */
    private static function flattenSimple(array $points): array
    {
        $contours = [];
        $current = [];
        foreach ($points as $p) {
            $current[] = $p;
            if (!empty($p['endOfContour'])) {
                $contours[] = $current;
                $current = [];
            }
        }

        $out = [];
        foreach ($contours as $contour) {
            $m = count($contour);
            if ($m < 2) {
                continue;
            }
            $start = null;
            for ($i = 0; $i < $m; $i++) {
                if ($contour[$i]['onCurve']) {
                    $start = $i;
                    break;
                }
            }
            if ($start === null) {
                // All points off-curve: begin at the midpoint of the closing span.
                $poly = [[($contour[0]['x'] + $contour[$m - 1]['x']) / 2, ($contour[0]['y'] + $contour[$m - 1]['y']) / 2]];
                $prev = $poly[0];
                $i = $m - 1;
            } else {
                $poly = [[$contour[$start]['x'], $contour[$start]['y']]];
                $prev = $poly[0];
                $i = $start;
            }
            $steps = self::CURVE_STEPS;
            $first = $i;
            do {
                $next = ($i + 1) % $m;
                if ($contour[$next]['onCurve']) {
                    $prev = [$contour[$next]['x'], $contour[$next]['y']];
                    $poly[] = $prev;
                    $i = $next;
                    continue;
                }
                $controls = [[$contour[$next]['x'], $contour[$next]['y']]];
                $j = $next;
                while (!$contour[($j + 1) % $m]['onCurve']) {
                    $j = ($j + 1) % $m;
                    $controls[] = [$contour[$j]['x'], $contour[$j]['y']];
                }
                $endIdx = ($j + 1) % $m;
                $end = [$contour[$endIdx]['x'], $contour[$endIdx]['y']];
                // A run of k control points splits into k quadratic segments.
                while (count($controls) > 1) {
                    $mid = [($controls[0][0] + $controls[1][0]) / 2, ($controls[0][1] + $controls[1][1]) / 2];
                    for ($s = 1; $s < $steps; $s++) {
                        $t = $s / $steps;
                        $mt = 1 - $t;
                        $poly[] = [
                            $mt * $mt * $prev[0] + 2 * $mt * $t * $controls[0][0] + $t * $t * $mid[0],
                            $mt * $mt * $prev[1] + 2 * $mt * $t * $controls[0][1] + $t * $t * $mid[1],
                        ];
                    }
                    $prev = $mid;
                    array_shift($controls);
                }
                for ($s = 1; $s <= $steps; $s++) {
                    $t = $s / $steps;
                    $mt = 1 - $t;
                    $poly[] = [
                        $mt * $mt * $prev[0] + 2 * $mt * $t * $controls[0][0] + $t * $t * $end[0],
                        $mt * $mt * $prev[1] + 2 * $mt * $t * $controls[0][1] + $t * $t * $end[1],
                    ];
                }
                $prev = $end;
                $i = $endIdx;
            } while ($i !== $first);
            $out[] = $poly;
        }
        return $out;
    }
}
