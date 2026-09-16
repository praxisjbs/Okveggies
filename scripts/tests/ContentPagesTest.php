<?php
/** Pure M12 content service contract tests. */

$slugs = ['home', 'about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'];
okv_test_eq($slugs, ContentPages::supportedSlugs(), 'content pages expose the fixed seven-page allowlist in admin order');
okv_test_ok(ContentPages::isSupportedSlug('about'), 'the stored about slug is supported');
okv_test_ok(!ContentPages::isSupportedSlug('our-story'), 'the public path is never accepted as a stored slug');
okv_test_ok(!ContentPages::isSupportedSlug('unknown-page'), 'an arbitrary slug is refused');
okv_test_eq('/', ContentPages::canonicalPath('home'), 'homepage canonical path is the root');
okv_test_eq('/our-story', ContentPages::canonicalPath('about'), 'about maps to the agreed Our Story path');
okv_test_eq(null, ContentPages::canonicalPath('unknown-page'), 'unknown pages have no canonical path');

$valid = ContentPages::validateDraft('about', [
    'title' => "  Our Story  ",
    'body' => "From farm to Lagos.\r\n\r\n## The work\r\n\r\nWe visit every farm.",
    'meta_title' => 'Our farm story',
    'meta_description' => 'Meet the people and farms behind OK Veggies.',
]);
okv_test_ok($valid['ok'], 'safe restricted Markdown passes draft validation');
okv_test_eq('Our Story', $valid['clean']['title'], 'draft titles are outer-trimmed');
okv_test_ok(!str_contains($valid['clean']['body'], "\r"), 'draft body line endings are normalised');
okv_test_ok(str_contains($valid['clean']['body'], "\n\n"), 'meaningful paragraph line breaks are preserved');

$blankDraft = ContentPages::validateDraft('about', ['title' => '', 'body' => ''], false);
okv_test_ok($blankDraft['ok'], 'an incomplete working draft may be saved');
$blankPublish = ContentPages::validateDraft('about', ['title' => '', 'body' => ''], true);
okv_test_ok(!$blankPublish['ok'], 'an incomplete page cannot be published');
okv_test_ok(isset($blankPublish['errors']['title']) && isset($blankPublish['errors']['body']), 'publish validation identifies both missing required fields');

foreach ([
    ['body' => '<script>alert(1)</script>', 'label' => 'raw script HTML'],
    ['body' => '<p>Paragraph</p>', 'label' => 'ordinary raw HTML'],
    ['body' => '# Duplicate page heading', 'label' => 'a level-1 heading'],
    ['body' => '#### Unsupported depth', 'label' => 'a level-4 heading'],
    ['body' => '![farm](/uploads/content/farm.jpg)', 'label' => 'an embedded Markdown image'],
    ['body' => '[bad](javascript:alert(1))', 'label' => 'a script link'],
    ['body' => '[bad](//attacker.example/path)', 'label' => 'a protocol-relative link'],
    ['body' => "Words \u{2014} more words", 'label' => 'an em dash'],
] as $case) {
    $result = ContentPages::validateDraft('about', ['title' => 'Our Story', 'body' => $case['body']]);
    okv_test_ok(!$result['ok'], $case['label'] . ' is refused');
}

foreach (['[local](/shop.php)', '[secure](https://example.test/path)', '[plain](http://example.test)'] as $body) {
    $result = ContentPages::validateDraft('about', ['title' => 'Our Story', 'body' => $body]);
    okv_test_ok($result['ok'], "$body uses an allowed link destination");
}

$tooLong = ContentPages::validateDraft('about', [
    'title' => str_repeat('T', ContentPages::TITLE_MAX + 1),
    'body' => str_repeat('B', ContentPages::BODY_MAX + 1),
    'meta_title' => str_repeat('S', ContentPages::META_TITLE_MAX + 1),
    'meta_description' => str_repeat('D', ContentPages::META_DESCRIPTION_MAX + 1),
]);
foreach (['title', 'body', 'meta_title', 'meta_description'] as $field) {
    okv_test_ok(isset($tooLong['errors'][$field]), "$field has its agreed length limit");
}

$faq = ContentPages::validateFaq("## When do you deliver?\n\nOn the day you choose.\n\n## Where do you source?\n\nFrom farms we visit.");
okv_test_ok($faq['ok'], 'FAQ level-2 questions and nonempty answers are accepted');
okv_test_eq(2, count($faq['items']), 'FAQ parser returns one presentation-neutral item per question');
okv_test_eq('When do you deliver?', $faq['items'][0]['question'], 'FAQ question text is returned without Markdown markers');
$incompleteFaqDraft = ContentPages::validateDraft('faq', ['title' => 'FAQ', 'body' => 'A question still being drafted.'], false);
okv_test_ok($incompleteFaqDraft['ok'], 'incomplete FAQ structure can be retained as a working draft');
$incompleteFaqPublish = ContentPages::validateDraft('faq', ['title' => 'FAQ', 'body' => 'A question still being drafted.'], true);
okv_test_ok(!$incompleteFaqPublish['ok'], 'incomplete FAQ structure is blocked at publication');

foreach ([
    ['', 'empty FAQ'],
    ["Intro first.\n\n## Question?\nAnswer.", 'FAQ preamble'],
    ["## Question?\n\n## Another?\nAnswer.", 'empty FAQ answer'],
    ['## ' . str_repeat('Q', ContentPages::FAQ_QUESTION_MAX + 1) . "\nAnswer.", 'overlong FAQ question'],
] as [$body, $label]) {
    okv_test_ok(!ContentPages::validateFaq($body)['ok'], "$label is refused");
}
$manyFaq = [];
for ($i = 1; $i <= ContentPages::FAQ_MAX + 1; $i++) {
    $manyFaq[] = "## Question $i?\nAnswer $i.";
}
okv_test_ok(!ContentPages::validateFaq(implode("\n\n", $manyFaq))['ok'], 'FAQ question count is capped');

