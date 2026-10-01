<?php
/**
 * includes/classes/ManualRefunds.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Money that has to leave by a bank transfer someone makes by hand.
 *
 * Paystack does not handle refunds for this shop, so two things end up here: a
 * refund the customer asked for after an item could not be sourced, and a wallet
 * cash out. In both the customer gives their bank name, account number and
 * account name, a colleague with payments.refund sends the money from the bank,
 * and marks the row paid with the bank's reference. Nothing here sends money; it
 * is the queue that makes sure a promise to pay is written down, is paid once,
 * and can be seen by everyone who needs to see it.
 *
 *   requested  waiting for someone to send the money
 *   paid       sent, with the bank reference
 *   cancelled  not sent. A wallet cash out goes back into the wallet; a shortage
 *              refund goes back to the customer to choose again.
 *
 * A wallet cash out takes the money out of the wallet when it is requested, so it
 * cannot be spent while the transfer is waiting.
 * -----------------------------------------------------------------------------
 */
final class ManualRefunds
{
    public const KIND_SHORTAGE = 'shortage';
    public const KIND_CASHOUT  = 'wallet_cashout';

    public const STATUS_REQUESTED = 'requested';
    public const STATUS_PAID      = 'paid';
    public const STATUS_CANCELLED = 'cancelled';

    private const MESSAGES = [
        'insufficient_balance' => 'That is more than your wallet holds.',
        'bad_amount'           => 'Enter an amount of at least ₦1.',
        'not_found'            => 'That refund could not be found.',
        'not_requested'        => 'That refund is not waiting to be paid.',
        'reference_required'   => 'Enter the bank reference, 3 to 120 characters.',
        'reason_required'      => 'Give a reason of 5 to 200 characters.',
        'bad_token'            => 'Reload the page and try again.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'We could not do that just now. Please try again.';
    }

    // -------------------------------------------------------------------------
    // Pure rules
    // -------------------------------------------------------------------------

    /**
     * Check the three bank details a refund needs. The account number is a
     * Nigerian bank account number: exactly ten digits, spaces and dashes
     * ignored. Returns the cleaned values, or a plain message per bad field.
     *
     * @param array<string,mixed> $input bank_name, account_number, account_name
     * @return array{ok:bool, clean:array{bank_name:string, account_number:string, account_name:string}, errors:array<string,string>}
     */
    public static function validateBank(array $input): array
    {
        $bank   = trim(preg_replace('/\s+/u', ' ', (string) ($input['bank_name'] ?? '')) ?? '');
        $number = preg_replace('/[\s\-]+/', '', (string) ($input['account_number'] ?? '')) ?? '';
        $name   = trim(preg_replace('/\s+/u', ' ', (string) ($input['account_name'] ?? '')) ?? '');
        $errors = [];

        if (mb_strlen($bank) < 2 || mb_strlen($bank) > 100 || preg_match('/^[\p{L}\p{N} .&\'()\/\-]+$/u', $bank) !== 1) {
            $errors['bank_name'] = 'Enter the name of your bank.';
        }
        if (preg_match('/^\d{10}$/', $number) !== 1) {
            $errors['account_number'] = 'An account number has 10 digits.';
        }
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || preg_match('/^[\p{L} .\'\-&]+$/u', $name) !== 1) {
            $errors['account_name'] = 'Enter the name on the account.';
        }

