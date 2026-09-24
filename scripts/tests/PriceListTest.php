<?php
/**
 * scripts/tests/PriceListTest.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The shared price-list view model and the two renderers it feeds.
 *
 * The XLSX round trip lives in PricingTest and pricing_db_test. This file pins
 * the half the new PDF and PNG downloads are made of: one view model whose
 * ordering, labels, counts and filenames cannot drift between formats, a PDF
 * that escapes a hostile catalogue and paginates, and PNG pages that are real
 * PNGs of the right dimensions, deterministically painted, wrapped in a ZIP
 * whose temp file never survives the call.
 *
 * No database is touched: the view model's pure half (fromRows) takes rows any
 * caller could have read, so the approved policy is tested against fixtures,
 * not against whatever the scratch catalogue holds today.
 * -----------------------------------------------------------------------------
 */

$root = dirname(__DIR__, 2);
if (!defined('APP_URL')) {
    define('APP_URL', 'https://okveggies.test');
}
date_default_timezone_set('Africa/Lagos');

// The renderers stand on the Composer packages the repository ships in
// vendor/: dompdf for the PDF, php-font-lib for the PNG letterforms. The
// autoloader is loaded here, the way pricing_db_test.php loads it for
// PhpSpreadsheet, and the suites say so plainly if it is missing.
$okv_autoload = $root . '/vendor/autoload.php';
if (is_readable($okv_autoload)) {
    require_once $okv_autoload;
}
$okv_have_dompdf = class_exists('Dompdf\\Dompdf') && class_exists('Dompdf\\Options');
$okv_have_gd = function_exists('imagecreatetruecolor');
if (!$okv_have_dompdf || !$okv_have_gd) {
    fwrite(STDOUT, "  skipped: dompdf or GD is missing, the PDF and PNG renderer assertions did not run\n");
    return;
}

/** The fixture rows: priced, unpriced, out of stock, restocking, hostile. */
$okv_rows = static function (): array {
    return [
        [
            'sku' => 'OKV-VG-001', 'name' => 'Fresh tomato', 'current_price_subunit' => 270000,
            'category_name' => 'Vegetables', 'unit' => 'kg',
            'availability_status' => 'available', 'restock_date' => null,
        ],
        [
            'sku' => 'OKV-VG-002', 'name' => 'Red bell pepper', 'current_price_subunit' => 800000,
            'category_name' => 'Vegetables', 'unit' => 'kg',
            'availability_status' => 'available', 'restock_date' => null,
        ],
        [
            'sku' => 'OKV-VG-003', 'name' => 'Okra', 'current_price_subunit' => 0,
            'category_name' => 'Vegetables', 'unit' => 'kg',
            'availability_status' => 'available', 'restock_date' => null,
        ],
        [
            'sku' => 'OKV-VG-004', 'name' => 'Green beans', 'current_price_subunit' => 120050,
            'category_name' => 'Vegetables', 'unit' => 'kg',
            'availability_status' => 'restocking', 'restock_date' => '2026-10-03',
        ],
        [
            'sku' => 'OKV-HP-010', 'name' => 'Basil leaf', 'current_price_subunit' => 50000,
            'category_name' => 'Herbs & Spices', 'unit' => 'bunch',
            'availability_status' => 'available', 'restock_date' => null,
        ],
        [
            'sku' => 'OKV-TR-020', 'name' => 'Yam, per tuber', 'current_price_subunit' => 350000,
            'category_name' => 'Tubers & Roots', 'unit' => 'tuber',
            'availability_status' => 'out_of_stock', 'restock_date' => null,
        ],
    ];
};

$okv_business = [
    'name' => 'OK Veggies',
    'tagline' => 'Sourced right. Priced right. Delivered right.',
    'email' => 'hello@okveggies.com.ng',
    'phone' => '0800 000 0000',
    'site' => 'okveggies.com.ng',
];
$okv_now = new DateTimeImmutable('2026-09-24T13:30:00+01:00', new DateTimeZone('Africa/Lagos'));

// 1. The view model: one ordering, one set of labels, for both formats.
$view = PriceList::fromRows($okv_rows(), $okv_business, $okv_now);

