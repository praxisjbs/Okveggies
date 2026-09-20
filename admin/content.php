<?php
/**
 * admin/content.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Content and Messages (PRD 4.3 and Section 18). Two tabs.
 *
 * Messages, built in M9: the contact submissions from the widget and the
 * contact page, read under `messages.view` and acted on under `messages.handle`.
 *
 * Page copy, built in M12: the homepage, Our Story, How It Works, FAQ and the
 * legal pages. The two modules share a route, but not a permission boundary.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
$canMessages = Rbac::can('messages.view');
$canContent = Rbac::can('content.view');
if (!$canMessages && !$canContent) {
    Rbac::requirePermission('messages.view');
}
$requestedTab = (string) okv_input('tab', '');
$tab = $requestedTab === 'page-copy' || (!$canMessages && $canContent) ? 'page-copy' : 'messages';
if ($tab === 'page-copy' && !$canContent) {
    Rbac::requirePermission('content.view');
}
if ($tab === 'messages' && !$canMessages) {
    Rbac::requirePermission('messages.view');
}

/** The tab strip both halves of this screen render. */
function okv_content_tabs(string $tab, int $newCount, bool $canMessages, bool $canContent): void
{
    $tabs = [];
    if ($canMessages) {
        $tabs['messages'] = 'Messages' . ($newCount > 0 ? ' (' . $newCount . ' new)' : '');
    }
    if ($canContent) {
        $tabs['page-copy'] = 'Page copy';
    }
    ?>
    <nav class="mb-5 flex flex-wrap gap-2 border-b border-mist" aria-label="Content and Messages sections">
      <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= okv_e($key === 'messages' ? '/admin/content.php' : '/admin/content.php?tab=page-copy') ?>"
           class="-mb-px inline-flex min-h-[44px] items-center border-b-2 px-4 text-sm font-medium <?= $tab === $key ? 'border-forest text-forest' : 'border-transparent text-ink-60 hover:text-ink' ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= okv_e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <?php
}

