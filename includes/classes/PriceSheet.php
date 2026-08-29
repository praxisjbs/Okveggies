<?php
/**
 * includes/classes/PriceSheet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The price list as a spreadsheet, out and back in again. The
 * Manager already keeps prices in Excel, so this meets that habit rather than
 * fighting it. See docs/PRD.md Section 6.
 *
 * Out: an .xlsx with the current price list.
 * In:  .xlsx or .csv. An import is read and checked first and shows exactly what
 *      it would do. Nothing is written until that preview is confirmed, and then
 *      it applies in one transaction. A SKU we do not know is reported, never
 *      turned into a product: a price sheet has no business inventing catalogue
 *      entries with no name, unit or category. A row with an empty price is left
 *      alone, so a Manager can clear the rows they do not want to touch and an
 *      unpriced draft survives a round trip.
 *
 * Every price change still goes through Pricing::change(), so an import cannot
 * slip past the history.
 * -----------------------------------------------------------------------------
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class PriceSheet
{
    /** The columns, in order. SKU and price are the only ones import reads. */
    public const HEADERS = ['SKU', 'Product', 'Category', 'Unit', 'Current price', 'New price'];

    public const MAX_ROWS = 2000;

    /** Rows for the export, and the shape the preview reports against. */
    public static function currentPrices(): array
    {
        return Database::all(
            'SELECT p.sku, p.name, p.current_price_subunit,
                    c.name AS category_name, u.symbol AS unit
               FROM products p
               JOIN product_categories c ON c.id = p.category_id
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.is_active = 1
              ORDER BY c.sort_order, p.name'
        );
    }

    /**
     * Write the current price list to an .xlsx file and return its path.
     * "New price" is left empty on purpose: the Manager types into that column
     * and sends the same file back.
     */
    public static function export(string $path): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Prices');

        foreach (self::HEADERS as $i => $header) {
            $sheet->setCellValue([$i + 1, 1], $header);
        }
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()
              ->setFillType(Fill::FILL_SOLID)
              ->getStartColor()->setRGB('E7EEEA');
        $sheet->getStyle('A1:F1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        $row = 2;
        foreach (self::currentPrices() as $product) {
            $sheet->setCellValue([1, $row], $product['sku']);
            $sheet->setCellValue([2, $row], $product['name']);
            $sheet->setCellValue([3, $row], $product['category_name']);
            $sheet->setCellValue([4, $row], $product['unit']);
            $current = (int) $product['current_price_subunit'];
            $sheet->setCellValue([5, $row], $current > 0 ? Money::toNaira($current) : '');
            $row++;
        }

        $last = max(2, $row - 1);
        $sheet->getStyle('E2:F' . $last)->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (range('A', 'F') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return $path;
    }

    /** A filename that says what it is and when it was taken. */
    public static function exportFilename(): string
    {
        return 'okveggies-prices-' . date('Y-m-d') . '.xlsx';
    }

    /**
     * Read a sheet into rows of ['sku' => string, 'price' => string]. Accepts
     * .xlsx and .csv. Uses "New price" when that column carries a value, and
     * falls back to "Current price", so a sheet the Manager edited in place
     * works as well as one built from our export.
     */
    public static function read(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getActiveSheet();

        $rows = [];
        $skuColumn = null;
        $currentColumn = null;
        $newColumn = null;
        $lineNumber = 0;

        foreach ($sheet->toArray(null, true, false, false) as $cells) {
            $lineNumber++;

            if ($skuColumn === null) {
                foreach ($cells as $index => $value) {
                    $header = strtolower(trim((string) $value));
                    if ($header === 'sku') {
                        $skuColumn = $index;
                    } elseif ($header === 'current price') {
                        $currentColumn = $index;
                    } elseif ($header === 'new price') {
                        $newColumn = $index;
                    }
                }
                // A sheet with no header row: assume SKU then price.
                if ($skuColumn === null && $lineNumber === 1) {
                    $first = trim((string) ($cells[0] ?? ''));
                    if ($first !== '' && !is_numeric($first)) {
                        $skuColumn = 0;
                        $currentColumn = 1;
                    }
                }
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                break;
            }

            $sku = strtoupper(trim((string) ($cells[$skuColumn] ?? '')));
            if ($sku === '') {
                continue;
            }
            $new = $newColumn !== null ? trim((string) ($cells[$newColumn] ?? '')) : '';
            $current = $currentColumn !== null ? trim((string) ($cells[$currentColumn] ?? '')) : '';
            $rows[] = [
                'line'  => $lineNumber,
                'sku'   => $sku,
                'price' => $new !== '' ? $new : $current,
            ];
        }

        $book->disconnectWorksheets();
        return $rows;
    }

    /**
     * Check a sheet against the catalogue without writing anything.
     *
     * Returns:
     *   changes  rows that would move a price
     *   same     rows already at that price, which are left alone
     *   skipped  rows with an empty price, which say "do not touch this one"
     *   problems rows we cannot apply, each with a plain reason
     *
     * Any problem stops the whole import: it is all or nothing, so a half
     * applied price sheet never exists.
     */
    public static function preview(array $rows): array
    {
        $changes = [];
        $same = [];
        $skipped = [];
        $problems = [];
        $seen = [];

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $line = (int) $row['line'];

            if (isset($seen[$sku])) {
                $problems[] = ['line' => $line, 'sku' => $sku, 'reason' => 'This SKU appears more than once in the sheet.'];
                continue;
            }
            $seen[$sku] = true;

            $product = Database::one(
                'SELECT p.id, p.name, p.current_price_subunit, u.symbol AS unit
                   FROM products p
                   JOIN units_of_measurement u ON u.id = p.unit_id
                  WHERE p.sku = :sku',
                [':sku' => $sku]
            );
            if (!$product) {
                $problems[] = ['line' => $line, 'sku' => $sku, 'reason' => 'No product in the catalogue has this SKU.'];
                continue;
            }

            $raw = trim((string) $row['price']);
            if ($raw === '') {
                // An empty cell means leave this product's price where it is.
                $skipped[] = ['line' => $line, 'sku' => $sku, 'name' => $product['name']];
                continue;
            }
            if (!preg_match('/^[₦\s]*-?[0-9][0-9,\s]*(\.[0-9]+)?$/u', $raw)) {
                $problems[] = ['line' => $line, 'sku' => $sku, 'name' => $product['name'], 'reason' => 'That price is not a number we can read.'];
                continue;
            }

            $subunit = Money::toSubunit($raw);
            if (!Pricing::isValidPrice($subunit)) {
                $problems[] = [
                    'line' => $line, 'sku' => $sku, 'name' => $product['name'],
                    'reason' => $subunit < Pricing::MIN_PRICE_SUBUNIT
                        ? 'A price has to be at least ₦1.'
                        : 'That price is above the ceiling we allow.',
                ];
                continue;
            }

            $entry = [
                'line' => $line,
                'id'   => (int) $product['id'],
                'sku'  => $sku,
                'name' => $product['name'],
                'unit' => $product['unit'],
                'old'  => (int) $product['current_price_subunit'],
                'new'  => $subunit,
            ];
            if ($entry['old'] === $entry['new']) {
                $same[] = $entry;
            } else {
                $changes[] = $entry;
            }
        }

        return [
            'changes'  => $changes,
            'same'     => $same,
            'skipped'  => $skipped,
            'problems' => $problems,
            'ok'       => $problems === [],
        ];
    }

    /**
     * Apply a preview that came back clean. One transaction, every change
     * through Pricing::change(), reason stamped with the file it came from.
     *
     * Throws DomainException('has_problems') if handed a preview that is not ok.
     */
    public static function apply(array $preview, string $sourceName, ?int $userId): int
    {
        if (empty($preview['ok'])) {
            throw new DomainException('has_problems');
        }

        $reason = 'Imported from ' . mb_substr($sourceName, 0, 180);
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $applied = 0;
            foreach ($preview['changes'] as $change) {
                $result = Pricing::change((int) $change['id'], (int) $change['new'], $reason, $userId, false);
                if ($result['changed']) {
                    $applied++;
                }
            }
            $pdo->commit();
            return $applied;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
