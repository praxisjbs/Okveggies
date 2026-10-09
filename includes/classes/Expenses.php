<?php
/**
 * includes/classes/Expenses.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Expense module's domain class. Money out, recorded once,
 * against an enforced category.
 *
 * The category taxonomy lives here, in CATEGORIES, as the single source of
 * truth. Migration 071 seeds the same rows into expense_categories, and the
 * unit tests assert this constant's integrity, so the code, the database and
 * the tests can never drift apart.
 *
 * Every amount is integer kobo, through Money, never a float. An expense is
 * voided, never deleted, so the ledger is append-only like orders and payments.
 * Every write is a prepared statement; the category is validated on the server
 * before a row is written, because the frontend gate is UX only.
 * -----------------------------------------------------------------------------
 */

final class Expenses
{
    /**
     * The enforced category taxonomy, derived from the business's own ledger
     * and named in their own words. `kind` is cost_of_goods for produce bought
     * to resell, operating for everything else; the reporting dashboard reads
     * it to draw the gross-margin line. `colour` is a brand token name.
     */
    public const CATEGORIES = [
        'stock-purchase'        => ['id' => 1,  'name' => 'Stock Purchase',        'kind' => 'cost_of_goods', 'colour' => 'forest',  'sort' => 1],
        'transport-logistics'   => ['id' => 2,  'name' => 'Transport & Logistics', 'kind' => 'operating',     'colour' => 'foliage', 'sort' => 2],
        'fuel'                  => ['id' => 3,  'name' => 'Fuel',                  'kind' => 'operating',     'colour' => 'gold',    'sort' => 3],
        'vehicle-repairs'       => ['id' => 4,  'name' => 'Vehicle & Repairs',     'kind' => 'operating',     'colour' => 'clay',    'sort' => 4],
        'airtime-data'          => ['id' => 5,  'name' => 'Airtime & Data',        'kind' => 'operating',     'colour' => 'forest',  'sort' => 5],
        'bank-pos-charges'      => ['id' => 6,  'name' => 'Bank & POS Charges',    'kind' => 'operating',     'colour' => 'ink',     'sort' => 6],
        'staff-welfare'         => ['id' => 7,  'name' => 'Staff & Welfare',       'kind' => 'operating',     'colour' => 'foliage', 'sort' => 7],
        'government-levies'     => ['id' => 8,  'name' => 'Government & Levies',   'kind' => 'operating',     'colour' => 'tomato',  'sort' => 8],
        'professional-services' => ['id' => 9,  'name' => 'Professional Services', 'kind' => 'operating',     'colour' => 'clay',    'sort' => 9],
        'giving'                => ['id' => 10, 'name' => 'Giving',                'kind' => 'operating',     'colour' => 'gold',    'sort' => 10],
        'loan-repayment'        => ['id' => 11, 'name' => 'Loan Repayment',        'kind' => 'operating',     'colour' => 'ink',     'sort' => 11],
        'other'                 => ['id' => 12, 'name' => 'Other',                 'kind' => 'operating',     'colour' => 'tomato',  'sort' => 12],
    ];

    // ---- Pure helpers (no database; the unit tests exercise these) ----------

