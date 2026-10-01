<?php
/**
 * includes/classes/Wallet.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The customer wallet: credit that came from us and can only be
 * spent with us.
 *
 * Money enters a wallet in one way, credit(): a complaint made right, a
 * cancellation refund the customer keeps, a goodwill credit, a shortage credit.
 * There are no top ups. Every credit writes a ledger row and issues a numbered
 * credit note in the same transaction, so a credit without paper cannot exist.
 *
 * Money leaves a wallet in one way, payOrder(): the customer spends it on an
 * order. It is recorded as an ordinary payment on that order (provider
 * "wallet"), so the order, the receipt, the sourcing gate and the credit line
 * settlement all see it as money received without knowing where it came from.
 *
 * Rules that hold everywhere:
 *   - The ledger (wallet_entries) is append only. Nothing is edited or deleted.
 *     Each row carries the balance after it, and the balance column on the
 *     account is only a cache of the ledger, written in the same transaction.
 *   - source_key is UNIQUE, so a retried credit or a double tapped spend writes
 *     once.
 *   - Spending locks the wallet row, so two requests can never spend the same
 *     money, and the balance is UNSIGNED so the database refuses a negative one.
 *   - Lock order is order, payments, wallet, business, everywhere.
 *   - Money is integer kobo through the Money helper. Never a float.
 *
 * A card payment overwrites the paid amount of its payment row, because one row
 * holds one charge. So a partial wallet payment never shares a row with a card
 * charge: the wallet gets its own paid row, and the row still owed is reduced by
 * the same amount (voided if nothing remains), so the rows keep adding up to the
 * order total.
 * -----------------------------------------------------------------------------
 */
final class Wallet
{
    public const ENTRY_CREDIT  = 'credit';
    public const ENTRY_SPEND   = 'spend';
    /** Money taken out to be sent to the customer's bank, and its reversal. */
    public const ENTRY_CASHOUT = 'cashout';
    public const ENTRY_RESTORE = 'cashout_reversal';

    /** Why money enters a wallet, as the customer reads it. */
    public const REASONS = [
        'complaint'    => 'Make It Right credit',
        'cancellation' => 'Refund from a cancelled order',
        'shortage'     => 'Out of stock credit',
        'goodwill'     => 'Goodwill credit',
    ];

    private const MESSAGES = [
        'not_found'           => 'That order could not be found.',
        'order_cancelled'     => 'This order has been cancelled, so there is nothing to pay.',
        'payment_in_progress' => 'A card payment is in progress on this order. Let it finish, then try again.',
        'nothing_due'         => 'There is nothing left to pay on this order.',
        'wallet_empty'        => 'Your wallet has no credit to spend.',
        'no_wallet'           => 'Sign in to use your wallet.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'We could not use your wallet just now. Please try again.';
    }

    public static function reasonLabel(string $code): string
    {
        return self::REASONS[$code] ?? 'Credit from OK Veggies';
    }

    // -------------------------------------------------------------------------
    // Pure rules, unit tested without a database
    // -------------------------------------------------------------------------

    /** Whether a credit request is well formed. */
    public static function creditIsValid(int $userId, int $amountSubunit, string $reasonCode, string $sourceKey): bool
    {
        return $userId >= 1
            && $amountSubunit >= 1
            && isset(self::REASONS[$reasonCode])
            && $sourceKey !== ''
            && strlen($sourceKey) <= 150;
    }

    /** How much of what is owed the wallet pays: the smaller of the two, never less than zero. */
    public static function planSpend(int $balanceSubunit, int $dueSubunit): int
    {
        if ($balanceSubunit < 1 || $dueSubunit < 1) {
            return 0;
        }
        return min($balanceSubunit, $dueSubunit);
    }

