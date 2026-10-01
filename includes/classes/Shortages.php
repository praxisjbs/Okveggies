<?php
/**
 * includes/classes/Shortages.php
 * -----------------------------------------------------------------------------
 * OK Veggies. An order line that cannot be sourced.
 *
 * During sourcing a colleague finds an item is out of stock and marks it short:
 * a quantity of it, or the whole line. That does three things in one locked
 * transaction.
 *
 *   1. The line and the order total come down by what is missing, so the
 *      invoice, the packing list and what the customer owes all say what will
 *      actually be delivered. What was ordered is kept on the shortage row.
 *   2. The value comes off whatever is still unpaid first (a deposit order's
 *      balance, a pay on delivery amount, a credit charge). Nothing is owed back
 *      for money that was never paid.
 *   3. Whatever the customer has paid beyond what they now owe is theirs. They
 *      are emailed a link with two buttons: into the wallet at once, or to their
 *      bank account, which a colleague sends by hand. Staff can choose for them.
 *      Nothing is decided for them: it waits for a choice.
 *
 * Money rules that hold everywhere: integer kobo, append only history, every
 * write inside one transaction that holds the order row first. A shortage can
 * be undone only while no money has moved because of it.
 * -----------------------------------------------------------------------------
 */
final class Shortages
{
    public const STATUS_AWAITING       = 'awaiting_choice';
    public const STATUS_REFUND_PENDING = 'refund_pending';
    public const STATUS_SETTLED        = 'settled';
    public const STATUS_WITHDRAWN      = 'withdrawn';

    public const RESOLUTION_REDUCED = 'reduced';
    public const RESOLUTION_WALLET  = 'wallet';
    public const RESOLUTION_BANK    = 'bank';

    /** An item can be marked short until the order is packed. */
    public const STAGES = ['pending', 'confirmed'];

    private const MESSAGES = [
        'not_found'           => 'That order line could not be found.',
        'not_sourcing'        => 'An item can only be marked short before the order is packed.',
        'already_short'       => 'This line is already fully out of stock.',
        'bad_quantity'        => 'Enter a quantity between 0.001 and what is left on the line.',
        'payment_in_progress' => 'A card payment on this order is still being checked. Try again in a moment.',
        'receipt_waiting'     => 'A bank transfer receipt is waiting to be verified on this order. Verify or decline it first.',
        'already_decided'     => 'This choice has already been made.',
        'not_awaiting'        => 'This shortage is not waiting for a choice.',
        'no_account'          => 'The wallet needs an account. Choose a refund to your bank instead.',
        'bad_bank'            => 'Check your bank details.',
        'cannot_undo'         => 'Money has moved on this order since, so it cannot be undone here.',
        'bad_choice'          => 'Choose the wallet or your bank account.',
    ];

    public static function message(string $code): string
    {
        return self::MESSAGES[$code] ?? 'We could not do that just now. Please try again.';
    }

    // -------------------------------------------------------------------------
    // Pure rules, unit tested without a database
    // -------------------------------------------------------------------------

    /**
     * Whether a quantity can be marked short on a line holding $current. The
     * quantity has at most three decimals, is more than nothing, and is no more
     * than the line has left. Returns '' when it is fine, otherwise a code.
     */
    public static function quantityError(string $short, string $current): string
    {
        if (preg_match('/^\d+(\.\d{1,3})?$/', trim($short)) !== 1) {
            return 'bad_quantity';
        }
        $shortF = (float) $short;
        if ($shortF <= 0.0 || round($shortF, 3) > round((float) $current, 3)) {
            return 'bad_quantity';
        }
        return '';
    }

    /**
     * What a missing quantity is worth. The same rounding the line was built with
     * (quantity times unit price). The last of a line is worth exactly what is
     * left of it, so no kobo is stranded by rounding, and no shortage is ever
     * worth more than the line.
     */
    public static function shortAmount(string $short, string $current, int $unitPriceSubunit, int $lineTotalSubunit): int
    {
        if (round((float) $short, 3) >= round((float) $current, 3)) {
            return $lineTotalSubunit;
        }
        return min($lineTotalSubunit, Money::lineTotal($short, $unitPriceSubunit));
    }

