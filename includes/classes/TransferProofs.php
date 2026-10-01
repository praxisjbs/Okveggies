<?php
/**
 * includes/classes/TransferProofs.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Direct bank transfer, paid by the customer, with the receipt
 * uploaded here for the team to verify (PRD Section 9.3a).
 *
 * The one rule that shapes this file: a receipt a customer uploads credits
 * NOTHING. The order only moves once a member of staff has looked at the bank
 * and said how much actually arrived. That is the opposite of
 * ManualPayments::record(), where a colleague asserts money and the order is
 * credited at once, so the two never share a state:
 *
 *   manual_payment_proofs.status   what it means
 *   -----------------------------  -------------------------------------------
 *   pending    (ManualPayments)    staff recorded it, order already credited,
 *                                  waiting for a second pair of eyes
 *   submitted  (this class)        the customer sent a receipt, nothing credited
 *   approved                       staff verified it, the order was credited
 *   declined   (this class)        staff could not match it, nothing credited
 *   rejected   (ManualPayments)    a colleague's recording was questioned
 *
 * A submitted receipt is one payment_transactions row (provider 'manual',
 * status 'awaiting_review') and one manual_payment_proofs row, tied one to one
 * by the existing unique index. Verifying it turns the same rows into an
 * ordinary successful manual transaction, so reversals, refunds, the money
 * history and Order 360 read a verified customer transfer exactly as they read
 * money staff recorded by hand. A declined receipt is closed, never deleted, and
 * a fresh upload is a fresh pair of rows.
 *
 * The pure helpers hold no database and are unit tested in
 * scripts/tests/TransferProofsTest.php.
 * -----------------------------------------------------------------------------
 */

final class TransferProofs
{
    public const PROOF_SUBMITTED = 'submitted';
    public const PROOF_VERIFIED  = 'approved';
    public const PROOF_DECLINED  = 'declined';

    /** The transaction status while a receipt waits to be looked at. */
    public const TXN_AWAITING = 'awaiting_review';

    public const METHOD_PAYSTACK = 'paystack';
    public const METHOD_TRANSFER = 'bank_transfer';

    /** Where receipts are kept. PHP execution is denied under uploads/. */
    public const SUBDIR = 'payment_proofs';

    /** Payment types a customer may pay by transfer. Cash on delivery is not one. */
    public const PAYABLE_TYPES = ['pay_in_full', 'deposit', 'balance'];

