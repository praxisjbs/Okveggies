<?php
/**
 * Editable page copy: the slot registry, its validation, how a page reads its
 * copy, and the wiring that keeps every word out of the templates. Pure, no
 * database. The saved, published and uploaded paths are in
 * content_slots_http_test.php.
 */
$root = dirname(__DIR__, 2);
$read = static fn(string $path): string => (string) file_get_contents($root . '/' . $path);

// --- the registry is complete and sound -------------------------------------------------------------------
$managed = array_keys(ContentPages::registry());
foreach ($managed as $slug) {
    okv_test_ok(ContentSlots::has($slug), "$slug, a page the Content module manages, has editable slots");
}
okv_test_eq(['home', 'about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'], ContentSlots::pages(), 'the slot pages are the managed pages, in the module\'s order');

$kinds = ['line', 'text', 'long', 'path', 'rows', 'image'];
foreach (ContentSlots::pages() as $slug) {
    $seen = [];
    foreach (ContentSlots::groups($slug) as $group) {
        okv_test_ok(trim($group['group']) !== '', "$slug: every group has a name");
        foreach ($group['slots'] as $key => $slot) {
            okv_test_ok(preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1, "$slug.$key is a plain snake case key");
            okv_test_ok(!isset($seen[$key]), "$slug.$key appears once");
            $seen[$key] = true;
            okv_test_ok(in_array($slot['kind'], $kinds, true), "$slug.$key has a known kind");
            okv_test_ok(trim((string) $slot['label']) !== '', "$slug.$key has a label an editor can read");
            okv_test_ok((int) $slot['max'] > 0, "$slug.$key has a length limit");
            okv_test_ok(trim((string) $slot['default']) !== '', "$slug.$key has standard wording, so the page can never render empty");
            okv_test_ok(mb_strlen((string) $slot['default']) <= (int) $slot['max'], "$slug.$key: the standard wording fits its own limit");
            okv_test_ok(!str_contains((string) $slot['default'], "\u{2014}") && !str_contains((string) $slot['label'], "\u{2014}"), "$slug.$key has no em dash");
            if ($slot['kind'] !== 'image') {
                okv_test_eq('', ContentSlots::valueError($slot, (string) $slot['default']), "$slug.$key: the standard wording passes the rules it enforces on the Owner");
            } else {
                okv_test_ok(isset(ContentSlots::slots($slug)[(string) $slot['alt']]) && ContentSlots::slots($slug)[(string) $slot['alt']]['kind'] === 'line', "$slug.$key: a photograph names a line slot as its description");
            }
        }
    }
    okv_test_eq(array_keys($seen), ContentSlots::keys($slug), "$slug: keys() agrees with the groups");
}

// --- what a page shows: published, or the standard wording ---------------------------------------------------
$aboutCopy = ContentSlots::copy('about', []);
okv_test_eq('Our story', $aboutCopy['eyebrow'], 'a slot nobody filled in shows the standard wording');
okv_test_eq('Fresh produce begins with people, places and work we can stand behind.', $aboutCopy['lead'], 'the Our Story lead is the line the site has always shown');
$custom = ContentSlots::copy('about', ['eyebrow' => 'The OK Veggies story', 'lead' => '   ']);
okv_test_eq('The OK Veggies story', $custom['eyebrow'], 'a slot the Owner filled in shows their words');
okv_test_eq('Fresh produce begins with people, places and work we can stand behind.', $custom['lead'], 'and a blank or spaces only value shows the standard wording, never nothing');
okv_test_eq(count(ContentSlots::keys('about')), count($custom), 'every slot is present in what a page gets');
okv_test_eq('Bringing the Best of the Farm Straight to Your Kitchen.', ContentSlots::copy('home', [])['hero_heading'], 'the hero heading falls back to the approved wording');
okv_test_eq('Mine', ContentSlots::copy('home', ['hero_heading' => 'Mine'])['hero_heading'], 'and shows the Owner\'s own when there is one');
okv_test_eq('', ContentSlots::copy('terms', [])['eyebrow'] === '' ? '' : '', 'a page without stored data is fine');
okv_test_eq(ContentSlots::defaults('faq'), ContentSlots::formValues('faq', []), 'the editor opens on the standard wording when nothing is stored');
okv_test_eq('Mine', ContentSlots::formValues('faq', ['lead' => 'Mine'])['lead'], 'and on the Owner\'s wording when there is some');
okv_test_eq([], ContentSlots::copy('not-a-page', []), 'an unknown page has no slots');
okv_test_ok(!ContentSlots::has('not-a-page') && ContentSlots::keys('not-a-page') === [], 'and is not a slot page');

