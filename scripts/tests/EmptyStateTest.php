<?php
/**
 * scripts/tests/EmptyStateTest.php
 * -----------------------------------------------------------------------------
 * The empty, error and success block (includes/components/shop/empty_state.php),
 * which the basket, the shop results, the combo lists, the kitchen runs page,
 * the FAQ panel and every degraded home section all render through.
 *
 * The heading is the part worth pinning hardest, because the component prints a
 * tag name it reads from its options. A caller that passes no options at all
 * used to print "< id=okv-empty-6d2ad963 class=...>Your basket is empty</>":
 * the whitelist checked a defaulted read, the default passed, and the branch it
 * approved then cast the raw, unset option, which is null. HTML parses "< " as
 * text, so the attributes landed on the page as copy and the section lost the
 * heading its aria-labelledby pointed at. Five live call sites did this. These
 * tests render the component and hold the markup to account.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/includes/functions/helpers.php';
require_once $root . '/includes/components/shop/empty_state.php';

/**
 * Render one empty state and catch every notice or warning it raises.
 *
 * @param list<array<string, mixed>> $actions
 * @param array<string, mixed> $opts
 * @return array{0:string,1:list<string>} the markup, then the messages
 */
$renderState = static function (string $icon, string $heading, string $line, array $actions = [], array $opts = []): array {
    $caught = [];
    set_error_handler(static function (int $severity, string $message) use (&$caught): bool {
        $caught[] = $message;
        return true;
    });
    ob_start();
    okv_empty_state($icon, $heading, $line, $actions, $opts);
    $html = (string) ob_get_clean();
    restore_error_handler();
    return [$html, $caught];
};

$basketActions = [
    ['href' => '/shop.php', 'label' => 'Shop produce', 'icon' => 'leaf'],
    ['href' => '/combos.php', 'label' => 'See combos', 'style' => 'outline', 'icon' => 'basket'],
];

// ---------------------------------------------------------------------------
// 1. The regression: no options at all, exactly as cart.php calls it.
// ---------------------------------------------------------------------------
[$html, $caught] = $renderState('basket-empty', 'Your basket is empty', 'Pick the produce or ready baskets you need.', $basketActions);

okv_test_ok(str_contains($html, '<h2 id="okv-empty-'), 'a state with no options opens a real h2 element');
okv_test_ok(!str_contains($html, '< id='), 'the heading never prints with an empty tag name');
okv_test_ok(!preg_match('/<\s+[a-zA-Z-]+=/', $html), 'no attribute can leak onto the page as visible copy');
okv_test_ok(!str_contains($html, '</>'), 'the heading closes with a tag name, not with a bare angle');
okv_test_eq(1, substr_count($html, '</h2>'), 'the heading closes with the same tag it opened with');
okv_test_ok(str_contains($html, '>Your basket is empty</h2>'), 'the heading reads as a heading, not as markup');
okv_test_ok(str_contains($html, '>Pick the produce or ready baskets you need.</p>'), 'the line under the heading still renders');
okv_test_eq([], $caught, 'rendering with no options raises no PHP notice or warning');

// The section is announced by the heading it actually prints. With the empty
// tag name, aria-labelledby named an id nothing carried, so a screen reader
// read the block as unlabelled.
preg_match('/aria-labelledby="([^"]*)"/', $html, $labelledBy);
preg_match('/<h2 id="([^"]*)"/', $html, $headingId);
okv_test_ok(($headingId[1] ?? '') !== '', 'the heading carries an id');
okv_test_eq($headingId[1] ?? '', $labelledBy[1] ?? '', 'the section is labelled by the heading it prints');

// The derived id stays the four-word heading's own fingerprint, so two blocks
// with different copy never share one.
okv_test_ok(str_contains($html, 'id="okv-empty-' . substr(sha1('Your basket is empty'), 0, 8) . '"'), 'the id is derived from the heading');

// ---------------------------------------------------------------------------
// 2. Every tag on the whitelist is honoured, because a page that opens on this
//    block needs it to be the h1.
// ---------------------------------------------------------------------------
foreach (['h1', 'h2', 'h3', 'p'] as $want) {
    [$h] = $renderState('leaf', 'Heading here', 'One short line.', [], ['heading_tag' => $want]);
    okv_test_ok(
        str_contains($h, '<' . $want . ' id="okv-empty-') && str_contains($h, '</' . $want . '>'),
        'heading_tag ' . $want . ' is honoured, opening and closing'
    );
}

// ---------------------------------------------------------------------------
// 3. Anything off the whitelist falls back to h2 and never reaches the markup.
// ---------------------------------------------------------------------------
foreach (['', '   ', 'h4', 'div', 'span', 'script', 'H2', null, 42] as $bad) {
    [$h, $c] = $renderState('leaf', 'Heading here', 'One short line.', [], ['heading_tag' => $bad]);
    okv_test_ok(str_contains($h, '<h2 id="okv-empty-'), 'heading_tag ' . var_export($bad, true) . ' falls back to h2');
    okv_test_ok(!str_contains($h, '< id='), 'heading_tag ' . var_export($bad, true) . ' cannot empty the tag name');
    okv_test_ok(!str_contains($h, '<script'), 'nothing off the whitelist reaches the markup');
    okv_test_eq([], $c, 'heading_tag ' . var_export($bad, true) . ' raises no warning');
}

