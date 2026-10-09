<?php
/**
 * includes/classes/FinancialReport.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Reporting Dashboard's service. It brings together the two
 * sides of the books:
 *
 *   Revenue   Cash confirmed through the app, on the M11 definition
 *             (docs/M11_ANALYTICS_CONTRACT.md): credited payment transactions
 *             by the day they were paid, minus completed refunds by the day
 *             they completed. Reversed transactions never count.
 *   Expenses  The expenses table (PR1), split by kind into cost of goods
 *             (produce bought to resell) and operating, via Expenses::summarise.
 *
 * Gross margin is revenue minus cost of goods; net profit is revenue minus all
 * expenses. Every figure is integer kobo. The profit maths is pure and tested;
 * the queries are prepared and bind their date bounds, never assembling a range
 * from request text.
 * -----------------------------------------------------------------------------
 */

final class FinancialReport
{
    public const DEFAULT_PERIOD = '30';
    public const TREND_MONTHS   = 6;

    /** The periods the dashboard offers. '7','30','90' are day windows; 'month' is this calendar month. */
    public const PERIODS = ['7', '30', '90', 'month'];

    // ---- Pure helpers (no database; the unit tests exercise these) -----------

    /** Net profit: revenue minus every expense. Signed; a loss is negative. */
    public static function netProfit(int $revenueSubunit, int $expenseSubunit): int
    {
        return $revenueSubunit - $expenseSubunit;
    }

    /** Gross margin: revenue minus the cost of the goods resold. Signed. */
    public static function grossMargin(int $revenueSubunit, int $costOfGoodsSubunit): int
    {
        return $revenueSubunit - $costOfGoodsSubunit;
    }