if ($tab === 'page-copy') {
    $contentError = false;
    $pages = [];
    $selected = null;
    $history = [];
    try {
        $pages = ContentPages::listForAdmin();
        $requestedSlug = trim((string) okv_input('page', ''));
        $selectedSlug = $requestedSlug !== '' ? $requestedSlug : (string) ($pages[0]['slug'] ?? '');
        $selected = $selectedSlug !== '' ? ContentPages::findForAdmin($selectedSlug) : null;
        $history = $selected ? ContentPages::history($selectedSlug, 20) : [];
    } catch (Throwable $e) {
        error_log('content.admin.read failed: ' . $e->getMessage());
        $contentError = true;
        $selectedSlug = '';
    }
    $canEdit = Rbac::can('content.edit');
    $newCount = $canMessages ? ContactMessages::countNew() : 0;
    $noticeCode = trim((string) okv_input('notice', ''));
    $errorCode = trim((string) okv_input('error', ''));
    $notices = [
        'updated' => 'The draft was saved.',
        'published' => 'The saved draft is now public.',
        'unpublished' => 'The page is no longer public.',
        'image_updated' => 'The documentary photograph was prepared and saved to the draft.',
        'image_removed' => 'The draft photograph was removed.',
        'unchanged' => 'Nothing changed.',
    ];
    $errors = [
        'validation_failed' => 'Some fields need attention. Nothing was changed.',
        'stale_draft' => 'Another staff member changed this draft. Reload it before saving.',
        'legal_approval_required' => 'Confirm that the legal wording is client-approved before publishing.',
        'confirmation_required' => 'Tick the confirmation box before changing publication status.',
        'page_not_seeded' => 'That managed page has not been seeded.',
        'unknown_page' => 'That page is not managed here.',
        'csrf_expired' => 'Your session expired. Reload the page and try again.',
        'invalid_image' => 'Choose a complete JPEG, PNG or WebP photograph within the upload limit.',
        'image_too_small' => 'Choose a photograph at least 640px wide and 360px high.',
        'image_processing_unavailable' => 'This server cannot prepare responsive photographs yet. Ask the host to enable PHP GD with WebP support.',
        'image_alt_required' => 'Describe the photograph before uploading it.',
        'image_not_supported' => 'Photography is managed only for the Homepage and Our Story.',
        'failed' => 'We could not update that page. Nothing was changed.',
    ];
    $fieldLabels = [
        'hero_eyebrow' => 'Hero eyebrow', 'hero_heading' => 'Hero heading', 'hero_intro' => 'Hero introduction',
        'primary_cta_label' => 'Primary button label', 'primary_cta_path' => 'Primary button destination',
        'secondary_cta_label' => 'Secondary button label', 'secondary_cta_path' => 'Secondary button destination',
        'promise_heading' => 'Promise heading', 'promise_body' => 'Promise copy',
        'combos_eyebrow' => 'Combos eyebrow', 'combos_heading' => 'Combos heading',
        'categories_eyebrow' => 'Categories eyebrow', 'categories_heading' => 'Categories heading',
        'products_eyebrow' => 'Products eyebrow', 'products_heading' => 'Products heading',
    ];
    $faq = $selected && $selected['slug'] === 'faq' ? ContentPages::validateFaq((string) $selected['body']) : null;
    $okv_admin_title = 'Content and Messages';
    $okv_admin_note  = 'The messages customers send you, and the page copy they read.';
    $okv_admin_script = '/assets/js/admin-content.js';
    require __DIR__ . '/../includes/components/admin/header.php';
    okv_content_tabs($tab, $newCount, $canMessages, $canContent);
    ?>
<?php if ($noticeCode !== ''): ?><p class="okv-note okv-note-ok mb-5" role="status"><?= okv_e($notices[$noticeCode] ?? 'The page was updated.') ?></p><?php endif; ?>
<?php if ($errorCode !== ''): ?><p class="okv-note-bad mb-5" role="alert"><?= okv_e($errors[$errorCode] ?? $errors['failed']) ?></p><?php endif; ?>
<?php if ($contentError): ?>
  <section class="okv-panel okv-panel-body" role="alert"><h2 class="okv-panel-title">Page copy is temporarily unavailable</h2><p class="mt-2 text-sm text-ink-60">We could not load the content store. No public copy was changed. Please try again.</p></section>
<?php elseif (!$pages): ?>
  <section class="okv-panel okv-panel-body"><h2 class="okv-panel-title">No managed pages are available</h2><p class="mt-2 text-sm text-ink-60">The fixed M12 pages have not been seeded. Run the approved migrations before editing content.</p></section>
<?php else: ?>
  <div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.5fr)]">
    <section class="okv-panel" aria-labelledby="page-list-heading">
      <div class="okv-panel-head"><h2 id="page-list-heading" class="okv-panel-title">Managed pages</h2><span class="text-xs text-ink-60"><?= count($pages) ?> pages</span></div>
      <ul class="divide-y divide-mist">
        <?php foreach ($pages as $page): $pageUrl = '/admin/content.php?tab=page-copy&page=' . rawurlencode((string) $page['slug']); ?>
          <li><a class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= $selected && $selected['slug'] === $page['slug'] ? 'bg-forest-tint' : '' ?>" href="<?= okv_e($pageUrl) ?>" <?= $selected && $selected['slug'] === $page['slug'] ? 'aria-current="page"' : '' ?>><span class="flex items-center justify-between gap-3"><strong class="text-sm"><?= okv_e($page['label']) ?></strong><span class="okv-badge <?= $page['is_published'] ? 'okv-badge-available' : 'okv-badge-warn' ?>"><?= $page['is_published'] ? 'Published' : 'Unpublished' ?></span></span><span class="mt-1 block font-mono text-xs text-ink-60"><?= okv_e($page['canonical_path']) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
    </section>

    <?php if (!$selected): ?>
      <section class="okv-panel okv-panel-body"><h2 class="okv-panel-title">Page not found</h2><p class="mt-2 text-sm text-ink-60">Choose one of the managed pages from the list.</p></section>
    <?php else: ?>
      <div class="space-y-5">
        <section class="okv-panel okv-panel-body" aria-labelledby="editor-heading">
          <div class="flex flex-wrap items-start justify-between gap-3"><div><h2 id="editor-heading" class="okv-panel-title"><?= okv_e($selected['label']) ?></h2><p class="mt-1 font-mono text-xs text-ink-60"><?= okv_e($selected['canonical_path']) ?></p></div><a class="okv-btn-outline" data-content-preview href="/admin/content-preview.php?page=<?= rawurlencode((string) $selected['slug']) ?>" target="_blank" rel="noopener">Preview saved draft</a></div>
          <p class="mt-3 text-xs text-ink-60">Last updated <?= $selected['updated_at'] !== '' ? okv_e(date('j M Y, H:i', strtotime((string) $selected['updated_at']))) : 'time not recorded' ?> by <?= okv_e((string) ($selected['updated_by_name'] ?: 'staff not recorded')) ?>.</p>
          <?php if (!$canEdit): ?><p class="okv-note mt-4 bg-gold-tint text-gold-ink">You can read and preview this copy. A staff member with content.edit is needed to save or publish it.</p><?php endif; ?>
          <p class="okv-note-bad mt-4 hidden" data-content-error role="alert"></p>
          <form class="mt-5 space-y-5" action="/api/v1/content.php" method="post" data-content-form data-save-form>
            <?= Csrf::field() ?><input type="hidden" name="action" value="save_draft"><input type="hidden" name="slug" value="<?= okv_e($selected['slug']) ?>"><input type="hidden" name="fingerprint" value="<?= okv_e($selected['fingerprint']) ?>">
            <div><label class="okv-label" for="content-title">Page title</label><input class="okv-input" id="content-title" name="title" maxlength="<?= ContentPages::TITLE_MAX ?>" value="<?= okv_e($selected['title']) ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field><p class="mt-1 hidden text-xs text-tomato" data-field-error="title"></p></div>
            <div><label class="okv-label" for="content-body"><?= $selected['slug'] === 'faq' ? 'Questions and answers' : 'Body copy' ?></label><textarea class="okv-input font-mono text-sm" id="content-body" name="body" rows="18" maxlength="<?= ContentPages::BODY_MAX ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field aria-describedby="content-body-help"><?= okv_e($selected['body']) ?></textarea><p class="mt-1 text-xs text-ink-60" id="content-body-help">Restricted Markdown only: paragraphs, lists, emphasis and links. HTML is not accepted.</p><p class="mt-1 hidden text-xs text-tomato" data-field-error="body"></p></div>
            <?php if ($selected['slug'] === 'faq'): ?>
              <aside class="rounded-md border border-mist bg-forest-tint p-4" aria-labelledby="faq-format-heading"><h3 class="text-sm font-semibold" id="faq-format-heading">FAQ format</h3><pre class="mt-2 whitespace-pre-wrap font-mono text-xs">## Question goes here
