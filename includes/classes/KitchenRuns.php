<?php
/**
 * includes/classes/KitchenRuns.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The Kitchen Run rules and the read path (PRD Section 8).
 *
 * A Kitchen Run is the original, highest-trust OK Veggies service, digitised: a
 * customer sends a list, we price it, they approve it, it becomes an ordinary
 * order. Many things on a kitchen list are not in our catalogue (pomo, meat,
 * oil), so nothing here requires a catalogue product, and the customer's own
 * words are kept exactly as they typed them.
 *
 * This file splits the same way Settings.php and SettingsEditor.php do:
 *
 *   KitchenRuns          the rules and the reads, which every screen calls.
 *   KitchenRunWorkflow   the only thing that changes a request from a request.
 *
 * Everything above the read section is pure: no database, no session, no clock
 * it did not receive as an argument. That is what makes the rules testable
 * without a database, and it is why the state map, the money maths, the upload
 * whitelist and the quote window all live here rather than inside a controller.
 *
 * One rule about this file is worth stating plainly, because the first attempt
 * at this milestone broke it: nothing in here exists only for a test to assert
 * against. Every public rule below is called by a real path. If you add one and
 * nothing calls it, the test that covers it is proving nothing.
 * -----------------------------------------------------------------------------
 */

final class KitchenRuns
{
    /**
     * How the list arrived (PRD 8.1). 'priced' is the fourth way in, a list
     * that already carries its own prices. It behaves exactly like 'mixed'
     * everywhere it is read, and it exists as its own mode so a report can say
     * "already priced" rather than inferring it from the pricing mode. Rows
     * written before it existed keep 'mixed', which is still a legal mode.
     */
    public const MODES = ['catalogue', 'custom', 'upload', 'mixed', 'priced'];

    /** Who put the prices on it (PRD 8.1). */
    public const PRICING_MODES = ['by_us', 'by_customer', 'already_priced'];

    public const STATUSES = ['submitted', 'quoted', 'approved', 'converted', 'declined', 'cancelled'];

    /**
     * What a converted run may be settled with. Pay on delivery is deliberately
     * absent: a Kitchen Run is produce bought to order against a list we do not
     * stock, so it is a deposit, the full amount, or approved credit.
     */
    public const PAYMENT_OPTIONS = ['deposit', 'pay_in_full', 'on_account'];

    public const UPLOAD_MIME = ['image/jpeg', 'image/png', 'application/pdf'];

    /** Matches Uploads::maxBytes() default. Kept here so allowedUpload() is pure. */
    public const UPLOAD_MAX_BYTES = 5242880;

    /** A kitchen list is long. It is not a thousand lines long. */
    public const MAX_LINES = 100;

    public const NOTE_MAX = 2000;

    /** The one legal state map. transition() is the only thing that reads it. */
    private const TRANSITIONS = [
        ['submitted', 'quoted'],
        ['submitted', 'declined'],
        ['submitted', 'cancelled'],
        ['quoted', 'quoted'],      // staff re-price a quote they got wrong
        ['quoted', 'approved'],
        ['quoted', 'declined'],
        ['quoted', 'cancelled'],
        ['quoted', 'submitted'],   // the quote expired, so it is open again
        ['approved', 'converted'],
        ['approved', 'cancelled'], // withdrawn before it is an order (PRD 8.3)
    ];

    // -------------------------------------------------------------------------
    // Pure rules. No database, no session, so they unit test without either.
    // Everything below this line is called by a real path; nothing here exists
    // only for a test to assert against.
    // -------------------------------------------------------------------------

    /** Whether the lifecycle allows this move at all. */
    public static function mayTransition(string $from, string $to): bool
    {
        return in_array([$from, $to], self::TRANSITIONS, true);
    }

