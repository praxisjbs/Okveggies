<?php
/**
 * includes/components/head_meta.php
 * -----------------------------------------------------------------------------
 * OK Veggies. One brand head block for every page: favicon and app icons, the
 * web manifest, the forest theme colour, the self-hosted font preloads, and
 * sensible social-card defaults. Call it inside <head>, after the page's own
 * <title> and <meta name="description">:
 *
 *     <?php okv_head_meta(); ?>
 *     <?php okv_head_meta(['og_image' => $ogImage, 'og_title' => $name]); ?>
 *
 * Loaded by bootstrap.php, so it is always available. Every value is escaped and
 * the icons are cache-busted through okv_asset(). This is the single place brand
 * chrome enters the document, so a favicon or icon change happens here once.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_head_meta')) {
    function okv_head_meta(array $o = []): void
    {
        $base = defined('APP_URL') ? rtrim((string) APP_URL, '/') : '';
        $ogImage = (string) ($o['og_image'] ?? '');
        if ($ogImage === '') {
            $ogImage = $base . '/assets/img/brand/og-image.png';
        }
        $ogTitle = (string) ($o['og_title'] ?? 'OK Veggies. Fresh from farms we can name.');
        $ogDesc  = (string) ($o['og_description']
            ?? 'Fresh produce from verified farms in Ogun State and Jos, delivered on the day you pick. Sourced right. Priced right. Delivered right.');
        $ogType  = (string) ($o['og_type'] ?? 'website');
        ?>
  <meta name="theme-color" content="#0F5132">
  <meta name="color-scheme" content="light">
  <meta name="application-name" content="OK Veggies">
  <meta name="apple-mobile-web-app-title" content="OK Veggies">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="msapplication-TileColor" content="#0F5132">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/svg+xml" href="<?= okv_e(okv_asset('/assets/img/brand/icons/favicon.svg')) ?>">
  <link rel="icon" type="image/png" sizes="32x32" href="<?= okv_e(okv_asset('/assets/img/brand/icons/favicon-32.png')) ?>">
  <link rel="icon" type="image/png" sizes="16x16" href="<?= okv_e(okv_asset('/assets/img/brand/icons/favicon-16.png')) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="<?= okv_e(okv_asset('/assets/img/brand/icons/apple-touch-icon.png')) ?>">
  <link rel="manifest" href="<?= okv_e(okv_asset('/site.webmanifest')) ?>">
  <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= okv_e(okv_asset('/assets/fonts/hanken-grotesk-latin.woff2')) ?>">
  <link rel="preload" as="font" type="font/woff2" crossorigin href="<?= okv_e(okv_asset('/assets/fonts/jetbrains-mono-latin.woff2')) ?>">
  <meta property="og:site_name" content="OK Veggies">
  <meta property="og:type" content="<?= okv_e($ogType) ?>">
  <meta property="og:title" content="<?= okv_e($ogTitle) ?>">
  <meta property="og:description" content="<?= okv_e($ogDesc) ?>">
  <meta property="og:image" content="<?= okv_e($ogImage) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= okv_e($ogTitle) ?>">
  <meta name="twitter:description" content="<?= okv_e($ogDesc) ?>">
  <meta name="twitter:image" content="<?= okv_e($ogImage) ?>">
<?php
    }
}