// --- tokens: policy figures follow the settings ----------------------------------------------------------------
okv_test_eq('Report it within ' . IssueReports::reportingWindowDays() . ' days from your order.', ContentSlots::copy('how-it-works', [])['mir_line'], 'a token in standard wording resolves to the live setting');
okv_test_eq('Up to ' . IssueReports::MAX_PHOTOS . ' photos', ContentSlots::resolve('Up to {{make_it_right_photos}} photos'), 'the photo limit token resolves');
okv_test_eq('Plain words {{not_a_token}}', ContentSlots::resolve('Plain words {{not_a_token}}'), 'an unknown token is left as harmless text when read');
okv_test_ok(str_contains(ContentSlots::html("Hello {{make_it_right_window}}"), IssueReports::reportingWindowDays() . ' days'), 'a long slot resolves tokens before it is rendered');
okv_test_ok(str_contains(ContentSlots::html("## A heading\n\n**Bold** words"), '<strong>Bold</strong>'), 'a long slot renders the restricted Markdown');
okv_test_ok(!str_contains(ContentSlots::html('<script>alert(1)</script>'), '<script>'), 'and never lets HTML through');
okv_test_eq('', ContentSlots::tokenError('Report within {{make_it_right_window}}.'), 'a documented token is accepted');
okv_test_ok(ContentSlots::tokenError('Report within {{made_up}}.') !== '', 'an unknown token is refused when saved');
okv_test_ok(ContentSlots::tokenError('Report within {{make_it_right_window}.') !== '', 'an incomplete token is refused');
okv_test_ok(ContentSlots::tokenError('Open {{ and close') !== '', 'a stray brace pair is refused');
foreach (ContentPages::FAQ_TOKENS as $token) {
    okv_test_ok(in_array($token, ContentSlots::TOKENS, true), "the FAQ token $token works in slots too");
}

// --- destinations -----------------------------------------------------------------------------------------------------
foreach (['/shop.php', '/', '/account.php?mode=register', '/our-story', '#make-it-right', 'https://wa.me/2348000000000', 'https://example.com/a?b=c', 'whatsapp', ''] as $good) {
    okv_test_eq('', ContentSlots::pathError($good), "'$good' is a destination a button may have");
}
foreach (['javascript:alert(1)', 'JAVASCRIPT:alert(1)', 'data:text/html,hi', '//evil.example', 'http://insecure.example', 'ftp://x.example', '/a/../b', '/has space', "/line\nbreak", 'shop.php', 'mailto:a@b.co', '#', str_repeat('/a', 200)] as $bad) {
    okv_test_ok(ContentSlots::pathError($bad) !== '', "'" . addcslashes(substr($bad, 0, 30), "\n") . "' is refused as a destination");
}
okv_test_eq('/shop.php', ContentSlots::href('/shop.php'), 'a good destination is used as it is');
okv_test_eq(okv_support_whatsapp_url(), ContentSlots::href('whatsapp'), 'the word whatsapp becomes the live chat address');
okv_test_eq('/', ContentSlots::href('javascript:alert(1)'), 'and an unsafe value already in the database can never reach an href');
okv_test_ok(array_key_exists('whatsapp', ContentSlots::DESTINATIONS) && array_key_exists('/shop.php', ContentSlots::DESTINATIONS), 'the suggestions the editor offers include the shop and the chat');

// --- delivery rows ------------------------------------------------------------------------------------------------------
okv_test_eq([['Mon', 'Household and business'], ['Tue', 'Business kitchens']], ContentSlots::rows("Mon | Household and business\nTue | Business kitchens"), 'rows split on the pipe');
okv_test_eq([['Mon', 'A | B']], ContentSlots::rows('Mon | A | B'), 'only the first pipe splits, so the second part may hold one');
okv_test_eq([['Wed', 'Household']], ContentSlots::rows("nothing here\nWed | Household\n | no day\nFri |"), 'half typed rows are dropped when reading, so they cannot break the table');
okv_test_eq(6, count(ContentSlots::rows(ContentSlots::defaults('delivery-policy')['delivery_rows'])), 'the standard table has the six delivery days');
okv_test_eq('', ContentSlots::rowsError("Mon | Household\nTue | Business"), 'good rows are accepted');
okv_test_ok(ContentSlots::rowsError("Mon | Household\nTue only") !== '', 'a row without two parts is refused when saved');
okv_test_ok(ContentSlots::rowsError("Mon | \nTue | Business") !== '', 'a row with an empty side is refused');
okv_test_ok(ContentSlots::rowsError(implode("\n", array_fill(0, 15, 'Mon | Household'))) !== '', 'too many rows are refused');