    /**
     * Price a set of lines and total them. Every line must be complete by the
     * time it gets here: a name, a positive quantity and a price in kobo. The
     * returned lines carry their own line totals so nothing downstream has to
     * multiply money a second time.
     *
     * The optional fields are normalised here as well, and that is not tidying.
     * A browser posts every field it renders, so an empty hidden product_id
     * arrives as '' rather than as an absent key. Bound straight into a BIGINT
     * column, MySQL refuses it with "Incorrect integer value: ''", and a
     * colleague pricing an ordinary free-text list is told the request failed.
     * The tests never saw it because a curl caller simply omits the key. So the
     * one function that turns posted lines into storable lines is the place to
     * decide what an empty optional field means: nothing.
     */
    public static function quoteLines(array $lines): array
    {
        if (!$lines) {
            throw new DomainException('no_items');
        }
        if (count($lines) > self::MAX_LINES) {
            throw new DomainException('too_many_items');
        }

        $priced = [];
        $total  = 0;
        foreach ($lines as $line) {
            $name     = trim((string) ($line['item_name'] ?? ''));
            $quantity = self::quantity($line['quantity'] ?? null);
            $price    = self::nonNegativeInt($line['unit_price_subunit'] ?? null);
            if ($name === '' || $quantity === null || $price === null) {
                throw new DomainException('invalid_line');
            }

            $line['item_name']          = $name;
            $line['quantity']           = $quantity;
            $line['unit_price_subunit'] = $price;
            $line['line_total_subunit'] = Money::lineTotal($quantity, $price);
            $line['product_id']         = self::positiveInt($line['product_id'] ?? null);
            $line['unit_id']            = self::positiveInt($line['unit_id'] ?? null);
            $line['unit_label']         = self::shortNote($line['unit_label'] ?? null);
            $line['note']               = self::shortNote($line['note'] ?? null);

            $priced[] = $line;
            $total   += $line['line_total_subunit'];
        }

        return ['lines' => $priced, 'total_subunit' => $total];
    }

    /** A spend cap is a hard limit. No cap means no limit. */
    public static function withinCap(int $total, ?int $cap): bool
    {
        return $cap === null || $total <= $cap;
    }

    public static function remainingBalance(int $total, int $deposit): int
    {
        return Money::balance($total, $deposit);
    }

    /**
     * Whether a converted run may be settled this way, before the database is
     * touched. On account also needs approved credit, which is checked against
     * the database in convert(); this half is the pure rule.
     *
     * An open-budget run cannot be paid in full up front, because at the moment
     * of conversion nobody yet knows what "in full" will be: that is the whole
     * point of the mode (PRD 8.2). It takes a deposit, or it goes on account.
     */
    public static function paymentAllowed(string $option, string $customerType, bool $openBudget, bool $creditApproved): bool
    {
        if (!in_array($option, self::PAYMENT_OPTIONS, true)) {
            return false;
        }
        if ($option === 'on_account') {
            return $customerType === 'business' && $creditApproved;
        }
        if ($option === 'deposit') {
            return true;
        }
        return !$openBudget;
    }

    /**
     * Whether a submission is well formed, without writing anything. The
     * storefront calls this to show a customer what is missing before it posts,
     * and submit() calls it again on the server, because the first check is a
     * courtesy and the second one is the rule.
     *
     * @return array{ok: bool, error: string|null}
     */
    public static function validateSubmission(string $mode, string $pricing, array $items): array
    {
        if (!in_array($mode, self::MODES, true)) {
            return self::invalid('bad_mode');
        }
        if (!in_array($pricing, self::PRICING_MODES, true)) {
            return self::invalid('bad_pricing_mode');
        }
        if (!$items) {
            return self::invalid('no_items');
        }
        if (count($items) > self::MAX_LINES) {
            return self::invalid('too_many_items');
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                return self::invalid('invalid_line');
            }
            $isCatalogue = self::positiveInt($item['product_id'] ?? null) !== null;
            if (!$isCatalogue && trim((string) ($item['item_name'] ?? '')) === '') {
                return self::invalid('invalid_line');
            }

            // Priced by us: we need to know what to buy, not what it costs.
            if ($pricing === 'by_us' && !$isCatalogue) {
                if (self::quantity($item['quantity'] ?? null) === null || self::positiveInt($item['unit_id'] ?? null) === null) {
                    return self::invalid('quantity_unit_required');
                }
            }
            // A catalogue line always needs its quantity, whoever is pricing it.
            if ($isCatalogue && self::quantity($item['quantity'] ?? null) === null) {
                return self::invalid('quantity_unit_required');
            }
            // Priced by the customer: a target price, and we fill the rest in.
            if ($pricing === 'by_customer' && !$isCatalogue) {
                $price = $item['target_price_subunit'] ?? $item['unit_price_subunit'] ?? null;
                if (self::positiveInt($price) === null) {
                    return self::invalid('price_required');
                }
            }
            // Already priced: the line is complete and we are only confirming it.
            if ($pricing === 'already_priced' && !$isCatalogue) {
                if (self::quantity($item['quantity'] ?? null) === null || self::positiveInt($item['unit_id'] ?? null) === null) {
                    return self::invalid('quantity_unit_required');
                }
                if (self::positiveInt($item['unit_price_subunit'] ?? null) === null) {
                    return self::invalid('price_required');
                }
            }
        }

