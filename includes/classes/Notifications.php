<?php
/**
 * includes/classes/Notifications.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The one way a message leaves the platform.
 *
 * Every order and payment event calls this class and nothing else sends email.
 * One path in means one place to fix when SMTP breaks, one place that records
 * what happened, and one place to add SMS in Phase 2 (the channel column is
 * already there).
 *
 * Each send:
 *   1. writes a `notifications` row: the event, what it relates to, the template
 *      used and the rendered words;
 *   2. writes a `notification_deliveries` row per recipient and channel, with
 *      the address, the attempt count, sent_at, and last_error when it fails;
 *   3. hands the email to Mail::sendTemplate, which renders the branded HTML and
 *      the plain text alternative from the same copy;
 *   4. never lets a failed email break the thing that triggered it. An order
 *      that dispatched has dispatched, even if the email bounced. Catch, record,
 *      carry on, and show the failure on the order screen.
 *
 * Two channels ship: `email` and `in_app`. The in-app copy is the same words in
 * the customer's account, so someone who never opens email still sees every
 * step. read_at is the in-app read marker only; email open tracking stays out of
 * Phase 1 and email rows leave it null.
 *
 * Call it after the commit, never inside a transaction. The ledger holds a
 * database transaction open and an SMTP round trip does not belong in one.
 * -----------------------------------------------------------------------------
 */

final class Notifications
{
    public const CHANNEL_EMAIL  = 'email';
    public const CHANNEL_IN_APP = 'in_app';

    public const STATUS_SENT   = 'sent';
    public const STATUS_FAILED = 'failed';

    /**
     * A notification written now and sent later.
     *
     *   held    Rendered and waiting on an event, not a clock. The pay in full
     *           receipt is held at checkout and released when Paystack confirms,
     *           which is the only way it can still carry the Order Trail token:
     *           the token exists in plain text for one request and is stored
     *           only as a hash, so an email rendered after the fact cannot have
     *           it. See PRD 14.2.
     *   queued  Rendered and due at scheduled_at. The single pending payment
     *           reminder is queued this way and sent by the cron pass.
     *   cancelled  Never to be sent. The reminder is cancelled the moment the
     *           payment lands or the order is cancelled.
     */
    public const STATUS_HELD      = 'held';
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * Every event this platform can announce, and the words it uses. Keeping the
     * catalogue here rather than at each call site means the template editor and
     * the order screen can both name an event without guessing.
     */
    public const EVENTS = [
        'order_placed'      => ['template' => 'order_placed',      'label' => 'Order placed',            'audience' => 'customer'],
        'order_confirmed'   => ['template' => 'order_confirmed',   'label' => 'Order confirmed',         'audience' => 'customer'],
        'order_packed'      => ['template' => 'order_packed',      'label' => 'Order packed',            'audience' => 'customer'],
        'order_dispatched'  => ['template' => 'order_dispatched',  'label' => 'Order dispatched',        'audience' => 'customer'],
        'order_delivered'   => ['template' => 'order_delivered',   'label' => 'Order delivered',         'audience' => 'customer'],
        'order_cancelled'   => ['template' => 'order_cancelled',   'label' => 'Order cancelled',         'audience' => 'customer'],
        'payment_confirmed' => ['template' => 'payment_confirmed', 'label' => 'Payment confirmed',       'audience' => 'customer'],
        'order_payment_pending' => ['template' => 'order_payment_pending', 'label' => 'Payment still pending', 'audience' => 'customer'],
        'deposit_received'  => ['template' => 'deposit_received',  'label' => 'Deposit received',        'audience' => 'customer'],
        'payment_recorded'  => ['template' => 'payment_recorded',  'label' => 'Payment recorded by staff', 'audience' => 'customer'],
        'refund_processed'  => ['template' => 'refund_processed',  'label' => 'Refund sent',             'audience' => 'customer'],
        'refund_failed'     => ['template' => 'refund_failed',     'label' => 'Refund failed',           'audience' => 'staff'],
        'admin_new_order'   => ['template' => 'admin_new_order',   'label' => 'New order, for staff',    'audience' => 'staff'],
        'admin_new_contact' => ['template' => 'admin_new_contact', 'label' => 'New contact message, for staff', 'audience' => 'staff'],
        'contact_acknowledgement' => ['template' => 'contact_acknowledgement', 'label' => 'We have your message',  'audience' => 'customer'],
        'issue_report_received' => ['template' => 'issue_report_received', 'label' => 'Report received', 'audience' => 'customer'],
        'issue_report_resolved' => ['template' => 'issue_report_resolved', 'label' => 'Report resolved', 'audience' => 'customer'],
        'admin_new_issue_report' => ['template' => 'admin_new_issue_report', 'label' => 'New Make It Right report, for staff', 'audience' => 'staff'],

        'kitchen_run_received' => ['template' => 'kitchen_run_received', 'label' => 'Kitchen Run received',      'audience' => 'customer'],
        'kitchen_run_quoted'   => ['template' => 'kitchen_run_quoted',   'label' => 'Kitchen Run priced',        'audience' => 'customer'],
        'kitchen_run_declined' => ['template' => 'kitchen_run_declined', 'label' => 'Kitchen Run declined',      'audience' => 'customer'],
        'admin_new_kitchen_run'      => ['template' => 'admin_new_kitchen_run',      'label' => 'New Kitchen Run, for staff',      'audience' => 'staff'],
        'admin_kitchen_run_approved' => ['template' => 'admin_kitchen_run_approved', 'label' => 'Kitchen Run approved, for staff', 'audience' => 'staff'],
        'admin_kitchen_run_cancelled' => ['template' => 'admin_kitchen_run_cancelled', 'label' => 'Kitchen Run withdrawn after approval, for staff', 'audience' => 'staff'],

        'credit_approved'            => ['template' => 'credit_approved',            'label' => 'Credit approved',               'audience' => 'customer'],
        'credit_declined'            => ['template' => 'credit_declined',            'label' => 'Credit declined',               'audience' => 'customer'],
        'credit_charge_posted'       => ['template' => 'credit_charge_posted',       'label' => 'Charge placed on account',      'audience' => 'customer'],
        'admin_new_credit_application' => ['template' => 'admin_new_credit_application', 'label' => 'New credit application, for staff', 'audience' => 'staff'],
    ];

    /** The tokens each template may use, so the editor can list them honestly. */
    public const TOKENS = [
        'order_placed'      => ['customer_name', 'order_number', 'delivery_day', 'order_total', 'source_line', 'order_trail_url'],
        'order_confirmed'   => ['customer_name', 'order_number', 'delivery_day', 'source_line', 'order_trail_url'],
        'order_packed'      => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_dispatched'  => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_delivered'   => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_cancelled'   => ['customer_name', 'order_number', 'money_line', 'order_trail_url'],
        'payment_confirmed' => ['customer_name', 'order_number', 'amount', 'delivery_day', 'source_line', 'order_trail_url'],
        'order_payment_pending' => ['customer_name', 'order_number', 'amount_due', 'delivery_day', 'pay_url'],
        'deposit_received'  => ['customer_name', 'order_number', 'amount', 'balance_line', 'order_trail_url'],
        'payment_recorded'  => ['customer_name', 'order_number', 'amount', 'balance_line', 'order_trail_url'],
        'refund_processed'  => ['customer_name', 'order_number', 'amount', 'order_trail_url'],
        'refund_failed'     => ['order_number', 'amount', 'reason', 'admin_url'],
        'admin_new_order'   => ['customer_name', 'order_number', 'order_total', 'delivery_day', 'zone_name', 'payment_choice', 'admin_url'],
        'admin_new_contact' => ['contact_name', 'contact_method', 'source_label', 'subject', 'message_preview', 'admin_url'],
        'contact_acknowledgement' => ['customer_name', 'received_at', 'whatsapp_url'],
        'issue_report_received' => ['customer_name', 'order_number', 'reported_at', 'issue_url'],
        'issue_report_resolved' => ['customer_name', 'order_number', 'outcome_line', 'amount_line', 'issue_url'],
        'admin_new_issue_report' => ['customer_name', 'order_number', 'category', 'reported_at', 'description_preview', 'admin_url'],

        'kitchen_run_received' => ['customer_name', 'request_number', 'line_count', 'delivery_day', 'request_url'],
        'kitchen_run_quoted'   => ['customer_name', 'request_number', 'quote_total', 'deposit_line', 'quote_expiry', 'request_url'],
        'kitchen_run_declined' => ['customer_name', 'request_number', 'decline_reason', 'request_url'],
        'admin_new_kitchen_run'      => ['customer_name', 'request_number', 'line_count', 'input_mode_label', 'pricing_mode_label', 'budget_line', 'admin_url'],
        'admin_kitchen_run_approved' => ['customer_name', 'request_number', 'quote_total', 'deposit_line', 'admin_url'],
        'admin_kitchen_run_cancelled' => ['customer_name', 'request_number', 'quote_total', 'admin_url'],

        'credit_approved'            => ['customer_name', 'business_name', 'credit_limit', 'credit_days', 'credit_url'],
        'credit_declined'            => ['customer_name', 'business_name', 'declined_reason', 'credit_url'],
        'credit_charge_posted'       => ['customer_name', 'business_name', 'order_number', 'amount', 'due_date', 'order_trail_url'],
        'admin_new_credit_application' => ['customer_name', 'business_name', 'requested_days', 'requested_limit', 'reason', 'admin_url'],
    ];

