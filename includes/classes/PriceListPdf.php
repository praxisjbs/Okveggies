<?php
/**
 * includes/classes/PriceListPdf.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The price list as a PDF, printed by the dompdf the repository
 * already ships for invoices and receipts.
 *
 * The markup is plain blocks and tables, never flex or grid, because dompdf
 * reads neither, which is the same rule the printed order documents follow.
 * The table headings repeat on every page (thead is table-header-group), rows
 * refuse to split across a page break, and the page numbers are stamped by the
 * canvas after layout so they are always right. Colours come from Brand, the
 * faces are the DejaVu set dompdf bundles, which carry the naira sign, and
 * every dynamic value goes through okv_e before it touches the markup.
 *
 * dompdf holds every laid-out row in memory until the document is written, so
 * one catalogue of thousands of rows would exhaust the PHP memory limit. A
 * catalogue bigger than CHUNK_ROWS is therefore laid out in bounded chunks,
 * each a small PDF on its own, and the chunks are joined at the PDF object
 * level by mergeChunks(): same page size, same faces, and page numbers that
 * count across the whole document, because the footer is stamped once every
 * chunk's page count is known. A catalogue within one chunk never goes near
 * the merger, so the everyday path stays exactly as simple as it looks.
 *
 * render() returns bytes: there is no temporary file to clean up and no path
 * to leak.
 * -----------------------------------------------------------------------------
 */

use Dompdf\Dompdf;
use Dompdf\Options;

final class PriceListPdf
{
    /** A4 portrait, the same geometry the order documents print on. */
    public const PAGE = 'A4';

    /** Page margins in millimetres. The bottom margin holds the footer. */
    public const MARGIN_MM = 14;
    public const MARGIN_BOTTOM_MM = 20;

    /** A4 in PostScript points, the units page_text draws in. */
    public const PAGE_WIDTH_PT = 595.28;
    public const PAGE_HEIGHT_PT = 841.89;

    /**
     * The rows one dompdf layout may take. A chunk of this size peaks well
     * under half of a 128M memory limit, leaving room for the PNG painter,
     * which may run after it in the same request, so even a host on the
     * default limit renders any catalogue, one bounded piece at a time.
     */
    public const CHUNK_ROWS = 200;

    public static function render(array $view): string
    {
        $chunks = self::chunkCategories($view['categories'], self::CHUNK_ROWS);
        if (count($chunks) <= 1) {
            // The everyday case: everything fits in one bounded layout, and
            // no piece ever needs joining. An empty catalogue lands here too.
            $single = $view;
            $single['categories'] = $chunks[0] ?? [];
            return self::renderChunk($single, null);
        }

        // Pass one: lay every chunk out to learn its page count, because the
        // footer has to say "Page 14 of 40" for the whole document and the
        // count is only known once each piece has been laid out. Pass two
        // lays them out again with the real numbers, and the pieces are
        // joined. Twice the layout of a rare, very large catalogue is the
        // price of bounded memory and honest page numbers.
        $counts = [];
        foreach ($chunks as $index => $categories) {
            $chunkView = $view;
            $chunkView['categories'] = $categories;
            $chunkView['continued_categories'] = self::continuedNames($chunks, $index);
            $chunkPdf = self::renderChunk($chunkView, null);
            $counts[$index] = self::pageCount($chunkPdf);
            unset($chunkView, $chunkPdf);
        }
        $total = array_sum($counts);

        $pieces = [];
        $before = 0;
        foreach ($chunks as $index => $categories) {
            $chunkView = $view;
            $chunkView['categories'] = $categories;
            $chunkView['continued_categories'] = self::continuedNames($chunks, $index);
            $pieces[] = self::renderChunk($chunkView, [$before + 1, $total]);
            $before += $counts[$index];
        }
        return self::mergeChunks($pieces);
    }

