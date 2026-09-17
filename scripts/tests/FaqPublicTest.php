<?php
$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/page.php');
$component = (string) file_get_contents($root . '/includes/components/shop/faq_disclosures.php');
$script = (string) file_get_contents($root . '/assets/js/faq.js');
$admin = (string) file_get_contents($root . '/admin/content.php');
$migration = (string) file_get_contents($root . '/migrations/050_unpublish_placeholder_faq.sql');

okv_test_ok(str_contains($page, "'faq'"), 'FAQ is in the fixed public content route allowlist');
okv_test_ok(str_contains($page, 'FaqContent::present'), 'the public FAQ uses the shared parser and token resolver');
okv_test_ok(str_contains($page, 'okv_faq_disclosures'), 'the public FAQ uses the shared disclosure component');
okv_test_ok(str_contains($component, '<details') && str_contains($component, '<summary'), 'FAQ uses native no-JavaScript disclosures');
okv_test_ok(str_contains($component, 'aria-level="2"') && str_contains($component, 'aria-controls='), 'questions retain heading and answer association semantics');
okv_test_ok(str_contains($component, 'Show answer') && str_contains($component, 'Hide answer'), 'expanded state has a text signal as well as colour');
okv_test_ok(str_contains($component, 'min-h-[56px]'), 'every question summary exceeds the 44px touch target');
okv_test_ok(str_contains($script, '.open = open') && !str_contains($script, 'innerHTML'), 'bulk controls use the native open property and never unsafe HTML');
okv_test_ok(str_contains($page, 'There are no published questions just now.'), 'a published FAQ with no valid items has a useful empty state');
okv_test_ok(substr_count($page, 'okv_support_whatsapp_url()') >= 2 && str_contains($page, '/contact.php'), 'FAQ offers both Contact and WhatsApp routes');
okv_test_ok(str_contains($admin, 'FaqContent::tokenDefinitions()'), 'the admin editor documents its explicit operational tokens');
okv_test_ok(str_contains($admin, 'cannot be published yet') && str_contains($admin, 'Published order'), 'the admin shows structure errors and stable source order');
okv_test_ok(str_contains($migration, "body = 'Answers to the questions we hear most"), 'migration 050 targets only the exact shipped FAQ placeholder');
okv_test_ok(str_contains($migration, 'START TRANSACTION;') && str_contains($migration, 'COMMIT;'), 'the FAQ data migration is transactional');

