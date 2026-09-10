<?php
/**
 * Read-only operational and sales figures for the staff dashboard.
 *
 * This class knows no signed-in user and performs no permission checks. The
 * admin route decides which sections a staff member may request before calling
 * overview(), so a forbidden figure is not queried or returned. Metric meaning
 * is fixed in docs/M11_ANALYTICS_CONTRACT.md.
 */
final class AdminDashboard
{
    public const DEFAULT_PERIOD = 30;
    public const PERIODS = [7, 30, 90];
    public const TOP_LIMIT = 10;

    public const SECTIONS = [
        'orders_today',
        'revenue',
        'payments_due',
        'credit_outstanding',
        'sales_over_time',
        'top_products',
        'order_share',
    ];

    /** One fixed source for the category chart's labels and design tokens. */
    public const SHOPPING_GROUPS = [
        'vegetables' => ['label' => 'Vegetables', 'colour_token' => 'foliage'],
        'herbs-spices' => ['label' => 'Herbs & Spices', 'colour_token' => 'forest'],
        'tubers-roots' => ['label' => 'Tubers & Roots', 'colour_token' => 'gold'],
        'fruits' => ['label' => 'Fruits', 'colour_token' => 'tomato'],
        'grains-cereals' => ['label' => 'Grains & Cereals', 'colour_token' => 'clay'],
        'combos' => ['label' => 'Combos', 'colour_token' => 'ink'],
        'kitchen-runs' => ['label' => 'Kitchen Runs', 'colour_token' => 'gold.ink'],
    ];

    /**
     * Run only the explicitly requested sections.
     *
     * The returned shape is stable but deliberately sparse. A section not
     * requested is absent, which lets the route prove that RBAC was applied
     * before a sensitive database read.
     */
    public static function overview(array $sections, $period = self::DEFAULT_PERIOD, ?DateTimeImmutable $now = null): array
    {
        $wanted = array_fill_keys(array_values(array_intersect(self::SECTIONS, array_unique($sections))), true);
        $days = self::normalisePeriod($period);
        $bounds = self::periodBounds($days, $now);
        $result = [
            'period' => [
                'days' => $days,
                'start_date' => $bounds['start_date'],
                'end_date' => $bounds['end_date'],
            ],
            'summary' => [],
        ];

        if (isset($wanted['orders_today'])) {
            $result['summary']['orders_today'] = self::ordersToday($now);
        }
        if (isset($wanted['revenue'])) {
            $result['summary'] += self::revenueToday($now);
        }
        if (isset($wanted['payments_due'])) {
            $result['summary'] += self::paymentsDue($now);
        }
        if (isset($wanted['credit_outstanding'])) {
            $result['summary']['credit_outstanding_subunit'] = self::creditOutstanding($now);
        }
        if (isset($wanted['sales_over_time'])) {
            $sales = self::salesOverTime($days, $now);
            $result['sales_over_time'] = $sales['series'];
            $result['integrity']['undated_receipts_count'] = $sales['undated_receipts_count'];
        }

        if (isset($wanted['top_products']) || isset($wanted['order_share'])) {
            $analytics = self::orderAnalytics($bounds);
            if (isset($wanted['top_products'])) {
                $result['top_products'] = $analytics['top_products'];
            }
            if (isset($wanted['order_share'])) {
                $result['order_share'] = $analytics['order_share'];
                $result['uncategorised_subunit'] = $analytics['uncategorised_subunit'];
            }
            if ($analytics['unallocated_refund_subunit'] > 0) {
                $result['integrity']['unallocated_refund_subunit'] = $analytics['unallocated_refund_subunit'];
            }
        }

        return $result;
    }

    public static function normalisePeriod($period): int
    {
        if (is_array($period) || is_object($period)) {
            return self::DEFAULT_PERIOD;
        }
        $text = trim((string) $period);
        if (preg_match('/^\d+$/', $text) !== 1) {
            return self::DEFAULT_PERIOD;
        }
        $days = (int) $text;
        return in_array($days, self::PERIODS, true) ? $days : self::DEFAULT_PERIOD;
    }

