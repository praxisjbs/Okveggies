<?php
/**
 * includes/classes/PriceListPng.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The price list as PNG pages, painted with GD and delivered as a
 * ZIP of A4-shaped images.
 *
 * Why GD and why paginated: GD is the one imaging extension the repository
 * already declares (PhpSpreadsheet has required it since the spreadsheet
 * export shipped), so the PNG download is exactly as available as the exports
 * that came before it. Pagination keeps memory bounded no matter how long the
 * catalogue grows: one 1240 by 1754 canvas (A4 at 150dpi) is painted page by
 * page, each page is encoded to its own PNG string immediately, and the only
 * thing that accumulates is a few hundred kilobytes of compressed pages. A
 * 2,000-row catalogue is roughly 45 pages and a few megabytes of canvas time,
 * never one unbounded strip.
 *
 * The pages break the same way the PDF does: categories never strand at a
 * foot, rows are never split, the column headings repeat on every page, and
 * the footer carries the generated stamp and the page number. Text is set by
 * PriceListType from the same DejaVu faces the PDF uses, and the mark is
 * painted by PriceListMark from the approved lockup file.
 *
 * The archive is written to a private temp file that is created, read and
 * deleted inside one try/finally, and its bytes are returned, so no temporary
 * path ever reaches a response.
 * -----------------------------------------------------------------------------
 */

final class PriceListPng
{
    /** A4 at 150dpi. The same page shape the PDF prints on. */
    public const DPI = 150.0;
    public const PX_PER_MM = self::DPI / 25.4;
    public const PAGE_W = 1240;
    public const PAGE_H = 1754;

    /** The page margin, 14mm, matching the PDF. */
    public const MARGIN = 84;

    /** The zone the footer lives in, below the content. */
    public const FOOTER_ZONE = 46;

    /** The mark is the full-colour lockup, printed at the PDF's 62mm width. */
    public const MARK_W = 366;
    public const MARK_W_SMALL = 236;

    /** Type sizes in device pixels. */
    public const SIZE_TITLE = 40;
    public const SIZE_ORG_NAME = 18;
    public const SIZE_ORG_LINE = 14;
    public const SIZE_STAMP = 16;
    public const SIZE_COLHEAD = 16;
    public const SIZE_GROUP = 19;
    public const SIZE_GROUP_COUNT = 14;
    public const SIZE_NAME = 18;
    public const SIZE_SKU = 13;
    public const SIZE_UNIT = 16;
    public const SIZE_PRICE = 18;
    public const SIZE_AVAIL = 15;
    public const SIZE_NOTE = 14;
    public const SIZE_FOOTER = 13;

    /** Row rhythm. */
    public const ROW_PAD = 13;
    public const NAME_LEADING = 25;
    public const COLHEAD_H = 40;
    public const GROUP_H = 44;

    /** Column x positions inside the page. */
    public const COL_SKU_X = self::MARGIN;
    public const COL_PRODUCT_X = 240;
    public const COL_UNIT_X = 730;
    public const AVAIL_WIDTH = 220;
    public const PRICE_RIGHT = self::PAGE_W - self::MARGIN - self::AVAIL_WIDTH - 26;
    public const AVAIL_RIGHT = self::PAGE_W - self::MARGIN;

    /** A wrapped name longer than this is capped, so no single row can eat a page. */
    public const MAX_NAME_LINES = 8;

    /** Colours, read once from the brand tokens. */
    private static function palette(): array
    {
        static $palette = null;
        if ($palette !== null) {
            return $palette;
        }
        $palette = [
            'forest'  => self::hex(Brand::FOREST),
            'tint'    => self::hex(Brand::FOREST_TINT),
            'gold'    => self::hex(Brand::GOLD),
            'ink'     => self::hex(Brand::INK),
            'muted'   => self::hex(Brand::INK_MUTED),
            'mist'    => self::hex(Brand::MIST),
            'tomato'  => self::hex(Brand::TOMATO),
            'foliage' => self::hex(Brand::FOLIAGE),
            'white'   => self::hex(Brand::WHITE),
        ];
        return $palette;
    }

