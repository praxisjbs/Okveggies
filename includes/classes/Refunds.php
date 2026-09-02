<?php
/**
 * includes/classes/Refunds.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Money going back to a customer. See docs/PRD.md Section 11.
 *
 * A refund is not a reversal. A reversal (ManualPayments) undoes a staff typing
 * mistake and no money moves. A refund sends real naira back through Paystack
 * and cannot be undone, so it is Owner gated, it names everything at stake
 * before it is confirmed, and it is never retried automatically.
 *
 * Refunds are asynchronous. Paystack accepts the request, then reports progress
 * through four states: pending, processing, processed and failed. All four are
 * mirrored onto the order so a customer is never left waiting on money that is
 * not coming. refund.failed is the case that makes this worth doing: without
 * it, a failed refund looks exactly like a slow one until the customer
 * complains.
 *
 * The payload of a refund webhook is NOT trusted, and is not even relied on for
 * its shape. An event tells us only which refund it concerns; the authoritative
 * state is then read back from Paystack with fetchRefund. That is the same rule
 * PR1 applies to charges, and here it has a second benefit: it holds however
 * Paystack shape their refund payload, which this build has never seen
 * documented.
 *
 * A refund does not restore the balance on an order. The order was paid, and a
 * refund is either compensation (Make It Right) or the unwinding of a
 * cancellation. Restoring balance_due would make a settled order look unpaid
 * and put it back on the chase list. Paid, refunded and net are tracked
 * separately.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/RefundsTest.php.
 * -----------------------------------------------------------------------------
 */

final class Refunds
{
    /** Ours, before Paystack has accepted the request. */
    public const STATUS_REQUESTED = 'requested';

    /** Paystack's four, mirrored exactly so nothing is lost in translation. */
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED  = 'processed';
    public const STATUS_FAILED     = 'failed';

    /** The event names Paystack sends, from their published events table. */
    public const EVENT_STATUS = [
        'refund.pending'    => self::STATUS_PENDING,
        'refund.processing' => self::STATUS_PROCESSING,
        'refund.processed'  => self::STATUS_PROCESSED,
        'refund.failed'     => self::STATUS_FAILED,
    ];

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /** What is still refundable on a transaction, in subunits. Never negative. */
    public static function refundableAmount(int $paidSubunit, int $alreadyRefundedSubunit): int
    {
        return Money::balance($paidSubunit, $alreadyRefundedSubunit);
    }

    /** Whether an amount may be refunded against what is left. */
    public static function amountIsValid(int $amount, int $refundable): bool
    {
        return $amount >= 1 && $amount <= $refundable;
    }

    /** Which of our statuses an event name maps to, or null if we ignore it. */
    public static function statusFromEvent(string $event): ?string
    {
        return self::EVENT_STATUS[$event] ?? null;
    }

    /** A refund that has finished moving, one way or the other. */
    public static function isTerminal(string $status): bool
    {
        return in_array($status, [self::STATUS_PROCESSED, self::STATUS_FAILED], true);
    }

    /**
     * What the customer reads on their order trail. Plain English, no gateway
     * vocabulary, and the failed case says what happens next rather than
     * leaving someone staring at the word failed.
     */
    public static function customerStatusLine(string $status): string
    {
        switch ($status) {
            case self::STATUS_REQUESTED:
            case self::STATUS_PENDING:
                return 'Your refund has been raised and is on its way to your bank.';
            case self::STATUS_PROCESSING:
                return 'Your refund is with your bank now. Most arrive within a few working days.';
            case self::STATUS_PROCESSED:
                return 'Your refund has been sent. It should be in your account.';
            case self::STATUS_FAILED:
                return 'Your refund did not go through. We have been told, and we will sort it out and be in touch.';
            default:
                return 'Your refund is being looked at.';
        }
    }

    /** Whether a refund at this status still needs chasing by staff. */
    public static function needsAttention(string $status): bool
    {
        return $status === self::STATUS_FAILED;
    }

    // -------------------------------------------------------------------------
    // Everything at stake, for the confirmation the approver has to read
    // -------------------------------------------------------------------------