    /** Which lifecycle stage announces itself, and with which event. */
    public const STAGE_EVENTS = [
        'confirmed'  => 'order_confirmed',
        'packed'     => 'order_packed',
        'dispatched' => 'order_dispatched',
        'delivered'  => 'order_delivered',
    ];

    /**
     * Send one event. Recipients are ['email' => ..., 'user_id' => ...] rows;
     * a row with no email address still gets the in-app copy.
     *
     * Returns the notification id, or 0 when nothing could be recorded. It never
     * throws: the caller has already done the real work.
     *
     * @param array<int, array{email?: ?string, user_id?: ?int, name?: ?string}> $recipients
     */
    public static function send(
        string $event,
        array $vars,
        array $recipients,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?int $actorId = null
    ): int {
        try {
            return self::dispatch($event, $vars, $recipients, $relatedType, $relatedId, $actorId);
        } catch (Throwable $e) {
            error_log('Notifications::send failed for ' . $event . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Render an event now and hold it until something releases it. Nothing is
     * sent here. Returns the notification id, or 0 when nothing was written.
     */
    public static function hold(
        string $event,
        array $vars,
        array $recipients,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): int {
        try {
            return self::dispatch($event, $vars, $recipients, $relatedType, $relatedId, null, self::STATUS_HELD, null);
        } catch (Throwable $e) {
            error_log('Notifications::hold failed for ' . $event . ': ' . $e->getMessage());
            return 0;
        }
    }

    /** Render an event now and send it at $sendAt, a 'Y-m-d H:i:s' timestamp. */
    public static function queueAt(
        string $event,
        string $sendAt,
        array $vars,
        array $recipients,
        ?string $relatedType = null,
        ?int $relatedId = null
    ): int {
        try {
            return self::dispatch($event, $vars, $recipients, $relatedType, $relatedId, null, self::STATUS_QUEUED, $sendAt);
        } catch (Throwable $e) {
            error_log('Notifications::queueAt failed for ' . $event . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Send a held notification for one record now. Returns how many were sent,
     * so a caller can fall back to sending a fresh one when nothing was held
     * (an order placed before this seam existed has nothing waiting).
     */
    public static function release(string $relatedType, int $relatedId, string $event): int
    {
        $rows = Database::all(
            'SELECT id FROM notifications
              WHERE event_type = :event AND related_type = :type AND related_id = :id AND status = :status
              ORDER BY id',
            [':event' => $event, ':type' => $relatedType, ':id' => $relatedId, ':status' => self::STATUS_HELD]
        );
        $sent = 0;
        foreach ($rows as $row) {
            $sent += self::deliver((int) $row['id']) ? 1 : 0;
        }
        return $sent;
    }

    /**
     * Stop notifications that have not gone out yet. Used when the reason for
     * one disappears: the payment landed, or the order was cancelled.
     *
     * @param array<int, string> $events
     */
    public static function cancelPending(string $relatedType, int $relatedId, array $events): int
    {
        if (!$events) {
            return 0;
        }
        // One named placeholder per position, values bound and never inlined,
        // the same rule the Kitchen Run queue learned the hard way.
        $params = [
            ':type'   => $relatedType,
            ':id'     => $relatedId,
            ':held'   => self::STATUS_HELD,
            ':queued' => self::STATUS_QUEUED,
        ];
        $names = [];
        foreach (array_values($events) as $i => $event) {
            $names[] = ':event' . $i;
            $params[':event' . $i] = $event;
        }

        $rows = Database::all(
            'SELECT id FROM notifications
              WHERE related_type = :type AND related_id = :id
                AND event_type IN (' . implode(', ', $names) . ')
                AND status IN (:held, :queued)',
            $params
        );
        foreach ($rows as $row) {
            Database::run(
                'UPDATE notifications SET status = :status WHERE id = :id',
                [':status' => self::STATUS_CANCELLED, ':id' => (int) $row['id']]
            );
            Database::run(
                'UPDATE notification_deliveries SET status = :status
                  WHERE notification_id = :id AND status = :waiting',
                [':status' => self::STATUS_CANCELLED, ':id' => (int) $row['id'], ':waiting' => 'queued']
            );
        }
        return count($rows);
    }

    /**
     * Send every queued notification whose time has come. This is the cron half
     * of the seam: scripts/cron.php and public/cron.php both call it.
     *
     * @return array{due: int, sent: int, failed: int}
     */
    public static function flushDue(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = Database::all(
            'SELECT id, event_type, related_type, related_id FROM notifications
              WHERE status = :status AND scheduled_at IS NOT NULL AND scheduled_at <= :now
              ORDER BY scheduled_at, id
              LIMIT ' . $limit,
            [':status' => self::STATUS_QUEUED, ':now' => date('Y-m-d H:i:s')]
        );

        $counts = ['due' => count($rows), 'sent' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            // Asked again at the last moment, so no payment path has to
            // remember to cancel this and no customer is ever chased for money
            // they have already paid.
            if (!self::stillWanted($row)) {
                self::cancelPending((string) $row['related_type'], (int) $row['related_id'], [(string) $row['event_type']]);
                $counts['skipped']++;
                continue;
            }
            if (self::deliver((int) $row['id'])) {
                $counts['sent']++;
            } else {
                $counts['failed']++;
            }
        }
        return $counts;
    }

    /**
     * Whether a queued notification is still true. Only the payment reminder
     * can go stale between being written and being due: the order may have been
     * paid by any route, or cancelled, in the half hour it waited.
     */
    private static function stillWanted(array $notification): bool
    {
        if ((string) $notification['event_type'] !== 'order_payment_pending') {
            return true;
        }
        $order = Database::one(
            'SELECT order_status, payment_status FROM orders WHERE id = :id',
            [':id' => (int) $notification['related_id']]
        );
        if (!$order || (string) $order['order_status'] === 'cancelled' || (string) $order['payment_status'] === 'paid') {
            return false;
        }
        return Database::one(
            'SELECT id FROM payments
              WHERE order_id = :id AND provider = \'paystack\' AND status <> \'paid\'
                AND expected_amount_subunit > paid_amount_subunit
              LIMIT 1',
            [':id' => (int) $notification['related_id']]
        ) !== null;
    }

    /**
     * Send one already-rendered notification. The body, the subject and the
     * button were all worked out when it was written, so this asks nothing of
     * the order it came from and cannot change its mind about the words.
     */
    private static function deliver(int $notificationId): bool
    {
        $notification = Database::one(
            'SELECT id, title, body, cta_url, cta_label, status FROM notifications WHERE id = :id',
            [':id' => $notificationId]
        );
        if (!$notification || !in_array((string) $notification['status'], [self::STATUS_HELD, self::STATUS_QUEUED], true)) {
            return false;
        }

        $cta = trim((string) ($notification['cta_url'] ?? '')) === ''
            ? null
            : ['label' => (string) ($notification['cta_label'] ?? 'Open this in your browser'), 'url' => (string) $notification['cta_url']];
        $subject = (string) $notification['title'];
        $body    = (string) $notification['body'];

        $deliveries = Database::all(
            'SELECT id, channel, recipient_address, attempt_count FROM notification_deliveries
              WHERE notification_id = :id AND status = :status',
            [':id' => $notificationId, ':status' => 'queued']
        );

        $anySent = false;
        foreach ($deliveries as $delivery) {
            if ((string) $delivery['channel'] !== self::CHANNEL_EMAIL) {
                self::recordAttempt((int) $delivery['id'], 1 + (int) $delivery['attempt_count'], true, '');
                $anySent = true;
                continue;
            }
            $sent = false;
            try {
                $sent = Mail::send(
                    (string) $delivery['recipient_address'],
                    $subject,
                    Mail::brandedHtml($subject, $body, $cta),
                    Mail::plainText($subject, $body, $cta)
                );
            } catch (Throwable $e) {
                error_log('Notifications::deliver threw for notification ' . $notificationId . ': ' . $e->getMessage());
            }
            self::recordAttempt((int) $delivery['id'], 1 + (int) $delivery['attempt_count'], $sent, 'The mail server would not take the message.');
            $anySent = $anySent || $sent;
        }

        Database::run(
            'UPDATE notifications SET status = :status, scheduled_at = COALESCE(scheduled_at, :now) WHERE id = :id',
            [':status' => $anySent ? self::STATUS_SENT : self::STATUS_FAILED, ':now' => date('Y-m-d H:i:s'), ':id' => $notificationId]
        );
        return $anySent;
    }

    /** Everything sent about one order, newest first, for the Order 360 screen. */
    public static function forOrder(int $orderId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT n.id, n.event_type, n.title, n.body, n.status, n.scheduled_at, n.created_at,
                    d.id AS delivery_id, d.channel, d.recipient_address, d.status AS delivery_status,
                    d.attempt_count, d.sent_at, d.last_error
               FROM notifications n
               JOIN notification_deliveries d ON d.notification_id = n.id
              WHERE n.related_type = :type AND n.related_id = :id
              ORDER BY n.id DESC, d.id ASC
              LIMIT ' . $limit,
            [':type' => 'order', ':id' => $orderId]
        );
    }

    /**
     * What a message on the Order 360 screen is actually doing, in words, and
     * whether staff may send it again. A held or queued email has not gone out
     * yet and must not be pushed early: it would tell the customer something
     * that is not true of their order yet.
     *
     * @return array{label: string, tone: string, may_resend: bool}
     */
    public static function deliveryState(array $message): array
    {
        $notification = (string) ($message['status'] ?? '');
        $delivery     = (string) ($message['delivery_status'] ?? '');

        if ($delivery === self::STATUS_SENT) {
            return ['label' => 'Sent', 'tone' => 'good', 'may_resend' => false];
        }
        if ($notification === self::STATUS_HELD) {
            return ['label' => 'Waiting on the payment', 'tone' => 'wait', 'may_resend' => false];
        }
        if ($notification === self::STATUS_QUEUED) {
            $when = trim((string) ($message['scheduled_at'] ?? ''));
            $at   = $when === '' ? '' : ' for ' . date('j M Y, H:i', (int) strtotime($when));
            return ['label' => 'Scheduled' . $at, 'tone' => 'wait', 'may_resend' => false];
        }
        if ($notification === self::STATUS_CANCELLED) {
            return ['label' => 'Cancelled', 'tone' => 'wait', 'may_resend' => false];
        }
        return ['label' => 'Not sent', 'tone' => 'bad', 'may_resend' => (string) ($message['channel'] ?? '') === self::CHANNEL_EMAIL];
    }

    /** One person's in-app updates, newest first. */
    public static function inboxFor(int $userId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            'SELECT n.id, n.event_type, n.title, n.body, n.related_id, n.created_at,
                    d.id AS delivery_id, d.read_at
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.user_id = :user AND d.channel = :channel
              ORDER BY d.id DESC
              LIMIT ' . $limit,
            [':user' => $userId, ':channel' => self::CHANNEL_IN_APP]
        );
    }

    public static function unreadCount(int $userId): int
    {
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM notification_deliveries
              WHERE user_id = :user AND channel = :channel AND read_at IS NULL',
            [':user' => $userId, ':channel' => self::CHANNEL_IN_APP]
        );
        return (int) ($row['n'] ?? 0);
    }

    /** Mark this person's in-app updates read. Their own rows only. */
    public static function markInboxRead(int $userId): int
    {
        return Database::run(
            'UPDATE notification_deliveries SET read_at = NOW()
              WHERE user_id = :user AND channel = :channel AND read_at IS NULL',
            [':user' => $userId, ':channel' => self::CHANNEL_IN_APP]
        );
    }

    /**
     * Try a failed email delivery again with the words already recorded, so a
     * resend never quietly says something different from the first attempt.
     */
    public static function resend(int $deliveryId, ?int $actorId = null): array
    {
        $delivery = Database::one(
            'SELECT d.id, d.channel, d.recipient_address, d.attempt_count, d.status,
                    n.title, n.body, n.cta_url, n.cta_label, n.event_type, n.status AS notification_status
               FROM notification_deliveries d
               JOIN notifications n ON n.id = d.notification_id
              WHERE d.id = :id',
            [':id' => $deliveryId]
        );
        if (!$delivery) {
            return ['ok' => false, 'code' => 'not_found', 'message' => 'That message could not be found.'];
        }
        if ((string) $delivery['channel'] !== self::CHANNEL_EMAIL) {
            return ['ok' => false, 'code' => 'not_email', 'message' => 'Only an email can be sent again.'];
        }
        if ((string) $delivery['status'] === self::STATUS_SENT) {
            return ['ok' => false, 'code' => 'already_sent', 'message' => 'That email already went out.'];
        }
        // Resend is for an email that failed, never a way to fire one early. A
        // held receipt says the payment has arrived, and a queued reminder says
        // it has not, so sending either before its moment tells the customer
        // something untrue.
        $waiting = (string) $delivery['notification_status'];
        if ($waiting === self::STATUS_HELD || $waiting === self::STATUS_QUEUED) {
            return ['ok' => false, 'code' => 'not_due', 'message' => 'This email has not been sent yet. It goes out on its own when the order reaches that point.'];
        }
        if ($waiting === self::STATUS_CANCELLED) {
            return ['ok' => false, 'code' => 'cancelled', 'message' => 'This email was cancelled, because what it was about no longer applies.'];
        }
        $cta = trim((string) ($delivery['cta_url'] ?? '')) === ''
            ? null
            : ['label' => (string) ($delivery['cta_label'] ?? 'Open this in your browser'), 'url' => (string) $delivery['cta_url']];

        $sent = false;
        $error = '';
        try {
            $sent = Mail::send(
                (string) $delivery['recipient_address'],
                (string) $delivery['title'],
                Mail::brandedHtml((string) $delivery['title'], (string) $delivery['body'], $cta),
                Mail::plainText((string) $delivery['title'], (string) $delivery['body'], $cta)
            );
        } catch (Throwable $e) {
            error_log('Notifications::resend failed: ' . $e->getMessage());
            $error = 'The mail server refused the message.';
        }
        self::recordAttempt((int) $delivery['id'], (int) $delivery['attempt_count'] + 1, $sent, $error ?: 'The mail server would not take the message.');

        return $sent
            ? ['ok' => true, 'code' => 'sent', 'message' => 'The email has been sent again.']
            : ['ok' => false, 'code' => 'send_failed', 'message' => 'That email still could not be sent. The error is recorded on the order.'];
    }

    /**
     * Send one template to the person asking for it, with sample values, so a
     * team can prove that email leaves this server without placing an order.
     *
     * The address is never taken from the request: it is the signed-in staff
     * member's own, read from the database. That is the whole safety property.
     * A "send a test to this address" box would make this platform a relay for
     * anyone who got hold of a staff session.
     */
    public static function sendTest(string $templateKey, int $staffId): array
    {
        if (!isset(self::TOKENS[$templateKey])) {
            return ['ok' => false, 'code' => 'unknown_template', 'message' => 'That is not a notification we send.'];
        }
        $staff = Database::one(
            'SELECT id, email, TRIM(CONCAT(COALESCE(first_name, \'\'), \' \', COALESCE(last_name, \'\'))) AS name
               FROM users WHERE id = :id AND status = \'active\'',
            [':id' => $staffId]
        );
        $address = trim((string) ($staff['email'] ?? ''));
        if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'code' => 'no_address', 'message' => 'Your account has no usable email address, so there is nowhere to send it.'];
        }

        $vars = SettingsEditor::sampleTokens($templateKey);
        $vars['customer_name'] = trim((string) ($staff['name'] ?? '')) ?: 'there';
        $id = self::send(
            self::eventForTemplate($templateKey),
            $vars,
            [['email' => $address, 'user_id' => (int) $staff['id']]],
            'settings_test',
            null,
            $staffId
        );
        if ($id < 1) {
            return ['ok' => false, 'code' => 'not_recorded', 'message' => 'That test could not be recorded. Check the error log.'];
        }
        $delivery = Database::one(
            'SELECT status, last_error FROM notification_deliveries WHERE notification_id = :id AND channel = :channel',
            [':id' => $id, ':channel' => self::CHANNEL_EMAIL]
        );
        if ((string) ($delivery['status'] ?? '') !== self::STATUS_SENT) {
            return [
                'ok' => false,
                'code' => 'send_failed',
                'message' => 'The mail server would not take it. ' . trim((string) ($delivery['last_error'] ?? '')),
            ];
        }
        return [
            'ok' => true,
            'code' => 'sent',
            'message' => 'Sent to ' . $address . '. It is a sample, so the figures in it are made up.',
        ];
    }

    /** The event that uses a given template, for a test send. */
    private static function eventForTemplate(string $templateKey): string
    {
        foreach (self::EVENTS as $event => $definition) {
            if ($definition['template'] === $templateKey) {
                return $event;
            }
        }
        return $templateKey;
    }

    /** Staff who should hear about an order or a money problem. */
    public static function staffRecipients(): array
    {
        $rows = Database::all(
            'SELECT DISTINCT u.id, u.email, TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS name
               FROM users u
               JOIN user_roles ur ON ur.user_id = u.id
               JOIN roles r ON r.id = ur.role_id
              WHERE u.status = \'active\' AND u.email IS NOT NULL AND r.name IN (\'owner\', \'manager\')'
        );
        $recipients = [];
        foreach ($rows as $row) {
            $recipients[] = ['email' => (string) $row['email'], 'user_id' => (int) $row['id'], 'name' => (string) $row['name']];
        }
        if (!$recipients) {
            $fallback = Settings::str('support_email', '');
            if ($fallback !== '') {
                $recipients[] = ['email' => $fallback, 'user_id' => null, 'name' => 'OK Veggies'];
            }
        }
        return $recipients;
    }

    /**
     * The order facts every customer email needs, gathered once. Returns null
     * when the order is gone, so a caller sends nothing rather than an email
     * full of blanks.
     */
    public static function orderContext(int $orderId, ?string $trailToken = null): ?array
    {
        $order = Database::one(
            'SELECT o.id, o.order_number, o.order_status, o.payment_option, o.payment_status,
                    o.order_total_subunit, o.amount_paid_subunit, o.balance_due_subunit,
                    o.preferred_delivery_date, o.source_regions_snapshot, o.user_id, o.contact_email,
                    a.recipient_name, z.name AS zone_name,
                    u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM orders o
               LEFT JOIN order_addresses a ON a.order_id = o.id
               LEFT JOIN delivery_zones z ON z.id = o.delivery_zone_id
               LEFT JOIN users u ON u.id = o.user_id
              WHERE o.id = :id',
            [':id' => $orderId]
        );
        if (!$order) {
            return null;
        }

        $name = trim((string) ($order['recipient_name'] ?? '')) ?: trim((string) ($order['user_name'] ?? ''));
        $regions = trim((string) ($order['source_regions_snapshot'] ?? '')) ?: Settings::str('source_regions', '');
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');

        // What is still owed online, and the page that can take it. A guest has
        // no account to sign in to, so their way back is the trail link they
        // were emailed; an account holder signs in and pays from their order.
        $pending = Database::one(
            'SELECT expected_amount_subunit, paid_amount_subunit FROM payments
              WHERE order_id = :id AND provider = \'paystack\' AND status <> \'paid\'
                AND expected_amount_subunit > paid_amount_subunit
              ORDER BY id LIMIT 1',
            [':id' => $orderId]
        );
        $amountDue = $pending
            ? Money::balance((int) $pending['expected_amount_subunit'], (int) $pending['paid_amount_subunit'])
            : 0;
        $trailUrl = $trailToken !== null && OrderTrail::isValidToken($trailToken)
            ? $base . '/public/order.php?token=' . rawurlencode($trailToken)
            : $base . '/public/order.php?order=' . (int) $order['id'];

        return [
            'order_id'           => (int) $order['id'],
            'payment_option'     => (string) $order['payment_option'],
            'amount_due_subunit' => $amountDue,
            'pay_url'            => $trailUrl,
            'recipients' => self::customerRecipients($order),
            'vars'       => [
                'customer_name'  => $name ?: 'there',
                'order_number'   => (string) $order['order_number'],
                'delivery_day'   => date('l jS F', strtotime((string) $order['preferred_delivery_date'])),
                'order_total'    => Money::format((int) $order['order_total_subunit']),
                'zone_name'      => trim((string) ($order['zone_name'] ?? '')) ?: 'Not assigned',
                'payment_choice' => self::paymentChoiceLabel((string) $order['payment_option']),
                'source_line'    => okv_sourced_line($regions, Settings::str('source_day', '')),
                'balance_line'   => self::balanceLine((int) $order['balance_due_subunit']),
                'order_trail_url' => $trailUrl,
                'admin_url'      => $base . '/admin/orders.php?order=' . (int) $order['id'],
            ],
        ];
    }

    /**
     * Who hears about this order. The account's address when there is an
     * account, otherwise the one typed at checkout, which is all a guest order
     * has (PRD 9.2).
     */
    private static function customerRecipients(array $order): array
    {
        $email = trim((string) ($order['user_email'] ?? '')) ?: trim((string) ($order['contact_email'] ?? ''));
        $userId = ($order['user_id'] ?? null) !== null ? (int) $order['user_id'] : null;
        if ($email === '' && $userId === null) {
            return [];
        }
        return [['email' => $email !== '' ? $email : null, 'user_id' => $userId]];
    }

    private static function balanceLine(int $balanceSubunit): string
    {
        return $balanceSubunit > 0
            ? 'There is still ' . Money::format($balanceSubunit) . ' to settle on this order.'
            : 'Nothing is left to pay on this order.';
    }

    private static function paymentChoiceLabel(string $option): string
    {
        return [
            'pay_in_full'     => 'Paid in full online',
            'deposit'         => 'Deposit online, balance on delivery',
            'pay_on_delivery' => 'Pay on delivery',
            'on_account'      => 'On account',
        ][$option] ?? $option;
    }

    // -------------------------------------------------------------------------
    // The announcements. Each one is called by the controller that did the work,
    // after its transaction has committed, so an SMTP round trip never sits
    // inside a database transaction and a failed email never rolls back an
    // order. Every one of them is safe to call twice: the worst case is a
    // second copy of an email, never a changed order.
    // -------------------------------------------------------------------------

    /**
     * A new order: the customer's receipt of it, and the staff alert.
     *
     * What the customer hears depends on what they chose to do about the money,
     * because "we have your order and we are sourcing it now" is not true of an
     * order that has not been paid for yet:
     *
     *   pay in full       Nothing now. The receipt is rendered here, while the
     *                     Order Trail token is still in hand, and held until
     *                     Paystack confirms the money. One email, and it is
     *                     true when it arrives.
     *   deposit           The receipt now, as before. The order is real: they
     *                     are paying 30% and settling the rest on delivery, and
     *                     the deposit is acknowledged separately when it lands.
     *   pay on delivery   The receipt now. Nothing is owed online.
     *   on account        The receipt now. The credit line is the payment.
     *
     * Either online option also queues one reminder, once, for the customer who
     * closed the Paystack tab and walked away. Staff hear about every order the
     * moment it is placed, paid or not, so nothing is invisible to the team.
     */
    public static function announceOrderPlaced(int $orderId, string $trailToken = ''): void
    {
        $context = self::orderContext($orderId, $trailToken !== '' ? $trailToken : null);
        if ($context === null) {
            return;
        }
        $option = (string) $context['payment_option'];

        if ($option === 'pay_in_full') {
            self::hold(
                'payment_confirmed',
                $context['vars'] + ['amount' => $context['vars']['order_total']],
                $context['recipients'],
                'order',
                $orderId
            );
        } else {
            self::send('order_placed', $context['vars'], $context['recipients'], 'order', $orderId);
        }

        if (in_array($option, ['pay_in_full', 'deposit'], true)) {
            self::queuePaymentReminder($orderId, $context);
        }

        $staffVars = $context['vars'];
        unset($staffVars['order_trail_url']);
        self::send('admin_new_order', $staffVars, self::staffRecipients(), 'order', $orderId);
    }

    /**
     * The one reminder an unpaid online order gets. Queued at placement so it
     * carries a link that still works when it is sent, and cancelled the moment
     * the money lands or the order is cancelled, so a paid order never nags.
     */
    private static function queuePaymentReminder(int $orderId, array $context): void
    {
        $due = (int) $context['amount_due_subunit'];
        if ($due < 1) {
            return;
        }
        $minutes = max(5, min(1440, Settings::int('payment_reminder_minutes', 30)));
        $sendAt  = date('Y-m-d H:i:s', strtotime('+' . $minutes . ' minutes'));

        // The button on this one is the way back to the payment, not the trail,
        // so the trail link is left out and Mail::ctaFromVars picks pay_url.
        $vars = $context['vars'];
        unset($vars['order_trail_url']);
        $vars['amount_due'] = Money::format($due);
        $vars['pay_url']    = (string) $context['pay_url'];

        self::queueAt('order_payment_pending', $sendAt, $vars, $context['recipients'], 'order', $orderId);
    }

    /**
     * A committed contact message. Two notices go out, both through the
     * dispatcher so each delivery is recorded: the staff alert PRD Section 15
     * asks for, and an acknowledgement to the sender when they left an email,
     * so the message does not vanish into silence.
     *
     * Called after the row is committed. Nothing here can fail the submission:
     * a send that does not arrive is recorded in `notification_deliveries` and
     * the message is still on the team's list.
     */
    public static function announceContactMessage(int $messageId): void
    {
        $message = Database::one(
            'SELECT id, name, email, phone, subject, message, source, created_at
               FROM contact_messages WHERE id = :id',
            [':id' => $messageId]
        );
        if (!$message) {
            return;
        }
        $method = trim((string) ($message['email'] ?? ''));
        if ($method === '') {
            $method = Phone::display((string) ($message['phone'] ?? ''));
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');

        self::send(
            'admin_new_contact',
            [
                'contact_name'    => (string) $message['name'],
                'contact_method'  => $method,
                'source_label'    => ContactMessages::sourceLabel((string) $message['source']),
                'subject'         => trim((string) ($message['subject'] ?? '')) ?: 'No subject',
                'message_preview' => mb_substr(trim((string) $message['message']), 0, 300),
                'admin_url'       => $base . '/admin/content.php?message=' . $messageId,
            ],
            self::staffRecipients(),
            'contact_message',
            $messageId
        );

        // Nobody has proved they own this address, so the acknowledgement
        // repeats nothing the sender typed. It says only that a message
        // arrived, which is useless to anyone trying to carry words to a
        // stranger, and is what the sender actually needs to hear.
        $email = trim((string) ($message['email'] ?? ''));
        if ($email === '') {
            return;
        }
        $firstName = trim((string) $message['name']);
        $firstName = $firstName !== '' ? (explode(' ', $firstName)[0] ?: 'there') : 'there';
        self::send(
            'contact_acknowledgement',
            [
                'customer_name' => $firstName,
                'received_at'   => date('l jS F', strtotime((string) $message['created_at'])),
                'whatsapp_url'  => okv_support_whatsapp_url(),
            ],
            [['email' => $email, 'name' => (string) $message['name']]],
            'contact_message',
            $messageId
        );
    }

    /** Announce a committed issue report to its owner and the staff team. */
    public static function announceIssueReportReceived(int $issueId): void
    {
        $report = Database::one(
            'SELECT i.id, i.category, i.description, i.created_at,
                    o.id AS order_id, o.order_number,
                    u.id AS user_id, u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM issue_reports i
               JOIN orders o ON o.id = i.order_id
               JOIN users u ON u.id = i.user_id
              WHERE i.id = :id',
            [':id' => $issueId]
        );
        if ($report === null) {
            return;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $name = trim((string) $report['user_name']);
        $firstName = $name !== '' ? (explode(' ', $name)[0] ?: 'there') : 'there';
        $reportedAt = date('l jS F, H:i', strtotime((string) $report['created_at']));
        $customerVars = [
            'customer_name' => $firstName,
            'order_number' => (string) $report['order_number'],
            'reported_at' => $reportedAt,
            'issue_url' => $base . '/public/order.php?order=' . (int) $report['order_id'],
        ];
        self::send(
            'issue_report_received',
            $customerVars,
            self::customerRecipients([
                'user_email' => $report['user_email'],
                'user_id' => $report['user_id'],
            ]),
            'issue_report',
            $issueId
        );
        self::send(
            'admin_new_issue_report',
            $customerVars + [
                'customer_name' => $name ?: 'Customer',
                'category' => IssueReports::CATEGORIES[(string) $report['category']] ?? 'Something else',
                'description_preview' => mb_substr(trim((string) $report['description']), 0, 300),
                'admin_url' => $base . '/admin/make_it_right.php?report=' . $issueId,
            ],
            self::staffRecipients(),
            'issue_report',
            $issueId
        );
    }

    /** Tell the customer about a committed safe terminal outcome. */
    public static function announceIssueReportResolved(int $issueId, ?int $actorId = null): void
    {
        $report = Database::one(
            'SELECT i.id, i.status, i.resolution_type, i.resolution_note,
                    i.resolution_amount_subunit, i.replacement_order_id,
                    o.id AS order_id, o.order_number,
                    r.status AS refund_status, ro.order_number AS replacement_order_number,
                    u.id AS user_id, u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM issue_reports i
               JOIN orders o ON o.id = i.order_id
               JOIN users u ON u.id = i.user_id
          LEFT JOIN refunds r ON r.issue_report_id = i.id
          LEFT JOIN orders ro ON ro.id = i.replacement_order_id
              WHERE i.id = :id AND i.status IN (:resolved_status, :declined_status)',
            [':id' => $issueId, ':resolved_status' => 'resolved', ':declined_status' => 'declined']
        );
        if ($report === null) {
            return;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $name = trim((string) $report['user_name']);
        $note = trim((string) $report['resolution_note']);
        $outcome = (string) $report['status'] === 'declined'
            ? 'We could not approve the report. ' . $note
            : $note;
        if ((string) $report['resolution_type'] === 'refund' && $report['refund_status'] !== null) {
            $outcome .= ' ' . Refunds::customerStatusLine((string) $report['refund_status']);
        }
        if ((string) $report['resolution_type'] === 'replacement' && trim((string) $report['replacement_order_number']) !== '') {
            $outcome .= ' Your replacement is on order ' . (string) $report['replacement_order_number'] . '.';
        }
        $amount = (int) ($report['resolution_amount_subunit'] ?? 0);
        self::send(
            'issue_report_resolved',
            [
                'customer_name' => $name !== '' ? (explode(' ', $name)[0] ?: 'there') : 'there',
                'order_number' => (string) $report['order_number'],
                'outcome_line' => $outcome,
                'amount_line' => $amount > 0 ? 'Amount: ' . Money::format($amount) : '',
                'issue_url' => $base . '/public/order.php?order=' . (int) $report['order_id'],
            ],
            self::customerRecipients(['user_email' => $report['user_email'], 'user_id' => $report['user_id']]),
            'issue_report',
            $issueId,
            $actorId
        );
    }

    // --- Kitchen Runs (PRD Section 8) ----------------------------------------

    /**
     * The facts every Kitchen Run email needs, gathered once. Returns null when
     * the request is gone, so a caller sends nothing rather than an email full
     * of blanks. Deliberately the superset of what the four templates use: an
     * unfilled token renders as nothing, and one context is easier to keep
     * honest than four.
     */
    public static function kitchenRunContext(int $requestId): ?array
    {
        $request = Database::one(
            'SELECT r.*, COUNT(i.id) AS line_count,
                    u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM kitchen_run_requests r
               LEFT JOIN kitchen_run_items i ON i.request_id = r.id
               LEFT JOIN users u ON u.id = r.user_id
              WHERE r.id = :id
              GROUP BY r.id',
            [':id' => $requestId]
        );
        if (!$request) {
            return null;
        }

        $base    = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $name    = trim((string) ($request['user_name'] ?? '')) ?: trim((string) ($request['contact_name'] ?? ''));
        $total   = $request['quoted_total_subunit'] === null ? null : (int) $request['quoted_total_subunit'];
        $deposit = $request['deposit_subunit'] === null ? null : (int) $request['deposit_subunit'];
        $count   = (int) $request['line_count'];

        return [
            'request_id' => $requestId,
            'recipients' => self::customerRecipients([
                'user_email' => $request['user_email'] ?? $request['contact_email'],
                'user_id'    => $request['user_id'],
            ]),
            'vars' => [
                'customer_name'      => $name ?: 'there',
                'request_number'     => (string) $request['request_number'],
                'quote_total'        => $total === null ? '' : Money::format($total),
                'deposit_line'       => self::kitchenRunDepositLine($total, $deposit),
                'quote_expiry'       => self::kitchenRunExpiryLine($request['quoted_at'] ?? null),
                'decline_reason'     => trim((string) ($request['admin_note'] ?? '')) ?: 'We have not been able to source this list at a price we can stand behind.',
                'line_count'         => $count . ' ' . ($count === 1 ? 'item' : 'items'),
                'input_mode_label'   => KitchenRuns::modeLabel((string) $request['input_mode']),
                'pricing_mode_label' => KitchenRuns::pricingLabel((string) $request['pricing_mode']),
                'budget_line'        => self::kitchenRunBudgetLine($request),
                'delivery_day'       => self::kitchenRunDeliveryDay($request['preferred_delivery_date'] ?? null),
                'request_url'        => $base . '/kitchen-runs.php?request=' . $requestId,
                'admin_url'          => $base . '/admin/kitchen_runs.php?request=' . $requestId,
            ],
        ];
    }

    /** The day the customer asked for, in words. Empty when there is not one. */
    private static function kitchenRunDeliveryDay($date): string
    {
        $day = trim((string) ($date ?? ''));
        if ($day === '') {
            return '';
        }
        $stamp = strtotime($day);
        return $stamp === false ? '' : date('l jS F', $stamp);
    }

    private static function kitchenRunDepositLine(?int $total, ?int $deposit): string
    {
        if ($total === null || $deposit === null || $deposit < 1) {
            return 'Nothing is due until we have agreed the list.';
        }
        return 'Deposit to start: ' . Money::format($deposit)
            . '. Balance on delivery: ' . Money::format(Money::balance($total, $deposit)) . '.';
    }

    private static function kitchenRunExpiryLine($quotedAt): string
    {
        $quoted = trim((string) ($quotedAt ?? ''));
        if ($quoted === '') {
            return '';
        }
        return date('l jS F', (int) strtotime('+' . KitchenRuns::quoteDays() . ' days', (int) strtotime($quoted)));
    }

    private static function kitchenRunBudgetLine(array $request): string
    {
        if (empty($request['is_open_budget'])) {
            return 'Standard request, priced line by line.';
        }
        $cap = $request['spend_cap_subunit'] === null ? null : (int) $request['spend_cap_subunit'];
        return 'Open budget. ' . ($cap === null
            ? 'No spend cap agreed, so set a deposit you are comfortable with.'
            : 'Agreed spend cap: ' . Money::format($cap) . '.');
    }

    /** A list has come in and somebody has to price it (PRD Section 14). */
    public static function announceKitchenRunSubmitted(int $requestId): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('admin_new_kitchen_run', $context['vars'], self::staffRecipients(), 'kitchen_run', $requestId);
    }

    /**
     * The customer has just sent a list, so tell them we have it (item 33 of
     * the M7 review). Four events fired after M7 and only the team heard any
     * of them; the person who pressed the button heard nothing at all.
     */
    public static function announceKitchenRunReceived(int $requestId): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('kitchen_run_received', $context['vars'], $context['recipients'], 'kitchen_run', $requestId);
    }

    /** The customer's prices, and how long they stand. */
    public static function announceKitchenRunQuoted(int $requestId, ?int $actorId = null): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('kitchen_run_quoted', $context['vars'], $context['recipients'], 'kitchen_run', $requestId, $actorId);
    }

    /** The customer said yes. Somebody has to turn it into an order. */
    public static function announceKitchenRunApproved(int $requestId, ?int $actorId = null): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('admin_kitchen_run_approved', $context['vars'], self::staffRecipients(), 'kitchen_run', $requestId, $actorId);
    }

