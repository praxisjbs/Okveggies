<?php
/**
 * includes/classes/ManualPayments.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Money that did not come through Paystack: a bank transfer or cash
 * handed over on delivery, recorded by staff.
 *
 * This is the one place in the system where a person asserts that money exists
 * and nothing outside the building confirms it. Everything here is shaped by
 * that fact.
 *
 *   Recording is one step. A staff member with payments.record enters the
 *   payment and the order is credited straight away, after a confirmation step
 *   on the screen. Waiting on a second person would leave a customer looking at
 *   an unpaid order after they had already paid.
 *
 *   Double submission cannot double credit. The form carries a one-time token
 *   that becomes part of the transaction reference, and reference is UNIQUE, so
 *   a second submit of the same form collides in the database and is reported
 *   back as already recorded rather than credited twice.
 *
 *   Part payments add up. Manual money increments what a payment holds rather
 *   than overwriting it, because a balance really can arrive in two transfers.
 *   A Paystack charge overwrites, because one payment row holds one charge.
 *
 *   A mistake is reversed, never deleted. A payment recorded against the wrong
 *   order is a data error, not a refund: no money travels back to a customer.
 *   The reversal is requested by one person and approved by another, and both
 *   entries stay visible. Refunds, where money really does go back, are PR3.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/ManualPaymentsTest.php.
 * -----------------------------------------------------------------------------
 */

final class ManualPayments
{
    /** How the money arrived. Cash leaves no artefact; a transfer always does. */
    public const METHODS = ['cash', 'transfer'];

    public const PROOF_PENDING  = 'pending';
    public const PROOF_APPROVED = 'approved';
    public const PROOF_REJECTED = 'rejected';
    public const PROOF_REVERSED = 'reversed';

    public const REVERSAL_REQUESTED = 'requested';
    public const REVERSAL_APPROVED  = 'approved';
    public const REVERSAL_DECLINED  = 'declined';

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    public static function methodIsValid(string $method): bool
    {
        return in_array($method, self::METHODS, true);
    }

    /**
     * Whether there is enough evidence to record this payment.
     *
     * A transfer always leaves something behind, either a bank reference or a
     * screenshot, so one of the two is required and asking costs the recorder
     * nothing. Cash leaves nothing, and demanding a photograph of a banknote
     * would be theatre, so cash needs neither.
     */
    public static function evidenceIsSufficient(string $method, ?string $bankReference, bool $hasFile): bool
    {
        if ($method !== 'transfer') {
            return true;
        }
        return $hasFile || trim((string) $bankReference) !== '';
    }

