<?php
/**
 * The staff order list (admin/orders.php): filters behind a disclosure, the
 * list paged 50 at a time, and the order detail opening as a sheet on a narrow
 * screen instead of stacking under the list.
 *
 * The Owner asked for three things on a phone: hide the filters under a toggle,
 * page the list 50 at a time with a next page, and open an order in a sheet
 * rather than scrolling to the bottom. This pins that contract without a
 * database: the page is read as source, the behaviour it hands to
 * assets/js/admin-orders.js is read there, and the sheet's styling is checked
 * in both the CSS source and the built stylesheet. With JavaScript off the page
 * still works, so the fallbacks are pinned too.
 */

$root = dirname(__DIR__, 2);
$read = static fn (string $rel): string => (string) file_get_contents($root . '/' . $rel);
$emDash = "\xe2\x80\x94";

$page   = $read('admin/orders.php');
$script = $read('assets/js/admin-orders.js');
$cssSrc = $read('assets/css/src/input.css');
$cssOut = $read('assets/css/tailwind.css');

// 1. Pagination, 50 a page, built on the shared helpers rather than a new
//    hand-rolled LIMIT. The count runs the same filters as the list.
okv_test_ok(
    str_contains($page, "require_once __DIR__ . '/../includes/components/pagination.php'"),
    'orders.php pulls in the shared pagination component'
);
okv_test_ok(
    (bool) preg_match('/\$perPage\s*=\s*50\s*;/', $page),
    'the list is paged 50 at a time'
);
okv_test_ok(
    str_contains($page, 'okv_limit_clause($page, $perPage)'),
    'the list query pages through okv_limit_clause, not a fixed LIMIT'
);
okv_test_ok(
    !str_contains($page, 'ORDER BY o.id DESC LIMIT 50'),
    'the old hard LIMIT 50 is gone'
);
okv_test_ok(
    str_contains($page, 'SELECT COUNT(*) AS matched'),
    'a count query backs the page switcher and the Showing line'
);
okv_test_ok(
    str_contains($page, "okv_pagination(\$page, \$pageCount, \$ordersPageUrl, 'Order pages')"),
    'the page switcher renders under the list'
);
okv_test_ok(
    str_contains($page, "okv_page_summary(\$page, \$totalOrders, \$perPage, 'order')"),
    'the Showing line replaces the old "Last 50" label'
);
okv_test_ok(
    !str_contains($page, 'Last 50'),
    'the fixed "Last 50" label is gone'
);
okv_test_ok(
    str_contains($page, "'page' => \$n") && str_contains($page, 'http_build_query($orderFilterQuery'),
    'page links and detail links keep the filters in the URL'
);

// 2. Filters behind a disclosure, open only when one is set, so an active
//    filter is never hidden.
okv_test_ok(
    str_contains($page, '<details class="group mb-5 rounded-md border border-mist bg-white"') && str_contains($page, '<summary'),
    'the filters live inside a details disclosure'
);
okv_test_ok(
    str_contains($page, '<?= $filtersActive ? \'open\' : \'\' ?>'),
    'the disclosure opens when a filter is active'
);
okv_test_ok(
    str_contains($page, 'method="get"') && str_contains($page, 'name="filter_status"')
        && str_contains($page, 'name="filter_customer"'),
    'the real GET filter form, with its fields, is still inside the disclosure'
);

// 3. The detail as a sheet on a narrow screen. The sheet shell is static in the
//    page, the list links are tagged for the script, and the detail section is
//    tagged as the source the script lifts from.
okv_test_ok(
    (bool) preg_match('/<div class="okv-order-backdrop xl:hidden" id="order-sheet" hidden>/', $page),
    'the sheet shell ships closed and only below xl'
);
okv_test_ok(
    str_contains($page, 'data-order-panel') && str_contains($page, 'data-order-close')
        && str_contains($page, 'id="order-sheet-body"'),
    'the sheet carries its panel, its Close and the body the detail drops into'
);
okv_test_ok(
    str_contains($page, 'data-order-detail'),
    'the server-rendered detail section is tagged as the sheet source'
);
okv_test_ok(
    substr_count($page, 'data-order-link') >= 1 && str_contains($page, 'data-order-link'),
    'each order in the list is tagged for the sheet to open'
);
okv_test_ok(
    str_contains($page, "\$okv_admin_script = '/assets/js/admin-orders.js'"),
    'the page loads its behaviour script'
);
okv_test_ok(
    str_contains($page, 'aria-labelledby="order-sheet-title"'),
    'the sheet dialog is labelled'
);

// 4. The script: it only takes over below xl, it opens a dialog, it pushes a
//    history entry so Back closes, and it fetches the same URL rather than
//    rebuilding the detail. Desktop is left to navigate normally.
okv_test_ok(
    str_contains($script, "window.matchMedia('(max-width: 1279px)')"),
    'the script only acts below the xl two-column breakpoint'
);
okv_test_ok(
    str_contains($script, 'if (!narrow.matches'),
    'a desktop click is left to navigate, the script steps aside'
);
okv_test_ok(
    str_contains($script, "setAttribute('role', 'dialog')") && str_contains($script, "setAttribute('aria-modal', 'true')"),
    'the open sheet is a modal dialog'
);
okv_test_ok(
    str_contains($script, 'window.history.pushState') && str_contains($script, "addEventListener('popstate'"),
    'opening pushes a history entry so phone Back closes the sheet'
);
okv_test_ok(
    str_contains($script, 'window.fetch(href') && str_contains($script, "querySelector('[data-order-detail]')"),
    'the script fetches the same URL and lifts the rendered detail out of it'
);
okv_test_ok(
    str_contains($script, 'document.documentElement.style.overflow') && str_contains($script, 'document.body.style.overflow'),
    'the page behind the sheet is locked'
);

// 5. The sheet styling exists in the CSS source and is carried into the built,
//    minified stylesheet, so a deploy does not ship a stale sheet.
okv_test_ok(
    str_contains($cssSrc, '.okv-order-backdrop') && str_contains($cssSrc, '.okv-order-sheet'),
    'the sheet classes are defined in the CSS source'
);
okv_test_ok(
    str_contains($cssOut, 'okv-order-backdrop') && str_contains($cssOut, 'okv-order-sheet'),
    'the built stylesheet carries the sheet classes'
);

// 6. House laws on the two files this work added or rewrote.
okv_test_ok(
    !str_contains($page, $emDash) && !str_contains($script, $emDash) && !str_contains($cssSrc, $emDash),
    'no em dash in the changed order-list source'
);