    /**
     * A customer withdrew a run they had already approved. There is no money to
     * reverse, because the deposit is only taken at conversion, but the produce
     * may already be bought, so the team hears about it straight away.
     */
    public static function announceKitchenRunCancelled(int $requestId, ?int $actorId = null): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('admin_kitchen_run_cancelled', $context['vars'], self::staffRecipients(), 'kitchen_run', $requestId, $actorId);
    }

    /** We are not taking this one on, and the customer is told why. */
    public static function announceKitchenRunDeclined(int $requestId, ?int $actorId = null): void
    {
        $context = self::kitchenRunContext($requestId);
        if ($context === null) {
            return;
        }
        self::send('kitchen_run_declined', $context['vars'], $context['recipients'], 'kitchen_run', $requestId, $actorId);
    }

    // --- Credit (PRD Section 12) ---------------------------------------------

    /**
     * The facts a credit email needs. One helper for an application, one for a
     * business directly, one for a charge. Each returns null when the row is
     * gone, so a caller sends nothing rather than an email full of blanks.
     */
    private static function creditApplicationContext(int $applicationId): ?array
    {
        $row = Database::one(
            'SELECT ca.id, ca.requested_days, ca.requested_limit_subunit, ca.reason, ca.decision_reason,
                    ca.status, ca.business_customer_id,
                    bc.business_name, bc.credit_limit_subunit, bc.credit_days, bc.credit_status,
                    u.id AS user_id, u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM credit_applications ca
               JOIN business_customers bc ON bc.id = ca.business_customer_id
               JOIN users u ON u.id = bc.user_id
              WHERE ca.id = :id',
            [':id' => $applicationId]
        );
        if (!$row) {
            return null;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $customer = (string) ($row['business_name'] !== '' ? $row['business_name'] : $row['user_name']);
        $limit = $row['credit_limit_subunit'] === null ? null : (int) $row['credit_limit_subunit'];
        $days = (int) ($row['credit_days'] ?? 0);
        return [
            'business_id' => (int) $row['business_customer_id'],
            'user_id'     => (int) $row['user_id'],
            'recipients'  => self::customerRecipients(['user_email' => $row['user_email'], 'user_id' => $row['user_id']]),
            'vars' => [
                'customer_name'   => trim((string) $row['user_name']) ?: 'there',
                'business_name'   => trim((string) $row['business_name']) ?: $customer,
                'credit_limit'    => $limit === null ? '' : Money::format($limit),
                'credit_days'     => $days > 0 ? $days . ' days' : '',
                'declined_reason' => trim((string) $row['decision_reason']) !== ''
                    ? trim((string) $row['decision_reason'])
                    : 'We need a longer trading history before we can set this limit.',
                'requested_days'  => ((int) $row['requested_days']) . ' days',
                'requested_limit' => $row['requested_limit_subunit'] === null ? '' : Money::format((int) $row['requested_limit_subunit']),
                'reason'          => trim((string) $row['reason']),
                'credit_url'      => $base . '/pro/credit.php',
                'admin_url'       => $base . '/admin/credit.php?application=' . (int) $row['id'],
            ],
        ];
    }

    private static function creditBusinessContext(int $businessCustomerId): ?array
    {
        $row = Database::one(
            'SELECT bc.id, bc.business_name, bc.credit_limit_subunit, bc.credit_days, bc.credit_status,
                    u.id AS user_id, u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM business_customers bc
               JOIN users u ON u.id = bc.user_id
              WHERE bc.id = :id',
            [':id' => $businessCustomerId]
        );
        if (!$row) {
            return null;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $limit = $row['credit_limit_subunit'] === null ? null : (int) $row['credit_limit_subunit'];
        $days = (int) ($row['credit_days'] ?? 0);
        return [
            'business_id' => (int) $row['id'],
            'user_id'     => (int) $row['user_id'],
            'recipients'  => self::customerRecipients(['user_email' => $row['user_email'], 'user_id' => $row['user_id']]),
            'vars' => [
                'customer_name' => trim((string) $row['user_name']) ?: 'there',
                'business_name' => trim((string) $row['business_name']) ?: 'there',
                'credit_limit'  => $limit === null ? '' : Money::format($limit),
                'credit_days'   => $days > 0 ? $days . ' days' : '',
                'credit_url'    => $base . '/pro/credit.php',
                'admin_url'     => $base . '/admin/credit.php?business=' . (int) $row['id'],
            ],
        ];
    }

    private static function creditChargeContext(int $orderId): ?array
    {
        $charge = Database::one(
            'SELECT amount_subunit, due_date FROM credit_transactions
              WHERE source_key = :key AND transaction_type = :type LIMIT 1',
            [':key' => 'order:' . $orderId . ':charge', ':type' => 'charge']
        );
        if (!$charge) {
            $charge = Database::one(
                'SELECT amount_subunit, due_date FROM credit_transactions
                  WHERE order_id = :order AND transaction_type = :type ORDER BY id ASC LIMIT 1',
                [':order' => $orderId, ':type' => 'charge']
            );
        }
        if (!$charge) {
            return null;
        }
        $order = Database::one(
            'SELECT o.id, o.order_number, o.user_id, o.order_trail_token_hash,
                    bc.business_name, u.email AS user_email,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS user_name
               FROM orders o
               LEFT JOIN business_customers bc ON bc.user_id = o.user_id
               LEFT JOIN users u ON u.id = o.user_id
              WHERE o.id = :id',
            [':id' => $orderId]
        );
        if (!$order) {
            return null;
        }
        $base = rtrim((string) (defined('APP_URL') ? APP_URL : ''), '/');
        $due = trim((string) ($charge['due_date'] ?? ''));
        $dueLabel = $due !== '' && strtotime($due) !== false ? date('l jS F', strtotime($due)) : $due;
        // Trail link: we have only the hash, not the raw token, so link by order id.
        return [
            'recipients' => self::customerRecipients(['user_email' => $order['user_email'], 'user_id' => $order['user_id']]),
            'vars' => [
                'customer_name'   => trim((string) $order['user_name']) ?: 'there',
                'business_name'   => trim((string) ($order['business_name'] ?? '')) ?: trim((string) $order['user_name']) ?: 'there',
                'order_number'    => (string) $order['order_number'],
                'amount'          => Money::format((int) $charge['amount_subunit']),
                'due_date'        => $dueLabel,
                'order_trail_url' => $base . '/public/order.php?order=' . (int) $order['id'],
                'admin_url'       => $base . '/admin/orders.php?order=' . (int) $order['id'],
            ],
        ];
    }

    /** A business asked for credit, the team needs to review it. */
    public static function announceCreditApplicationSubmitted(int $applicationId, ?int $actorId = null): void
    {
        $context = self::creditApplicationContext($applicationId);
        if ($context === null) {
            return;
        }
        self::send('admin_new_credit_application', $context['vars'], self::staffRecipients(), 'credit_application', $applicationId, $actorId);
    }

    /** An approved application, customer hears the approved limit and terms. */
    public static function announceCreditApproved(int $applicationId, ?int $actorId = null): void
    {
        $context = self::creditApplicationContext($applicationId);
        if ($context === null) {
            return;
        }
        // Approved terms come from the business row, which the approval already wrote.
        self::send('credit_approved', $context['vars'], $context['recipients'], 'credit_application', $applicationId, $actorId);
    }

    /** A manual grant without an application shares the same customer words. */
    public static function announceCreditGranted(int $businessCustomerId, ?int $actorId = null): void
    {
        $context = self::creditBusinessContext($businessCustomerId);
        if ($context === null) {
            return;
        }
        self::send('credit_approved', $context['vars'], $context['recipients'], 'business_customer', $businessCustomerId, $actorId);
    }

    /** A declined application, with the approved staff reason only. */
    public static function announceCreditDeclined(int $applicationId, ?int $actorId = null): void
    {
        $context = self::creditApplicationContext($applicationId);
        if ($context === null) {
            return;
        }
        // Never echo the customer's own application reason, only the reviewer decision.
        self::send('credit_declined', $context['vars'], $context['recipients'], 'credit_application', $applicationId, $actorId);
    }

    /** A charge placed on account, with amount and due date. */
    public static function announceCreditChargePosted(int $orderId, ?int $actorId = null): void
    {
        $context = self::creditChargeContext($orderId);
        if ($context === null) {
            return;
        }
        self::send('credit_charge_posted', $context['vars'], $context['recipients'], 'order', $orderId, $actorId);
    }

    /** A lifecycle stage the customer should hear about. */
    public static function announceStage(int $orderId, string $status, ?int $actorId = null): void
    {
        $event = self::STAGE_EVENTS[$status] ?? null;
        if ($event === null) {
            return;
        }
        $context = self::orderContext($orderId);
        if ($context === null) {
            return;
        }
        self::send($event, $context['vars'], $context['recipients'], 'order', $orderId, $actorId);
    }

    /** A cancellation, with the money outcome said plainly rather than implied. */
    public static function announceCancellation(int $orderId, array $result, ?int $actorId = null): void
    {
        $context = self::orderContext($orderId);
        if ($context === null) {
            return;
        }
        // Nothing that was waiting on this order should still go out: no
        // reminder to pay for it, and no receipt held for a payment that will
        // now never be made.
        self::cancelPending('order', $orderId, ['order_payment_pending', 'payment_confirmed']);

        $vars = $context['vars'] + ['money_line' => self::cancellationMoneyLine($result)];
        self::send('order_cancelled', $vars, $context['recipients'], 'order', $orderId, $actorId);
    }

    /** What a cancelled customer needs to know about their money, in one line. */
    public static function cancellationMoneyLine(array $result): string
    {
        $refund  = (int) ($result['refund_subunit'] ?? 0);
        $forfeit = (int) ($result['forfeit_subunit'] ?? 0);
        $manual  = (int) ($result['manual_subunit'] ?? 0);
        $status  = (string) ($result['refund_status'] ?? 'not_required');

        if ($refund < 1 && $forfeit < 1) {
            return 'Nothing had been paid on this order, so there is no refund to wait for.';
        }
        $lines = [];
        if ($refund > 0) {
            if ($status === 'processed') {
                $lines[] = 'We have sent ' . Money::format($refund) . ' back to you.';
            } elseif (in_array($status, ['failed', 'failed_manual'], true)) {
                $lines[] = 'We could not send ' . Money::format($refund) . ' back automatically. Our team has been told and will check it before contacting you.';
            } else {
                $lines[] = 'We are sending ' . Money::format($refund) . ' back to you. It goes to the account you paid from and most banks show it within a few working days.';
            }
        }
        if ($manual > 0) {
            $lines[] = 'Part of that money, ' . Money::format($manual) . ', was paid outside our online gateway, so our team returns it by hand and will confirm it with you.';
        }
        if ($forfeit > 0) {
            $lines[] = 'The deposit of ' . Money::format($forfeit) . ' is kept, '
                     . Cancellation::forfeitReason((string) ($result['forfeit_reason'] ?? '')) . '.';
        }
        return implode(' ', $lines);
    }

    /** A verified Paystack charge: a full payment, or a deposit with a balance. */
    public static function announceCharge(array $result): void
    {
        if (empty($result['ok']) || (int) ($result['order_id'] ?? 0) < 1) {
            return;
        }
        $orderId = (int) $result['order_id'];
        $payment = Database::one(
            'SELECT payment_type FROM payments WHERE id = :id',
            [':id' => (int) ($result['payment_id'] ?? 0)]
        );
        $context = self::orderContext($orderId);
        if ($context === null) {
            return;
        }
        // The money is in, so nothing is pending any more.
        self::cancelPending('order', $orderId, ['order_payment_pending']);

        $vars = $context['vars'] + ['amount' => Money::format((int) ($result['credited'] ?? 0))];
        if ((string) ($payment['payment_type'] ?? '') === 'deposit') {
            self::send('deposit_received', $vars, $context['recipients'], 'order', $orderId);
            return;
        }

        // A pay in full order had its receipt written at checkout and held for
        // this moment, because only that copy carries a working Order Trail
        // link. Send a fresh one when nothing was waiting: an order placed
        // before this seam existed, or a balance settled online later.
        if (self::release('order', $orderId, 'payment_confirmed') > 0) {
            return;
        }
        self::send('payment_confirmed', $vars, $context['recipients'], 'order', $orderId);
    }

    /** Cash or a transfer recorded by staff. */
    public static function announceManualPayment(array $result, ?int $staffId = null): void
    {
        if (empty($result['ok']) || (int) ($result['order_id'] ?? 0) < 1) {
            return;
        }
        $orderId = (int) $result['order_id'];
        $context = self::orderContext($orderId);
        if ($context === null) {
            return;
        }
        // Money is money. An order settled at the counter is not waiting for a
        // payment either, so the reminder goes with it.
        self::cancelPending('order', $orderId, ['order_payment_pending']);

        $vars = $context['vars'] + ['amount' => Money::format((int) ($result['amount_subunit'] ?? 0))];
        self::send('payment_recorded', $vars, $context['recipients'], 'order', $orderId, $staffId);
    }

    /** A refund that landed goes to the customer; one that failed goes to staff. */
    public static function announceRefund(array $result): void
    {
        $orderId = (int) ($result['order_id'] ?? 0);
        // Refunds::request reports the request operation in `code` and the
        // gateway outcome in `status`. Webhook results use `code` for the
        // outcome. Reading both lets an immediate processed or failed response
        // announce itself without waiting for a webhook that may only say the
        // row is already final.
        $status  = (string) ($result['status'] ?? $result['code'] ?? '');
        if ($orderId < 1 || !in_array($status, [Refunds::STATUS_PROCESSED, Refunds::STATUS_FAILED], true)) {
            return;
        }
        $context = self::orderContext($orderId);
        if ($context === null) {
            return;
        }
        $amount = Money::format((int) ($result['amount_subunit'] ?? 0));
        if ($status === Refunds::STATUS_PROCESSED) {
            self::send('refund_processed', $context['vars'] + ['amount' => $amount], $context['recipients'], 'order', $orderId);
            return;
        }
        $vars = $context['vars'] + [
            'amount' => $amount,
            'reason' => trim((string) ($result['message'] ?? '')) ?: 'No reason was given.',
        ];
        self::send('refund_failed', $vars, self::staffRecipients(), 'order', $orderId);
    }

    /** The real work behind send(). */
    private static function dispatch(
        string $event,
        array $vars,
        array $recipients,
        ?string $relatedType,
        ?int $relatedId,
        ?int $actorId,
        string $disposition = 'now',
        ?string $sendAt = null
    ): int {
        $definition = self::EVENTS[$event] ?? null;
        if ($definition === null) {
            error_log('Notifications: unknown event ' . $event);
            return 0;
        }
        $recipients = self::cleanRecipients($recipients);
        if (!$recipients) {
            return 0;
        }

        $templateKey = $definition['template'];
        $template = Database::one(
            'SELECT id, subject_template, body_template FROM notification_templates
              WHERE template_key = :key AND is_active = 1',
            [':key' => $templateKey]
        );
        if (!$template) {
            error_log('Notifications: template missing or switched off: ' . $templateKey);
            return 0;
        }

        [$subject, $body] = self::render($template, $vars);
        $cta = Mail::ctaFromVars($vars);

        $notificationId = self::writeNotification(
            $event,
            $subject,
            $body,
            (int) $template['id'],
            $relatedType,
            $relatedId,
            $actorId,
            $disposition === 'now' ? 'queued' : $disposition,
            $disposition === self::STATUS_QUEUED ? $sendAt : null,
            $cta
        );
        if ($notificationId < 1) {
            return 0;
        }

        // Written, not sent. Something else releases a held notification, and
        // the cron pass sends a queued one when its time comes.
        if ($disposition !== 'now') {
            foreach ($recipients as $recipient) {
                if (!empty($recipient['user_id'])) {
                    self::writeDelivery($notificationId, (int) $recipient['user_id'], self::CHANNEL_IN_APP, 'in-app', 'queued', 0, null);
                }
                if (empty($recipient['email'])) {
                    continue;
                }
                self::writeDelivery(
                    $notificationId,
                    $recipient['user_id'] ?? null,
                    self::CHANNEL_EMAIL,
                    (string) $recipient['email'],
                    'queued',
                    0,
                    null
                );
            }
            return $notificationId;
        }

        $anySent = false;
        foreach ($recipients as $recipient) {
            if (!empty($recipient['user_id'])) {
                self::writeDelivery($notificationId, (int) $recipient['user_id'], self::CHANNEL_IN_APP, 'in-app', self::STATUS_SENT, 1, null);
                $anySent = true;
            }
            if (empty($recipient['email'])) {
                continue;
            }
            $deliveryId = self::writeDelivery(
                $notificationId,
                $recipient['user_id'] ?? null,
                self::CHANNEL_EMAIL,
                (string) $recipient['email'],
                'queued',
                0,
                null
            );
            $sent = false;
            try {
                $sent = Mail::send(
                    (string) $recipient['email'],
                    $subject,
                    Mail::brandedHtml($subject, $body, $cta),
                    Mail::plainText($subject, $body, $cta)
                );
            } catch (Throwable $e) {
                error_log('Notifications: send threw for ' . $event . ': ' . $e->getMessage());
            }
            self::recordAttempt($deliveryId, 1, $sent, 'The mail server would not take the message.');
            $anySent = $anySent || $sent;
        }

        Database::run(
            'UPDATE notifications SET status = :status WHERE id = :id',
            [':status' => $anySent ? self::STATUS_SENT : self::STATUS_FAILED, ':id' => $notificationId]
        );
        return $notificationId;
    }

    /** @return array{0: string, 1: string} */
    private static function render(array $template, array $vars): array
    {
        $fill = static function (string $text) use ($vars): string {
            return trim((string) preg_replace_callback(
                '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
                static fn(array $m): string => isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '',
                $text
            ));
        };
        $subject = $fill((string) ($template['subject_template'] ?? ''));
        $body = (string) preg_replace("/\n{3,}/", "\n\n", $fill((string) ($template['body_template'] ?? '')));
        return [$subject !== '' ? $subject : 'OK Veggies', $body];
    }

    private static function writeNotification(
        string $event,
        string $subject,
        string $body,
        int $templateId,
        ?string $relatedType,
        ?int $relatedId,
        ?int $actorId,
        string $status = 'queued',
        ?string $sendAt = null,
        ?array $cta = null
    ): int {
        Database::run(
            'INSERT INTO notifications
                (event_type, related_type, related_id, template_id, title, body,
                 cta_url, cta_label, status, scheduled_at, created_by)
             VALUES (:event, :type, :related, :template, :title, :body,
                     :cta_url, :cta_label, :status, :send_at, :actor)',
            [
                ':event'     => mb_substr($event, 0, 100),
                ':type'      => $relatedType,
                ':related'   => $relatedId,
                ':template'  => $templateId,
                ':title'     => mb_substr($subject, 0, 255),
                ':body'      => $body,
                ':cta_url'   => $cta === null ? null : mb_substr((string) $cta['url'], 0, 500),
                ':cta_label' => $cta === null ? null : mb_substr((string) $cta['label'], 0, 80),
                ':status'    => $status,
                ':send_at'   => $sendAt,
                ':actor'     => $actorId,
            ]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private static function writeDelivery(
        int $notificationId,
        ?int $userId,
        string $channel,
        string $address,
        string $status,
        int $attempts,
        ?string $error
    ): int {
        Database::run(
            'INSERT INTO notification_deliveries
                (notification_id, user_id, channel, recipient_address, provider, status, attempt_count, last_error, sent_at)
             VALUES (:notification, :user, :channel, :address, :provider, :status, :attempts, :error, :sent_at)',
            [
                ':notification' => $notificationId,
                ':user'         => $userId,
                ':channel'      => $channel,
                ':address'      => mb_substr($address, 0, 255),
                ':provider'     => $channel === self::CHANNEL_EMAIL ? 'smtp' : null,
                ':status'       => $status,
                ':attempts'     => $attempts,
                ':error'        => $error,
                ':sent_at'      => $status === self::STATUS_SENT ? date('Y-m-d H:i:s') : null,
            ]
        );
        return (int) Database::getInstance()->getConnection()->lastInsertId();
    }

    private static function recordAttempt(int $deliveryId, int $attempts, bool $sent, string $error): void
    {
        Database::run(
            'UPDATE notification_deliveries
                SET status = :status, attempt_count = :attempts, sent_at = :sent_at, last_error = :error
              WHERE id = :id',
            [
                ':status'   => $sent ? self::STATUS_SENT : self::STATUS_FAILED,
                ':attempts' => $attempts,
                ':sent_at'  => $sent ? date('Y-m-d H:i:s') : null,
                ':error'    => $sent ? null : mb_substr($error, 0, 1000),
                ':id'       => $deliveryId,
            ]
        );
    }

    /** Drop empty rows and send one message per address, never two. */
    private static function cleanRecipients(array $recipients): array
    {
        $clean = [];
        $seenEmail = [];
        $seenUser = [];
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $userId = isset($recipient['user_id']) && $recipient['user_id'] !== null ? (int) $recipient['user_id'] : null;
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
            }
            if ($email !== '' && isset($seenEmail[mb_strtolower($email)])) {
                $email = '';
            }
            if ($userId !== null && isset($seenUser[$userId])) {
                $userId = null;
            }
            if ($email === '' && $userId === null) {
                continue;
            }
            if ($email !== '') {
                $seenEmail[mb_strtolower($email)] = true;
            }
            if ($userId !== null) {
                $seenUser[$userId] = true;
            }
            $clean[] = ['email' => $email !== '' ? $email : null, 'user_id' => $userId];
        }
        return $clean;
    }
}