// --- validation of what the editor posts -----------------------------------------------------------------------------------
$result = ContentSlots::clean('about', ['eyebrow' => "  Our   story  ", 'lead' => "Line one\r\nline two", 'closing_heading' => '']);
okv_test_eq([], $result['errors'], 'a good form has no errors');
okv_test_eq('Our story', $result['clean']['eyebrow'], 'a line collapses its spaces');
okv_test_eq("Line one\nline two", $result['clean']['lead'], 'a text slot keeps its line breaks, normalised');
okv_test_eq('', $result['clean']['closing_heading'], 'a blank slot is stored blank, which means the standard wording');
okv_test_eq('', $result['clean']['action_1_path'], 'and so is a slot that was not sent at all');
okv_test_ok(!array_key_exists('founder_portrait', $result['clean']), 'a photograph is never part of the text form');
okv_test_ok(!array_key_exists('invented', ContentSlots::clean('about', ['invented' => 'x'])['clean']), 'a field the page does not have is discarded');

$bad = ContentSlots::clean('about', [
    'eyebrow' => str_repeat('x', 61), 'lead' => '<b>bold</b>', 'closing_heading' => "A dash \u{2014} here",
    'action_1_path' => 'javascript:alert(1)', 'founder_caption' => "Control\x07char",
]);
foreach (['eyebrow', 'lead', 'closing_heading', 'action_1_path', 'founder_caption'] as $field) {
    okv_test_ok(isset($bad['errors']['content_data.' . $field]), "$field: a bad value is refused with a message beside its box");
}
okv_test_ok(str_contains($bad['errors']['content_data.eyebrow'], '60'), 'a too long value says the limit');
$badLong = ContentSlots::clean('how-it-works', ['sheet_1_body' => "# A level one heading\n\nWords"]);
okv_test_ok(isset($badLong['errors']['content_data.sheet_1_body']), 'a long slot refuses what the page body refuses');
$badImage = ContentSlots::clean('how-it-works', ['sheet_1_body' => "![x](http://a.b/c.png)"]);
okv_test_ok(isset($badImage['errors']['content_data.sheet_1_body']), 'including an embedded image');
$notArray = ContentSlots::clean('about', ['eyebrow' => ['nested' => 'x']]);
okv_test_eq('', $notArray['clean']['eyebrow'], 'a value that is not text is treated as blank');
foreach (ContentSlots::pages() as $slug) {
    okv_test_eq([], ContentSlots::clean($slug, ContentSlots::defaults($slug))['errors'], "$slug: the standard wording posted back is a valid form");
}

