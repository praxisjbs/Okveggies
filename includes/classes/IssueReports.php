<?php
/** Shared Make It Right rules for customer and staff routes. */
final class IssueReports
{
    public const REPORTING_WINDOW_KEY = 'make_it_right_reporting_window_days';
    public const DEFAULT_REPORTING_WINDOW_DAYS = 7;
    public const DESCRIPTION_MIN = 10;
    public const DESCRIPTION_MAX = 1000;
    public const ACCOUNT_LIMIT = 5;
    public const IP_LIMIT = 20;
    public const RATE_WINDOW = 3600;
    public const MAX_PHOTOS = 5;
    public const PHOTO_MIME = ['image/jpeg', 'image/png', 'image/webp'];
    public const PER_PAGE = 25;
    public const STATUSES = ['open', 'in_progress', 'resolved', 'declined'];
    public const RESOLUTION_TYPES = [
        'refund' => 'Refund',
        'credit' => 'Account credit',
        'replacement' => 'Replacement',
    ];
    public const RESOLUTION_NOTE_MIN = 10;
    public const RESOLUTION_NOTE_MAX = 1000;

    public const CATEGORIES = [
        'wrong_item'     => 'Wrong item',
        'missing_item'   => 'Missing item',
        'quality'        => 'Quality',
        'short_quantity' => 'Short quantity',
        'damaged'        => 'Damaged',
        'late'           => 'Late',
        'something_else' => 'Something else',
    ];

    public static function reportingWindowDays(): int
    {
        return max(1, min(90, Settings::int(self::REPORTING_WINDOW_KEY, self::DEFAULT_REPORTING_WINDOW_DAYS)));
    }

    /** Validate and normalise customer words without touching the database. */
    public static function validateFields(string $category, string $description): array
    {
        $category = trim($category);
        $description = trim(str_replace(["\r\n", "\r"], "\n", $description));
        if (!isset(self::CATEGORIES[$category])) {
            return self::failure('category_required', 'Choose what was not right.', 'category');
        }
        $length = mb_strlen($description);
        if ($length < self::DESCRIPTION_MIN) {
            return self::failure('description_too_short', 'Tell us what happened in at least 10 characters.', 'description');
        }
        if ($length > self::DESCRIPTION_MAX) {
            return self::failure('description_too_long', 'Keep your description to 1,000 characters or fewer.', 'description');
        }
        return ['ok' => true, 'category' => $category, 'description' => $description];
    }

    /**
     * Work out whether an owned order is reportable. The final calendar day is
     * inclusive in Africa/Lagos. Missing lifecycle evidence fails closed.
     */
    public static function eligibility(array $order, ?DateTimeImmutable $now = null, ?int $windowDays = null): array
    {
        $status = (string) ($order['order_status'] ?? '');
        if (!in_array($status, ['dispatched', 'delivered'], true)) {
            return self::failure(
                'ineligible_status',
                'You can report a delivery problem after order ' . (string) ($order['order_number'] ?? '') . ' has been dispatched.'
            );
        }

        $anchorRaw = $status === 'delivered'
            ? trim((string) ($order['delivered_at'] ?? ''))
            : trim((string) ($order['dispatched_at'] ?? ''));
        if ($anchorRaw === '') {
            return self::failure(
                'missing_timestamp',
                'We cannot confirm the reporting time for this order online. Please contact us and we will check it.'
            );
        }

        $zone = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Africa/Lagos');
        try {
            $anchor = new DateTimeImmutable($anchorRaw, $zone);
        } catch (Throwable $e) {
            return self::failure(
                'missing_timestamp',
                'We cannot confirm the reporting time for this order online. Please contact us and we will check it.'
            );
        }
        $days = max(1, min(90, $windowDays ?? self::reportingWindowDays()));
        $deadline = $anchor->modify('+' . $days . ' days')->setTime(23, 59, 59);
        $now = $now === null ? new DateTimeImmutable('now', $zone) : $now->setTimezone($zone);
        if ($now > $deadline) {
            return self::failure(
                'expired',
                'The reporting window for order ' . (string) ($order['order_number'] ?? '') . ' closed on ' . $deadline->format('l jS F') . '.'
            ) + ['anchor' => $anchor, 'deadline' => $deadline];
        }
        return ['ok' => true, 'code' => 'eligible', 'anchor' => $anchor, 'deadline' => $deadline];
    }