        return ['ok' => true, 'error' => null];
    }

    /**
     * Whether an uploaded list may be saved. Uploads::saveUploadedFile() sniffs
     * the real MIME type and randomises the name, and this runs first so a
     * rejection is a plain sentence to the customer rather than an exception,
     * and so the rule is testable without a request.
     */
    public static function allowedUpload(string $filename, string $mime, int $size): bool
    {
        return $size > 0
            && $size <= self::UPLOAD_MAX_BYTES
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,180}\.(?:jpe?g|png|pdf)$/i', $filename) === 1
            && in_array($mime, self::UPLOAD_MIME, true);
    }

    /**
     * Whether a quote sent at $quotedAt has gone stale by $now. Produce prices
     * move, so a quote cannot stand forever; the window is Order Settings, not
     * a constant, because it is a money rule.
     */
    public static function quoteExpired(string $quotedAt, int $days, string $now): bool
    {
        $start   = strtotime($quotedAt);
        $current = strtotime($now);
        if ($start === false || $current === false || $days < 1) {
            return false;
        }
        return $current >= strtotime('+' . $days . ' days', $start);
    }

    /** How long a quote stands, from Order Settings. */
    public static function quoteDays(): int
    {
        return max(1, min(90, Settings::int('kitchen_run_quote_days', 7)));
    }

    /**
     * A customer edits their own list only while nobody has priced it. Once a
     * quote exists, changing the list would change what was quoted underneath
     * it, so the honest path is to cancel and send a new one, which leaves both
     * requests in the history.
     */
    public static function canCustomerEdit(string $status): bool
    {
        return $status === 'submitted';
    }

    /**
     * A customer may withdraw their own request until it is an order. PRD 8.3
     * says "before it is converted", and approved is before it is converted.
     *
     * There is no money to reverse at this point: the deposit is only taken at
     * conversion. What an approved run may already have is produce bought for
     * it at the market, so the screen asks the customer to call us when that is
     * possible, and staff are emailed the moment one is withdrawn.
     */
    public static function canCustomerCancel(string $status): bool
    {
        return in_array($status, ['submitted', 'quoted', 'approved'], true);
    }

    public static function modeLabel(string $mode): string
    {
        return [
            'catalogue' => 'Picked from the shop',
            'custom'    => 'Typed list',
            'upload'    => 'Uploaded list',
            'mixed'     => 'Shop items and a typed list',
            'priced'    => 'Already priced by the customer',
        ][$mode] ?? $mode;
    }

    public static function pricingLabel(string $pricing): string
    {
        return [
            'by_us'          => 'OK Veggies prices it',
            'by_customer'    => 'Customer set target prices',
            'already_priced' => 'Customer priced it, we confirm',
        ][$pricing] ?? $pricing;
    }

    public static function statusLabel(string $status): string
    {
        return [
            'submitted' => 'Waiting for a price',
            'quoted'    => 'Quote sent',
            'approved'  => 'Approved',
            'converted' => 'Made into an order',
            'declined'  => 'Declined',
            'cancelled' => 'Cancelled',
        ][$status] ?? ucfirst($status);
    }

    /**
     * The one place a refusal is turned into words. The controller sends this to
     * a fetch caller, and the storefront and admin screens render the same
     * sentence after a plain form post, so a customer can never be told two
     * different things about one problem. Nigerian English, no jargon, and it
     * always says what to do next.
     */
    public static function message(string $code): string
    {
        return [
            'bad_mode'               => 'Choose how you want to send your list.',
            'bad_pricing_mode'       => 'Choose who should put the prices on this list.',
            'bad_address'            => 'We need a delivery name, phone number, street, city and state.',
            'bad_customer'           => 'Sign in again, then send your list.',
            'open_budget_pricing'    => 'An open budget means we set the prices, so leave the prices blank.',
            'attachment_required'    => 'Attach your list as a JPEG, PNG or PDF.',
            'attachment_rejected'    => 'That file is not a JPEG, PNG or PDF under 5MB.',
            'no_items'               => 'Add at least 1 item to your list.',
            'too_many_items'         => 'A list can hold up to ' . self::MAX_LINES . ' items. Send the rest as a second run.',
            'invalid_line'           => 'Check the name, quantity and price on every line.',
            'invalid_catalogue_item' => 'One of the shop items on your list is no longer available. Remove it and send again.',
            'quantity_unit_required' => 'Give a quantity and a unit for every item we should price.',
            'price_required'         => 'Give your target price for every item.',
            'budget_not_open'        => 'A spend cap only applies to an open-budget run.',
            'note_too_long'          => 'Keep your note under ' . number_format(self::NOTE_MAX) . ' characters.',
            'budget_not_a_number'    => 'Write the spend cap as a plain amount, for example 150,000.',
            'deposit_not_a_number'   => 'Write the deposit as a plain amount, for example 10,000.',
            'deposit_required'       => 'Set a deposit before this request goes any further.',
            'deposit_above_total'    => 'The deposit cannot be more than the quote.',
            'cap_exceeded'           => 'This quote is above the spend cap that was agreed.',
            'delivery_required'      => 'Choose a delivery date and area before you send the quote.',
            'delivery_unavailable'   => 'We do not deliver on that date. Pick another day.',
            'zone_unavailable'       => 'That delivery area is not available. Pick another one.',
            'quote_expired'          => 'This quote has expired, so we have sent it back for fresh prices.',
            'total_moved'            => 'The lines changed after this was quoted. Price it again before converting it.',
            'reason_required'        => 'Say why you are declining it. The customer is told.',
            'authorisation_required' => 'Say who approved it and how they told you. It goes on the record.',
            'not_quoted'             => 'Only a request with a quote on it can be approved.',
            'stale'                  => 'This request changed while you were working on it. Reload it and try again.',
            'stale_or_not_owned'     => 'This request changed, or it is not yours. Reload the page.',
            'illegal_transition'     => 'That is not something this request can do right now.',
            'payment_not_allowed'    => 'That payment choice is not open to this account.',
            'not_found'              => 'We could not find that Kitchen Run.',
        ][$code] ?? 'We could not save that Kitchen Run. Please try again.';
    }

    /** The HTTP status a refusal deserves. Kept beside the words on purpose. */
    public static function statusCode(string $code): int
    {
        if ($code === 'not_found') {
            return 404;
        }
        if (in_array($code, ['stale', 'stale_or_not_owned', 'illegal_transition', 'quote_expired', 'total_moved'], true)) {
            return 409;
        }
        return 422;
    }

    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /**
     * Add the things every screen needs and no screen should work out for
     * itself: whether the quote has expired, what the customer may still do,
     * and the balance after the deposit.
     */
    public static function decorate(array $request): array
    {
        $status  = (string) ($request['status'] ?? 'submitted');
        $quoted  = ($request['quoted_at'] ?? null) === null ? '' : (string) $request['quoted_at'];
        $expired = $status === 'quoted'
            && $quoted !== ''
            && self::quoteExpired($quoted, self::quoteDays(), date('Y-m-d H:i:s'));

        $total   = ($request['quoted_total_subunit'] ?? null) === null ? null : (int) $request['quoted_total_subunit'];
        $deposit = ($request['deposit_subunit'] ?? null) === null ? null : (int) $request['deposit_subunit'];

        $request['is_expired']     = $expired;
        $request['expires_at']     = $quoted === '' ? null : date('Y-m-d H:i:s', strtotime('+' . self::quoteDays() . ' days', (int) strtotime($quoted)));
        $request['status_label']   = $expired ? 'Quote expired' : self::statusLabel($status);
        $request['may_approve']    = $status === 'quoted' && !$expired;
        $request['may_cancel']     = self::canCustomerCancel($status);
        $request['may_edit']       = self::canCustomerEdit($status);
        $request['balance_subunit'] = $total === null ? null : self::remainingBalance($total, (int) $deposit);

        return $request;
    }

    public static function findForCustomer(int $id, int $userId): ?array
    {
        $row = Database::one(
            'SELECT r.*, z.name AS zone_name, o.order_number
               FROM kitchen_run_requests r
               LEFT JOIN delivery_zones z ON z.id = r.delivery_zone_id
               LEFT JOIN orders o ON o.id = r.converted_order_id
              WHERE r.id = :id AND r.user_id = :user',
            [':id' => $id, ':user' => $userId]
        );
        if ($row === null) {
            return null;
        }
        // The team's own note never leaves the admin panel. Dropping it here
        // rather than trusting every template not to print it means a new
        // screen built on this read cannot leak it by accident.
        unset($row['staff_note']);
        return self::decorate($row);
    }

    public static function findForStaff(int $id): ?array
    {
        $row = Database::one(
            'SELECT r.*, z.name AS zone_name, o.order_number,
                    u.email AS customer_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name
               FROM kitchen_run_requests r
               LEFT JOIN delivery_zones z ON z.id = r.delivery_zone_id
               LEFT JOIN orders o ON o.id = r.converted_order_id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.id = :id',
            [':id' => $id]
        );
        return $row === null ? null : self::decorate($row);
    }

    /** One customer's own requests, newest first. */
    public static function allForCustomer(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows  = Database::all(
            'SELECT r.*, COUNT(i.id) AS line_count
               FROM kitchen_run_requests r
               LEFT JOIN kitchen_run_items i ON i.request_id = r.id
              WHERE r.user_id = :user
              GROUP BY r.id
              ORDER BY r.id DESC
              LIMIT ' . $limit,
            [':user' => $userId]
        );
        return array_map(static function (array $row): array {
            unset($row['staff_note']);
            return self::decorate($row);
        }, $rows);
    }

    /**
     * The staff queue. Waiting-for-a-price first, because that is the only
     * column where a customer is waiting on us, then newest.
     *
     * The customer filter matches whoever a colleague is looking for: the
     * account name, the email, the phone number on the request and the request
     * number itself, because a customer on the phone reads out the number.
     *
     * One named placeholder per position. The connection runs native prepared
     * statements, so MySQL refuses the same name twice in one statement; that
     * mistake shipped once on the orders filter (M6) and once in conversion
     * (M7), and both were a 500 in front of a colleague trying to work.
     */
    public static function allForStaff(string $status = '', int $limit = 100, string $customer = ''): array
    {
        $limit    = max(1, min(200, $limit));
        $where    = [];
        $params   = [];
        $customer = mb_substr(trim($customer), 0, 100);

        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 'r.status = :status';
            $params[':status'] = $status;
        }
        if ($customer !== '') {
            $where[] = '(u.email LIKE :customer_email
                         OR r.request_number LIKE :customer_number
                         OR r.contact_phone LIKE :customer_phone
                         OR r.contact_name LIKE :customer_contact
                         OR TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) LIKE :customer_account)';
            $like = '%' . $customer . '%';
            $params[':customer_email']   = $like;
            $params[':customer_number']  = $like;
            $params[':customer_phone']   = $like;
            $params[':customer_contact'] = $like;
            $params[':customer_account'] = $like;
        }
        $where = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $rows = Database::all(
            'SELECT r.*, COUNT(i.id) AS line_count,
                    u.email AS customer_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name
               FROM kitchen_run_requests r
               LEFT JOIN kitchen_run_items i ON i.request_id = r.id
               LEFT JOIN users u ON u.id = r.user_id'
            . $where .
            ' GROUP BY r.id
              ORDER BY (r.status = \'submitted\') DESC, r.id DESC
              LIMIT ' . $limit,
            $params
        );
        return array_map([self::class, 'decorate'], $rows);
    }

    /** How many lists are waiting on us, for the queue badge. */
    public static function waitingCount(): int
    {
        $row = Database::one('SELECT COUNT(*) AS n FROM kitchen_run_requests WHERE status = \'submitted\'');
        return (int) ($row['n'] ?? 0);
    }

    public static function lines(int $id): array
    {
        return Database::all(
            'SELECT i.*, p.name AS product_name, p.sku, u.name AS unit_name
               FROM kitchen_run_items i
               LEFT JOIN products p ON p.id = i.product_id
               LEFT JOIN units_of_measurement u ON u.id = i.unit_id
              WHERE i.request_id = :id
              ORDER BY i.sort_order, i.id',
            [':id' => $id]
        );
    }

    /** The append-only lifecycle trail, oldest first, the way a story reads. */
    public static function history(int $id): array
    {
        return Database::all(
            'SELECT h.*, TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS actor_name
               FROM kitchen_run_status_history h
               LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.request_id = :id
              ORDER BY h.id ASC',
            [':id' => $id]
        );
    }

    // -------------------------------------------------------------------------
    // Value rules. Every one refuses rather than coerces: a value that is not
    // what it claims to be comes back as null and the caller decides what to
    // say about it. Public because KitchenRunWorkflow parses the same values on
    // the way in, and one set of rules is the only way the form and the writer
    // can agree about what a quantity is.
    // -------------------------------------------------------------------------

    /** A positive decimal with at most 3 places, kept as a string so it stays exact. */
    public static function quantity($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/', $text) !== 1) {
            return null;
        }
        return (float) $text > 0 ? $text : null;
    }

    /**
     * Naira as a person types it into a form, in kobo. Strict on purpose.
     *
     * Money::toSubunit() is deliberately forgiving because it also takes values
     * we generated ourselves, so it reads "abc" as 0 and "1e3" as 13 naira. On
     * a Kitchen Run every price is typed by hand, by a customer setting a budget
     * or a colleague pricing a list, and a silently wrong figure is worse than a
     * refused one. So this refuses anything that is not plainly money and the
     * caller says so, the same way SettingsEditor validates before it converts.
     *
     * Returns null when the field was empty, and false when it held something
     * that is not a price.
     *
     * @return int|null|false
     */
    public static function nairaToKobo($value)
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $stripped = str_replace([',', ' ', "\u{20A6}"], '', $text);
        if (preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', $stripped) !== 1) {
            return false;
        }
        return Money::toSubunit($stripped);
    }

    /** Kobo, or null when the field was left empty. */
    public static function optionalMoney($value): ?int
    {
        return ($value === null || $value === '') ? null : self::nonNegativeInt($value);
    }

    /** Kobo. An integer, never a float, and never wider than the column. */
    public static function nonNegativeInt($value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^[0-9]+$/', $value) !== 1) {
            return null;
        }
        $trimmed = ltrim($value, '0');
        if ($trimmed === '') {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($trimmed) > strlen($max) || (strlen($trimmed) === strlen($max) && strcmp($trimmed, $max) > 0)) {
            return null;
        }
        return (int) $trimmed;
    }

    public static function positiveInt($value): ?int
    {
        $number = self::nonNegativeInt($value);
        return ($number !== null && $number > 0) ? $number : null;
    }

    /** A note the customer or a colleague typed, or null. Refuses an essay. */
    public static function note($value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > self::NOTE_MAX) {
            throw new DomainException('note_too_long');
        }
        return $text;
    }

    /** A one-line note that is truncated rather than refused. */
    public static function shortNote($value): ?string
    {
        $text = trim((string) $value);
        return $text === '' ? null : mb_substr($text, 0, 255);
    }

    private static function invalid(string $code): array
    {
        return ['ok' => false, 'error' => $code];
    }
}