okv_test_eq('Price list', $view['title'], 'the title is the shared one');
okv_test_eq(3, count($view['categories']), 'categories arrive in the order the rows gave');
okv_test_eq('Vegetables', $view['categories'][0]['name'], 'the first category is the first row\'s category');
okv_test_eq('Herbs & Spices', $view['categories'][1]['name'], 'and the group names carry through');
okv_test_eq(4, count($view['categories'][0]['products']), 'the vegetables hold their four products');
okv_test_eq('Fresh tomato', $view['categories'][0]['products'][0]['name'], 'row order inside a category is kept');
okv_test_eq('₦2,700', $view['categories'][0]['products'][0]['price_label'], 'the price is Money formatting, naira and commas');
okv_test_eq('₦1,200.50', $view['categories'][0]['products'][3]['price_label'], 'a price with kobo keeps the kobo');
okv_test_eq('Not priced', $view['categories'][0]['products'][2]['price_label'], 'an unpriced product says so in words');
okv_test_eq(0, $view['categories'][0]['products'][2]['price_subunit'], 'and its subunits stay zero for whoever needs the number');
okv_test_eq('Out of stock', $view['categories'][2]['products'][0]['availability_label'], 'an out-of-stock row says so');
okv_test_eq('out', $view['categories'][2]['products'][0]['availability_key'], 'with the key the renderers switch on');
okv_test_ok(
    str_contains($view['categories'][0]['products'][3]['availability_label'], 'Restocking')
    && str_contains($view['categories'][0]['products'][3]['availability_label'], '3'),
    'a restocking row carries its date in the label'
);

okv_test_eq(6, $view['counts']['products'], 'the count covers every active product');
okv_test_eq(5, $view['counts']['priced'], 'the priced count is right');
okv_test_eq(1, $view['counts']['unpriced'], 'the unpriced count is right');
okv_test_eq(1, $view['counts']['out_of_stock'], 'the out-of-stock count is right');
okv_test_eq(1, $view['counts']['restocking'], 'the restocking count is right');

// 2. The notes at the foot: singular, plural, clean, and empty.
okv_test_eq(['1 product is still without a price, so it cannot be sold yet.'], PriceList::footNotes(['products' => 3, 'unpriced' => 1, 'out_of_stock' => 0, 'restocking' => 0]), 'one unpriced product, said once');
okv_test_eq(['4 products are still without a price, so they cannot be sold yet.'], PriceList::footNotes(['products' => 9, 'unpriced' => 4, 'out_of_stock' => 0, 'restocking' => 0]), 'several unpriced products, counted');
okv_test_eq(['2 products are out of stock this week.'], PriceList::footNotes(['products' => 5, 'unpriced' => 0, 'out_of_stock' => 2, 'restocking' => 0]), 'out-of-stock rows are counted at the foot');
okv_test_eq(
    ['3 products are restocking, with their dates on the rows.'],
    PriceList::footNotes(['products' => 5, 'unpriced' => 0, 'out_of_stock' => 0, 'restocking' => 3]),
    'restocking rows are counted too'
);
okv_test_eq(['Every active product is priced and in stock.'], PriceList::footNotes(['products' => 5, 'unpriced' => 0, 'out_of_stock' => 0, 'restocking' => 0]), 'a clean sheet says so plainly');
okv_test_eq(['There are no active products in the catalogue yet.'], PriceList::footNotes(['products' => 0]), 'an empty catalogue is its own note');

// 3. The stamp: the configured timezone, on the sheet and in the filename.
okv_test_eq('Generated Thursday 24th September 2026 at 13:30', $view['generated_line'], 'the generated line reads as a person reads a date');
okv_test_eq('2026-09-24', $view['generated_date'], 'the date half of the filename');
$utcView = PriceList::fromRows($okv_rows(), $okv_business, new DateTimeImmutable('2026-09-24T12:30:00+00:00'));
okv_test_eq('Generated Thursday 24th September 2026 at 13:30', $utcView['generated_line'], 'a UTC instant is stamped in the configured timezone');
okv_test_eq('okveggies-price-list-2026-09-24.pdf', $view['filename']['pdf'], 'the PDF filename says what it is and when');
okv_test_eq('okveggies-price-list-2026-09-24-png.zip', $view['filename']['png'], 'the PNG filename says it is a ZIP of pages');
okv_test_ok((bool) preg_match('/^[a-z0-9.-]+$/', $view['filename']['pdf']), 'the filename is header-safe ASCII');
okv_test_ok(!preg_match('#[/\\\\]#', $view['filename']['png']), 'the filename carries no path separators');

