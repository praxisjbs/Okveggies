<?php
/** Static and pure M12 homepage acceptance guards. */

$root = dirname(__DIR__, 2);
$home = (string) file_get_contents($root . '/index.php');
$catalogue = (string) file_get_contents($root . '/includes/classes/Catalogue.php');
$admin = (string) file_get_contents($root . '/admin/content.php');
$controller = (string) file_get_contents($root . '/api/v1/content.php');
$images = (string) file_get_contents($root . '/includes/classes/ContentImages.php');

okv_test_ok(str_contains($home, "ContentPages::findPublished('home')"), 'editable homepage copy comes from the published ContentPages snapshot');
okv_test_ok(str_contains($home, '$fallback') && !str_contains($home, 'findPreview'), 'homepage has reviewed continuity copy and never reads a draft');
okv_test_ok(str_contains($home, 'ContentRenderer::render($copy[\'promise_body\'])'), 'the editable promise uses the restricted Markdown renderer');
okv_test_ok(str_contains($home, 'The OK Veggies promise'), 'the promise has its own semantic section');
foreach (['/shop.php', '/combos.php', '/kitchen-runs.php'] as $route) {
    okv_test_ok(str_contains($home, 'href="' . $route . '"'), "homepage has a clear route to $route");
}
okv_test_ok(str_contains($home, 'ContentPages::findPublished') && str_contains($home, 'Catalogue::featuredCombos') && str_contains($home, 'Catalogue::featuredProducts'), 'content and catalogue responsibilities remain separate');
okv_test_ok(!preg_match('/(?:SELECT|INSERT|UPDATE|DELETE)\s+/i', $home), 'homepage contains no catalogue or content SQL');
okv_test_ok(str_contains($catalogue, "(bool) (\$combo['is_featured'] ?? false)"), 'featured combo selection respects the explicit database flag');
okv_test_ok(str_contains($home, 'No featured combo is on the stall today'), 'combos have an honest empty state');
okv_test_ok(str_contains($home, 'No produce has been marked as a weekly pick'), 'featured products have an honest empty state');
okv_test_ok(str_contains($home, 'The category list is being prepared') && str_contains($home, 'Being sourced'), 'categories have section and zero-item states');
foreach (['home.categories failed', 'home.featured_combos failed', 'home.featured_products failed'] as $failure) {
    okv_test_ok(str_contains($home, $failure), "$failure is isolated and logged");
}
okv_test_ok(str_contains($home, 'rel="canonical"') && str_contains($home, 'property="og:url"'), 'homepage emits canonical and Open Graph URLs');
okv_test_ok(str_contains($home, "'og_title' => \$documentTitle") && str_contains($home, "'og_description' => \$description"), 'published SEO values reach shared metadata');
okv_test_ok(str_contains($home, 'Documentary photograph pending') && str_contains($home, 'We will not replace it with stock photography'), 'missing approved photography is stated honestly');
okv_test_ok(str_contains($home, 'fetchpriority="high"') && !str_contains($home, 'fetchpriority="high" loading="lazy"'), 'the approved hero is high priority and never lazy-loaded');
okv_test_ok(str_contains($home, 'imagesrcset=') && str_contains($home, 'sizes="<?= okv_e($heroSizes) ?>"'), 'the hero preload and image share responsive sizing');
okv_test_ok(str_contains($home, 'fresh-produce-640.webp')
    && str_contains($home, 'fresh-produce-960.webp')
    && str_contains($home, 'fresh-produce-1280.webp'),
    'the committed hero exposes all three WebP candidates');
okv_test_ok(str_contains($home, 'Crates of fresh tomatoes, red and yellow peppers, and onions.')
    && str_contains($home, '$defaultHeroReady'),
    'the committed hero has descriptive fallback text and a missing-asset guard');
okv_test_ok(str_contains($home, '$publishedHero = ContentImages::presentation($publishedHeroPath)')
    && str_contains($home, '$heroImage = $publishedHero'),
    'a valid published CMS hero still takes precedence over the committed fallback');
okv_test_ok(str_contains($admin, 'image/jpeg,image/png,image/webp') && str_contains($admin, 'required'), 'admin image input restricts formats and requires supporting fields');
okv_test_ok(str_contains($controller, 'Rbac::requirePermission(\'content.edit\')') && str_contains($controller, 'Csrf::validate()'), 'documentary image writes inherit server RBAC and CSRF gates');
okv_test_ok(str_contains($images, "private const WIDTHS = [640, 960, 1280]") && str_contains($images, 'imagewebp'), 'content photography is resized into responsive WebP variants');
okv_test_ok(str_contains($images, "bin2hex(random_bytes(16))") && str_contains($images, "'/uploads/content/'"), 'content images use random names under the non-executable upload tree');
okv_test_ok(str_contains($images, 'array_merge(self::WIDTHS, [(int) $match[2]])'), 'cleanup includes a short source image whose largest variant uses its natural width');

$unknown = ContentImages::presentation('/assets/img/product_images/Fresh Tomatoes.jpeg');
okv_test_eq('', $unknown['srcset'], 'a product image is never treated as a documentary responsive set');
okv_test_eq('/assets/img/product_images/Fresh Tomatoes.jpeg', $unknown['src'], 'an unrelated safe path is returned unchanged for neutral presentation');

// The approved presentation copy is fixed without writing to the CMS snapshot.
okv_test_ok(str_contains($home, "\$heroHeading = 'Bringing the Best of the Farm Straight to Your Kitchen.';")
    && str_contains($home, 'okv_e($heroHeading)'), 'hero renders the approved heading, even when older CMS copy exists');
okv_test_ok(str_contains($home, "\$heroIntro = 'Freshness You Can Trust. Sourced daily from local farms, carefully selected, and delivered perfectly to you.';")
    && str_contains($home, 'okv_e($heroIntro)'), 'hero renders the approved supporting text exactly');
foreach (['hero_eyebrow', 'primary_cta_path', 'primary_cta_label', 'secondary_cta_path', 'secondary_cta_label'] as $field) {
    okv_test_ok(str_contains($home, 'okv_e($copy[\'' . $field . '\'])'), $field . ' still honours the published CMS value');
}
okv_test_ok(str_contains($home, "\$heroSizes = '100vw';"), 'hero responsive sizing describes the full viewport, not a column');
$hero = substr($home, strpos($home, '<section class="okv-home-hero'), strpos($home, '</section>') - strpos($home, '<section class="okv-home-hero'));
okv_test_ok(str_contains($hero, 'data-okv-hero>') && str_contains($hero, 'okv-home-hero-media')
    && str_contains($hero, 'okv-home-hero-wash'), 'one hero hook contains the full-image and full-overlay layers');
okv_test_ok(!str_contains($hero, 'backdrop-blur') && !str_contains($hero, 'okv-card')
    && !str_contains($hero, 'bg-white/') && !str_contains($hero, 'animate-okv-rise'), 'hero has no glass panel or JavaScript-independent entrance');