// --- photographs ---------------------------------------------------------------------------------------------------------------
okv_test_eq(['founder_portrait'], ContentSlots::imageKeys('about'), 'Our Story has the founder portrait as a photograph slot');
okv_test_eq([], ContentSlots::imageKeys('faq'), 'a page without a photograph slot reports none');
okv_test_ok(ContentSlots::isUploadedPath('/uploads/content/0123456789abcdef0123456789abcdef-1280.webp') && ContentSlots::isUploadedPath('/uploads/content/a.jpg'), 'a path the upload workflow made is recognised');
foreach (['/assets/img/story/founder-kumbish-emmanuel-putleh.jpg', '/uploads/content/../x.jpg', '/uploads/other/a.jpg', 'https://evil.example/a.jpg', '/uploads/content/a.php'] as $notUploaded) {
    okv_test_ok(!ContentSlots::isUploadedPath($notUploaded), "$notUploaded is not an uploaded content image");
}
$standard = ContentSlots::image('about', 'founder_portrait', ContentSlots::copy('about', []));
okv_test_eq('/assets/img/story/founder-kumbish-emmanuel-putleh.jpg', $standard['path'], 'with nothing uploaded the portrait is the standard photograph');
okv_test_ok(!$standard['custom'] && $standard['alt'] === 'Kumbish Emmanuel Putleh, founder of OK Veggies', 'which is not "custom" and carries its standard description');
$uploaded = ContentSlots::image('about', 'founder_portrait', ContentSlots::copy('about', ['founder_portrait' => '/uploads/content/0123456789abcdef0123456789abcdef-1280.webp', 'founder_portrait_alt' => 'The founder at the market']));
okv_test_ok($uploaded['custom'] && $uploaded['alt'] === 'The founder at the market', 'an uploaded portrait is custom and carries the description the Owner wrote');
okv_test_eq([], ContentSlots::imageErrors('about', []), 'no photograph, no photograph errors');
okv_test_eq([], ContentSlots::imageErrors('about', ['founder_portrait' => '/uploads/content/0123456789abcdef0123456789abcdef-1280.webp', 'founder_portrait_alt' => 'The founder']), 'a photograph with a description can be published');
okv_test_ok(isset(ContentSlots::imageErrors('about', ['founder_portrait' => '/uploads/content/0123456789abcdef0123456789abcdef-1280.webp'])['content_data.founder_portrait_alt']), 'a photograph without a description cannot');
okv_test_ok(isset(ContentSlots::imageErrors('about', ['founder_portrait' => '/etc/passwd', 'founder_portrait_alt' => 'x'])['content_data.founder_portrait']), 'and a photograph that did not come through the upload workflow cannot');
$carried = ContentSlots::withImages('about', ['founder_portrait' => '/uploads/content/a.webp', 'founder_portrait_alt' => 'x', 'eyebrow' => 'Old'], ['eyebrow' => 'New']);
okv_test_eq('/uploads/content/a.webp', $carried['founder_portrait'], 'saving the text form keeps the photograph the draft holds');
okv_test_eq('New', $carried['eyebrow'], 'while the text comes from the form');
okv_test_ok(!array_key_exists('founder_portrait', ContentSlots::withImages('about', [], ['eyebrow' => 'New'])), 'and adds nothing when there is no photograph');

// --- the old homepage contract still holds ------------------------------------------------------------------------------------------
foreach (ContentPages::homeFields() as $key => $max) {
    okv_test_ok(isset(ContentSlots::slots('home')[$key]) && ContentSlots::slots('home')[$key]['kind'] !== 'image', "homeFields() lists $key as a text slot");
}

// --- wiring: no word is left in a template ----------------------------------------------------------------------------------------------
$index = $read('index.php');
$page = $read('page.php');
$steps = $read('includes/components/shop/how_it_works_steps.php');
$table = $read('includes/components/shop/delivery_policy_table.php');
$guidance = $read('includes/components/shop/make_it_right_guidance.php');
// Each template is checked against the wording of the page it renders. A few
// phrases legitimately appear in a template as well: the "paused" and "not found"
// states, which show when the database cannot be read and so cannot read slots,
// and the business tagline, which is a Settings value with its own default.
$checks = [
    'index.php'              => [$index, ['home'], ['promise_heading', 'start_kitchen_label', 'start_kitchen_path', 'products_empty_button', 'categories_empty_button', 'combos_empty_button', 'secondary_cta_label']],
    'page.php'               => [$page, ['about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'], ['action_1_label', 'action_2_label', 'action_1_path', 'action_2_path', 'help_contact_label', 'sheet_2_button_2_label']],
    'how_it_works_steps'     => [$steps, ['how-it-works'], []],
    'delivery_policy_table'  => [$table, ['delivery-policy'], []],
    'make_it_right_guidance' => [$guidance, ['how-it-works', 'delivery-policy'], []],
];
foreach ($checks as $name => [$source, $slugs, $allowedTwins]) {
    $checked = 0;
    foreach ($slugs as $slug) {
        foreach (ContentSlots::slots($slug) as $key => $slot) {
            if ($slot['kind'] === 'image' || in_array($key, $allowedTwins, true) || mb_strlen((string) $slot['default']) < 14
                || str_contains((string) $slot['default'], "\n") || str_contains((string) $slot['default'], '{{')) {
                continue;
            }
            // A sentence that is the standard wording of a slot must not also be typed into the template.
            okv_test_ok(!str_contains($source, '>' . $slot['default'] . '<') && !str_contains($source, "'" . $slot['default'] . "'") && !str_contains($source, '"' . $slot['default'] . '"'), "$name does not hardcode the standard wording of $slug.$key");
            $checked++;
        }
    }
    okv_test_ok($checked > 0, "$name was checked against the registry's wording ($checked phrases)");
}
okv_test_ok(str_contains($index, "ContentSlots::copy('home'") && str_contains($page, 'ContentSlots::copy($slug') , 'the homepage and the six pages read their copy from the slots');
okv_test_ok(str_contains($steps, 'ContentSlots::sheetHtml($copy["sheet_{$n}_body"])'), 'the three How It Works sheets render their long slots');
okv_test_ok(str_contains($table, 'ContentSlots::rows($copy[\'delivery_rows\'])'), 'the delivery table is built from its rows slot');
okv_test_ok(str_contains($guidance, '$copy[\'mir_sheet_body\']') && str_contains($guidance, '$copy[\'mir_heading\']'), 'the Make It Right panel reads its slots');
foreach (['eyebrow', 'closing_eyebrow', 'closing_heading'] as $key) {
    okv_test_ok(str_contains($page, '$copy[\'' . $key . '\']'), "page.php renders the $key slot");
}
okv_test_ok(!preg_match('/\$eyebrow\s*=\s*match/', $page), 'the eyebrow is no longer chosen by a match on the page slug');

