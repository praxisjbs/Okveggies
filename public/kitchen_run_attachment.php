<?php
/**
 * public/kitchen_run_attachment.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Serve the list a customer uploaded with their Kitchen Run.
 *
 * This is a direct object reference, so it is gated three ways and answers 404
 * to every failure rather than distinguishing "not yours" from "not there":
 *
 *   1. Only the customer who owns the request, or a staff member with
 *      kitchen_runs.view, gets anything at all.
 *   2. The stored path has to match the shape Uploads::store() writes. A path
 *      that has been edited in the database, or was never one of ours, is
 *      refused before the filesystem is touched, so nothing here can be walked
 *      out of uploads/.
 *   3. The file has to actually be inside uploads/kitchen_runs after the path
 *      is resolved, which is belt and braces over the pattern above.
 *
 * The content type comes from the extension we ourselves assigned when the file
 * was stored, never from anything the uploader sent, and every response carries
 * nosniff so a browser cannot decide it knows better.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../includes/bootstrap.php';

/** Every refusal looks the same, so this route never confirms what exists. */
function kra_refuse(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$requestId = (int) okv_input('request', 0);
if ($requestId < 1) {
    kra_refuse();
}

$request = Database::one(
    'SELECT attachment_url, user_id FROM kitchen_run_requests WHERE id = :id',
    [':id' => $requestId]
);
if (!$request || $request['attachment_url'] === null) {
    kra_refuse();
}

$isOwner = Customer::isLoggedIn() && (int) Customer::id() === (int) $request['user_id'];
if (!$isOwner && !Rbac::can('kitchen_runs.view')) {
    kra_refuse();
}

// Uploads::store() writes uploads/<subdir>/<32 hex>.<ext> and nothing else.
$relative = (string) $request['attachment_url'];
if (preg_match('#^uploads/kitchen_runs/[a-f0-9]{32}\.(jpg|png|pdf)$#', $relative, $matches) !== 1) {
    kra_refuse();
}

$root = dirname(__DIR__);
$path = realpath($root . '/' . $relative);
$base = realpath($root . '/uploads/kitchen_runs');
if ($path === false || $base === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    kra_refuse();
}

$extension = $matches[1];
$type = ['pdf' => 'application/pdf', 'png' => 'image/png', 'jpg' => 'image/jpeg'][$extension];

header('Content-Type: ' . $type);
header('Content-Disposition: attachment; filename="kitchen-list-' . $requestId . '.' . $extension . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
