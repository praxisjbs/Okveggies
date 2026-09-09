<?php
/** Pure saved Kitchen List limits and messages. */

$basics = KitchenLists::validateBasics('  Tuesday restock  ', '  Main kitchen  ', [['item_name' => 'Tomatoes']]);
okv_test_eq('Tuesday restock', $basics[0], 'saved-list names are trimmed');
okv_test_eq('Main kitchen', $basics[1], 'saved-list notes are trimmed');
okv_test_eq(50, KitchenLists::MAX_LINES, 'a saved list holds at most 50 lines');

$refuses = static function (callable $work, string $code, string $label): void {
    try {
        $work();
        okv_test_ok(false, $label);
    } catch (DomainException $e) {
        okv_test_eq($code, $e->getMessage(), $label);
    }
};

$refuses(static fn() => KitchenLists::validateBasics('', null, [['item_name' => 'Tomatoes']]), 'invalid_name', 'a saved list needs a name');
$refuses(static fn() => KitchenLists::validateBasics(str_repeat('a', 151), null, [['item_name' => 'Tomatoes']]), 'invalid_name', 'a saved-list name is capped at 150 characters');
$refuses(static fn() => KitchenLists::validateBasics('Tuesday', str_repeat('a', 256), [['item_name' => 'Tomatoes']]), 'note_too_long', 'a saved-list note is capped at 255 characters');
$refuses(static fn() => KitchenLists::validateBasics('Tuesday', null, []), 'no_items', 'a saved list needs at least 1 line');
$refuses(static fn() => KitchenLists::validateBasics('Tuesday', null, array_fill(0, 51, ['item_name' => 'Tomatoes'])), 'too_many_items', 'a saved list refuses line 51');
okv_test_eq(404, KitchenLists::statusCode('not_found'), 'an unavailable saved list uses a non-revealing 404');
okv_test_eq(409, KitchenLists::statusCode('duplicate_name'), 'a duplicate saved-list name is a conflict');
