<?php
/** M10 money and fulfilment outcomes for a handled Make It Right report. */
final class IssueResolutions
{
    public const TYPES = ['refund', 'credit', 'replacement'];

    public static function amountIsValid(int $amountSubunit, array $lineAmounts, int $availableSubunit): bool
    {
        $cap = Money::sum(array_map('intval', $lineAmounts));
        return $amountSubunit >= 1
            && $amountSubunit <= $cap
            && $amountSubunit <= $availableSubunit;
    }

    /** Data the admin form needs without trusting any posted money or order. */
    public static function options(int $issueId): array
    {
        $report = Database::one(
            'SELECT i.id, i.order_id, i.user_id, i.status, i.handled_by,
                    i.resolution_type, i.resolution_amount_subunit,
                    i.credit_transaction_id, i.replacement_order_id,
                    o.order_number, o.customer_type
               FROM issue_reports i
               JOIN orders o ON o.id = i.order_id
              WHERE i.id = :id',
            [':id' => $issueId]
        );
        if ($report === null) {
            return [];
        }
        $transactions = Database::all(
            'SELECT t.id, t.reference, t.amount_subunit, p.payment_type
               FROM payment_transactions t
               JOIN payments p ON p.id = t.payment_id
              WHERE p.order_id = :order_id
                AND t.status = :success
                AND t.provider = :provider
           ORDER BY t.paid_at, t.id',
            [':order_id' => (int) $report['order_id'], ':success' => 'success', ':provider' => 'paystack']
        );
        foreach ($transactions as &$transaction) {
            $quote = Refunds::quote((int) $transaction['id']);
            $transaction['refundable_subunit'] = !empty($quote['ok']) ? (int) $quote['refundable_subunit'] : 0;
        }
        unset($transaction);

        $credit = Database::one(
            'SELECT id, credit_status FROM business_customers WHERE user_id = :user_id',
            [':user_id' => (int) $report['user_id']]
        );
        $replacement = $report['replacement_order_id'] === null ? null : Database::one(
            'SELECT id, order_number FROM orders WHERE id = :id',
            [':id' => (int) $report['replacement_order_id']]
        );
        $refund = Database::one(
            'SELECT id, amount_subunit, status FROM refunds WHERE issue_report_id = :issue_id',
            [':issue_id' => $issueId]
        );
        return [
            'transactions' => array_values(array_filter(
                $transactions,
                static fn(array $row): bool => (int) $row['refundable_subunit'] > 0
            )),
            'credit_available' => $credit !== null && (string) $credit['credit_status'] === 'approved',
            'refund' => $refund,
            'replacement' => $replacement,
            'items' => self::storedItems($issueId),
        ];
    }

