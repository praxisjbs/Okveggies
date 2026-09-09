<?php
/**
 * includes/classes/KitchenRunWorkflow.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The only thing that changes a Kitchen Run (PRD Section 8).
 *
 * KitchenRuns.php holds the rules and the read path. This class holds every
 * write, the same way SettingsEditor is the only thing that writes a setting.
 * Four things are worth knowing before you change anything in here.
 *
 *   Every state change goes through one gate. transition() checks
 *   KitchenRuns::mayTransition(), moves the row, bumps the version and writes a
 *   kitchen_run_status_history line. There is no other way to move a request,
 *   so the state map is the truth rather than a decoration.
 *
 *   Money is never trusted from the request. Catalogue prices are read from
 *   products on the server, quote totals are summed from the lines on the
 *   server, and conversion re-sums the stored lines again and proves the answer
 *   equals the figure the customer approved. A posted total is never stored.
 *
 *   Conversion is idempotent and atomic. The request row is locked for the
 *   length of the transaction and converted_order_id is the record: a double
 *   submit, a retry or a second colleague gets the first order back rather than
 *   a second one.
 *
 *   A converted run is an ordinary order. It gets the same address snapshot,
 *   trail token, status history, delivery schedule and payment rows as a
 *   checkout order, written by the same Checkout methods, so the day manifest,
 *   the packing list, the documents and the public Order Trail cannot tell the
 *   two apart. That is the whole point: the customer who sent a list gets the
 *   same order everybody else gets.
 * -----------------------------------------------------------------------------
 */

final class KitchenRunWorkflow
{
    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * A customer sends a list. Their words and their numbers are stored exactly
     * as they arrived in original_submission_json, so a later argument about
     * what was asked for is settled by the record rather than by memory.
     *
     * The delivery address is captured here, with the list, so conversion has a
     * snapshot to copy into order_addresses and the run reaches the day
     * manifest with a recipient on it.
     */
    public static function submit(int $userId, string $customerType, array $input, ?string $attachment = null): array
    {
        if ($userId < 1 || !in_array($customerType, Customer::TYPES, true)) {
            throw new DomainException('bad_customer');
        }

        $mode = (string) ($input['input_mode'] ?? '');
        if (!in_array($mode, KitchenRuns::MODES, true)) {
            throw new DomainException('bad_mode');
        }
        $pricing = (string) ($input['pricing_mode'] ?? 'by_us');
        if (!in_array($pricing, KitchenRuns::PRICING_MODES, true)) {
            throw new DomainException('bad_pricing_mode');
        }

        $open = !empty($input['is_open_budget']);
        if ($open && $pricing !== 'by_us') {
            // Open budget means "source it, you price it". The two ideas cannot
            // both be true of one list.
            throw new DomainException('open_budget_pricing');
        }
        if ($mode === 'upload' && $attachment === null) {
            throw new DomainException('attachment_required');
        }

        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        $lines = self::submissionLines($mode, $pricing, $items, $attachment !== null);

        $cap    = KitchenRuns::optionalMoney($input['spend_cap_subunit'] ?? null);
        $budget = KitchenRuns::optionalMoney($input['budget_ceiling_subunit'] ?? null);
        if (!$open && ($cap !== null || $budget !== null)) {
            throw new DomainException('budget_not_open');
        }

        $address  = self::validateAddress($input);

        // The day and the area are the customer's choice now, not something
        // staff fill in later on their behalf (PRD 8.3, and PRD 9.4 for the
        // picker itself). Checked here on the way in, the same way checkout
        // checks it, so nobody can ask for a day the shop does not run. Staff
        // may still change both at quote time, because a day can pass while a
        // request waits for a price.
        $date = trim((string) ($input['preferred_delivery_date'] ?? ''));
        $zone = KitchenRuns::positiveInt($input['delivery_zone_id'] ?? null);
        if ($date === '' || $zone === null) {
            throw new DomainException('delivery_required');
        }
        self::assertDeliverable($date, $customerType, $zone);
        $customer = Database::one(
            'SELECT first_name, last_name, email, phone FROM users WHERE id = :id AND status = \'active\'',
            [':id' => $userId]
        );
        if (!$customer) {
            throw new DomainException('bad_customer');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $number = OrderNumber::nextKitchenRunNumber($pdo);
            Database::run(
                'INSERT INTO kitchen_run_requests
                    (request_number, user_id, customer_type, contact_name, contact_phone, contact_email,
                     delivery_recipient_name, delivery_recipient_phone, delivery_address_line_1,
                     delivery_address_line_2, delivery_city, delivery_state, delivery_landmark,
                     input_mode, pricing_mode, status, is_open_budget,
                     budget_ceiling_subunit, spend_cap_subunit, attachment_url,
                     preferred_delivery_date, delivery_zone_id,
                     original_submission_json, customer_note, created_by)
                 VALUES
                    (:number, :user_id, :type, :contact_name, :contact_phone, :contact_email,
                     :recipient_name, :recipient_phone, :line1,
                     :line2, :city, :state, :landmark,
                     :mode, :pricing, \'submitted\', :open,
                     :budget, :cap, :attachment,
                     :date, :zone,
                     :original, :note, :created_by)',
                [
                    ':number'         => $number,
                    ':user_id'        => $userId,
                    ':type'           => $customerType,
                    ':contact_name'   => trim($customer['first_name'] . ' ' . $customer['last_name']),
                    ':contact_phone'  => $customer['phone'],
                    ':contact_email'  => $customer['email'],
                    ':recipient_name' => $address['recipient_name'],
                    ':recipient_phone' => $address['recipient_phone'],
                    ':line1'          => $address['address_line_1'],
                    ':line2'          => $address['address_line_2'],
                    ':city'           => $address['city'],
                    ':state'          => $address['state'],
                    ':landmark'       => $address['landmark'],
                    ':mode'           => $mode,
                    ':pricing'        => $pricing,
                    ':open'           => $open ? 1 : 0,
                    ':budget'         => $budget,
                    ':cap'            => $cap,
                    ':attachment'     => $attachment,
                    ':date'           => $date,
                    ':zone'           => $zone,
                    ':original'       => json_encode(self::auditable($input), JSON_THROW_ON_ERROR),
                    ':note'           => KitchenRuns::note($input['customer_note'] ?? ''),
                    ':created_by'     => $userId,
                ]
            );
            $id = (int) $pdo->lastInsertId();

            self::insertLines($pdo, $id, $lines);
            self::writeHistory($id, null, 'submitted', 'customer', $userId, 'List received with ' . count($lines) . ' ' . (count($lines) === 1 ? 'line' : 'lines') . '.');

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $id, 'request_number' => $number, 'status' => 'submitted', 'line_count' => count($lines)];
    }