        return ['ok' => $errors === [], 'clean' => ['bank_name' => $bank, 'account_number' => $number, 'account_name' => $name], 'errors' => $errors];
    }

    /** An account number with all but the last four digits hidden. */
    public static function maskAccount(string $accountNumber): string
    {
        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';
        if (strlen($digits) < 5) {
            return str_repeat('*', strlen($digits));
        }
        return str_repeat('*', strlen($digits) - 4) . substr($digits, -4);
    }

    /** The destination in one line, safe to put in an email: bank, last four digits, name. */
    public static function bankLine(array $refund): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($refund['account_number'] ?? '')) ?? '';
        return trim((string) ($refund['bank_name'] ?? '')) . ', account ending ' . substr($digits, -4)
            . ' (' . trim((string) ($refund['account_name'] ?? '')) . ')';
    }

    /** Why a refund exists, as staff read it. */
    public static function kindLabel(string $kind): string
    {
        return $kind === self::KIND_CASHOUT ? 'Wallet cash out' : 'Out of stock refund';
    }

    // -------------------------------------------------------------------------
    // Raising one
    // -------------------------------------------------------------------------

    /**
     * A shortage refund, written inside the transaction that settles the
     * shortage. The shortage id is UNIQUE here, so one shortage can only ever
     * have one refund waiting.
     *
     * @param array{bank_name:string, account_number:string, account_name:string} $bank already validated
     * @return array{id:int, refund_number:string}
     */
    public static function createForShortage(int $shortageId, int $orderId, ?int $userId, int $amountSubunit, array $bank, string $byType, ?int $byUser): array
    {
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('manual refund created outside a transaction');
        }
        $number = OrderNumber::nextManualRefundNumber($pdo);
        Database::run(
            'INSERT INTO manual_refunds
                (refund_number, kind, user_id, order_id, shortage_id, amount_subunit, bank_name, account_number,
                 account_name, status, requested_by_type, requested_by)
             VALUES (:number, :kind, :user, :order, :shortage, :amount, :bank, :account, :name, :status, :type, :by)',
            [
                ':number' => $number, ':kind' => self::KIND_SHORTAGE, ':user' => $userId, ':order' => $orderId,
                ':shortage' => $shortageId, ':amount' => $amountSubunit, ':bank' => $bank['bank_name'],
                ':account' => $bank['account_number'], ':name' => $bank['account_name'],
                ':status' => self::STATUS_REQUESTED, ':type' => $byType, ':by' => $byUser,
            ]
        );
        $id = (int) $pdo->lastInsertId();
        Audit::record('manual_refunds.request', 'manual_refund', $id, null,
            ['kind' => self::KIND_SHORTAGE, 'amount_subunit' => $amountSubunit, 'shortage_id' => $shortageId, 'order_id' => $orderId], $byUser);
        return ['id' => $id, 'refund_number' => $number];
    }

    /**
     * A customer asks for part or all of their wallet back in their bank account.
     * The money leaves the wallet now, in the same transaction, so it cannot be
     * spent twice. $token is the form's own token: sending the form again
     * answers with the first request instead of taking the money twice.
     *
     * @param array<string,mixed> $bankInput bank_name, account_number, account_name
     * @return array{ok:bool, code:string, message:string, refund_id?:int, refund_number?:string, already?:bool, errors?:array<string,string>}
     */
    public static function requestCashout(int $userId, int $amountSubunit, array $bankInput, string $token): array
    {
        $refuse = static fn(string $code, array $extra = []): array => ['ok' => false, 'code' => $code, 'message' => self::message($code)] + $extra;
        if ($userId < 1) {
            return $refuse('not_found');
        }
        if ($amountSubunit < 1) {
            return $refuse('bad_amount');
        }
        if ($token === '' || strlen($token) > 64) {
            return $refuse('bad_token');
        }
        $bank = self::validateBank($bankInput);
        if (!$bank['ok']) {
            return ['ok' => false, 'code' => 'bad_bank', 'message' => 'Check your bank details.', 'errors' => $bank['errors']];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $debit = Wallet::debitForCashout($userId, $amountSubunit, 'cashout:' . $userId . ':' . $token, $userId);
            if ($debit['already']) {
                $existing = Database::one('SELECT id, refund_number FROM manual_refunds WHERE wallet_entry_id = :e', [':e' => $debit['entry_id']]);
                $pdo->commit();
                return ['ok' => true, 'code' => 'already_requested', 'message' => 'That request was already sent.', 'already' => true,
                    'refund_id' => (int) ($existing['id'] ?? 0), 'refund_number' => (string) ($existing['refund_number'] ?? '')];
            }
            $number = OrderNumber::nextManualRefundNumber($pdo);
            Database::run(
                'INSERT INTO manual_refunds
                    (refund_number, kind, user_id, wallet_entry_id, amount_subunit, bank_name, account_number,
                     account_name, status, requested_by_type, requested_by)
                 VALUES (:number, :kind, :user, :entry, :amount, :bank, :account, :name, :status, \'customer\', :by)',
                [
                    ':number' => $number, ':kind' => self::KIND_CASHOUT, ':user' => $userId, ':entry' => $debit['entry_id'],
                    ':amount' => $amountSubunit, ':bank' => $bank['clean']['bank_name'], ':account' => $bank['clean']['account_number'],
                    ':name' => $bank['clean']['account_name'], ':status' => self::STATUS_REQUESTED, ':by' => $userId,
                ]
            );
            $id = (int) $pdo->lastInsertId();
            Audit::record('manual_refunds.request', 'manual_refund', $id, null,
                ['kind' => self::KIND_CASHOUT, 'amount_subunit' => $amountSubunit], $userId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'requested', 'message' => 'Your request is with our team.', 'already' => false,
                'refund_id' => $id, 'refund_number' => $number];
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $refuse($e->getMessage() === 'insufficient_balance' ? 'insufficient_balance' : 'bad_amount');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Paying or cancelling one
    // -------------------------------------------------------------------------

    /**
     * Mark a refund as sent. The bank reference is what makes it findable on a
     * statement later, so it is required. A refund can only be paid once, and
     * paying a shortage refund settles the shortage.
     *
     * @return array{ok:bool, code:string, message:string, refund_id?:int}
     */
    public static function markPaid(int $refundId, string $reference, int $staffId): array
    {
        $reference = trim($reference);
        if (mb_strlen($reference) < 3 || mb_strlen($reference) > 120) {
            return ['ok' => false, 'code' => 'reference_required', 'message' => self::message('reference_required')];
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $refund = Database::one('SELECT * FROM manual_refunds WHERE id = :id FOR UPDATE', [':id' => $refundId]);
            if ($refund === null) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => self::message('not_found')];
            }
            if ((string) $refund['status'] !== self::STATUS_REQUESTED) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_requested', 'message' => self::message('not_requested')];
            }
            Database::run(
                'UPDATE manual_refunds SET status = :status, paid_by = :staff, paid_at = NOW(), payment_reference = :ref WHERE id = :id',
                [':status' => self::STATUS_PAID, ':staff' => $staffId, ':ref' => $reference, ':id' => $refundId]
            );
            if ($refund['shortage_id'] !== null) {
                Database::run(
                    'UPDATE order_shortages SET status = :settled WHERE id = :id AND status = :pending',
                    [':settled' => Shortages::STATUS_SETTLED, ':id' => (int) $refund['shortage_id'], ':pending' => Shortages::STATUS_REFUND_PENDING]
                );
            }
            Audit::record('manual_refunds.paid', 'manual_refund', $refundId,
                ['status' => self::STATUS_REQUESTED], ['status' => self::STATUS_PAID, 'reference' => $reference, 'amount_subunit' => (int) $refund['amount_subunit']], $staffId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'paid', 'message' => 'Marked as paid.', 'refund_id' => $refundId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Cancel a refund that was not sent. A wallet cash out goes back into the
     * wallet. A shortage refund goes back to awaiting the customer's choice, with
     * the same link, so wrong bank details can be corrected by choosing again.
     *
     * @return array{ok:bool, code:string, message:string, refund_id?:int}
     */
    public static function cancel(int $refundId, string $reason, int $staffId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            return ['ok' => false, 'code' => 'reason_required', 'message' => self::message('reason_required')];
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $refund = Database::one('SELECT * FROM manual_refunds WHERE id = :id FOR UPDATE', [':id' => $refundId]);
            if ($refund === null) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => self::message('not_found')];
            }
            if ((string) $refund['status'] !== self::STATUS_REQUESTED) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_requested', 'message' => self::message('not_requested')];
            }
            $note = $reason;
            if ($refund['kind'] === self::KIND_CASHOUT && $refund['user_id'] !== null) {
                Wallet::restoreCashout((int) $refund['user_id'], (int) $refund['amount_subunit'], 'cashout:' . $refundId . ':cancelled', $staffId);
            }
            if ($refund['shortage_id'] !== null) {
                $note = 'Shortage ' . (int) $refund['shortage_id'] . ': ' . $reason;
                Database::run(
                    'UPDATE order_shortages
                        SET status = :awaiting, resolution = NULL, decided_by_type = NULL, decided_by = NULL, decided_at = NULL
                      WHERE id = :id AND status = :pending',
                    [':awaiting' => Shortages::STATUS_AWAITING, ':id' => (int) $refund['shortage_id'], ':pending' => Shortages::STATUS_REFUND_PENDING]
                );
            }
            Database::run(
                'UPDATE manual_refunds
                    SET status = :status, cancelled_by = :staff, cancelled_at = NOW(), cancel_reason = :reason, shortage_id = NULL
                  WHERE id = :id',
                [':status' => self::STATUS_CANCELLED, ':staff' => $staffId, ':reason' => substr($note, 0, 200), ':id' => $refundId]
            );
            Audit::record('manual_refunds.cancel', 'manual_refund', $refundId,
                ['status' => self::STATUS_REQUESTED], ['status' => self::STATUS_CANCELLED, 'reason' => $reason], $staffId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'cancelled', 'message' => 'Cancelled.', 'refund_id' => $refundId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /** @return ?array<string,mixed> */
    public static function find(int $id): ?array
    {
        return Database::one(
            'SELECT r.*, o.order_number,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name,
                    u.email AS customer_email
               FROM manual_refunds r
               LEFT JOIN orders o ON o.id = r.order_id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.id = :id',
            [':id' => $id]
        );
    }

    /**
     * The queue, oldest waiting first for requested, newest first otherwise.
     *
     * @return list<array<string,mixed>>
     */
    public static function queue(string $status = self::STATUS_REQUESTED, int $limit = 50): array
    {
        $order = $status === self::STATUS_REQUESTED ? 'r.id ASC' : 'r.id DESC';
        return Database::all(
            'SELECT r.*, o.order_number,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name
               FROM manual_refunds r
               LEFT JOIN orders o ON o.id = r.order_id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.status = :status
              ORDER BY ' . $order . '
              LIMIT ' . max(1, min(200, $limit)),
            [':status' => $status]
        );
    }

    public static function openCount(): int
    {
        return (int) (Database::one('SELECT COUNT(*) AS c FROM manual_refunds WHERE status = :s', [':s' => self::STATUS_REQUESTED])['c'] ?? 0);
    }

    /**
     * What a customer's own wallet page shows about their cash outs: the ones
     * still waiting and the latest few that were sent.
     *
     * @return list<array<string,mixed>>
     */
    public static function forUser(int $userId, int $limit = 10): array
    {
        return Database::all(
            'SELECT id, refund_number, kind, amount_subunit, bank_name, account_number, status, created_at, paid_at
               FROM manual_refunds
              WHERE user_id = :u AND status <> :cancelled
              ORDER BY id DESC
              LIMIT ' . max(1, min(50, $limit)),
            [':u' => $userId, ':cancelled' => self::STATUS_CANCELLED]
        );
    }
}