    /** A brand token as the [r, g, b] triple the type renderer paints from. */
    private static function hex(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /** Pack a palette triple into GD's truecolor value for rect and line calls. */
    private static function pack(array $rgb): int
    {
        return ((int) $rgb[0] << 16) | ((int) $rgb[1] << 8) | (int) $rgb[2];
    }

    /**
     * The whole download: the paginated pages, zipped.
     * Returns the archive's bytes.
     */
    public static function renderZip(array $view): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The ZIP extension is not available on this server.');
        }

        // A private scratch file, read and removed before this returns. Never
        // under the web root, never with a guessable name, never left behind.
        $temp = tempnam(sys_get_temp_dir(), 'okvprices');
        if ($temp === false) {
            throw new RuntimeException('The server could not make room for the download.');
        }
        try {
            $zip = new ZipArchive();
            $opened = $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($opened !== true) {
                throw new RuntimeException('The PNG archive could not be opened for writing.');
            }
            $index = 0;
            foreach (self::eachPage($view) as $bytes) {
                $index++;
                $zip->addFromString('page-' . str_pad((string) $index, 2, '0', STR_PAD_LEFT) . '.png', $bytes);
            }
            $zip->setArchiveComment((string) $view['generated_line']);
            $zip->close();
            $bytes = (string) file_get_contents($temp);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
        return $bytes;
    }

    /**
     * The pages, each as PNG bytes, painted in order.
     *
     * @return array<int, string>
     */
    /** @return array<int, string> the PNG bytes of every page, in order */
    public static function pages(array $view): array
    {
        return iterator_to_array(self::eachPage($view));
    }

    /**
     * Paint the pages one at a time, so a long catalogue never holds more
     * than one finished page in memory at a time.
     */
    private static function eachPage(array $view): \Generator
    {
        $layout = self::paginate($view);
        $pageCount = max(1, count($layout));
        for ($number = 1; $number <= $pageCount; $number++) {
            yield self::paintPage($view, $layout[$number - 1] ?? [], $number, $pageCount);
        }
    }

