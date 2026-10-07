<?php
/**
 * includes/classes/PriceListMark.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The approved seal, placed on a GD canvas.
 *
 * The Owner's one-logo decision of 7 October 2026 retired the derived lockup
 * and the strict SVG painter that reproduced it: every surface, print
 * included, carries the photographic seal itself. The seal is decoded straight
 * from the generated raster (never redrawn, never restyled) and resampled to
 * the requested width; the mark is square, so the height follows the width.
 * -----------------------------------------------------------------------------
 */

final class PriceListMark
{
    /** The approved seal the documents print, per the Owner's one-logo decision. */
    public const SEAL = '/assets/img/brand/seal-640.png';

    /**
     * Paint the seal at the requested width and return the GD image.
     *
     * @return GdImage
     */
    public static function seal(int $targetWidth): object
    {
        $targetWidth = max(16, $targetWidth);
        $src = imagecreatefrompng(dirname(__DIR__, 2) . self::SEAL);
        if ($src === false) {
            throw new RuntimeException('The brand seal could not be read.');
        }
        $img = imagecreatetruecolor($targetWidth, $targetWidth);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagecopyresampled(
            $img,
            $src,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetWidth,
            imagesx($src),
            imagesy($src)
        );
        return $img;
    }
}
