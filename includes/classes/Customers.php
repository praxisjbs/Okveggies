<?php
/**
 * includes/classes/Customers.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Staff-side customer reads: the searchable list of households and
 * businesses, and the one profile that gathers what every other module already
 * owns.
 *
 * This class reads. It never writes an order, a payment, a Kitchen Run or a
 * credit entry, because those modules own those writes along with their audit
 * and notification paths. Screens link out to them instead.
 *
 * Credit figures come from Credit, which calculates them from the signed
 * journal every time. Nothing here stores or recomputes a balance.
 * -----------------------------------------------------------------------------
 */

final class Customers
{
    public const PER_PAGE = 25;
    public const TYPES = ['household', 'business'];

    public const RECENT_ORDERS   = 10;
    public const RECENT_PAYMENTS = 10;
    public const RECENT_RUNS     = 5;
    public const RECENT_CREDIT   = 10;

    /** Normalise what came in on the query string. No trust, no surprises. */
    public static function normaliseFilters(array $input): array
    {
        $type = (string) ($input['type'] ?? '');
        return [
            'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 100),
            'type'   => in_array($type, self::TYPES, true) ? $type : '',
            'page'   => max(1, (int) ($input['page'] ?? 1)),
        ];
    }

    /**
     * One page of customers, households and businesses together. Staff accounts
     * are not customers and never appear here.
     *
     * @return array{customers: array<int, array<string, mixed>>, count: int, page: int, lastPage: int, search: string, type: string}
     */
    public static function listing(array $filters, int $page): array
    {
        $filters = self::normaliseFilters($filters + ['page' => $page]);
        $where   = ["u.user_type IN ('household', 'business')"];
        $params  = [];

        if ($filters['type'] !== '') {
            $where[] = 'u.user_type = :type';
            $params[':type'] = $filters['type'];
        }
        if ($filters['search'] !== '') {
            // One placeholder per position: this connection runs native
            // prepared statements, which refuse a repeated named placeholder.
            $where[] = '(TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) LIKE :search_name
                         OR u.email LIKE :search_email
                         OR u.phone LIKE :search_phone
                         OR bc.business_name LIKE :search_business)';
            $like = '%' . $filters['search'] . '%';
            $params[':search_name']     = $like;
            $params[':search_email']    = $like;
            $params[':search_phone']    = $like;
            $params[':search_business'] = $like;
        }
        $clause = 'WHERE ' . implode(' AND ', $where);

        $count = (int) (Database::one(
            'SELECT COUNT(*) AS total FROM users u
               LEFT JOIN business_customers bc ON bc.user_id = u.id ' . $clause,
            $params
        )['total'] ?? 0);

        $lastPage = max(1, (int) ceil($count / self::PER_PAGE));
        $page     = min($filters['page'], $lastPage);