    /**
     * The reference for a manual transaction. The token comes from the form and
     * makes the reference unique per submission, which is what turns the UNIQUE
     * index on reference into double-submit protection. Legal under Paystack's
     * charset so every transaction reference in the system reads the same way.
     */
    public static function reference(string $orderNumber, string $token): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $orderNumber) ?? '';
        $safe  = preg_replace('/[^A-Za-z0-9]/', '', $token) ?? '';
        return $clean . '-M-' . $safe;
    }

    /** A fresh one-time token for a record form. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** What an amount does to the outstanding balance on a payment. */
    public static function outcomeKind(int $amount, int $outstanding): string
    {
        if ($amount < $outstanding) {
            return 'part';
        }
        return $amount === $outstanding ? 'settles' : 'over';
    }

    /** Human copy for the confirmation step, so the recorder sees the effect. */
    public static function confirmationLine(int $amount, int $outstanding): string
    {
        switch (self::outcomeKind($amount, $outstanding)) {
            case 'part':
                return 'This records ' . Money::format($amount) . ' and leaves '
                     . Money::format(Money::balance($outstanding, $amount)) . ' outstanding.';
            case 'settles':
                return 'This records ' . Money::format($amount) . ' and settles the payment in full.';
            default:
                return 'This records ' . Money::format($amount) . ', which is '
                     . Money::format($amount - $outstanding) . ' more than is outstanding. '
                     . 'The order will show as overpaid and will need a refund decision.';
        }
    }

    // -------------------------------------------------------------------------
    // Recording
    // -------------------------------------------------------------------------

    /**
     * Record money that arrived outside Paystack, and credit the order.
     *
     * One transaction covers the transaction row, the proof, the credit, the
     * order recompute and the history entry, so a failure anywhere leaves no
     * half-recorded payment behind.
     */
    public static function record(array $input, int $staffId): array
    {
        $paymentId = (int) ($input['payment_id'] ?? 0);
        $amount    = (int) ($input['amount_subunit'] ?? 0);
        $method    = (string) ($input['method'] ?? '');
        $token     = (string) ($input['record_token'] ?? '');

        if ($paymentId < 1 || !self::methodIsValid($method) || $token === '') {
            return ['ok' => false, 'code' => 'bad_input', 'message' => 'Check the payment details and try again.'];
        }
        if ($amount < 1) {
            return ['ok' => false, 'code' => 'bad_amount', 'message' => 'Enter an amount greater than zero.'];
        }
        if (!self::evidenceIsSufficient($method, $input['bank_reference'] ?? null, !empty($input['proof_url']))) {
            return ['ok' => false, 'code' => 'evidence_required', 'message' => 'A transfer needs either the transaction reference or a screenshot.'];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            // The user join carries the customer's real email. A manual
            // transaction row is still a payment record, and stamping a
            // placeholder address on it would pollute every screen and receipt
            // that reads it later.
            $payment = Database::one(
                'SELECT p.*, o.order_number, o.id AS order_id, u.email
                   FROM payments p
                   JOIN orders o ON o.id = p.order_id
                   LEFT JOIN users u ON u.id = p.user_id
                  WHERE p.id = :id
                  FOR UPDATE',
                [':id' => $paymentId]
            );
            if (!$payment) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That payment could not be found.'];
            }

            $reference = self::reference((string) $payment['order_number'], $token);

            // The UNIQUE index on reference is the double-submit guard. A second
            // submission of the same form carries the same token, so it lands
            // here and is reported rather than credited a second time.
            if (Database::one('SELECT id FROM payment_transactions WHERE reference = :ref', [':ref' => $reference])) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'already_recorded', 'message' => 'That payment has already been recorded.'];
            }

            $expected    = (int) $payment['expected_amount_subunit'];
            $alreadyPaid = (int) $payment['paid_amount_subunit'];
            $outstanding = Money::balance($expected, $alreadyPaid);
            $oldStatus   = (string) $payment['status'];

            Database::run(
                'INSERT INTO payment_transactions
                    (payment_id, attempt_number, provider, reference, domain, status,
                     requested_amount_subunit, amount_subunit, currency, customer_email,
                     channel, gateway_response, paid_at, verified_at)
                 VALUES (:pid, :attempt, \'manual\', :ref, :domain, \'success\',
                         :amount, :amount2, :currency, :email,
                         :method, :note, NOW(), NOW())',
                [
                    ':pid'      => $paymentId,
                    ':attempt'  => 1 + (int) (Database::one(
                        'SELECT COALESCE(MAX(attempt_number), 0) AS n FROM payment_transactions WHERE payment_id = :id',
                        [':id' => $paymentId]
                    )['n'] ?? 0),
                    ':ref'      => $reference,
                    ':domain'   => Paystack::domain(),
                    ':amount'   => $amount,
                    ':amount2'  => $amount,
                    ':currency' => Money::CODE,
                    ':email'    => substr(trim((string) ($payment['email'] ?? '')), 0, 255)
                                   ?: (substr(trim((string) ($input['customer_email'] ?? '')), 0, 255) ?: 'unknown@okveggies.invalid'),
                    ':method'   => $method,
                    ':note'     => 'Recorded by staff as ' . $method . '.',
                ]
            );
            $txnId = (int) $pdo->lastInsertId();

            Database::run(
                'INSERT INTO manual_payment_proofs
                    (payment_transaction_id, method, proof_url, bank_reference, payer_name,
                     amount_subunit, status, recorded_by)
                 VALUES (:txn, :method, :url, :bank_ref, :payer, :amount, :status, :staff)',
                [
                    ':txn'      => $txnId,
                    ':method'   => $method,
                    ':url'      => substr(trim((string) ($input['proof_url'] ?? '')), 0, 500) ?: null,
                    ':bank_ref' => substr(trim((string) ($input['bank_reference'] ?? '')), 0, 150) ?: null,
                    ':payer'    => substr(trim((string) ($input['payer_name'] ?? '')), 0, 150) ?: null,
                    ':amount'   => $amount,
                    ':status'   => self::PROOF_PENDING,
                    ':staff'    => $staffId,
                ]
            );

            // Manual money adds up: a balance can genuinely arrive in two
            // transfers, so this increments rather than overwriting. A Paystack
            // charge overwrites, because one payment row holds one charge.
            Database::run(
                'UPDATE payments
                    SET paid_amount_subunit = paid_amount_subunit + :amount,
                        status = CASE WHEN paid_amount_subunit + :amount2 >= expected_amount_subunit
                                      THEN :paid ELSE :part END,
                        confirmed_at = CASE WHEN paid_amount_subunit + :amount3 >= expected_amount_subunit
                                            THEN NOW() ELSE confirmed_at END
                  WHERE id = :id',
                [
                    ':amount'  => $amount,
                    ':amount2' => $amount,
                    ':amount3' => $amount,
                    ':paid'    => Payments::STATUS_PAID,
                    ':part'    => Payments::STATUS_PART_PAID,
                    ':id'      => $paymentId,
                ]
            );

            $newStatus = Payments::paymentStatus($alreadyPaid + $amount, $expected);
            Payments::writeHistory(
                $paymentId,
                $txnId,
                $oldStatus,
                $newStatus,
                'admin',
                null,
                'Recorded by staff as ' . $method . '. ' . self::confirmationLine($amount, $outstanding)
            );
            Payments::recomputeOrder((int) $payment['order_id']);

            $pdo->commit();
            return [
                'ok'          => true,
                'code'        => 'recorded',
                'outcome'     => self::outcomeKind($amount, $outstanding),
                'reference'   => $reference,
                'transaction_id' => $txnId,
                'order_id'    => (int) $payment['order_id'],
                'message'     => self::confirmationLine($amount, $outstanding),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Reviewing the evidence
    // -------------------------------------------------------------------------

    /**
     * Confirm or question a recorded proof. The money has already moved, so
     * this changes no balance: it records that a second pair of eyes has looked
     * at the evidence. Questioning one is how a reversal gets started.
     */
    public static function reviewProof(int $proofId, string $decision, string $note, int $staffId): array
    {
        if (!in_array($decision, [self::PROOF_APPROVED, self::PROOF_REJECTED], true)) {
            return ['ok' => false, 'code' => 'bad_decision', 'message' => 'That decision is not available.'];
        }

        $proof = Database::one('SELECT * FROM manual_payment_proofs WHERE id = :id', [':id' => $proofId]);
        if (!$proof) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That proof could not be found.'];
        }
        if ((string) $proof['status'] !== self::PROOF_PENDING) {
            return ['ok' => false, 'code' => 'already_reviewed', 'message' => 'That proof has already been reviewed.'];
        }

        Database::run(
            'UPDATE manual_payment_proofs
                SET status = :status, reviewed_by = :staff, review_note = :note, reviewed_at = NOW()
              WHERE id = :id AND status = :pending',
            [
                ':status'  => $decision,
                ':staff'   => $staffId,
                ':note'    => substr(trim($note), 0, 500) ?: null,
                ':id'      => $proofId,
                ':pending' => self::PROOF_PENDING,
            ]
        );

        return [
            'ok'      => true,
            'code'    => $decision,
            'message' => $decision === self::PROOF_APPROVED
                ? 'Proof confirmed.'
                : 'Proof questioned. Raise a reversal if the money should come off the order.',
            'transaction_id' => (int) $proof['payment_transaction_id'],
        ];
    }

    // -------------------------------------------------------------------------
    // Undoing a mistake
    // -------------------------------------------------------------------------

    /** Ask for a recorded payment to be taken back off the order. */
    public static function requestReversal(int $transactionId, string $reason, int $staffId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            return ['ok' => false, 'code' => 'reason_required', 'message' => 'Say why this payment should be reversed.'];
        }

        $txn = Database::one(
            'SELECT t.*, p.order_id FROM payment_transactions t
               JOIN payments p ON p.id = t.payment_id
              WHERE t.id = :id',
            [':id' => $transactionId]
        );
        if (!$txn) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That transaction could not be found.'];
        }
        if ((string) $txn['provider'] !== 'manual') {
            return ['ok' => false, 'code' => 'not_manual', 'message' => 'Only a payment recorded by staff can be reversed here. A Paystack charge is refunded instead.'];
        }
        if ((string) $txn['status'] !== 'success') {
            return ['ok' => false, 'code' => 'not_reversible', 'message' => 'That payment is not in a state that can be reversed.'];
        }
        if (Database::one(
            'SELECT id FROM payment_reversals WHERE payment_transaction_id = :id AND status = :status',
            [':id' => $transactionId, ':status' => self::REVERSAL_REQUESTED]
        )) {
            return ['ok' => false, 'code' => 'already_requested', 'message' => 'A reversal has already been asked for on this payment.'];
        }

        Database::run(
            'INSERT INTO payment_reversals
                (payment_id, payment_transaction_id, amount_subunit, reason, status, requested_by)
             VALUES (:pid, :txn, :amount, :reason, :status, :staff)',
            [
                ':pid'    => (int) $txn['payment_id'],
                ':txn'    => $transactionId,
                ':amount' => (int) $txn['amount_subunit'],
                ':reason' => substr($reason, 0, 500),
                ':status' => self::REVERSAL_REQUESTED,
                ':staff'  => $staffId,
            ]
        );

        return ['ok' => true, 'code' => 'requested', 'message' => 'Reversal requested. Someone else has to approve it.'];
    }

    /**
     * Approve or decline a reversal.
     *
     * Nobody approves their own request. That rule lives here rather than in the
     * role seed so it holds however the roles are later rearranged. The Owner is
     * the exception, because at launch the Owner may be the only staff account
     * and a business with one account still has to be able to fix a typo.
     */
    public static function decideReversal(int $reversalId, string $decision, string $note, int $staffId, bool $isOwner): array
    {
        if (!in_array($decision, [self::REVERSAL_APPROVED, self::REVERSAL_DECLINED], true)) {
            return ['ok' => false, 'code' => 'bad_decision', 'message' => 'That decision is not available.'];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $reversal = Database::one('SELECT * FROM payment_reversals WHERE id = :id FOR UPDATE', [':id' => $reversalId]);
            if (!$reversal) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That reversal could not be found.'];
            }
            if ((string) $reversal['status'] !== self::REVERSAL_REQUESTED) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'already_decided', 'message' => 'That reversal has already been decided.'];
            }
            if ((int) $reversal['requested_by'] === $staffId && !$isOwner) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'self_approval', 'message' => 'Someone else has to approve a reversal you asked for.'];
            }

            Database::run(
                'UPDATE payment_reversals
                    SET status = :status, decided_by = :staff, decision_note = :note, decided_at = NOW()
                  WHERE id = :id AND status = :requested',
                [
                    ':status'    => $decision,
                    ':staff'     => $staffId,
                    ':note'      => substr(trim($note), 0, 500) ?: null,
                    ':id'        => $reversalId,
                    ':requested' => self::REVERSAL_REQUESTED,
                ]
            );

            if ($decision === self::REVERSAL_DECLINED) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'declined', 'message' => 'Reversal declined. The payment stands.'];
            }

            $paymentId = (int) $reversal['payment_id'];
            $amount    = (int) $reversal['amount_subunit'];

            $payment = Database::one('SELECT * FROM payments WHERE id = :id FOR UPDATE', [':id' => $paymentId]);
            if (!$payment) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That payment could not be found.'];
            }
            $oldStatus = (string) $payment['status'];
            $expected  = (int) $payment['expected_amount_subunit'];
            $remaining = max(0, (int) $payment['paid_amount_subunit'] - $amount);

            Database::run(
                'UPDATE payments
                    SET paid_amount_subunit = :paid,
                        status = :status,
                        confirmed_at = CASE WHEN :paid2 >= expected_amount_subunit THEN confirmed_at ELSE NULL END
                  WHERE id = :id',
                [
                    ':paid'   => $remaining,
                    ':paid2'  => $remaining,
                    ':status' => Payments::paymentStatus($remaining, $expected),
                    ':id'     => $paymentId,
                ]
            );

            // The reversed entry stays visible beside what it undoes. Nothing is
            // deleted, here or anywhere else in the money tables.
            Database::run(
                'UPDATE payment_transactions SET status = \'reversed\' WHERE id = :id',
                [':id' => (int) $reversal['payment_transaction_id']]
            );
            Database::run(
                'UPDATE manual_payment_proofs SET status = :status WHERE payment_transaction_id = :id',
                [':status' => self::PROOF_REVERSED, ':id' => (int) $reversal['payment_transaction_id']]
            );

            Payments::writeHistory(
                $paymentId,
                (int) $reversal['payment_transaction_id'],
                $oldStatus,
                Payments::paymentStatus($remaining, $expected),
                'admin',
                null,
                'Reversed: ' . (string) $reversal['reason']
            );
            Payments::recomputeOrder((int) $payment['order_id']);

            $pdo->commit();
            return ['ok' => true, 'code' => 'approved', 'message' => 'Reversal approved. The money has come off the order.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