    /**
     * Measure every row, then fill pages top to bottom. A category band never
     * strands at the foot of a page, and no row is ever split.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function paginate(array $view): array
    {
        $firstTop = self::MARGIN + self::pageHeadHeight(true);
        $laterTop = self::MARGIN + self::pageHeadHeight(false);
        $bottom = self::PAGE_H - self::MARGIN - self::FOOTER_ZONE;

        $pages = [];
        $y = $firstTop;
        $current = ['groups' => []];

        foreach ($view['categories'] as $category) {
            $products = [];
            foreach ($category['products'] as $product) {
                $products[] = self::measureRow($product);
            }
            if ($products === []) {
                continue;
            }

            $bandHeight = self::GROUP_H;
            $firstRow = $products[0]['height'];
            if ($y + $bandHeight + min($firstRow, $bottom - $y) > $bottom && $y > $firstTop) {
                $pages[] = $current;
                $current = ['groups' => []];
                $y = $laterTop;
            } elseif ($y + $bandHeight + $firstRow > $bottom) {
                // A category taller than a whole fresh page starts on its own
                // page and its rows flow on from there.
                if ($current['groups'] !== []) {
                    $pages[] = $current;
                    $current = ['groups' => []];
                }
                $y = $laterTop;
            }

            $current['groups'][] = ['name' => $category['name'], 'count' => count($products), 'products' => []];
            $groupIndex = count($current['groups']) - 1;
            $y += $bandHeight;
            foreach ($products as $row) {
                if ($y + $row['height'] > $bottom) {
                    $pages[] = $current;
                    $current = ['groups' => []];
                    // The category name repeats when its rows continue, so a
                    // page never shows unlabelled rows.
                    $current['groups'][] = ['name' => $category['name'], 'count' => count($products), 'products' => [], 'continued' => true];
                    $groupIndex = count($current['groups']) - 1;
                    $y = $laterTop + $bandHeight;
                }
                $current['groups'][$groupIndex]['products'][] = $row;
                $y += $row['height'];
            }
        }
        $pages[] = $current;
        return $pages;
    }

    /** Wrap and measure one product row. */
    private static function measureRow(array $product): array
    {
        $nameWidth = self::COL_UNIT_X - 24 - self::COL_PRODUCT_X;
        $lines = PriceListType::wrap('sans', $product['name'], self::SIZE_NAME, $nameWidth);
        if (count($lines) > self::MAX_NAME_LINES) {
            $lines = array_slice($lines, 0, self::MAX_NAME_LINES);
            $lines[self::MAX_NAME_LINES - 1] = mb_substr($lines[self::MAX_NAME_LINES - 1], 0, max(0, mb_strlen($lines[self::MAX_NAME_LINES - 1], 'UTF-8') - 1), 'UTF-8') . '...';
        }

        $availLines = [];
        if ($product['availability_key'] === 'out') {
            $availLines = PriceListType::wrap('sans-bold', $product['availability_label'], self::SIZE_AVAIL, self::AVAIL_WIDTH);
        } elseif ($product['availability_key'] === 'restocking') {
            // The label already carries the date when there is one.
            $availLines = PriceListType::wrap('sans', $product['availability_label'], self::SIZE_AVAIL, self::AVAIL_WIDTH);
        }

        $lineCount = max(count($lines), count($availLines), 1);
        return $product + [
            'name_lines'     => $lines,
            'avail_lines'    => $availLines,
            'height'         => self::ROW_PAD * 2 + $lineCount * self::NAME_LEADING,
        ];
    }

    /** The height of the letterhead zone: full on page one, running after. */
    private static function pageHeadHeight(bool $first): int
    {
        if ($first) {
            // mark, title, stamp, rule, column head.
            return (int) (self::MARK_W / 3.81) + self::SIZE_TITLE + self::SIZE_STAMP + 40 + self::COLHEAD_H + 8;
        }
        return (int) (self::MARK_W_SMALL / 3.81) + 26 + self::COLHEAD_H + 8;
    }

