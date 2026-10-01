<?php
/**
 * scripts/tests/content_slots_http_test.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PR3 of the 23 Sep review, items 2 and 15: every word on every
 * public page is editable from the dashboard, and the dashboard is the source of
 * truth.
 *
 * Over the real admin screen, the real content API and the real public pages, for
 * the homepage and all six information pages: a colleague changes slots and saves
 * a draft (the public page still reads the old copy), publishes (the public page
 * reads the new copy, in the right places), blanks a slot (the standard wording
 * returns, never an empty page), and uploads and removes the founder's portrait.
 * Bad destinations, HTML, unknown tokens and malformed rows are refused with a
 * message beside the box; a stale draft, a missing CSRF token and a colleague who
 * may only look are refused; and an unsafe value already in the database can
 * never become a link.
 *
 * It needs the clean content routes, which the built-in server only has through
 * the router that stands in for .htaccess:
 *   php -S 127.0.0.1:8123 -t . scripts/tests/public_content_router.php
 *   php scripts/tests/content_slots_http_test.php
 *
 * It changes the seven content rows and puts every one of them back.
 * -----------------------------------------------------------------------------
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once __DIR__ . '/lib/scratch_guard.php';
if (!function_exists('curl_init')) { fwrite(STDERR, "This test needs the PHP curl extension.\n"); exit(2); }
if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) { fwrite(STDERR, "This test needs PHP GD with WebP.\n"); exit(2); }

$base   = rtrim(getenv('OKV_TEST_BASE') ?: 'http://127.0.0.1:8123', '/');
$tests  = 0;
$passed = 0;

function cs_ok($condition, string $label): void
{
    global $tests, $passed;
    $tests++;
    if ($condition) { $passed++; return; }
    fwrite(STDOUT, "  FAIL: $label\n");
}
function cs_eq($expected, $actual, string $label): void
{
    $same = $expected === $actual;
    if (!$same) { $label .= ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')'; }
    cs_ok($same, $label);
}
function cs_req(string $jar, string $url, ?array $post = null, bool $json = true): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $json ? ['X-Requested-With: fetch', 'Accept: application/json'] : [],
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        $multipart = false;
        foreach ($post as $value) { if ($value instanceof CURLFile) { $multipart = true; break; } }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
    }
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body, json_decode($body, true)];
}
function cs_csrf(string $jar, string $base): string
{
    [, $body] = cs_req($jar, $base . '/account.php', null, false);
    return preg_match('/name="csrf-token" content="([^"]+)"/', $body, $m) ? $m[1] : '';
}

/** Whether a file exists now. The web server creates and deletes these files, so PHP's stat cache must not answer. */
function cs_file(string $absolute): bool
{
    clearstatcache(true, $absolute);
    return is_file($absolute);
}

$suffix   = substr(bin2hex(random_bytes(4)), 0, 8);
$s        = $suffix;
$password = 'slots-http-7741';
$users = []; $roles = [];
$jarE = tempnam(sys_get_temp_dir(), 'okv-cs-e-');   // may edit page copy
$jarV = tempnam(sys_get_temp_dir(), 'okv-cs-v-');   // may only look
$jarO = tempnam(sys_get_temp_dir(), 'okv-cs-o-');   // no content permission
$jarG = tempnam(sys_get_temp_dir(), 'okv-cs-g-');   // the public

$slugs = ['home', 'about', 'how-it-works', 'faq', 'terms', 'privacy', 'delivery-policy'];
$columns = ['title', 'draft_title', 'body', 'draft_body', 'meta_title', 'draft_meta_title', 'meta_description', 'draft_meta_description',
            'content_data', 'draft_content_data', 'image_url', 'draft_image_url', 'image_alt', 'draft_image_alt', 'is_published', 'published_at', 'published_by', 'updated_by', 'updated_at'];
$before = [];
foreach ($slugs as $slug) {
    $before[$slug] = Database::one('SELECT * FROM content_pages WHERE slug = :slug', [':slug' => $slug]);
}
$uploadsBefore = glob(dirname(__DIR__, 2) . '/uploads/content/*') ?: [];