    /**
     * Staff price the list. The whole line set is replaced, so staff can add a
     * line a customer forgot, drop one we cannot get, and transcribe an
     * uploaded list into real lines. That is the only way PRD 8.1 mode 3 can
     * work: an upload arrives as one placeholder and has to become a list.
     *
     * Compare-and-swap on state_version, so two people pricing the same request
     * in two tabs cannot silently overwrite one another. Re-pricing a request
     * that is already Quoted is allowed and clears the approval, because a
     * customer must never end up having approved figures they never saw.
     */
    public static function quote(int $requestId, int $staffId, int $version, array $input): array
    {
        $items = is_array($input['items'] ?? null) ? $input['items'] : [];
        foreach ($items as $line) {
            if (!is_array($line) || KitchenRuns::positiveInt($line['unit_id'] ?? null) === null) {
                throw new DomainException('quantity_unit_required');
            }
        }
        $quoted = KitchenRuns::quoteLines($items);

        $deposit = KitchenRuns::optionalMoney($input['deposit_subunit'] ?? null);
        if ($deposit !== null && $deposit > $quoted['total_subunit']) {
            throw new DomainException('deposit_above_total');
        }

        $date = trim((string) ($input['preferred_delivery_date'] ?? ''));
        $zone = KitchenRuns::positiveInt($input['delivery_zone_id'] ?? null);
        if ($date === '' || $zone === null) {
            throw new DomainException('delivery_required');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $request = self::lockRequest($pdo, $requestId);
            self::assertVersion($request, $version);

            $from = (string) $request['status'];
            if (!KitchenRuns::mayTransition($from, 'quoted')) {
                throw new DomainException('stale');
            }
            if (!empty($request['is_open_budget']) && $deposit === null) {
                // PRD 8.2: an open budget is only safe for both sides if we ask
                // for something up front, and the amount is a judgement call.
                throw new DomainException('deposit_required');
            }

            $cap = $request['spend_cap_subunit'] === null ? null : (int) $request['spend_cap_subunit'];
            if (!KitchenRuns::withinCap($quoted['total_subunit'], $cap)) {
                throw new DomainException('cap_exceeded');
            }
            self::assertDeliverable($date, (string) $request['customer_type'], $zone);

            Database::run('DELETE FROM kitchen_run_items WHERE request_id = :id', [':id' => $requestId]);
            self::insertLines($pdo, $requestId, $quoted['lines']);

            Database::run(
                'UPDATE kitchen_run_requests
                    SET quoted_total_subunit    = :quoted_total,
                        estimated_total_subunit = :estimated_total,
                        deposit_subunit         = :deposit,
                        deposit_status          = :deposit_status,
                        preferred_delivery_date = :date,
                        delivery_zone_id        = :zone,
                        admin_note              = :note,
                        quoted_by               = :staff,
                        quoted_at               = NOW(),
                        approved_at             = NULL
                  WHERE id = :id',
                [
                    ':quoted_total'    => $quoted['total_subunit'],
                    ':estimated_total' => $quoted['total_subunit'],
                    ':deposit'         => $deposit,
                    ':deposit_status'  => $deposit === null ? 'none' : 'pending',
                    ':date'            => $date,
                    ':zone'            => $zone,
                    ':note'            => KitchenRuns::note($input['admin_note'] ?? ''),
                    ':staff'           => $staffId,
                    ':id'              => $requestId,
                ]
            );

            self::transition($requestId, $from, 'quoted', 'admin', $staffId, self::quoteNote($from, $quoted['total_subunit']));
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'            => $requestId,
            'status'        => 'quoted',
            'total_subunit' => $quoted['total_subunit'],
            'version'       => $version + 1,
        ];
    }

