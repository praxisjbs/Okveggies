<?php
/**
 * admin/content.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Content and Messages (PRD 4.3 and Section 18). Two tabs.
 *
 * Messages, built in M9: the contact submissions from the widget and the
 * contact page, read under `messages.view` and acted on under `messages.handle`.
 *
 * Page copy, which M12 builds: Our Story, How It Works, the questions and the
 * legal pages. M9 does not touch it. The tab is here so the screen matches the
 * nav and the PRD, and so M12 has somewhere to land without moving anything.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pagination.php';
Rbac::requirePermission('messages.view');

$tab = okv_input('tab', '') === 'page-copy' ? 'page-copy' : 'messages';

/** The tab strip both halves of this screen render. */
function okv_content_tabs(string $tab, int $newCount): void
{
    $tabs = [
        'messages'  => 'Messages' . ($newCount > 0 ? ' (' . $newCount . ' new)' : ''),
        'page-copy' => 'Page copy',
    ];
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
    $okv_admin_title = 'Content and Messages';
    $okv_admin_note  = 'The messages customers send you, and the page copy they read.';
    require __DIR__ . '/../includes/components/admin/header.php';
    okv_content_tabs($tab, ContactMessages::countNew());
    ?>
  <section class="okv-panel okv-panel-body" aria-labelledby="page-copy-heading">
    <h2 id="page-copy-heading" class="okv-panel-title">Page copy</h2>
    <p class="mt-2 max-w-2xl text-sm text-ink-60">
      Our Story, How It Works, the questions and answers and the legal pages are
      edited here rather than in code. That half of this screen is built in
      milestone M12, which owns the storefront pages it feeds. Nothing on the
      Messages tab depends on it, and M12 can land here without moving anything.
    </p>
    <p class="mt-3 max-w-2xl text-sm text-ink-60">The plan for it is in <code>docs/PRD.md</code> Section 18.</p>
    <div class="mt-5 flex flex-wrap gap-3">
      <a class="okv-btn-outline" href="/admin/content.php">Read the messages</a>
      <a class="okv-btn-text min-h-[44px]" href="/">See the shop as a customer does</a>
    </div>
  </section>
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

okv_content_tabs($tab, ContactMessages::countNew());
?>


<?php if ($noticeCode !== ''): ?>
  <p class="okv-note okv-note-ok mb-5" role="status"><?= okv_e($notices[$noticeCode] ?? 'The message was updated.') ?></p>
<?php endif; ?>
<?php if ($errorCode !== ''): ?>
  <p class="okv-note bg-clay-tint mb-5" role="alert"><?= okv_e($errors[$errorCode] ?? $errors['failed']) ?></p>
<?php endif; ?>

<section class="okv-panel okv-panel-body mb-5" aria-labelledby="message-filters-heading">
  <div class="flex flex-wrap items-baseline justify-between gap-3">
    <h2 id="message-filters-heading" class="okv-panel-title">Find messages</h2>
    <p class="text-sm text-ink-60"><?= okv_e(okv_page_summary($page, $total, ContactMessages::PER_PAGE, 'message')) ?></p>
  </div>
  <form action="/admin/content.php" method="get" class="mt-4 grid gap-3 md:grid-cols-5">
    <div class="md:col-span-2">
      <label class="okv-label" for="message-search">Search</label>
      <input class="okv-input-sm" id="message-search" name="search" type="search" maxlength="100" value="<?= okv_e($search) ?>" placeholder="Name, contact, subject or words">
    </div>
    <div>
      <label class="okv-label" for="message-status">Status</label>
      <select class="okv-input-sm" id="message-status" name="status">
        <option value="">All statuses</option>
        <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>New</option>
        <option value="handled" <?= $status === 'handled' ? 'selected' : '' ?>>Handled</option>
      </select>
    </div>
    <div>
      <label class="okv-label" for="message-from">Received from</label>
      <input class="okv-input-sm" id="message-from" name="from" type="date" value="<?= okv_e($from) ?>">
    </div>
    <div>
      <label class="okv-label" for="message-to">Received to</label>
      <input class="okv-input-sm" id="message-to" name="to" type="date" value="<?= okv_e($to) ?>">
    </div>
    <div class="flex flex-wrap items-center gap-3 md:col-span-5">
      <button class="okv-btn-sm" type="submit">Apply filters</button>
      <?php if ($hasFilters): ?><a class="okv-btn-text min-h-[44px]" href="/admin/content.php">Clear filters</a><?php endif; ?>
    </div>
  </form>
</section>

<div class="grid gap-5 xl:grid-cols-[minmax(18rem,0.8fr)_minmax(0,1.4fr)]">
  <section class="okv-panel" aria-labelledby="message-list-heading">
    <div class="okv-panel-head">
      <h2 id="message-list-heading" class="okv-panel-title">Newest messages</h2>
      <span class="text-xs text-ink-60"><?= (int) $total ?> total</span>
    </div>
    <?php if (!$messages): ?>
      <div class="p-5 text-sm text-ink-60">
        <?php if ($hasFilters): ?>
          <p>Nothing matched those filters.</p>
          <a href="/admin/content.php" class="okv-btn-text mt-3">Clear filters</a>
        <?php else: ?>
          <p>No contact messages have arrived yet.</p>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <ul class="divide-y divide-mist">
        <?php foreach ($messages as $message): ?>
          <?php $messageUrl = $urlFor(['message' => (int) $message['id']]); ?>
          <li>
            <a href="<?= okv_e($messageUrl) ?>" class="block min-h-[44px] px-4 py-3 hover:bg-forest-tint <?= (int) $message['id'] === $selectedId ? 'bg-forest-tint' : '' ?>" <?= (int) $message['id'] === $selectedId ? 'aria-current="page"' : '' ?>>
              <span class="flex items-start justify-between gap-3">
                <strong class="min-w-0 truncate text-sm"><?= okv_e($message['name']) ?></strong>
                <span class="okv-badge <?= $message['status'] === 'new' ? 'okv-badge-warn' : 'okv-badge-available' ?>"><?= $message['status'] === 'new' ? 'New' : 'Handled' ?></span>
              </span>
              <span class="mt-1 block truncate text-sm text-ink-60"><?= okv_e(trim((string) ($message['subject'] ?? '')) ?: 'No subject') ?></span>
              <span class="mt-1 flex flex-wrap justify-between gap-2 text-xs text-ink-60">
                <span class="truncate"><?= okv_e((string) ($message['email'] ?: Phone::display((string) $message['phone']))) ?></span>
                <time datetime="<?= okv_e($message['created_at']) ?>"><?= okv_e(date('j M, H:i', strtotime((string) $message['created_at']))) ?></time>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php okv_pagination($page, $pages, static fn(int $number): string => $urlFor(['page' => $number, 'message' => null]), 'Message pages'); ?>
    <?php endif; ?>
  </section>

  <section class="okv-panel" aria-labelledby="message-detail-heading">
    <?php if (!$selected): ?>
      <div class="p-6 text-center text-sm text-ink-60">
        <h2 id="message-detail-heading" class="font-editorial text-okv-h6 text-ink"><?= $selectedId > 0 ? 'Message not found' : 'Choose a message' ?></h2>
        <p class="mt-2"><?= $selectedId > 0 ? 'It may no longer be available.' : 'Open a message from the list to read it and record what happened.' ?></p>
      </div>
    <?php else: ?>
      <?php
        $email = filter_var((string) ($selected['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $selected['email'] : '';
        $phone = Phone::normalize((string) ($selected['phone'] ?? ''));
        $replySubject = 'Re: ' . (trim((string) ($selected['subject'] ?? '')) ?: 'Your message to OK Veggies');
        $whatsAppText = 'Hello ' . (string) $selected['name'] . ', this is OK Veggies replying to your message.';
      ?>
      <div class="okv-panel-head">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wide text-ink-60">Message <?= (int) $selected['id'] ?></p>
          <h2 id="message-detail-heading" class="mt-1 font-editorial text-okv-h6 text-ink"><?= okv_e(trim((string) ($selected['subject'] ?? '')) ?: 'No subject') ?></h2>
        </div>
        <span class="okv-badge <?= $selected['status'] === 'new' ? 'okv-badge-warn' : 'okv-badge-available' ?>"><?= $selected['status'] === 'new' ? 'New' : 'Handled' ?></span>
      </div>

      <div class="space-y-6 p-5">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
          <div><dt class="text-ink-60">From</dt><dd class="mt-1 font-medium"><?= okv_e($selected['name']) ?></dd></div>
          <div><dt class="text-ink-60">Received</dt><dd class="mt-1"><time datetime="<?= okv_e($selected['created_at']) ?>"><?= okv_e(date('j M Y, H:i', strtotime((string) $selected['created_at']))) ?></time></dd></div>
          <div><dt class="text-ink-60">Email</dt><dd class="mt-1 break-words"><?= $email !== '' ? okv_e($email) : 'Not provided' ?></dd></div>
          <div><dt class="text-ink-60">Phone</dt><dd class="mt-1"><?= $phone !== null ? okv_e(Phone::display($phone)) : 'Not provided' ?></dd></div>
          <div><dt class="text-ink-60">Source</dt><dd class="mt-1"><?= okv_e(ucfirst(str_replace('_', ' ', (string) $selected['source']))) ?></dd></div>
          <div><dt class="text-ink-60">Handling</dt><dd class="mt-1"><?php if ($selected['status'] === 'handled'): ?>Handled by <?= okv_e(trim((string) $selected['handled_by_name']) ?: 'a former team member') ?><?= $selected['handled_at'] ? ' on ' . okv_e(date('j M Y, H:i', strtotime((string) $selected['handled_at']))) : ', time not recorded' ?><?php else: ?>Waiting for a team member<?php endif; ?></dd></div>
        </dl>

        <div>
          <h3 class="text-sm font-semibold text-ink">Message</h3>
          <p class="mt-2 whitespace-pre-wrap break-words rounded-md border border-mist bg-white p-4 text-sm leading-relaxed"><?= okv_e($selected['message']) ?></p>
        </div>

        <div>
          <h3 class="text-sm font-semibold text-ink">Reply outside the website</h3>
          <p class="mt-1 text-xs text-ink-60">These links open your email, phone or WhatsApp app. The website does not send or record a reply.</p>
          <div class="mt-3 flex flex-wrap gap-2">
            <?php if ($email !== ''): ?><a class="okv-btn-outline" href="mailto:<?= okv_e($email) ?>?subject=<?= okv_e(rawurlencode($replySubject)) ?>">Open email app</a><?php endif; ?>
            <?php if ($phone !== null): ?>
              <a class="okv-btn-outline" href="tel:<?= okv_e($phone) ?>">Call</a>
              <a class="okv-btn-outline" href="https://wa.me/<?= okv_e(substr($phone, 1)) ?>?text=<?= okv_e(rawurlencode($whatsAppText)) ?>" target="_blank" rel="noopener">Open WhatsApp</a>
            <?php endif; ?>
            <?php if ($email === '' && $phone === null): ?><p class="text-sm text-ink-60">No usable reply details were provided.</p><?php endif; ?>
          </div>
        </div>

        <div class="border-t border-mist pt-5">
          <h3 class="text-sm font-semibold text-ink">Internal note</h3>
          <?php if ($canHandle): ?>
            <form action="/api/v1/contact.php" method="post" class="mt-3 space-y-3">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="save_note">
              <input type="hidden" name="message_id" value="<?= (int) $selected['id'] ?>">
              <input type="hidden" name="expected_note" value="<?= okv_e((string) ($selected['admin_note'] ?? '')) ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
              <label class="okv-label" for="admin-note">Only staff can see this note</label>
              <textarea class="okv-input min-h-24 py-3" id="admin-note" name="admin_note" maxlength="500"><?= okv_e((string) ($selected['admin_note'] ?? '')) ?></textarea>
              <button class="okv-btn-outline" type="submit">Save note</button>
            </form>
          <?php else: ?>
            <p class="mt-2 whitespace-pre-wrap text-sm text-ink-60"><?= trim((string) ($selected['admin_note'] ?? '')) !== '' ? okv_e($selected['admin_note']) : 'No internal note.' ?></p>
          <?php endif; ?>
        </div>

        <?php if ($canHandle): ?>
          <div class="border-t border-mist pt-5">
            <form action="/api/v1/contact.php" method="post">
              <?= Csrf::field() ?>
              <input type="hidden" name="action" value="<?= $selected['status'] === 'new' ? 'handle' : 'reopen' ?>">
              <input type="hidden" name="message_id" value="<?= (int) $selected['id'] ?>">
              <input type="hidden" name="expected_status" value="<?= okv_e($selected['status']) ?>">
              <input type="hidden" name="return_to" value="<?= okv_e($detailUrl) ?>">
              <button class="<?= $selected['status'] === 'new' ? 'okv-btn' : 'okv-btn-outline' ?>" type="submit"><?= $selected['status'] === 'new' ? 'Mark handled' : 'Reopen message' ?></button>
            </form>
          </div>
        <?php endif; ?>

        <div class="border-t border-mist pt-5">
          <h3 class="text-sm font-semibold text-ink">Handling history</h3>
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
                <li class="border-l-2 border-gold pl-3 text-sm">
                  <p class="font-medium"><?= okv_e($label) ?></p>
                  <p class="text-xs text-ink-60"><?= okv_e(trim((string) $event['actor_name']) ?: 'Storefront visitor') ?>, <?= okv_e(date('j M Y, H:i', strtotime((string) $event['created_at']))) ?></p>
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
