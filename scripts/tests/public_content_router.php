<?php
/** PHP-development-server equivalent of the production Apache content routes. */
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($path !== '/' && is_file(dirname(__DIR__, 2) . $path)) {
    return false;
}
$routes = [
    '/our-story' => 'about',
    '/how-it-works' => 'how-it-works',
    '/faq' => 'faq',
    '/terms' => 'terms',
    '/privacy' => 'privacy',
    '/delivery-policy' => 'delivery-policy',
];
$normalisedPath = rtrim($path, '/') ?: '/';
if ($path !== $normalisedPath && isset($routes[$normalisedPath])) {
    header('Location: ' . $normalisedPath, true, 301);
    return true;
}
if ($path === '/sitemap.xml') {
    require dirname(__DIR__, 2) . '/sitemap.php';
    return true;
}
if (isset($routes[$normalisedPath])) {
    $_GET['slug'] = $routes[$normalisedPath];
    require dirname(__DIR__, 2) . '/page.php';
    return true;
}
return false;
