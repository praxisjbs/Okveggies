<?php
/**
 * includes/components/shop/picture.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One way to draw a catalogue photograph: WebP source when the
 * sibling exists, JPEG fallback, explicit width and height so the layout does
 * not jump, lazy and async everywhere except the one image a caller marks as
 * the hero. fetchpriority=high is reserved for that hero.
 */
require_once dirname(__DIR__, 2) . '/classes/ProductImages.php';

if (!function_exists('okv_picture')) {
    /**
     * @param array{
     *   class?:string,
     *   sizes?:string,
     *   lazy?:bool,
     *   priority?:bool
     * } $opts
     */
    function okv_picture(string $path, string $alt, array $opts = []): void
    {
        $class = (string) ($opts['class'] ?? 'h-full w-full object-cover');
        $sizes = (string) ($opts['sizes'] ?? '(min-width: 1024px) 25vw, 50vw');
        $lazy = array_key_exists('lazy', $opts) ? (bool) $opts['lazy'] : true;
        $priority = !empty($opts['priority']);
        $info = ProductImages::presentation($path);
        $src = okv_image_url($info['src']);
        $width = (int) $info['width'];
        $height = (int) $info['height'];

        $img = '<img src="' . okv_e($src) . '" alt="' . okv_e($alt) . '"';
        if ($width > 0 && $height > 0) {
            $img .= ' width="' . $width . '" height="' . $height . '"';
        }
        $img .= ' class="' . okv_e($class) . '" decoding="async"';
        if ($priority) {
            $img .= ' fetchpriority="high"';
        } elseif ($lazy) {
            $img .= ' loading="lazy"';
        }
        $img .= '>';

        if ($info['srcset'] !== '') {
            echo '<picture class="block h-full w-full">';
            echo '<source type="image/webp" srcset="' . okv_e($info['srcset']) . '" sizes="' . okv_e($sizes) . '">';
            echo $img;
            echo '</picture>';
            return;
        }
        echo $img;
    }
}