    /**
     * Take $amount off what is still unpaid, newest payment row first. A row
     * that ends with nothing left to pay and nothing paid is voided (expected
     * 0), a row that already holds money never drops below it, and what the
     * unpaid rows could not absorb is what the customer has overpaid.
     *
     * @param list<array<string,mixed>> $rows id, status, expected_amount_subunit, paid_amount_subunit, in id order
     * @return array{reductions:list<array<string,mixed>>, reduced:int, excess:int}
     */
    public static function plan(array $rows, int $amount): array
    {
        $left = max(0, $amount);
        $reductions = [];
        foreach (array_reverse($rows) as $row) {
            if ($left < 1) {
                break;
            }
            $status   = (string) $row['status'];
            $expected = (int) $row['expected_amount_subunit'];
            $paid     = (int) $row['paid_amount_subunit'];
            if ($status === Payments::STATUS_PAID || $status === Payments::STATUS_VOID || $expected <= $paid) {
                continue;
            }
            $take  = min($left, $expected - $paid);
            $after = $expected - $take;
            $newStatus = null;
            if ($paid < 1 && $after < 1) {
                $newStatus = Payments::STATUS_VOID;
            } elseif ($after <= $paid) {
                $newStatus = Payments::STATUS_PAID;
            }
            $reductions[] = [
                'payment_id'      => (int) $row['id'],
                'expected_before' => $expected,
                'expected_after'  => $after,
                'status_before'   => $status,
                'status_after'    => $newStatus ?? $status,
            ];
            $left -= $take;
        }
        $reduced = max(0, $amount) - $left;
        return ['reductions' => $reductions, 'reduced' => $reduced, 'excess' => $left];
    }

    /** "2 kg Tomatoes", the way the customer reads a line. */
    public static function itemLine(string $quantity, string $unit, string $name): string
    {
        $q = rtrim(rtrim(number_format((float) $quantity, 3, '.', ''), '0'), '.');
        return trim(($q === '' ? '0' : $q) . ' ' . $unit . ' ' . $name);
    }

