<?php
/**
 * includes/classes/Brand.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The brand tokens for the few places a stylesheet cannot reach:
 * an HTML email, where every rule has to be an inline style attribute, and a
 * generated PDF. Everywhere else the tokens come from tailwind.config.js and
 * the classes built from it. Never hand-type a colour; read it from here.
 *
 * These values mirror tailwind.config.js exactly, and BrandAssetsTest asserts
 * they still do, so the two can never drift apart.
 * -----------------------------------------------------------------------------
 */

final class Brand
{
    /** The four locked seal colours (bible 3.9). */
    public const FOREST       = '#0F5132';
    public const FOREST_HOVER = '#0D472C';
    public const FOREST_TINT  = '#E7EEEA';
    public const GOLD         = '#C9922B';
    public const GOLD_TINT    = '#FAF4EA';
    public const GOLD_INK     = '#7A5A18';
    public const TOMATO       = '#C8321E';
    public const TOMATO_TINT  = '#FAEAE8';
    public const FOLIAGE      = '#3E8B4A';
    public const FOLIAGE_TINT = '#ECF3ED';

    /** Canvas and text. */
    public const WHITE = '#FFFFFF';
    public const INK   = '#03100A';
    public const MIST  = '#EAE8E8';

    /**
     * Ink at 62%, flattened over white. The token itself is rgba(3,16,10,0.62),
     * and an email client cannot be trusted with rgba in an inline style, so
     * quiet body text in an email uses the flattened value. Derived from the
     * token, not picked by eye: BrandAssetsTest recomputes it.
     */
    public const INK_MUTED = '#636B67';

    /** The three brand faces, as an email-safe stack with real fallbacks. */
    public const FONT_SANS = "'Hanken Grotesk', 'Segoe UI', -apple-system, Arial, sans-serif";
    public const FONT_MONO = "'JetBrains Mono', 'SF Mono', Consolas, monospace";

    /**
     * Flatten a token that carries an alpha over a solid ground. Kept here so
     * the derivation lives beside the values it produced.
     * Brand::flatten(3, 16, 10, 0.62) -> "#636B67".
     */
    public static function flatten(int $r, int $g, int $b, float $alpha, string $overHex = self::WHITE): string
    {
        $over = ltrim($overHex, '#');
        $or = (int) hexdec(substr($over, 0, 2));
        $og = (int) hexdec(substr($over, 2, 2));
        $ob = (int) hexdec(substr($over, 4, 2));
        $mix = static fn(int $c, int $o): string
            => str_pad(strtoupper(dechex((int) round($c * $alpha + $o * (1 - $alpha)))), 2, '0', STR_PAD_LEFT);
        return '#' . $mix($r, $or) . $mix($g, $og) . $mix($b, $ob);
    }
}