    /** Inclusive local start and exclusive local end. */
    public static function periodBounds($period, ?DateTimeImmutable $now = null): array
    {
        $days = self::normalisePeriod($period);
        return self::boundsForDays($days, $now);
    }

    private static function boundsForDays(int $days, ?DateTimeImmutable $now = null): array
    {
        $zone = self::timezone();
        $localNow = ($now ?? new DateTimeImmutable('now', $zone))->setTimezone($zone);
        $today = $localNow->setTime(0, 0, 0);
        $start = $today->modify('-' . ($days - 1) . ' days');
        $end = $today->modify('+1 day');

        return [
            'days' => $days,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
            'today_start' => $today->format('Y-m-d H:i:s'),
            'tomorrow_start' => $end->format('Y-m-d H:i:s'),
        ];
    }

    public static function ordersToday(?DateTimeImmutable $now = null): int
    {
        $bounds = self::boundsForDays(1, $now);
        $row = Database::one(
            'SELECT COUNT(*) AS order_count
               FROM orders
              WHERE created_at >= :start_at
                AND created_at < :end_at
                AND order_status <> :cancelled',
            [':start_at' => $bounds['start'], ':end_at' => $bounds['end'], ':cancelled' => 'cancelled']
        );
        return (int) ($row['order_count'] ?? 0);
    }

    public static function revenueToday(?DateTimeImmutable $now = null): array
    {
        $cash = self::cashMovement(self::boundsForDays(1, $now));
        $today = $cash['series'][0] ?? ['gross_subunit' => 0, 'refund_subunit' => 0, 'amount_subunit' => 0];
        return [
            'revenue_subunit' => (int) $today['amount_subunit'],
            'revenue_gross_subunit' => (int) $today['gross_subunit'],
            'revenue_refund_subunit' => (int) $today['refund_subunit'],
            'undated_receipts_count' => $cash['undated_receipts_count'],
        ];
    }

    public static function paymentsDue(?DateTimeImmutable $now = null): array
    {
        $bounds = self::boundsForDays(1, $now);
        return self::summariseDuePayments(self::duePaymentObligations($now), $bounds['today_start']);
    }

    /**
     * The obligations behind the dashboard due total, oldest first.
     *
     * Payments uses this same read for its due-attention view, so the card and
     * destination cannot drift into different definitions of "due".
     */
    public static function duePaymentObligations(?DateTimeImmutable $now = null): array
    {
        $bounds = self::boundsForDays(1, $now);
        $rows = Database::all(
            'SELECT p.id AS payment_id, p.payment_number, p.payment_type,
                    p.expected_amount_subunit, p.paid_amount_subunit, p.due_at,
                    o.id AS order_id, o.order_number,
                    COALESCE(NULLIF(a.recipient_name, \'\'),
                        NULLIF(TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))), \'\'),
                        \'Customer\') AS customer_name
               FROM payments p
               JOIN orders o ON o.id = p.order_id
               LEFT JOIN users u ON u.id = o.user_id
               LEFT JOIN order_addresses a ON a.order_id = o.id
              WHERE o.order_status <> :cancelled
                AND p.status IN (:unpaid, :part_paid)
                AND p.expected_amount_subunit > p.paid_amount_subunit
                AND p.due_at IS NOT NULL
                AND p.due_at < :tomorrow_start
              ORDER BY p.due_at, p.id',
            [
                ':cancelled' => 'cancelled',
                ':unpaid' => Payments::STATUS_UNPAID,
                ':part_paid' => Payments::STATUS_PART_PAID,
                ':tomorrow_start' => $bounds['tomorrow_start'],
            ]
        );
        foreach ($rows as &$row) {
            $row['payment_id'] = (int) $row['payment_id'];
            $row['order_id'] = (int) $row['order_id'];
            $row['expected_amount_subunit'] = (int) $row['expected_amount_subunit'];
            $row['paid_amount_subunit'] = (int) $row['paid_amount_subunit'];
            $row['outstanding_subunit'] = Money::balance(
                $row['expected_amount_subunit'],
                $row['paid_amount_subunit']
            );
            $row['due_state'] = (string) $row['due_at'] < $bounds['today_start'] ? 'overdue' : 'due_today';
        }
        unset($row);
        return $rows;
    }

