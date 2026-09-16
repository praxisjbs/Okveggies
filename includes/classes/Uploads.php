<?php
/**
 * includes/classes/Uploads.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Safe file uploads. Every saved file gets an extension whitelist,
 * a MIME sniff, a size cap and a randomised name, and lands under uploads/
 * where PHP execution is denied by uploads/.htaccess. Used for product images,
 * kitchen-run list attachments, payment proofs and issue photos.
 * -----------------------------------------------------------------------------
 */

final class Uploads
{
    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/webp' => ['webp'],
    ];
    private const IMAGE_MAX_DIMENSION = 12000;
    private const IMAGE_MAX_PIXELS = 40000000;

    private const MIME_EXT = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2) . '/uploads';
    }

    public static function maxBytes(): int
    {
        return (int) env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
    }

    /**
     * Validate a real HTTP image upload before it is moved. The browser's MIME
     * claim is ignored. Fileinfo, the image header and the format ending must
     * all agree, which also catches common truncated-image cases without GD.
     */
    public static function validateUploadedImage(array $file, ?array $allowedMime = null): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'code' => $error === UPLOAD_ERR_NO_FILE ? 'missing' : 'upload_failed'];
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
            return ['ok' => false, 'code' => 'invalid_upload'];
        }

        $actualSize = filesize($tmp);
        $claimedSize = (int) ($file['size'] ?? 0);
        if ($actualSize === false || $actualSize < 1 || $claimedSize < 1) {
            return ['ok' => false, 'code' => 'empty'];
        }
        if ($actualSize > self::maxBytes() || $claimedSize > self::maxBytes()) {
            return ['ok' => false, 'code' => 'too_large'];
        }

        $name = (string) ($file['name'] ?? '');
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowedMime = $allowedMime ?? array_keys(self::IMAGE_MIME_EXTENSIONS);
        $allowedExtensions = [];
        foreach ($allowedMime as $mime) {
            foreach (self::IMAGE_MIME_EXTENSIONS[$mime] ?? [] as $allowedExtension) {
                $allowedExtensions[] = $allowedExtension;
            }
        }
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            return ['ok' => false, 'code' => 'unsupported_extension'];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmp);
        if (!in_array($mime, $allowedMime, true) || !isset(self::IMAGE_MIME_EXTENSIONS[$mime])) {
            return ['ok' => false, 'code' => 'unsupported_type'];
        }
        if (!in_array($extension, self::IMAGE_MIME_EXTENSIONS[$mime], true)) {
            return ['ok' => false, 'code' => 'disguised_type'];
        }

        $info = @getimagesize($tmp);
        if (!is_array($info) || (string) ($info['mime'] ?? '') !== $mime) {
            return ['ok' => false, 'code' => 'unreadable_image'];
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1
            || $width > self::IMAGE_MAX_DIMENSION || $height > self::IMAGE_MAX_DIMENSION
            || $width * $height > self::IMAGE_MAX_PIXELS) {
            return ['ok' => false, 'code' => 'unsafe_dimensions'];
        }
        if (!self::imageIsComplete($tmp, $mime, (int) $actualSize)) {
            return ['ok' => false, 'code' => 'truncated_image'];
        }

        return ['ok' => true, 'mime' => $mime, 'extension' => self::MIME_EXT[$mime]];
    }

    /**
     * Save a single uploaded file (an entry from $_FILES) into a subfolder of
     * uploads/. Returns the app-relative path (uploads/subdir/name.ext) or
     * throws on any validation failure.
     */
    public static function saveUploadedFile(array $file, string $subdir, ?array $allowedMime = null): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('That file did not upload. Please try again.');
        }
        if (($file['size'] ?? 0) > self::maxBytes()) {
            throw new RuntimeException('That file is too large.');
        }
        $tmp  = $file['tmp_name'] ?? '';
        if (!is_uploaded_file($tmp)) {
            throw new RuntimeException('Invalid upload.');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) $finfo->file($tmp);
        $allow = $allowedMime ?? array_keys(self::MIME_EXT);
        if (!in_array($mime, $allow, true) || !isset(self::MIME_EXT[$mime])) {
            throw new RuntimeException('That file type is not allowed.');
        }
        return self::store($tmp, $subdir, self::MIME_EXT[$mime]);
    }

    /** Save an image only after the stricter image-specific validation passes. */
    public static function saveUploadedImage(array $file, string $subdir, ?array $allowedMime = null): string
    {
        $validated = self::validateUploadedImage($file, $allowedMime);
        if (empty($validated['ok'])) {
            throw new RuntimeException('Image upload refused: ' . (string) ($validated['code'] ?? 'invalid'));
        }
        return self::store((string) $file['tmp_name'], $subdir, (string) $validated['extension']);
    }

    /** Remove only a randomised file previously created in the named folder. */
    public static function removeStoredFile(string $relativePath, string $subdir): bool
    {
        $subdir = preg_replace('/[^a-z0-9_\-]/', '', $subdir) ?: 'misc';
        if (preg_match('#^uploads/' . preg_quote($subdir, '#') . '/[a-f0-9]{32}\.(?:jpg|png|webp|pdf)$#', $relativePath) !== 1) {
            return false;
        }
        $base = realpath(self::root() . '/' . $subdir);
        $path = realpath(dirname(__DIR__, 2) . '/' . $relativePath);
        if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
            return false;
        }
        return unlink($path);
    }

    /** Save a base64 data image (for signature-style captures). */
    public static function saveBase64Image(string $data, string $subdir): string
    {
        if (preg_match('#^data:(image/(?:jpeg|png|webp));base64,#', $data, $m)) {
            $mime = $m[1];
            $data = substr($data, strpos($data, ',') + 1);
        } else {
            $mime = 'image/png';
        }
        $bin = base64_decode($data, true);
        if ($bin === false || strlen($bin) === 0) {
            throw new RuntimeException('Could not read that image.');
        }
        if (strlen($bin) > self::maxBytes()) {
            throw new RuntimeException('That image is too large.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'okv');
        file_put_contents($tmp, $bin);
        try {
            return self::store($tmp, $subdir, self::MIME_EXT[$mime] ?? 'png');
        } finally {
            @unlink($tmp);
        }
    }

    private static function store(string $tmp, string $subdir, string $ext): string
    {
        $subdir = preg_replace('/[^a-z0-9_\-]/', '', $subdir) ?: 'misc';
        $dir    = self::root() . '/' . $subdir;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not prepare the upload folder.');
        }
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $name;
        if (is_uploaded_file($tmp)) {
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('Could not save the file.');
            }
        } else {
            if (!copy($tmp, $dest)) {
                throw new RuntimeException('Could not save the file.');
            }
        }
        return 'uploads/' . $subdir . '/' . $name;
    }

    private static function imageIsComplete(string $path, string $mime, int $size): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $head = fread($handle, 12);
            if ($head === false) {
                return false;
            }
            if ($mime === 'image/jpeg') {
                if ($size < 4 || !str_starts_with($head, "\xFF\xD8")) {
                    return false;
                }
                fseek($handle, -2, SEEK_END);
                return fread($handle, 2) === "\xFF\xD9";
            }
            if ($mime === 'image/png') {
                if ($size < 20 || substr($head, 0, 8) !== "\x89PNG\r\n\x1A\n") {
                    return false;
                }
                fseek($handle, -12, SEEK_END);
                return fread($handle, 12) === "\x00\x00\x00\x00IEND\xAE\x42\x60\x82";
            }
            if ($mime === 'image/webp') {
                if ($size < 20 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') {
                    return false;
                }
                $declared = unpack('Vsize', substr($head, 4, 4));
                return (int) ($declared['size'] ?? -1) + 8 === $size;
            }
            return false;
        } finally {
            fclose($handle);
        }
    }
}