    /**
     * Group categories into chunks of at most $maxRows rows. A category that
     * fits goes whole; a single category longer than the chunk is split, and
     * the split is marked so the continuation prints its name again.
     *
     * @param array $categories the view model's categories
     * @return array<int, array<int, array<string, mixed>>>
     */
    private static function chunkCategories(array $categories, int $maxRows): array
    {
        $chunks = [];
        $current = [];
        $used = 0;
        foreach ($categories as $category) {
            $total = count($category['products']);
            $emitted = 0;
            $firstPart = true;
            while ($emitted < $total) {
                if ($used >= $maxRows) {
                    $chunks[] = $current;
                    $current = [];
                    $used = 0;
                }
                $take = array_slice($category['products'], $emitted, $maxRows - $used);
                $emitted += count($take);
                $part = $category;
                $part['products'] = $take;
                $part['continued'] = !$firstPart;
                $firstPart = false;
                $current[] = $part;
                $used += count($take);
            }
        }
        if ($current !== []) {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /** The category names a chunk continues from the one before it. */
    private static function continuedNames(array $chunks, int $index): array
    {
        if ($index === 0) {
            return [];
        }
        $names = [];
        foreach ($chunks[$index] as $category) {
            foreach ($chunks[$index - 1] as $previous) {
                if ($previous['name'] === $category['name']) {
                    $names[$category['name']] = true;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * Lay one chunk out. $pageNumbers is null for a self-contained document,
     * whose footer uses the canvas counters, or [firstPage, totalPages] for a
     * piece of a bigger one, whose footer is told its real numbers.
     */
    private static function renderChunk(array $view, ?array $pageNumbers): string
    {
        $root = dirname(__DIR__, 2);
        $options = new Options([
            'isRemoteEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'chroot' => $root,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($view), 'UTF-8');
        $dompdf->setPaper(self::PAGE, 'portrait');
        $dompdf->render();

        // The footer goes on after layout, on every page. The canvas measures
        // from the bottom left corner, and right alignment is computed from
        // the page label's own width because page_text draws raw text.
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $size = 7.5;
        $colour = [0x63, 0x6B, 0x67];
        $left = self::MARGIN_MM * 72 / 25.4;
        $right = self::PAGE_WIDTH_PT - $left;
        $baseline = self::PAGE_HEIGHT_PT - self::MARGIN_BOTTOM_MM * 72 / 25.4 + 14;
        $canvas->page_text($left, $baseline, (string) $view['generated_line'], $font, $size, $colour);
        if ($pageNumbers === null) {
            // A self-contained document lets the canvas counters do the work.
            $canvas->page_script(static function (int $pageNumber, int $pageCount, $pageCanvas, $pageFontMetrics) use ($right, $baseline, $font, $size, $colour): void {
                $label = 'Page ' . $pageNumber . ' of ' . $pageCount;
                $width = $pageFontMetrics->getTextWidth($label, $font, $size);
                $pageCanvas->text($right - $width, $baseline, $label, $font, $size, $colour);
            });
        } else {
            // A piece of a bigger document is told where it starts, so the
            // numbers it paints continue the document's count instead of
            // starting over inside the piece.
            [$firstPage, $totalPages] = $pageNumbers;
            $canvas->page_script(static function (int $pageNumber, int $pageCount, $pageCanvas, $pageFontMetrics) use ($right, $baseline, $font, $size, $colour, $firstPage, $totalPages): void {
                $label = 'Page ' . ($firstPage + $pageNumber - 1) . ' of ' . $totalPages;
                $width = $pageFontMetrics->getTextWidth($label, $font, $size);
                $pageCanvas->text($right - $width, $baseline, $label, $font, $size, $colour);
            });
        }

        return (string) $dompdf->output();
    }

    /** How many pages a rendered piece carries, from its page tree. */
    public static function pageCount(string $pdf): int
    {
        if (!preg_match('#/Type\s*/Pages(.)*?/Count\s+(\d+)#s', $pdf, $m)) {
            throw new RuntimeException('The rendered piece carries no page count.');
        }
        return (int) $m[2];
    }

    /**
     * Join the pieces a chunked render produced into one PDF.
     *
     * The pieces are all ours: dompdf output with a classic xref table, flat
     * numbered objects and no incremental updates. The merger reads each
     * piece's object table, renumbers every object, rewrites every reference
     * it renumbers, points every page at one new page tree, and moves the
     * resources each tree held down onto the pages, so the font subsets keep
     * resolving. Anything that is not the shape we generate is refused rather
     * than guessed at.
     *
     * @param array<int, string> $pieces rendered PDF bytes, in order
     */
    public static function mergeChunks(array $pieces): string
    {
        if (count($pieces) === 1) {
            return (string) $pieces[0];
        }

        $objects = [];   // new id => ['dict' => string, 'stream' => ?string]
        $pageIds = [];   // new ids of every page, in document order
        $catalog = null; // ['dict', 'newId', 'oldPagesId']
        $infoId = null;
        $mediaBox = null;

        foreach (array_values($pieces) as $pieceIndex => $piece) {
            $parsed = self::parsePiece($piece);
            $map = [];
            foreach ($parsed['objects'] as $oldId => $object) {
                if ($oldId === $parsed['pagesId']) {
                    continue; // the old page tree is replaced by one shared tree
                }
                $newId = count($objects) + 1;
                $map[$oldId] = $newId;
                $objects[$newId] = $object;
            }
            if ($pieceIndex === 0) {
                $catalog = [
                    'dict'       => $parsed['objects'][$parsed['rootId']]['dict'],
                    'newId'      => $map[$parsed['rootId']],
                    'oldPagesId' => $parsed['pagesId'],
                ];
                $mediaBox = self::extractMediaBox($parsed['pagesDict']);
                if ($parsed['infoId'] !== null && isset($map[$parsed['infoId']])) {
                    $infoId = $map[$parsed['infoId']];
                }
            }

            // The resources the piece's page tree held move onto each of its
            // pages, with their references renumbered.
            $resources = self::extractResources($parsed['pagesDict']);
            if ($resources !== null) {
                $resources = self::rewriteRefs($resources, $map, $parsed['pagesId']);
            }
            foreach ($parsed['pageIds'] as $oldPageId) {
                $newId = $map[$oldPageId];
                $pageIds[] = $newId;
                $dict = self::rewriteRefs($objects[$newId]['dict'], $map, $parsed['pagesId']);
                if ($resources !== null && !str_contains($dict, '/Resources')) {
                    $dict = preg_replace('#/Type\s*/Page\b#', '/Type /Page ' . $resources, $dict, 1);
                }
                $objects[$newId]['dict'] = $dict;
            }
            // Every other copied object's references are rewritten too.
            foreach ($map as $oldId => $newId) {
                if (!in_array($newId, $pageIds, true)) {
                    $objects[$newId]['dict'] = self::rewriteRefs($objects[$newId]['dict'], $map, $parsed['pagesId']);
                }
            }
        }

        if ($catalog === null || $pageIds === []) {
            throw new RuntimeException('The rendered pieces could not be joined.');
        }

        $pagesId = count($objects) + 1;
        // Wherever a piece pointed at its own page tree -- a page's /Parent
        // or the catalog's /Pages -- it now points at the one shared tree.
        // The catalog itself is already among the copied objects, rewritten.
        foreach ($objects as $id => $object) {
            $objects[$id]['dict'] = str_replace('@@SHARED_PAGES@@', $pagesId . ' 0 R', $object['dict']);
        }
        $kids = implode(' ', array_map(static fn($id) => $id . ' 0 R', $pageIds));
        $objects[$pagesId] = [
            'dict'   => '<< /Type /Pages /Kids [' . $kids . '] /Count ' . count($pageIds)
                . ($mediaBox !== null ? ' ' . $mediaBox : '') . ' >>',
            'stream' => null,
        ];

        return self::assemble($objects, $catalog['newId'], $infoId);
    }

    /**
     * Rewrite every "N 0 R" reference in a dictionary against the piece's
     * renumbering map. A reference to the dropped page tree becomes the
     * shared-tree placeholder; a reference to anything else that was not
     * copied is a piece we do not understand, and stops the merge.
     */
    private static function rewriteRefs(string $dict, array $map, int $oldPagesId): string
    {
        return (string) preg_replace_callback(
            '#(\d+)\s+0\s+R#',
            static function (array $m) use ($map, $oldPagesId): string {
                $old = (int) $m[1];
                if (isset($map[$old])) {
                    return $map[$old] . ' 0 R';
                }
                if ($old === $oldPagesId) {
                    return '@@SHARED_PAGES@@';
                }
                throw new RuntimeException('A piece to join refers to an object the merger did not copy: ' . $old);
            },
            $dict
        );
    }

    /** The /MediaBox a piece's page tree declared, as text. */
    private static function extractMediaBox(string $pagesDict): ?string
    {
        return preg_match('#/MediaBox\s*\[[^]]*]#', $pagesDict, $m) ? $m[0] : null;
    }

    /**
     * Read one dompdf piece: its objects, its catalog, its page tree and the
     * page ids in order. Only classic xref tables, which is all we generate.
     *
     * @return array{objects: array<int, array{dict: string, stream: ?string}>, rootId: int, pagesId: int, pagesDict: string, infoId: ?int, pageIds: array<int, int>}
     */
    private static function parsePiece(string $pdf): array
    {
        if (!str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('A piece to join is not a PDF.');
        }
        if (str_contains($pdf, '/Type /XRef') || str_contains($pdf, '/ObjStm')) {
            throw new RuntimeException('A piece to join uses a cross-reference stream, which the merger does not read.');
        }
        $startxref = strrpos($pdf, 'startxref');
        if ($startxref === false || !preg_match('#startxref\s+(\d+)#', substr($pdf, $startxref), $m)) {
            throw new RuntimeException('A piece to join carries no cross-reference offset.');
        }
        $xrefPos = (int) $m[1];
        if (!preg_match('#xref\s+(\d+)\s+(\d+)\s*#', substr($pdf, $xrefPos, 32), $head)) {
            throw new RuntimeException('A piece to join carries no classic cross-reference table.');
        }
        $first = (int) $head[1];
        $count = (int) $head[2];
        $tablePos = $xrefPos + strlen($head[0]);
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = substr($pdf, $tablePos + $i * 20, 20);
            if (strlen($entry) < 20 || $entry[0] === 'f') {
                continue;
            }
            if ($first + $i === 0) {
                // dompdf sometimes lists object 0 as live at offset zero;
                // object numbering starts at 1, so the entry is padding.
                continue;
            }
            $offsets[$first + $i] = (int) substr($entry, 0, 10);
        }
        if (!preg_match('#trailer\s*(<<.*?>>)#s', $pdf, $trailer)) {
            throw new RuntimeException('A piece to join carries no trailer.');
        }
        $trailerDict = $trailer[1];
        $readRef = static function (string $key) use ($trailerDict): ?int {
            return preg_match('#/' . $key . '\s+(\d+)\s+0\s+R#', $trailerDict, $m) ? (int) $m[1] : null;
        };
        $rootId = $readRef('Root');
        $infoId = $readRef('Info');
        if ($rootId === null || !isset($offsets[$rootId])) {
            throw new RuntimeException('A piece to join does not name its catalog.');
        }

        // Object extents from the offsets, ascending. The last object ends
        // where the cross-reference table begins.
        $order = array_keys($offsets);
        sort($order);
        $objects = [];
        $pagesId = null;
        $pageIds = [];
        $pagesDict = '';
        foreach ($order as $index => $oldId) {
            $from = $offsets[$oldId];
            $to = $index + 1 < count($order) ? $offsets[$order[$index + 1]] : $xrefPos;
            $raw = substr($pdf, $from, $to - $from);
            if (!preg_match('#^' . $oldId . '\s+0\s+obj#', $raw)) {
                throw new RuntimeException('A piece to join carries an object table that does not match its objects.');
            }
            $body = substr($raw, strlen((string) $oldId) + strlen(' 0 obj'));
            $streamPos = strpos($body, 'stream');
            if ($streamPos !== false && ($streamPos === 0 || $body[$streamPos - 1] !== 'd')) {
                // Everything before the keyword is the dictionary; the bytes
                // between it and the last endstream are the stream, kept
                // byte for byte.
                $dict = substr($body, 0, $streamPos);
                $bodyStart = $streamPos + strlen('stream');
                if (isset($body[$bodyStart]) && $body[$bodyStart] === "\r") {
                    $bodyStart++;
                }
                if (isset($body[$bodyStart]) && $body[$bodyStart] === "\n") {
                    $bodyStart++;
                }
                // The declared length is the truth about the data. dompdf
                // writes it as a direct integer; when it is there, the bytes
                // are copied exactly as declared, because the whitespace
                // around the endstream keyword must never be mistaken for
                // data. Without a declared length the data is what sits
                // before the keyword, with its one separating newline.
                $declared = preg_match('#/Length\s+(\d+)(?!\s+0\s+R)#', $dict, $lm)
                    ? (int) $lm[1]
                    : null;
                $endStream = strrpos($body, 'endstream');
                if ($endStream === false) {
                    throw new RuntimeException('A piece to join carries a stream that never ends.');
                }
                if ($declared !== null && $bodyStart + $declared <= strlen($body)) {
                    $stream = substr($body, $bodyStart, $declared);
                } else {
                    $stream = substr($body, $bodyStart, $endStream - $bodyStart);
                    if ($stream !== '' && ($stream[-1] === "\n" || $stream[-1] === "\r")) {
                        $stream = substr($stream, 0, -1);
                    }
                }
            } else {
                $endObj = strrpos($body, 'endobj');
                if ($endObj === false) {
                    throw new RuntimeException('A piece to join carries an object without an end.');
                }
                $dict = substr($body, 0, $endObj);
                $stream = null;
            }
            $objects[$oldId] = ['dict' => trim((string) $dict), 'stream' => $stream];

            if ($oldId === $rootId) {
                if (!preg_match('#/Pages\s+(\d+)\s+0\s+R#', $objects[$oldId]['dict'], $pm)) {
                    throw new RuntimeException('A piece to join carries a catalog without a page tree.');
                }
                $pagesId = (int) $pm[1];
            }
        }
        if ($pagesId === null || !isset($objects[$pagesId])) {
            throw new RuntimeException('A piece to join does not carry its page tree object.');
        }
        $pagesDict = $objects[$pagesId]['dict'];
        if (!preg_match('#/Kids\s*\[([^]]*)]#', $pagesDict, $kids)) {
            throw new RuntimeException('A piece to join carries a page tree without pages.');
        }
        if (!preg_match_all('#(\d+)\s+0\s+R#', (string) $kids[1], $refs)) {
            throw new RuntimeException('A piece to join carries a page tree whose pages were not named.');
        }
        foreach ($refs[1] as $ref) {
            $pageIds[] = (int) $ref;
        }
        foreach ($pageIds as $pageId) {
            if (!isset($objects[$pageId]) || !preg_match('#/Type\s*/Page\b#', $objects[$pageId]['dict'])) {
                throw new RuntimeException('A piece to join names something that is not a page.');
            }
        }
        return ['objects' => $objects, 'rootId' => $rootId, 'pagesId' => $pagesId, 'pagesDict' => $pagesDict, 'infoId' => $infoId, 'pageIds' => $pageIds];
    }

    /**
     * The /Resources dictionary a piece's page tree held, as text, so each
     * page it ruled can carry it after the trees are joined.
     */
    private static function extractResources(string $pagesDict): ?string
    {
        if (!preg_match('#/Resources\s*(\{\{|<<)#', $pagesDict, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $open = (int) $m[1][1];
        $openToken = $m[1][0];
        $closeToken = $openToken === '{{' ? '}}' : '>>';
        $depth = 1;
        $cursor = $open + strlen($openToken);
        while ($cursor < strlen($pagesDict) && $depth > 0) {
            $nextOpen = strpos($pagesDict, $openToken, $cursor);
            $nextClose = strpos($pagesDict, $closeToken, $cursor);
            if ($nextClose === false) {
                throw new RuntimeException('A piece to join carries an unbalanced resources dictionary.');
            }
            if ($nextOpen !== false && $nextOpen < $nextClose) {
                $depth++;
                $cursor = $nextOpen + strlen($openToken);
            } else {
                $depth--;
                if ($depth === 0) {
                    return '/Resources ' . substr($pagesDict, $open, $nextClose + strlen($closeToken) - $open);
                }
                $cursor = $nextClose + strlen($closeToken);
            }
        }
        throw new RuntimeException('A piece to join carries an unbalanced resources dictionary.');
    }

    /**
     * Write the joined document: objects in id order with their streams kept
     * byte for byte, a fresh cross-reference table built from where they now
     * sit, and a trailer. The file identity is the content's digest, so the
     * same list renders the same bytes.
     *
     * @param array<int, array{dict: string, stream: ?string}> $objects
     */
    private static function assemble(array $objects, int $rootId, ?int $infoId): string
    {
        ksort($objects);
        $body = "%PDF-1.7\n";
        $offsets = [];
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($body);
            if ($object['stream'] !== null) {
                $body .= $id . " 0 obj\n" . $object['dict'] . "\nstream\n"
                    . $object['stream'] . "\nendstream\nendobj\n";
            } else {
                $body .= $id . " 0 obj\n" . $object['dict'] . "\nendobj\n";
            }
        }
        $xrefPos = strlen($body);
        $size = count($objects) + 1;
        $body .= "xref\n0 " . $size . "\n";
        $body .= "0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $body .= str_pad((string) ($offsets[$id] ?? 0), 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $identity = substr(hash('md5', $body), 0, 32);
        $body .= "trailer\n<<\n/Size $size\n/Root $rootId 0 R\n";
        if ($infoId !== null) {
            $body .= "/Info $infoId 0 R\n";
        }
        $body .= "/ID[<$identity><$identity>]\n>>\nstartxref\n$xrefPos\n%%EOF\n";
        return $body;
    }

    /**
     * The document markup, separable from the PDF engine so tests can pin the
     * escaped content, the column labels and the brand reference without
     * rendering a page.
     */
    public static function html(array $view): string
    {
        $business = $view['business'];
        $markPath = okv_e(PriceListMark::LOCKUP);
        $name = okv_e($business['name']);
        $tagline = okv_e($business['tagline']);
        $email = okv_e($business['email']);
        $phone = okv_e($business['phone']);
        $site = okv_e($business['site']);
        $generated = okv_e($view['generated_line']);
        $title = okv_e($view['title']);
        $continuedNames = array_fill_keys((array) ($view['continued_categories'] ?? []), true);

        $tint = Brand::FOREST_TINT;
        $gold = Brand::GOLD;
        $forest = Brand::FOREST;
        $ink = Brand::INK;
        $muted = Brand::INK_MUTED;
        $mist = Brand::MIST;
        $tomato = Brand::TOMATO;
        $foliage = Brand::FOLIAGE;

        $rows = '';
        foreach ($view['categories'] as $category) {
            $count = count($category['products']);
            $label = okv_e($category['name']);
            if (isset($continuedNames[$category['name']])) {
                $label .= ' (continued)';
            }
            $rows .= '<tr class="group"><td colspan="5">' . $label
                . ' <span class="group-count">(' . $count . ' '
                . ($count === 1 ? 'product' : 'products') . ')</span>'
                . '</td></tr>';
            foreach ($category['products'] as $product) {
                $priceCell = $product['price_label'] === PriceList::LABEL_NOT_PRICED
                    ? '<span class="quiet">' . okv_e($product['price_label']) . '</span>'
                    : okv_e($product['price_label']);
                $availability = '';
                if ($product['availability_key'] === 'out') {
                    $availability = '<span class="alert">' . okv_e($product['availability_label']) . '</span>';
                } elseif ($product['availability_key'] === 'restocking') {
                    // The label already carries the date when there is one
                    // ("Restocking, back on Saturday 3rd October"), so the
                    // note is not printed twice.
                    $availability = '<span class="restock">' . okv_e($product['availability_label']) . '</span>';
                }
                $rows .= '<tr>'
                    . '<td class="sku">' . okv_e($product['sku']) . '</td>'
                    . '<td>' . okv_e($product['name']) . '</td>'
                    . '<td>' . okv_e($product['unit']) . '</td>'
                    . '<td class="figure">' . $priceCell . '</td>'
                    . '<td class="availability">' . $availability . '</td>'
                    . '</tr>';
            }
        }

        if ($rows === '') {
            $body = '<p class="empty">There are no active products in the catalogue yet, '
                . 'so there is nothing to price. Add a product first and download the list again.</p>';
        } else {
            $head = '';
            foreach (PriceList::COLUMNS as $column) {
                $align = in_array($column, ['This week', 'Availability'], true) ? ' class="figure-head"' : '';
                $head .= '<th' . $align . '>' . okv_e($column) . '</th>';
            }
            $notes = '';
            foreach ($view['notes'] as $note) {
                $notes .= '<p>' . okv_e($note) . '</p>';
            }
            $body = '<table class="prices"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>'
                . '<div class="notes">' . $notes . '</div>';
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}: {$name}</title>
<style>
  @page { margin: 14mm 14mm 20mm 14mm; }
  body { font-family: "DejaVu Sans"; font-size: 9pt; color: {$ink}; }
  .head { width: 100%; margin-bottom: 2mm; }
  .mark { width: 62mm; }
  .org { font-size: 8pt; text-align: right; color: {$muted}; line-height: 1.55; }
  .org strong { color: {$forest}; font-size: 10pt; }
  h1 { font-size: 19pt; color: {$forest}; margin: 4mm 0 1mm 0; }
  .stamp { font-size: 8.5pt; color: {$muted}; margin: 0 0 2mm 0; }
  .rule { border-bottom: 1.6pt solid {$gold}; margin: 0 0 4mm 0; }
  table.prices { width: 100%; border-collapse: collapse; }
  table.prices thead { display: table-header-group; }
  table.prices th { background: {$tint}; color: {$forest}; text-align: left;
    font-size: 8pt; padding: 2.2mm 2mm; border-bottom: 1pt solid {$gold}; }
  table.prices th.figure-head { text-align: right; }
  table.prices td { padding: 1.8mm 2mm; border-bottom: 0.3pt solid {$mist};
    vertical-align: top; }
  table.prices tr { page-break-inside: avoid; }
  table.prices td.sku { font-family: "DejaVu Sans Mono"; font-size: 7.5pt; color: {$muted};
    white-space: nowrap; }
  table.prices td.figure { font-family: "DejaVu Sans Mono"; text-align: right;
    white-space: nowrap; }
  table.prices td.figure .quiet { font-family: "DejaVu Sans"; }
  table.prices td.availability { text-align: right; font-size: 8pt; }
  tr.group td { background: {$tint}; color: {$forest}; font-weight: bold;
    font-size: 9pt; padding: 2mm; border-bottom: 0.6pt solid {$gold}; }
  tr.group td .group-count { font-weight: normal; font-size: 8pt; color: {$muted}; }
  .quiet { color: {$muted}; }
  .alert { color: {$tomato}; font-weight: bold; }
  .restock { color: {$foliage}; }
  .notes { margin-top: 6mm; font-size: 8pt; color: {$muted}; }
  .notes p { margin: 0 0 1mm 0; }
  .empty { margin-top: 8mm; color: {$muted}; }
</style>
</head>
<body>
  <table class="head"><tr>
    <td><img class="mark" src="{$markPath}" alt="{$name}"></td>
    <td class="org"><strong>{$name}</strong><br>
      {$tagline}<br>
      {$email}<br>
      {$phone}<br>
      {$site}</td>
  </tr></table>
  <h1>{$title}</h1>
  <p class="stamp">{$generated}</p>
  <div class="rule"></div>
  {$body}
</body>
</html>
HTML;
    }
}
