<?php
/**
 * M12 content writes. Public reads never pass through this controller.
 * Every action is POST, CSRF protected and independently gated by content.edit.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';

function content_wants_json(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'fetch'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function content_return_path(string $slug): string
{
    return '/admin/content.php?tab=page-copy&page=' . rawurlencode($slug);
}

function content_finish(array $result, string $returnTo): void
{
    if (content_wants_json()) {
        okv_json(['status' => 'ok'] + $result);
    }
    okv_redirect($returnTo . '&notice=' . rawurlencode((string) ($result['code'] ?? 'updated')), 303);
}

function content_fail(array $result, int $status, string $returnTo): void
{
    if (content_wants_json()) {
        okv_json(['status' => 'error'] + $result, $status);
    }
    okv_redirect($returnTo . '&error=' . rawurlencode((string) ($result['code'] ?? 'failed')), 303);
}

if (!okv_is_post()) {
    okv_error('Use POST for this action.', 405, 'method_not_allowed');
}
Rbac::requirePermission('content.edit');
if (!Csrf::validate()) {
    okv_error('Your session expired. Reload the page and try again.', 419, 'csrf_expired');
}

$action = okv_action();
$slug = trim((string) okv_input('slug', ''));
$returnTo = content_return_path($slug);
if (!in_array($action, ['save_draft', 'upload_image', 'remove_image', 'publish', 'unpublish'], true)) {
    content_fail(['code' => 'unknown_action', 'message' => 'That content action is not available.'], 400, $returnTo);
}
if (!ContentPages::isSupportedSlug($slug)) {
    content_fail(['code' => 'unknown_page', 'message' => 'That page is not managed here.'], 404, $returnTo);
}

$fingerprint = trim((string) okv_input('fingerprint', ''));
try {
    if ($action === 'save_draft') {
        $contentData = $_POST['content_data'] ?? [];
        $result = ContentPages::updateDraft($slug, [
            'title' => okv_input('title', ''),
            'body' => okv_input('body', ''),
            'meta_title' => okv_input('meta_title', ''),
            'meta_description' => okv_input('meta_description', ''),
            'content_data' => is_array($contentData) ? $contentData : [],
        ], $fingerprint, (int) Rbac::userId());
    } elseif ($action === 'upload_image') {
        if (!in_array($slug, ['home', 'about'], true)) {
            content_fail(['code' => 'image_not_supported', 'message' => 'Photography is not managed for that page.'], 422, $returnTo);
        }
        $alt = trim((string) okv_input('image_alt', ''));
        if ($alt === '') {
            content_fail(['code' => 'image_alt_required', 'message' => 'Describe the photograph before uploading it.'], 422, $returnTo);
        }
        $beforePage = ContentPages::findForAdmin($slug);
        $stored = ContentImages::storeUploaded($_FILES['image'] ?? []);
        if (empty($stored['ok'])) {
            content_fail([
                'code' => (string) ($stored['code'] ?? 'invalid_image'),
                'message' => 'That photograph could not be prepared. Check its type, size and dimensions.',
            ], 422, $returnTo);
        }
        $newPath = (string) $stored['path'];
        try {
            $result = ContentPages::updateDraftImage($slug, $newPath, $alt, $fingerprint, (int) Rbac::userId());
        } catch (Throwable $e) {
            ContentImages::removeSet($newPath);
            throw $e;
        }
        if (empty($result['ok'])) {
            ContentImages::removeSet($newPath);
        } else {
            $oldDraft = (string) ($beforePage['image_url'] ?? '');
            $oldPublished = (string) ($beforePage['published']['image_url'] ?? '');
            if ($oldDraft !== '' && $oldDraft !== $oldPublished && $oldDraft !== $newPath) {
                ContentImages::removeSet($oldDraft);
            }
            $result['code'] = 'image_updated';
        }
    } elseif ($action === 'remove_image') {
        if (!in_array($slug, ['home', 'about'], true)) {
            content_fail(['code' => 'image_not_supported', 'message' => 'Photography is not managed for that page.'], 422, $returnTo);
        }
        $beforePage = ContentPages::findForAdmin($slug);
        $result = ContentPages::updateDraftImage($slug, '', '', $fingerprint, (int) Rbac::userId());
        if (!empty($result['ok'])) {
            $oldDraft = (string) ($beforePage['image_url'] ?? '');
            $oldPublished = (string) ($beforePage['published']['image_url'] ?? '');
            if ($oldDraft !== '' && $oldDraft !== $oldPublished) {
                ContentImages::removeSet($oldDraft);
            }
            $result['code'] = 'image_removed';
        }
    } elseif ($action === 'publish') {
        if ((string) okv_input('confirm', '') !== '1') {
            content_fail(['code' => 'confirmation_required', 'message' => 'Confirm publication before continuing.'], 422, $returnTo);
        }
        $result = ContentPages::publish(
            $slug,
            $fingerprint,
            (int) Rbac::userId(),
            (string) okv_input('legal_approved', '') === '1'
        );
    } else {
        if ((string) okv_input('confirm', '') !== '1') {
            content_fail(['code' => 'confirmation_required', 'message' => 'Confirm unpublishing before continuing.'], 422, $returnTo);
        }
        $result = ContentPages::unpublish($slug, (int) Rbac::userId(), $fingerprint);
    }
} catch (Throwable $e) {
    error_log('content.' . $action . ' failed: ' . $e->getMessage());
    content_fail(['code' => 'failed', 'message' => 'We could not update that page. Nothing was changed.'], 500, $returnTo);
}

if (empty($result['ok'])) {
    $code = (string) ($result['code'] ?? 'failed');
    $status = in_array($code, ['unknown_page', 'page_not_seeded'], true) ? 404
        : ($code === 'stale_draft' ? 409 : ($code === 'invalid_actor' ? 403 : 422));
    content_fail($result, $status, $returnTo);
}
content_finish($result, $returnTo);