    /**
     * What the payment row that was owed becomes once the wallet has paid part
     * of it. A row nothing is left on is voided (expected 0, like every replaced
     * row); a row that already holds some money never drops below it.
     *
     * @return array{expected:int, status:?string} status null means unchanged
     */
    public static function reducedRow(int $expectedSubunit, int $paidSubunit, int $walletSubunit): array
    {
        $expected = $expectedSubunit - $walletSubunit;
        if ($paidSubunit < 1 && $expected <= 0) {
            return ['expected' => 0, 'status' => Payments::STATUS_VOID];
        }
        if ($expected <= $paidSubunit) {
            return ['expected' => $paidSubunit, 'status' => Payments::STATUS_PAID];
        }
        return ['expected' => $expected, 'status' => null];
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /** What this customer can spend now. Zero when they have no wallet yet. */
    public static function balance(int $userId): int
    {
        $row = Database::one('SELECT balance_subunit FROM wallet_accounts WHERE user_id = :u', [':u' => $userId]);
        return $row === null ? 0 : (int) $row['balance_subunit'];
    }

    /**
     * The ledger, newest first, each row labelled for the customer.
     *
     * @return list<array<string,mixed>>
     */
    public static function ledger(int $userId, int $limit = 50): array
    {
        $rows = Database::all(
            'SELECT e.id, e.entry_type, e.source, e.amount_subunit, e.balance_after_subunit, e.note,
                    e.created_at, o.id AS order_id, o.order_number,
                    n.id AS credit_note_id, n.credit_note_number
               FROM wallet_entries e
               LEFT JOIN orders o ON o.id = e.order_id
               LEFT JOIN credit_notes n ON n.wallet_entry_id = e.id
              WHERE e.user_id = :u
              ORDER BY e.id DESC
              LIMIT ' . max(1, min(200, $limit)),
            [':u' => $userId]
        );
        foreach ($rows as &$row) {
            $row['label'] = match ((string) $row['entry_type']) {
                self::ENTRY_SPEND   => 'Paid toward ' . ((string) ($row['order_number'] ?? '') !== '' ? 'order ' . $row['order_number'] : 'an order'),
                self::ENTRY_CASHOUT => 'Sent to your bank account',
                self::ENTRY_RESTORE => 'Cash out cancelled, back in your wallet',
                default             => self::reasonLabel((string) $row['source']),
            };
        }
        unset($row);
        return $rows;
    }

    /**
     * Everything a wallet screen draws: the balance and the latest activity.
     *
     * @return array{balance_subunit:int, entries:list<array<string,mixed>>, refunds:list<array<string,mixed>>}
     */
    public static function view(int $userId, int $limit = 50): array
    {
        return [
            'balance_subunit' => self::balance($userId),
            'entries'         => self::ledger($userId, $limit),
            // Cash outs and bank refunds this customer has asked for, newest first.
            'refunds'         => ManualRefunds::forUser($userId),
        ];
    }

    /**
     * One credit note with what a document needs to print it, or null. Access is
     * the caller's decision (the owner, or staff with wallet.view): this reads
     * only, so it can be tested without a session.
     *
     * @return ?array<string,mixed>
     */
    public static function creditNote(int $id): ?array
    {
        return Database::one(
            'SELECT n.id, n.credit_note_number, n.user_id, n.order_id, n.amount_subunit, n.reason_code,
                    n.reason_text, n.created_at, o.order_number,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name,
                    bc.business_name
               FROM credit_notes n
               JOIN users u ON u.id = n.user_id
          LEFT JOIN orders o ON o.id = n.order_id
          LEFT JOIN business_customers bc ON bc.user_id = n.user_id
              WHERE n.id = :id',
            [':id' => $id]
        );
    }

    /**
     * The ledger walked from the first row: does the cached balance agree with
     * it, and does every row's running balance agree with the one before?
     *
     * @return array{ok:bool, cached_subunit:int, ledger_subunit:int, broken_entry_id:?int}
     */
    public static function reconcile(int $userId): array
    {
        $cached = self::balance($userId);
        $run = 0;
        $broken = null;
        foreach (Database::all('SELECT id, amount_subunit, balance_after_subunit FROM wallet_entries WHERE user_id = :u ORDER BY id', [':u' => $userId]) as $row) {
            $run += (int) $row['amount_subunit'];
            if ($run !== (int) $row['balance_after_subunit'] && $broken === null) {
                $broken = (int) $row['id'];
            }
        }
        return ['ok' => $broken === null && $run === $cached, 'cached_subunit' => $cached, 'ledger_subunit' => $run, 'broken_entry_id' => $broken];
    }

    /**
     * What paying an order from the wallet would do, without changing anything.
     * Null when the wallet has nothing to give or the order owes nothing the
     * wallet can pay. Used to decide whether to offer the button and to word it.
     *
     * @return ?array{balance_subunit:int, due_subunit:int, apply_subunit:int, remaining_subunit:int, covers_all:bool, payment_id:int}
     */
    public static function preview(int $orderId, string $paymentOption, int $userId): ?array
    {
        $balance = self::balance($userId);
        if ($balance < 1) {
            return null;
        }
        $target = self::targetPayment($orderId, $paymentOption, false);
        if ($target === null) {
            return null;
        }
        $apply = self::planSpend($balance, $target['due']);
        if ($apply < 1) {
            return null;
        }
        return [
            'balance_subunit'   => $balance,
            'due_subunit'       => $target['due'],
            'apply_subunit'     => $apply,
            'remaining_subunit' => $target['due'] - $apply,
            'covers_all'        => $apply >= $target['due'],
            'payment_id'        => (int) $target['row']['id'],
        ];
    }

    // -------------------------------------------------------------------------
    // Money in
    // -------------------------------------------------------------------------

    /**
     * Credit a wallet and issue the credit note for it. Runs inside the caller's
     * transaction, so the credit lands or fails together with whatever caused
     * it (a complaint being resolved, an order being cancelled).
     *
     * Idempotent on $sourceKey: a second call with the same key writes nothing
     * and answers with the first call's entry and credit note.
     *
     * @throws DomainException invalid_wallet_credit, not_found
     * @return array{already:bool, entry_id:int, credit_note_id:int, credit_note_number:string, amount_subunit:int, balance_after_subunit:int}
     */
    public static function credit(
        int $userId,
        int $amountSubunit,
        string $reasonCode,
        string $reasonText,
        string $sourceKey,
        ?int $orderId = null,
        ?int $actorId = null
    ): array {
        if (!self::creditIsValid($userId, $amountSubunit, $reasonCode, $sourceKey)) {
            throw new DomainException('invalid_wallet_credit');
        }
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('wallet credit called outside a transaction');
        }

        $account = self::lockedAccount($userId);

        // Read after the lock, so two requests racing on one key serialise and
        // the second finds the first's row instead of failing on the unique key.
        $existing = Database::one(
            'SELECT e.id, e.amount_subunit, e.balance_after_subunit, n.id AS note_id, n.credit_note_number
               FROM wallet_entries e
               LEFT JOIN credit_notes n ON n.wallet_entry_id = e.id
              WHERE e.source_key = :key',
            [':key' => $sourceKey]
        );
        if ($existing !== null) {
            return [
                'already'               => true,
                'entry_id'              => (int) $existing['id'],
                'credit_note_id'        => (int) ($existing['note_id'] ?? 0),
                'credit_note_number'    => (string) ($existing['credit_note_number'] ?? ''),
                'amount_subunit'        => abs((int) $existing['amount_subunit']),
                'balance_after_subunit' => (int) $existing['balance_after_subunit'],
            ];
        }

        $reasonText = substr(trim($reasonText), 0, 255);
        $after = (int) $account['balance_subunit'] + $amountSubunit;

        Database::run(
            'INSERT INTO wallet_entries
                (wallet_account_id, user_id, entry_type, source, source_key, amount_subunit,
                 balance_after_subunit, order_id, note, created_by)
             VALUES (:account, :user, :type, :source, :key, :amount, :after, :order, :note, :actor)',
            [
                ':account' => (int) $account['id'],
                ':user'    => $userId,
                ':type'    => self::ENTRY_CREDIT,
                ':source'  => $reasonCode,
                ':key'     => $sourceKey,
                ':amount'  => $amountSubunit,
                ':after'   => $after,
                ':order'   => $orderId,
                ':note'    => $reasonText !== '' ? $reasonText : null,
                ':actor'   => $actorId,
            ]
        );
        $entryId = (int) $pdo->lastInsertId();
        Database::run('UPDATE wallet_accounts SET balance_subunit = :balance WHERE id = :id', [':balance' => $after, ':id' => (int) $account['id']]);

        $number = OrderNumber::nextCreditNoteNumber($pdo);
        Database::run(
            'INSERT INTO credit_notes
                (credit_note_number, user_id, order_id, wallet_entry_id, amount_subunit, reason_code, reason_text, issued_by)
             VALUES (:number, :user, :order, :entry, :amount, :reason, :text, :actor)',
            [
                ':number' => $number,
                ':user'   => $userId,
                ':order'  => $orderId,
                ':entry'  => $entryId,
                ':amount' => $amountSubunit,
                ':reason' => $reasonCode,
                ':text'   => $reasonText !== '' ? $reasonText : null,
                ':actor'  => $actorId,
            ]
        );
        $noteId = (int) $pdo->lastInsertId();

        Audit::record(
            'wallet.credit',
            'wallet_entry',
            $entryId,
            null,
            ['user_id' => $userId, 'amount_subunit' => $amountSubunit, 'reason' => $reasonCode,
             'order_id' => $orderId, 'credit_note' => $number],
            $actorId
        );

        return [
            'already'               => false,
            'entry_id'              => $entryId,
            'credit_note_id'        => $noteId,
            'credit_note_number'    => $number,
            'amount_subunit'        => $amountSubunit,
            'balance_after_subunit' => $after,
        ];
    }