// --- wiring: the editor, the API and the preview --------------------------------------------------------------------------------------
$admin = $read('admin/content.php');
$api = $read('api/v1/content.php');
$preview = $read('admin/content-preview.php');
okv_test_ok(str_contains($admin, 'ContentSlots::groups(') && str_contains($admin, 'name="content_data[') && !str_contains($admin, '$fieldLabels'), 'the editor is built from the registry, not from a list of homepage fields');
okv_test_ok(str_contains($admin, 'ContentSlots::formValues('), 'and opens on what the page says now');
okv_test_ok(str_contains($admin, 'upload_slot_image') && str_contains($admin, 'remove_slot_image'), 'a photograph slot has its own upload and remove forms');
okv_test_ok(str_contains($api, "Rbac::requirePermission('content.edit')") && str_contains($api, 'Csrf::validate()'), 'every content write needs content.edit and the CSRF token');
okv_test_ok(str_contains($api, "'upload_slot_image'") && str_contains($api, "ContentSlots::imageKeys(\$slug)"), 'a slot photograph can only be set on a slot the page has');
okv_test_ok(str_contains($api, 'ContentImages::removeSet($newPath)'), 'and a photograph that could not be saved is not left on disk');
okv_test_ok(str_contains($preview, 'ContentSlots::formValues('), 'the draft preview shows every slot, for any page');
okv_test_ok(!preg_match('/UPDATE\s+content_pages|INSERT\s+INTO\s+content_pages/i', $page . $index . $steps . $table . $guidance), 'the public pages never write content');

// --- migration 070 ------------------------------------------------------------------------------------------------------------------------
$heroMigration = $read('migrations/070_homepage_hero_wording_in_dashboard.sql');
okv_test_ok(substr_count($heroMigration, "\n   AND JSON_UNQUOTE(JSON_EXTRACT(`content_data`, '$.hero_heading')) = 'We are bringing the other half home.';") === 1
    && substr_count($heroMigration, "\n   AND JSON_UNQUOTE(JSON_EXTRACT(`draft_content_data`, '$.hero_heading')) = 'We are bringing the other half home.';") === 1
    && substr_count($heroMigration, "\n   AND JSON_UNQUOTE(JSON_EXTRACT(`content_data`, '$.hero_intro')) = 'Freshness You Can Trust. From Farm to Your Kitchen.';") === 1
    && substr_count($heroMigration, "\n   AND JSON_UNQUOTE(JSON_EXTRACT(`draft_content_data`, '$.hero_intro')) = 'Freshness You Can Trust. From Farm to Your Kitchen.';") === 1, 'the hero migration is conditional on the original seed text, for the published copy and the draft');
okv_test_ok(!preg_match('/\bDELETE\b|\bDROP\b|\bALTER\b/i', $heroMigration), 'and only updates rows, with no DDL');
okv_test_ok(str_contains($heroMigration, 'START TRANSACTION;') && str_contains($heroMigration, 'COMMIT;'), 'inside a transaction');
okv_test_ok(!str_contains($heroMigration, "\u{2014}"), 'with no em dash');