    /** The taxonomy as an ordered list, each row carrying its slug. */
    public static function categories(): array
    {
        $out = [];
        foreach (self::CATEGORIES as $slug => $row) {
            $out[] = ['slug' => $slug] + $row;
        }
        usort($out, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
        return $out;
    }

    /** One category row, or null if the slug is not in the enforced list. */
    public static function category(string $slug): ?array
    {
        return isset(self::CATEGORIES[$slug]) ? ['slug' => $slug] + self::CATEGORIES[$slug] : null;
    }

    /** Whether a slug is a real, enforced category. */
    public static function isCategory(string $slug): bool
    {
        return isset(self::CATEGORIES[$slug]);
    }

    /** The category id for a slug, or 0 if unknown. */
    public static function categoryId(string $slug): int
    {
        return (int) (self::CATEGORIES[$slug]['id'] ?? 0);
    }

    /** 'cost_of_goods' or 'operating', or null for an unknown slug. */
    public static function kindOf(string $slug): ?string
    {
        return self::CATEGORIES[$slug]['kind'] ?? null;
    }

    /** Whether a category is produce bought to resell. */
    public static function isCostOfGoods(string $slug): bool
    {
        return self::kindOf($slug) === 'cost_of_goods';
    }

    /**
     * Normalise a typed supplier name into a grouping key, so "Mile 12 ",
     * "mile 12" and "MILE 12" all roll up together in "spend by supplier".
     * Lowercased, trimmed, inner whitespace collapsed, surrounding punctuation
     * stripped. An empty or whitespace-only name yields ''.
     */
    public static function normaliseSupplier(?string $name): string
    {
        $clean = strtolower(trim((string) $name));
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = trim($clean, " \t\n\r\0\x0B.,;:-");
        return $clean ?? '';
    }

    /**
     * Pure aggregation over expense rows, each needing 'amount_subunit' and
     * 'category_slug' (and optionally 'supplier_key'). Returns signed integer
     * subunit totals: overall, split by kind, and grouped by category and
     * supplier. Unknown or missing slugs fall into 'other' so no money is lost
     * from a total. This is the maths the reporting dashboard builds on.
     */
    public static function summarise(array $rows): array
    {
        $total = 0;
        $cog   = 0;
        $op    = 0;
        $byCategory = [];
        $bySupplier = [];

        foreach ($rows as $row) {
            $amount = (int) ($row['amount_subunit'] ?? 0);
            $slug   = (string) ($row['category_slug'] ?? '');
            if (!self::isCategory($slug)) {
                $slug = 'other';
            }

            $total += $amount;
            if (self::isCostOfGoods($slug)) {
                $cog += $amount;
            } else {
                $op += $amount;
            }

            $byCategory[$slug] = ($byCategory[$slug] ?? 0) + $amount;

            $supplier = (string) ($row['supplier_key'] ?? '');
            if ($supplier !== '') {
                $bySupplier[$supplier] = ($bySupplier[$supplier] ?? 0) + $amount;
            }
        }

        arsort($byCategory);
        arsort($bySupplier);

        return [
            'total'         => $total,
            'cost_of_goods' => $cog,
            'operating'     => $op,
            'by_category'   => $byCategory,
            'by_supplier'   => $bySupplier,
        ];
    }

    /** A date string is valid only if it is a real calendar date in Y-m-d. */
    public static function isValidDate(string $date): bool
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $d !== false && $d->format('Y-m-d') === $date;
    }

    /** A friendly sentence for a refusal code, for the controller to return. */
    public static function message(string $code): string
    {
        return [
            'invalid_category' => 'Choose a category for this expense.',
            'invalid_amount'   => 'Enter an amount greater than zero.',
            'invalid_date'     => 'Enter a valid date.',
            'not_found'        => 'That expense was not found.',
            'already_void'     => 'That expense is already voided.',
        ][$code] ?? 'We could not save that expense. Please try again.';
    }

    // ---- Database methods ----------------------------------------------------

    /**
     * Record one expense. Validates the enforced category, a positive amount
     * and a real date, then writes a prepared insert. Returns the new id and
     * the stored subunit amount. Throws DomainException with a code the
     * controller maps to a 422.
     */
    public static function create(array $input, ?int $staffId = null): array
    {
        $slug = (string) ($input['category_slug'] ?? '');
        if (!self::isCategory($slug)) {
            throw new DomainException('invalid_category');
        }

        $spentOn = trim((string) ($input['spent_on'] ?? ''));
        if ($spentOn === '') {
            $spentOn = date('Y-m-d');
        }
        if (!self::isValidDate($spentOn)) {
            throw new DomainException('invalid_date');
        }

        $amountSubunit = Money::toSubunit($input['amount'] ?? 0);
        if ($amountSubunit <= 0) {
            throw new DomainException('invalid_amount');
        }

        $supplierName = self::trimOrNull($input['supplier_name'] ?? null, 120);
        $supplierKey  = $supplierName === null ? null : self::nullIfEmpty(self::normaliseSupplier($supplierName));
        $description  = self::trimOrNull($input['description'] ?? null, 255);

        $quantity = null;
        if (isset($input['quantity']) && $input['quantity'] !== '' && is_numeric($input['quantity'])) {
            $quantity = (float) $input['quantity'];
        }
        $unitCostSubunit = null;
        if (isset($input['unit_cost']) && $input['unit_cost'] !== '') {
            $unitCostSubunit = Money::toSubunit($input['unit_cost']);
        }

        $source = (string) ($input['source'] ?? 'manual');
        if ($source !== 'migration') {
            $source = 'manual';
        }
        $externalRef = self::trimOrNull($input['external_ref'] ?? null, 80);

        Database::run(
            'INSERT INTO expenses
                (spent_on, category_id, supplier_name, supplier_key, description,
                 quantity, unit_cost_subunit, amount_subunit, source, external_ref, created_by)
             VALUES
                (:spent_on, :category_id, :supplier_name, :supplier_key, :description,
                 :quantity, :unit_cost, :amount, :source, :external_ref, :created_by)',
            [
                ':spent_on'      => $spentOn,
                ':category_id'   => self::categoryId($slug),
                ':supplier_name' => $supplierName,
                ':supplier_key'  => $supplierKey,
                ':description'   => $description,
                ':quantity'      => $quantity,
                ':unit_cost'     => $unitCostSubunit,
                ':amount'        => $amountSubunit,
                ':source'        => $source,
                ':external_ref'  => $externalRef,
                ':created_by'    => $staffId,
            ]
        );

        return [
            'id'             => (int) Database::getInstance()->getConnection()->lastInsertId(),
            'amount_subunit' => $amountSubunit,
            'category_slug'  => $slug,
        ];
    }