Answer copy goes here.</pre><p class="mt-2 text-sm"><?= count($faq['items']) ?> question<?= count($faq['items']) === 1 ? '' : 's' ?> found in document order.</p><?php if (!$faq['ok']): ?><div class="okv-note-bad mt-3" role="alert"><p class="font-semibold">This draft can be saved, but it cannot be published yet.</p><ul class="mt-2 list-disc space-y-1 pl-5"><?php foreach ($faq['errors'] as $message): ?><li><?= okv_e($message) ?></li><?php endforeach; ?></ul></div><?php else: ?><p class="okv-note-ok mt-3">Every question has an answer and no duplicates were found.</p><?php endif; ?></aside>
              <aside class="rounded-md border border-mist p-4" aria-labelledby="faq-order-heading"><h3 class="text-sm font-semibold" id="faq-order-heading">Published order</h3><?php if (!$faq['items']): ?><p class="mt-2 text-sm text-ink-60">No questions have been started. Begin with <code class="font-mono">##</code>.</p><?php else: ?><ol class="mt-2 list-decimal space-y-2 pl-5 text-sm"><?php foreach ($faq['items'] as $item): ?><li><?= okv_e((string) $item['question']) ?><?= trim((string) $item['answer']) === '' ? ' (answer missing)' : '' ?></li><?php endforeach; ?></ol><?php endif; ?><p class="mt-3 text-xs text-ink-60">Move a complete question section in the editor to change its public position.</p></aside>
              <aside class="rounded-md border border-mist p-4" aria-labelledby="faq-token-heading"><h3 class="text-sm font-semibold" id="faq-token-heading">Current operational values</h3><p class="mt-2 text-sm text-ink-60">Place a token in an answer. The public page reads its current value when the page opens.</p><dl class="mt-3 space-y-3"><?php foreach (FaqContent::tokenDefinitions() as $token => [$label, $help]): ?><div><dt><code class="font-mono text-xs">{{<?= okv_e($token) ?>}}</code> <span class="text-sm font-semibold"><?= okv_e($label) ?></span></dt><dd class="mt-1 text-xs text-ink-60"><?= okv_e($help) ?></dd></div><?php endforeach; ?></dl></aside>
            <?php endif; ?>
            <?php if ($selected['slug'] === 'home'): ?>
              <fieldset><legend class="okv-panel-title">Homepage sections</legend><p class="mt-1 text-sm text-ink-60">Photography is managed separately. These fields control the documentary hero and section copy.</p><div class="mt-4 grid gap-4 md:grid-cols-2">
                <?php foreach (ContentPages::homeFields() as $key => $max): $isLong = $key === 'promise_body' || $key === 'hero_intro'; ?>
                  <div class="<?= $isLong ? 'md:col-span-2' : '' ?>"><label class="okv-label" for="home-<?= okv_e($key) ?>"><?= okv_e($fieldLabels[$key] ?? $key) ?></label><?php if ($isLong): ?><textarea class="okv-input" id="home-<?= okv_e($key) ?>" name="content_data[<?= okv_e($key) ?>]" rows="4" maxlength="<?= (int) $max ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field><?= okv_e((string) ($selected['content_data'][$key] ?? '')) ?></textarea><?php else: ?><input class="okv-input" id="home-<?= okv_e($key) ?>" name="content_data[<?= okv_e($key) ?>]" maxlength="<?= (int) $max ?>" value="<?= okv_e((string) ($selected['content_data'][$key] ?? '')) ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field><?php endif; ?><p class="mt-1 hidden text-xs text-tomato" data-field-error="content_data.<?= okv_e($key) ?>"></p></div>
                <?php endforeach; ?>
              </div></fieldset>
            <?php endif; ?>
            <fieldset><legend class="okv-panel-title">Search and sharing</legend><div class="mt-4 grid gap-4 md:grid-cols-2"><div><label class="okv-label" for="meta-title">SEO title</label><input class="okv-input" id="meta-title" name="meta_title" maxlength="<?= ContentPages::META_TITLE_MAX ?>" value="<?= okv_e($selected['meta_title']) ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field><p class="mt-1 hidden text-xs text-tomato" data-field-error="meta_title"></p></div><div><label class="okv-label" for="meta-description">SEO description</label><textarea class="okv-input" id="meta-description" name="meta_description" rows="3" maxlength="<?= ContentPages::META_DESCRIPTION_MAX ?>" <?= $canEdit ? '' : 'disabled' ?> data-content-field><?= okv_e($selected['meta_description']) ?></textarea><p class="mt-1 hidden text-xs text-tomato" data-field-error="meta_description"></p></div></div></fieldset>
            <?php if ($canEdit): ?><div class="flex flex-wrap items-center gap-3"><button class="okv-btn" type="submit">Save draft</button><span class="hidden text-sm text-gold-ink" data-content-dirty>Unsaved changes</span></div><?php endif; ?>
          </form>
        </section>

        <?php if (in_array($selected['slug'], ['home', 'about'], true)): ?>
          <section class="okv-panel okv-panel-body" aria-labelledby="photo-heading">
            <h2 id="photo-heading" class="okv-panel-title">Documentary photograph</h2>
            <p class="mt-2 text-sm text-ink-60">Use a rights-cleared photograph of the real OK Veggies operation. Stock and synthetic documentary images are not accepted. The upload is converted into responsive WebP files.</p>
            <?php if ($selected['image_url'] !== ''): ?>
              <img class="mt-4 max-h-64 rounded-md object-cover" src="<?= okv_e(okv_image_url($selected['image_url'])) ?>" alt="<?= okv_e($selected['image_alt']) ?>">
              <dl class="mt-3 grid gap-2 text-sm"><div><dt class="text-ink-60">Path</dt><dd class="break-all font-mono text-xs"><?= okv_e($selected['image_url']) ?></dd></div><div><dt class="text-ink-60">Alternative text</dt><dd><?= okv_e($selected['image_alt']) ?></dd></div></dl>
            <?php else: ?>
              <p class="mt-3 rounded-md border border-mist bg-forest-tint p-4 text-sm text-ink-60">No draft documentary photograph is assigned. The public page will use its branded no-photo state.</p>
            <?php endif; ?>
            <?php if ($canEdit): ?>
              <form class="mt-5 space-y-4" action="/api/v1/content.php" method="post" enctype="multipart/form-data">
                <?= Csrf::field() ?><input type="hidden" name="action" value="upload_image"><input type="hidden" name="slug" value="<?= okv_e($selected['slug']) ?>"><input type="hidden" name="fingerprint" value="<?= okv_e($selected['fingerprint']) ?>">
                <div><label class="okv-label" for="content-image">Approved photograph</label><input class="okv-input min-h-[48px] py-2" id="content-image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required><p class="mt-1 text-xs text-ink-60">JPEG, PNG or WebP, at least 640px by 360px, up to <?= okv_e((string) round(Uploads::maxBytes() / 1048576, 1)) ?>MB.</p></div>
                <div><label class="okv-label" for="content-image-alt">Photograph description</label><input class="okv-input" id="content-image-alt" name="image_alt" maxlength="255" value="<?= okv_e($selected['image_alt']) ?>" required><p class="mt-1 text-xs text-ink-60">Describe the people, place and activity that matter in the photograph.</p></div>
                <button class="okv-btn" type="submit">Prepare draft photograph</button>
              </form>
              <?php if ($selected['image_url'] !== ''): ?><form class="mt-3" action="/api/v1/content.php" method="post"><?= Csrf::field() ?><input type="hidden" name="action" value="remove_image"><input type="hidden" name="slug" value="<?= okv_e($selected['slug']) ?>"><input type="hidden" name="fingerprint" value="<?= okv_e($selected['fingerprint']) ?>"><button class="okv-btn-outline" type="submit">Remove draft photograph</button></form><?php endif; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <section class="okv-panel okv-panel-body" aria-labelledby="publication-heading"><div class="flex flex-wrap items-center justify-between gap-3"><h2 id="publication-heading" class="okv-panel-title">Publication</h2><span class="okv-badge <?= $selected['is_published'] ? 'okv-badge-available' : 'okv-badge-warn' ?>"><?= $selected['is_published'] ? 'Published' : 'Unpublished' ?></span></div><?php if ($selected['published_at']): ?><p class="mt-2 text-sm text-ink-60">Published <?= okv_e(date('j M Y, H:i', strtotime((string) $selected['published_at']))) ?> by <?= okv_e((string) ($selected['published_by_name'] ?: 'staff not recorded')) ?>.</p><?php endif; ?>
          <?php if ($canEdit): ?><form class="mt-4 space-y-3" action="/api/v1/content.php" method="post" data-content-form><?= Csrf::field() ?><input type="hidden" name="action" value="<?= $selected['is_published'] ? 'unpublish' : 'publish' ?>"><input type="hidden" name="slug" value="<?= okv_e($selected['slug']) ?>"><input type="hidden" name="fingerprint" value="<?= okv_e($selected['fingerprint']) ?>"><label class="flex min-h-[44px] items-start gap-3"><input class="mt-1 h-5 w-5" type="checkbox" name="confirm" value="1"><span class="text-sm"><?= $selected['is_published'] ? 'I understand that guessed and saved public links will stop working.' : 'I have checked the saved draft and want to make it public.' ?></span></label><?php if ($selected['legal'] && !$selected['is_published']): ?><label class="flex min-h-[44px] items-start gap-3"><input class="mt-1 h-5 w-5" type="checkbox" name="legal_approved" value="1"><span class="text-sm">I confirm this is client-approved legal copy, not placeholder text or legal advice invented by the team.</span></label><?php endif; ?><button class="<?= $selected['is_published'] ? 'okv-btn-outline' : 'okv-btn' ?>" type="submit"><?= $selected['is_published'] ? 'Unpublish page' : 'Publish saved draft' ?></button></form><?php endif; ?>
        </section>

        <section class="okv-panel" aria-labelledby="history-heading"><div class="okv-panel-head"><h2 id="history-heading" class="okv-panel-title">Recent content history</h2><span class="text-xs text-ink-60">Latest 20</span></div><?php if (!$history): ?><p class="p-5 text-sm text-ink-60">No content changes have been recorded yet.</p><?php else: ?><ol class="divide-y divide-mist"><?php foreach ($history as $event): $actionLabel = match ($event['action']) { ContentPages::ACTION_DRAFT => 'Draft saved', ContentPages::ACTION_IMAGE => 'Photograph changed', ContentPages::ACTION_PUBLISH => 'Published', ContentPages::ACTION_UNPUBLISH => 'Unpublished', default => 'Content changed' }; ?><li class="px-4 py-3"><p class="text-sm font-medium"><?= okv_e($actionLabel) ?></p><p class="mt-1 text-xs text-ink-60"><?= okv_e(trim((string) $event['actor_name']) ?: 'Staff member') ?>, <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></p></li><?php endforeach; ?></ol><?php endif; ?></section>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
    <?php
    require __DIR__ . '/../includes/components/admin/footer.php';
    return;
}

