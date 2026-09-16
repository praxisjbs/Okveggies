<?php
$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/page.php');
$footer = (string) file_get_contents($root . '/includes/components/shop/footer.php');
$nav = (string) file_get_contents($root . '/includes/config/nav.php');
$rewrites = (string) file_get_contents($root . '/.htaccess');
$checkout = (string) file_get_contents($root . '/checkout.php');
$helpers = (string) file_get_contents($root . '/includes/functions/helpers.php');

okv_test_ok(str_contains($page, 'ContentPages::findPublished($slug)'), 'public content reads only the published service projection');
okv_test_ok(!str_contains($page, 'Database::'), 'the public controller contains no direct SQL or database calls');
okv_test_ok(str_contains($page, 'ContentRenderer::render'), 'the public controller uses the restricted shared renderer');
okv_test_ok(str_contains($page, 'okv_head_meta('), 'every public response emits shared head metadata');
okv_test_ok(str_contains($page, '<link rel="canonical"') && str_contains($page, 'property="og:url"'), 'published pages emit canonical and Open Graph URLs');
okv_test_ok(str_contains($page, 'http_response_code($status)') && str_contains($page, "'Page not found'"), 'unknown and unpublished pages share a real 404 experience');
okv_test_ok(str_contains($page, "header('Retry-After: 300')") && str_contains($page, "'Page temporarily unavailable'"), 'database failures return an honest retryable experience');
okv_test_ok(str_contains($page, 'name="robots" content="noindex, nofollow"'), 'error responses are excluded from search indexing');
okv_test_ok(str_contains($page, 'okv_shop_header();') && str_contains($page, 'okv_shop_footer'), 'content and errors use the storefront shell');
okv_test_ok(str_contains($page, "okv_shop_footer([])"), 'database-error and missing-page responses suppress managed footer links');
okv_test_ok(str_contains($footer, 'ContentPages::publishedNavigation'), 'the shared footer makes managed links publication-aware');
okv_test_ok(str_contains($footer, "error_log('content.footer_navigation failed:"), 'footer database errors are logged and fail closed');
okv_test_ok(str_contains($nav, "'/faq'") && preg_match("/'slug' => 'faq'.*'public_ready' => true/", $nav), 'FAQ is eligible for publication-aware footer navigation');
foreach (['our-story', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'] as $route) {
    okv_test_ok(str_contains($rewrites, 'RewriteRule ^' . $route . '/?$'), "$route has a clean Apache route");
}
okv_test_ok(str_contains($rewrites, '%{THE_REQUEST}'), 'legacy redirects distinguish browser requests from internal rewrites');
okv_test_ok(str_contains($checkout, 'okv_make_it_right_policy_url()'), 'checkout resolves its Make It Right link through publication state');
okv_test_ok(str_contains($helpers, "ContentPages::findPublished('delivery-policy')"), 'the policy-link helper never inspects drafts');
okv_test_ok(str_contains($helpers, "return '/how-it-works#make-it-right';"), 'How It Works is the safe transition when Delivery Policy is not public');
