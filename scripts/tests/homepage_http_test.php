<?php
/** M12 homepage data, metadata and empty states over real HTTP. */
require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

require_once __DIR__ . '/lib/scratch_guard.php';

$tests = 0; $passed = 0;
function hph_ok($condition, string $label): void { global $tests, $passed; $tests++; if ($condition) { $passed++; } else { fwrite(STDERR, "  FAIL: $label\n"); } }
function hph_eq($expected, $actual, string $label): void { hph_ok($expected === $actual, $label . ($expected === $actual ? '' : ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')')); }
function hph_get(string $path): array
{
    $base = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: https']]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $body];
}

$homeBefore = Database::one('SELECT * FROM content_pages WHERE slug = :slug', [':slug' => 'home']);
$productsBefore = Database::all('SELECT id, is_active, is_featured FROM products ORDER BY id');
$combosBefore = Database::all('SELECT id, is_featured FROM combo_packages ORDER BY id');
$categories = Database::all('SELECT id, name, slug FROM product_categories WHERE is_active = :active ORDER BY sort_order, name', [':active' => 1]);

try {
    $copy = [
        'hero_eyebrow' => 'From the Saturday market',
        'hero_heading' => 'Produce with a route back to its source.',
        'hero_intro' => 'A homepage introduction written for the HTTP acceptance test.',
        'primary_cta_label' => 'Open the shop', 'primary_cta_path' => '/shop.php',
        'secondary_cta_label' => 'Choose a combo', 'secondary_cta_path' => '/combos.php',
        'promise_heading' => 'The test promise', 'promise_body' => '**Checked** before it leaves us.',
        'combos_eyebrow' => 'Combo test eyebrow', 'combos_heading' => 'Combo test heading',
        'categories_eyebrow' => 'Category test eyebrow', 'categories_heading' => 'Category test heading',
        'products_eyebrow' => 'Product test eyebrow', 'products_heading' => 'Product test heading',
    ];
    Database::run(
        'UPDATE content_pages SET title = :title, meta_title = :meta_title, meta_description = :description, '
        . 'content_data = :content_data, image_url = NULL, image_alt = NULL, is_published = :published WHERE slug = :slug',
        [':title' => 'Homepage visible title', ':meta_title' => 'Homepage search title', ':description' => 'Homepage search description for sharing.',
         ':content_data' => json_encode($copy), ':published' => 1, ':slug' => 'home']
    );
    Database::run('UPDATE combo_packages SET is_featured = :featured', [':featured' => 0]);
    Database::run('UPDATE products SET is_featured = :featured', [':featured' => 0]);
    if ($categories) {
        Database::run('UPDATE products SET is_active = :active WHERE category_id = :category', [':active' => 0, ':category' => (int) $categories[0]['id']]);
    }

    [$status, $body] = hph_get('/');
    hph_eq(200, $status, 'homepage remains available with a published content snapshot');
    hph_ok(str_contains($body, 'Produce with a route back to its source.'), 'published hero copy is rendered');
    hph_ok(str_contains($body, '<strong>Checked</strong> before it leaves us.'), 'promise restricted Markdown is rendered safely');
    hph_ok(str_contains($body, '<title>Homepage search title. OK Veggies</title>'), 'published homepage SEO title is used');
    hph_ok(str_contains($body, 'Homepage search description for sharing.'), 'published homepage SEO description is used');
    hph_ok(str_contains($body, 'rel="canonical"') && str_contains($body, 'property="og:url"'), 'homepage emits canonical and Open Graph URL metadata');
    hph_ok(str_contains($body, 'No featured combo is on the stall today'), 'no featured combo produces an honest section state');
    hph_ok(str_contains($body, 'No produce has been marked as a weekly pick'), 'no featured product produces an honest section state');
    hph_ok(str_contains($body, 'Being sourced'), 'a zero-product active category stays visible with a sourcing label');
    foreach ($categories as $category) {
        hph_ok(str_contains($body, '/shop.php?category=' . $category['slug']), 'category link reaches the ' . $category['slug'] . ' shop filter');
    }
    hph_ok(str_contains($body, 'href="/shop.php"') && str_contains($body, 'href="/combos.php"') && str_contains($body, 'href="/kitchen-runs.php"'), 'homepage provides all 3 core routes');
    hph_ok(str_contains($body, 'Documentary photograph pending') && !str_contains($body, 'assets/img/product_images'), 'missing documentary photography never falls back to a product image');

    [, $notice] = hph_get('/?basket=added');
    hph_ok(str_contains($notice, 'Added to your basket.'), 'existing basket notices remain intact');

    $draftCopy = $copy;
    $draftCopy['hero_heading'] = 'SECRET UNPUBLISHED HOME COPY';
    Database::run(
        'UPDATE content_pages SET draft_content_data = :draft, is_published = :published WHERE slug = :slug',
        [':draft' => json_encode($draftCopy), ':published' => 0, ':slug' => 'home']
    );
    [$status, $fallback] = hph_get('/');
    hph_eq(200, $status, 'an unpublished home record falls back without taking down the storefront');
    hph_ok(str_contains($fallback, 'We are bringing the other half home.'), 'reviewed continuity copy is used while home is unpublished');
    hph_ok(!str_contains($fallback, 'SECRET UNPUBLISHED HOME COPY'), 'unpublished homepage draft data never reaches public HTML');
} finally {
    if ($homeBefore !== null) {
        $columns = ['title','body','draft_title','draft_body','draft_meta_title','draft_meta_description','meta_title','meta_description','draft_content_data','content_data','draft_image_url','draft_image_alt','image_url','image_alt','is_published','published_at','published_by','updated_by','created_at','updated_at'];
        $sets = []; $params = [':id' => $homeBefore['id']];
        foreach ($columns as $column) { $sets[] = $column . ' = :' . $column; $params[':' . $column] = $homeBefore[$column]; }
        Database::run('UPDATE content_pages SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
    }
    foreach ($productsBefore as $row) {
        Database::run('UPDATE products SET is_active = :active, is_featured = :featured WHERE id = :id', [':active' => $row['is_active'], ':featured' => $row['is_featured'], ':id' => $row['id']]);
    }
    foreach ($combosBefore as $row) {
        Database::run('UPDATE combo_packages SET is_featured = :featured WHERE id = :id', [':featured' => $row['is_featured'], ':id' => $row['id']]);
    }
}

fwrite(STDOUT, "\n$passed / $tests homepage HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