$search = mb_substr(trim((string) okv_input('search', '')), 0, 100);
$status = in_array((string) okv_input('status', ''), ContactMessages::STATUSES, true)
    ? (string) okv_input('status', '')
    : '';
$from = ContactMessages::validDate((string) okv_input('from', '')) ? (string) okv_input('from', '') : '';
$to = ContactMessages::validDate((string) okv_input('to', '')) ? (string) okv_input('to', '') : '';
$total = ContactMessages::countForStaff($search, $status, $from, $to);
$pages = max(1, (int) ceil($total / ContactMessages::PER_PAGE));
$page = min(max(1, (int) okv_input('page', 1)), $pages);
$messages = ContactMessages::forStaff($search, $status, $from, $to, $page);
$selectedId = (int) okv_input('message', $messages ? $messages[0]['id'] : 0);
$selected = $selectedId > 0 ? ContactMessages::findForStaff($selectedId) : null;
$history = $selected ? ContactMessages::handlingHistory($selectedId) : [];
$canHandle = Rbac::can('messages.handle');
$hasFilters = $search !== '' || $status !== '' || $from !== '' || $to !== '';

$urlFor = static function (array $extra = []) use ($search, $status, $from, $to, $page): string {
    $query = array_filter(
        array_merge(['search' => $search, 'status' => $status, 'from' => $from, 'to' => $to, 'page' => $page > 1 ? $page : null], $extra),
        static fn($value): bool => $value !== '' && $value !== null
    );
    return '/admin/content.php' . ($query ? '?' . http_build_query($query) : '');
};
$detailUrl = $selected ? $urlFor(['message' => (int) $selected['id']]) : $urlFor();

