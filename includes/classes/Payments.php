<?php
/**
 * includes/classes/Payments.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The payment ledger. This is the only place money is recorded
 * against an order, and it is written so that recording it twice is impossible.
 *
 * How a charge is bound to an order, with certainty:
 *
 *   1. We mint the reference, and the payment_transactions row is written
 *      BEFORE Paystack is called. There is never a reference alive at Paystack
 *      that we have no row for, not even if the network dies mid-call.
 *   2. reference is UNIQUE, so a reference resolves to exactly one transaction,
 *      one payment and one order by key, never by search.
 *   3. An unknown reference is never guessed at. It is recorded unmatched and
 *      left for an admin. A reference from another integration cannot reach an
 *      order.
 *   4. Four guards run before any credit: status, currency, reference and
 *      domain. The domain guard is what stops a test-mode event crediting a
 *      live order.
 *   5. The credit itself is a conditional UPDATE on the payment row. The winner
 *      sees rowCount() === 1, every loser sees 0, and the ledger write lives
 *      only inside the winning branch. Callback, webhook, webhook retry and the
 *      sweep can all race; one credits.
 *   6. Anything still unresolved past the sweep window is verified against
 *      Paystack directly, which is what makes the outcome certain rather than
 *      merely likely.
 *
 * The amount rule: an order is credited with requested_amount, the price we
 * asked for the goods, NOT amount, which is the gross charge and includes the
 * Paystack fee when the customer bears it. Paystack's own documented sample has
 * these differing by exactly the fee. Crediting amount would over-credit every
 * order by the fee. The fee is recorded separately for the settlement view.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/PaymentsTest.php.
 * -----------------------------------------------------------------------------
 */

final class Payments
{
    /** A payment that has been fully settled. The claim guards against this. */
    public const STATUS_PAID      = 'paid';
    public const STATUS_PART_PAID = 'part_paid';
    public const STATUS_UNPAID    = 'unpaid';

    /** Transaction lifecycle. 'unknown' means a call died without an answer. */
    public const TXN_INITIALIZED = 'initialized';
    public const TXN_SUCCESS     = 'success';
    public const TXN_FAILED      = 'failed';
    public const TXN_ABANDONED   = 'abandoned';
    public const TXN_UNKNOWN     = 'unknown';
    public const TXN_MISMATCH    = 'mismatch';
    public const TXN_DUPLICATE   = 'duplicate';

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /**
     * What a verified Paystack payload credits to the order, in subunits.
     *
     * requested_amount is what we asked for the goods. amount is the gross
     * charge, which is larger when the customer bears the Paystack fee. We
     * credit the goods price. Falls back to amount only when Paystack sends no
     * requested_amount, which older payloads may not.
     */
    public static function creditableAmount(array $data): int
    {
        $requested = (int) ($data['requested_amount'] ?? 0);
        if ($requested > 0) {
            return $requested;
        }
        return max(0, (int) ($data['amount'] ?? 0));
    }

    /** The Paystack fee on a verified payload, in subunits. Zero when absent. */
    public static function providerFee(array $data): int
    {
        return max(0, (int) ($data['fees'] ?? 0));
    }

    /** How a credited amount compares with what the payment expected. */
    public static function mismatchKind(int $credited, int $expected): string
    {
        if ($credited === $expected) {
            return 'exact';
        }
        return $credited < $expected ? 'under' : 'over';
    }

    /** A payment's status from what it expected and what it has been paid. */
    public static function paymentStatus(int $paid, int $expected): string
    {
        if ($paid <= 0) {
            return self::STATUS_UNPAID;
        }
        return $paid >= $expected ? self::STATUS_PAID : self::STATUS_PART_PAID;
    }

    /** An order's payment_status from its total and everything paid against it. */
    public static function orderPaymentStatus(int $paid, int $total): string
    {
        if ($paid <= 0) {
            return self::STATUS_UNPAID;
        }
        return $paid >= $total ? self::STATUS_PAID : self::STATUS_PART_PAID;
    }

