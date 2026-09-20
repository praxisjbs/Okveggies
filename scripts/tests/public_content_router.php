<?php
/**
 * scripts/tests/public_content_router.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The PHP development-server equivalent of the production rules.
 *
 * The built-in server reads no .htaccess, so this router stands in for it: the
 * clean content routes and sitemap, the canonical redirects, and the
 * server-only paths that must never be served. Without the denial rules the
 * deployment smoke checks (`scripts/verify.sh`) cannot run anywhere except the
 * live host, which is why they went unrun for four milestones.
 *
 * What it emulates, and what it cannot:
 *   - FilesMatch on dotfiles and on source extensions     emulated
 *   - RedirectMatch on the server-only directories        emulated
 *   - uploads never execute PHP                           emulated
 *   - legacy content query URLs become canonical paths    emulated
 *   - trailing-slash normalisation and the sitemap route  emulated
 *   - the HTTPS force and mod_headers                     NOT emulated, because
 *     forcing HTTPS here would make every local test a redirect. Those two
 *     remain proven on the real host by the deploy run of `scripts/verify.sh`.
 *
 * Start it with:
 *   php -S 127.0.0.1:8123 -t . scripts/tests/public_content_router.php
 * -----------------------------------------------------------------------------
 */

$root  = dirname(__DIR__, 2);
$path  = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');

$deny = static function (): bool {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden\n";
    return true;
};

// 1. Dotfiles (.env and friends), with .well-known left reachable.
if (preg_match('#(^|/)\.(?!well-known(?:/|$))#', $path) === 1) {
    return $deny();
}

// 2. Source and data by extension, matching the FilesMatch in .htaccess.
if (preg_match('#\.(sql|md|sh|mjs|json|lock|ini|log|dist)$#i', $path) === 1) {
    return $deny();
}

// 3. Server-only directories.
if (preg_match('#^/(includes|migrations|scripts|docs|vendor|node_modules)(/|$)#', $path) === 1) {
    return $deny();
}

// 4. Uploads are served, never executed.
if (preg_match('#^/uploads/.*\.(php|phar|phtml|pl|py|cgi|sh)$#i', $path) === 1) {
    return $deny();
}

// 5. Legacy content query URLs become the canonical path, dropping the query.
$legacy = [
    'about'           => '/our-story',
    'how-it-works'    => '/how-it-works',
    'faq'             => '/faq',
    'terms'           => '/terms',
    'privacy'         => '/privacy',
    'delivery-policy' => '/delivery-policy',
];
if ($path === '/page.php'
    && preg_match('/(?:^|&)slug=([a-z0-9_-]+)(?:&|$)/', $query, $legacyMatch) === 1
    && isset($legacy[$legacyMatch[1]])) {
    header('Location: ' . $legacy[$legacyMatch[1]], true, 301);
    return true;
}

// 6. The clean content routes and the sitemap.
$routes = [
    '/our-story'       => 'about',
    '/how-it-works'    => 'how-it-works',
    '/faq'             => 'faq',
    '/terms'           => 'terms',
    '/privacy'         => 'privacy',
    '/delivery-policy' => 'delivery-policy',
];

$normalisedPath = rtrim($path, '/') ?: '/';

if ($path !== $normalisedPath && isset($routes[$normalisedPath])) {
    header('Location: ' . $normalisedPath, true, 301);
    return true;
}

if ($path === '/sitemap.xml') {
    require $root . '/sitemap.php';
    return true;
}

if (isset($routes[$normalisedPath])) {
    $_GET['slug'] = $routes[$normalisedPath];
    require $root . '/page.php';
    return true;
}

// 7. Anything that is a real file is served by the built-in server.
if ($path !== '/' && is_file($root . $path)) {
    return false;
}

return false;