$noticeCode = trim((string) okv_input('notice', ''));
$errorCode = trim((string) okv_input('error', ''));
$notices = [
    'note_saved' => 'The internal note has been saved.',
    'handled' => 'The message is marked handled.',
    'reopened' => 'The message is open again.',
    'unchanged' => 'Nothing changed.',
];
$errors = [
    'note_too_long' => 'Keep the internal note to 500 characters or fewer.',
    'stale' => 'This message changed after the page loaded. Reload it and try again.',
    'not_found' => 'That message could not be found.',
    'bad_status' => 'Choose a valid message status.',
    'failed' => 'We could not update that message. Please try again.',
];

$okv_admin_title = 'Content and Messages';
$okv_admin_note = 'The messages customers send you, and the page copy they read.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_content_tabs($tab, ContactMessages::countNew(), $canMessages, $canContent);
?>
<?php if ($noticeCode !== ''): ?>
  <p class="okv-note okv-note-ok mb-5" role="status"><?= okv_e($notices[$noticeCode] ?? 'The message was updated.') ?></p>
<?php endif; ?>
<?php if ($errorCode !== ''): ?>
  <p class="okv-note bg-clay-tint mb-5" role="alert"><?= okv_e($errors[$errorCode] ?? $errors['failed']) ?></p>
<?php endif; ?>

