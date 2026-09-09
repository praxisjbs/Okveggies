<?php
/**
 * includes/components/pro/header.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Opens a Pro Portal document: the head, the brand bar carrying the
 * horizontal lockup, the portal navigation and the page head. A page sets
 * $okv_pro_title (and optionally $okv_pro_note and $okv_pro_active) before
 * including this, then includes footer.php to close.
 *
 * Same seal, same promise as the storefront; what changes is the density.
 * Access is enforced by require_business_customer() before this file is used.
 * -----------------------------------------------------------------------------
 */
if (!defined('OKV_BOOTSTRAPPED')) {
    http_response_code(500);
    exit;
}
require_once __DIR__ . '/nav.php';

$okv_pro_title  = $okv_pro_title ?? 'Pro Portal';
$okv_pro_note   = $okv_pro_note ?? '';
$okv_pro_active = $okv_pro_active ?? '';
$okv_pro_name   = Customer::isLoggedIn() && Customer::firstName() !== '' ? Customer::firstName() : '';
$okv_pro_home   = $okv_pro_active === '/pro/';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($okv_pro_title) ?> . OK Veggies Pro</title>
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(['og_title' => 'OK Veggies Pro. Supply for your kitchen.']); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="min-h-screen flex flex-col bg-forest-tint pb-16 text-ink md:pb-0">
  <a href="#okv-pro-main" class="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:m-2 focus:rounded-md focus:bg-white focus:px-4 focus:py-2 focus:text-forest">Skip to the screen</a>

  <header class="bg-white border-b border-mist">
    <div class="okv-container flex h-16 items-center justify-between gap-4">
      <a href="/pro/" class="inline-flex items-center gap-3 rounded-md" aria-label="OK Veggies Pro, home">
        <img src="<?= okv_e(okv_asset('/assets/img/brand/lockup.svg')) ?>" alt="OK Veggies, Fresh Picks" width="183" height="48" class="hidden h-12 w-auto sm:block">
        <img src="<?= okv_e(okv_asset('/assets/img/brand/seal-320.png')) ?>" alt="OK Veggies" width="44" height="44" class="h-11 w-11 sm:hidden">
        <span class="okv-eyebrow text-forest">Pro</span>
      </a>
      <div class="flex items-center gap-2">
        <a href="/shop.php" class="okv-btn-text text-sm">Shop</a>
        <a href="/account.php" class="okv-btn-outline-sm">
          <?= $okv_pro_name !== '' ? okv_e($okv_pro_name) : 'Sign in' ?>
        </a>
      </div>
    </div>
  </header>
  <?php okv_pro_nav($okv_pro_active); ?>

  <main id="okv-pro-main" class="okv-container w-full flex-1 py-6 md:py-8">
    <div class="okv-page-head mb-6">
      <div class="min-w-0">
        <nav class="okv-crumbs mb-1" aria-label="Breadcrumb">
          <a href="/" class="inline-flex min-h-[44px] items-center">Home</a>
          <span aria-hidden="true">/</span>
          <?php if ($okv_pro_home): ?>
            <span class="text-ink-60 font-medium" aria-current="page">Pro Portal</span>
          <?php else: ?>
            <a href="/pro/" class="inline-flex min-h-[44px] items-center">Pro Portal</a>
            <span aria-hidden="true">/</span>
            <span class="text-ink-60 font-medium" aria-current="page"><?= okv_e($okv_pro_title) ?></span>
          <?php endif; ?>
        </nav>
        <h1 class="okv-page-title"><?= okv_e($okv_pro_title) ?></h1>
        <?php if ($okv_pro_note !== ''): ?>
          <p class="okv-page-note"><?= okv_e($okv_pro_note) ?></p>
        <?php endif; ?>
      </div>
      <a href="<?= $okv_pro_home ? '/shop.php' : '/pro/' ?>" class="okv-btn-text inline-flex min-h-[44px] items-center">
        <span aria-hidden="true">&larr;</span> <?= $okv_pro_home ? 'Back to the shop' : 'Back to Dashboard' ?>
      </a>
    </div>