$makeStaff = static function (string $first, array $permissions) use ($suffix, $password, &$users, &$roles): array {
    $email = strtolower($first) . "-cs-$suffix@example.test";
    Database::run(
        'INSERT INTO users (first_name, last_name, email, phone, password_hash, user_type, status, email_verified_at)
         VALUES (:f, \'Slots\', :e, :p, :h, \'staff\', \'active\', NOW())',
        [':f' => $first, ':e' => $email, ':p' => '+23475' . random_int(10000000, 99999999), ':h' => password_hash($password, PASSWORD_BCRYPT)]
    );
    $id = (int) Database::getInstance()->getConnection()->lastInsertId();
    $users[] = $id;
    Database::run('INSERT INTO roles (name, description) VALUES (:n, \'Content slots HTTP fixture\')', [':n' => 'slots_http_' . $suffix . '_' . count($roles)]);
    $role = (int) Database::getInstance()->getConnection()->lastInsertId();
    $roles[] = $role;
    foreach ($permissions as $permission) {
        Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT :r, id FROM permissions WHERE `key` = :p', [':r' => $role, ':p' => $permission]);
    }
    Database::run('INSERT INTO user_roles (user_id, role_id) VALUES (:u, :r)', [':u' => $id, ':r' => $role]);
    return [$id, $email];
};
$signIn = static function (string $jar, string $email) use ($base, $password): string {
    $csrf = cs_csrf($jar, $base);
    cs_req($jar, $base . '/api/v1/auth.php', ['action' => 'login', 'context' => 'admin', 'identifier' => $email, 'password' => $password, 'okv_csrf' => $csrf]);
    return cs_csrf($jar, $base);
};

try {
    Database::run('DELETE FROM rate_limits');
    [$editorId, $editorEmail] = $makeStaff('Editor', ['dashboard.view', 'content.view', 'content.edit']);
    [$viewerId, $viewerEmail] = $makeStaff('Looker', ['dashboard.view', 'content.view']);
    [$outsiderId, $outsiderEmail] = $makeStaff('Outsider', ['dashboard.view']);
    $csrfE = $signIn($jarE, $editorEmail);
    $csrfV = $signIn($jarV, $viewerEmail);
    $csrfO = $signIn($jarO, $outsiderEmail);

    $pub = static function (string $path) use ($base, $jarG): string { [, $html] = cs_req($jarG, $base . $path, null, false); return $html; };
    $fingerprint = static fn(string $slug): string => (string) ContentPages::findForAdmin($slug)['fingerprint'];
    $api = static function (string $action, string $slug, array $more = [], ?string $csrf = null, ?string $jar = null) use ($base, $jarE, $csrfE, $fingerprint): array {
        return cs_req($jar ?? $jarE, $base . '/api/v1/content.php', [
            'action' => $action, 'slug' => $slug, 'fingerprint' => $more['fingerprint'] ?? $fingerprint($slug), 'okv_csrf' => $csrf ?? $csrfE,
        ] + $more);
    };
    $save = static function (string $slug, string $title, string $body, array $data) use ($api): array {
        return $api('save_draft', $slug, ['title' => $title, 'body' => $body, 'content_data' => $data]);
    };
    $publish = static function (string $slug) use ($api): array {
        return $api('publish', $slug, ['confirm' => '1', 'legal_approved' => '1']);
    };
    $paths = ['home' => '/', 'about' => '/our-story', 'how-it-works' => '/how-it-works', 'faq' => '/faq', 'terms' => '/terms', 'privacy' => '/privacy', 'delivery-policy' => '/delivery-policy'];

    // ---- 1. The editor opens on what the page says now ---------------------------------------------------------------------
    [$code, $editor] = cs_req($jarE, $base . '/admin/content.php?tab=page-copy&page=about', null, false);
    cs_eq(200, $code, 'the editor opens Our Story');
    cs_ok(str_contains($editor, 'name="content_data[eyebrow]"') && str_contains($editor, 'name="content_data[founder_caption]"') && str_contains($editor, 'name="content_data[action_3_path]"'), 'it has a box for the eyebrow, the founder caption and the third closing button');
    cs_ok(!str_contains($editor, 'name="content_data[founder_portrait]"'), 'but the photograph is not a text box');
    cs_ok(str_contains($editor, 'Photographs on this page') && str_contains($editor, 'name="action" value="upload_slot_image"'), 'it has its own place to upload the founder portrait');
    cs_ok(str_contains($editor, 'datalist id="okv-destinations"') && str_contains($editor, 'list="okv-destinations"'), 'and suggests destinations beside every button box');
    foreach (['Top of the page', 'The founder', 'Closing buttons'] as $groupName) {
        cs_ok(str_contains($editor, $groupName), "the editor groups the boxes: $groupName");
    }
    foreach ($slugs as $slug) {
        [, $screen] = cs_req($jarE, $base . '/admin/content.php?tab=page-copy&page=' . $slug, null, false);
        $missing = [];
        foreach (ContentSlots::keys($slug) as $key) {
            if (ContentSlots::slots($slug)[$key]['kind'] === 'image') { continue; }
            if (!str_contains($screen, 'name="content_data[' . $key . ']"')) { $missing[] = $key; }
        }
        cs_eq([], $missing, "$slug: every text slot has a box in the editor");
    }
    [, $homeEditor] = cs_req($jarE, $base . '/admin/content.php?tab=page-copy&page=home', null, false);
    cs_ok(str_contains($homeEditor, 'Bringing the Best of the Farm Straight to Your Kitchen.') || str_contains($homeEditor, 'Your wording'), 'the homepage hero heading is a box in the editor, holding the wording the page shows');

    // ---- 2. A viewer can look and nothing else; outsiders and the public cannot ----------------------------------------------
    [$code, $viewerScreen] = cs_req($jarV, $base . '/admin/content.php?tab=page-copy&page=about', null, false);
    cs_eq(200, $code, 'a colleague who may only view opens the editor');
    cs_ok(preg_match('/name="content_data\[eyebrow\]"[^>]*disabled/', $viewerScreen) === 1 && !str_contains($viewerScreen, 'Save draft'), 'with every box disabled and no Save button');
    [$code] = $api('save_draft', 'about', ['title' => 'x', 'body' => 'y', 'content_data' => ['eyebrow' => 'Nope']], $csrfV, $jarV);
    cs_eq(403, $code, 'and a save from them is refused');
    [$code] = cs_req($jarO, $base . '/admin/content.php?tab=page-copy&page=about', null, false);
    cs_ok(in_array($code, [302, 403], true), 'a colleague with no content permission cannot open the editor');
    [$code] = $api('save_draft', 'about', ['title' => 'x', 'body' => 'y', 'content_data' => ['eyebrow' => 'Nope']], $csrfO, $jarO);
    cs_eq(403, $code, 'or save');
    [$code] = cs_req($jarE, $base . '/api/v1/content.php', ['action' => 'save_draft', 'slug' => 'about', 'fingerprint' => $fingerprint('about'), 'title' => 'x', 'body' => 'y']);
    cs_eq(419, $code, 'a save without the CSRF token is refused');
    [$code] = $api('save_draft', 'about', ['title' => 'x', 'body' => 'y', 'content_data' => ['eyebrow' => 'Nope']], 'x', $jarG);
    cs_ok(in_array($code, [401, 403], true), 'and the public cannot save');
    cs_ok(!str_contains(json_encode(ContentPages::findForAdmin('about')['content_data']), 'Nope'), 'none of those refusals wrote anything');

    // ---- 3. Every page: a draft is private until it is published --------------------------------------------------------------
    $bodies = [
        'home'            => "Fresh produce, weighed right, brought on the day you pick.",
        'about'           => "## Where we started\n\nThe market, the farm and the van.\n\n## The person behind it\n\nFounder words $s.\n\n## What comes next\n\nMore of it.",
        'how-it-works'    => "Pick, choose a day, and we bring it over. Body $s.",
        'faq'             => "## What is this question $s?\n\nThis is the answer.\n\n## Another question $s?\n\nAnother answer.",
        'terms'           => "## First part\n\nWords.\n\n## Second part\n\nMore words.",
        'privacy'         => "## First part\n\nWords.\n\n## Second part\n\nMore words.",
        'delivery-policy' => "We deliver on the days in the table. Policy body $s.",
    ];
    $custom = [
        'home' => [
            'hero_eyebrow' => "Eyebrow $s", 'hero_heading' => "Heading $s", 'hero_intro' => "Intro $s words",
            'primary_cta_label' => "Buy $s", 'primary_cta_path' => '/combos.php', 'secondary_cta_label' => "Look $s", 'secondary_cta_path' => '/contact.php',
            'promise_eyebrow' => "Promise eyebrow $s", 'promise_1_title' => "Card one $s", 'promise_2_line' => "Card two line $s", 'promise_learn_label' => "Learn $s",
            'promise_body' => "**Bold $s** promise body",
            'start_shop_label' => "Go shop $s", 'start_combos_line' => "Combo line $s", 'start_kitchen_path' => '/contact.php', 'start_kitchen_label' => "Kitchen $s",
            'combos_link_label' => "More combos $s", 'products_link_label' => "More produce $s", 'combos_eyebrow' => "Combo eyebrow $s",
            'categories_heading' => "Aisles $s", 'products_heading' => "Picks $s",
        ],
        'about' => [
            'eyebrow' => "Story $s", 'lead' => "Lead line $s", 'founder_caption' => "Caption $s",
            'closing_eyebrow' => "Onward $s", 'closing_heading' => "Next $s", 'action_1_label' => "First $s", 'action_1_path' => '/contact.php',
            'action_2_label' => "Second $s", 'action_2_path' => 'whatsapp', 'action_3_label' => "Third $s", 'action_3_path' => '/faq',
        ],
        'how-it-works' => [
            'eyebrow' => "Journey $s", 'step_2_title' => "Day step $s", 'step_3_line' => "Door line $s", 'tap_label' => "Tap $s", 'learn_label' => "Learn more $s",
            'body_sheet_title' => "Sheet title $s", 'sheet_1_body' => "Sheet one $s. Report within {{make_it_right_window}}.",
            'sheet_3_button_1_label' => "Report $s", 'sheet_3_button_1_path' => '#make-it-right',
            'mir_eyebrow' => "Right $s", 'mir_heading' => "Not right $s", 'mir_line' => "Within {{make_it_right_window}} please $s", 'mir_sheet_body' => "Mir body $s with {{make_it_right_photos}} photos.",
            'mir_signin_label' => "Sign in $s", 'closing_heading' => "How next $s",
        ],
        'faq' => [
            'eyebrow' => "Asked $s", 'lead' => "Open one $s", 'help_heading' => "Help $s", 'help_line' => "Help line $s",
            'help_contact_label' => "Write $s", 'help_chat_label' => "Chat $s", 'help_chat_path' => 'https://example.com/chat-' . $s, 'closing_heading' => "Faq next $s",
        ],
        'terms' => ['eyebrow' => "Terms eyebrow $s", 'toc_label' => "Contents $s", 'closing_heading' => "Terms next $s"],
        'privacy' => ['eyebrow' => "Privacy eyebrow $s", 'toc_label' => "Inside $s", 'closing_heading' => "Privacy next $s"],
        'delivery-policy' => [
            'eyebrow' => "Delivery eyebrow $s", 'read_label' => "Read all $s", 'sheet_title' => "Policy sheet $s", 'table_day_heading' => "When $s", 'table_who_heading' => "For $s",
            'delivery_rows' => "Mon | Rowone $s\nSat | Rowtwo $s", 'delivery_note' => "Note under table $s", 'mir_heading' => "Delivery not right $s", 'closing_heading' => "Delivery next $s",
        ],
    ];
    $titles = ['home' => 'Fresh from farms we can name', 'about' => 'Our Story', 'how-it-works' => 'How It Works', 'faq' => 'Questions', 'terms' => 'Terms', 'privacy' => 'Privacy', 'delivery-policy' => 'Delivery Policy'];

    // Publish a plain version of every page first, so each has a known public state to compare against.
    foreach ($slugs as $slug) {
        [$code, , $result] = $save($slug, $titles[$slug], $bodies[$slug], []);
        cs_ok($code === 200, "$slug: a plain draft is saved");
        [$code, , $result] = $publish($slug);
        cs_ok($code === 200 && in_array((string) ($result['code'] ?? ''), ['published', 'unchanged'], true), "$slug: and published (" . ($result['code'] ?? $code) . ')');
    }
    foreach ($slugs as $slug) {
        $html = $pub($paths[$slug]);
        cs_ok(str_contains($html, '<main'), "$slug: the public page is up");
        foreach ($custom[$slug] as $value) {
            if (str_contains((string) $value, $s)) { cs_ok(!str_contains($html, $value), "$slug: '" . substr((string) $value, 0, 24) . "' is not on the page before anyone saves it"); break; }
        }
    }

    // Save the custom copy as a draft only.
    foreach ($slugs as $slug) {
        [$code, , $result] = $save($slug, $titles[$slug], $bodies[$slug], $custom[$slug]);
        cs_ok($code === 200 && ($result['code'] ?? '') === 'updated', "$slug: the custom copy is saved as a draft");
    }
    foreach ($slugs as $slug) {
        $draft = ContentPages::findForAdmin($slug)['content_data'];
        $first = array_key_first($custom[$slug]);
        cs_eq($custom[$slug][$first], $draft[$first] ?? null, "$slug: the draft holds the new wording");
        $html = $pub($paths[$slug]);
        $leak = false;
        foreach ($custom[$slug] as $value) { if (str_contains((string) $value, $s) && str_contains($html, htmlspecialchars((string) $value, ENT_QUOTES))) { $leak = true; } }
        cs_ok(!$leak, "$slug: a saved draft does not reach the public page");
    }
    [, $previewScreen] = cs_req($jarE, $base . '/admin/content-preview.php?page=about', null, false);
    cs_ok(str_contains($previewScreen, "Lead line $s") && str_contains($previewScreen, "Caption $s"), 'the draft preview shows the new wording before it is public');

    // Publish, and the public pages read it.
    foreach ($slugs as $slug) {
        [$code, , $result] = $publish($slug);
        cs_ok($code === 200 && ($result['code'] ?? '') === 'published', "$slug: the draft is published");
    }
    $home = $pub('/');
    foreach (['hero_eyebrow', 'hero_heading', 'hero_intro', 'primary_cta_label', 'secondary_cta_label', 'promise_eyebrow', 'promise_1_title', 'promise_2_line', 'promise_learn_label', 'start_shop_label', 'start_combos_line', 'start_kitchen_label', 'combos_link_label', 'products_link_label', 'combos_eyebrow', 'categories_heading', 'products_heading'] as $key) {
        cs_ok(str_contains($home, htmlspecialchars($custom['home'][$key], ENT_QUOTES)), "home: $key reaches the page");
    }
    cs_ok(str_contains($home, '<strong>Bold ' . $s . '</strong> promise body'), 'home: the promise renders its Markdown');
    cs_ok(!str_contains($home, 'Bringing the Best of the Farm Straight to Your Kitchen.'), 'home: the hero no longer shows the old hardcoded wording once the dashboard says something else');
    cs_ok(str_contains($home, 'href="/combos.php"') && preg_match('/data-okv-hero-cta>.*?href="\/combos\.php".*?Buy ' . $s . '/s', $home) === 1, 'home: the hero button points where the Owner pointed it');
    cs_ok(preg_match('/aria-label="Start an order">.*href="\/contact\.php"[^>]*>.*Kitchen ' . $s . '/s', $home) === 1, 'home: the Kitchen Run button points where the Owner pointed it');

    $about = $pub('/our-story');
    foreach (['eyebrow', 'lead', 'founder_caption', 'closing_eyebrow', 'closing_heading', 'action_1_label', 'action_2_label', 'action_3_label'] as $key) {
        cs_ok(str_contains($about, $custom['about'][$key]), "about: $key reaches the page");
    }
    cs_ok(preg_match('/href="\/contact\.php"[^>]*>\s*First ' . $s . '/', $about) === 1, 'about: the first closing button goes where the Owner pointed it');
    cs_ok(preg_match('/href="https:\/\/wa\.me\/[^"]*"[^>]*>\s*Second ' . $s . '/', $about) === 1, 'about: the word whatsapp becomes the live chat link');
    cs_ok(preg_match('/href="\/faq"[^>]*>\s*Third ' . $s . '/', $about) === 1, 'about: the third button goes where the Owner pointed it');
    cs_ok(str_contains($about, 'founder-kumbish-emmanuel-putleh.jpg') && strpos($about, 'founder-kumbish') > strpos($about, 'The person behind it'), 'about: the standard founder portrait sits under its heading');

    $how = $pub('/how-it-works');
    foreach (['eyebrow', 'step_2_title', 'step_3_line', 'tap_label', 'learn_label', 'body_sheet_title', 'sheet_3_button_1_label', 'mir_eyebrow', 'mir_heading', 'mir_signin_label', 'closing_heading'] as $key) {
        cs_ok(str_contains($how, $custom['how-it-works'][$key]), "how-it-works: $key reaches the page");
    }
    cs_ok(str_contains($how, 'Sheet one ' . $s . '. Report within ' . IssueReports::reportingWindowDays() . ' days.'), 'how-it-works: a token in a sheet resolves to the live reporting window');
    cs_ok(str_contains($how, 'Within ' . IssueReports::reportingWindowDays() . ' days please ' . $s), 'how-it-works: and in the Make It Right line');
    cs_ok(str_contains($how, 'Mir body ' . $s . ' with ' . IssueReports::MAX_PHOTOS . ' photos.'), 'how-it-works: and the photo limit in the Make It Right sheet');
    cs_ok(preg_match('/href="#make-it-right"[^>]*>.{0,600}?Report ' . $s . '/s', $how) === 1, 'how-it-works: a sheet button can point at a section of the page');

    $faq = $pub('/faq');
    foreach (['eyebrow', 'lead', 'help_heading', 'help_line', 'help_contact_label', 'help_chat_label', 'closing_heading'] as $key) {
        cs_ok(str_contains($faq, $custom['faq'][$key]), "faq: $key reaches the page");
    }
    cs_ok(preg_match('/href="' . preg_quote($custom['faq']['help_chat_path'], '/') . '"[^>]*target="_blank"[^>]*>/', $faq) === 1, 'faq: an https chat address opens in a new tab');
    cs_ok(str_contains($faq, "What is this question $s?"), 'faq: the questions are still the page body');

    foreach (['terms', 'privacy'] as $legal) {
        $html = $pub($paths[$legal]);
        foreach (['eyebrow', 'toc_label', 'closing_heading'] as $key) {
            cs_ok(str_contains($html, $custom[$legal][$key]), "$legal: $key reaches the page");
        }
    }

    $policy = $pub('/delivery-policy');
    foreach (['eyebrow', 'read_label', 'sheet_title', 'table_day_heading', 'table_who_heading', 'delivery_note', 'mir_heading', 'closing_heading'] as $key) {
        cs_ok(str_contains($policy, $custom['delivery-policy'][$key]), "delivery-policy: $key reaches the page");
    }
    cs_ok(str_contains($policy, "Rowone $s") && str_contains($policy, "Rowtwo $s") && !str_contains($policy, 'Business kitchens'), 'delivery-policy: the table shows the Owner\'s rows and not the standard ones');
    cs_eq(2, preg_match_all('/<tr>\s*<td>/', $policy), 'delivery-policy: with exactly two body rows');

    // ---- 4. Blank goes back to the standard wording, never to an empty page ------------------------------------------------------------
    $blank = $custom['about'];
    $blank['eyebrow'] = '';
    $blank['lead'] = '   ';
    [$code] = $save('about', $titles['about'], $bodies['about'], $blank);
    cs_eq(200, $code, 'a colleague clears two boxes and saves');
    $publish('about');
    $aboutBlank = $pub('/our-story');
    cs_ok(str_contains($aboutBlank, '>Our story<') && str_contains($aboutBlank, 'Fresh produce begins with people, places and work we can stand behind.'), 'the standard eyebrow and lead are back');
    cs_ok(!str_contains($aboutBlank, "Story $s") && !str_contains($aboutBlank, "Lead line $s"), 'and the cleared wording is gone');
    cs_ok(str_contains($aboutBlank, "Caption $s"), 'while the boxes they did not clear keep their wording');

    // ---- 5. Bad values are refused beside the box, and change nothing ----------------------------------------------------------------------
    $draftBefore = json_encode(ContentPages::findForAdmin('about')['content_data']);
    $refusals = [
        'action_1_path'   => ['javascript:alert(1)', 'a script address'],
        'closing_heading' => ["Dash \u{2014} here", 'an em dash'],
        'lead'            => ['<b>bold</b>', 'HTML'],
        'closing_eyebrow' => [str_repeat('x', 61), 'too many characters'],
        'founder_caption' => ['Uses {{made_up_token}}', 'an unknown token'],
    ];
    foreach ($refusals as $key => [$value, $why]) {
        $data = $custom['about'];
        $data[$key] = $value;
        [$code, , $result] = $save('about', $titles['about'], $bodies['about'], $data);
        cs_eq(422, $code, "$why in $key is refused");
        cs_ok(isset($result['errors']['content_data.' . $key]), "$why: the message is for the $key box");
    }
    [$code, , $result] = $save('delivery-policy', $titles['delivery-policy'], $bodies['delivery-policy'], ['delivery_rows' => "Mon | Household\nTue only"]);
    cs_ok($code === 422 && isset($result['errors']['content_data.delivery_rows']), 'a delivery row without two parts is refused beside the table box');
    [$code, , $result] = $save('how-it-works', $titles['how-it-works'], $bodies['how-it-works'], ['sheet_1_body' => "# Level one\n\nWords"]);
    cs_ok($code === 422 && isset($result['errors']['content_data.sheet_1_body']), 'a level one heading in a sheet is refused');
    cs_eq($draftBefore, json_encode(ContentPages::findForAdmin('about')['content_data']), 'and none of the refused saves changed the draft');
    [$code, , $result] = $api('save_draft', 'about', ['title' => $titles['about'], 'body' => $bodies['about'], 'content_data' => $custom['about'], 'fingerprint' => str_repeat('0', 64)]);
    cs_ok($code === 409 && ($result['code'] ?? '') === 'stale_draft', 'a save made from a draft someone else has since changed is refused');
    [$code] = $api('save_draft', 'not-a-page', ['title' => 'x', 'body' => 'y', 'fingerprint' => 'x']);
    cs_eq(404, $code, 'and a page the module does not manage is not found');

    // ---- 6. An unsafe value already in the database can never become a link ---------------------------------------------------------------------
    $stored = ContentPages::findForAdmin('about')['published']['content_data'];
    $stored['action_1_path'] = 'javascript:alert(1)';
    Database::run('UPDATE content_pages SET content_data = :d WHERE slug = :s', [':d' => json_encode($stored), ':s' => 'about']);
    $unsafe = $pub('/our-story');
    cs_ok(!str_contains($unsafe, 'javascript:') && str_contains($unsafe, 'First ' . $s), 'a script address stored in the database is turned into a safe link when it is shown');

    // ---- 7. The founder's portrait: upload, publish, replace by the standard ---------------------------------------------------------------------
    $jpeg = tempnam(sys_get_temp_dir(), 'okv-cs-img-') . '.jpg';
    $canvas = imagecreatetruecolor(900, 1100);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 40, 120, 60));
    imagejpeg($canvas, $jpeg, 80);
    imagedestroy($canvas);
    $textFile = tempnam(sys_get_temp_dir(), 'okv-cs-txt-') . '.jpg';
    file_put_contents($textFile, 'this is not an image');
    $upload = static function (string $slug, string $slot, string $file, string $alt, ?string $csrf = null, ?string $jar = null) use ($base, $jarE, $csrfE, $fingerprint): array {
        return cs_req($jar ?? $jarE, $base . '/api/v1/content.php', [
            'action' => 'upload_slot_image', 'slug' => $slug, 'slot' => $slot, 'fingerprint' => $fingerprint($slug), 'okv_csrf' => $csrf ?? $csrfE,
            'image_alt' => $alt, 'image' => new CURLFile($file, 'image/jpeg', 'portrait.jpg'),
        ]);
    };
    [$code, , $result] = $upload('about', 'founder_portrait', $jpeg, '');
    cs_ok($code === 422 && ($result['code'] ?? '') === 'image_alt_required', 'a portrait without a description is refused');
    [$code, , $result] = $upload('about', 'founder_portrait', $textFile, "Description $s");
    cs_ok($code === 422 && in_array((string) ($result['code'] ?? ''), ['invalid_image', 'image_too_small'], true), 'a file that is not a photograph is refused');
    [$code, , $result] = $upload('faq', 'founder_portrait', $jpeg, "Description $s");
    cs_ok($code === 422 && ($result['code'] ?? '') === 'image_not_supported', 'a page without that photograph slot refuses it');
    [$code, , $result] = $upload('about', 'not_a_slot', $jpeg, "Description $s");
    cs_ok($code === 422 && ($result['code'] ?? '') === 'image_not_supported', 'and so does a slot that does not exist');
    [$code] = $upload('about', 'founder_portrait', $jpeg, "Description $s", $csrfV, $jarV);
    cs_eq(403, $code, 'a colleague who may only look cannot upload');
    cs_eq(count($uploadsBefore), count(glob(dirname(__DIR__, 2) . '/uploads/content/*') ?: []), 'and none of the refused uploads left a file behind');

    [$code, , $result] = $upload('about', 'founder_portrait', $jpeg, "The founder at the market $s");
    cs_eq(200, $code, 'a colleague uploads the founder portrait');
    cs_eq('image_updated', (string) ($result['code'] ?? ''), 'and is told it was saved to the draft');
    $draftData = ContentPages::findForAdmin('about')['content_data'];
    $portraitPath = (string) ($draftData['founder_portrait'] ?? '');
    cs_ok(ContentSlots::isUploadedPath($portraitPath) && cs_file(dirname(__DIR__, 2) . $portraitPath), 'the draft holds a prepared WebP and the file is on disk');
    cs_eq("The founder at the market $s", (string) ($draftData['founder_portrait_alt'] ?? ''), 'with the description the colleague wrote');
    cs_eq("Caption $s", (string) ($draftData['founder_caption'] ?? ''), 'and the other words were not lost');
    cs_ok(!str_contains($pub('/our-story'), $portraitPath), 'the public page does not show it before it is published');

    // Saving the text form must not wipe the photograph the draft holds. The real form
    // posts the description box as well, pre-filled, so the test does too.
    $aboutForm = $custom['about'] + ['founder_portrait_alt' => "The founder at the market $s"];
    [$code] = $save('about', $titles['about'], $bodies['about'], $aboutForm);
    cs_eq(200, $code, 'a colleague saves the text form again');
    cs_eq($portraitPath, (string) (ContentPages::findForAdmin('about')['content_data']['founder_portrait'] ?? ''), 'the photograph is still in the draft');

    $publish('about');
    $aboutPortrait = $pub('/our-story');
    cs_ok(str_contains($aboutPortrait, 'src="' . okv_image_url($portraitPath) . '"') && str_contains($aboutPortrait, 'alt="The founder at the market ' . $s . '"'), 'published, the portrait is the uploaded one, with its description');
    cs_ok(str_contains($aboutPortrait, "Caption $s</figcaption>") && !str_contains($aboutPortrait, 'founder-kumbish-emmanuel-putleh.jpg'), 'under the caption the Owner wrote, and the standard photograph is gone');
    cs_ok(strpos($aboutPortrait, okv_image_url($portraitPath)) > strpos($aboutPortrait, 'The person behind it'), 'sitting under the heading in the story');

    // Rename the heading: the portrait must not vanish with it.
    $renamedBody = "## Where we started\n\nThe market.\n\n## About the founder\n\nFounder words $s.";
    $save('about', $titles['about'], $renamedBody, $aboutForm);
    $publish('about');
    $aboutRenamed = $pub('/our-story');
    cs_ok(str_contains($aboutRenamed, 'src="' . okv_image_url($portraitPath) . '"') && strpos($aboutRenamed, okv_image_url($portraitPath)) > strpos($aboutRenamed, "Founder words $s"), 'when the Owner renames that heading the portrait follows the story instead of disappearing');

    // Back to the standard photograph.
    [$code, , $result] = $api('remove_slot_image', 'about', ['slot' => 'founder_portrait']);
    cs_eq(200, $code, 'a colleague goes back to the standard photograph');
    cs_eq('image_removed', (string) ($result['code'] ?? ''), 'and is told so');
    cs_eq('', (string) (ContentPages::findForAdmin('about')['content_data']['founder_portrait'] ?? 'x'), 'the draft no longer holds one');
    cs_ok(cs_file(dirname(__DIR__, 2) . $portraitPath), 'but the file stays while it is still the published photograph, so the live page never breaks');
    $publish('about');
    $aboutStandard = $pub('/our-story');
    cs_ok(str_contains($aboutStandard, 'founder-kumbish-emmanuel-putleh.jpg') && !str_contains($aboutStandard, okv_image_url($portraitPath)), 'published, the standard portrait is back');

    // A photograph that never went live is cleaned up when it is replaced.
    [$code] = $upload('about', 'founder_portrait', $jpeg, "First try $s");
    $firstDraft = (string) (ContentPages::findForAdmin('about')['content_data']['founder_portrait'] ?? '');
    [$code] = $upload('about', 'founder_portrait', $jpeg, "Second try $s");
    $secondDraft = (string) (ContentPages::findForAdmin('about')['content_data']['founder_portrait'] ?? '');
    cs_ok($firstDraft !== '' && $secondDraft !== '' && $firstDraft !== $secondDraft, 'two uploads in a row give two different files');
    cs_ok(!cs_file(dirname(__DIR__, 2) . $firstDraft) && cs_file(dirname(__DIR__, 2) . $secondDraft), 'the first, which never went live, was removed when the second replaced it');
    $api('remove_slot_image', 'about', ['slot' => 'founder_portrait']);
    cs_ok(!cs_file(dirname(__DIR__, 2) . $secondDraft), 'and removing a draft only photograph deletes its files');

    // A photograph can only be published with a description, and only if it came through the upload workflow.
    $tampered = ContentPages::findForAdmin('about')['content_data'];
    $tampered['founder_portrait'] = '/etc/passwd';
    Database::run('UPDATE content_pages SET draft_content_data = :d WHERE slug = :s', [':d' => json_encode($tampered), ':s' => 'about']);
    [$code, , $result] = $publish('about');
    cs_ok($code === 422 && isset($result['errors']['content_data.founder_portrait']), 'a photograph path that did not come from the upload workflow cannot be published');
    unset($jpeg);
} finally {
    foreach ($slugs as $slug) {
        $row = $before[$slug] ?? null;
        if ($row === null) { continue; }
        $set = []; $params = [':slug' => $slug];
        foreach ($columns as $column) {
            $set[] = "`$column` = :$column";
            $params[':' . $column] = $row[$column];
        }
        Database::run('UPDATE content_pages SET ' . implode(', ', $set) . ' WHERE slug = :slug', $params);
    }
    foreach ((glob(dirname(__DIR__, 2) . '/uploads/content/*') ?: []) as $file) {
        if (!in_array($file, $uploadsBefore, true)) { @unlink($file); }
    }
    $ids = $users ? implode(',', array_map('intval', $users)) : '0';
    Database::run("DELETE FROM audit_logs WHERE entity_type = 'content_page' AND actor_user_id IN ($ids)");
    Database::run("DELETE FROM audit_logs WHERE actor_user_id IN ($ids)");
    Database::run("DELETE FROM user_roles WHERE user_id IN ($ids)");
    foreach ($roles as $role) {
        Database::run('DELETE FROM role_permissions WHERE role_id = :r', [':r' => $role]);
        Database::run('DELETE FROM roles WHERE id = :r', [':r' => $role]);
    }
    Database::run("DELETE FROM users WHERE id IN ($ids)");
    foreach ([$jarE, $jarV, $jarO, $jarG] as $jar) { if (is_string($jar) && is_file($jar)) { unlink($jar); } }
    foreach (glob(sys_get_temp_dir() . '/okv-cs-*') ?: [] as $leftover) { @unlink($leftover); }
}

fwrite(STDOUT, "\n$passed / $tests content slots HTTP assertions passed.\n");
exit($passed === $tests ? 0 : 1);
