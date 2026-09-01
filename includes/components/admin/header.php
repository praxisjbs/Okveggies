<?php
/**
 * includes/components/admin/header.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Opens the admin document: the head, the forest sidebar (from
 * sidebar.php, permission gated), a sticky top bar with the trail back, and the
 * page head. A page sets its variables before including this, and later
 * includes footer.php to close. The page must already have run its own
 * Rbac::requirePermission check; this file gates nothing.
 *
 *   $okv_admin_title    required. The screen name. Also the document title.
 *   $okv_admin_note     optional. One line saying what this screen is for.
 *   $okv_admin_crumbs   optional. [['label' => 'Products', 'href' => '/admin/products.php'], ...]
 *                       The current screen is appended automatically, unlinked.
 *   $okv_admin_actions  optional. Static markup for the buttons that belong to
 *                       the whole screen. Author it in the page, never from
 *                       request or database data: it is printed as given.
 *
 * The admin is desktop software (bible 1.9, PRD Section 2.2), so this shell is
 * denser and flatter than the shop: hairline rules instead of shadows, one
 * scannable page head, and the sidebar always in view on a laptop.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}
$okv_admin_title   = $okv_admin_title ?? 'OK Veggies';
$okv_admin_note    = $okv_admin_note ?? '';
$okv_admin_crumbs  = $okv_admin_crumbs ?? [];
$okv_admin_actions = $okv_admin_actions ?? '';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($okv_admin_title) ?> . OK Veggies</title>
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint text-ink">
  <a href="#okv-admin-main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-forest">Skip to the screen</a>
  <div class="md:flex min-h-screen">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <div class="flex-1 min-w-0 flex flex-col">
      <header class="sticky top-0 z-10 bg-white border-b border-mist">
        <div class="flex items-center gap-3 h-16 px-4 md:px-8">
          <button type="button" data-okv-nav-toggle aria-controls="okv-admin-sidebar" aria-expanded="false"
                  class="md:hidden inline-flex items-center justify-center w-11 h-11 -ml-2 rounded-md text-ink hover:bg-forest-tint">
            <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <span class="sr-only">Open the menu</span>
          </button>

          <nav class="min-w-0 hidden md:block" aria-label="Breadcrumb">
            <ol class="okv-crumbs">
              <li><a href="/admin/">Admin</a></li>
              <?php foreach ($okv_admin_crumbs as $crumb): ?>
                <li aria-hidden="true">/</li>
                <li><a href="<?= okv_e($crumb['href']) ?>"><?= okv_e($crumb['label']) ?></a></li>
              <?php endforeach; ?>
              <li aria-hidden="true">/</li>
              <li class="text-ink-60 font-medium truncate" aria-current="page"><?= okv_e($okv_admin_title) ?></li>
            </ol>
          </nav>

          <p class="md:hidden font-display font-extrabold text-lg text-ink truncate"><?= okv_e($okv_admin_title) ?></p>

          <a href="/" class="ml-auto okv-btn-text text-sm shrink-0">
            <span>View shop</span>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
          </a>
        </div>
      </header>
      <main id="okv-admin-main" class="okv-container w-full py-6 md:py-8 flex-1">
        <div class="okv-page-head mb-6">
          <div class="min-w-0">
            <h1 class="okv-page-title"><?= okv_e($okv_admin_title) ?></h1>
            <?php if ($okv_admin_note !== ''): ?>
              <p class="okv-page-note"><?= okv_e($okv_admin_note) ?></p>
            <?php endif; ?>
          </div>
          <?php if ($okv_admin_actions !== ''): ?>
            <div class="flex flex-wrap items-center gap-2"><?= $okv_admin_actions ?></div>
          <?php endif; ?>
        </div>
