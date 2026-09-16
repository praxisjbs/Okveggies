<?php
/** Authorised, non-shareable preview of the current saved content draft. */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('content.view');

$slug = trim((string) okv_input('page', ''));
$page = null;
$loadFailed = false;
try {
    $page = ContentPages::findPreview($slug);
} catch (Throwable $e) {
    error_log('content.preview.read failed: ' . $e->getMessage());
    $loadFailed = true;
}
if (!$loadFailed && $page === null) {
    http_response_code(404);
}
if ($loadFailed) {
    http_response_code(503);
}

$registry = ContentPages::registry();
$label = (string) ($registry[$slug]['label'] ?? 'Content preview');
$okv_admin_title = $label . ' draft preview';
$okv_admin_note = 'A staff-only view of the latest saved draft. Unsaved editor changes are not included.';
$okv_admin_actions = $page
    ? '<a class="okv-btn-outline" href="/admin/content.php?tab=page-copy&amp;page=' . rawurlencode($slug) . '">Back to editor</a>'
    : '<a class="okv-btn-outline" href="/admin/content.php?tab=page-copy">Back to page copy</a>';
require __DIR__ . '/../includes/components/admin/header.php';
?>
<?php if ($loadFailed): ?>
  <section class="okv-panel okv-panel-body" role="alert"><h1 class="okv-panel-title">Preview unavailable</h1><p class="mt-2 text-sm text-ink-60">The saved draft could not be loaded. Please try again.</p></section>
<?php elseif (!$page): ?>
  <section class="okv-panel okv-panel-body"><h1 class="okv-panel-title">Draft not found</h1><p class="mt-2 text-sm text-ink-60">That page is not managed here.</p></section>
<?php else: ?>
  <div class="mx-auto max-w-4xl space-y-5">
    <aside class="okv-note bg-gold-tint text-gold-ink" role="status">Staff preview only. This URL requires content.view and is not a permanent public link.</aside>
    <article class="okv-panel okv-panel-body">
      <p class="font-mono text-xs text-ink-60"><?= okv_e($page['canonical_path']) ?></p>
      <h1 class="mt-3 font-editorial text-4xl text-forest"><?= okv_e($page['title'] !== '' ? $page['title'] : 'Untitled draft') ?></h1>
      <?php if ($page['image_url'] !== ''): ?><img class="mt-6 max-h-96 w-full rounded-md object-cover" src="<?= okv_e(okv_image_url($page['image_url'])) ?>" alt="<?= okv_e($page['image_alt']) ?>"><?php endif; ?>
      <?php if ($slug === 'faq'): $faq = ContentPages::validateFaq((string) $page['body']); ?>
        <?php if (!$faq['ok']): ?><p class="okv-note-bad mt-6">This FAQ draft is incomplete. Its raw saved copy is shown below.</p><div class="mt-6 whitespace-pre-wrap text-okv-body"><?= okv_e($page['body']) ?></div><?php else: ?><div class="mt-6 divide-y divide-mist"><?php foreach ($faq['items'] as $item): ?><details class="py-4"><summary class="min-h-[44px] cursor-pointer font-semibold text-forest"><?= okv_e($item['question']) ?></summary><div class="mt-2 whitespace-pre-wrap text-okv-body"><?= okv_e($item['answer']) ?></div></details><?php endforeach; ?></div><?php endif; ?>
      <?php else: ?><div class="mt-6 whitespace-pre-wrap text-okv-body"><?= okv_e($page['body']) ?></div><?php endif; ?>
    </article>
    <?php if ($slug === 'home'): ?><section class="okv-panel okv-panel-body"><h2 class="okv-panel-title">Homepage structured copy</h2><dl class="mt-4 grid gap-4 md:grid-cols-2"><?php foreach ($page['content_data'] as $key => $value): ?><div><dt class="text-xs font-semibold uppercase tracking-wide text-ink-60"><?= okv_e(str_replace('_', ' ', (string) $key)) ?></dt><dd class="mt-1 whitespace-pre-wrap text-sm"><?= okv_e((string) $value) ?></dd></div><?php endforeach; ?></dl></section><?php endif; ?>
    <section class="okv-panel okv-panel-body"><h2 class="okv-panel-title">Search metadata</h2><dl class="mt-4 grid gap-4 md:grid-cols-2"><div><dt class="text-sm text-ink-60">SEO title</dt><dd class="mt-1"><?= okv_e($page['meta_title'] !== '' ? $page['meta_title'] : 'Uses the page title') ?></dd></div><div><dt class="text-sm text-ink-60">SEO description</dt><dd class="mt-1"><?= okv_e($page['meta_description'] !== '' ? $page['meta_description'] : 'Not supplied') ?></dd></div></dl></section>
  </div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/components/admin/footer.php'; ?>
