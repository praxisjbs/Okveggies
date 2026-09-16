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
if (isset($routes[rtrim($path, '/') ?: '/'])) {
    $_GET['slug'] = $routes[rtrim($path, '/') ?: '/'];
    require dirname(__DIR__, 2) . '/page.php';
    return true;
}
return false;