    /**
     * The customer says yes to the figures in front of them. An expired quote
     * cannot be approved: the prices behind it have moved, so it goes back to
     * Submitted and staff price it again.
     */
    public static function approve(int $requestId, int $userId, int $version): array
    {
        return self::applyApproval($requestId, $version, 'customer', $userId, $userId, 'Customer approved the quote.');
    }

    /**
     * Staff say yes on the customer's behalf, because the customer said yes on
     * the phone. This is a real thing in this business: a restaurant manager
     * standing over a pot rings us rather than opening a laptop, and refusing
     * to record that would only push it into a WhatsApp message nobody can
     * audit later.
     *
     * So it is gated on kitchen_runs.approve, it demands in writing who gave
     * the approval and how it reached us, and the trail records it as an admin
     * action with the colleague named. A reader of the history can always tell
     * an approval the customer made from one we made for them.
     */
    public static function approveForCustomer(int $requestId, int $staffId, int $version, string $authorisation): array
    {
        $note = KitchenRuns::note($authorisation);
        if ($note === null) {
            throw new DomainException('authorisation_required');
        }
        return self::applyApproval($requestId, $version, 'admin', $staffId, null, 'Approved for the customer by staff. ' . $note);
    }

    /**
     * One approval, whoever pressed it. The expiry rule is the same for both,
     * because it is a money rule: a quote nobody honours any more cannot be
     * approved over the phone either.
     *
     * $ownerId is the customer whose request this must be, or null when a
     * colleague with the permission is approving it for them.
     */
    private static function applyApproval(int $requestId, int $version, string $source, ?int $actorId, ?int $ownerId, string $note): array
    {
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $request = self::lockRequest($pdo, $requestId);

            if ($ownerId !== null) {
                if ((int) $request['user_id'] !== $ownerId) {
                    throw new DomainException('stale_or_not_owned');
                }
                if ((int) $request['state_version'] !== $version || $request['status'] !== 'quoted') {
                    throw new DomainException('stale_or_not_owned');
                }
            } else {
                self::assertVersion($request, $version);
                if ($request['status'] !== 'quoted') {
                    throw new DomainException('not_quoted');
                }
            }

            $quotedAt = (string) ($request['quoted_at'] ?? '');
            if ($quotedAt !== '' && KitchenRuns::quoteExpired($quotedAt, KitchenRuns::quoteDays(), date('Y-m-d H:i:s'))) {
                self::transition($requestId, 'quoted', 'submitted', 'system', null, 'The quote expired before it was approved, so it is open for pricing again.');
                $pdo->commit();
                throw new DomainException('quote_expired');
            }

            Database::run('UPDATE kitchen_run_requests SET approved_at = NOW() WHERE id = :id', [':id' => $requestId]);
            self::transition($requestId, 'quoted', 'approved', $source, $actorId, $note);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $requestId, 'status' => 'approved', 'version' => $version + 1];
    }

    /** Staff turn a request down. The reason is written and it reaches the customer. */
    public static function decline(int $requestId, int $staffId, int $version, string $reason): array
    {
        $reason = KitchenRuns::note($reason);
        if ($reason === null) {
            throw new DomainException('reason_required');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $request = self::lockRequest($pdo, $requestId);
            self::assertVersion($request, $version);
            $from = (string) $request['status'];
            if (!KitchenRuns::mayTransition($from, 'declined')) {
                throw new DomainException('stale');
            }

            Database::run('UPDATE kitchen_run_requests SET admin_note = :note WHERE id = :id', [':note' => $reason, ':id' => $requestId]);
            self::transition($requestId, $from, 'declined', 'admin', $staffId, $reason);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $requestId, 'status' => 'declined', 'version' => $version + 1];
    }

    /**
     * The customer withdraws their own list, any time before it is an order.
     *
     * The state it was withdrawn from comes back with the result, because
     * withdrawing an approved run is not the same event as withdrawing one
     * nobody has priced: our own cash may already be at the market against it,
     * so the controller emails the team on that one.
     */
    public static function cancel(int $requestId, int $userId, int $version): array
    {
        $from = '';
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $request = self::lockRequest($pdo, $requestId);
            if ((int) $request['user_id'] !== $userId) {
                throw new DomainException('stale_or_not_owned');
            }
            if ((int) $request['state_version'] !== $version) {
                throw new DomainException('stale_or_not_owned');
            }
            $from = (string) $request['status'];
            if (!KitchenRuns::mayTransition($from, 'cancelled')) {
                throw new DomainException('stale_or_not_owned');
            }

            self::transition($requestId, $from, 'cancelled', 'customer', $userId, $from === 'approved'
                ? 'Customer withdrew the request after approving it. Check whether anything has been bought for it.'
                : 'Customer withdrew the request.');
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['id' => $requestId, 'status' => 'cancelled', 'from' => $from, 'version' => $version + 1];
    }

    /**
     * The internal note, for the team only (item 22 of the M7 review).
     *
     * admin_note is written for the customer: it is printed on their quote and
     * it is the sentence they are emailed when we decline. This one is the
     * other kind of note, the frank one, and it goes in its own column exactly
     * the way orders.staff_note does. Nothing renders it outside the admin
     * panel and no API a customer can reach returns it.
     *
     * It is not a state change, so it does not go through transition(): the
     * request has not moved, and a note is not a lifecycle event.
     */
    public static function saveStaffNote(int $requestId, int $staffId, string $note): array
    {
        $clean = KitchenRuns::note($note);
        if ($requestId < 1 || !Database::one('SELECT id FROM kitchen_run_requests WHERE id = :id', [':id' => $requestId])) {
            throw new DomainException('not_found');
        }

        Database::run(
            'UPDATE kitchen_run_requests SET staff_note = :note WHERE id = :id',
            [':note' => $clean, ':id' => $requestId]
        );

        return ['id' => $requestId, 'saved' => true, 'staff_id' => $staffId];
    }

    /**
     * Turn an approved request into an ordinary order.
     *
     * Idempotent: the request row is locked and converted_order_id is the
     * record, so a double submit, a retried request or a second staff member
     * returns the first order rather than making a second one.
     *
     * Everything the order needs is written here, the same way checkout writes
     * it: the address snapshot, the trail token, the line snapshot, the first
     * status event, the delivery schedule and the unpaid payment rows. A
     * converted run is indistinguishable from a checkout order downstream, and
     * that is the point.
     */
    public static function convert(int $requestId, int $staffId, int $version, string $paymentOption, ?string $deliveryDate = null): array
    {
        if (!in_array($paymentOption, KitchenRuns::PAYMENT_OPTIONS, true)) {
            throw new DomainException('payment_not_allowed');
        }

        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $request = self::lockRequest($pdo, $requestId);

            if ($request['converted_order_id'] !== null) {
                $order = Database::one(
                    'SELECT id, order_number FROM orders WHERE id = :id',
                    [':id' => $request['converted_order_id']]
                );
                $pdo->commit();
                if (!$order) {
                    throw new DomainException('not_found');
                }
                return [
                    'id'               => (int) $order['id'],
                    'order_number'     => (string) $order['order_number'],
                    'already_converted' => true,
                    'trail_token'      => '',
                ];
            }

            self::assertVersion($request, $version);
            if (!KitchenRuns::mayTransition((string) $request['status'], 'converted')) {
                throw new DomainException('stale');
            }

            // The facility is read, never assumed. This used to pass "true"
            // whenever the option was on account, which asked the pure rule a
            // question it had already answered itself.
            $facility = (string) $request['customer_type'] === 'business'
                ? Credit::facilityForUser((int) $request['user_id'])
                : null;
            $creditApproved = $facility !== null && (string) ($facility['state'] ?? '') === 'approved';
            if (!KitchenRuns::paymentAllowed($paymentOption, (string) $request['customer_type'], !empty($request['is_open_budget']), $creditApproved)) {
                throw new DomainException('payment_not_allowed');
            }

            // Re-sum the stored lines. The quoted total on the row is what we
            // told the customer; this is what we are about to charge, and the
            // two are proved equal rather than assumed equal.
            $lines  = self::linesForConversion($requestId);
            $quoted = KitchenRuns::quoteLines($lines);
            $total  = $quoted['total_subunit'];
            if ($total < 1) {
                throw new DomainException('no_items');
            }
            if ((int) $request['quoted_total_subunit'] !== $total) {
                throw new DomainException('total_moved');
            }

            $cap = $request['spend_cap_subunit'] === null ? null : (int) $request['spend_cap_subunit'];
            if (!KitchenRuns::withinCap($total, $cap)) {
                throw new DomainException('cap_exceeded');
            }

            // An over-limit run is refused before the order row is written, on
            // the same rule checkout uses. Credit::drawForOrder checks it once
            // more under a lock when the charge is appended.
            if ($paymentOption === 'on_account') {
                $refusal = Credit::drawRefusal($facility, $total);
                if ($refusal !== '') {
                    throw new DomainException($refusal);
                }
            }

            $date = trim((string) ($deliveryDate ?? '')) !== ''
                ? trim((string) $deliveryDate)
                : (string) $request['preferred_delivery_date'];
            $zoneId = (int) $request['delivery_zone_id'];
            self::assertDeliverable($date, (string) $request['customer_type'], $zoneId);

            $address = self::addressFromRequest($request);

            // The deposit is the staff judgement on the request when there is
            // one (PRD 8.2), and otherwise the site's standard percentage.
            $percentage = null;
            if ($paymentOption === 'deposit') {
                $deposit = $request['deposit_subunit'] === null ? null : (int) $request['deposit_subunit'];
                if ($deposit === null) {
                    $percentage = Settings::depositPercentage();
                    $deposit    = Money::deposit($total, $percentage);
                }
                if ($deposit < 1 || $deposit > $total) {
                    throw new DomainException('deposit_required');
                }
            } else {
                $deposit = null;
            }
            $due = $paymentOption === 'deposit' ? (int) $deposit : $total;

            $orderNumber = OrderNumber::nextOrderNumber($pdo);
            $token       = Checkout::freshTrailToken();

            Database::run(
                'INSERT INTO orders
                    (order_number, order_trail_token_hash, user_id, customer_type,
                     order_status, payment_option, payment_status, subtotal_subunit, order_total_subunit,
                     deposit_percentage, deposit_required_subunit, balance_due_subunit,
                     preferred_delivery_date, delivery_zone_id, delivery_fee_note, customer_note, created_by)
                 VALUES
                    (:number, :token, :user_id, :type,
                     \'pending\', :option, \'unpaid\', :subtotal, :order_total,
                     :percentage, :deposit, :balance,
                     :date, :zone, :fee, :note, :created_by)',
                [
                    ':number'      => $orderNumber,
                    ':token'       => Checkout::hashToken($token),
                    ':user_id'     => $request['user_id'],
                    ':type'        => $request['customer_type'],
                    ':option'      => $paymentOption,
                    ':subtotal'    => $total,
                    ':order_total' => $total,
                    ':percentage'  => $percentage,
                    ':deposit'     => $deposit,
                    ':balance'     => $total,
                    ':date'        => $date,
                    ':zone'        => $zoneId,
                    ':fee'         => 'Delivery fee is arranged and settled separately after we confirm your area.',
                    ':note'        => $request['customer_note'],
                    ':created_by'  => $staffId,
                ]
            );
            $orderId = (int) $pdo->lastInsertId();
            if ($paymentOption === 'on_account') {
                Credit::drawForOrder((int) $request['user_id'], $orderId, $total, $date);
            }

            Checkout::writeAddress($orderId, (int) $request['user_id'], $address);
            self::insertOrderLines($pdo, $orderId, $quoted['lines']);

            Database::run(
                'INSERT INTO order_status_history (order_id, old_status, new_status, source, changed_by, note)
                 VALUES (:order, NULL, \'pending\', \'kitchen_run\', :staff, :note)',
                [
                    ':order' => $orderId,
                    ':staff' => $staffId,
                    ':note'  => 'Made from Kitchen Run ' . $request['request_number'] . '.',
                ]
            );
            Database::run(
                'INSERT INTO delivery_schedules (order_id, delivery_date, status, updated_by)
                 VALUES (:order, :date, \'scheduled\', :staff)',
                [':order' => $orderId, ':date' => $date, ':staff' => $staffId]
            );
            Checkout::writePayments($orderId, (int) $request['user_id'], $orderNumber, $paymentOption, $total, $due, $date);

            Database::run(
                'UPDATE kitchen_run_requests
                    SET converted_order_id = :order,
                        deposit_status = :deposit_status,
                        preferred_delivery_date = :date
                  WHERE id = :id',
                [
                    ':order' => $orderId,
                    ':deposit_status' => $paymentOption === 'deposit' ? 'pending' : 'none',
                    ':date'  => $date,
                    ':id'    => $requestId,
                ]
            );
            self::transition($requestId, 'approved', 'converted', 'admin', $staffId, 'Made into order ' . $orderNumber . '.');

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'id'                => $orderId,
            'order_number'      => $orderNumber,
            'already_converted' => false,
            'trail_token'       => $token,
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /** Lock the request for the length of the transaction, or say it is gone. */
    private static function lockRequest(PDO $pdo, int $requestId): array
    {
        if ($requestId < 1) {
            throw new DomainException('not_found');
        }
        $row = Database::one('SELECT * FROM kitchen_run_requests WHERE id = :id FOR UPDATE', [':id' => $requestId]);
        if (!$row) {
            throw new DomainException('not_found');
        }
        return $row;
    }

    private static function assertVersion(array $request, int $version): void
    {
        if ((int) $request['state_version'] !== $version) {
            throw new DomainException('stale');
        }
    }

    /**
     * The one gate every state change passes through. It refuses a move the
     * lifecycle does not allow, bumps the version so anyone holding the old one
     * is told rather than silently overwritten, and writes the trail line.
     */
    private static function transition(int $requestId, ?string $from, string $to, string $source, ?int $actorId, ?string $note): void
    {
        if ($from !== null && !KitchenRuns::mayTransition($from, $to)) {
            throw new DomainException('illegal_transition');
        }

        Database::run(
            'UPDATE kitchen_run_requests SET status = :to, state_version = state_version + 1 WHERE id = :id',
            [':to' => $to, ':id' => $requestId]
        );
        self::writeHistory($requestId, $from, $to, $source, $actorId, $note);
    }

    private static function writeHistory(int $requestId, ?string $from, string $to, string $source, ?int $actorId, ?string $note): void
    {
        Database::run(
            'INSERT INTO kitchen_run_status_history (request_id, old_status, new_status, source, changed_by, note)
             VALUES (:request, :old, :new, :source, :actor, :note)',
            [
                ':request' => $requestId,
                ':old'     => $from,
                ':new'     => $to,
                ':source'  => $source,
                ':actor'   => $actorId,
                ':note'    => $note === null ? null : mb_substr($note, 0, 500),
            ]
        );
    }

    private static function quoteNote(string $from, int $total): string
    {
        return ($from === 'quoted' ? 'Quote corrected and sent again at ' : 'Quote sent at ')
            . Money::format($total) . '. Any earlier approval was cleared.';
    }

    /** A delivery date and area we can actually serve, checked the same way checkout checks it. */
    private static function assertDeliverable(string $date, string $customerType, int $zoneId): void
    {
        $eligibility = Delivery::isEligible($date, $customerType);
        if (empty($eligibility['eligible'])) {
            throw new DomainException('delivery_unavailable');
        }
        if (!Database::one('SELECT id FROM delivery_zones WHERE id = :id AND is_active = 1', [':id' => $zoneId])) {
            throw new DomainException('zone_unavailable');
        }
    }

    /**
     * Normalise the customer's lines. A catalogue line is hydrated from the
     * products table, never from what was posted; a free-text line is kept in
     * the customer's own words. The two may sit in one list, because a real
     * kitchen list does.
     */
    private static function submissionLines(string $mode, string $pricing, array $items, bool $hasAttachment): array
    {
        if (!$items) {
            if ($mode === 'upload' && $hasAttachment) {
                // The list is in the attachment. Staff transcribe it into real
                // lines when they price it, and this placeholder holds its place
                // until then.
                return [[
                    'product_id'         => null,
                    'item_name'          => 'List awaiting transcription',
                    'quantity'           => null,
                    'unit_id'            => null,
                    'unit_label'         => null,
                    'unit_price_subunit' => null,
                    'line_total_subunit' => null,
                    'price_source'       => 'admin',
                    'note'               => null,
                ]];
            }
            throw new DomainException('no_items');
        }

        $check = KitchenRuns::validateSubmission($mode, $pricing, $items);
        if (empty($check['ok'])) {
            throw new DomainException((string) $check['error']);
        }

        $out = [];
        foreach ($items as $item) {
            $productId = KitchenRuns::positiveInt($item['product_id'] ?? null);
            $quantity  = KitchenRuns::quantity($item['quantity'] ?? null);
            $unitId    = KitchenRuns::positiveInt($item['unit_id'] ?? null);
            $price     = KitchenRuns::optionalMoney($item['unit_price_subunit'] ?? $item['target_price_subunit'] ?? null);

            $out[] = [
                'product_id'         => $productId,
                'item_name'          => trim((string) ($item['item_name'] ?? '')),
                'quantity'           => $quantity,
                'unit_id'            => $unitId,
                'unit_label'         => KitchenRuns::shortNote($item['unit_label'] ?? ''),
                'unit_price_subunit' => $price,
                'line_total_subunit' => ($quantity !== null && $price !== null) ? Money::lineTotal($quantity, $price) : null,
                'price_source'       => $pricing === 'by_us' ? 'admin' : 'customer',
                'note'               => KitchenRuns::shortNote($item['note'] ?? ''),
            ];
        }

        return self::hydrateCatalogueLines($out);
    }

    /**
     * Catalogue names and prices are read on the server. A posted price is
     * never stored, so a customer editing the form cannot set their own price
     * on one of our products. One query for the whole list, not one per line.
     */
    private static function hydrateCatalogueLines(array $lines): array
    {
        $ids = [];
        foreach ($lines as $line) {
            if ($line['product_id'] !== null) {
                $ids[(int) $line['product_id']] = true;
            }
        }
        if (!$ids) {
            return $lines;
        }

        $ids   = array_keys($ids);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt  = Database::getInstance()->getConnection()->prepare(
            'SELECT p.id, p.name, p.current_price_subunit, p.unit_id, u.name AS unit_name
               FROM products p
               JOIN units_of_measurement u ON u.id = p.unit_id
              WHERE p.id IN (' . $marks . ') AND p.is_active = 1'
        );
        $stmt->execute($ids);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(int) $row['id']] = $row;
        }

        foreach ($lines as $index => $line) {
            if ($line['product_id'] === null) {
                continue;
            }
            $row = $found[(int) $line['product_id']] ?? null;
            if (!$row || $row['current_price_subunit'] === null) {
                throw new DomainException('invalid_catalogue_item');
            }
            $price = (int) $row['current_price_subunit'];

            $lines[$index]['item_name']          = (string) $row['name'];
            $lines[$index]['unit_id']            = (int) $row['unit_id'];
            $lines[$index]['unit_label']         = (string) $row['unit_name'];
            $lines[$index]['unit_price_subunit'] = $price;
            $lines[$index]['line_total_subunit'] = $line['quantity'] === null ? null : Money::lineTotal($line['quantity'], $price);
            $lines[$index]['price_source']       = 'catalogue';
        }

        return $lines;
    }

    /** The stored lines, with the unit resolved, ready to become order lines. */
    private static function linesForConversion(int $requestId): array
    {
        return Database::all(
            'SELECT i.*, COALESCE(u.name, i.unit_label, \'unit\') AS resolved_unit, p.sku
               FROM kitchen_run_items i
               LEFT JOIN units_of_measurement u ON u.id = i.unit_id
               LEFT JOIN products p ON p.id = i.product_id
              WHERE i.request_id = :id
              ORDER BY i.sort_order, i.id',
            [':id' => $requestId]
        );
    }

    private static function insertLines(PDO $pdo, int $requestId, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO kitchen_run_items
                (request_id, product_id, item_name, quantity, unit_id, unit_label,
                 unit_price_subunit, line_total_subunit, price_source, sort_order, note)
             VALUES (:request, :product, :name, :quantity, :unit, :label, :price, :total, :source, :sort, :note)'
        );
        foreach ($lines as $index => $line) {
            $stmt->execute([
                ':request'  => $requestId,
                ':product'  => $line['product_id'] ?? null,
                ':name'     => $line['item_name'],
                ':quantity' => $line['quantity'] ?? null,
                ':unit'     => $line['unit_id'] ?? null,
                ':label'    => $line['unit_label'] ?? null,
                ':price'    => $line['unit_price_subunit'] ?? null,
                ':total'    => $line['line_total_subunit'] ?? null,
                ':source'   => $line['price_source'] ?? 'admin',
                ':sort'     => $index,
                ':note'     => $line['note'] ?? null,
            ]);
        }
    }

    /**
     * The immutable order-line snapshot. A Kitchen Run line is a product line
     * whether or not it came from the catalogue, and a line that never had a
     * catalogue product carries the KITCHEN-RUN sku so the packing list still
     * reads.
     */
    private static function insertOrderLines(PDO $pdo, int $orderId, array $lines): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO order_items
                (order_id, item_type, product_id, item_name, sku, unit_name,
                 quantity, unit_price_subunit, line_total_subunit)
             VALUES (:order, \'product\', :product, :name, :sku, :unit, :quantity, :price, :total)'
        );
        foreach ($lines as $line) {
            $stmt->execute([
                ':order'    => $orderId,
                ':product'  => $line['product_id'] ?? null,
                ':name'     => $line['item_name'],
                ':sku'      => trim((string) ($line['sku'] ?? '')) !== '' ? $line['sku'] : 'KITCHEN-RUN',
                ':unit'     => $line['resolved_unit'] ?? $line['unit_label'] ?? 'unit',
                ':quantity' => $line['quantity'],
                ':price'    => $line['unit_price_subunit'],
                ':total'    => $line['line_total_subunit'],
            ]);
        }
    }

    /** The delivery address the customer gave with the list. Same rules as checkout. */
    private static function validateAddress(array $input): array
    {
        $required = ['recipient_name', 'recipient_phone', 'address_line_1', 'city', 'state'];
        foreach ($required as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new DomainException('bad_address');
            }
        }
        $phone = Phone::normalize((string) $input['recipient_phone']);
        if ($phone === null) {
            throw new DomainException('bad_address');
        }

        return [
            'recipient_name'  => mb_substr(trim((string) $input['recipient_name']), 0, 150),
            'recipient_phone' => $phone,
            'address_line_1'  => mb_substr(trim((string) $input['address_line_1']), 0, 255),
            'address_line_2'  => mb_substr(trim((string) ($input['address_line_2'] ?? '')), 0, 255) ?: null,
            'city'            => mb_substr(trim((string) $input['city']), 0, 100),
            'state'           => mb_substr(trim((string) $input['state']), 0, 100),
            'landmark'        => mb_substr(trim((string) ($input['landmark'] ?? '')), 0, 255) ?: null,
        ];
    }

    /** The stored address, in the shape Checkout::writeAddress() expects. */
    private static function addressFromRequest(array $request): array
    {
        if (trim((string) ($request['delivery_address_line_1'] ?? '')) === '') {
            throw new DomainException('bad_address');
        }
        return [
            'recipient_name'  => (string) ($request['delivery_recipient_name'] ?: $request['contact_name']),
            'recipient_phone' => (string) ($request['delivery_recipient_phone'] ?: $request['contact_phone']),
            'address_line_1'  => (string) $request['delivery_address_line_1'],
            'address_line_2'  => $request['delivery_address_line_2'] ?: null,
            'city'            => (string) $request['delivery_city'],
            'state'           => (string) $request['delivery_state'],
            'landmark'        => $request['delivery_landmark'] ?: null,
        ];
    }

    /**
     * What is kept as the record of what was asked for. The uploaded file's own
     * name is not: it is attacker-controlled text we would be storing verbatim,
     * and the saved attachment path is on the row already.
     */
    private static function auditable(array $input): array
    {
        unset($input['attachment'], $input['csrf_token'], $input['action']);
        return $input;
    }
}