<?php if ($canHandle): ?>
<section class="okv-panel okv-panel-body mb-6 rounded-[20px] border border-ink-10 bg-white shadow-sm" aria-labelledby="composer-heading">
  <div class="flex items-start gap-4">
    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-forest text-sm font-bold text-white" aria-hidden="true">
      <?= okv_e(mb_strtoupper(mb_substr(trim((string) (($_SESSION['user_name'] ?? 'S') ?: 'S')), 0, 1))) ?>
    </div>
    <div class="min-w-0 flex-1">
      <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 id="composer-heading" class="font-display text-[22px] font-semibold leading-tight tracking-tight text-ink">Start a thread</h2>
        <span class="inline-flex items-center gap-1 rounded-full bg-forest/10 px-2.5 py-1 text-[11px] font-medium text-forest">Staff initiated</span>
      </div>
      <p class="mt-1 text-[13px] leading-snug text-ink-60">Proactive message to a customer. One subject, one message, email sent.</p>
      <form action="/api/v1/contact.php" method="post" class="mt-4 space-y-3">
        <?= Csrf::field() ?>
        <input type="hidden" name="action" value="staff_initiate">
        <input type="hidden" name="return_to" value="<?= okv_e($urlFor(['message' => null])) ?>">
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="okv-label text-xs" for="compose-name">Customer name</label>
            <input class="okv-input min-h-[44px] rounded-xl" id="compose-name" name="name" maxlength="150" required placeholder="Full name">
          </div>
          <div>
            <label class="okv-label text-xs" for="compose-email">Email</label>
            <input class="okv-input min-h-[44px] rounded-xl" id="compose-email" name="email" type="email" maxlength="254" placeholder="customer@example.com">
          </div>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <div>
            <label class="okv-label text-xs" for="compose-phone">Phone (optional)</label>
            <input class="okv-input min-h-[44px] rounded-xl" id="compose-phone" name="phone" maxlength="30" placeholder="0803 000 0000">
          </div>
          <div>
            <label class="okv-label text-xs" for="compose-subject">Subject</label>
            <input class="okv-input min-h-[44px] rounded-xl" id="compose-subject" name="subject" maxlength="200" placeholder="About your order">
          </div>
        </div>
        <div>
          <label class="okv-label text-xs" for="compose-message">Message</label>
          <textarea class="okv-input min-h-[96px] rounded-xl py-3" id="compose-message" name="message" maxlength="5000" required placeholder="Write a helpful message…"></textarea>
        </div>
        <div class="flex justify-end">
          <button type="submit" class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-xl bg-forest px-6 text-sm font-medium text-white shadow-sm transition hover:bg-forest/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 active:scale-[0.98]">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2Z"/></svg>
            Send message
          </button>
        </div>
      </form>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="okv-panel okv-panel-body mb-5 rounded-[16px]" aria-labelledby="message-filters-heading">
  <div class="flex flex-wrap items-baseline justify-between gap-3">
    <h2 id="message-filters-heading" class="okv-panel-title text-sm">Find messages</h2>
    <p class="text-xs text-ink-60"><?= okv_e(okv_page_summary($page, $total, ContactMessages::PER_PAGE, 'message')) ?></p>
  </div>
  <form action="/admin/content.php" method="get" class="mt-4 grid gap-3 md:grid-cols-5">
    <div class="md:col-span-2">
      <label class="okv-label text-xs" for="message-search">Search</label>
      <input class="okv-input-sm min-h-[44px] rounded-xl" id="message-search" name="search" type="search" maxlength="100" value="<?= okv_e($search) ?>" placeholder="Name, contact, subject or words">
    </div>
    <div>
      <label class="okv-label text-xs" for="message-status">Status</label>
      <select class="okv-input-sm min-h-[44px] rounded-xl" id="message-status" name="status">
        <option value="">All statuses</option>
        <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New</option>
        <option value="handled" <?= $status === 'handled' ? 'selected' : '' ?>>Handled</option>
      </select>
    </div>
    <div>
      <label class="okv-label text-xs" for="message-from">From</label>
      <input class="okv-input-sm min-h-[44px] rounded-xl" id="message-from" name="from" type="date" value="<?= okv_e($from) ?>">
    </div>
    <div>
      <label class="okv-label text-xs" for="message-to">To</label>
      <input class="okv-input-sm min-h-[44px] rounded-xl" id="message-to" name="to" type="date" value="<?= okv_e($to) ?>">
    </div>
    <div class="flex flex-wrap items-center gap-3 md:col-span-5">
      <button class="okv-btn-sm min-h-[44px] rounded-xl" type="submit">Apply filters</button>
      <?php if ($hasFilters): ?><a class="inline-flex min-h-[44px] items-center rounded-xl px-3 text-sm text-ink-60 hover:text-ink" href="/admin/content.php">Clear filters</a><?php endif; ?>
    </div>
  </form>
</section>