        $customers = Database::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.user_type, u.status, u.created_at,
                    bc.id AS business_customer_id, bc.business_name, bc.business_type,
                    bc.credit_status, bc.credit_days, bc.credit_limit_subunit,
                    (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id) AS order_count,
                    (SELECT MAX(o.created_at) FROM orders o WHERE o.user_id = u.id) AS last_order_at
               FROM users u
               LEFT JOIN business_customers bc ON bc.user_id = u.id
               ' . $clause . '
              ORDER BY u.id DESC' . okv_limit_clause($page, self::PER_PAGE),
            $params
        );

        return [
            'customers' => $customers,
            'count'     => $count,
            'page'      => $page,
            'lastPage'  => $lastPage,
            'search'    => $filters['search'],
            'type'      => $filters['type'],
        ];
    }

    /** One customer account, with its business profile when it has one. */
    public static function find(int $userId): ?array
    {
        $customer = Database::one(
            'SELECT id, first_name, last_name, email, phone, user_type, status,
                    email_verified_at, last_login_at, created_at
               FROM users
              WHERE id = :id AND user_type IN (\'household\', \'business\')',
            [':id' => $userId]
        );
        if ($customer === null) { return null; }

        $customer['business'] = (string) $customer['user_type'] === 'business'
            ? Database::one(
                'SELECT id, business_name, business_type, registration_number, contact_person,
                        credit_requested, credit_status, credit_days, credit_limit_subunit, created_at
                   FROM business_customers WHERE user_id = :id',
                [':id' => $userId]
            )
            : null;
        return $customer;
    }

    /** Saved delivery addresses. Behind customers.addresses.view on every screen. */
    public static function addresses(int $userId): array
    {
        return Database::all(
            'SELECT id, label, recipient_name, recipient_phone, address_line_1, address_line_2,
                    city, state, landmark, is_default
               FROM customer_addresses WHERE user_id = :id ORDER BY is_default DESC, id',
            [':id' => $userId]
        );
    }

    /** The newest orders on this account, each one opening the admin order detail. */
    public static function orders(int $userId, int $limit = self::RECENT_ORDERS): array
    {
        return Database::all(
            'SELECT id, order_number, order_status, payment_status, payment_option,
                    order_total_subunit, balance_due_subunit, preferred_delivery_date, created_at
               FROM orders WHERE user_id = :id ORDER BY id DESC' . okv_limit_clause(1, max(1, $limit)),
            [':id' => $userId]
        );
    }

    /** The newest payments on this account. Behind payments.view. */
    public static function payments(int $userId, int $limit = self::RECENT_PAYMENTS): array
    {
        return Database::all(
            'SELECT p.id, p.payment_number, p.order_id, p.provider, p.payment_type, p.status,
                    p.expected_amount_subunit, p.paid_amount_subunit, p.refunded_amount_subunit,
                    p.confirmed_at, p.created_at, o.order_number
               FROM payments p
               LEFT JOIN orders o ON o.id = p.order_id
              WHERE p.user_id = :id ORDER BY p.id DESC' . okv_limit_clause(1, max(1, $limit)),
            [':id' => $userId]
        );
    }

    /** The newest Kitchen Runs on this account. Behind kitchen_runs.view. */
    public static function kitchenRuns(int $userId, int $limit = self::RECENT_RUNS): array
    {
        return Database::all(
            'SELECT id, request_number, status, input_mode, quoted_total_subunit,
                    preferred_delivery_date, converted_order_id, created_at
               FROM kitchen_run_requests WHERE user_id = :id ORDER BY id DESC' . okv_limit_clause(1, max(1, $limit)),
            [':id' => $userId]
        );
    }

    /** The newest credit journal entries for a business. Behind credit.view. */
    public static function creditEntries(int $businessCustomerId, int $limit = self::RECENT_CREDIT): array
    {
        return Database::all(
            'SELECT ct.id, ct.transaction_type, ct.amount_subunit, ct.due_date, ct.paid_at,
                    ct.status, ct.order_id, ct.payment_id, ct.created_at, o.order_number
               FROM credit_transactions ct
               LEFT JOIN orders o ON o.id = ct.order_id
              WHERE ct.business_customer_id = :id ORDER BY ct.id DESC' . okv_limit_clause(1, max(1, $limit)),
            [':id' => $businessCustomerId]
        );
    }

    /**
     * What this account adds up to: how many orders, how much has been paid,
     * and what is still owed across its orders. Read from the payment ledger,
     * the same figures the order screen and the invoice use.
     */
    public static function totals(int $userId): array
    {
        $orders = Database::one(
            'SELECT COUNT(*) AS order_count,
                    COALESCE(SUM(order_total_subunit), 0) AS ordered,
                    COALESCE(SUM(CASE WHEN order_status <> \'cancelled\' THEN balance_due_subunit ELSE 0 END), 0) AS outstanding
               FROM orders WHERE user_id = :id',
            [':id' => $userId]
        );
        $paid = Database::one(
            'SELECT COALESCE(SUM(paid_amount_subunit), 0) AS paid,
                    COALESCE(SUM(refunded_amount_subunit), 0) AS refunded
               FROM payments WHERE user_id = :id',
            [':id' => $userId]
        );
        return [
            'order_count'         => (int) ($orders['order_count'] ?? 0),
            'ordered_subunit'     => (int) ($orders['ordered'] ?? 0),
            'outstanding_subunit' => (int) ($orders['outstanding'] ?? 0),
            'paid_subunit'        => max(0, (int) ($paid['paid'] ?? 0) - (int) ($paid['refunded'] ?? 0)),
        ];
    }

    /** The words a screen uses for an account type. Households are never businesses. */
    public static function typeLabel(string $userType): string
    {
        return $userType === 'business' ? 'Business' : 'Household';
    }

    /** The customer's name, or the business name when there is one. */
    public static function displayName(array $customer): string
    {
        $person = trim(preg_replace('/\s+/', ' ', ((string) ($customer['first_name'] ?? '')) . ' ' . ((string) ($customer['last_name'] ?? ''))) ?? '');
        $business = trim((string) ($customer['business_name'] ?? ''));
        if ($business !== '') { return $business; }
        return $person !== '' ? $person : 'Customer ' . (int) ($customer['id'] ?? 0);
    }
}