    public static function creditOutstanding(?DateTimeImmutable $now = null): int
    {
        $bounds = self::boundsForDays(1, $now);
        $businesses = Database::all(
            'SELECT id, credit_status, credit_limit_subunit
               FROM business_customers
              ORDER BY id'
        );
        if ($businesses === []) {
            return 0;
        }
        $entries = Database::all(
            'SELECT business_customer_id, amount_subunit, due_date
               FROM credit_transactions
              ORDER BY business_customer_id, (due_date IS NULL), due_date, id'
        );
        return self::sumCreditOutstanding($businesses, $entries, $bounds['start_date']);
    }

    public static function salesOverTime($period = self::DEFAULT_PERIOD, ?DateTimeImmutable $now = null): array
    {
        return self::cashMovement(self::periodBounds($period, $now));
    }

    public static function topProducts($period = self::DEFAULT_PERIOD, ?DateTimeImmutable $now = null): array
    {
        return self::orderAnalytics(self::periodBounds($period, $now))['top_products'];
    }

    public static function categoryShare($period = self::DEFAULT_PERIOD, ?DateTimeImmutable $now = null): array
    {
        $analytics = self::orderAnalytics(self::periodBounds($period, $now));
        return [
            'rows' => $analytics['order_share'],
            'uncategorised_subunit' => $analytics['uncategorised_subunit'],
            'unallocated_refund_subunit' => $analytics['unallocated_refund_subunit'],
        ];
    }

    /** Pure due-payment arithmetic for unit tests and the database projection. */
    public static function summariseDuePayments(array $rows, string $todayStart): array
    {
        $summary = [
            'payments_due_count' => 0,
            'payments_due_subunit' => 0,
            'payments_due_today_count' => 0,
            'payments_due_today_subunit' => 0,
            'payments_overdue_count' => 0,
            'payments_overdue_subunit' => 0,
        ];
        foreach ($rows as $row) {
            $amount = Money::balance(
                max(0, (int) ($row['expected_amount_subunit'] ?? 0)),
                max(0, (int) ($row['paid_amount_subunit'] ?? 0))
            );
            if ($amount < 1 || empty($row['due_at'])) {
                continue;
            }
            $summary['payments_due_count']++;
            $summary['payments_due_subunit'] += $amount;
            if ((string) $row['due_at'] < $todayStart) {
                $summary['payments_overdue_count']++;
                $summary['payments_overdue_subunit'] += $amount;
            } else {
                $summary['payments_due_today_count']++;
                $summary['payments_due_today_subunit'] += $amount;
            }
        }
        return $summary;
    }

    /** Reuse Credit's signed-journal walk after grouping 2 bounded reads. */
    public static function sumCreditOutstanding(array $businesses, array $entries, string $today): int
    {
        $byBusiness = [];
        foreach ($entries as $entry) {
            $byBusiness[(int) ($entry['business_customer_id'] ?? 0)][] = $entry;
        }
        $total = 0;
        foreach ($businesses as $business) {
            $id = (int) ($business['id'] ?? 0);
            $snapshot = Credit::snapshotFromTransactions($business, $byBusiness[$id] ?? [], $today);
            $total += (int) $snapshot['outstanding_subunit'];
        }
        return $total;
    }

    /** Fill every calendar day and preserve negative net cash days. */
    public static function denseSalesSeries(array $bounds, array $receipts, array $refunds): array
    {
        $grossByDay = [];
        foreach ($receipts as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day !== '') {
                $grossByDay[$day] = ($grossByDay[$day] ?? 0) + (int) ($row['amount_subunit'] ?? 0);
            }
        }
        $refundByDay = [];
        foreach ($refunds as $row) {
            $day = (string) ($row['day'] ?? '');
            if ($day !== '') {
                $refundByDay[$day] = ($refundByDay[$day] ?? 0) + (int) ($row['amount_subunit'] ?? 0);
            }
        }

