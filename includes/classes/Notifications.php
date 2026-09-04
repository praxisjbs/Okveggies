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
        'deposit_received'  => ['template' => 'deposit_received',  'label' => 'Deposit received',        'audience' => 'customer'],
        'payment_recorded'  => ['template' => 'payment_recorded',  'label' => 'Payment recorded by staff', 'audience' => 'customer'],
        'refund_processed'  => ['template' => 'refund_processed',  'label' => 'Refund sent',             'audience' => 'customer'],
        'refund_failed'     => ['template' => 'refund_failed',     'label' => 'Refund failed',           'audience' => 'staff'],
        'admin_new_order'   => ['template' => 'admin_new_order',   'label' => 'New order, for staff',    'audience' => 'staff'],
    ];

    /** The tokens each template may use, so the editor can list them honestly. */
    public const TOKENS = [
        'order_placed'      => ['customer_name', 'order_number', 'delivery_day', 'order_total', 'source_line', 'order_trail_url'],
        'order_confirmed'   => ['customer_name', 'order_number', 'delivery_day', 'source_line', 'order_trail_url'],
        'order_packed'      => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_dispatched'  => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_delivered'   => ['customer_name', 'order_number', 'delivery_day', 'order_trail_url'],
        'order_cancelled'   => ['customer_name', 'order_number', 'money_line', 'order_trail_url'],
        'payment_confirmed' => ['customer_name', 'order_number', 'amount', 'order_trail_url'],
        'deposit_received'  => ['customer_name', 'order_number', 'amount', 'balance_line', 'order_trail_url'],
        'payment_recorded'  => ['customer_name', 'order_number', 'amount', 'balance_line', 'order_trail_url'],
        'refund_processed'  => ['customer_name', 'order_number', 'amount', 'order_trail_url'],
        'refund_failed'     => ['order_number', 'amount', 'reason', 'admin_url'],
        'admin_new_order'   => ['customer_name', 'order_number', 'order_total', 'delivery_day', 'zone_name', 'payment_choice', 'admin_url'],
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

    /** Everything sent about one order, newest first, for the Order 360 screen. */
    public static function forOrder(int $orderId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        return Database::all(
            'SELECT n.id, n.event_type, n.title, n.body, n.status, n.created_at,
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
                    n.title, n.body, n.event_type
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

        $sent = false;
        $error = '';
        try {
            $sent = Mail::send(
                (string) $delivery['recipient_address'],
                (string) $delivery['title'],
                Mail::brandedHtml((string) $delivery['title'], (string) $delivery['body']),
                Mail::plainText((string) $delivery['title'], (string) $delivery['body'])
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
                    o.preferred_delivery_date, o.source_regions_snapshot, o.user_id,
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

        return [
            'order_id'   => (int) $order['id'],
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
                'order_trail_url' => $trailToken !== null && OrderTrail::isValidToken($trailToken)
                    ? $base . '/public/order.php?token=' . rawurlencode($trailToken)
                    : $base . '/public/order.php?order=' . (int) $order['id'],
                'admin_url'      => $base . '/admin/orders.php?order=' . (int) $order['id'],
            ],
        ];
    }

    private static function customerRecipients(array $order): array
    {
        $email = trim((string) ($order['user_email'] ?? ''));
        $userId = $order['user_id'] !== null ? (int) $order['user_id'] : null;
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

    /** A new order: the customer's receipt of it, and the staff alert. */
    public static function announceOrderPlaced(int $orderId, string $trailToken = ''): void
    {
        $context = self::orderContext($orderId, $trailToken !== '' ? $trailToken : null);
        if ($context === null) {
            return;
        }
        self::send('order_placed', $context['vars'], $context['recipients'], 'order', $orderId);

        $staffVars = $context['vars'];
        unset($staffVars['order_trail_url']);
        self::send('admin_new_order', $staffVars, self::staffRecipients(), 'order', $orderId);
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
            $lines[] = $status === 'processed'
                ? 'We have sent ' . Money::format($refund) . ' back to you.'
                : 'We are sending ' . Money::format($refund) . ' back to you. It goes to the account you paid from and most banks show it within a few working days.';
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
        $vars = $context['vars'] + ['amount' => Money::format((int) ($result['credited'] ?? 0))];
        $event = (string) ($payment['payment_type'] ?? '') === 'deposit' ? 'deposit_received' : 'payment_confirmed';
        self::send($event, $vars, $context['recipients'], 'order', $orderId);
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
        $vars = $context['vars'] + ['amount' => Money::format((int) ($result['amount_subunit'] ?? 0))];
        self::send('payment_recorded', $vars, $context['recipients'], 'order', $orderId, $staffId);
    }

    /** A refund that landed goes to the customer; one that failed goes to staff. */
    public static function announceRefund(array $result): void
    {
        $orderId = (int) ($result['order_id'] ?? 0);
        $status  = (string) ($result['code'] ?? '');
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
        ?int $actorId
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

        $notificationId = self::writeNotification($event, $subject, $body, (int) $template['id'], $relatedType, $relatedId, $actorId);
        if ($notificationId < 1) {
            return 0;
        }

        $cta = Mail::ctaFromVars($vars);
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
        ?int $actorId
    ): int {
        Database::run(
            'INSERT INTO notifications (event_type, related_type, related_id, template_id, title, body, status, created_by)
             VALUES (:event, :type, :related, :template, :title, :body, :status, :actor)',
            [
                ':event'    => mb_substr($event, 0, 100),
                ':type'     => $relatedType,
                ':related'  => $relatedId,
                ':template' => $templateId,
                ':title'    => mb_substr($subject, 0, 255),
                ':body'     => $body,
                ':status'   => 'queued',
                ':actor'    => $actorId,
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