    /** The Owner can take over a report that another colleague owns. */
    public static function reassignToOwner(int $issueId, string $expectedStatus, int $ownerId): array
    {
        if ($issueId < 1 || $ownerId < 1 || $expectedStatus !== 'in_progress') {
            return self::failure('invalid_reassign', 'Reload the report before taking it over.');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $report = self::lockedReport($issueId);
            if ($report === null) {
                $pdo->rollBack();
                return self::failure('not_found', 'That report could not be found.');
            }
            if (self::terminal($report)) {
                $pdo->rollBack();
                return self::failure('terminal', 'This report has already been finished.');
            }
            if ((string) $report['status'] !== $expectedStatus) {
                $pdo->rollBack();
                return self::failure('stale', 'This report changed after the page loaded. Reload it before acting.');
            }
            $oldHandler = (int) ($report['handled_by'] ?? 0);
            if ($oldHandler === $ownerId) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'already_handler', 'status' => 'in_progress'];
            }
            Database::run(
                'UPDATE issue_reports SET handled_by = :owner, handled_at = NOW() WHERE id = :id',
                [':owner' => $ownerId, ':id' => $issueId]
            );
            self::history($issueId, 'in_progress', 'in_progress', 'reassigned', 'Taken over by the Owner.', $ownerId);
            Audit::record(
                'issue_reports.reassign',
                'issue_report',
                $issueId,
                ['handled_by' => $oldHandler],
                ['handled_by' => $ownerId],
                $ownerId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => 'reassigned', 'status' => 'in_progress'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function resolve(
        int $issueId,
        string $expectedStatus,
        int $actorId,
        string $type,
        string $note,
        array $itemIds,
        int $amountSubunit,
        int $transactionId = 0,
        string $replacementOrderNumber = ''
    ): array {
        $clean = IssueReports::validateResolutionNote($note);
        if (empty($clean['ok'])) {
            return $clean;
        }
        if ($issueId < 1 || $actorId < 1 || $expectedStatus !== 'in_progress' || !in_array($type, self::TYPES, true)) {
            return self::failure('invalid_resolution', 'Take the report before resolving it.');
        }

        $report = Database::one(
            'SELECT id, order_id, user_id, status, handled_by, resolution_type, resolved_at
               FROM issue_reports WHERE id = :id',
            [':id' => $issueId]
        );
        if ($report === null) {
            return self::failure('not_found', 'That report could not be found.');
        }
        if (self::terminal($report)) {
            return self::failure('terminal', 'This report has already been finished.');
        }
        if ((int) ($report['handled_by'] ?? 0) !== $actorId) {
            return self::failure('not_handler', 'Only the colleague handling this report can finish it.');
        }

        $items = self::selectedItems((int) $report['order_id'], $itemIds);
        if ($items === []) {
            return self::failure('items_required', 'Choose at least 1 affected order item.', 'item_ids');
        }
        $cap = Money::sum(array_column($items, 'line_total_subunit'));
        if ($type !== 'replacement' && !self::amountIsValid($amountSubunit, array_column($items, 'line_total_subunit'), $cap)) {
            return self::failure(
                'bad_amount',
                'Enter an amount between ' . Money::format(1) . ' and ' . Money::format($cap) . '.',
                'amount'
            );
        }

        return match ($type) {
            'refund' => self::refund($report, $clean['note'], $items, $amountSubunit, $transactionId, $actorId),
            'credit' => self::credit($report, $clean['note'], $items, $amountSubunit, $actorId),
            'replacement' => self::replacement($report, $clean['note'], $items, $replacementOrderNumber, $actorId),
        };
    }

    private static function refund(
        array $report,
        string $note,
        array $items,
        int $amount,
        int $transactionId,
        int $actorId
    ): array {
        $existing = Database::one(
            'SELECT id, order_id, amount_subunit, status FROM refunds WHERE issue_report_id = :issue_id',
            [':issue_id' => (int) $report['id']]
        );
        if ($existing !== null) {
            return self::finishRefund($report, $note, $items, $existing, $actorId);
        }
        $quote = Refunds::quote($transactionId);
        if (empty($quote['ok']) || (int) ($quote['order_id'] ?? 0) !== (int) $report['order_id']) {
            return self::failure('invalid_transaction', 'Choose a refundable Paystack payment from this order.', 'transaction_id');
        }
        if (!self::amountIsValid($amount, array_column($items, 'line_total_subunit'), (int) $quote['refundable_subunit'])) {
            return self::failure('bad_amount', 'The amount is above what can be refunded for the selected items or payment.', 'amount');
        }

        $result = Refunds::request(
            $transactionId,
            $amount,
            $note,
            'Make It Right report ' . (int) $report['id'],
            $actorId,
            (int) $report['id']
        );
        if (empty($result['refund_id'])) {
            return $result;
        }
        return self::finishRefund($report, $note, $items, [
            'id' => (int) $result['refund_id'],
            'order_id' => (int) $result['order_id'],
            'amount_subunit' => (int) $result['amount_subunit'],
            'status' => (string) $result['status'],
        ], $actorId) + ['refund_result' => $result];
    }

    private static function finishRefund(array $report, string $note, array $items, array $refund, int $actorId): array
    {
        if ((int) $refund['order_id'] !== (int) $report['order_id']) {
            return self::failure('invalid_transaction', 'The refund does not belong to this order.');
        }
        return self::finish(
            $report,
            'refund',
            $note,
            $items,
            (int) $refund['amount_subunit'],
            null,
            null,
            $actorId,
            ['refund_id' => (int) $refund['id'], 'refund_status' => (string) $refund['status']]
        );
    }

    private static function credit(array $report, string $note, array $items, int $amount, int $actorId): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $locked = self::lockForFinish($report, $actorId);
            if (empty($locked['ok'])) {
                $pdo->rollBack();
                return $locked;
            }
            $entry = Credit::grantIssueCredit(
                (int) $report['user_id'],
                (int) $report['order_id'],
                (int) $report['id'],
                $amount
            );
            self::storeItems((int) $report['id'], $items);
            self::writeTerminal($report, 'credit', $note, $amount, (int) $entry['id'], null, $actorId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'resolved', 'status' => 'resolved', 'type' => 'credit',
                'amount_subunit' => $amount, 'credit_transaction_id' => (int) $entry['id']];
        } catch (DomainException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return self::failure(
                $e->getMessage() === 'credit_not_available' ? 'credit_not_available' : 'invalid_credit',
                'Account credit is available only for a business with an approved credit facility.'
            );
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function replacement(
        array $report,
        string $note,
        array $items,
        string $replacementOrderNumber,
        int $actorId
    ): array {
        $replacementOrderNumber = strtoupper(trim($replacementOrderNumber));
        if ($replacementOrderNumber === '') {
            return self::failure('replacement_required', 'Enter the replacement order number.', 'replacement_order_number');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $locked = self::lockForFinish($report, $actorId);
            if (empty($locked['ok'])) {
                $pdo->rollBack();
                return $locked;
            }
            $replacement = Database::one(
                'SELECT id, order_number, user_id, order_status, created_by
                   FROM orders WHERE order_number = :number FOR UPDATE',
                [':number' => $replacementOrderNumber]
            );
            if ($replacement === null
                || (int) $replacement['id'] === (int) $report['order_id']
                || (int) $replacement['user_id'] !== (int) $report['user_id']
                || $replacement['created_by'] === null
                || (string) $replacement['order_status'] === 'cancelled'
            ) {
                $pdo->rollBack();
                return self::failure(
                    'invalid_replacement',
                    'Choose an active manual order for the same customer.',
                    'replacement_order_number'
                );
            }
            $used = Database::one(
                'SELECT id FROM issue_reports
                  WHERE replacement_order_id = :order_id AND id <> :issue_id LIMIT 1',
                [':order_id' => (int) $replacement['id'], ':issue_id' => (int) $report['id']]
            );
            if ($used !== null) {
                $pdo->rollBack();
                return self::failure('replacement_used', 'That order already carries another replacement.');
            }
            self::storeItems((int) $report['id'], $items);
            self::writeTerminal($report, 'replacement', $note, null, null, (int) $replacement['id'], $actorId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'resolved', 'status' => 'resolved', 'type' => 'replacement',
                'replacement_order_id' => (int) $replacement['id'], 'replacement_order_number' => (string) $replacement['order_number']];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function finish(
        array $report,
        string $type,
        string $note,
        array $items,
        ?int $amount,
        ?int $creditTransactionId,
        ?int $replacementOrderId,
        int $actorId,
        array $extra = []
    ): array {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $locked = self::lockForFinish($report, $actorId);
            if (empty($locked['ok'])) {
                $pdo->rollBack();
                return $locked;
            }
            self::storeItems((int) $report['id'], $items);
            self::writeTerminal($report, $type, $note, $amount, $creditTransactionId, $replacementOrderId, $actorId);
            $pdo->commit();
            return ['ok' => true, 'code' => 'resolved', 'status' => 'resolved', 'type' => $type,
                'amount_subunit' => $amount] + $extra;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private static function lockForFinish(array $report, int $actorId): array
    {
        $locked = self::lockedReport((int) $report['id']);
        if ($locked === null) {
            return self::failure('not_found', 'That report could not be found.');
        }
        if (self::terminal($locked)) {
            return self::failure('terminal', 'This report has already been finished.');
        }
        if ((string) $locked['status'] !== 'in_progress') {
            return self::failure('stale', 'This report changed after the page loaded. Reload it before acting.');
        }
        if ((int) ($locked['handled_by'] ?? 0) !== $actorId) {
            return self::failure('not_handler', 'Only the colleague handling this report can finish it.');
        }
        return ['ok' => true];
    }

    private static function writeTerminal(
        array $report,
        string $type,
        string $note,
        ?int $amount,
        ?int $creditTransactionId,
        ?int $replacementOrderId,
        int $actorId
    ): void {
        Database::run(
            'UPDATE issue_reports
                SET status = :status, resolution_type = :type, resolution_note = :note,
                    resolution_amount_subunit = :amount, credit_transaction_id = :credit,
                    replacement_order_id = :replacement, resolved_at = NOW(), active_slot = NULL
              WHERE id = :id',
            [':status' => 'resolved', ':type' => $type, ':note' => $note, ':amount' => $amount,
                ':credit' => $creditTransactionId, ':replacement' => $replacementOrderId, ':id' => (int) $report['id']]
        );
        self::history((int) $report['id'], 'in_progress', 'resolved', 'resolved_' . $type, $note, $actorId);
        Audit::record(
            'issue_reports.resolve.' . $type,
            'issue_report',
            (int) $report['id'],
            ['status' => 'in_progress'],
            ['status' => 'resolved', 'resolution_type' => $type, 'amount_subunit' => $amount,
                'credit_transaction_id' => $creditTransactionId, 'replacement_order_id' => $replacementOrderId],
            $actorId
        );
    }

    private static function selectedItems(int $orderId, array $itemIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', array_filter($itemIds, 'is_scalar')),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach ($ids as $id) {
            $item = Database::one(
                'SELECT id, line_total_subunit FROM order_items
                  WHERE id = :id AND order_id = :order_id',
                [':id' => $id, ':order_id' => $orderId]
            );
            if ($item === null) {
                return [];
            }
            $found[] = ['id' => (int) $item['id'], 'line_total_subunit' => (int) $item['line_total_subunit']];
        }
        return $found;
    }

    private static function storeItems(int $issueId, array $items): void
    {
        foreach ($items as $item) {
            Database::run(
                'INSERT INTO issue_report_resolution_items (issue_id, order_item_id, amount_subunit)
                 VALUES (:issue_id, :item_id, :amount)
                 ON DUPLICATE KEY UPDATE amount_subunit = VALUES(amount_subunit)',
                [':issue_id' => $issueId, ':item_id' => (int) $item['id'], ':amount' => (int) $item['line_total_subunit']]
            );
        }
    }

    private static function storedItems(int $issueId): array
    {
        return Database::all(
            'SELECT r.order_item_id, r.amount_subunit, o.item_name, o.quantity, o.unit_name
               FROM issue_report_resolution_items r
               JOIN order_items o ON o.id = r.order_item_id
              WHERE r.issue_id = :issue_id ORDER BY r.id',
            [':issue_id' => $issueId]
        );
    }

    private static function lockedReport(int $issueId): ?array
    {
        return Database::one(
            'SELECT id, order_id, user_id, status, handled_by, resolution_type, resolved_at
               FROM issue_reports WHERE id = :id FOR UPDATE',
            [':id' => $issueId]
        );
    }

    private static function terminal(array $report): bool
    {
        return in_array((string) $report['status'], ['resolved', 'declined'], true)
            || $report['resolved_at'] !== null;
    }

    private static function history(
        int $issueId,
        ?string $oldStatus,
        string $newStatus,
        string $action,
        ?string $note,
        int $actorId
    ): void {
        Database::run(
            'INSERT INTO issue_report_history
                (issue_id, old_status, new_status, action, note, acted_by)
             VALUES (:issue_id, :old_status, :new_status, :action, :note, :actor)',
            [':issue_id' => $issueId, ':old_status' => $oldStatus, ':new_status' => $newStatus,
                ':action' => $action, ':note' => $note, ':actor' => $actorId]
        );
    }

    private static function failure(string $code, string $message, ?string $field = null): array
    {
        $result = ['ok' => false, 'code' => $code, 'message' => $message];
        if ($field !== null) {
            $result['field'] = $field;
        }
        return $result;
    }
}