    /**
     * The last $months calendar-month keys (YYYY-MM), oldest first, ending with
     * the month of $now. Day-safe: anchored to the first of each month.
     */
    public static function monthKeys(int $months, ?DateTimeImmutable $now = null): array
    {
        if ($months < 1) { $months = 1; }
        $base = ($now ?? new DateTimeImmutable('now'))->modify('first day of this month')->setTime(0, 0, 0);
        $keys = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $keys[] = $base->modify('-' . $i . ' months')->format('Y-m');
        }
        return $keys;
    }

    /**
     * Combine month to revenue and month to expense maps into an ordered series,
     * one row per key, with profit computed. A month missing from either map is
     * zero, never dropped, so the chart has no gaps.
     */
    public static function combineMonthly(array $revenueByMonth, array $expenseByMonth, array $monthKeys): array
    {
        $series = [];
        foreach ($monthKeys as $key) {
            $rev = (int) ($revenueByMonth[$key] ?? 0);
            $exp = (int) ($expenseByMonth[$key] ?? 0);
            $series[] = [
                'month'           => $key,
                'revenue_subunit' => $rev,
                'expense_subunit' => $exp,
                'profit_subunit'  => $rev - $exp,
            ];
        }
        return $series;
    }

    /** Validate a requested period, falling back to the default. */
    public static function normalisePeriod($period): string
    {
        $period = is_string($period) ? $period : (string) $period;
        return in_array($period, self::PERIODS, true) ? $period : self::DEFAULT_PERIOD;
    }

    /**
     * Inclusive start and exclusive end for a period, as datetime and date
     * strings. '7','30','90' end at tomorrow's midnight and reach back that many
     * days including today; 'month' covers the current calendar month.
     */
    public static function periodBounds($period, ?DateTimeImmutable $now = null): array
    {
        $period   = self::normalisePeriod($period);
        $localNow = $now ?? new DateTimeImmutable('now');
        $today    = $localNow->setTime(0, 0, 0);
        $end      = $today->modify('+1 day');

        if ($period === 'month') {
            $start = $today->modify('first day of this month');
            $label = $start->format('F Y');
        } else {
            $days  = (int) $period;
            $start = $today->modify('-' . ($days - 1) . ' days');
            $label = 'Last ' . $days . ' days';
        }

        return [
            'period'            => $period,
            'label'             => $label,
            'start'             => $start->format('Y-m-d H:i:s'),
            'end'               => $end->format('Y-m-d H:i:s'),
            'start_date'        => $start->format('Y-m-d'),
            'end_date_exclusive'=> $end->format('Y-m-d'),
        ];
    }

    // ---- Revenue (the M11 cash definition) -----------------------------------

    /** Net cash revenue between two datetimes: credited receipts minus completed refunds. */
    public static function revenueBetween(string $start, string $end): array
    {
        $receipts = (int) (Database::one(
            "SELECT COALESCE(SUM(requested_amount_subunit), 0) AS s
               FROM payment_transactions
              WHERE status IN ('success', 'part_refunded', 'refunded')
                AND paid_at >= :start AND paid_at < :end",
            [':start' => $start, ':end' => $end]
        )['s'] ?? 0);

        $refunds = (int) (Database::one(
            "SELECT COALESCE(SUM(amount_subunit), 0) AS s
               FROM refunds
              WHERE status = 'processed'
                AND refunded_at >= :start AND refunded_at < :end",
            [':start' => $start, ':end' => $end]
        )['s'] ?? 0);

        return ['gross_subunit' => $receipts, 'refund_subunit' => $refunds, 'net_subunit' => $receipts - $refunds];
    }

    /** Net cash revenue grouped by calendar month (YYYY-MM) between two datetimes. */
    public static function revenueByMonth(string $start, string $end): array
    {
        $map = [];
        foreach (Database::all(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS m, COALESCE(SUM(requested_amount_subunit), 0) AS s
               FROM payment_transactions
              WHERE status IN ('success', 'part_refunded', 'refunded')
                AND paid_at >= :start AND paid_at < :end
              GROUP BY m",
            [':start' => $start, ':end' => $end]
        ) as $r) {
            $map[(string) $r['m']] = (int) $r['s'];
        }
        foreach (Database::all(
            "SELECT DATE_FORMAT(refunded_at, '%Y-%m') AS m, COALESCE(SUM(amount_subunit), 0) AS s
               FROM refunds
              WHERE status = 'processed'
                AND refunded_at >= :start AND refunded_at < :end
              GROUP BY m",
            [':start' => $start, ':end' => $end]
        ) as $r) {
            $key = (string) $r['m'];
            $map[$key] = ($map[$key] ?? 0) - (int) $r['s'];
        }
        return $map;
    }

    // ---- Expenses ------------------------------------------------------------

    /** Live expense rows (for Expenses::summarise) between two dates. */
    public static function expenseRowsBetween(string $startDate, string $endDateExclusive): array
    {
        return Database::all(
            "SELECT e.amount_subunit, e.supplier_key, c.slug AS category_slug
               FROM expenses e
               JOIN expense_categories c ON c.id = e.category_id
              WHERE e.is_void = 0
                AND e.spent_on >= :start AND e.spent_on < :end",
            [':start' => $startDate, ':end' => $endDateExclusive]
        );
    }

    /** Live expense total grouped by calendar month between two dates. */
    public static function expenseByMonth(string $startDate, string $endDateExclusive): array
    {
        $map = [];
        foreach (Database::all(
            "SELECT DATE_FORMAT(spent_on, '%Y-%m') AS m, COALESCE(SUM(amount_subunit), 0) AS s
               FROM expenses
              WHERE is_void = 0
                AND spent_on >= :start AND spent_on < :end
              GROUP BY m",
            [':start' => $startDate, ':end' => $endDateExclusive]
        ) as $r) {
            $map[(string) $r['m']] = (int) $r['s'];
        }
        return $map;
    }

    /** Top suppliers by spend between two dates, grouped by the normalised key. */
    public static function topSuppliersBetween(string $startDate, string $endDateExclusive, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return Database::all(
            "SELECT MAX(supplier_name) AS supplier, SUM(amount_subunit) AS amount_subunit, COUNT(*) AS entries
               FROM expenses
              WHERE is_void = 0
                AND supplier_key IS NOT NULL AND supplier_key <> ''
                AND spent_on >= :start AND spent_on < :end
              GROUP BY supplier_key
              ORDER BY amount_subunit DESC
              LIMIT " . $limit,
            [':start' => $startDate, ':end' => $endDateExclusive]
        );
    }

    // ---- Orders side: receivables and top customers --------------------------

    /** Money customers still owe: the balance on every live order. */
    public static function outstandingReceivables(): int
    {
        $row = Database::one(
            "SELECT COALESCE(SUM(balance_due_subunit), 0) AS s FROM orders WHERE order_status <> 'cancelled'"
        );
        return (int) ($row['s'] ?? 0);
    }

    /** Top customers by order value between two datetimes. */
    public static function topCustomersBetween(string $start, string $end, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        return Database::all(
            "SELECT COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), 'Customer') AS customer,
                    SUM(o.order_total_subunit) AS amount_subunit, COUNT(*) AS orders
               FROM orders o
               LEFT JOIN users u ON u.id = o.user_id
              WHERE o.order_status <> 'cancelled'
                AND o.created_at >= :start AND o.created_at < :end
              GROUP BY o.user_id
              ORDER BY amount_subunit DESC
              LIMIT " . $limit,
            [':start' => $start, ':end' => $end]
        );
    }

    // ---- The assembled dashboard ---------------------------------------------

    /**
     * Everything the Reporting Dashboard draws, for one period. The KPI figures
     * and the tables are ready to print server-side; the series and the category
     * breakdown feed the charts. Pass a frozen $now in tests.
     */
    public static function dashboard($period = self::DEFAULT_PERIOD, ?DateTimeImmutable $now = null): array
    {
        $bounds = self::periodBounds($period, $now);

        $revenue = self::revenueBetween($bounds['start'], $bounds['end']);
        $expenseRows = self::expenseRowsBetween($bounds['start_date'], $bounds['end_date_exclusive']);
        $summary = Expenses::summarise(array_map(static fn(array $r): array => [
            'amount_subunit' => (int) $r['amount_subunit'],
            'category_slug'  => (string) $r['category_slug'],
            'supplier_key'   => (string) ($r['supplier_key'] ?? ''),
        ], $expenseRows));

        $net = (int) $revenue['net_subunit'];
        $expenseTotal = (int) $summary['total'];
        $cog = (int) $summary['cost_of_goods'];

        // The monthly trend for the chart.
        $monthKeys  = self::monthKeys(self::TREND_MONTHS, $now);
        $trendStart = $monthKeys[0] . '-01 00:00:00';
        $revByMonth = self::revenueByMonth($trendStart, $bounds['end']);
        $expByMonth = self::expenseByMonth($monthKeys[0] . '-01', $bounds['end_date_exclusive']);
        $series     = self::combineMonthly($revByMonth, $expByMonth, $monthKeys);

        // The category breakdown for the donut, in the taxonomy's own order.
        $categoryBreakdown = [];
        foreach (Expenses::categories() as $c) {
            $amount = (int) ($summary['by_category'][$c['slug']] ?? 0);
            if ($amount > 0) {
                $categoryBreakdown[] = [
                    'slug'           => $c['slug'],
                    'name'           => $c['name'],
                    'colour'         => $c['colour'],
                    'amount_subunit' => $amount,
                ];
            }
        }

        return [
            'period' => $bounds['period'],
            'label'  => $bounds['label'],
            'kpi' => [
                'revenue_subunit'       => $net,
                'expense_subunit'       => $expenseTotal,
                'profit_subunit'        => self::netProfit($net, $expenseTotal),
                'outstanding_subunit'   => self::outstandingReceivables(),
                'cost_of_goods_subunit' => $cog,
                'operating_subunit'     => (int) $summary['operating'],
                'gross_margin_subunit'  => self::grossMargin($net, $cog),
            ],
            'series'             => $series,
            'category_breakdown' => $categoryBreakdown,
            'top_suppliers'      => self::topSuppliersBetween($bounds['start_date'], $bounds['end_date_exclusive']),
            'top_customers'      => self::topCustomersBetween($bounds['start'], $bounds['end']),
            'top_products'       => AdminDashboard::topProducts($period === 'month' ? 30 : (int) $period, $now),
        ];
    }
}
