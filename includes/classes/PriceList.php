<?php
/**
 * includes/classes/PriceList.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The price list as one shared view model.
 *
 * The XLSX export meets the Manager's spreadsheet habit (PriceSheet). The PDF
 * and the PNG downloads are the same list dressed for handing out: to a chef
 * over WhatsApp, to a household that wants this week's prices on the fridge,
 * and to staff who want the whole catalogue on paper. Both renderers read this
 * one model, so the ordering, the labels, the stamps and the brand details can
 * never drift apart between the two files.
 *
 * Everything here comes from configuration or the database, never from a
 * literal: business details from Settings, units and categories from their
 * tables, prices through the one Money helper, availability from
 * product_availability, colours from Brand. fromRows() is the pure half, so
 * the tests can pin the policy without a database; build() is the half that
 * reads the live catalogue in the same order the spreadsheet and the pricing
 * screen use.
 *
 * Approved policy (Owner, 24 September 2026): every active product appears.
 * A product with no price shows "Not priced". An out-of-stock row says so.
 * A restocking row carries its date. The foot of the document counts what is
 * held back, so nothing is ever hidden by silence.
 * -----------------------------------------------------------------------------
 */

final class PriceList
{
    /** The same ceiling the spreadsheet applies. A price list is not a data dump. */
    public const MAX_ROWS = 2000;

    public const FORMAT_XLSX = 'xlsx';
    public const FORMAT_PDF  = 'pdf';
    public const FORMAT_PNG  = 'png';

    /** The columns, in order, for both rendered formats. */
    public const COLUMNS = ['SKU', 'Product', 'Unit', 'This week', 'Availability'];

    public const TITLE = 'Price list';

    /** Availability labels a row can carry beside an ordinary price. */
    public const LABEL_NOT_PRICED = 'Not priced';

    public static function build(): array
    {
        $rows = Database::all(
            'SELECT p.sku, p.name, p.current_price_subunit,
                    c.name AS category_name,
                    u.symbol AS unit,
                    a.availability_status, a.restock_date
               FROM products p
               JOIN product_categories c ON c.id = p.category_id
               JOIN units_of_measurement u ON u.id = p.unit_id
               LEFT JOIN product_availability a ON a.product_id = p.id
              WHERE p.is_active = 1
              ORDER BY c.sort_order, p.name
              LIMIT ' . self::MAX_ROWS
        );

        return self::fromRows($rows, self::business(), new DateTimeImmutable('now'));
    }

    /**
     * Business identity exactly as the invoices and receipts print it: the
     * name, tagline and support channels from Settings, the site host from
     * APP_URL, the phone through the Phone helper.
     */
    public static function business(): array
    {
        $site = preg_replace('#^https?://#', '', rtrim((string) APP_URL, '/'));
        return [
            'name'    => Settings::str('business_name', 'OK Veggies'),
            'tagline' => Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.'),
            'email'   => Settings::str('support_email', 'hello@okveggies.com.ng'),
            'phone'   => Phone::display(Settings::str('support_whatsapp_number', '2348000000000')),
            'site'    => $site,
        ];
    }