$homeFields = [
    'hero_eyebrow' => 'Est. 2026. Lagos',
    'hero_heading' => 'We are bringing the other half home.',
    'hero_intro' => 'Fresh produce from farms we have checked ourselves.',
    'primary_cta_label' => 'Start shopping',
    'primary_cta_path' => '/shop.php',
    'secondary_cta_label' => 'See the combos',
    'secondary_cta_path' => '/combos.php',
    'promise_heading' => 'Sourced right. Priced right. Delivered right.',
    'promise_body' => 'Farms we have visited. Prices we can explain.',
    'combos_eyebrow' => 'Cooked together, priced together',
    'combos_heading' => "This week's combos",
    'categories_eyebrow' => 'Five aisles, one stall',
    'categories_heading' => 'Shop by category',
    'products_eyebrow' => 'Picked this week',
    'products_heading' => "This week's picks",
    'invented_field' => 'must disappear',
];
$home = ContentPages::validateDraft('home', [
    'title' => 'Fresh from farms we can name',
    'body' => 'Fresh produce, weighed right.',
    'content_data' => $homeFields,
], true);
okv_test_ok($home['ok'], 'the complete fixed homepage field set can be published');
okv_test_ok(!array_key_exists('invented_field', $home['clean']['content_data']), 'unknown homepage keys are discarded');
okv_test_eq(15, count($home['clean']['content_data']), 'only the fifteen approved homepage fields survive');

$badCta = $homeFields;
$badCta['primary_cta_path'] = 'https://attacker.example/';
$badHome = ContentPages::validateDraft('home', [
    'title' => 'Fresh from farms we can name', 'body' => 'Copy', 'content_data' => $badCta,
], true);
okv_test_ok(isset($badHome['errors']['content_data.primary_cta_path']), 'homepage calls to action use the destination allowlist');

$snapshot = [
    'draft_title' => 'Our Story', 'draft_body' => "One\r\nTwo",
    'draft_meta_title' => null, 'draft_meta_description' => '',
    'draft_content_data' => null, 'draft_image_url' => null, 'draft_image_alt' => null,
    'updated_at' => '2026-09-16 10:00:00', 'updated_by' => 1,
];
$sameSnapshot = $snapshot;
$sameSnapshot['updated_at'] = '2026-09-16 11:00:00';
$sameSnapshot['updated_by'] = 99;
okv_test_eq(ContentPages::draftFingerprint($snapshot), ContentPages::draftFingerprint($sameSnapshot), 'draft fingerprint ignores timestamps and actors');
$sameSnapshot['draft_body'] = 'Changed';
okv_test_ok(ContentPages::draftFingerprint($snapshot) !== ContentPages::draftFingerprint($sameSnapshot), 'draft fingerprint changes when editable copy changes');

$service = file_get_contents(dirname(__DIR__, 2) . '/includes/classes/ContentPages.php');
okv_test_ok(!preg_match('/<\/?(?:p|h[1-6]|ul|ol|li|a|script)\b/i', $service), 'the content service does not render HTML');
okv_test_ok(str_contains($service, 'FOR UPDATE'), 'content writes lock the current page before comparing its fingerprint');
okv_test_ok(str_contains($service, 'Audit::record'), 'content mutations use the shared append-only audit writer');
okv_test_ok(str_contains($service, "user_type = :type") && str_contains($service, "status = :status"), 'mutation actors are checked as active staff with bound values');

$bootstrap = file_get_contents(dirname(__DIR__, 2) . '/includes/bootstrap.php');
okv_test_ok(str_contains($bootstrap, "'/classes/ContentPages.php'"), 'the shared bootstrap registers ContentPages');
$runner = file_get_contents(__DIR__ . '/run.php');
okv_test_ok(str_contains($runner, "'/includes/classes/ContentPages.php'"), 'the dependency-free unit runner registers ContentPages');

$migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/049_m12_content_pages.sql');
foreach (['draft_title', 'draft_body', 'draft_meta_title', 'draft_meta_description', 'meta_title', 'meta_description',
          'draft_content_data', 'content_data', 'draft_image_url', 'draft_image_alt', 'image_url', 'image_alt',
          'published_at', 'published_by'] as $column) {
    okv_test_ok(str_contains($migration, "COLUMN_NAME = '$column'"), "migration 049 guards the $column addition");
}
okv_test_eq(14, substr_count($migration, 'ADD COLUMN `'), 'migration 049 adds exactly the fourteen approved columns');
okv_test_ok(str_contains($migration, "('home',"), 'migration 049 seeds the fixed homepage row');
foreach (['This is placeholder copy to be replaced with the reviewed terms before launch.',
          'This is placeholder copy to be replaced with the reviewed policy before launch.',
          'This is placeholder copy to be finalised before launch.'] as $placeholder) {
    okv_test_ok(str_contains($migration, $placeholder), 'migration 049 limits legal unpublishing to an original placeholder body');
}
okv_test_ok(!str_contains($migration, "WHERE slug IN ('terms', 'privacy', 'delivery-policy')"), 'a forced migration rerun cannot unpublish later approved legal copy by slug alone');
okv_test_ok(str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'migration 049 is repeatable at its fixed homepage seed');
okv_test_ok(str_contains($migration, '-- Verification:'), 'migration 049 ends with verification queries');