<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.85fr)_minmax(0,1.4fr)]">
  <section class="okv-panel rounded-[20px] border border-ink-10 bg-white shadow-sm" aria-labelledby="message-list-heading">
    <div class="okv-panel-head rounded-t-[20px]">
      <h2 id="message-list-heading" class="okv-panel-title text-sm">Newest messages</h2>
      <span class="rounded-full bg-mist px-2.5 py-1 text-xs text-ink-60"><?= (int) $total ?> total</span>
    </div>
    <?php if (!$messages): ?>
      <div class="flex flex-col items-center gap-3 p-8 text-center">
        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-mist" aria-hidden="true">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0F5132" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5"/><path d="M21 11.5c0-4.7-3.8-8.5-8.5-8.5S4 6.8 4 11.5c0 2.2.8 4.2 2.2 5.8L4 21l3.9-1.8A8.4 8.4 0 0 0 12.5 20"/><path d="M8 11h8"/><path d="M8 14h5"/></svg>
        </div>
        <h3 class="font-display text-[18px] font-semibold text-ink">No messages yet</h3>
        <p class="max-w-[22ch] text-sm leading-snug text-ink-60"><?= $hasFilters ? 'Nothing matched those filters. Clear and try again.' : 'Customer messages and staff threads appear here.' ?></p>
        <?php if ($hasFilters): ?><a href="/admin/content.php" class="okv-btn-outline min-h-[44px] rounded-xl">Clear filters</a><?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="divide-y divide-mist/80">
        <?php foreach ($messages as $message): ?>
          <?php
            $messageUrl = $urlFor(['message' => (int) $message['id']]);
            $initial = mb_strtoupper(mb_substr(trim((string) $message['name']), 0, 1)) ?: '?';
            $isStaff = !empty($message['is_staff_initiated']);
            $statusChip = $message['status'] === 'new' ? 'bg-amber-100 text-amber-800' : 'bg-forest/10 text-forest';
          ?>
          <li>
            <a href="<?= okv_e($messageUrl) ?>" class="flex gap-3 px-4 py-3 hover:bg-forest-tint/60 <?= (int) $message['id'] === $selectedId ? 'bg-forest-tint' : '' ?>" <?= (int) $message['id'] === $selectedId ? 'aria-current="page"' : '' ?>>
              <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-mist text-sm font-semibold text-ink" aria-hidden="true"><?= okv_e($initial) ?></span>
              <span class="min-w-0 flex-1">
                <span class="flex items-start justify-between gap-2">
                  <strong class="min-w-0 truncate text-[13px] font-medium text-ink"><?= okv_e($message['name']) ?></strong>
                  <span class="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium <?= $statusChip ?>"><?= $message['status'] === 'new' ? 'New' : 'Handled' ?><?= $isStaff ? ' • Staff' : '' ?></span>
                </span>
                <span class="mt-1 block truncate text-[13px] text-ink-60"><?= okv_e(trim((string) ($message['subject'] ?? '')) ?: 'No subject') ?></span>
                <span class="mt-1 flex flex-wrap justify-between gap-2 text-[11px] text-ink-60">
                  <span class="truncate"><?= okv_e((string) ($message['email'] ?: Phone::display((string) $message['phone']))) ?></span>
                  <time datetime="<?= okv_e($message['created_at']) ?>"><?= okv_e(date('j M, H:i', strtotime((string) $message['created_at']))) ?></time>
                </span>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="border-t border-mist/80 p-3"><?php okv_pagination($page, $pages, static fn(int $number): string => $urlFor(['page' => $number, 'message' => null]), 'Message pages'); ?></div>
    <?php endif; ?>
  </section>

  <section class="okv-panel rounded-[20px] border border-ink-10 bg-white shadow-sm" aria-labelledby="message-detail-heading">
    <?php if (!$selected): ?>
      <div class="flex flex-col items-center gap-3 p-8 text-center">
        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-forest-tint" aria-hidden="true">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#0F5132" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg>
        </div>
        <h2 id="message-detail-heading" class="font-display text-[20px] font-semibold text-ink"><?= $selectedId > 0 ? 'Message not found' : 'Choose a message' ?></h2>
        <p class="max-w-[28ch] text-sm leading-snug text-ink-60"><?= $selectedId > 0 ? 'It may no longer be available.' : 'Open a message from the list to read it and record what happened.' ?></p>
      </div>
    <?php else: ?>
      <?php
        $email = filter_var((string) ($selected['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $selected['email'] : '';
        $phone = Phone::normalize((string) ($selected['phone'] ?? ''));
        $replySubject = 'Re: ' . (trim((string) ($selected['subject'] ?? '')) ?: 'Your message to OK Veggies');
        $whatsAppText = 'Hello ' . (string) $selected['name'] . ', this is OK Veggies replying to your message.';
        $initial = mb_strtoupper(mb_substr(trim((string) $selected['name']), 0, 1)) ?: '?';
      ?>
      <div class="okv-panel-head rounded-t-[20px]">
        <div class="flex items-center gap-3 min-w-0">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-forest text-sm font-bold text-white"><?= okv_e($initial) ?></span>
          <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-wide text-ink-60">Message <?= (int) $selected['id'] ?><?= !empty($selected['is_staff_initiated']) ? ' • Staff initiated' : '' ?></p>
            <h2 id="message-detail-heading" class="mt-0.5 truncate font-display text-[18px] font-semibold leading-tight text-ink"><?= okv_e(trim((string) ($selected['subject'] ?? '')) ?: 'No subject') ?></h2>
          </div>
        </div>
        <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-medium <?= $selected['status'] === 'new' ? 'bg-amber-100 text-amber-800' : 'bg-forest/10 text-forest' ?>"><?= $selected['status'] === 'new' ? 'New' : 'Handled' ?></span>
      </div>

      <div class="space-y-5 p-5">
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">From</dt><dd class="mt-1 font-medium text-ink"><?= okv_e($selected['name']) ?></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">Received</dt><dd class="mt-1 text-ink-60"><time datetime="<?= okv_e($selected['created_at']) ?>"><?= okv_e(date('j M Y, H:i', strtotime((string) $selected['created_at']))) ?></time></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">Email</dt><dd class="mt-1 break-words text-ink-60"><?= $email !== '' ? okv_e($email) : 'Not provided' ?></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">Phone</dt><dd class="mt-1 text-ink-60"><?= $phone !== null ? okv_e(Phone::display($phone)) : 'Not provided' ?></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">Source</dt><dd class="mt-1 text-ink-60"><?= okv_e(ucfirst(str_replace('_', ' ', (string) $selected['source']))) ?></dd></div>
          <div><dt class="text-[11px] uppercase tracking-wide text-ink-60">Handling</dt><dd class="mt-1 text-ink-60"><?php if ($selected['status'] === 'handled'): ?>Handled by <?= okv_e(trim((string) $selected['handled_by_name']) ?: 'a former team member') ?><?= $selected['handled_at'] ? ' on ' . okv_e(date('j M Y, H:i', strtotime((string) $selected['handled_at']))) : ', time not recorded' ?><?php else: ?>Waiting for a team member<?php endif; ?></dd></div>
        </dl>

        <div class="rounded-xl border border-mist bg-mist/30 p-4">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-60">Message</h3>
          <p class="mt-2 whitespace-pre-wrap break-words text-[14px] leading-relaxed text-ink"><?= okv_e($selected['message']) ?></p>
        </div>

        <div>
          <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-60">Reply outside the website</h3>
          <p class="mt-1 text-xs leading-snug text-ink-60">These links open your email, phone or WhatsApp app. The website does not send or record a reply.</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <?php if ($email !== ''): ?><a class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm hover:bg-mist" href="mailto:<?= okv_e($email) ?>?subject=<?= okv_e(rawurlencode($replySubject)) ?>">Open email app</a><?php endif; ?>
            <?php if ($phone !== null): ?>
              <a class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm hover:bg-mist" href="tel:<?= okv_e($phone) ?>">Call</a>
              <a class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm hover:bg-mist" href="https://wa.me/<?= okv_e(substr($phone, 1)) ?>?text=<?= okv_e(rawurlencode($whatsAppText)) ?>" target="_blank" rel="noopener">Open WhatsApp</a>
            <?php endif; ?>
            <?php if ($email === '' && $phone === null): ?><p class="text-sm text-ink-60">No usable reply details were provided.</p><?php endif; ?>
          </div>
        </div>

        <details class="group rounded-xl border border-ink-10">
          <summary class="flex min-h-[44px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium text-ink">
            <span>Internal note</span>
            <svg class="h-4 w-4 shrink-0 transition group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div class="border-t border-ink-10 p-4">
            <?php if ($canHandle): ?>
              <form action="/api/v1/contact.php" method="post" class="space-y-3">
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="save_note">
                <input type="hidden" name="message_id" value="<?= (int) $selected['id'] ?>">
                <input type="hidden" name="expected_note" value="<?= okv_e((string) ($selected['admin_note'] ?? '')) ?>">
                <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
                <label class="okv-label text-xs" for="admin-note">Only staff can see this note</label>
                <textarea class="okv-input min-h-24 rounded-xl py-3" id="admin-note" name="admin_note" maxlength="500"><?= okv_e((string) ($selected['admin_note'] ?? '')) ?></textarea>
                <button class="inline-flex min-h-[44px] items-center rounded-xl border border-ink-10 px-4 text-sm hover:bg-mist" type="submit">Save note</button>
              </form>
            <?php else: ?>
              <p class="whitespace-pre-wrap text-sm text-ink-60"><?= trim((string) ($selected['admin_note'] ?? '')) !== '' ? okv_e($selected['admin_note']) : 'No internal note.' ?></p>
            <?php endif; ?>
          </div>
        </details>

        <?php if ($canHandle): ?>
          <div class="border-t border-mist pt-4">
            <form action="/api/v1/contact.php" method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="<?= $selected['status'] === 'new' ? 'handle' : 'reopen' ?>">
              <input type="hidden" name="message_id" value="<?= (int) $selected['id'] ?>">
              <input type="hidden" name="expected_status" value="<?= okv_e($selected['status']) ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
              <button class="<?= $selected['status'] === 'new' ? 'inline-flex min-h-[44px] items-center justify-center rounded-xl bg-forest px-5 text-sm font-medium text-white hover:bg-forest/90' : 'inline-flex min-h-[44px] items-center justify-center rounded-xl border border-ink-10 px-5 text-sm hover:bg-mist' ?>" type="submit"><?= $selected['status'] === 'new' ? 'Mark handled' : 'Reopen message' ?></button>
            </form>
          </div>
        <?php endif; ?>

        <div class="border-t border-mist pt-4">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-ink-60">Handling history</h3>
          <?php if (!$history): ?>
            <p class="mt-2 text-sm text-ink-60">No handling changes have been recorded.</p>
          <?php else: ?>
            <ol class="mt-3 space-y-3">
              <?php foreach ($history as $event): ?>
                <?php
                  $label = [
                      'contact_messages.create' => 'Message received',
                      'contact_messages.note.update' => 'Internal note updated',
                      'contact_messages.handle' => 'Marked handled',
                      'contact_messages.reopen' => 'Reopened',
                  ][$event['action']] ?? 'Message updated';
                ?>
                <li class="flex gap-3">
                  <span class="mt-1 h-2 w-2 shrink-0 rounded-full bg-forest"></span>
                  <span class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink"><?= okv_e($label) ?></p>
                    <p class="text-xs text-ink-60"><?= okv_e(trim((string) $event['actor_name']) ?: 'Storefront visitor') ?>, <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></p>
                  </span>
                </li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