        $zone = self::timezone();
        $cursor = new DateTimeImmutable((string) $bounds['start_date'], $zone);
        $last = new DateTimeImmutable((string) $bounds['end_date'], $zone);
        $series = [];
        while ($cursor <= $last) {
            $day = $cursor->format('Y-m-d');
            $gross = (int) ($grossByDay[$day] ?? 0);
            $refund = (int) ($refundByDay[$day] ?? 0);
            $series[] = [
                'date' => $day,
                'gross_subunit' => $gross,
                'refund_subunit' => $refund,
                'amount_subunit' => $gross - $refund,
            ];
            $cursor = $cursor->modify('+1 day');
        }
        return $series;
    }

    /**
     * Allocate a processed refund across immutable lines without floats.
     * The small floor remainder is assigned by ascending line id.
     */
    public static function allocateRefund(array $lines, int $refundSubunit): array
    {
        usort($lines, static fn(array $a, array $b): int => (int) ($a['id'] ?? 0) <=> (int) ($b['id'] ?? 0));
        $total = 0;
        foreach ($lines as $line) {
            $total += max(0, (int) ($line['line_total_subunit'] ?? 0));
        }
        $applied = min(max(0, $refundSubunit), $total);
        $allocated = 0;
        foreach ($lines as &$line) {
            $value = max(0, (int) ($line['line_total_subunit'] ?? 0));
            $share = $total > 0 ? intdiv($value * $applied, $total) : 0;
            $line['refund_subunit'] = $share;
            $line['net_subunit'] = $value - $share;
            $allocated += $share;
        }
        unset($line);

        $remainder = $applied - $allocated;
        foreach ($lines as &$line) {
            if ($remainder < 1) {
                break;
            }
            if ((int) ($line['line_total_subunit'] ?? 0) < 1) {
                continue;
            }
            $line['refund_subunit']++;
            $line['net_subunit']--;
            $remainder--;
        }
        unset($line);

        return [
            'lines' => $lines,
            'applied_subunit' => $applied,
            'unallocated_subunit' => max(0, $refundSubunit - $applied),
        ];
    }

    /** Pure aggregation shared by top products and category share. */
    public static function aggregateOrderLines(array $lines, array $refundsByOrder): array
    {
        $orders = [];
        foreach ($lines as $line) {
            $orders[(int) ($line['order_id'] ?? 0)][] = $line;
        }

        $top = [];
        $categoryTotals = [];
        $uncategorised = 0;
        $unallocated = 0;

        foreach ($orders as $orderId => $orderLines) {
            $allocation = self::allocateRefund($orderLines, (int) ($refundsByOrder[$orderId] ?? 0));
            $unallocated += $allocation['unallocated_subunit'];
            foreach ($allocation['lines'] as $line) {
                $net = max(0, (int) $line['net_subunit']);
                $identity = self::lineIdentity($line);
                if (!isset($top[$identity['key']])) {
                    $top[$identity['key']] = [
                        'key' => $identity['key'],
                        'kind' => $identity['kind'],
                        'label' => (string) ($line['item_name'] ?? ''),
                        'unit_name' => (string) ($line['unit_name'] ?? ''),
                        'quantity_milli' => 0,
                        'order_ids' => [],
                        'amount_subunit' => 0,
                        'latest_at' => '',
                    ];
                }
                $top[$identity['key']]['amount_subunit'] += $net;
                $top[$identity['key']]['quantity_milli'] += self::quantityMilli((string) ($line['quantity'] ?? '0'));
                $top[$identity['key']]['order_ids'][$orderId] = true;
                $createdAt = (string) ($line['created_at'] ?? '');
                if ($createdAt >= $top[$identity['key']]['latest_at']) {
                    $top[$identity['key']]['latest_at'] = $createdAt;
                    $top[$identity['key']]['label'] = (string) ($line['item_name'] ?? '');
                }

                $category = self::lineCategory($line);
                if ($category === null) {
                    $uncategorised += $net;
                } else {
                    $categoryTotals[$category] = ($categoryTotals[$category] ?? 0) + $net;
                }
            }
        }

        $topRows = [];
        foreach ($top as $row) {
            if ($row['amount_subunit'] < 1) {
                continue;
            }
            $topRows[] = [
                'key' => $row['key'],
                'kind' => $row['kind'],
                'label' => $row['label'],
                'unit_name' => $row['unit_name'],
                'quantity' => self::milliToQuantity($row['quantity_milli']),
                'order_count' => count($row['order_ids']),
                'amount_subunit' => $row['amount_subunit'],
            ];
        }
        usort($topRows, static function (array $a, array $b): int {
            return $b['amount_subunit'] <=> $a['amount_subunit']
                ?: strcasecmp($a['label'], $b['label'])
                ?: strcmp($a['key'], $b['key']);
        });

        return [
            'top_products' => array_slice($topRows, 0, self::TOP_LIMIT),
            'order_share' => self::categoryShares($categoryTotals),
            'uncategorised_subunit' => $uncategorised,
            'unallocated_refund_subunit' => $unallocated,
        ];
    }

    /** Convert positive category totals into exact integer basis points. */
    public static function categoryShares(array $totals): array
    {
        $positive = [];
        foreach (self::SHOPPING_GROUPS as $slug => $meta) {
            $value = max(0, (int) ($totals[$slug] ?? 0));
            if ($value > 0) {
                $positive[$slug] = $value;
            }
        }
        $total = Money::sum(array_values($positive));
        if ($total < 1) {
            return [];
        }

        $rows = [];
        $assigned = 0;
        foreach ($positive as $slug => $value) {
            $basisPoints = intdiv($value * 10000, $total);
            $assigned += $basisPoints;
            $rows[$slug] = [
                'category_slug' => $slug,
                'label' => self::SHOPPING_GROUPS[$slug]['label'],
                'colour_token' => self::SHOPPING_GROUPS[$slug]['colour_token'],
                'amount_subunit' => $value,
                'share_basis_points' => $basisPoints,
                '_remainder' => ($value * 10000) % $total,
            ];
        }

        $ranked = array_keys($rows);
        usort($ranked, static function (string $a, string $b) use ($rows): int {
            return $rows[$b]['_remainder'] <=> $rows[$a]['_remainder'] ?: strcmp($a, $b);
        });
        $left = 10000 - $assigned;
        for ($i = 0; $i < $left; $i++) {
            $rows[$ranked[$i % count($ranked)]]['share_basis_points']++;
        }

        $result = [];
        foreach (self::SHOPPING_GROUPS as $slug => $_meta) {
            if (!isset($rows[$slug])) {
                continue;
            }
            unset($rows[$slug]['_remainder']);
            $result[] = $rows[$slug];
        }
        return $result;
    }

    private static function cashMovement(array $bounds): array
    {
        $receipts = Database::all(
            'SELECT DATE(paid_at) AS day, COALESCE(SUM(requested_amount_subunit), 0) AS amount_subunit
               FROM payment_transactions
              WHERE status IN (:success, :part_refunded, :refunded)
                AND paid_at >= :start_at
                AND paid_at < :end_at
              GROUP BY DATE(paid_at)
              ORDER BY day',
            [
                ':success' => 'success',
                ':part_refunded' => 'part_refunded',
                ':refunded' => 'refunded',
                ':start_at' => $bounds['start'],
                ':end_at' => $bounds['end'],
            ]
        );
        $refunds = Database::all(
            'SELECT DATE(refunded_at) AS day, COALESCE(SUM(amount_subunit), 0) AS amount_subunit
               FROM refunds
              WHERE status = :processed
                AND refunded_at >= :start_at
                AND refunded_at < :end_at
              GROUP BY DATE(refunded_at)
              ORDER BY day',
            [
                ':processed' => Refunds::STATUS_PROCESSED,
                ':start_at' => $bounds['start'],
                ':end_at' => $bounds['end'],
            ]
        );
        $undated = Database::one(
            'SELECT COUNT(*) AS receipt_count
               FROM payment_transactions
              WHERE status IN (:success, :part_refunded, :refunded)
                AND paid_at IS NULL',
            [':success' => 'success', ':part_refunded' => 'part_refunded', ':refunded' => 'refunded']
        );
        return [
            'series' => self::denseSalesSeries($bounds, $receipts, $refunds),
            'undated_receipts_count' => (int) ($undated['receipt_count'] ?? 0),
        ];
    }

    private static function orderAnalytics(array $bounds): array
    {
        $lines = Database::all(
            'SELECT oi.id, oi.order_id, oi.item_type, oi.product_id, oi.combo_package_id,
                    oi.item_name, oi.unit_name, oi.quantity, oi.line_total_subunit, oi.created_at,
                    pc.slug AS category_slug,
                    EXISTS (
                        SELECT 1 FROM kitchen_run_requests kr
                         WHERE kr.converted_order_id = o.id
                    ) AS is_kitchen_run
               FROM order_items oi
               JOIN orders o ON o.id = oi.order_id
               LEFT JOIN products p ON p.id = oi.product_id
               LEFT JOIN product_categories pc ON pc.id = p.category_id
              WHERE o.created_at >= :start_at
                AND o.created_at < :end_at
                AND o.order_status <> :cancelled
              ORDER BY o.created_at, o.id, oi.id',
            [':start_at' => $bounds['start'], ':end_at' => $bounds['end'], ':cancelled' => 'cancelled']
        );
        $refundRows = Database::all(
            'SELECT r.order_id, COALESCE(SUM(r.amount_subunit), 0) AS amount_subunit
               FROM refunds r
               JOIN orders o ON o.id = r.order_id
              WHERE r.status = :processed
                AND o.created_at >= :start_at
                AND o.created_at < :end_at
                AND o.order_status <> :cancelled
              GROUP BY r.order_id',
            [
                ':processed' => Refunds::STATUS_PROCESSED,
                ':start_at' => $bounds['start'],
                ':end_at' => $bounds['end'],
                ':cancelled' => 'cancelled',
            ]
        );
        $refundsByOrder = [];
        foreach ($refundRows as $row) {
            $refundsByOrder[(int) $row['order_id']] = (int) $row['amount_subunit'];
        }
        return self::aggregateOrderLines($lines, $refundsByOrder);
    }

    private static function lineIdentity(array $line): array
    {
        $unit = self::normaliseText((string) ($line['unit_name'] ?? ''));
        if (!empty($line['is_kitchen_run'])) {
            return [
                'kind' => 'kitchen_run',
                'key' => 'kitchen-run:' . self::normaliseText((string) ($line['item_name'] ?? '')) . ':' . $unit,
            ];
        }
        if ((string) ($line['item_type'] ?? '') === 'combo') {
            $id = (int) ($line['combo_package_id'] ?? 0);
            return [
                'kind' => 'combo',
                'key' => 'combo:' . ($id > 0 ? (string) $id : self::normaliseText((string) ($line['item_name'] ?? ''))) . ':' . $unit,
            ];
        }
        $productId = (int) ($line['product_id'] ?? 0);
        if ($productId > 0) {
            return ['kind' => 'product', 'key' => 'product:' . $productId . ':' . $unit];
        }
        return [
            'kind' => 'manual',
            'key' => 'manual:' . self::normaliseText((string) ($line['item_name'] ?? '')) . ':' . $unit,
        ];
    }

    private static function lineCategory(array $line): ?string
    {
        if (!empty($line['is_kitchen_run'])) {
            return 'kitchen-runs';
        }
        if ((string) ($line['item_type'] ?? '') === 'combo') {
            return 'combos';
        }
        $slug = (string) ($line['category_slug'] ?? '');
        return isset(self::SHOPPING_GROUPS[$slug]) && !in_array($slug, ['combos', 'kitchen-runs'], true)
            ? $slug
            : null;
    }

    private static function quantityMilli(string $quantity): int
    {
        $text = trim($quantity);
        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $text, $matches) !== 1) {
            return 0;
        }
        return ((int) $matches[1] * 1000) + (int) str_pad($matches[2] ?? '', 3, '0');
    }

    private static function milliToQuantity(int $milli): string
    {
        $milli = max(0, $milli);
        return intdiv($milli, 1000) . '.' . str_pad((string) ($milli % 1000), 3, '0', STR_PAD_LEFT);
    }

    private static function normaliseText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        return preg_replace('/\s+/', ' ', $text) ?? $text;
    }

    private static function timezone(): DateTimeZone
    {
        $name = defined('APP_TIMEZONE') ? (string) APP_TIMEZONE : 'Africa/Lagos';
        try {
            return new DateTimeZone($name);
        } catch (Throwable $e) {
            return new DateTimeZone('Africa/Lagos');
        }
    }
}