    /**
     * Mint a transaction reference. Legal under Paystack's charset (only -, .
     * = and alphanumerics) and unique per attempt. The order number carries the
     * binding in plain sight, which makes a Paystack dashboard row readable
     * without a lookup.
     */
    public static function reference(string $orderNumber, int $attempt): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $orderNumber) ?? '';
        $tail  = bin2hex(random_bytes(4));
        return $clean . '-' . str_pad((string) $attempt, 2, '0', STR_PAD_LEFT) . '-' . $tail;
    }

    /**
     * Whether a verified payload may credit anything at all. Every guard here
     * runs before money moves, and each one refuses rather than guesses.
     */
    public static function chargeIsAcceptable(array $data, string $expectedDomain, string $expectedReference): array
    {
        if ((string) ($data['status'] ?? '') !== 'success') {
            return ['ok' => false, 'reason' => 'not_successful'];
        }
        if ((string) ($data['reference'] ?? '') !== $expectedReference) {
            return ['ok' => false, 'reason' => 'reference_mismatch'];
        }
        if (strtoupper((string) ($data['currency'] ?? '')) !== Money::CODE) {
            return ['ok' => false, 'reason' => 'currency_mismatch'];
        }
        // A test-mode event must never credit a live order, or the reverse.
        if ((string) ($data['domain'] ?? '') !== $expectedDomain) {
            return ['ok' => false, 'reason' => 'domain_mismatch'];
        }
        if (self::creditableAmount($data) < 1) {
            return ['ok' => false, 'reason' => 'zero_amount'];
        }
        return ['ok' => true, 'reason' => 'ok'];
    }

    /**
     * The dedupe key for a webhook event. Paystack sends no idempotency header
     * and retries the same event for up to 72 hours, so the key is the event
     * name plus the resource it is about.
     */
    public static function deduplicationKey(string $event, array $data): string
    {
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            $id = (string) ($data['reference'] ?? '');
        }
        return $event . ':' . $id;
    }

    /** Paystack sends metadata back as an object, a JSON string, or "". */
    public static function decodeMetadata($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    // -------------------------------------------------------------------------
    // Starting a charge
    // -------------------------------------------------------------------------

    /**
     * Open a Paystack transaction for a payment row and hand back the URL the
     * customer is sent to. The local row is written first and always survives,
     * so a call that dies without an answer leaves something for the sweep.
     */
    public static function beginCharge(int $paymentId, string $callbackUrl): array
    {
        $payment = Database::one(
            'SELECT p.*, o.order_number, o.id AS order_id, u.email
               FROM payments p
               JOIN orders o ON o.id = p.order_id
               LEFT JOIN users u ON u.id = p.user_id
              WHERE p.id = :id',
            [':id' => $paymentId]
        );
        if (!$payment) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That payment could not be found.'];
        }
        if ($payment['status'] === self::STATUS_PAID) {
            return ['ok' => false, 'code' => 'already_paid', 'message' => 'This payment has already been settled.'];
        }
        $email = trim((string) ($payment['email'] ?? ''));
        if ($email === '') {
            return ['ok' => false, 'code' => 'no_email', 'message' => 'This account has no email address to send a receipt to.'];
        }

        $expected = (int) $payment['expected_amount_subunit'];
        $paid     = (int) $payment['paid_amount_subunit'];
        $due      = Money::balance($expected, $paid);
        if ($due < 1) {
            return ['ok' => false, 'code' => 'nothing_due', 'message' => 'There is nothing left to pay on this order.'];
        }

        $attempt   = 1 + (int) (Database::one(
            'SELECT COALESCE(MAX(attempt_number), 0) AS n FROM payment_transactions WHERE payment_id = :id',
            [':id' => $paymentId]
        )['n'] ?? 0);
        $reference = self::reference((string) $payment['order_number'], $attempt);

        // The row exists before Paystack hears the reference. This is the whole
        // basis of the binding guarantee, so it is committed on its own.
        Database::run(
            'INSERT INTO payment_transactions
                (payment_id, attempt_number, provider, reference, domain, status,
                 requested_amount_subunit, currency, customer_email, callback_url, ip_address)
             VALUES (:pid, :attempt, \'paystack\', :ref, :domain, \'initialized\',
                     :amount, :currency, :email, :callback, :ip)',
            [
                ':pid'      => $paymentId,
                ':attempt'  => $attempt,
                ':ref'      => $reference,
                ':domain'   => Paystack::domain(),
                ':amount'   => $due,
                ':currency' => Money::CODE,
                ':email'    => $email,
                ':callback' => $callbackUrl,
                ':ip'       => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]
        );
        $txnId = (int) Database::getInstance()->getConnection()->lastInsertId();

        $response = Paystack::initializeTransaction($email, $due, $reference, $callbackUrl, [
            'order_id'     => (int) $payment['order_id'],
            'order_number' => (string) $payment['order_number'],
            'payment_id'   => $paymentId,
            'payment_type' => (string) $payment['payment_type'],
        ]);

        if (!$response['ok']) {
            // A network reason means Paystack may or may not hold this
            // reference. Mark it unknown, never failed, and let the sweep
            // settle it against Paystack rather than guessing here.
            $status = $response['reason'] === 'network' ? self::TXN_UNKNOWN : self::TXN_FAILED;
            Database::run(
                'UPDATE payment_transactions
                    SET status = :status, gateway_response = :message
                  WHERE id = :id',
                [':status' => $status, ':message' => substr($response['message'], 0, 255), ':id' => $txnId]
            );
            return [
                'ok'      => false,
                'code'    => $response['reason'] === 'network' ? 'gateway_unreachable' : 'gateway_refused',
                'message' => $response['message'],
            ];
        }

        $data = $response['data'];
        Database::run(
            'UPDATE payment_transactions
                SET access_code = :code, authorization_url = :url, initialization_response = :raw
              WHERE id = :id',
            [
                ':code' => (string) ($data['access_code'] ?? '') ?: null,
                ':url'  => (string) ($data['authorization_url'] ?? '') ?: null,
                ':raw'  => json_encode($data),
                ':id'   => $txnId,
            ]
        );

        return [
            'ok'                => true,
            'reference'         => $reference,
            'authorization_url' => (string) ($data['authorization_url'] ?? ''),
            'access_code'       => (string) ($data['access_code'] ?? ''),
            'amount_subunit'    => $due,
        ];
    }

    // -------------------------------------------------------------------------
    // Applying a verified charge. The only path by which money moves.
    // -------------------------------------------------------------------------

    /**
     * Apply a payload already verified against Paystack. Safe to call from the
     * callback, the webhook, a webhook retry and the sweep, concurrently. At
     * most one call credits; the rest are recorded and return 'duplicate'.
     *
     * $source is one of callback, webhook, sweep, admin.
     */
    public static function applyVerifiedCharge(string $reference, array $data, string $source, ?int $webhookEventId = null): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $txn = Database::one(
                'SELECT * FROM payment_transactions WHERE reference = :ref FOR UPDATE',
                [':ref' => $reference]
            );
            if (!$txn) {
                // Never guessed at, never created. An admin sees it unmatched.
                $pdo->commit();
                return ['ok' => false, 'code' => 'unmatched', 'message' => 'No local transaction holds that reference.'];
            }

            $check = self::chargeIsAcceptable($data, (string) $txn['domain'], $reference);
            if (!$check['ok']) {
                self::recordTransactionOutcome((int) $txn['id'], self::TXN_FAILED, $data, $check['reason']);
                $pdo->commit();
                return ['ok' => false, 'code' => $check['reason'], 'message' => 'That payment was not accepted.'];
            }

            $payment = Database::one('SELECT * FROM payments WHERE id = :id FOR UPDATE', [':id' => (int) $txn['payment_id']]);
            if (!$payment) {
                $pdo->commit();
                return ['ok' => false, 'code' => 'unmatched', 'message' => 'That transaction has no payment row.'];
            }

            $credited = self::creditableAmount($data);
            $expected = (int) $payment['expected_amount_subunit'];
            $kind     = self::mismatchKind($credited, $expected);

            // The claim. Exactly one caller can move a payment out of unpaid.
            $claimed = Database::run(
                'UPDATE payments
                    SET status = :status,
                        paid_amount_subunit = :paid,
                        confirmed_at = NOW()
                  WHERE id = :id AND status <> :paid_status',
                [
                    ':status'      => self::paymentStatus($credited, $expected),
                    ':paid'        => $credited,
                    ':id'          => (int) $payment['id'],
                    ':paid_status' => self::STATUS_PAID,
                ]
            );

            if ($claimed === 0) {
                // Someone already settled this payment. Either this is the same
                // event arriving again, or the customer paid a second time. The
                // second case is money we hold and must not silently keep.
                $isSameTransaction = (string) $txn['status'] === self::TXN_SUCCESS;
                $status = $isSameTransaction ? self::TXN_DUPLICATE : self::TXN_MISMATCH;
                self::recordTransactionOutcome((int) $txn['id'], $status, $data, $isSameTransaction ? 'duplicate' : 'overpayment');
                self::writeHistory((int) $payment['id'], (int) $txn['id'], (string) $payment['status'], (string) $payment['status'], $source, $webhookEventId, $isSameTransaction ? 'Duplicate event, already settled.' : 'A second successful charge arrived. Needs a refund decision.');
                $pdo->commit();
                return [
                    'ok'      => false,
                    'code'    => $isSameTransaction ? 'duplicate' : 'overpayment',
                    'message' => $isSameTransaction ? 'This payment was already recorded.' : 'A second payment arrived for a settled order.',
                ];
            }

            // We are the single winner. Everything below runs exactly once.
            self::recordTransactionOutcome((int) $txn['id'], self::TXN_SUCCESS, $data, $kind === 'exact' ? null : $kind);
            self::writeHistory(
                (int) $payment['id'],
                (int) $txn['id'],
                (string) $payment['status'],
                self::paymentStatus($credited, $expected),
                $source,
                $webhookEventId,
                $kind === 'exact' ? null : 'Amount ' . $kind . ': expected ' . $expected . ', received ' . $credited . ' subunits.'
            );
            self::recomputeOrder((int) $payment['order_id']);

            $pdo->commit();
            return [
                'ok'          => true,
                'code'        => 'credited',
                'mismatch'    => $kind,
                'credited'    => $credited,
                'expected'    => $expected,
                'order_id'    => (int) $payment['order_id'],
                'payment_id'  => (int) $payment['id'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Write the verified facts onto the transaction row. */
    private static function recordTransactionOutcome(int $txnId, string $status, array $data, ?string $note): void
    {
        $auth = is_array($data['authorization'] ?? null) ? $data['authorization'] : [];
        $customer = is_array($data['customer'] ?? null) ? $data['customer'] : [];

        Database::run(
            'UPDATE payment_transactions
                SET status = :status,
                    provider_transaction_id = :pid,
                    amount_subunit = :amount,
                    provider_fee_subunit = :fee,
                    channel = :channel,
                    gateway_response = :gateway,
                    customer_code = :customer_code,
                    card_type = :card_type,
                    last4 = :last4,
                    bank_name = :bank,
                    metadata = :metadata,
                    verification_response = :raw,
                    paid_at = :paid_at,
                    verified_at = NOW()
              WHERE id = :id',
            [
                ':status'  => $status,
                ':pid'     => ($data['id'] ?? null) ? (int) $data['id'] : null,
                ':amount'  => max(0, (int) ($data['amount'] ?? 0)),
                ':fee'     => self::providerFee($data),
                ':channel' => substr((string) ($data['channel'] ?? ''), 0, 40) ?: null,
                // Paystack's own sample carries "visa " with a trailing space.
                ':gateway' => substr(trim((string) ($data['gateway_response'] ?? ($note ?? ''))), 0, 255) ?: null,
                ':customer_code' => substr(trim((string) ($customer['customer_code'] ?? '')), 0, 100) ?: null,
                ':card_type'     => substr(trim((string) ($auth['card_type'] ?? '')), 0, 50) ?: null,
                ':last4'         => substr(trim((string) ($auth['last4'] ?? '')), 0, 4) ?: null,
                ':bank'          => substr(trim((string) ($auth['bank'] ?? '')), 0, 150) ?: null,
                ':metadata'      => json_encode(self::decodeMetadata($data['metadata'] ?? null)),
                ':raw'           => json_encode($data),
                ':paid_at'       => self::toDateTime($data['paid_at'] ?? ($data['paidAt'] ?? null)),
                ':id'            => $txnId,
            ]
        );
    }

    /** Append-only. Every move records why, from where, and on whose event. */
    private static function writeHistory(int $paymentId, ?int $txnId, ?string $old, string $new, string $source, ?int $eventId, ?string $reason): void
    {
        Database::run(
            'INSERT INTO payment_status_history
                (payment_id, payment_transaction_id, old_status, new_status, source, webhook_event_id, reason)
             VALUES (:pid, :txn, :old, :new, :source, :event, :reason)',
            [
                ':pid'    => $paymentId,
                ':txn'    => $txnId,
                ':old'    => $old,
                ':new'    => $new,
                ':source' => $source,
                ':event'  => $eventId,
                ':reason' => $reason === null ? null : substr($reason, 0, 500),
            ]
        );
    }

    /**
     * Recompute an order from its payment rows. Never trusts a running total:
     * the order is the sum of what its payments actually hold.
     */
    public static function recomputeOrder(int $orderId): void
    {
        $order = Database::one('SELECT order_total_subunit FROM orders WHERE id = :id', [':id' => $orderId]);
        if (!$order) {
            return;
        }
        $total = (int) $order['order_total_subunit'];
        $paid  = (int) (Database::one(
            'SELECT COALESCE(SUM(paid_amount_subunit), 0) AS paid FROM payments WHERE order_id = :id',
            [':id' => $orderId]
        )['paid'] ?? 0);

        Database::run(
            'UPDATE orders
                SET amount_paid_subunit = :paid,
                    balance_due_subunit = :balance,
                    payment_status = :status
              WHERE id = :id',
            [
                ':paid'    => $paid,
                ':balance' => Money::balance($total, $paid),
                ':status'  => self::orderPaymentStatus($paid, $total),
                ':id'      => $orderId,
            ]
        );
    }

    // -------------------------------------------------------------------------
    // Reconciliation sweep
    // -------------------------------------------------------------------------

    /**
     * Settle anything left hanging, by asking Paystack directly.
     *
     * This is the safety net that makes the outcome certain rather than merely
     * likely. It catches the two cases nothing else can: a webhook that never
     * arrived while the customer also never came back, and an initialise whose
     * network call died without an answer, where Paystack may or may not hold
     * the reference.
     *
     * Idempotent by construction, because everything it finds goes through
     * applyVerifiedCharge, which admits exactly one credit per payment.
     * Designed to be run from cron every few minutes.
     */
    public static function sweep(int $limit = 50): array
    {
        // Both are integers before they reach the query: a placeholder inside an
        // INTERVAL is the one spot native prepares can be awkward, and this is
        // the safety net, so it must not be the thing that quietly throws.
        $minutes = (int) max(1, Settings::int('payment_verify_sweep_minutes', 15));
        $counts  = ['checked' => 0, 'credited' => 0, 'failed' => 0, 'pending' => 0, 'unreachable' => 0];

        $stale = Database::all(
            'SELECT id, reference, status
               FROM payment_transactions
              WHERE status IN (:initialized, :unknown)
                AND initialized_at < DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE)
              ORDER BY initialized_at
              LIMIT ' . (int) $limit,
            [':initialized' => self::TXN_INITIALIZED, ':unknown' => self::TXN_UNKNOWN]
        );

        foreach ($stale as $txn) {
            $counts['checked']++;
            $reference = (string) $txn['reference'];

            try {
                $verified = Paystack::verifyTransaction($reference);
            } catch (Throwable $e) {
                error_log('payment sweep: verify threw for ' . $reference . ': ' . $e->getMessage());
                $counts['unreachable']++;
                continue;
            }

            if (!$verified['ok']) {
                if ($verified['reason'] === 'network') {
                    // Still unknown. Leave it for the next run rather than
                    // recording an outcome we cannot stand behind.
                    $counts['unreachable']++;
                    continue;
                }
                // Paystack answered. A reference it has never heard of belongs
                // to an initialise that died before it landed, so it is dead.
                Database::run(
                    'UPDATE payment_transactions SET status = :status, gateway_response = :message WHERE id = :id',
                    [':status' => self::TXN_FAILED, ':message' => substr($verified['message'], 0, 255), ':id' => (int) $txn['id']]
                );
                $counts['failed']++;
                continue;
            }

            $status = (string) ($verified['data']['status'] ?? '');
            if ($status === 'success') {
                $result = self::applyVerifiedCharge($reference, $verified['data'], 'sweep');
                if ($result['ok'] || $result['code'] === 'duplicate') {
                    $counts['credited']++;
                } else {
                    $counts['failed']++;
                }
                continue;
            }

            if (in_array($status, ['failed', 'abandoned', 'reversed'], true)) {
                Database::run(
                    'UPDATE payment_transactions
                        SET status = :status, gateway_response = :message, verified_at = NOW(), verification_response = :raw
                      WHERE id = :id',
                    [
                        ':status'  => $status === 'abandoned' ? self::TXN_ABANDONED : self::TXN_FAILED,
                        ':message' => substr((string) ($verified['data']['gateway_response'] ?? $status), 0, 255),
                        ':raw'     => json_encode($verified['data']),
                        ':id'      => (int) $txn['id'],
                    ]
                );
                $counts['failed']++;
                continue;
            }

            // ongoing, pending, queued and anything new Paystack adds: leave it.
            $counts['pending']++;
        }

        return $counts;
    }

    /** Paystack sends ISO 8601. MySQL wants a DATETIME, or NULL. */
    private static function toDateTime($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