    /** Declared type, allowed extensions. The sniffed type has to agree. */
    private const RECEIPT_TYPES = [
        'image/jpeg'      => ['jpg', 'jpeg'],
        'image/png'       => ['png'],
        'image/webp'      => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    // -------------------------------------------------------------------------
    // Pure helpers. No database. Unit tested.
    // -------------------------------------------------------------------------

    /** Nigerian bank accounts are 10 digits (NUBAN). */
    public static function accountNumberIsValid(string $number): bool
    {
        return preg_match('/^\d{10}$/', $number) === 1;
    }

    public static function methodIsValid(string $method): bool
    {
        return in_array($method, [self::METHOD_PAYSTACK, self::METHOD_TRANSFER], true);
    }

    /** Whether a payment choice can be paid by direct transfer at all. */
    public static function optionAllowsTransfer(string $option): bool
    {
        return in_array($option, ['pay_in_full', 'deposit'], true);
    }

    /** The label a customer and a colleague both read for a payment type. */
    public static function typeLabel(string $type): string
    {
        return [
            'pay_in_full'     => 'Payment in full',
            'deposit'         => 'Deposit',
            'balance'         => 'Balance',
            'pay_on_delivery' => 'Pay on delivery',
            'on_account'      => 'On account',
        ][$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

    /**
     * Why a file cannot be a receipt, or null when it can. Judged on the
     * extension, the sniffed type, and the size, all three of which must agree:
     * the browser's own claim about the type is never consulted.
     */
    public static function receiptFileProblem(string $name, string $sniffedMime, int $bytes, int $maxBytes): ?string
    {
        if ($bytes < 1) {
            return 'empty';
        }
        if ($bytes > $maxBytes) {
            return 'too_large';
        }
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::RECEIPT_TYPES[$sniffedMime])) {
            return 'unsupported_type';
        }
        if (!in_array($extension, self::RECEIPT_TYPES[$sniffedMime], true)) {
            return $extension === '' || !self::extensionIsAllowed($extension)
                ? 'unsupported_extension'
                : 'disguised_type';
        }
        return null;
    }

    private static function extensionIsAllowed(string $extension): bool
    {
        foreach (self::RECEIPT_TYPES as $extensions) {
            if (in_array($extension, $extensions, true)) {
                return true;
            }
        }
        return false;
    }

    /** Plain words for a refusal code. Never an exception message. */
    public static function receiptProblemMessage(string $code): string
    {
        return [
            'missing'               => 'Attach the receipt from your bank so we can check the payment.',
            'empty'                 => 'That file is empty. Attach the receipt again.',
            'too_large'             => 'That file is too large. Attach a photo or PDF under ' . self::maxMegabytes() . 'MB.',
            'unsupported_type'      => 'Attach the receipt as a photo (JPG, PNG or WebP) or a PDF.',
            'unsupported_extension' => 'Attach the receipt as a photo (JPG, PNG or WebP) or a PDF.',
            'disguised_type'        => 'That file does not look like a receipt. Attach a photo or PDF.',
            'unreadable_image'      => 'We could not read that photo. Attach it again or send a PDF.',
            'unreadable_pdf'        => 'We could not read that PDF. Attach it again or send a photo.',
            'upload_failed'         => 'That file did not upload. Please try again.',
        ][$code] ?? 'We could not accept that file. Please try again.';
    }

    private static function maxMegabytes(): int
    {
        return max(1, (int) floor(Uploads::maxBytes() / (1024 * 1024)));
    }

    // -------------------------------------------------------------------------
    // The OK Veggies account, as the Owner set it in Settings
    // -------------------------------------------------------------------------

    /** The three details a customer transfers to, exactly as saved. */
    public static function bankDetails(): array
    {
        return [
            'bank_name'      => trim(Settings::str('bank_transfer_bank_name', '')),
            'account_name'   => trim(Settings::str('bank_transfer_account_name', '')),
            'account_number' => preg_replace('/\D+/', '', Settings::str('bank_transfer_account_number', '')) ?? '',
        ];
    }

    /**
     * Whether direct transfer is offered: the switch is on AND all three details
     * are filled in. An account with a missing digit is worse than no account,
     * so a half-set-up card never reaches a customer.
     */
    public static function isEnabled(): bool
    {
        if (!Settings::bool('bank_transfer_enabled', false)) {
            return false;
        }
        $details = self::bankDetails();
        return $details['bank_name'] !== ''
            && $details['account_name'] !== ''
            && self::accountNumberIsValid($details['account_number']);
    }

    // -------------------------------------------------------------------------
    // Receipt files
    // -------------------------------------------------------------------------

    /**
     * Check a real HTTP upload before anything is written. Extension whitelist,
     * sniffed type, size cap; a photo also has to be a whole image, and a PDF
     * has to start like one.
     *
     * @param  array $file An entry from $_FILES.
     * @return array{ok: bool, code: string, message: string}
     */
    public static function validateReceiptUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            return self::refusal('missing');
        }
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return self::refusal('too_large');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return self::refusal('upload_failed');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp) || !is_file($tmp)) {
            return self::refusal('upload_failed');
        }

        $bytes = (int) filesize($tmp);
        $mime  = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $problem = self::receiptFileProblem((string) ($file['name'] ?? ''), $mime, $bytes, Uploads::maxBytes());
        if ($problem !== null) {
            return self::refusal($problem);
        }

        if ($mime === 'application/pdf') {
            $head = (string) file_get_contents($tmp, false, null, 0, 5);
            if ($head !== '%PDF-') {
                return self::refusal('unreadable_pdf');
            }
        } else {
            $info = @getimagesize($tmp);
            if (!is_array($info) || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1 || (string) ($info['mime'] ?? '') !== $mime) {
                return self::refusal('unreadable_image');
            }
        }

        return ['ok' => true, 'code' => 'ok', 'message' => ''];
    }

    /**
     * Store a receipt already passed by validateReceiptUpload(). Returns the
     * app-relative path under uploads/, with a random name, or throws.
     */
    public static function storeReceipt(array $file): string
    {
        return Uploads::saveUploadedFile($file, self::SUBDIR, array_keys(self::RECEIPT_TYPES));
    }

    /**
     * True when the browser sent a body larger than the server accepts. PHP then
     * hands the script an empty $_POST and $_FILES, so without this a big photo
     * reads as an expired session.
     */
    public static function postWasTooLarge(): bool
    {
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        return $length > 0 && empty($_POST) && empty($_FILES);
    }

    /** @return array{ok: false, code: string, message: string} */
    private static function refusal(string $code): array
    {
        return ['ok' => false, 'code' => $code, 'message' => self::receiptProblemMessage($code)];
    }

    // -------------------------------------------------------------------------
    // What the customer can pay by transfer, and who may ask
    // -------------------------------------------------------------------------

    /**
     * The payment a receipt would apply to right now, or null. It is the next
     * unpaid payment on the order that is settled by transfer: the deposit
     * before the balance. The order must be live and nothing may already be
     * waiting for review on it.
     */
    public static function nextTransferPayment(int $orderId): ?array
    {
        $order = Database::one('SELECT order_status FROM orders WHERE id = :id', [':id' => $orderId]);
        if (!$order || (string) $order['order_status'] === 'cancelled') {
            return null;
        }
        $payment = Database::one(
            'SELECT p.id, p.payment_type, p.expected_amount_subunit, p.paid_amount_subunit, p.status, p.due_at
               FROM payments p
              WHERE p.order_id = :order
                AND p.provider = \'manual\'
                AND p.payment_type IN (\'pay_in_full\', \'deposit\', \'balance\')
                AND p.status <> :paid
                AND p.expected_amount_subunit > p.paid_amount_subunit
              ORDER BY p.id
              LIMIT 1',
            [':order' => $orderId, ':paid' => Payments::STATUS_PAID]
        );
        if (!$payment) {
            return null;
        }
        if (self::awaitingProof((int) $payment['id']) !== null) {
            return null;
        }
        $payment['due_subunit'] = Money::balance((int) $payment['expected_amount_subunit'], (int) $payment['paid_amount_subunit']);
        return $payment;
    }

    /** The receipt waiting for review on one payment, or null. */
    public static function awaitingProof(int $paymentId): ?array
    {
        return Database::one(
            'SELECT mp.id AS proof_id, mp.amount_subunit, mp.created_at
               FROM manual_payment_proofs mp
               JOIN payment_transactions t ON t.id = mp.payment_transaction_id
              WHERE t.payment_id = :payment AND mp.status = :status
              LIMIT 1',
            [':payment' => $paymentId, ':status' => self::PROOF_SUBMITTED]
        );
    }

    /**
     * Whether this browser may act on the order: the signed-in owner, or, for a
     * guest order that has no account to sign in to, the holder of one of its
     * Order Trail tokens: the one minted at checkout, or a link issued into an
     * email since (the declined-receipt email carries one, so a guest can always
     * upload again). A token is refused the moment an account owns the order, so
     * it can never be used to act around a sign in. Sending a receipt moves no
     * money: a colleague still has to verify it.
     */
    public static function customerMayActOn(int $orderId, ?int $userId, string $token): bool
    {
        if ($orderId < 1) {
            return false;
        }
        if ($userId !== null) {
            return Database::one(
                'SELECT id FROM orders WHERE id = :id AND user_id = :user',
                [':id' => $orderId, ':user' => $userId]
            ) !== null;
        }
        if ($token === '' || !OrderTrail::isValidToken($token)) {
            return false;
        }
        $hash = OrderTrail::hashToken($token);
        return Database::one(
            'SELECT o.id
               FROM orders o
              WHERE o.id = :id AND o.user_id IS NULL
                AND (o.order_trail_token_hash = :hash
                     OR EXISTS (SELECT 1 FROM order_trail_share_links l
                                 WHERE l.order_id = o.id AND l.token_hash = :share_hash))',
            [':id' => $orderId, ':hash' => $hash, ':share_hash' => $hash]
        ) !== null;
    }

    // -------------------------------------------------------------------------
    // Submitting a receipt. Credits nothing.
    // -------------------------------------------------------------------------

    /**
     * Record a receipt the customer has uploaded. The amount is not typed by the
     * customer: it is what the payment is still owed, worked out here, so a
     * receipt is always for a figure we asked for.
     *
     * $input carries proof_url (already stored), and optionally bank_reference
     * and payer_name.
     */
    public static function submit(int $paymentId, array $input): array
    {
        $proofUrl = substr(trim((string) ($input['proof_url'] ?? '')), 0, 500);
        if ($paymentId < 1 || $proofUrl === '') {
            return ['ok' => false, 'code' => 'missing', 'message' => self::receiptProblemMessage('missing')];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $payment = Database::one(
                'SELECT p.*, o.order_number, o.order_status, o.id AS order_id,
                        COALESCE(NULLIF(u.email, \'\'), o.contact_email) AS email
                   FROM payments p
                   JOIN orders o ON o.id = p.order_id
                   LEFT JOIN users u ON u.id = p.user_id
                  WHERE p.id = :id
                  FOR UPDATE',
                [':id' => $paymentId]
            );
            if (!$payment || (string) $payment['provider'] !== 'manual' || !in_array((string) $payment['payment_type'], self::PAYABLE_TYPES, true)) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That payment could not be found.'];
            }
            if ((string) $payment['order_status'] === 'cancelled') {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'order_cancelled', 'message' => 'This order has been cancelled and cannot be paid.'];
            }
            $expected = (int) $payment['expected_amount_subunit'];
            $due      = Money::balance($expected, (int) $payment['paid_amount_subunit']);
            if ($due < 1 || (string) $payment['status'] === Payments::STATUS_PAID) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'nothing_due', 'message' => 'There is nothing left to pay on this payment.'];
            }
            if (self::awaitingProof($paymentId) !== null) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'already_submitted', 'message' => 'We already have a receipt for this payment and our team is checking it.'];
            }
            $email = substr(trim((string) ($payment['email'] ?? '')), 0, 255);
            if ($email === '') {
                $email = 'unknown@okveggies.invalid';
            }

            $attempt = 1 + (int) (Database::one(
                'SELECT COALESCE(MAX(attempt_number), 0) AS n FROM payment_transactions WHERE payment_id = :id',
                [':id' => $paymentId]
            )['n'] ?? 0);
            $reference = ManualPayments::reference((string) $payment['order_number'], ManualPayments::newToken());

            Database::run(
                'INSERT INTO payment_transactions
                    (payment_id, attempt_number, provider, reference, domain, status,
                     requested_amount_subunit, currency, customer_email, channel, gateway_response, ip_address)
                 VALUES (:pid, :attempt, \'manual\', :ref, :domain, :status,
                         :amount, :currency, :email, \'transfer\', :note, :ip)',
                [
                    ':pid'      => $paymentId,
                    ':attempt'  => $attempt,
                    ':ref'      => $reference,
                    ':domain'   => Paystack::domain(),
                    ':status'   => self::TXN_AWAITING,
                    ':amount'   => $due,
                    ':currency' => Money::CODE,
                    ':email'    => $email,
                    ':note'     => 'Receipt submitted by the customer, waiting for verification.',
                    ':ip'       => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
                ]
            );
            $txnId = (int) $pdo->lastInsertId();

            Database::run(
                'INSERT INTO manual_payment_proofs
                    (payment_transaction_id, method, proof_url, bank_reference, payer_name,
                     amount_subunit, status, recorded_by, submitted_by_customer)
                 VALUES (:txn, \'transfer\', :url, :bank_ref, :payer, :amount, :status, NULL, 1)',
                [
                    ':txn'      => $txnId,
                    ':url'      => $proofUrl,
                    ':bank_ref' => substr(trim((string) ($input['bank_reference'] ?? '')), 0, 150) ?: null,
                    ':payer'    => substr(trim((string) ($input['payer_name'] ?? '')), 0, 150) ?: null,
                    ':amount'   => $due,
                    ':status'   => self::PROOF_SUBMITTED,
                ]
            );
            $proofId = (int) $pdo->lastInsertId();

            Payments::writeHistory(
                $paymentId,
                $txnId,
                (string) $payment['status'],
                (string) $payment['status'],
                'customer',
                null,
                'Customer uploaded a bank transfer receipt for ' . $due . ' subunits. Nothing credited until staff verify it.'
            );

            $pdo->commit();
            return [
                'ok'             => true,
                'code'           => 'submitted',
                'order_id'       => (int) $payment['order_id'],
                'payment_id'     => $paymentId,
                'transaction_id' => $txnId,
                'proof_id'       => $proofId,
                'amount_subunit' => $due,
                'message'        => 'Your receipt is with our team. Your payment is pending verification.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Verifying and declining. Staff only; the caller has checked the permission.
    // -------------------------------------------------------------------------

    /**
     * Verify a receipt: staff have looked at the bank and $amount is what they
     * saw arrive. That figure, and not the one the customer declared, is what is
     * credited. A shortfall leaves the payment part paid and the balance owing; an
     * excess is credited as received and is left to the existing overpayment
     * review, so it is never hidden.
     */
    public static function verify(int $proofId, int $amount, string $note, int $staffId): array
    {
        if ($proofId < 1 || $staffId < 1) {
            return ['ok' => false, 'code' => 'bad_input', 'message' => 'Check the details and try again.'];
        }
        if ($amount < 1) {
            return ['ok' => false, 'code' => 'bad_amount', 'message' => 'Enter the amount you can see in the bank, greater than zero.'];
        }

        // Find the payment without a lock, then lock in the same order submit()
        // does (payment first) so the two can never wait on each other.
        $locator = Database::one(
            'SELECT t.payment_id
               FROM manual_payment_proofs mp
               JOIN payment_transactions t ON t.id = mp.payment_transaction_id
              WHERE mp.id = :id AND mp.submitted_by_customer = 1',
            [':id' => $proofId]
        );
        if (!$locator) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That receipt could not be found.'];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $payment = Database::one(
                'SELECT p.*, o.order_number, o.order_status, o.id AS order_id
                   FROM payments p
                   JOIN orders o ON o.id = p.order_id
                  WHERE p.id = :id
                  FOR UPDATE',
                [':id' => (int) $locator['payment_id']]
            );
            $proof = Database::one(
                'SELECT mp.*, t.id AS txn_id, t.status AS txn_status
                   FROM manual_payment_proofs mp
                   JOIN payment_transactions t ON t.id = mp.payment_transaction_id
                  WHERE mp.id = :id
                  FOR UPDATE',
                [':id' => $proofId]
            );
            if (!$payment || !$proof) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That receipt could not be found.'];
            }
            if ((string) $proof['status'] !== self::PROOF_SUBMITTED || (string) $proof['txn_status'] !== self::TXN_AWAITING) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'already_reviewed', 'message' => 'That receipt has already been looked at.'];
            }
            if ((string) $payment['order_status'] === 'cancelled') {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'order_cancelled', 'message' => 'This order was cancelled. Decline this receipt and arrange the refund with the customer.'];
            }

            $expected    = (int) $payment['expected_amount_subunit'];
            $alreadyPaid = (int) $payment['paid_amount_subunit'];
            $outstanding = Money::balance($expected, $alreadyPaid);
            $oldStatus   = (string) $payment['status'];
            $note        = substr(trim($note), 0, 500);

            // Manual money adds up: a balance can genuinely arrive in two
            // transfers. The new total and its status are worked out here, from
            // the row this transaction holds locked, and written as plain values.
            // They are not left to one UPDATE to derive from itself, because
            // MySQL evaluates the assignments of a single UPDATE left to right,
            // so a status computed from paid_amount_subunit in the same
            // statement would read the figure already moved on by the first
            // assignment and call a part payment paid.
            $newPaid   = $alreadyPaid + $amount;
            $newStatus = Payments::paymentStatus($newPaid, $expected);
            Database::run(
                'UPDATE payments
                    SET paid_amount_subunit = :paid_total,
                        status = :status,
                        confirmed_at = CASE WHEN :is_paid = 1 THEN NOW() ELSE confirmed_at END
                  WHERE id = :id',
                [
                    ':paid_total' => $newPaid,
                    ':status'     => $newStatus,
                    ':is_paid'    => $newStatus === Payments::STATUS_PAID ? 1 : 0,
                    ':id'         => (int) $payment['id'],
                ]
            );
            Database::run(
                // requested_amount_subunit follows the credited figure, exactly as
                // it does for money staff record by hand, because the dashboard
                // and the reconciliation both sum it. The figure the customer
                // declared stays on the proof (amount_subunit) for comparison.
                'UPDATE payment_transactions
                    SET status = :status, amount_subunit = :amount, requested_amount_subunit = :requested,
                        paid_at = NOW(), verified_at = NOW(), gateway_response = :note
                  WHERE id = :id AND status = :awaiting',
                [
                    ':status'   => Payments::TXN_SUCCESS,
                    ':amount'   => $amount,
                    ':requested' => $amount,
                    ':note'     => 'Verified by staff from a customer receipt.',
                    ':id'       => (int) $proof['txn_id'],
                    ':awaiting' => self::TXN_AWAITING,
                ]
            );
            Database::run(
                'UPDATE manual_payment_proofs
                    SET status = :status, verified_amount_subunit = :amount,
                        reviewed_by = :staff, review_note = :note, reviewed_at = NOW()
                  WHERE id = :id AND status = :submitted',
                [
                    ':status'    => self::PROOF_VERIFIED,
                    ':amount'    => $amount,
                    ':staff'     => $staffId,
                    ':note'      => $note !== '' ? $note : null,
                    ':id'        => $proofId,
                    ':submitted' => self::PROOF_SUBMITTED,
                ]
            );

            Payments::writeHistory(
                (int) $payment['id'],
                (int) $proof['txn_id'],
                $oldStatus,
                $newStatus,
                'admin',
                null,
                'Customer receipt verified by staff. ' . ManualPayments::confirmationLine($amount, $outstanding)
            );
            Payments::recomputeOrder((int) $payment['order_id']);

            $pdo->commit();
            return [
                'ok'             => true,
                'code'           => 'verified',
                'outcome'        => ManualPayments::outcomeKind($amount, $outstanding),
                'order_id'       => (int) $payment['order_id'],
                'payment_id'     => (int) $payment['id'],
                'payment_type'   => (string) $payment['payment_type'],
                'transaction_id' => (int) $proof['txn_id'],
                'proof_id'       => $proofId,
                'amount_subunit' => $amount,
                'declared_subunit' => (int) $proof['amount_subunit'],
                'message'        => ManualPayments::confirmationLine($amount, $outstanding),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Decline a receipt that cannot be matched to the bank. A reason is required
     * because the customer is sent it, and a decline with no reason is the kind of
     * silence that makes someone who really did pay ring us. Nothing is credited
     * and nothing is deleted: the receipt stays on file, closed.
     */
    public static function decline(int $proofId, string $reason, int $staffId): array
    {
        $reason = substr(trim($reason), 0, 500);
        if ($reason === '') {
            return ['ok' => false, 'code' => 'reason_required', 'message' => 'Say why the receipt could not be confirmed. The customer is sent this.'];
        }

        $locator = Database::one(
            'SELECT t.payment_id
               FROM manual_payment_proofs mp
               JOIN payment_transactions t ON t.id = mp.payment_transaction_id
              WHERE mp.id = :id AND mp.submitted_by_customer = 1',
            [':id' => $proofId]
        );
        if (!$locator) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That receipt could not be found.'];
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $payment = Database::one(
                'SELECT p.id, p.status, p.order_id FROM payments p WHERE p.id = :id FOR UPDATE',
                [':id' => (int) $locator['payment_id']]
            );
            $proof = Database::one(
                'SELECT mp.id, mp.status, mp.amount_subunit, t.id AS txn_id
                   FROM manual_payment_proofs mp
                   JOIN payment_transactions t ON t.id = mp.payment_transaction_id
                  WHERE mp.id = :id
                  FOR UPDATE',
                [':id' => $proofId]
            );
            if (!$payment || !$proof) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'not_found', 'message' => 'That receipt could not be found.'];
            }
            if ((string) $proof['status'] !== self::PROOF_SUBMITTED) {
                $pdo->rollBack();
                return ['ok' => false, 'code' => 'already_reviewed', 'message' => 'That receipt has already been looked at.'];
            }

            Database::run(
                'UPDATE manual_payment_proofs
                    SET status = :status, reviewed_by = :staff, review_note = :note, reviewed_at = NOW()
                  WHERE id = :id AND status = :submitted',
                [
                    ':status'    => self::PROOF_DECLINED,
                    ':staff'     => $staffId,
                    ':note'      => $reason,
                    ':id'        => $proofId,
                    ':submitted' => self::PROOF_SUBMITTED,
                ]
            );
            Database::run(
                'UPDATE payment_transactions
                    SET status = :status, gateway_response = :note, verified_at = NOW()
                  WHERE id = :id AND status = :awaiting',
                [
                    ':status'   => Payments::TXN_FAILED,
                    ':note'     => substr('Receipt declined: ' . $reason, 0, 255),
                    ':id'       => (int) $proof['txn_id'],
                    ':awaiting' => self::TXN_AWAITING,
                ]
            );
            Payments::writeHistory(
                (int) $payment['id'],
                (int) $proof['txn_id'],
                (string) $payment['status'],
                (string) $payment['status'],
                'admin',
                null,
                'Customer receipt declined by staff. Nothing credited. ' . $reason
            );

            $pdo->commit();
            return [
                'ok'             => true,
                'code'           => 'declined',
                'order_id'       => (int) $payment['order_id'],
                'payment_id'     => (int) $payment['id'],
                'proof_id'       => $proofId,
                'amount_subunit' => (int) $proof['amount_subunit'],
                'reason'         => $reason,
                'message'        => 'Receipt declined. The customer has been told and can upload another.',
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // The gate on confirming an order
    // -------------------------------------------------------------------------

    /**
     * Why staff may not confirm this order yet, or null when they may. An order
     * that was placed to be paid by transfer is held until a payment has been
     * verified, because "we are sourcing it now" is not true of an order nobody
     * has paid for. Verifying, or staff recording the money by hand, clears it.
     * Nothing here ever cancels anything by itself.
     */
    public static function confirmationBlock(int $orderId): ?string
    {
        $held = Database::one(
            'SELECT p.id
               FROM payments p
              WHERE p.order_id = :order
                AND p.provider = \'manual\'
                AND p.payment_type IN (\'pay_in_full\', \'deposit\')
                AND p.paid_amount_subunit = 0
              ORDER BY p.id
              LIMIT 1',
            [':order' => $orderId]
        );
        if (!$held) {
            return null;
        }
        return self::awaitingProof((int) $held['id']) !== null
            ? 'This order is waiting for its bank transfer to be verified. Verify the receipt in Payments, then confirm the order.'
            : 'This order is waiting for its bank transfer. Confirm it once a payment has been verified or recorded.';
    }
}
