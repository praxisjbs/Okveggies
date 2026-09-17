<?php
$values = [
    'deposit_percentage' => '35%',
    'household_delivery_schedule' => 'Monday and Saturday.',
    'support_email' => 'help@example.test',
];
$items = FaqContent::present(
    "## How do deposits work?\n\nPay {{deposit_percentage}} first, then the balance later.\n\n"
    . "## When do you deliver?\n\n{{household_delivery_schedule}}\n\n"
    . "## How do I ask for help?\n\nWrite to **{{support_email}}** or [contact us](/contact.php).",
    $values
);
okv_test_eq(3, count($items), 'FAQ presentation keeps every complete item');
okv_test_eq('How do deposits work?', $items[0]['question'], 'FAQ presentation keeps source order');
okv_test_ok(str_contains($items[0]['answer_html'], '35%'), 'FAQ presentation substitutes an approved current value');
okv_test_ok(str_contains($items[2]['answer_html'], '<strong>help@example.test</strong>'), 'resolved values retain safe restricted formatting');
okv_test_ok(str_contains($items[2]['answer_html'], 'href="/contact.php"'), 'FAQ answers retain safe local links');
okv_test_eq('how-do-deposits-work', $items[0]['anchor'], 'question anchors are descriptive and deterministic');
$duplicates = FaqContent::present("## Same question?\n\nOne.\n\n## same question\n\nTwo.", []);
okv_test_eq(1, count($duplicates), 'invalid legacy duplicates are shown only once publicly');
$unsafe = FaqContent::present("## Is text escaped?\n\n<script>alert(1)</script>", []);
okv_test_ok(str_contains($unsafe[0]['answer_html'], '&lt;script&gt;'), 'legacy FAQ HTML remains escaped at presentation time');
okv_test_ok(!str_contains($unsafe[0]['answer_html'], '<script>'), 'legacy FAQ HTML never becomes executable');
$unknown = FaqContent::resolveTokens('Keep {{unknown_value}} visible.', []);
okv_test_eq('Keep {{unknown_value}} visible.', $unknown, 'unknown tokens never access settings or disappear silently');

