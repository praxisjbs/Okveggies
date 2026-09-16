<?php
/** Serve a private Make It Right photo to its customer or authorised staff. */
require_once __DIR__ . '/../includes/bootstrap.php';

function issue_photo_refuse(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo 'Not found.';
    exit;
}

$photo = IssueReports::photoAccessRecord((int) okv_input('photo', 0));
if ($photo === null) {
    issue_photo_refuse();
}

$isOwner = Customer::isLoggedIn() && (int) Customer::id() === (int) $photo['user_id'];
if (!$isOwner && !Rbac::can('issues.view')) {
    issue_photo_refuse();
}

$relative = (string) $photo['photo_url'];
if (preg_match('#^uploads/issues/[a-f0-9]{32}\.(jpg|png|webp)$#', $relative, $matches) !== 1) {
    issue_photo_refuse();
}

$root = dirname(__DIR__);
$base = realpath($root . '/uploads/issues');
$path = realpath($root . '/' . $relative);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    issue_photo_refuse();
}

$type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$matches[1]];
header('Content-Type: ' . $type);
header('Content-Disposition: inline; filename="order-' . (int) $photo['order_id'] . '-photo-' . (int) $photo['id'] . '.' . $matches[1] . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