    /**
     * credit() in its own transaction, for a caller that has none (a staff
     * member giving goodwill credit). Refusals come back as a plain result.
     *
     * @return array{ok:bool, code:string, message:string, entry_id?:int, credit_note_id?:int, credit_note_number?:string, already?:bool}
     */
    public static function creditNow(
        int $userId,
        int $amountSubunit,
        string $reasonCode,
        string $reasonText,
        string $sourceKey,
        ?int $orderId = null,
        ?int $actorId = null
    ): array {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $credit = self::credit($userId, $amountSubunit, $reasonCode, $reasonText, $sourceKey, $orderId, $actorId);
            $pdo->commit();
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'code' => $e->getMessage(), 'message' => 'That credit could not be added. Check the customer and the amount.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return ['ok' => true, 'code' => $credit['already'] ? 'already_credited' : 'credited', 'message' => ''] + $credit;
    }

    // -------------------------------------------------------------------------
    // Money out: a cash out to the customer's bank, and putting it back
    // -------------------------------------------------------------------------

    /**
     * Take money out of the wallet to be sent to the customer's bank by hand.
     * The money leaves the balance now, so it cannot also be spent while the
     * transfer is waiting. Runs inside the caller's transaction, locks the
     * wallet, and never takes more than it holds. Idempotent on $sourceKey.
     *
     * @throws DomainException insufficient_balance, invalid_amount, not_found
     * @return array{already:bool, entry_id:int, amount_subunit:int, balance_after_subunit:int}
     */
    public static function debitForCashout(int $userId, int $amountSubunit, string $sourceKey, ?int $actorId = null): array
    {
        if ($userId < 1 || $amountSubunit < 1 || $sourceKey === '' || strlen($sourceKey) > 150) {
            throw new DomainException('invalid_amount');
        }
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('wallet cash out called outside a transaction');
        }
        $account = self::lockedAccount($userId);
        $existing = Database::one('SELECT id, amount_subunit, balance_after_subunit FROM wallet_entries WHERE source_key = :key', [':key' => $sourceKey]);
        if ($existing !== null) {
            return ['already' => true, 'entry_id' => (int) $existing['id'], 'amount_subunit' => abs((int) $existing['amount_subunit']), 'balance_after_subunit' => (int) $existing['balance_after_subunit']];
        }
        $balance = (int) $account['balance_subunit'];
        if ($amountSubunit > $balance) {
            throw new DomainException('insufficient_balance');
        }
        $after = $balance - $amountSubunit;
        Database::run(
            'INSERT INTO wallet_entries
                (wallet_account_id, user_id, entry_type, source, source_key, amount_subunit, balance_after_subunit, note, created_by)
             VALUES (:account, :user, :type, \'cashout\', :key, :amount, :after, \'Cash out to bank\', :actor)',
            [':account' => (int) $account['id'], ':user' => $userId, ':type' => self::ENTRY_CASHOUT, ':key' => $sourceKey,
             ':amount' => -$amountSubunit, ':after' => $after, ':actor' => $actorId]
        );
        $entryId = (int) $pdo->lastInsertId();
        Database::run('UPDATE wallet_accounts SET balance_subunit = :b WHERE id = :id', [':b' => $after, ':id' => (int) $account['id']]);
        return ['already' => false, 'entry_id' => $entryId, 'amount_subunit' => $amountSubunit, 'balance_after_subunit' => $after];
    }