    /** Owner-only state used by the authenticated order page. */
    public static function stateForCustomer(int $orderId, int $userId): ?array
    {
        $order = self::ownedOrder($orderId, $userId, false);
        if ($order === null) {
            return null;
        }
        $report = Database::one(
            'SELECT id, category, description, status, resolution_type, resolution_note,
                    created_at, resolved_at
               FROM issue_reports
              WHERE order_id = :order_id AND resolved_at IS NULL
              LIMIT 1',
            [':order_id' => $orderId]
        );
        if ($report !== null) {
            $report['photos'] = self::photosForReport((int) $report['id']);
            return [
                'ok' => false,
                'code' => 'already_open',
                'order' => $order,
                'report' => $report,
                'message' => 'We already have an open report for order ' . $order['order_number'] . '.',
            ];
        }
        return self::eligibility($order) + ['order' => $order, 'report' => null];
    }

    /**
     * Create one report. The order row lock and generated unique key jointly
     * protect the one-active-report rule under concurrent requests.
     */
    public static function submit(
        int $orderId,
        int $userId,
        string $category,
        string $description,
        ?string $ipAddress = null,
        array $photos = []
    ): array {
        $limited = self::spendRateAllowance($userId, $ipAddress);
        if ($limited !== null) {
            return $limited;
        }

        $clean = self::validateFields($category, $description);
        if (empty($clean['ok'])) {
            return $clean;
        }
        if (count($photos) > self::MAX_PHOTOS) {
            return self::failure('too_many_photos', 'Choose no more than 5 photos.', 'photos');
        }

        $pdo = Database::getInstance()->getConnection();
        $storedPhotos = [];
        $pdo->beginTransaction();
        try {
            $order = self::ownedOrder($orderId, $userId, true);
            if ($order === null) {
                $pdo->rollBack();
                return self::failure('not_found', 'We could not find that order in your account.');
            }
            $eligible = self::eligibility($order);
            if (empty($eligible['ok'])) {
                $pdo->rollBack();
                return $eligible;
            }
            $existing = Database::one(
                'SELECT id FROM issue_reports
                  WHERE order_id = :order_id AND resolved_at IS NULL
                  LIMIT 1 FOR UPDATE',
                [':order_id' => $orderId]
            );
            if ($existing !== null) {
                $pdo->commit();
                return [
                    'ok' => true,
                    'code' => 'already_open',
                    'issue_id' => (int) $existing['id'],
                    'order_number' => (string) $order['order_number'],
                ];
            }

            Database::run(
                'INSERT INTO issue_reports (order_id, user_id, category, description, status, active_slot)
                 VALUES (:order_id, :user_id, :category, :description, :status, :active_slot)',
                [
                    ':order_id' => $orderId,
                    ':user_id' => $userId,
                    ':category' => $clean['category'],
                    ':description' => $clean['description'],
                    ':status' => 'open',
                    ':active_slot' => 1,
                ]
            );
            $issueId = (int) $pdo->lastInsertId();

            foreach ($photos as $index => $photo) {
                $checked = Uploads::validateUploadedImage($photo, self::PHOTO_MIME);
                if (empty($checked['ok'])) {
                    $pdo->rollBack();
                    self::removeOrphanedPhotos($storedPhotos);
                    return self::photoFailure((string) ($checked['code'] ?? 'invalid_upload'), $index + 1);
                }
                try {
                    $path = Uploads::saveUploadedImage($photo, 'issues', self::PHOTO_MIME);
                } catch (RuntimeException $e) {
                    error_log('issue photo save failed: ' . $e->getMessage());
                    $pdo->rollBack();
                    self::removeOrphanedPhotos($storedPhotos);
                    return self::failure('photo_save_failed', 'We could not save photo ' . ($index + 1) . '. Choose the photos again and try once more.', 'photos');
                }
                $storedPhotos[] = $path;
                Database::run(
                    'INSERT INTO issue_report_photos (issue_id, photo_url)
                     VALUES (:issue_id, :photo_url)',
                    [':issue_id' => $issueId, ':photo_url' => $path]
                );
            }

            self::appendHistory($issueId, null, 'open', 'reported', null, $userId);

            Audit::record(
                'issue_reports.create',
                'issue_report',
                $issueId,
                null,
                [
                    'order_id' => $orderId,
                    'category' => $clean['category'],
                    'status' => 'open',
                    'photo_count' => count($storedPhotos),
                ],
                $userId
            );
            $pdo->commit();
            return [
                'ok' => true,
                'code' => 'reported',
                'issue_id' => $issueId,
                'order_number' => (string) $order['order_number'],
                'photo_count' => count($storedPhotos),
            ];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::removeOrphanedPhotos($storedPhotos);
            if ((string) $e->getCode() === '23000') {
                $existing = Database::one(
                    'SELECT id FROM issue_reports WHERE order_id = :order_id AND resolved_at IS NULL LIMIT 1',
                    [':order_id' => $orderId]
                );
                if ($existing !== null) {
                    return [
                        'ok' => true,
                        'code' => 'already_open',
                        'issue_id' => (int) $existing['id'],
                        'order_number' => (string) ($order['order_number'] ?? ''),
                    ];
                }
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            self::removeOrphanedPhotos($storedPhotos);
            throw $e;
        }
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'open' => 'Open',
            'in_progress' => 'Being handled',
            'resolved' => 'Resolved',
            'declined' => 'Declined',
            default => 'Unknown',
        };
    }

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? 'Something else';
    }

    public static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /** Reports for the staff queue. Active is the deliberate oldest-first default. */
    public static function countForStaff(string $status = 'active', string $category = '', string $from = '', string $to = ''): int
    {
        [$where, $params] = self::staffWhere($status, $category, $from, $to);
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM issue_reports i'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    public static function forStaff(
        string $status = 'active',
        string $category = '',
        string $from = '',
        string $to = '',
        int $page = 1
    ): array {
        [$where, $params] = self::staffWhere($status, $category, $from, $to);
        $sql = 'SELECT i.id, i.order_id, i.category, i.status, i.handled_by, i.created_at,
                       o.order_number,
                       TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name,
                       TRIM(CONCAT(COALESCE(h.first_name, \'\'), \' \', COALESCE(h.last_name, \'\'))) AS handler_name,
                       COUNT(p.id) AS photo_count
                  FROM issue_reports i
                  JOIN orders o ON o.id = i.order_id
             LEFT JOIN users u ON u.id = i.user_id
             LEFT JOIN users h ON h.id = i.handled_by
             LEFT JOIN issue_report_photos p ON p.issue_id = i.id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' GROUP BY i.id, o.order_number, u.first_name, u.last_name, h.first_name, h.last_name
                 ORDER BY i.created_at ASC, i.id ASC
                 LIMIT :limit OFFSET :offset';
        $stmt = Database::getInstance()->getConnection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (max(1, $page) - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Full same-screen staff context, including the M5-maintained paid total. */
    public static function findForStaff(int $issueId): ?array
    {
        if ($issueId < 1) {
            return null;
        }
        $report = Database::one(
            'SELECT i.*, o.order_number, o.order_status, o.payment_status, o.payment_option,
                    o.order_total_subunit, o.amount_paid_subunit AS verified_paid_subunit,
                    o.balance_due_subunit, o.preferred_delivery_date, o.delivered_at,
                    u.email AS customer_email, u.phone AS customer_phone,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS customer_name,
                    TRIM(CONCAT(COALESCE(h.first_name, \'\'), \' \', COALESCE(h.last_name, \'\'))) AS handler_name,
                    a.recipient_name, a.recipient_phone, a.address_line_1, a.address_line_2,
                    a.city, a.state AS delivery_state, a.landmark,
                    z.name AS zone_name, ds.status AS delivery_status, ds.delivered_at AS schedule_delivered_at,
                    (SELECT MIN(sh.created_at) FROM order_status_history sh
                      WHERE sh.order_id = o.id AND sh.new_status = :dispatch_status) AS dispatched_at
               FROM issue_reports i
               JOIN orders o ON o.id = i.order_id
          LEFT JOIN users u ON u.id = i.user_id
          LEFT JOIN users h ON h.id = i.handled_by
          LEFT JOIN order_addresses a ON a.order_id = o.id
          LEFT JOIN delivery_zones z ON z.id = o.delivery_zone_id
          LEFT JOIN delivery_schedules ds ON ds.order_id = o.id
              WHERE i.id = :id',
            [':dispatch_status' => 'dispatched', ':id' => $issueId]
        );
        if ($report === null) {
            return null;
        }
        $report['photos'] = self::photosForReport($issueId);
        $report['items'] = Database::all(
            'SELECT id, item_name, quantity, unit_name, line_total_subunit
               FROM order_items WHERE order_id = :order_id ORDER BY id',
            [':order_id' => (int) $report['order_id']]
        );
        $report['history'] = self::historyForStaff($issueId);
        return $report;
    }

    public static function forOrderStaff(int $orderId): array
    {
        if ($orderId < 1) {
            return [];
        }
        return Database::all(
            'SELECT id, category, status, created_at
               FROM issue_reports WHERE order_id = :order_id
           ORDER BY created_at DESC, id DESC',
            [':order_id' => $orderId]
        );
    }

    public static function historyForStaff(int $issueId): array
    {
        return Database::all(
            'SELECT h.id, h.old_status, h.new_status, h.action, h.note, h.created_at,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS actor_name
               FROM issue_report_history h
          LEFT JOIN users u ON u.id = h.acted_by
              WHERE h.issue_id = :issue_id
           ORDER BY h.created_at, h.id',
            [':issue_id' => $issueId]
        );
    }

    /** Take an unassigned open report. A same-actor replay is a harmless no-op. */
    public static function take(int $issueId, string $expectedStatus, int $actorId): array
    {
        if ($issueId < 1 || $actorId < 1 || $expectedStatus !== 'open') {
            return self::failure('invalid_take', 'Reload the report before taking it.');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $report = Database::one(
                'SELECT id, order_id, status, handled_by FROM issue_reports WHERE id = :id FOR UPDATE',
                [':id' => $issueId]
            );
            if ($report === null) {
                $pdo->rollBack();
                return self::failure('not_found', 'That report could not be found.');
            }
            $status = (string) $report['status'];
            $handler = $report['handled_by'] === null ? null : (int) $report['handled_by'];
            if ($status === 'in_progress' && $handler === $actorId) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'already_taken', 'status' => $status];
            }
            if (in_array($status, ['resolved', 'declined'], true)) {
                $pdo->rollBack();
                return self::failure('terminal', 'This report has already been finished.');
            }
            if ($status !== $expectedStatus || $handler !== null) {
                $pdo->rollBack();
                return self::failure('stale', 'This report changed after the page loaded. Reload it before taking it.');
            }
            Database::run(
                'UPDATE issue_reports
                    SET status = :status, handled_by = :actor, handled_at = NOW()
                  WHERE id = :id',
                [':status' => 'in_progress', ':actor' => $actorId, ':id' => $issueId]
            );
            self::appendHistory($issueId, 'open', 'in_progress', 'taken', null, $actorId);
            Audit::record(
                'issue_reports.take',
                'issue_report',
                $issueId,
                ['status' => 'open', 'handled_by' => null],
                ['status' => 'in_progress', 'handled_by' => $actorId],
                $actorId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => 'taken', 'status' => 'in_progress'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function validateResolutionNote(string $note): array
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        $length = mb_strlen($note);
        if ($length < self::RESOLUTION_NOTE_MIN) {
            return self::failure('resolution_note_too_short', 'Use at least 10 characters so the customer understands the outcome.', 'resolution_note');
        }
        if ($length > self::RESOLUTION_NOTE_MAX) {
            return self::failure('resolution_note_too_long', 'Keep the customer note to 1,000 characters or fewer.', 'resolution_note');
        }
        return ['ok' => true, 'note' => $note];
    }

    /** Decline is a permanent terminal outcome and clears the active slot. */
    public static function decline(int $issueId, string $expectedStatus, int $actorId, string $reason): array
    {
        $clean = self::validateResolutionNote($reason);
        if (empty($clean['ok'])) {
            return $clean;
        }
        if ($issueId < 1 || $actorId < 1 || $expectedStatus !== 'in_progress') {
            return self::failure('invalid_decline', 'Take the report before declining it.');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $report = Database::one(
                'SELECT id, status, handled_by, resolution_type, resolved_at
                   FROM issue_reports WHERE id = :id FOR UPDATE',
                [':id' => $issueId]
            );
            if ($report === null) {
                $pdo->rollBack();
                return self::failure('not_found', 'That report could not be found.');
            }
            $status = (string) $report['status'];
            if (in_array($status, ['resolved', 'declined'], true) || $report['resolved_at'] !== null) {
                $pdo->rollBack();
                return self::failure('terminal', 'This report has already been finished.');
            }
            if ($status !== $expectedStatus) {
                $pdo->rollBack();
                return self::failure('stale', 'This report changed after the page loaded. Reload it before declining it.');
            }
            if ((int) ($report['handled_by'] ?? 0) !== $actorId) {
                $pdo->rollBack();
                return self::failure('not_handler', 'Only the colleague handling this report can finish it.');
            }
            Database::run(
                'UPDATE issue_reports
                    SET status = :status, resolution_type = :type, resolution_note = :note,
                        resolved_at = NOW(), active_slot = NULL
                  WHERE id = :id',
                [':status' => 'declined', ':type' => 'declined', ':note' => $clean['note'], ':id' => $issueId]
            );
            self::appendHistory($issueId, 'in_progress', 'declined', 'declined', $clean['note'], $actorId);
            Audit::record(
                'issue_reports.decline',
                'issue_report',
                $issueId,
                ['status' => 'in_progress'],
                ['status' => 'declined', 'resolution_type' => 'declined'],
                $actorId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => 'declined', 'status' => 'declined'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Turn PHP's multiple-upload shape into ordinary file entries. */
    public static function normalisePhotoUpload(array $group): array
    {
        if ($group === [] || !isset($group['error'])) {
            return [];
        }
        if (!is_array($group['error'])) {
            return (int) $group['error'] === UPLOAD_ERR_NO_FILE ? [] : [$group];
        }

        $photos = [];
        foreach ($group['error'] as $index => $error) {
            if ((int) $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $photos[] = [
                'name' => $group['name'][$index] ?? '',
                'type' => $group['type'][$index] ?? '',
                'tmp_name' => $group['tmp_name'][$index] ?? '',
                'error' => $error,
                'size' => $group['size'][$index] ?? 0,
            ];
        }
        return $photos;
    }

    /** Metadata used by the protected photo route for owner and staff checks. */
    public static function photoAccessRecord(int $photoId): ?array
    {
        if ($photoId < 1) {
            return null;
        }
        return Database::one(
            'SELECT p.id, p.photo_url, p.issue_id, i.user_id, i.order_id, o.order_number
               FROM issue_report_photos p
               JOIN issue_reports i ON i.id = p.issue_id
               JOIN orders o ON o.id = i.order_id
              WHERE p.id = :id',
            [':id' => $photoId]
        );
    }

    /** Minimal Task B preview. Task C replaces this with the full report view. */
    public static function photoPreviewForStaff(int $issueId): ?array
    {
        if ($issueId < 1) {
            return null;
        }
        $report = Database::one(
            'SELECT i.id, i.category, o.id AS order_id, o.order_number
               FROM issue_reports i
               JOIN orders o ON o.id = i.order_id
              WHERE i.id = :id',
            [':id' => $issueId]
        );
        if ($report === null) {
            return null;
        }
        $report['photos'] = self::photosForReport($issueId);
        return $report;
    }

    private static function ownedOrder(int $orderId, int $userId, bool $lock): ?array
    {
        if ($orderId < 1 || $userId < 1) {
            return null;
        }
        return Database::one(
            'SELECT o.id, o.order_number, o.order_status,
                    COALESCE(o.delivered_at,
                      (SELECT MIN(d.created_at) FROM order_status_history d
                        WHERE d.order_id = o.id AND d.new_status = :delivery_status)) AS delivered_at,
                    (SELECT MIN(h.created_at) FROM order_status_history h
                      WHERE h.order_id = o.id AND h.new_status = :dispatch_status) AS dispatched_at
               FROM orders o
              WHERE o.id = :order_id AND o.user_id = :user_id
              LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [
                ':delivery_status' => 'delivered',
                ':dispatch_status' => 'dispatched',
                ':order_id' => $orderId,
                ':user_id' => $userId,
            ]
        );
    }

    private static function spendRateAllowance(int $userId, ?string $ipAddress): ?array
    {
        $accountAllowed = RateLimiter::hit(
            'issues:account:' . $userId,
            self::ACCOUNT_LIMIT,
            self::RATE_WINDOW
        );
        $ip = is_string($ipAddress) && filter_var($ipAddress, FILTER_VALIDATE_IP) ? $ipAddress : 'unknown';
        $ipAllowed = RateLimiter::hit(
            'issues:ip:' . hash('sha256', $ip),
            self::IP_LIMIT,
            self::RATE_WINDOW
        );
        if (!$accountAllowed || !$ipAllowed) {
            return self::failure('rate_limited', 'Too many reports were attempted. Wait 1 hour and try again.');
        }
        return null;
    }

    private static function photosForReport(int $issueId): array
    {
        return Database::all(
            'SELECT id, photo_url, created_at
               FROM issue_report_photos
              WHERE issue_id = :issue_id
           ORDER BY id',
            [':issue_id' => $issueId]
        );
    }

    private static function photoFailure(string $code, int $position): array
    {
        $message = match ($code) {
            'too_large' => 'Photo ' . $position . ' is larger than the allowed ' . self::photoLimitLabel() . '.',
            'unsupported_extension', 'unsupported_type', 'disguised_type' => 'Photo ' . $position . ' must be a JPEG, PNG or WebP image.',
            'unreadable_image', 'unsafe_dimensions', 'truncated_image', 'empty' => 'Photo ' . $position . ' is not a complete readable image.',
            default => 'Photo ' . $position . ' did not upload. Choose it again.',
        };
        return self::failure('photo_' . $code, $message, 'photos');
    }

    public static function photoLimitLabel(): string
    {
        $megabytes = Uploads::maxBytes() / (1024 * 1024);
        return rtrim(rtrim(number_format($megabytes, 1, '.', ''), '0'), '.') . 'MB';
    }

    private static function removeOrphanedPhotos(array $paths): void
    {
        foreach ($paths as $path) {
            if (!Uploads::removeStoredFile((string) $path, 'issues')) {
                error_log('issue photo orphan cleanup failed for a request-owned path');
            }
        }
    }

    private static function staffWhere(string $status, string $category, string $from, string $to): array
    {
        $where = [];
        $params = [];
        if ($status === 'active' || $status === '') {
            $where[] = 'i.status IN (:open_status, :progress_status)';
            $params[':open_status'] = 'open';
            $params[':progress_status'] = 'in_progress';
        } elseif (in_array($status, self::STATUSES, true)) {
            $where[] = 'i.status = :status';
            $params[':status'] = $status;
        }
        if (isset(self::CATEGORIES[$category])) {
            $where[] = 'i.category = :category';
            $params[':category'] = $category;
        }
        if (self::validDate($from)) {
            $where[] = 'i.created_at >= :from_date';
            $params[':from_date'] = $from . ' 00:00:00';
        }
        if (self::validDate($to)) {
            $where[] = 'i.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $params[':to_date'] = $to . ' 00:00:00';
        }
        return [$where, $params];
    }

    private static function appendHistory(
        int $issueId,
        ?string $oldStatus,
        string $newStatus,
        string $action,
        ?string $note,
        ?int $actorId
    ): void {
        Database::run(
            'INSERT INTO issue_report_history
                (issue_id, old_status, new_status, action, note, acted_by)
             VALUES (:issue_id, :old_status, :new_status, :action, :note, :actor)',
            [
                ':issue_id' => $issueId,
                ':old_status' => $oldStatus,
                ':new_status' => $newStatus,
                ':action' => $action,
                ':note' => $note,
                ':actor' => $actorId,
            ]
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