[$h] = $renderState('leaf', 'Heading here', 'One short line.', [], ['heading_tag' => ' h1 ']);
okv_test_ok(str_contains($h, '<h1 id="okv-empty-'), 'a padded heading_tag is trimmed, not rejected');

// ---------------------------------------------------------------------------
// 4. Copy is escaped on the way in, on both lines.
// ---------------------------------------------------------------------------
[$h] = $renderState('leaf', '<script>alert("x")</script>', 'A <b>bold</b> claim & more.', []);
okv_test_ok(!str_contains($h, '<script>'), 'the heading is escaped, never echoed as markup');
okv_test_ok(str_contains($h, '&lt;script&gt;'), 'the escaped heading still shows the words');
okv_test_ok(str_contains($h, '&lt;b&gt;bold&lt;/b&gt;'), 'the line under the heading is escaped too');
okv_test_ok(str_contains($h, '&amp; more.'), 'an ampersand in the copy survives as an entity');

// ---------------------------------------------------------------------------
// 5. The actions: two forest buttons, escaped, with the 44px touch target.
// ---------------------------------------------------------------------------
[$h] = $renderState('leaf', 'Heading here', 'One short line.', [
    ['href' => '/shop.php?a=1&b=2', 'label' => 'Shop & see', 'icon' => 'leaf'],
    ['href' => '/combos.php', 'label' => 'See combos', 'style' => 'outline'],
]);
okv_test_ok(str_contains($h, 'href="/shop.php?a=1&amp;b=2"'), 'an action href is escaped');
okv_test_ok(str_contains($h, 'Shop &amp; see'), 'an action label is escaped');
okv_test_ok(str_contains($h, 'okv-btn-outline'), 'an outline action keeps the outline button');
okv_test_eq(2, substr_count($h, 'min-h-[44px]'), 'both actions keep the 44px touch target');
okv_test_ok(str_contains($h, 'okv-empty-art'), 'the illustration sits in its own art span');

[$h] = $renderState('leaf', 'Heading here', 'One short line.', []);
okv_test_ok(!str_contains($h, '<a href'), 'a state with no actions prints no buttons and no empty row');

[$h, $caught] = $renderState('leaf', 'Heading here', 'One short line.', [['href' => '/shop.php']]);
okv_test_eq([], $caught, 'an action with no label, style or icon raises no warning');
okv_test_ok(str_contains($h, 'href="/shop.php"'), 'a label-less action still links where it was pointed');

// ---------------------------------------------------------------------------
// 6. An icon the set does not know draws nothing rather than breaking the block.
// ---------------------------------------------------------------------------
[$h, $caught] = $renderState('not-an-icon', 'Heading here', 'One short line.', []);
okv_test_ok(str_contains($h, '<h2 id="okv-empty-'), 'an unknown icon name does not break the block');
okv_test_ok(!str_contains($h, '<svg'), 'an unknown icon prints no svg at all, never an empty one');
okv_test_eq([], $caught, 'an unknown icon name raises no warning');

// ---------------------------------------------------------------------------
// 7. The caller's id, class and role are used as given.
// ---------------------------------------------------------------------------
[$h] = $renderState('leaf', 'Heading here', 'One short line.', [], [
    'id' => 'basket-empty-state',
    'class' => 'mt-8',
    'role' => 'alert',
]);
okv_test_ok(str_contains($h, '<h2 id="basket-empty-state"'), 'a caller id replaces the derived one');
okv_test_ok(str_contains($h, 'aria-labelledby="basket-empty-state"'), 'the label follows the caller id');
okv_test_ok(str_contains($h, 'role="alert"'), 'a caller can make the state assertive');
okv_test_ok(str_contains($h, 'class="okv-empty mt-8"'), 'a caller class is added to the component class');

// ---------------------------------------------------------------------------
// 8. The bug class, nowhere in the shipped code. A whitelist that checks a
//    defaulted read and then casts the raw key in the branch it just approved
//    is what printed the empty tag name. Only a non-empty default can do it:
//    an empty one never passes a strict whitelist, so the raw read never runs.
// ---------------------------------------------------------------------------
$rawReread = '~\(\s*\$(\w+)\[\x27([^\x27]+)\x27\]\s*\?\?\s*(\x27[^\x27]+\x27|[1-9]\d*)\s*\)[^;]*\?\s*\((?:string|int|float|bool)\)\s*\$\1\[\x27\2\x27\]~';

$collectPhp = static function (string $dir) use (&$collectPhp): array {
    $found = glob($dir . '/*.php') ?: [];
    foreach (glob($dir . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
        if (in_array(basename($sub), ['vendor', 'node_modules', 'uploads'], true)) {
            continue;
        }
        $found = array_merge($found, $collectPhp($sub));
    }
    return $found;
};

$offenders = [];
$scanned = 0;
foreach ($collectPhp($root) as $file) {
    $source = (string) file_get_contents($file);
    $scanned++;
    if (preg_match($rawReread, $source, $match)) {
        $offset = (int) strpos($source, $match[0]);
        $offenders[] = substr($file, strlen($root) + 1) . ':' . (substr_count(substr($source, 0, $offset), "\n") + 1);
    }
}
okv_test_ok($scanned > 250, 'the guard actually walked the shipped tree (' . $scanned . ' files)');
okv_test_eq([], $offenders, 'no file re-reads an option raw after a defaulted whitelist check');
