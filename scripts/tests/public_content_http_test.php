<?php
/** M12 public content behavior against the configured migrated scratch DB. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0;
function pch_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function pch_eq($expected, $actual, string $label): void { pch_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function pch_location(array $headers): string
{
    foreach ($headers as $line) {
        if (str_starts_with(strtolower($line), 'location:')) {
            return trim(substr($line, strlen('location:')));
        }
    }
    return '';
}
function pch_is_local_route(string $location, string $path): bool
{
    if ($location === $path) { return true; }
    $base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
    return parse_url($location, PHP_URL_PATH) === $path
        && parse_url($location, PHP_URL_HOST) === parse_url($base, PHP_URL_HOST);
}
function pch_get(string $path, bool $follow = false): array
{
    $base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
    $headers = [];
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: https'],
        CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) { $headers[] = trim($line); return strlen($line); }]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string) $body, $headers];
}

$slugs = ['about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'];
$columns = ['id', 'title', 'body', 'meta_title', 'meta_description', 'image_url', 'image_alt', 'is_published'];
$before = [];
foreach ($slugs as $slug) {
    $before[$slug] = Database::one('SELECT ' . implode(', ', $columns) . ' FROM content_pages WHERE slug = :slug', [':slug' => $slug]);
}
$depositBefore = Database::one('SELECT setting_value, value_type FROM site_settings WHERE setting_key = :key', [':key' => 'deposit_percentage_default']);

try {
    Database::run(
        'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :description, is_published = :published WHERE slug = :slug',
        [':title' => 'Our real story', ':body' => "## Where we started\n\nSafe <script>alert(1)</script> copy.\n\n## What comes next\n\n[Visit the shop](/shop.php).",
         ':meta_title' => 'Fresh produce, honestly sourced', ':description' => 'Meet the people and approach behind OK Veggies.', ':published' => 1, ':slug' => 'about']
    );
    Database::run('UPDATE content_pages SET is_published = :published WHERE slug = :slug', [':published' => 0, ':slug' => 'terms']);
    Database::run(
        'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :description, is_published = :published WHERE slug = :slug',
        [':title' => 'Questions and Answers', ':body' => "## How do deposits work?\n\nPay {{deposit_percentage}} first.\n\n## When do you deliver?\n\n{{household_delivery_schedule}}",
         ':meta_title' => 'Shopping questions answered', ':description' => 'Answers about ordering and delivery.', ':published' => 1, ':slug' => 'faq']
    );
    Database::run('UPDATE site_settings SET setting_value = :value WHERE setting_key = :key', [':value' => '37', ':key' => 'deposit_percentage_default']);

    [$status, $story] = pch_get('/our-story');
    pch_eq(200, $status, 'a clean published content route returns 200');
    pch_ok(str_contains($story, '<h1') && str_contains($story, 'Our real story'), 'the published visible title is rendered');
    pch_ok(str_contains($story, '<title>Fresh produce, honestly sourced. OK Veggies</title>'), 'the approved SEO title reaches the document title');
    pch_ok(str_contains($story, 'rel="canonical"') && str_contains($story, '/our-story'), 'the clean canonical URL is emitted');
    pch_ok(str_contains($story, 'property="og:url"') && str_contains($story, '/our-story'), 'the Open Graph URL matches the canonical URL');
    pch_ok(str_contains($story, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($story, '<script>alert(1)</script>'), 'legacy unsafe text is escaped at presentation time');
    pch_ok(str_contains($story, 'href="/our-story"'), 'the published current page appears in managed footer navigation');
    pch_ok(!str_contains($story, 'href="/terms"'), 'an unpublished legal page is absent from footer navigation');
    pch_ok(str_contains($story, 'href="/faq"'), 'a published FAQ appears in footer navigation');

    $metadataRoutes = [
        'how-it-works' => '/how-it-works',
        'terms' => '/terms',
        'privacy' => '/privacy',
        'delivery-policy' => '/delivery-policy',
    ];
    foreach ($metadataRoutes as $metadataSlug => $metadataPath) {
        $fragment = ucwords(str_replace('-', ' ', $metadataSlug)) . ' search title';
        Database::run(
            'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :description, is_published = :published WHERE slug = :slug',
            [':title' => ucwords(str_replace('-', ' ', $metadataSlug)), ':body' => '## Details' . "\n\n" . 'Published route copy.',
             ':meta_title' => $fragment, ':description' => 'Route & safe "copy".', ':published' => 1, ':slug' => $metadataSlug]
        );
        [$metadataStatus, $metadataBody] = pch_get($metadataPath);
        pch_eq(200, $metadataStatus, "$metadataPath returns its published page");
        pch_ok(str_contains($metadataBody, '<title>' . $fragment . '. OK Veggies</title>'), "$metadataPath emits its page-specific title");
        pch_ok(str_contains($metadataBody, 'Route &amp; safe &quot;copy&quot;.'), "$metadataPath escapes its description in metadata");
        pch_ok(str_contains($metadataBody, 'rel="canonical" href="' . rtrim((string) APP_URL, '/') . $metadataPath . '"'), "$metadataPath emits its exact canonical URL");
        pch_ok(str_contains($metadataBody, 'property="og:url" content="' . rtrim((string) APP_URL, '/') . $metadataPath . '"'), "$metadataPath Open Graph URL matches its canonical URL");
    }

    [$status, $faq] = pch_get('/faq');
    pch_eq(200, $status, 'the clean published FAQ route returns 200');
    pch_ok(str_contains($faq, '<title>Shopping questions answered. OK Veggies</title>'), 'FAQ emits its approved metadata title');
    pch_ok(str_contains($faq, 'rel="canonical"') && str_contains($faq, '/faq'), 'FAQ emits its clean canonical URL');
    pch_eq(2, substr_count($faq, 'data-faq-item'), 'FAQ renders each stored question once and in place');
    pch_ok(str_contains($faq, 'How do deposits work?') && str_contains($faq, '37%'), 'FAQ resolves the current deposit percentage at request time');
    pch_ok(str_contains($faq, 'Monday') && str_contains($faq, 'cutoff on the previous day'), 'FAQ derives delivery-day wording from active delivery rules');
    pch_ok(str_contains($faq, '<details') && str_contains($faq, '<summary'), 'FAQ answers remain native and available without JavaScript');
    pch_ok(str_contains($faq, 'data-faq-expand') && str_contains($faq, 'data-faq-collapse'), 'FAQ exposes progressive bulk controls');

    Database::run('UPDATE content_pages SET body = :body, is_published = :published WHERE slug = :slug', [':body' => '', ':published' => 1, ':slug' => 'faq']);
    [$status, $emptyFaq] = pch_get('/faq');
    pch_eq(200, $status, 'a published FAQ with no valid questions keeps a useful page');
    pch_ok(str_contains($emptyFaq, 'We are preparing these answers') && str_contains($emptyFaq, 'Chat on WhatsApp'), 'FAQ empty state offers direct help');
    Database::run('UPDATE content_pages SET is_published = :published WHERE slug = :slug', [':published' => 0, ':slug' => 'faq']);
    [$status, $hiddenFaq] = pch_get('/faq');
    pch_eq(404, $status, 'an unpublished FAQ remains indistinguishable from a missing page');

    Database::run('UPDATE content_pages SET is_published = :published WHERE slug = :slug', [':published' => 0, ':slug' => 'terms']);
    [$status, $missing] = pch_get('/terms');
    pch_eq(404, $status, 'an unpublished page returns 404');
    pch_ok(str_contains($missing, 'Page not found') && !str_contains($missing, (string) ($before['terms']['title'] ?? 'Terms')), 'the unpublished response does not disclose stored copy');
    pch_ok(str_contains($missing, 'noindex, nofollow'), 'an unpublished response is noindex');
    [, , $missingHeaders] = pch_get('/terms');
    pch_ok((bool) array_filter($missingHeaders, static fn(string $line): bool => strcasecmp($line, 'X-Robots-Tag: noindex, nofollow') === 0), 'an unpublished response sends the HTTP noindex directive');

    [$status, $unknown] = pch_get('/page.php?slug=unknown-page');
    pch_eq(404, $status, 'an unknown slug returns 404');
    pch_ok(str_contains($unknown, 'Browse the shop') && str_contains($unknown, 'Contact us'), 'a missing page leaves useful routes onward');

    [$status, , $headers] = pch_get('/page.php?slug=about');
    pch_eq(301, $status, 'a known legacy URL redirects permanently');
    pch_ok(pch_is_local_route(pch_location($headers), '/our-story'), 'the legacy redirect points to the clean canonical route on this origin');

    [$status, , $headers] = pch_get('/page.php?slug=about&next=https%3A%2F%2Fattacker.example');
    pch_eq(301, $status, 'a recognised legacy URL with extra parameters still redirects permanently');
    pch_ok(pch_is_local_route(pch_location($headers), '/our-story') && !str_contains(pch_location($headers), 'attacker.example'), 'legacy parameters are discarded and cannot form an open redirect');

    [$status, , $headers] = pch_get('/our-story/');
    pch_eq(301, $status, 'the trailing-slash content variant redirects permanently');
    pch_ok(pch_is_local_route(pch_location($headers), '/our-story'), 'the trailing-slash redirect selects the one canonical form on this origin');
} finally {
    foreach ($before as $row) {
        if ($row === null) { continue; }
        Database::run(
            'UPDATE content_pages SET title = :title, body = :body, meta_title = :meta_title, meta_description = :meta_description, '
            . 'image_url = :image_url, image_alt = :image_alt, is_published = :is_published WHERE id = :id',
            [':title' => $row['title'], ':body' => $row['body'], ':meta_title' => $row['meta_title'], ':meta_description' => $row['meta_description'],
             ':image_url' => $row['image_url'], ':image_alt' => $row['image_alt'], ':is_published' => $row['is_published'], ':id' => $row['id']]
        );
    }
    if ($depositBefore !== null) {
        Database::run(
            'UPDATE site_settings SET setting_value = :value, value_type = :type WHERE setting_key = :key',
            [':value' => $depositBefore['setting_value'], ':type' => $depositBefore['value_type'], ':key' => 'deposit_percentage_default']
        );
    }
}

fwrite(STDOUT, "\n$passed / $tests public content HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
