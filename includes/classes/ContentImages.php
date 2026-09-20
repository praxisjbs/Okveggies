<?php
/**
 * Responsive documentary images for managed content pages.
 *
 * The database stores the largest generated WebP path. Smaller siblings use
 * the same random stem, so no extra schema or second media catalogue is needed.
 */
final class ContentImages
{
    private const WIDTHS = [640, 960, 1280];
    private const QUALITY = 78;

    public static function storeUploaded(array $file): array
    {
        $validated = Uploads::validateUploadedImage($file);
        if (empty($validated['ok'])) {
            return ['ok' => false, 'code' => 'invalid_image'];
        }
        if (!function_exists('imagewebp') || !function_exists('imagecreatetruecolor')) {
            return ['ok' => false, 'code' => 'image_processing_unavailable'];
        }

        $tmp = (string) $file['tmp_name'];
        $mime = (string) $validated['mime'];
        $source = self::open($tmp, $mime);
        if ($source === false) {
            return ['ok' => false, 'code' => 'invalid_image'];
        }

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 640 || $sourceHeight < 360) {
            imagedestroy($source);
            return ['ok' => false, 'code' => 'image_too_small'];
        }

        $directory = dirname(__DIR__, 2) . '/uploads/content';
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            imagedestroy($source);
            throw new RuntimeException('Could not prepare the content image folder.');
        }

        $stem = bin2hex(random_bytes(16));
        $created = [];
        try {
            foreach (self::WIDTHS as $requestedWidth) {
                $width = min($requestedWidth, $sourceWidth);
                if (isset($created[$width])) {
                    continue;
                }
                $height = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
                $canvas = imagecreatetruecolor($width, $height);
                if ($canvas === false) {
                    throw new RuntimeException('Could not resize the content image.');
                }
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                imagefill($canvas, 0, 0, $transparent);
                if (!imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
                    imagedestroy($canvas);
                    throw new RuntimeException('Could not resize the content image.');
                }
                $filename = $stem . '-' . $width . '.webp';
                $absolute = $directory . '/' . $filename;
                if (!imagewebp($canvas, $absolute, self::QUALITY)) {
                    imagedestroy($canvas);
                    throw new RuntimeException('Could not write the content image.');
                }
                imagedestroy($canvas);
                $created[$width] = '/uploads/content/' . $filename;
            }
        } catch (Throwable $e) {
            foreach ($created as $path) {
                @unlink(dirname(__DIR__, 2) . $path);
            }
            imagedestroy($source);
            throw $e;
        }
        imagedestroy($source);

        ksort($created);
        $largestWidth = (int) array_key_last($created);
        return [
            'ok' => true,
            'path' => $created[$largestWidth],
            'width' => $largestWidth,
            'height' => max(1, (int) round($sourceHeight * ($largestWidth / $sourceWidth))),
            'srcset' => self::srcset($created),
        ];
    }

    public static function presentation(string $path): array
    {
        $unavailable = ['src' => $path, 'srcset' => '', 'width' => 0, 'height' => 0];
        if (preg_match('#^/uploads/content/([a-f0-9]{32})-([0-9]{3,4})\.webp$#', $path, $match) !== 1) {
            return $unavailable;
        }
        $mainWidth = (int) $match[2];
        $size = self::readSize($path, $mainWidth);
        if ($size === null) {
            return $unavailable;
        }
        $created = [];
        foreach (array_unique(array_merge(self::WIDTHS, [$mainWidth])) as $width) {
            $candidate = '/uploads/content/' . $match[1] . '-' . $width . '.webp';
            if ($width === $mainWidth || self::readSize($candidate, $width) !== null) {
                $created[$width] = $candidate;
            }
        }
        ksort($created);
        return [
            'src' => $path,
            'srcset' => self::srcset($created),
            'width' => (int) $size[0],
            'height' => (int) $size[1],
        ];
    }

    /** A missing, corrupt or mislabelled candidate must not enter a srcset. */
    private static function readSize(string $path, int $width): ?array
    {
        $absolute = dirname(__DIR__, 2) . $path;
        $size = is_file($absolute) ? @getimagesize($absolute) : false;
        if ($size === false || ($size['mime'] ?? '') !== 'image/webp'
            || (int) $size[0] !== $width || (int) $size[1] <= 0) {
            return null;
        }
        return $size;
    }

    public static function removeSet(string $path): void
    {
        if (preg_match('#^/uploads/content/([a-f0-9]{32})-([0-9]{3,4})\.webp$#', $path, $match) !== 1) {
            return;
        }
        $widths = array_values(array_unique(array_merge(self::WIDTHS, [(int) $match[2]])));
        foreach ($widths as $width) {
            $candidate = dirname(__DIR__, 2) . '/uploads/content/' . $match[1] . '-' . $width . '.webp';
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    private static function open(string $path, string $mime)
    {
        return match ($mime) {
            'image/jpeg' => function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false,
            'image/png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
    }

    private static function srcset(array $paths): string
    {
        $parts = [];
        foreach ($paths as $width => $path) {
            $parts[] = okv_image_url((string) $path) . ' ' . (int) $width . 'w';
        }
        return implode(', ', $parts);
    }
}