    /**
     * Put a cash out back when it is cancelled before the money was sent. It is
     * the customer's own money returning, not new credit from us, so it gets a
     * ledger row and no credit note. Idempotent on $sourceKey.
     *
     * @return array{already:bool, entry_id:int, balance_after_subunit:int}
     */
    public static function restoreCashout(int $userId, int $amountSubunit, string $sourceKey, ?int $actorId = null): array
    {
        if ($userId < 1 || $amountSubunit < 1 || $sourceKey === '' || strlen($sourceKey) > 150) {
            throw new DomainException('invalid_amount');
        }
        $pdo = Database::getInstance()->getConnection();
        if (!$pdo->inTransaction()) {
            throw new LogicException('wallet restore called outside a transaction');
        }
        $account = self::lockedAccount($userId);
        $existing = Database::one('SELECT id, balance_after_subunit FROM wallet_entries WHERE source_key = :key', [':key' => $sourceKey]);
        if ($existing !== null) {
            return ['already' => true, 'entry_id' => (int) $existing['id'], 'balance_after_subunit' => (int) $existing['balance_after_subunit']];
        }
        $after = (int) $account['balance_subunit'] + $amountSubunit;
        Database::run(
            'INSERT INTO wallet_entries
                (wallet_account_id, user_id, entry_type, source, source_key, amount_subunit, balance_after_subunit, note, created_by)
             VALUES (:account, :user, :type, \'cashout_reversal\', :key, :amount, :after, \'Cash out cancelled\', :actor)',
            [':account' => (int) $account['id'], ':user' => $userId, ':type' => self::ENTRY_RESTORE, ':key' => $sourceKey,
             ':amount' => $amountSubunit, ':after' => $after, ':actor' => $actorId]
        );
        $entryId = (int) $pdo->lastInsertId();
        Database::run('UPDATE wallet_accounts SET balance_subunit = :b WHERE id = :id', [':b' => $after, ':id' => (int) $account['id']]);
        return ['already' => false, 'entry_id' => $entryId, 'balance_after_subunit' => $after];
    }

    // -------------------------------------------------------------------------
    // Money out: paying an order
    // -------------------------------------------------------------------------

    /**
     * Pay an order, or the part of it the wallet can cover, from the wallet.
     *
     * Only the caller's own order. Refused while a card attempt on the order is
     * unresolved, because that charge could arrive after the payment row was
     * reshaped. On success the order is recomputed exactly as it is after a card
     * payment, so the sourcing gate, the receipt and the credit line settlement
     * need no special case.
     *
     * @return array{ok:bool, code:string, message:string, amount_subunit?:int, remaining_subunit?:int, payment_id?:int, target_payment_id?:int, balance_subunit?:int}
     */
    public static function payOrder(int $orderId, int $userId, ?int $actorId = null): array
    {
        $refuse = static fn(string $code): array => ['ok' => false, 'code' => $code, 'message' => self::message($code)];
        if ($orderId < 1 || $userId < 1) {
            return $refuse('not_found');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $order = Database::one(
                'SELECT o.id, o.user_id, o.order_number, o.order_status, o.payment_option, o.contact_email,
                        u.email AS user_email
                   FROM orders o LEFT JOIN users u ON u.id = o.user_id
                  WHERE o.id = :id FOR UPDATE',
                [':id' => $orderId]
            );
            if ($order === null || (int) ($order['user_id'] ?? 0) !== $userId) {
                $pdo->rollBack();
                return $refuse('not_found');
            }
            if ((string) $order['order_status'] === 'cancelled') {
                $pdo->rollBack();
                return $refuse('order_cancelled');
            }

            $target = self::targetPayment($orderId, (string) $order['payment_option'], true);
            if ($target === null) {
                $pdo->rollBack();
                return $refuse('nothing_due');
            }
            $moving = Database::one(
                'SELECT t.id
                   FROM payment_transactions t
                   JOIN payments p ON p.id = t.payment_id
                  WHERE p.order_id = :order AND t.status IN (:initialized, :unknown)
                  LIMIT 1
                  FOR UPDATE',
                [':order' => $orderId, ':initialized' => Payments::TXN_INITIALIZED, ':unknown' => Payments::TXN_UNKNOWN]
            );
            if ($moving !== null) {
                $pdo->rollBack();
                return $refuse('payment_in_progress');
            }

            $account = self::lockedAccount($userId);
            $balance = (int) $account['balance_subunit'];
            $amount  = self::planSpend($balance, $target['due']);
            if ($amount < 1) {
                $pdo->rollBack();
                return $refuse($balance < 1 ? 'wallet_empty' : 'nothing_due');
            }

            $row      = $target['row'];
            $rowId    = (int) $row['id'];
            $sequence = 1 + (int) (Database::one(
                'SELECT COUNT(*) AS c FROM payments WHERE order_id = :o AND provider = \'wallet\'',
                [':o' => $orderId]
            )['c'] ?? 0);
            $number = (string) $order['order_number'];

            // The row that holds the wallet's money.
            Database::run(
                'INSERT INTO payments
                    (payment_number, user_id, order_id, provider, payment_type, expected_amount_subunit,
                     paid_amount_subunit, currency, status, confirmed_at)
                 VALUES (:number, :user, :order, \'wallet\', \'wallet\', :amount, :amount2, :currency, :status, NOW())',
                [
                    ':number'   => 'PAY-' . $number . '-W' . $sequence,
                    ':user'     => $userId,
                    ':order'    => $orderId,
                    ':amount'   => $amount,
                    ':amount2'  => $amount,
                    ':currency' => Money::CODE,
                    ':status'   => Payments::STATUS_PAID,
                ]
            );
            $walletPaymentId = (int) $pdo->lastInsertId();

            $email = trim((string) ($order['user_email'] ?? '')) ?: trim((string) ($order['contact_email'] ?? ''));
            Database::run(
                'INSERT INTO payment_transactions
                    (payment_id, attempt_number, provider, reference, domain, status,
                     requested_amount_subunit, amount_subunit, currency, customer_email,
                     channel, gateway_response, paid_at, verified_at)
                 VALUES (:pid, 1, \'wallet\', :ref, :domain, \'success\',
                         :amount, :amount2, :currency, :email,
                         \'wallet\', :note, NOW(), NOW())',
                [
                    ':pid'      => $walletPaymentId,
                    ':ref'      => 'WAL-' . $number . '-' . $sequence,
                    ':domain'   => Paystack::domain(),
                    ':amount'   => $amount,
                    ':amount2'  => $amount,
                    ':currency' => Money::CODE,
                    ':email'    => substr($email, 0, 255) ?: 'unknown@okveggies.invalid',
                    ':note'     => 'Paid from the customer wallet.',
                ]
            );
            $txnId = (int) $pdo->lastInsertId();
            Payments::writeHistory($walletPaymentId, $txnId, null, Payments::STATUS_PAID, 'wallet', null, 'Paid from the customer wallet.');

            // The row that was owed shrinks by what the wallet paid.
            $shape = self::reducedRow((int) $row['expected_amount_subunit'], (int) $row['paid_amount_subunit'], $amount);
            if ($shape['status'] === null) {
                Database::run('UPDATE payments SET expected_amount_subunit = :e WHERE id = :id', [':e' => $shape['expected'], ':id' => $rowId]);
            } elseif ($shape['status'] === Payments::STATUS_PAID) {
                Database::run(
                    'UPDATE payments SET expected_amount_subunit = :e, status = :s, confirmed_at = COALESCE(confirmed_at, NOW()) WHERE id = :id',
                    [':e' => $shape['expected'], ':s' => $shape['status'], ':id' => $rowId]
                );
            } else {
                Database::run('UPDATE payments SET expected_amount_subunit = :e, status = :s WHERE id = :id', [':e' => $shape['expected'], ':s' => $shape['status'], ':id' => $rowId]);
            }
            Payments::writeHistory(
                $rowId,
                null,
                (string) $row['status'],
                $shape['status'] ?? (string) $row['status'],
                'wallet',
                null,
                $shape['status'] === Payments::STATUS_VOID
                    ? 'Replaced by a payment from the wallet.'
                    : 'Expected amount reduced by ' . Money::format($amount) . ' paid from the wallet.'
            );

            // The ledger row, and the cache, in the same transaction.
            $after = $balance - $amount;
            Database::run(
                'INSERT INTO wallet_entries
                    (wallet_account_id, user_id, entry_type, source, source_key, amount_subunit,
                     balance_after_subunit, order_id, payment_transaction_id, note, created_by)
                 VALUES (:account, :user, :type, \'order_payment\', :key, :amount, :after, :order, :txn, :note, :actor)',
                [
                    ':account' => (int) $account['id'],
                    ':user'    => $userId,
                    ':type'    => self::ENTRY_SPEND,
                    ':key'     => 'order:' . $orderId . ':wallet:' . $sequence,
                    ':amount'  => -$amount,
                    ':after'   => $after,
                    ':order'   => $orderId,
                    ':txn'     => $txnId,
                    ':note'    => 'Paid toward order ' . $number,
                    ':actor'   => $actorId,
                ]
            );
            Database::run('UPDATE wallet_accounts SET balance_subunit = :b WHERE id = :id', [':b' => $after, ':id' => (int) $account['id']]);

            Payments::recomputeOrder($orderId);

            Audit::record(
                'wallet.spend',
                'order',
                $orderId,
                ['wallet_subunit' => $balance],
                ['wallet_subunit' => $after, 'amount_subunit' => $amount, 'payment_id' => $walletPaymentId],
                $actorId ?? $userId
            );
            $pdo->commit();

            $remaining = $target['due'] - $amount;
            return [
                'ok'                => true,
                'code'              => $remaining < 1 ? 'paid' : 'part_paid',
                'message'           => $remaining < 1
                    ? Money::format($amount) . ' paid from your wallet.'
                    : Money::format($amount) . ' paid from your wallet. ' . Money::format($remaining) . ' is left to pay.',
                'amount_subunit'    => $amount,
                'remaining_subunit' => $remaining,
                'payment_id'        => $remaining < 1 ? $walletPaymentId : $rowId,
                'target_payment_id' => $rowId,
                'balance_subunit'   => $after,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * The payment row a wallet payment reduces, and how much is owed on it.
     * The same row the card button would charge: the account row for an order
     * on the credit line (capped at what the ledger still shows open), the first
     * unpaid Paystack row for any other order.
     *
     * @return ?array{row:array<string,mixed>, due:int}
     */
    private static function targetPayment(int $orderId, string $paymentOption, bool $lock): ?array
    {
        $rows = Database::all(
            'SELECT id, provider, payment_type, expected_amount_subunit, paid_amount_subunit, status
               FROM payments WHERE order_id = :o ORDER BY id' . ($lock ? ' FOR UPDATE' : ''),
            [':o' => $orderId]
        );
        $provider = $paymentOption === 'on_account' ? 'account' : 'paystack';
        foreach ($rows as $row) {
            if ((string) $row['provider'] !== $provider
                || in_array((string) $row['status'], [Payments::STATUS_PAID, Payments::STATUS_VOID], true)
                || (int) $row['expected_amount_subunit'] <= (int) $row['paid_amount_subunit']
            ) {
                continue;
            }
            $credit = $provider === 'account' ? (OrderMoney::creditFor([$orderId])[$orderId] ?? null) : null;
            $due = Payments::dueFor($row, $credit);
            return $due > 0 ? ['row' => $row, 'due' => $due] : null;
        }
        return null;
    }

    /**
     * The customer's wallet row, created on first use and locked for the rest of
     * the transaction. INSERT IGNORE is what makes the first use safe when two
     * requests arrive together.
     *
     * @return array<string,mixed>
     * @throws DomainException not_found when the user does not exist
     */
    private static function lockedAccount(int $userId): array
    {
        Database::run('INSERT IGNORE INTO wallet_accounts (user_id) VALUES (:u)', [':u' => $userId]);
        $account = Database::one('SELECT id, user_id, balance_subunit FROM wallet_accounts WHERE user_id = :u FOR UPDATE', [':u' => $userId]);
        if ($account === null) {
            throw new DomainException('not_found');
        }
        return $account;
    }
}
