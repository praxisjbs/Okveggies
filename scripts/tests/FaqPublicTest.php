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
okv_test_ok(str_contains($component, 'Expand') && str_contains($component, 'Close'), 'expanded state has a text signal as well as colour');
okv_test_ok(str_contains($component, 'min-h-[56px]'), 'every question summary exceeds the 44px touch target');
okv_test_ok(str_contains($script, '.open = open') && !str_contains($script, 'innerHTML'), 'bulk controls use the native open property and never unsafe HTML');
okv_test_ok(str_contains($page, '$copy[\'empty_body\']') && ContentSlots::defaults('faq')['empty_body'] === 'There are no published questions just now.', 'a published FAQ with no valid items has a useful empty state, in words the Owner can change');
okv_test_ok(str_contains($page, '$copy[\'help_contact_path\']') && str_contains($page, '$copy[\'help_chat_path\']'), 'FAQ offers a Contact and a chat route, both from the slots');
okv_test_eq('/contact.php', ContentSlots::defaults('faq')['help_contact_path'], 'the standard Contact route is the contact page');
okv_test_eq('whatsapp', ContentSlots::defaults('faq')['help_chat_path'], 'and the standard chat route is the live WhatsApp link');
okv_test_eq(okv_support_whatsapp_url(), ContentSlots::href('whatsapp'), 'which resolves to the support WhatsApp address');
okv_test_ok(str_contains($admin, 'FaqContent::tokenDefinitions()'), 'the admin editor documents its explicit operational tokens');
okv_test_ok(str_contains($admin, 'cannot be published yet') && str_contains($admin, 'Published order'), 'the admin shows structure errors and stable source order');
okv_test_ok(str_contains($migration, "body = 'Answers to the questions we hear most"), 'migration 050 targets only the exact shipped FAQ placeholder');
okv_test_ok(str_contains($migration, 'START TRANSACTION;') && str_contains($migration, 'COMMIT;'), 'the FAQ data migration is transactional');