    /** Where a shortage stands, for staff. */
    public static function statusLabel(string $status, ?string $resolution = null): string
    {
        return match ($status) {
            self::STATUS_AWAITING       => 'Waiting for the customer',
            self::STATUS_REFUND_PENDING => 'Refund to pay',
            self::STATUS_WITHDRAWN      => 'Undone',
            self::STATUS_SETTLED        => match ($resolution) {
                self::RESOLUTION_WALLET  => 'Added to wallet',
                self::RESOLUTION_BANK    => 'Refund sent',
                default                  => 'Taken off the balance',
            },
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    // -------------------------------------------------------------------------
    // Marking a line short
    // -------------------------------------------------------------------------

    /**
     * Mark a quantity of an order line, or all of it, short.
     *
     * @param string $quantity a decimal quantity, or "all"
     * @return array{ok:bool, code:string, message:string, shortage_id?:int, amount_subunit?:int, reduced_subunit?:int,
     *               refund_due_subunit?:int, needs_choice?:bool, token?:?string, item_line?:string}
     */
    public static function record(int $orderId, int $itemId, string $quantity, string $reason, int $staffId): array
    {
        $refuse = static fn(string $code): array => ['ok' => false, 'code' => $code, 'message' => self::message($code)];
        $reason = mb_substr(trim($reason), 0, 200);

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $order = Database::one(
                'SELECT id, user_id, order_number, order_status, payment_option, contact_email
                   FROM orders WHERE id = :id FOR UPDATE',
                [':id' => $orderId]
            );
            if ($order === null) {
                $pdo->rollBack();
                return $refuse('not_found');
            }
            if (!in_array((string) $order['order_status'], self::STAGES, true)) {
                $pdo->rollBack();
                return $refuse('not_sourcing');
            }
            $item = Database::one(
                'SELECT id, item_name, unit_name, quantity, unit_price_subunit, line_total_subunit
                   FROM order_items WHERE id = :id AND order_id = :order FOR UPDATE',
                [':id' => $itemId, ':order' => $orderId]
            );
            if ($item === null) {
                $pdo->rollBack();
                return $refuse('not_found');
            }
            $current = (string) $item['quantity'];
            if (round((float) $current, 3) <= 0.0) {
                $pdo->rollBack();
                return $refuse('already_short');
            }
            $short = strtolower(trim($quantity)) === 'all' ? $current : trim($quantity);
            if (self::quantityError($short, $current) !== '') {
                $pdo->rollBack();
                return $refuse('bad_quantity');
            }
            $lineTotal = (int) $item['line_total_subunit'];
            $amount    = self::shortAmount($short, $current, (int) $item['unit_price_subunit'], $lineTotal);
            if ($amount < 1) {
                $pdo->rollBack();
                return $refuse('bad_quantity');
            }

            $rows = Database::all(
                'SELECT id, status, expected_amount_subunit, paid_amount_subunit
                   FROM payments WHERE order_id = :o ORDER BY id FOR UPDATE',
                [':o' => $orderId]
            );
            $moving = Database::one(
                'SELECT t.id FROM payment_transactions t JOIN payments p ON p.id = t.payment_id
                  WHERE p.order_id = :o AND t.status IN (:initialized, :unknown) LIMIT 1 FOR UPDATE',
                [':o' => $orderId, ':initialized' => Payments::TXN_INITIALIZED, ':unknown' => Payments::TXN_UNKNOWN]
            );
            if ($moving !== null) {
                $pdo->rollBack();
                return $refuse('payment_in_progress');
            }
            $waiting = Database::one(
                'SELECT mp.id
                   FROM manual_payment_proofs mp
                   JOIN payment_transactions t ON t.id = mp.payment_transaction_id
                   JOIN payments p ON p.id = t.payment_id
                  WHERE p.order_id = :o AND mp.status = :submitted LIMIT 1',
                [':o' => $orderId, ':submitted' => TransferProofs::PROOF_SUBMITTED]
            );
            if ($waiting !== null) {
                $pdo->rollBack();
                return $refuse('receipt_waiting');
            }

            $plan = self::plan($rows, $amount);
            $needsChoice = $plan['excess'] > 0;
            $token = $needsChoice ? bin2hex(random_bytes(24)) : null;
            $itemLine = self::itemLine($short, (string) $item['unit_name'], (string) $item['item_name']);

            Database::run(
                'INSERT INTO order_shortages
                    (order_id, order_item_id, short_quantity, original_quantity, original_line_total_subunit, amount_subunit,
                     reduced_subunit, refund_due_subunit, status, resolution, reason, adjustment, token_hash, recorded_by)
                 VALUES (:order, :item, :short, :orig, :origtotal, :amount, :reduced, :due, :status, :resolution, :reason,
                         :adjustment, :token, :staff)',
                [
                    ':order' => $orderId, ':item' => $itemId, ':short' => $short, ':orig' => $current, ':origtotal' => $lineTotal,
                    ':amount' => $amount, ':reduced' => $plan['reduced'], ':due' => $plan['excess'],
                    ':status' => $needsChoice ? self::STATUS_AWAITING : self::STATUS_SETTLED,
                    ':resolution' => $needsChoice ? null : self::RESOLUTION_REDUCED,
                    ':reason' => $reason !== '' ? $reason : null,
                    ':adjustment' => json_encode(['payments' => $plan['reductions'], 'credit' => $order['payment_option'] === 'on_account']),
                    ':token' => $token === null ? null : hash('sha256', $token),
                    ':staff' => $staffId,
                ]
            );
            $shortageId = (int) $pdo->lastInsertId();

            foreach ($plan['reductions'] as $r) {
                Database::run(
                    'UPDATE payments
                        SET expected_amount_subunit = :e, status = :s,
                            confirmed_at = CASE WHEN :s2 = \'paid\' THEN COALESCE(confirmed_at, NOW()) ELSE confirmed_at END
                      WHERE id = :id',
                    [':e' => $r['expected_after'], ':s' => $r['status_after'], ':s2' => $r['status_after'], ':id' => $r['payment_id']]
                );
                Payments::writeHistory(
                    (int) $r['payment_id'], null, (string) $r['status_before'], (string) $r['status_after'], 'shortage', null,
                    'Expected amount reduced by ' . Money::format($r['expected_before'] - $r['expected_after']) . ': ' . $itemLine . ' is out of stock.'
                );
            }

            $newQuantity = number_format(max(0.0, round((float) $current - (float) $short, 3)), 3, '.', '');
            Database::run(
                'UPDATE order_items SET quantity = :q, line_total_subunit = :t WHERE id = :id',
                [':q' => $newQuantity, ':t' => $lineTotal - $amount, ':id' => $itemId]
            );
            Database::run(
                'UPDATE orders
                    SET subtotal_subunit = GREATEST(subtotal_subunit - :a, 0),
                        order_total_subunit = GREATEST(order_total_subunit - :a2, 0)
                  WHERE id = :id',
                [':a' => $amount, ':a2' => $amount, ':id' => $orderId]
            );
            if ((string) $order['payment_option'] === 'on_account') {
                Credit::adjustShortage($orderId, $shortageId, $amount);
            }
            Payments::recomputeOrder($orderId);

            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, note, changed_by)
                 VALUES (:order, :status, :status2, \'staff\', :note, :staff)',
                [
                    ':order' => $orderId, ':status' => $order['order_status'], ':status2' => $order['order_status'],
                    ':note' => substr('Out of stock: ' . $itemLine . '. ' . Money::format($amount) . ' taken off the order.'
                        . ($reason !== '' ? ' ' . $reason : ''), 0, 500),
                    ':staff' => $staffId,
                ]
            );
            Audit::record(
                'orders.shortage',
                'order',
                $orderId,
                ['line_total_subunit' => $lineTotal, 'quantity' => $current],
                ['shortage_id' => $shortageId, 'short_quantity' => $short, 'amount_subunit' => $amount,
                 'reduced_subunit' => $plan['reduced'], 'refund_due_subunit' => $plan['excess']],
                $staffId
            );
            $pdo->commit();

            return [
                'ok' => true, 'code' => $needsChoice ? 'recorded_choice' : 'recorded_reduced',
                'message' => $needsChoice
                    ? 'Marked short. The customer has been asked how to return ' . Money::format($plan['excess']) . '.'
                    : 'Marked short. ' . Money::format($amount) . ' came off what the customer owes.',
                'shortage_id' => $shortageId, 'amount_subunit' => $amount, 'reduced_subunit' => $plan['reduced'],
                'refund_due_subunit' => $plan['excess'], 'needs_choice' => $needsChoice, 'token' => $token, 'item_line' => $itemLine,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Choosing what happens to the money
    // -------------------------------------------------------------------------

    /**
     * Settle a shortage that is waiting for a choice: into the wallet now, or a
     * refund to a bank account that staff send by hand. The customer chooses from
     * their link; a colleague can choose for them.
     *
     * @param array<string,mixed> $bankInput bank_name, account_number, account_name (bank only)
     * @return array{ok:bool, code:string, message:string, resolution?:string, refund_id?:int, errors?:array<string,string>}
     */
    public static function decide(int $shortageId, string $choice, array $bankInput, string $byType, ?int $byUserId): array
    {
        $refuse = static fn(string $code, array $extra = []): array => ['ok' => false, 'code' => $code, 'message' => self::message($code)] + $extra;
        if (!in_array($choice, [self::RESOLUTION_WALLET, self::RESOLUTION_BANK], true) || !in_array($byType, ['customer', 'staff'], true)) {
            return $refuse('bad_choice');
        }
        $bank = null;
        if ($choice === self::RESOLUTION_BANK) {
            $bank = ManualRefunds::validateBank($bankInput);
            if (!$bank['ok']) {
                return $refuse('bad_bank', ['errors' => $bank['errors']]);
            }
        }

        $first = Database::one('SELECT order_id FROM order_shortages WHERE id = :id', [':id' => $shortageId]);
        if ($first === null) {
            return $refuse('not_found');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $order = Database::one('SELECT id, user_id, order_number FROM orders WHERE id = :id FOR UPDATE', [':id' => (int) $first['order_id']]);
            $shortage = Database::one(
                'SELECT s.*, i.item_name, i.unit_name
                   FROM order_shortages s JOIN order_items i ON i.id = s.order_item_id
                  WHERE s.id = :id FOR UPDATE',
                [':id' => $shortageId]
            );
            if ($order === null || $shortage === null) {
                $pdo->rollBack();
                return $refuse('not_found');
            }
            if ((string) $shortage['status'] !== self::STATUS_AWAITING) {
                $pdo->rollBack();
                return in_array((string) $shortage['status'], [self::STATUS_SETTLED, self::STATUS_REFUND_PENDING], true)
                    ? ['ok' => true, 'code' => 'already_decided', 'message' => self::message('already_decided'), 'resolution' => (string) $shortage['resolution']]
                    : $refuse('not_awaiting');
            }
            $due = (int) $shortage['refund_due_subunit'];
            $itemLine = self::itemLine((string) $shortage['short_quantity'], (string) $shortage['unit_name'], (string) $shortage['item_name']);
            $refundId = 0;

            if ($choice === self::RESOLUTION_WALLET) {
                if ($order['user_id'] === null) {
                    $pdo->rollBack();
                    return $refuse('no_account');
                }
                $credit = Wallet::credit(
                    (int) $order['user_id'], $due, 'shortage',
                    'Out of stock: ' . $itemLine . ' on order ' . $order['order_number'],
                    'shortage:' . $shortageId . ':wallet', (int) $order['id'], $byUserId
                );
                Database::run(
                    'UPDATE order_shortages
                        SET status = :status, resolution = :res, decided_by_type = :type, decided_by = :by, decided_at = NOW(),
                            wallet_entry_id = :entry
                      WHERE id = :id',
                    [':status' => self::STATUS_SETTLED, ':res' => self::RESOLUTION_WALLET, ':type' => $byType, ':by' => $byUserId,
                     ':entry' => $credit['entry_id'], ':id' => $shortageId]
                );
            } else {
                $refund = ManualRefunds::createForShortage(
                    $shortageId, (int) $order['id'], $order['user_id'] === null ? null : (int) $order['user_id'], $due, $bank['clean'], $byType, $byUserId
                );
                $refundId = $refund['id'];
                Database::run(
                    'UPDATE order_shortages
                        SET status = :status, resolution = :res, decided_by_type = :type, decided_by = :by, decided_at = NOW()
                      WHERE id = :id',
                    [':status' => self::STATUS_REFUND_PENDING, ':res' => self::RESOLUTION_BANK, ':type' => $byType, ':by' => $byUserId, ':id' => $shortageId]
                );
            }
            Audit::record(
                'orders.shortage.decide', 'order', (int) $order['id'], ['status' => self::STATUS_AWAITING],
                ['shortage_id' => $shortageId, 'resolution' => $choice, 'by' => $byType, 'amount_subunit' => $due], $byUserId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => 'decided', 'message' => $choice === self::RESOLUTION_WALLET
                ? Money::format($due) . ' is in the wallet.' : 'Our team will send ' . Money::format($due) . ' to the bank account.',
                'resolution' => $choice, 'refund_id' => $refundId];
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $refuse('bad_choice');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Undoing a shortage that was marked by mistake
    // -------------------------------------------------------------------------

    /**
     * Put the line back, while no money has moved because of it: the shortage is
     * still waiting for a choice, or it only reduced what was owed. Everything it
     * changed is restored from the snapshot taken when it was marked, and only if
     * nothing else has touched those rows since.
     *
     * @return array{ok:bool, code:string, message:string}
     */
    public static function withdraw(int $shortageId, int $staffId): array
    {
        $refuse = static fn(string $code): array => ['ok' => false, 'code' => $code, 'message' => self::message($code)];
        $first = Database::one('SELECT order_id FROM order_shortages WHERE id = :id', [':id' => $shortageId]);
        if ($first === null) {
            return $refuse('not_found');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $order = Database::one('SELECT id, order_status, payment_option FROM orders WHERE id = :id FOR UPDATE', [':id' => (int) $first['order_id']]);
            $shortage = Database::one('SELECT * FROM order_shortages WHERE id = :id FOR UPDATE', [':id' => $shortageId]);
            if ($order === null || $shortage === null) {
                $pdo->rollBack();
                return $refuse('not_found');
            }
            $undoable = (string) $shortage['status'] === self::STATUS_AWAITING
                || ((string) $shortage['status'] === self::STATUS_SETTLED && (string) $shortage['resolution'] === self::RESOLUTION_REDUCED);
            if (!$undoable || !in_array((string) $order['order_status'], self::STAGES, true)) {
                $pdo->rollBack();
                return $refuse('cannot_undo');
            }
            $snapshot = json_decode((string) $shortage['adjustment'], true) ?: [];
            $rows = Database::all(
                'SELECT id, status, expected_amount_subunit, paid_amount_subunit FROM payments WHERE order_id = :o ORDER BY id FOR UPDATE',
                [':o' => (int) $order['id']]
            );
            $byId = [];
            foreach ($rows as $row) {
                $byId[(int) $row['id']] = $row;
            }
            foreach ($snapshot['payments'] ?? [] as $r) {
                $current = $byId[(int) $r['payment_id']] ?? null;
                if ($current === null || (int) $current['expected_amount_subunit'] !== (int) $r['expected_after'] || (string) $current['status'] !== (string) $r['status_after']) {
                    $pdo->rollBack();
                    return $refuse('cannot_undo');
                }
            }
            $item = Database::one('SELECT id, quantity, line_total_subunit FROM order_items WHERE id = :id FOR UPDATE', [':id' => (int) $shortage['order_item_id']]);
            $amount = (int) $shortage['amount_subunit'];
            if ($item === null
                || (int) $item['line_total_subunit'] !== (int) $shortage['original_line_total_subunit'] - $amount
            ) {
                $pdo->rollBack();
                return $refuse('cannot_undo');
            }

            foreach ($snapshot['payments'] ?? [] as $r) {
                Database::run(
                    'UPDATE payments SET expected_amount_subunit = :e, status = :s WHERE id = :id',
                    [':e' => $r['expected_before'], ':s' => $r['status_before'], ':id' => $r['payment_id']]
                );
                Payments::writeHistory((int) $r['payment_id'], null, (string) $r['status_after'], (string) $r['status_before'], 'shortage', null,
                    'Expected amount put back: the out of stock mark was undone.');
            }
            Database::run(
                'UPDATE order_items SET quantity = :q, line_total_subunit = :t WHERE id = :id',
                [':q' => $shortage['original_quantity'], ':t' => (int) $shortage['original_line_total_subunit'], ':id' => (int) $shortage['order_item_id']]
            );
            Database::run(
                'UPDATE orders SET subtotal_subunit = subtotal_subunit + :a, order_total_subunit = order_total_subunit + :a2 WHERE id = :id',
                [':a' => $amount, ':a2' => $amount, ':id' => (int) $order['id']]
            );
            if (!empty($snapshot['credit'])) {
                Credit::restoreShortage((int) $order['id'], $shortageId);
            }
            Payments::recomputeOrder((int) $order['id']);
            Database::run(
                'UPDATE order_shortages SET status = :s, token_hash = NULL, resolution = NULL WHERE id = :id',
                [':s' => self::STATUS_WITHDRAWN, ':id' => $shortageId]
            );
            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, note, changed_by)
                 VALUES (:order, :status, :status2, \'staff\', :note, :staff)',
                [':order' => (int) $order['id'], ':status' => $order['order_status'], ':status2' => $order['order_status'],
                 ':note' => 'Out of stock mark undone. ' . Money::format($amount) . ' put back on the order.', ':staff' => $staffId]
            );
            Audit::record('orders.shortage.withdraw', 'order', (int) $order['id'], ['status' => $shortage['status']],
                ['shortage_id' => $shortageId, 'amount_subunit' => $amount], $staffId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'withdrawn', 'message' => 'Undone. The line is back on the order.'];
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

    /**
     * A shortage by the token in the customer's link, with what the page shows.
     * Null for an unknown, withdrawn or malformed token.
     *
     * @return ?array<string,mixed>
     */
    public static function byToken(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{48}$/', $token) !== 1) {
            return null;
        }
        $row = Database::one(
            'SELECT s.id, s.order_id, s.short_quantity, s.amount_subunit, s.refund_due_subunit, s.status, s.resolution,
                    i.item_name, i.unit_name, o.order_number, o.user_id, o.order_status
               FROM order_shortages s
               JOIN order_items i ON i.id = s.order_item_id
               JOIN orders o ON o.id = s.order_id
              WHERE s.token_hash = :hash AND s.status <> :withdrawn',
            [':hash' => hash('sha256', $token), ':withdrawn' => self::STATUS_WITHDRAWN]
        );
        if ($row === null) {
            return null;
        }
        $row['item_line'] = self::itemLine((string) $row['short_quantity'], (string) $row['unit_name'], (string) $row['item_name']);
        $row['can_use_wallet'] = $row['user_id'] !== null;
        return $row;
    }

    /**
     * A shortage the signed-in owner of the order can answer without the emailed
     * link, so a lost email is never a lost refund. Null when it is not theirs.
     *
     * @return ?array<string,mixed>
     */
    public static function forOwner(int $shortageId, int $userId): ?array
    {
        $row = Database::one(
            'SELECT s.id, s.order_id, s.short_quantity, s.amount_subunit, s.refund_due_subunit, s.status, s.resolution,
                    i.item_name, i.unit_name, o.order_number, o.user_id, o.order_status
               FROM order_shortages s
               JOIN order_items i ON i.id = s.order_item_id
               JOIN orders o ON o.id = s.order_id
              WHERE s.id = :id AND o.user_id = :user AND s.status <> :withdrawn',
            [':id' => $shortageId, ':user' => $userId, ':withdrawn' => self::STATUS_WITHDRAWN]
        );
        if ($row === null) {
            return null;
        }
        $row['item_line'] = self::itemLine((string) $row['short_quantity'], (string) $row['unit_name'], (string) $row['item_name']);
        $row['can_use_wallet'] = true;
        return $row;
    }

    /**
     * Every shortage on an order, newest first, with the line it is about and
     * the refund it created, if any.
     *
     * @return list<array<string,mixed>>
     */
    public static function forOrder(int $orderId): array
    {
        $rows = Database::all(
            'SELECT s.*, i.item_name, i.unit_name,
                    r.id AS refund_id, r.refund_number, r.status AS refund_status
               FROM order_shortages s
               JOIN order_items i ON i.id = s.order_item_id
               LEFT JOIN manual_refunds r ON r.shortage_id = s.id
              WHERE s.order_id = :o
              ORDER BY s.id DESC',
            [':o' => $orderId]
        );
        foreach ($rows as &$row) {
            $row['item_line']   = self::itemLine((string) $row['short_quantity'], (string) $row['unit_name'], (string) $row['item_name']);
            $row['status_label'] = self::statusLabel((string) $row['status'], $row['resolution'] === null ? null : (string) $row['resolution']);
            $row['can_undo']    = (string) $row['status'] === self::STATUS_AWAITING
                || ((string) $row['status'] === self::STATUS_SETTLED && (string) $row['resolution'] === self::RESOLUTION_REDUCED);
        }
        unset($row);
        return $rows;
    }

    /**
     * What has been given back to the customer because of shortages on this
     * order: into the wallet, or waiting to be sent to their bank. Cancelling the
     * order must not return it a second time.
     */
    public static function returnedSubunit(int $orderId): int
    {
        return (int) (Database::one(
            'SELECT COALESCE(SUM(refund_due_subunit), 0) AS total
               FROM order_shortages
              WHERE order_id = :o AND status IN (:pending, :settled) AND resolution IN (:wallet, :bank)',
            [':o' => $orderId, ':pending' => self::STATUS_REFUND_PENDING, ':settled' => self::STATUS_SETTLED,
             ':wallet' => self::RESOLUTION_WALLET, ':bank' => self::RESOLUTION_BANK]
        )['total'] ?? 0);
    }

    /** How many of this customer's shortages are waiting for them to choose. */
    public static function awaitingCountForUser(int $userId): int
    {
        return (int) (Database::one(
            'SELECT COUNT(*) AS c FROM order_shortages s JOIN orders o ON o.id = s.order_id
              WHERE o.user_id = :u AND s.status = :awaiting',
            [':u' => $userId, ':awaiting' => self::STATUS_AWAITING]
        )['c'] ?? 0);
    }
}