    /**
     * Recent live expenses, newest first, joined to their category. Optional
     * filters: 'month' (Y-m), 'category_slug', 'supplier_key', 'limit', and
     * 'include_void'. Every value is bound; nothing is interpolated into SQL.
     */
    public static function listRecent(array $filters = []): array
    {
        $where  = [];
        $params = [];

        if (empty($filters['include_void'])) {
            $where[] = 'e.is_void = 0';
        }

        if (!empty($filters['month']) && preg_match('/^\d{4}-\d{2}$/', (string) $filters['month'])) {
            $start = $filters['month'] . '-01';
            $first = DateTimeImmutable::createFromFormat('!Y-m-d', $start);
            if ($first !== false) {
                $next = $first->modify('first day of next month')->format('Y-m-d');
                $where[]            = 'e.spent_on >= :from AND e.spent_on < :to';
                $params[':from']    = $first->format('Y-m-d');
                $params[':to']      = $next;
            }
        }

        if (!empty($filters['category_slug']) && self::isCategory((string) $filters['category_slug'])) {
            $where[]               = 'e.category_id = :category_id';
            $params[':category_id'] = self::categoryId((string) $filters['category_slug']);
        }

        if (!empty($filters['supplier_key'])) {
            $where[]                = 'e.supplier_key = :supplier_key';
            $params[':supplier_key'] = self::normaliseSupplier((string) $filters['supplier_key']);
        }

        $limit = (int) ($filters['limit'] ?? 100);
        if ($limit < 1)    { $limit = 1; }
        if ($limit > 500)  { $limit = 500; }

        $sql = 'SELECT e.id, e.spent_on, e.supplier_name, e.description, e.quantity,
                       e.unit_cost_subunit, e.amount_subunit, e.is_void, e.created_at,
                       c.slug AS category_slug, c.name AS category_name,
                       c.kind AS category_kind, c.colour_token AS category_colour
                  FROM expenses e
                  JOIN expense_categories c ON c.id = e.category_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.spent_on DESC, e.id DESC LIMIT ' . $limit;

        return Database::all($sql, $params);
    }

    /** Void an expense (reverse, never delete). Idempotent on an already-void row. */
    public static function void(int $id, int $staffId, string $reason = ''): void
    {
        $row = Database::one('SELECT id, is_void FROM expenses WHERE id = :id', [':id' => $id]);
        if ($row === null) {
            throw new DomainException('not_found');
        }
        if ((int) $row['is_void'] === 1) {
            throw new DomainException('already_void');
        }
        Database::run(
            'UPDATE expenses
                SET is_void = 1, voided_at = NOW(), voided_by = :by, void_reason = :reason
              WHERE id = :id AND is_void = 0',
            [':by' => $staffId, ':reason' => self::trimOrNull($reason, 255), ':id' => $id]
        );
    }

    /** The active categories as the database holds them, for the UI to render. */
    public static function categoriesFromDb(): array
    {
        return Database::all(
            'SELECT id, slug, name, kind, colour_token, sort_order
               FROM expense_categories
              WHERE is_active = 1
              ORDER BY sort_order ASC'
        );
    }

    /** Supplier names already used, for the entry sheet's typeahead. */
    public static function supplierSuggestions(string $query, int $limit = 8): array
    {
        $key = self::normaliseSupplier($query);
        if ($key === '') {
            return [];
        }
        if ($limit < 1)  { $limit = 1; }
        if ($limit > 20) { $limit = 20; }

        $rows = Database::all(
            'SELECT supplier_name, COUNT(*) AS uses
               FROM expenses
              WHERE supplier_key LIKE :key AND supplier_name IS NOT NULL
              GROUP BY supplier_name
              ORDER BY uses DESC, supplier_name ASC
              LIMIT ' . $limit,
            [':key' => $key . '%']
        );
        return array_values(array_map(static fn(array $r): string => (string) $r['supplier_name'], $rows));
    }

    // ---- Small internals -----------------------------------------------------

    /** Trim a value to a cap, returning null when it is empty. */
    private static function trimOrNull($value, int $max): ?string
    {
        $clean = trim((string) ($value ?? ''));
        if ($clean === '') {
            return null;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, $max);
        }
        return substr($clean, 0, $max);
    }

    /** Null for an empty string, the string otherwise. */
    private static function nullIfEmpty(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