    /**
     * What a refund on this transaction would involve. This is what the
     * confirmation shows: no refund is ever a single click, so the person
     * approving it sees the order, the customer, what was paid, what has
     * already gone back and what is left, before they commit.
     */
    public static function quote(int $transactionId): array
    {
        $txn = Database::one(
            'SELECT t.id, t.reference, t.provider, t.status, t.amount_subunit, t.channel, t.paid_at,
                    p.id AS payment_id, p.payment_type, p.refunded_amount_subunit,
                    o.id AS order_id, o.order_number, o.order_total_subunit,
                    o.amount_paid_subunit, o.order_status,
                    a.recipient_name, a.recipient_phone
               FROM payment_transactions t
               JOIN payments p ON p.id = t.payment_id
               JOIN orders o ON o.id = p.order_id
               LEFT JOIN order_addresses a ON a.order_id = o.id
              WHERE t.id = :id',
            [':id' => $transactionId]
        );
        if (!$txn) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That transaction could not be found.'];
        }

        $paid = (int) $txn['amount_subunit'];
        $done = (int) (Database::one(
            'SELECT COALESCE(SUM(amount_subunit), 0) AS total
               FROM refunds
              WHERE payment_transaction_id = :id AND status <> :failed',
            [':id' => $transactionId, ':failed' => self::STATUS_FAILED]
        )['total'] ?? 0);

