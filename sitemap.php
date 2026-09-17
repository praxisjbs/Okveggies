<?php
/** Complete public sitemap. Database failure is explicit, never partial. */
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=300');

try {
    echo Sitemap::xml(Sitemap::entries(), (string) APP_URL);
} catch (Throwable $e) {
    error_log('sitemap.build failed: ' . $e->getMessage());
    http_response_code(503);
    header('Retry-After: 300');
    header('X-Robots-Tag: noindex, nofollow');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    echo "<error>Sitemap temporarily unavailable.</error>\n";
}