    /** Paint one page. */
    private static function paintPage(array $view, array $layout, int $number, int $pageCount): string
    {
        $palette = self::palette();
        $img = imagecreatetruecolor(self::PAGE_W, self::PAGE_H);
        imagefill($img, 0, 0, self::pack($palette['white']));
        imagealphablending($img, true);

        $first = $number === 1;
        self::paintHead($img, $view, $palette, $first);
        $y = self::MARGIN + self::pageHeadHeight($first) - self::COLHEAD_H - 8;
        $y = self::paintColumnHead($img, $palette, $y);

        foreach ($layout['groups'] ?? [] as $group) {
            $y = self::paintGroupBand($img, $group, $palette, $y);
            foreach ($group['products'] as $row) {
                $y = self::paintRow($img, $row, $palette, $y);
            }
        }

        self::paintFooter($img, $view, $palette, $number, $pageCount);

        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    /** Letterhead on page one, the running head afterwards. */
    private static function paintHead(object $img, array $view, array $palette, bool $first): void
    {
        $root = dirname(__DIR__, 2);
        $business = $view['business'];
        $generated = (string) $view['generated_line'];
        $right = self::PAGE_W - self::MARGIN;

        if ($first) {
            $mark = PriceListMark::paint($root . PriceListMark::LOCKUP, self::MARK_W);
            imagecopy($img, $mark, self::MARGIN, self::MARGIN - 6, 0, 0, imagesx($mark), imagesy($mark));

            $orgX = $right;
            PriceListType::drawRight($img, 'sans-bold', (string) $business['name'], $orgX, self::MARGIN + 26, self::SIZE_ORG_NAME, $palette['forest']);
            $lines = [
                (string) $business['tagline'],
                (string) $business['email'],
                (string) $business['phone'],
                (string) $business['site'],
            ];
            $y = self::MARGIN + 26 + self::SIZE_ORG_LINE + 10;
            foreach ($lines as $line) {
                if ($line !== '') {
                    PriceListType::drawRight($img, 'sans', $line, $orgX, $y, self::SIZE_ORG_LINE, $palette['muted']);
                    $y += self::SIZE_ORG_LINE + 8;
                }
            }

            $titleY = self::MARGIN - 6 + imagesy($mark) + self::SIZE_TITLE;
            PriceListType::draw($img, 'sans-bold', (string) $view['title'], self::MARGIN, $titleY, self::SIZE_TITLE, $palette['forest']);
            PriceListType::draw($img, 'sans', $generated, self::MARGIN, $titleY + self::SIZE_STAMP + 14, self::SIZE_STAMP, $palette['muted']);
            $ruleY = (int) ($titleY + self::SIZE_STAMP + 30);
            imagefilledrectangle($img, self::MARGIN, $ruleY, self::PAGE_W - self::MARGIN, $ruleY + 3, self::pack($palette['gold']));
        } else {
            $mark = PriceListMark::paint($root . PriceListMark::LOCKUP, self::MARK_W_SMALL);
            imagecopy($img, $mark, self::MARGIN, self::MARGIN - 10, 0, 0, imagesx($mark), imagesy($mark));
            $markBottom = self::MARGIN - 10 + imagesy($mark);
            PriceListType::drawRight($img, 'sans', (string) $view['title'], self::PAGE_W - self::MARGIN, self::MARGIN + 8, self::SIZE_GROUP, $palette['forest']);
            PriceListType::drawRight($img, 'sans', $generated, self::PAGE_W - self::MARGIN, self::MARGIN + 8 + self::SIZE_STAMP + 8, self::SIZE_STAMP - 2, $palette['muted']);
            imagefilledrectangle($img, self::MARGIN, $markBottom + 8, self::PAGE_W - self::MARGIN, $markBottom + 10, self::pack($palette['mist']));
        }
    }

    /** The column headings, repeated on every page. Returns the next y. */
    private static function paintColumnHead(object $img, array $palette, int $y): int
    {
        imagefilledrectangle($img, self::MARGIN, $y, self::PAGE_W - self::MARGIN, $y + self::COLHEAD_H, self::pack($palette['tint']));
        $baseline = $y + 27;
        PriceListType::draw($img, 'sans-bold', 'SKU', self::COL_SKU_X, $baseline, self::SIZE_COLHEAD - 2, $palette['forest']);
        PriceListType::draw($img, 'sans-bold', 'Product', self::COL_PRODUCT_X, $baseline, self::SIZE_COLHEAD - 2, $palette['forest']);
        PriceListType::draw($img, 'sans-bold', 'Unit', self::COL_UNIT_X, $baseline, self::SIZE_COLHEAD - 2, $palette['forest']);
        $labels = ['This week', 'Availability'];
        $rights = [self::PRICE_RIGHT, self::AVAIL_RIGHT];
        foreach ($labels as $i => $label) {
            PriceListType::drawRight($img, 'sans-bold', $label, $rights[$i], $baseline, self::SIZE_COLHEAD - 2, $palette['forest']);
        }
        imagefilledrectangle($img, self::MARGIN, $y + self::COLHEAD_H, self::PAGE_W - self::MARGIN, $y + self::COLHEAD_H + 2, self::pack($palette['gold']));
        return $y + self::COLHEAD_H + 2;
    }

    /** A category band. Returns the next y. */
    private static function paintGroupBand(object $img, array $group, array $palette, int $y): int
    {
        imagefilledrectangle($img, self::MARGIN, $y, self::PAGE_W - self::MARGIN, $y + self::GROUP_H - 4, self::pack($palette['tint']));
        $name = (string) $group['name'];
        if (!empty($group['continued'])) {
            $name .= ' (continued)';
        }
        PriceListType::draw($img, 'sans-bold', $name, self::MARGIN + 8, $y + 29, self::SIZE_GROUP, $palette['forest']);
        $count = (int) $group['count'];
        $countLabel = $count . ' ' . ($count === 1 ? 'product' : 'products');
        PriceListType::drawRight($img, 'sans', $countLabel, self::AVAIL_RIGHT, $y + 30, self::SIZE_GROUP_COUNT, $palette['muted']);
        imagefilledrectangle($img, self::MARGIN, $y + self::GROUP_H - 4, self::PAGE_W - self::MARGIN, $y + self::GROUP_H - 2, self::pack($palette['gold']));
        return $y + self::GROUP_H;
    }

    /** One product row. Returns the next y. */
    private static function paintRow(object $img, array $row, array $palette, int $y): int
    {
        $baseline = $y + self::ROW_PAD + 14;
        $lines = $row['name_lines'];

        PriceListType::draw($img, 'mono', (string) $row['sku'], self::COL_SKU_X, $baseline, self::SIZE_SKU, $palette['muted']);
        foreach ($lines as $i => $line) {
            PriceListType::draw($img, 'sans', $line, self::COL_PRODUCT_X, $baseline + $i * self::NAME_LEADING, self::SIZE_NAME, $palette['ink']);
        }
        PriceListType::draw($img, 'sans', (string) $row['unit'], self::COL_UNIT_X, $baseline, self::SIZE_UNIT, $palette['muted']);

        if ($row['price_label'] === PriceList::LABEL_NOT_PRICED) {
            PriceListType::drawRight($img, 'sans', PriceList::LABEL_NOT_PRICED, self::PRICE_RIGHT, $baseline, self::SIZE_AVAIL, $palette['muted']);
        } else {
            PriceListType::drawRight($img, 'mono-bold', (string) $row['price_label'], self::PRICE_RIGHT, $baseline, self::SIZE_PRICE, $palette['ink']);
        }

        if ($row['availability_key'] === 'out') {
            foreach ($row['avail_lines'] as $i => $line) {
                PriceListType::drawRight($img, 'sans-bold', $line, self::AVAIL_RIGHT, $baseline + $i * (self::SIZE_AVAIL + 8), self::SIZE_AVAIL, $palette['tomato']);
            }
        } elseif ($row['availability_key'] === 'restocking') {
            foreach ($row['avail_lines'] as $i => $line) {
                PriceListType::drawRight($img, 'sans', $line, self::AVAIL_RIGHT, $baseline + $i * (self::SIZE_AVAIL + 8), self::SIZE_AVAIL, $palette['foliage']);
            }
        }

        $bottom = $y + (int) $row['height'] - 1;
        imagefilledrectangle($img, self::MARGIN, $bottom, self::PAGE_W - self::MARGIN, $bottom, self::pack($palette['mist']));
        return $bottom + 1;
    }

    /** The generated stamp and the page number, on every page. */
    private static function paintFooter(object $img, array $view, array $palette, int $number, int $pageCount): void
    {
        $baseline = self::PAGE_H - self::MARGIN - self::FOOTER_ZONE + 26;
        PriceListType::draw($img, 'sans', (string) $view['generated_line'], self::MARGIN, $baseline, self::SIZE_FOOTER, $palette['muted']);
        $label = 'Page ' . $number . ' of ' . $pageCount;
        PriceListType::drawRight($img, 'sans', $label, self::PAGE_W - self::MARGIN, $baseline, self::SIZE_FOOTER, $palette['muted']);
    }
}
