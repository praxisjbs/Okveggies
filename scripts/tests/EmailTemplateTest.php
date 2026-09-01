<?php
/**
 * scripts/tests/EmailTemplateTest.php
 * An email is the one piece of the brand that lands somewhere we do not
 * control, and it is often the first thing a customer sees after paying. These
 * assertions pin the branded shell: that it escapes what it is given, that the
 * one action is always reachable, that the copy carries no em dash and no
 * jargon, and that migration 009 is the shape a migration has to be.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/classes/Brand.php';
require_once $root . '/includes/classes/Mail.php';

if (!defined('APP_URL')) {
    define('APP_URL', 'https://okveggies.test');
}

// 1. The shell renders the brand chrome, from the tokens, every time.
$html = Mail::brandedHtml('Payment received for OKV26001', "Hi Ada, your payment reached us.\n\nThank you.");
okv_test_ok(str_starts_with($html, '<!doctype html>'), 'the email is a complete HTML document');
okv_test_ok(str_contains($html, Brand::FOREST), 'the header band is Forest Green from the token');
okv_test_ok(str_contains($html, 'lockup-white-720.png'), 'the letterhead carries the raster lockup, not an SVG an email client would drop');
okv_test_ok(str_contains($html, 'alt="OK Veggies"'), 'the mark has alt text for a client with images switched off');
okv_test_ok(str_contains($html, 'https://okveggies.test/assets/img/brand/lockup-white-720.png'), 'the mark is an absolute URL, because an email has no site to be relative to');
okv_test_ok(str_contains($html, Brand::FONT_SANS), 'the email sets the brand sans with real fallbacks');
okv_test_ok(!str_contains($html, 'bg-forest'), 'no stylesheet class in an email, only inline styles');

// 2. Gold is never a fill in an email either (bible 3.10).
okv_test_ok(!str_contains($html, 'background:' . Brand::GOLD), 'gold is never an email background');
okv_test_ok(!str_contains($html, 'background:' . Brand::GOLD_TINT), 'gold tint is never an email background');

// 3. Two paragraphs of copy become two paragraphs of HTML.
okv_test_eq(2, substr_count($html, '<p style="margin:0 0 16px;font-size:16px'), 'a blank line in the copy makes a new paragraph');

// 4. Anything from a template or a customer is escaped.
$evil = Mail::brandedHtml('Hi', 'Hi <script>alert(1)</script> & "friends"');
okv_test_ok(!str_contains($evil, '<script>'), 'a script tag in the copy is escaped, never rendered');
okv_test_ok(str_contains($evil, '&lt;script&gt;'), 'the escaped form is what lands in the email');
okv_test_ok(str_contains($evil, '&amp;'), 'an ampersand is escaped');
$evilHeading = Mail::brandedHtml('<img src=x onerror=alert(1)>', 'Body');
okv_test_ok(!str_contains($evilHeading, '<img src=x'), 'a heading is escaped as tightly as the body');

// 5. The one action is a real button, and its address is always readable.
$cta = Mail::brandedHtml('Your code is 123456', 'Use the button below.', ['label' => 'Activate your account', 'url' => 'https://okveggies.test/public/auth/activate.php']);
okv_test_ok(str_contains($cta, '>Activate your account</a>'), 'the call to action is a link with its own label');
okv_test_ok(str_contains($cta, 'padding:12px 24px'), 'the button is on the 8px grid, 12px by 24px, as bible 5.5 sets it');
okv_test_eq(2, substr_count($cta, 'https://okveggies.test/public/auth/activate.php'), 'the address appears in the button and again as text, so a blocked button is never a dead end');

// 6. The plain text alternative carries the same next step.
$text = Mail::plainText('Your code is 123456', 'Use the link below.', ['label' => 'Activate your account', 'url' => 'https://okveggies.test/go']);
okv_test_ok(str_contains($text, 'Activate your account: https://okveggies.test/go'), 'the plain text alternative carries the link');
okv_test_ok(!str_contains($text, '<'), 'the plain text alternative carries no markup');

// 7. A sender that passes an address but forgets the button still sends one.
okv_test_eq('Follow your order', Mail::ctaFromVars(['order_trail_url' => 'https://okveggies.test/t/abc'])['label'] ?? '', 'an order trail link becomes a Follow your order button');
okv_test_eq('Set a new password', Mail::ctaFromVars(['reset_url' => 'https://okveggies.test/r'])['label'] ?? '', 'a reset link becomes a Set a new password button');
okv_test_eq('Open this in your browser', Mail::ctaFromVars(['tracking_url' => 'https://okveggies.test/x'])['label'] ?? '', 'an address we have no label for still gets a button');
okv_test_eq(null, Mail::ctaFromVars(['customer_name' => 'Ada', 'code' => '123456']), 'no address means no button, not an empty one');
okv_test_eq(null, Mail::ctaFromVars(['order_trail_url' => '']), 'an empty address is not a button');

// 8. Migration 009 is the shape every migration has to be.
$migration = (string) file_get_contents($root . '/migrations/009_branded_email_templates.sql');
okv_test_ok($migration !== '', 'migration 009 exists');
okv_test_ok(str_contains($migration, 'START TRANSACTION;'), 'migration 009 opens a transaction');
okv_test_ok(str_contains($migration, 'COMMIT;'), 'migration 009 commits');
okv_test_ok(str_contains($migration, 'ON DUPLICATE KEY UPDATE'), 'migration 009 is idempotent on the template key');
okv_test_ok(str_contains($migration, '-- Verification:'), 'migration 009 ends with verification queries');
okv_test_ok(!str_contains($migration, "\u{2014}"), 'no em dash in migration 009');
okv_test_ok(!str_contains($migration, 'UPDATE notification_templates SET'), 'migration 009 seeds by insert, so a missing row is created rather than silently skipped');

foreach (['order_placed', 'payment_confirmed', 'deposit_received', 'order_dispatched',
          'order_delivered', 'account_activation', 'password_reset'] as $key) {
    okv_test_ok(str_contains($migration, "('" . $key . "', 'email',"), "migration 009 carries the $key template");
}

// 9. The copy in the migration is on brand.
// The banned words are read out of scripts/brand-check.sh rather than typed
// again here, so the repository holds exactly one list. Typing them a second
// time would also, correctly, trip the guard on this very file.
$guard = (string) file_get_contents($root . '/scripts/brand-check.sh');
// The alternation holding the words is the one group with several
// pipe-separated lowercase phrases; the others on that line are lookarounds.
preg_match('/\\(([a-z\\- ]+(?:\\|[a-z\\- ]+){5,})\\)/', $guard, $m);
$banned = array_filter(explode('|', $m[1] ?? ''));
okv_test_ok(count($banned) >= 11, 'the banned word list was read from the brand guard');
foreach ($banned as $word) {
    okv_test_ok(stripos($migration, $word) === false, "no banned jargon in the email copy: $word");
}
okv_test_ok(substr_count($migration, '\n\n') >= 7, 'every template body is split into paragraphs for the shell');

// 10. Migrations 006 and 007 were not edited to get here.
$six   = (string) file_get_contents($root . '/migrations/006_content_and_templates_seed.sql');
$seven = (string) file_get_contents($root . '/migrations/007_auth_email_templates.sql');
okv_test_ok(str_contains($six, '{{order_trail_url}}'), 'migration 006 still says exactly what it said when it shipped');
okv_test_ok(str_contains($seven, '{{activate_url}}'), 'migration 007 still says exactly what it said when it shipped');
