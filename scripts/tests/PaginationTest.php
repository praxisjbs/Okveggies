<?php
/** Pagination maths: the LIMIT clause, the page window and the summary line. */

require_once $appRoot . '/includes/classes/Products.php';

// The agreed page size, pinned so a stray edit cannot quietly change it.
okv_test_eq(25, Catalogue::PER_PAGE, 'the shop shows 25 products per page');
okv_test_eq(25, Products::PER_PAGE, 'the admin catalogue shows 25 products per page');

// The LIMIT clause. Everything is a clamped int and the search bound through
// placeholders, so nothing hostile can ride into the SQL through a page number.
okv_test_eq('', okv_limit_clause(1, null), 'no page size means no LIMIT at all');
okv_test_eq(' LIMIT 25 OFFSET 0', okv_limit_clause(1, 25), 'page one starts at offset zero');
okv_test_eq(' LIMIT 25 OFFSET 50', okv_limit_clause(3, 25), 'page three skips two full pages');
okv_test_eq(' LIMIT 25 OFFSET 0', okv_limit_clause(0, 25), 'a page below one clamps back to one');
okv_test_eq(' LIMIT 25 OFFSET 0', okv_limit_clause(-40, 25), 'a negative page clamps back to one');
okv_test_eq(' LIMIT 1 OFFSET 0', okv_limit_clause(1, 0), 'a page size below one clamps to one');

// The page window. First and last pages always show; the rest is the current
// page and its neighbours, with an ellipsis where a run breaks.
okv_test_eq([], okv_page_window(1, 0), 'no pages, no window');
okv_test_eq([1], okv_page_window(1, 1), 'one page is just itself');
okv_test_eq([1, 2, 3], okv_page_window(1, 3), 'every page fits when there are only three');
okv_test_eq([1, 2, 3, '…', 10], okv_page_window(1, 10), 'page one keeps the last page in reach');
okv_test_eq([1, 2, 3, 4], okv_page_window(2, 4), 'near the start there is no leading gap');
okv_test_eq([1, '…', 3, 4, 5, 6, 7, '…', 10], okv_page_window(5, 10), 'a middle page shows neighbours both ways');
okv_test_eq([1, '…', 8, 9, 10], okv_page_window(10, 10), 'the last page keeps the first in reach');
okv_test_eq([1, '…', 8, 9, 10], okv_page_window(14, 10), 'a page past the end clamps to the end');
okv_test_eq([1, 2, 3], okv_page_window(-2, 3), 'a page below one clamps to one');

// The summary line over a listing.
okv_test_eq('0 items', okv_page_summary(1, 0, 25, 'item'), 'no matches says so plainly');
okv_test_eq('1 item', okv_page_summary(1, 1, 25, 'item'), 'one row stays singular');
okv_test_eq('24 items', okv_page_summary(1, 24, 25, 'item'), 'a single page is a plain count');
okv_test_eq('Showing 1 to 25 of 87 items', okv_page_summary(1, 87, 25, 'item'), 'first page of many names the range');
okv_test_eq('Showing 26 to 50 of 87 items', okv_page_summary(2, 87, 25, 'item'), 'second page of many names the range');
okv_test_eq('Showing 76 to 87 of 87 items', okv_page_summary(4, 87, 25, 'item'), 'the last page stops at the total');
okv_test_eq('Showing 26 to 50 of 57 products', okv_page_summary(2, 57, 25, 'product'), 'the admin list counts products');