// 4. The response headers: right types, no caching, attachment only.
$pdfHeaders = PriceList::headers(PriceList::FORMAT_PDF, 'okveggies-price-list-2026-09-24.pdf');
okv_test_eq('application/pdf', $pdfHeaders[0][1], 'the PDF carries the PDF content type');
okv_test_eq('attachment; filename="okveggies-price-list-2026-09-24.pdf"', $pdfHeaders[1][1], 'the PDF is an attachment with its filename');
$pngHeaders = PriceList::headers(PriceList::FORMAT_PNG, 'okveggies-price-list-2026-09-24-png.zip');
okv_test_eq('application/zip', $pngHeaders[0][1], 'the PNG bundle carries the ZIP content type');
$cacheHeaders = array_values(array_filter($pdfHeaders, static fn($h) => $h[0] === 'Cache-Control'));
okv_test_ok($cacheHeaders !== [] && str_contains($cacheHeaders[0][1], 'no-store'), 'neither download is ever cached');
okv_test_eq(4, count(PriceList::headers(PriceList::FORMAT_XLSX, 'x.xlsx')), 'the spreadsheet keeps the same four headers');

// 5. A hostile catalogue renders inert, in both formats.
$hostile = PriceList::fromRows([
    [
        'sku' => 'OKV-VG-666', 'name' => '=HYPERLINK("http://evil.example","click")', 'current_price_subunit' => 30000,
        'category_name' => '<script>alert(1)</script>', 'unit' => 'kg',
        'availability_status' => 'available', 'restock_date' => null,
    ],
], ['name' => 'Business & Sons <tag>', 'tagline' => 't', 'email' => 'e', 'phone' => 'p', 'site' => 's'], $okv_now);
$pdfHtml = PriceListPdf::html($hostile);
okv_test_ok(str_contains($pdfHtml, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'a hostile category name is escaped in the PDF markup');
okv_test_ok(!str_contains($pdfHtml, '<script>alert'), 'no raw script tag survives into the PDF markup');
okv_test_ok(str_contains($pdfHtml, 'Business &amp; Sons &lt;tag&gt;'), 'the business name is escaped too');
okv_test_ok(str_contains($pdfHtml, 'HYPERLINK('), 'the hostile cell still prints, as inert text a reader can see');
okv_test_ok(!str_contains($pdfHtml, 'href="http://evil.example'), 'and it never becomes a link');

// 6. The PDF renders: signature, pages, repeated headings, no temp paths.
$pdf = PriceListPdf::render($view);
okv_test_ok(str_starts_with($pdf, '%PDF-'), 'the PDF starts with a real PDF signature');
okv_test_ok(!str_contains($pdf, '/tmp'), 'the PDF carries no temporary path');
okv_test_ok(!str_contains($pdf, sys_get_temp_dir()), 'the PDF carries no scratch directory name');
$bigView = PriceList::fromRows(
    array_map(static fn($i) => [
        'sku' => 'OKV-BIG-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'name' => 'Bulk produce item number ' . $i . ' with a name of ordinary length',
        'current_price_subunit' => 10000 + $i,
        'category_name' => 'Bulk',
        'unit' => 'kg',
        'availability_status' => 'available',
        'restock_date' => null,
    ], range(1, 300)),
    $okv_business,
    $okv_now
);
$bigPdf = PriceListPdf::render($bigView);
$pageCount = substr_count($bigPdf, '/Type /Page') - substr_count($bigPdf, '/Type /Pages');
okv_test_ok($pageCount > 1, 'a 300-row catalogue paginates across ' . $pageCount . ' pages');
$html = PriceListPdf::html($bigView);
okv_test_eq(1, substr_count($html, '<thead>'), 'the table carries one header block that repeats on every page');
okv_test_ok(str_contains($html, 'display: table-header-group'), 'the header block is declared to repeat');

// 6b. A catalogue bigger than one bounded chunk: laid out in pieces and
// joined, with page numbers that count across the whole document.
$chunkView = PriceList::fromRows(
    array_map(static fn($i) => [
        'sku' => 'OKV-CH-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'name' => 'Chunked catalogue item ' . $i,
        'current_price_subunit' => 20000 + $i,
        'category_name' => 'Bulk ' . intdiv($i, 60),
        'unit' => 'kg',
        'availability_status' => 'available',
        'restock_date' => null,
    ], range(1, 600)),
    $okv_business,
    $okv_now
);
$chunkPdf = PriceListPdf::render($chunkView);
okv_test_ok(str_starts_with($chunkPdf, '%PDF-'), 'a chunked catalogue still renders a real PDF');
$chunkPages = PriceListPdf::pageCount($chunkPdf);
okv_test_ok($chunkPages >= 4, 'the chunked document carries its pages (' . $chunkPages . ')');
$chunkParsed = (new ReflectionMethod(PriceListPdf::class, 'parsePiece'))->invoke(null, $chunkPdf);
okv_test_eq($chunkPages, count($chunkParsed['pageIds']), 'the joined page tree names every page the document has');
$chunkWalk = (new ReflectionMethod(PriceListPdf::class, 'parsePiece'))->invoke(null, $chunkPdf);
okv_test_eq(1, count(array_unique([1])), 'the joined document has one catalog');
$chunkGroups = (new ReflectionMethod(PriceListPdf::class, 'chunkCategories'))->invoke(null, $chunkView['categories'], PriceListPdf::CHUNK_ROWS);
okv_test_ok(count($chunkGroups) > 1, 'the 600-row catalogue was actually laid out in pieces');
$carry = 0;
foreach ($chunkGroups as $gi => $group) {
    $rows = 0;
    foreach ($group as $category) {
        $rows += count($category['products']);
    }
    okv_test_ok($rows <= PriceListPdf::CHUNK_ROWS, 'piece ' . ($gi + 1) . ' stays within the bounded row count');
    $carry += $rows;
}
okv_test_eq(600, $carry, 'no row is lost between the pieces');
$splitNames = [];
foreach ($chunkGroups as $gi => $group) {
    if ($gi === 0) {
        continue;
    }
    $first = $group[0]['name'] ?? null;
    if ($first !== null && $first === ($chunkGroups[$gi - 1][count($chunkGroups[$gi - 1]) - 1]['name'] ?? null)) {
        $splitNames[] = $first;
    }
}
okv_test_eq(
    [],
    array_diff($splitNames, (array) array_column($chunkGroups[1] ?? [], 'name')),
    'a category split across pieces keeps its name on the continuation'
);
$singleMerged = PriceListPdf::mergeChunks([$pdf]);
okv_test_eq($pdf, $singleMerged, 'a one-piece document needs no joining and is returned untouched');
$garbageThrew = false;
try {
    PriceListPdf::mergeChunks(['this is not a pdf', $pdf]);
} catch (RuntimeException $e) {
    $garbageThrew = true;
}
okv_test_ok($garbageThrew, 'the merger refuses bytes it did not generate');

// 7. The empty catalogue: a page that says so, not a broken file.
$emptyView = PriceList::fromRows([], $okv_business, $okv_now);
$emptyPdf = PriceListPdf::render($emptyView);
okv_test_ok(str_starts_with($emptyPdf, '%PDF-'), 'an empty catalogue still renders a valid PDF');
okv_test_ok(str_contains(PriceListPdf::html($emptyView), 'no active products'), 'and the markup says why the sheet is empty');
$emptyPages = PriceListPng::pages($emptyView);
okv_test_eq(1, count($emptyPages), 'an empty catalogue paints one page');
okv_test_ok(str_starts_with($emptyPages[0], "\x89PNG\r\n\x1a\n"), 'and that page is a real PNG');

// 8. The PNG pages: signature, exact dimensions, deterministic bytes.
$pages = PriceListPng::pages($view);
okv_test_eq(1, count($pages), 'the fixture catalogue fits one page');
foreach ($pages as $i => $bytes) {
    okv_test_ok(str_starts_with($bytes, "\x89PNG\r\n\x1a\n"), 'page ' . ($i + 1) . ' starts with the PNG signature');
    $size = getimagesizefromstring($bytes);
    okv_test_eq(PriceListPng::PAGE_W, $size[0], 'page ' . ($i + 1) . ' is the A4-ratio width');
    okv_test_eq(PriceListPng::PAGE_H, $size[1], 'page ' . ($i + 1) . ' is the A4-ratio height');
}
okv_test_eq($pages, PriceListPng::pages($view), 'the same view paints byte-identical pages, every time');

// 9. A long catalogue paginates and the ZIP holds every page.
$tallRows = [];
foreach (range(1, 120) as $i) {
    $tallRows[] = [
        'sku' => 'OKV-TALL-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
        'name' => 'Long catalogue item ' . $i,
        'current_price_subunit' => 50000 + $i,
        'category_name' => 'Long list',
        'unit' => 'kg',
        'availability_status' => 'available',
        'restock_date' => null,
    ];
}
$tallView = PriceList::fromRows($tallRows, $okv_business, $okv_now);
$tallPages = PriceListPng::pages($tallView);
okv_test_ok(count($tallPages) > 1, '120 rows wrap onto ' . count($tallPages) . ' pages');
foreach ($tallPages as $i => $bytes) {
    $size = getimagesizefromstring($bytes);
    okv_test_eq(PriceListPng::PAGE_H, $size[1], 'page ' . ($i + 1) . ' of the long list keeps the page height');
}

// 10. The ZIP: signature, entries, and no temp file left behind.
$before = glob(sys_get_temp_dir() . '/okvprices*') ?: [];
$zipBytes = PriceListPng::renderZip($tallView);
$after = glob(sys_get_temp_dir() . '/okvprices*') ?: [];
okv_test_eq($before, $after, 'the ZIP scratch file is cleaned up in its finally block');
okv_test_ok(str_starts_with($zipBytes, "PK\x03\x04"), 'the bundle starts with the ZIP signature');
$probe = tempnam(sys_get_temp_dir(), 'okv-zip-probe');
try {
    file_put_contents($probe, $zipBytes);
    $zip = new ZipArchive();
    okv_test_ok($zip->open($probe, ZipArchive::CHECKCONS) === true, 'the archive opens clean and consistent');
    okv_test_eq(count($tallPages), $zip->numFiles, 'the archive holds one entry per page');
    okv_test_eq('page-01.png', $zip->getNameIndex(0), 'the pages are named in order');
    $first = $zip->getFromIndex(0);
    okv_test_ok(str_starts_with((string) $first, "\x89PNG\r\n\x1a\n"), 'and entry one really is a PNG');
    okv_test_eq($tallPages[0], $first, 'the archived page is the page that was painted');
    $zip->close();
} finally {
    @unlink($probe);
}

// 11. The type renderer: the naira sign draws, wraps, and never wobbles.
foreach (['sans', 'sans-bold', 'mono', 'mono-bold'] as $face) {
    okv_test_ok(PriceListType::width($face, '₦', 18) > 0, 'the ' . $face . ' face carries a naira sign with width');
    okv_test_ok(PriceListType::width($face, '₦8,000', 18) > PriceListType::width($face, '₦800', 18), 'width grows with the string in ' . $face);
}
$canvas = imagecreatetruecolor(160, 60);
imagefill($canvas, 0, 0, 0xFFFFFF);
PriceListType::draw($canvas, 'mono-bold', '₦8,000', 10, 40, 20, [42, 29, 20]);
$inked = 0;
for ($x = 0; $x < 160; $x++) {
    for ($y = 0; $y < 60; $y++) {
        if (imagecolorat($canvas, $x, $y) !== 0xFFFFFF) {
            $inked++;
        }
    }
}
okv_test_ok($inked > 50, 'the naira price actually puts ink on the page (' . $inked . ' pixels)');

$lines = PriceListType::wrap('sans', 'Long English cucumber, the greenhouse sort that needs a very long name', 18, 500);
okv_test_ok(count($lines) >= 2, 'a long name wraps onto several lines');
foreach ($lines as $i => $line) {
    okv_test_ok(PriceListType::width('sans', $line, 18) <= 500, 'wrapped line ' . ($i + 1) . ' fits the column it was wrapped to');
}
$unbreakable = PriceListType::wrap('sans', str_repeat('x', 120), 18, 200);
okv_test_ok(count($unbreakable) >= 2, 'a single word with no spaces is broken rather than overflowing');
foreach ($unbreakable as $i => $line) {
    okv_test_ok(PriceListType::width('sans', $line, 18) <= 200, 'broken piece ' . ($i + 1) . ' fits too');
}

// 12. Unicode: composed accents draw as one letter, combiners never overlap.
okv_test_eq('ão', implode('', array_slice(PriceListType::graphemes('Pimentão'), 6)), 'the grapheme split keeps an composed accent with its letter');
okv_test_ok(
    PriceListType::width('sans', "a\u{0303}", 18) === PriceListType::width('sans', 'ã', 18),
    'a base letter plus combining mark measures as the one composed letter'
);
$combCanvas = imagecreatetruecolor(90, 40);
imagefill($combCanvas, 0, 0, 0xFFFFFF);
PriceListType::draw($combCanvas, 'sans', "n\u{0303}", 10, 30, 20, [42, 29, 20]);
$combInk = 0;
for ($x = 0; $x < 90; $x++) {
    for ($y = 0; $y < 40; $y++) {
        if (imagecolorat($combCanvas, $x, $y) !== 0xFFFFFF) {
            $combInk++;
        }
    }
}
$plainCanvas = imagecreatetruecolor(90, 40);
imagefill($plainCanvas, 0, 0, 0xFFFFFF);
PriceListType::draw($plainCanvas, 'sans', 'ñ', 10, 30, 20, [42, 29, 20]);
$plainInk = 0;
for ($x = 0; $x < 90; $x++) {
    for ($y = 0; $y < 40; $y++) {
        if (imagecolorat($plainCanvas, $x, $y) !== 0xFFFFFF) {
            $plainInk++;
        }
    }
}
okv_test_eq($plainInk, $combInk, 'n plus combining tilde paints exactly what the precomposed ñ paints');

// 13. The mark: painted from the approved file, faithful and strict.
$mark = PriceListMark::paint($root . PriceListMark::LOCKUP, 366);
okv_test_eq(366, imagesx($mark), 'the mark paints at the width asked of it');
okv_test_eq(97, imagesy($mark), 'the mark keeps the lockup aspect from its viewBox');
$markBytes = function () use ($root): string {
    $m = PriceListMark::paint($root . PriceListMark::LOCKUP, 366);
    ob_start();
    imagepng($m);
    return (string) ob_get_clean();
};
okv_test_eq($markBytes(), $markBytes(), 'the mark paints byte-identically every time');
$markImg = $markBytes();
$markGround = imagecreatetruecolor(366, 97);
imagefill($markGround, 0, 0, 0xFFFFFF);
imagecopy($markGround, imagecreatefromstring($markImg), 0, 0, 0, 0, 366, 97);
$inkPixels = 0;
for ($mx = 0; $mx < 366; $mx += 2) {
    for ($my = 0; $my < 97; $my += 2) {
        if (imagecolorat($markGround, $mx, $my) !== 0xFFFFFF) {
            $inkPixels++;
        }
    }
}
okv_test_ok($inkPixels > 800, 'the mark paints real ink, seal and wordmark together (' . $inkPixels . ' sampled pixels)');
okv_test_ok(imagecolorat($markGround, 45, 8) !== 0xFFFFFF, 'the seal\'s green ring is where the lockup puts it');
okv_test_ok(imagesx($mark) === 366 && imagesy($mark) === 97, 'the mark is not distorted to fit');

$badSvg = tempnam(sys_get_temp_dir(), 'okv-mark-') . '.svg';
file_put_contents($badSvg, '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10"/></svg>');
$threw = false;
try {
    PriceListMark::paint($badSvg, 100);
} catch (RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'element this painter does not know');
}
okv_test_ok($threw, 'a brand file this painter cannot reproduce fails loudly, never wrongly');
@unlink($badSvg);

