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

    private static function maxBytes(): int
    {
        return (int) env('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
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
}
