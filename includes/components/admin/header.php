<?php
/**
 * includes/components/admin/header.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Opens the admin document: the head, the forest sidebar (from
 * sidebar.php, permission gated) and a sticky top bar. A page sets
 * $okv_admin_title before including this, and later includes footer.php to
 * close. The page must already have run its own Rbac::requirePermission check.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}
$okv_admin_title = $okv_admin_title ?? 'OK Veggies';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($okv_admin_title) ?> . OK Veggies</title>
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen bg-forest-tint text-ink">
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
          <h1 class="font-display font-extrabold text-xl text-ink truncate"><?= okv_e($okv_admin_title) ?></h1>
          <a href="/" class="ml-auto okv-btn-text text-sm">View shop</a>
        </div>
      </header>
      <main class="okv-container w-full py-8 flex-1">
