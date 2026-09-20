<?php
/** Static and pure checks for the M12 Page Copy admin seam. */

$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/admin/content.php');
$preview = (string) file_get_contents($root . '/admin/content-preview.php');
$endpoint = (string) file_get_contents($root . '/api/v1/content.php');
$script = (string) file_get_contents($root . '/assets/js/admin-content.js');
$nav = (string) file_get_contents($root . '/includes/config/nav.php');

okv_test_eq(15, count(ContentPages::homeFields()), 'the admin can derive the fixed homepage field set from the service');
okv_test_ok(str_contains($page, 'ContentPages::listForAdmin()'), 'the editor lists pages through the shared service');
okv_test_ok(str_contains($page, 'ContentPages::findForAdmin'), 'the shareable page selection loads through the shared service');
okv_test_ok(str_contains($page, 'ContentPages::history'), 'the editor shows append-only content history');
okv_test_ok(str_contains($page, 'data-content-preview'), 'the editor offers a saved-draft preview');
okv_test_ok(str_contains($page, 'name="fingerprint"'), 'draft and publication forms carry an optimistic-lock token');
okv_test_ok(str_contains($page, 'name="legal_approved"'), 'legal publication requires an explicit client-approval confirmation');
okv_test_ok(str_contains($page, 'Legal readiness') && str_contains($page, 'Waiting for client copy'), 'the legal readiness panel names the client-owned dependency while legal pages are unpublished');
okv_test_ok(str_contains($page, 'enctype="multipart/form-data"') && str_contains($page, 'name="image_alt"'), 'the editor accepts an approved photograph with required alt text');
okv_test_ok(str_contains($page, 'Stock and synthetic documentary images are not accepted'), 'the editor states the documentary-image provenance rule');
okv_test_ok(!preg_match('/(?:SELECT|INSERT|UPDATE|DELETE)\s+.*content_pages/i', $page), 'the admin page contains no content SQL');

okv_test_ok(str_contains($preview, "Rbac::requirePermission('content.view')"), 'draft preview requires content.view on every request');
okv_test_ok(str_contains($preview, 'ContentPages::findPreview'), 'preview reads the saved draft through the service');
okv_test_ok(str_contains($preview, 'okv_e($page['), 'preview escapes stored content');
okv_test_ok(!str_contains($preview, 'findPublished'), 'preview does not confuse the public snapshot with the saved draft');
okv_test_ok(str_contains($preview, "header('X-Robots-Tag: noindex, nofollow')"), 'staff preview sends an HTTP noindex directive');

okv_test_ok(str_contains($endpoint, 'if (!okv_is_post())'), 'all content writes reject non-POST requests');
okv_test_ok(str_contains($endpoint, "Rbac::requirePermission('content.edit')"), 'all content writes require content.edit');
okv_test_ok(str_contains($endpoint, 'Csrf::validate()'), 'all content writes validate CSRF');
foreach (['updateDraft', 'updateDraftImage', 'publish', 'unpublish'] as $method) {
    okv_test_ok(str_contains($endpoint, 'ContentPages::' . $method), "the controller routes $method through the shared service");
}
okv_test_ok(method_exists(ContentPages::class, 'updateDraftImage'), 'the controller image method exists on the shared service');
okv_test_ok(str_contains($endpoint, 'error_log(') && !preg_match('/okv_(?:json|error)\([^;]*\$e->getMessage/s', $endpoint), 'database exceptions are logged but never returned');
okv_test_ok(!str_contains($endpoint, 'Database::'), 'the write controller contains no direct database access');
okv_test_ok(str_contains($endpoint, "['home', 'about']") && str_contains($endpoint, 'ContentImages::storeUploaded'), 'image writes are limited to the approved pages and use the responsive image helper');

okv_test_ok(str_contains($nav, "'permissions_any' => ['content.view', 'messages.view']"), 'shared navigation is visible for either viewing permission');
okv_test_ok(str_contains($page, '$canMessages ? ContactMessages::countNew() : 0'), 'page-copy does not count messages for a content-only viewer');
okv_test_ok(str_contains($page, '$canEdit = Rbac::can(\'content.edit\')'), 'viewing page copy does not imply editing it');
okv_test_ok(!str_contains($script, 'innerHTML'), 'content JavaScript never inserts stored data as HTML');
okv_test_ok(str_contains($script, 'beforeunload') && str_contains($script, 'last saved draft'), 'dirty edits are warned before leaving or previewing');
okv_test_ok(str_contains($script, "method: 'POST'") && str_contains($script, 'new FormData(form)'), 'progressive enhancement keeps POST form semantics');