        return [
            'ok'                 => true,
            'transaction_id'     => (int) $txn['id'],
            'reference'          => (string) $txn['reference'],
            'provider'           => (string) $txn['provider'],
            'order_id'           => (int) $txn['order_id'],
            'order_number'       => (string) $txn['order_number'],
            'order_status'       => (string) $txn['order_status'],
            'customer_name'      => (string) ($txn['recipient_name'] ?? ''),
            'customer_phone'     => (string) ($txn['recipient_phone'] ?? ''),
            'paid_subunit'       => $paid,
            'refunded_subunit'   => $done,
            'refundable_subunit' => self::refundableAmount($paid, $done),
            'is_paystack'        => (string) $txn['provider'] === 'paystack',
        ];
    }

    // -------------------------------------------------------------------------
    // Raising a refund
    // -------------------------------------------------------------------------

    /**
     * Send money back. Owner gated at the controller. Not retried: a refund that
     * reached Paystack has moved money, and a blind retry pays a customer twice.
     */
    public static function request(int $transactionId, int $amountSubunit, string $customerNote, string $merchantNote, int $staffId): array
    {
        $quote = self::quote($transactionId);
        if (!$quote['ok']) {
            return $quote;
        }
        if (!$quote['is_paystack']) {
            return ['ok' => false, 'code' => 'not_refundable', 'message' => 'Only a Paystack payment can be refunded here. Money recorded by staff is corrected with a reversal.'];
        }
        if (!self::amountIsValid($amountSubunit, $quote['refundable_subunit'])) {
            return [
                'ok'      => false,
                'code'    => 'bad_amount',
                'message' => 'Enter an amount between ' . Money::format(1) . ' and ' . Money::format($quote['refundable_subunit']) . '.',
            ];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            // Lock the transaction so two approvers cannot each pass the
            // refundable check and both send money.
            Database::one('SELECT id FROM payment_transactions WHERE id = :id FOR UPDATE', [':id' => $transactionId]);

            $stillDone = (int) (Database::one(
                'SELECT COALESCE(SUM(amount_subunit), 0) AS total
                   FROM refunds
                  WHERE payment_transaction_id = :id AND status <> :failed',
                [':id' => $transactionId, ':failed' => self::STATUS_FAILED]
            )['total'] ?? 0);
            if (!self::amountIsValid($amountSubunit, self::refundableAmount($quote['paid_subunit'], $stillDone))) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'bad_amount', 'message' => 'Another refund was raised on this payment while you were looking. Reopen it and check what is left.'];
            }

            Database::run(
                'INSERT INTO refunds
                    (payment_transaction_id, order_id, amount_subunit, currency, status,
                     customer_note, merchant_note, requested_by, approved_by)
                 VALUES (:txn, :order, :amount, :currency, :status, :cnote, :mnote, :staff, :staff2)',
                [
                    ':txn'      => $transactionId,
                    ':order'    => $quote['order_id'],
                    ':amount'   => $amountSubunit,
                    ':currency' => Money::CODE,
                    ':status'   => self::STATUS_REQUESTED,
                    ':cnote'    => trim($customerNote) ?: null,
                    ':mnote'    => trim($merchantNote) ?: null,
                    ':staff'    => $staffId,
                    ':staff2'   => $staffId,
                ]
            );
            $refundId = (int) $pdo->lastInsertId();
            self::writeHistory($refundId, null, self::STATUS_REQUESTED, null, $staffId, 'Raised by staff.');

            // Committed before Paystack is called, so a call that dies without
            // an answer still leaves a row the reconciliation can find. Same
            // rule as opening a charge in PR1.
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $response = Paystack::createRefund($quote['reference'], $amountSubunit, $customerNote, $merchantNote);

        if (!$response['ok']) {
            // A network reason means we do not know whether Paystack took it.
            // The row stays at requested for a human to check rather than being
            // marked failed, because marking it failed invites a second attempt.
            $note = $response['reason'] === 'network'
                ? 'Paystack could not be reached. Check the Paystack dashboard before raising this again.'
                : $response['message'];
            if ($response['reason'] !== 'network') {
                Database::run(
                    'UPDATE refunds SET status = :status WHERE id = :id',
                    [':status' => self::STATUS_FAILED, ':id' => $refundId]
                );
                self::writeHistory($refundId, self::STATUS_REQUESTED, self::STATUS_FAILED, null, $staffId, $note);
            } else {
                self::writeHistory($refundId, self::STATUS_REQUESTED, self::STATUS_REQUESTED, null, $staffId, $note);
            }
            return [
                'ok'      => false,
                'code'    => $response['reason'] === 'network' ? 'gateway_unreachable' : 'gateway_refused',
                'message' => $note,
                'refund_id' => $refundId,
            ];
        }

        $data   = $response['data'];
        $status = self::normaliseStatus((string) ($data['status'] ?? self::STATUS_PENDING));

        Database::run(
            'UPDATE refunds
                SET provider_refund_id = :pid, status = :status, provider_response = :raw,
                    expected_at = :expected
              WHERE id = :id',
            [
                ':pid'      => ($data['id'] ?? null) ? (int) $data['id'] : null,
                ':status'   => $status,
                ':raw'      => json_encode($data),
                ':expected' => self::toDateTime($data['expected_at'] ?? null),
                ':id'       => $refundId,
            ]
        );
        self::writeHistory($refundId, self::STATUS_REQUESTED, $status, null, $staffId, 'Accepted by Paystack.');

        if ($status === self::STATUS_PROCESSED) {
            self::applyProcessed($refundId);
        }

        return [
            'ok'        => true,
            'code'      => 'raised',
            'refund_id' => $refundId,
            'status'    => $status,
            'message'   => 'Refund of ' . Money::format($amountSubunit) . ' raised. ' . self::customerStatusLine($status),
        ];
    }

    // -------------------------------------------------------------------------
    // Webhook
    // -------------------------------------------------------------------------

    /**
     * Move a refund on from a signed refund.* event.
     *
     * The payload identifies the refund and nothing more. The state that gets
     * written is read back from Paystack, so this holds whatever shape their
     * refund payload takes, which this build has never seen documented.
     */
    public static function applyWebhook(string $event, array $data, ?int $webhookEventId = null): array
    {
        $mapped = self::statusFromEvent($event);
        if ($mapped === null) {
            return ['ok' => false, 'code' => 'ignored', 'message' => 'Not a refund event.'];
        }

        $providerId = ($data['id'] ?? null) !== null ? (int) $data['id'] : null;
        $reference  = trim((string) ($data['transaction_reference'] ?? ($data['transaction'] ?? '')));

        $refund = null;
        if ($providerId !== null) {
            $refund = Database::one('SELECT * FROM refunds WHERE provider_refund_id = :id', [':id' => $providerId]);
        }
        if (!$refund && $reference !== '') {
            $refund = Database::one(
                'SELECT r.* FROM refunds r
                   JOIN payment_transactions t ON t.id = r.payment_transaction_id
                  WHERE t.reference = :ref
                  ORDER BY r.id DESC LIMIT 1',
                [':ref' => $reference]
            );
        }
        if (!$refund) {
            // Never guessed at. An admin sees it unmatched, exactly as with an
            // unmatched charge.
            return ['ok' => false, 'code' => 'unmatched', 'message' => 'No local refund holds that identifier.'];
        }

        $refundId = (int) $refund['id'];
        $old      = (string) $refund['status'];

        // Read the truth from Paystack rather than from the event body.
        $status = $mapped;
        if ($providerId !== null) {
            $fetched = Paystack::fetchRefund((string) $providerId);
            if ($fetched['ok'] && isset($fetched['data']['status'])) {
                $status = self::normaliseStatus((string) $fetched['data']['status']);
                Database::run(
                    'UPDATE refunds SET provider_response = :raw WHERE id = :id',
                    [':raw' => json_encode($fetched['data']), ':id' => $refundId]
                );
            }
        }

        if ($status === $old) {
            return ['ok' => true, 'code' => 'unchanged', 'message' => 'Refund already at that state.'];
        }
        // A refund that has already landed does not move again.
        if (self::isTerminal($old)) {
            return ['ok' => true, 'code' => 'already_final', 'message' => 'Refund already finished.'];
        }

        Database::run('UPDATE refunds SET status = :status WHERE id = :id', [':status' => $status, ':id' => $refundId]);
        self::writeHistory($refundId, $old, $status, $webhookEventId, null, 'Paystack reported ' . $status . '.');

        if ($status === self::STATUS_PROCESSED) {
            self::applyProcessed($refundId);
        }

        return ['ok' => true, 'code' => $status, 'message' => 'Refund moved to ' . $status . '.'];
    }

    /**
     * A refund that has actually landed. This is the only place the money is
     * counted, so a refund that never completes never touches the books.
     */
    private static function applyProcessed(int $refundId): void
    {
        $refund = Database::one(
            'SELECT r.*, t.payment_id FROM refunds r
               JOIN payment_transactions t ON t.id = r.payment_transaction_id
              WHERE r.id = :id',
            [':id' => $refundId]
        );
        if (!$refund) {
            return;
        }

        Database::run(
            'UPDATE payments
                SET refunded_amount_subunit = refunded_amount_subunit + :amount
              WHERE id = :id',
            [':amount' => (int) $refund['amount_subunit'], ':id' => (int) $refund['payment_id']]
        );
        Database::run(
            'UPDATE refunds SET refunded_at = NOW() WHERE id = :id AND refunded_at IS NULL',
            [':id' => $refundId]
        );
        Database::run(
            'UPDATE payment_transactions
                SET status = CASE WHEN :amount2 >= amount_subunit THEN \'refunded\' ELSE \'part_refunded\' END
              WHERE id = :txn',
            [':amount2' => (int) $refund['amount_subunit'], ':txn' => (int) $refund['payment_transaction_id']]
        );

        // Deliberately does NOT restore balance_due. The order was paid; a
        // refund is compensation or the unwinding of a cancellation, not an
        // unpaid balance, and restoring it would put a settled order back on
        // the chase list.
    }

    /** Every refund raised against an order, newest first. */
    public static function forOrder(int $orderId): array
    {
        return Database::all(
            'SELECT r.*, t.reference, u.first_name AS requested_by_name
               FROM refunds r
               JOIN payment_transactions t ON t.id = r.payment_transaction_id
               LEFT JOIN users u ON u.id = r.requested_by
              WHERE r.order_id = :id
              ORDER BY r.id DESC',
            [':id' => $orderId]
        );
    }

    /** Refunds that stopped somewhere they should not have. */
    public static function needingAttention(int $limit = 50): array
    {
        return Database::all(
            'SELECT r.*, t.reference, o.order_number, o.id AS order_id
               FROM refunds r
               JOIN payment_transactions t ON t.id = r.payment_transaction_id
               JOIN orders o ON o.id = r.order_id
              WHERE r.status IN (:failed, :requested)
              ORDER BY r.created_at
              LIMIT ' . (int) $limit,
            [':failed' => self::STATUS_FAILED, ':requested' => self::STATUS_REQUESTED]
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** Map whatever Paystack sends onto one of our five, defaulting to pending. */
    private static function normaliseStatus(string $raw): string
    {
        $clean = strtolower(trim($raw));
        $known = [
            self::STATUS_PENDING, self::STATUS_PROCESSING,
            self::STATUS_PROCESSED, self::STATUS_FAILED,
        ];
        if (in_array($clean, $known, true)) {
            return $clean;
        }
        // Paystack has used "success" on some refund reads. Treat it as landed.
        if ($clean === 'success' || $clean === 'succeeded') {
            return self::STATUS_PROCESSED;
        }
        return self::STATUS_PENDING;
    }

    private static function writeHistory(int $refundId, ?string $old, string $new, ?int $eventId, ?int $staffId, ?string $note): void
    {
        Database::run(
            'INSERT INTO refund_status_history
                (refund_id, old_status, new_status, webhook_event_id, note, changed_by)
             VALUES (:id, :old, :new, :event, :note, :staff)',
            [
                ':id'    => $refundId,
                ':old'   => $old,
                ':new'   => $new,
                ':event' => $eventId,
                ':note'  => $note,
                ':staff' => $staffId,
            ]
        );
    }

    private static function toDateTime($value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