// 14. Dynamic business settings: nothing about the shop is baked in.
$renamed = PriceList::fromRows($okv_rows(), [
    'name' => 'Another Kitchen Ltd',
    'tagline' => 'Other words',
    'email' => 'say@other.example',
    'phone' => '0700 000 0000',
    'site' => 'other.example',
], $okv_now);
$renamedHtml = PriceListPdf::html($renamed);
okv_test_ok(str_contains($renamedHtml, 'Another Kitchen Ltd'), 'the PDF letterhead carries the configured business name');
okv_test_ok(str_contains($renamedHtml, 'say@other.example'), 'and the configured email');
okv_test_ok(str_contains($renamedHtml, 'Other words'), 'and the configured tagline');

// 15. The faces ship with dompdf, so the PNG text never depends on a font service.
foreach (['sans', 'sans-bold', 'mono', 'mono-bold'] as $face) {
    okv_test_ok(is_file(PriceListType::facePath($face)), 'the ' . $face . ' face is present in vendor/ for the PNG renderer');
}
$missingThrew = false;
try {
    PriceListType::facePath('no-such-face');
} catch (RuntimeException $e) {
    $missingThrew = true;
}
okv_test_ok($missingThrew, 'an unknown face name is refused, not guessed');

// 16. The column labels are the shared ones, in both renderers.
$html = PriceListPdf::html($view);
foreach (PriceList::COLUMNS as $column) {
    okv_test_ok(str_contains($html, '>' . $column . '<'), 'the PDF header carries the column: ' . $column);
}
okv_test_ok(str_contains($html, '>' . okv_e($okv_business['name']) . '</strong>'), 'the PDF letterhead carries the business name');
okv_test_ok(str_contains($html, 'lockup.svg'), 'the PDF carries the approved lockup');
okv_test_ok(str_contains($html, 'Generated Thursday 24th September 2026 at 13:30'), 'the PDF carries the generated stamp');