    /**
     * Assemble the view model from raw rows. $rows is the shape the build()
     * query returns; any caller with the same shape gets the same list, which
     * is the property the tests lean on. Grouping follows the order the rows
     * arrive in, so the sort stays in the one SQL order the pricing screen
     * and the spreadsheet already use.
     *
     * @param array $rows rows of sku, name, current_price_subunit, category_name,
     *                    unit, availability_status, restock_date
     * @param array $business name, tagline, email, phone, site
     * @param ?DateTimeImmutable $now the generated stamp, injectable for tests
     */
    public static function fromRows(array $rows, array $business, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now');
        $timezone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Lagos');
        $now = $now->setTimezone($timezone);

        $categories = [];
        $counts = ['products' => 0, 'priced' => 0, 'unpriced' => 0, 'out_of_stock' => 0, 'restocking' => 0];

        foreach ($rows as $row) {
            $categoryName = (string) $row['category_name'];
            if (!isset($categories[$categoryName])) {
                $categories[$categoryName] = ['name' => $categoryName, 'products' => []];
            }

            $price = (int) $row['current_price_subunit'];
            $status = (string) ($row['availability_status'] ?? '') ?: 'available';
            $restockDate = $row['restock_date'] ?? null;
            $availability = okv_availability($status, $restockDate !== null ? (string) $restockDate : null);

            if ($price > 0) {
                $counts['priced']++;
                $priceLabel = Money::format($price);
            } else {
                $counts['unpriced']++;
                $priceLabel = self::LABEL_NOT_PRICED;
            }
            if ($availability['key'] === 'out') {
                $counts['out_of_stock']++;
            } elseif ($availability['key'] === 'restocking') {
                $counts['restocking']++;
            }
            $counts['products']++;

            $categories[$categoryName]['products'][] = [
                'sku'                => (string) $row['sku'],
                'name'               => (string) $row['name'],
                'unit'               => (string) $row['unit'],
                'price_subunit'      => $price,
                'price_label'        => $priceLabel,
                'availability_key'   => $availability['key'],
                'availability_label' => (string) $availability['label'],
                'availability_note'  => (string) $availability['note'],
            ];
        }

        return [
            'title'          => self::TITLE,
            'generated_at'   => $now,
            'generated_line' => 'Generated ' . $now->format('l jS F Y \a\t H:i'),
            'generated_date' => $now->format('Y-m-d'),
            'business'       => $business,
            'columns'        => self::COLUMNS,
            'categories'     => array_values($categories),
            'counts'         => $counts,
            'notes'          => self::footNotes($counts),
            'filename'       => [
                self::FORMAT_PDF => self::filename($now, self::FORMAT_PDF),
                self::FORMAT_PNG => self::filename($now, self::FORMAT_PNG),
            ],
        ];
    }

    /**
     * The honest note a printed sheet owes the reader: what is on it, and what
     * was held back. The plain line when there is nothing to declare.
     *
     * @return array<int, string>
     */
    public static function footNotes(array $counts): array
    {
        if ((int) ($counts['products'] ?? 0) === 0) {
            return ['There are no active products in the catalogue yet.'];
        }
        $notes = [];
        $unpriced = (int) $counts['unpriced'];
        $out = (int) $counts['out_of_stock'];
        $restocking = (int) $counts['restocking'];

        if ($unpriced === 1) {
            $notes[] = '1 product is still without a price, so it cannot be sold yet.';
        } elseif ($unpriced > 1) {
            $notes[] = $unpriced . ' products are still without a price, so they cannot be sold yet.';
        }
        if ($out === 1) {
            $notes[] = '1 product is out of stock this week.';
        } elseif ($out > 1) {
            $notes[] = $out . ' products are out of stock this week.';
        }
        if ($restocking === 1) {
            $notes[] = '1 product is restocking, with its date on the row.';
        } elseif ($restocking > 1) {
            $notes[] = $restocking . ' products are restocking, with their dates on the rows.';
        }
        if ($notes === []) {
            $notes[] = 'Every active product is priced and in stock.';
        }
        return $notes;
    }

    /**
     * A filename that says what it is and when it was taken. ASCII slug and a
     * date only, so it is safe in every header, browser and unzipping tool.
     */
    public static function filename(DateTimeImmutable $generatedAt, string $format): string
    {
        $date = $generatedAt->format('Y-m-d');
        return match ($format) {
            self::FORMAT_PDF => 'okveggies-price-list-' . $date . '.pdf',
            self::FORMAT_PNG => 'okveggies-price-list-' . $date . '-png.zip',
            default          => 'okveggies-price-list-' . $date . '.xlsx',
        };
    }

    /**
     * The response headers for a download, in order. Returned as pairs rather
     * than sent, so a test can pin them and the controller stays a loop.
     * No caching: a price list a week old is a wrong price with a printout.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function headers(string $format, string $filename): array
    {
        $contentType = match ($format) {
            self::FORMAT_PDF => 'application/pdf',
            self::FORMAT_PNG => 'application/zip',
            self::FORMAT_XLSX => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/octet-stream',
        };
        return [
            ['Content-Type', $contentType],
            ['Content-Disposition', 'attachment; filename="' . $filename . '"'],
            ['Cache-Control', 'no-store, no-cache, must-revalidate'],
            ['Pragma', 'no-cache'],
        ];
    }
}
