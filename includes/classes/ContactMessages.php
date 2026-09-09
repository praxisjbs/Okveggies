<?php
/** Storefront contact submissions and their one-time form tokens. */
final class ContactMessages
{
    public const PER_PAGE = 25;
    public const STATUSES = ['new', 'handled'];
    public const NAME_MAX = 150;
    public const EMAIL_MAX = 254;
    public const PHONE_MAX = 30;
    public const SUBJECT_MAX = 200;
    public const MESSAGE_MAX = 5000;

    private const TOKEN_SESSION_KEY = 'okv_contact_submission_tokens';
    private const TOKEN_MAX_AGE = 7200;
    private const MINIMUM_FORM_SECONDS = 2;

    public static function newSubmissionToken(): string
    {
        $now = time();
        $tokens = is_array($_SESSION[self::TOKEN_SESSION_KEY] ?? null)
            ? $_SESSION[self::TOKEN_SESSION_KEY]
            : [];
        foreach ($tokens as $hash => $issuedAt) {
            if (!is_int($issuedAt) || $issuedAt < $now - self::TOKEN_MAX_AGE) {
                unset($tokens[$hash]);
            }
        }
        if (count($tokens) >= 20) {
            asort($tokens);
            $tokens = array_slice($tokens, -19, null, true);
        }
        $token = bin2hex(random_bytes(32));
        $tokens[hash('sha256', $token)] = $now;
        $_SESSION[self::TOKEN_SESSION_KEY] = $tokens;
        return $token;
    }

    /** Validate and normalise public fields without trusting browser constraints. */
    public static function validateFields(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $subject = trim((string) ($input['subject'] ?? ''));
        $message = trim(str_replace(["\r\n", "\r"], "\n", (string) ($input['message'] ?? '')));

        if ($name === '') {
            return self::failure('name_required', 'Enter your name.', 'name');
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return self::failure('name_too_long', 'Keep your name to 150 characters or fewer.', 'name');
        }
        if ($email !== '' && (mb_strlen($email) > self::EMAIL_MAX || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return self::failure('bad_email', 'Enter a valid email address.', 'email');
        }
        $phone = $phoneRaw === '' ? null : Phone::normalize($phoneRaw);
        if ($phoneRaw !== '' && $phone === null) {
            return self::failure('bad_phone', 'Enter a valid Nigerian phone number, for example 0803 000 0000.', 'phone');
        }
        if ($email === '' && $phone === null) {
            return self::failure('contact_required', 'Enter an email address or phone number so we can reply.', 'email');
        }
        if (mb_strlen($subject) > self::SUBJECT_MAX) {
            return self::failure('subject_too_long', 'Keep the subject to 200 characters or fewer.', 'subject');
        }
        if ($message === '') {
            return self::failure('message_required', 'Tell us how we can help.', 'message');
        }
        if (mb_strlen($message) > self::MESSAGE_MAX) {
            return self::failure('message_too_long', 'Keep your message to 5,000 characters or fewer.', 'message');
        }

        return [
            'ok' => true,
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone,
            'subject' => $subject !== '' ? $subject : null,
            'message' => $message,
        ];
    }

    /** Insert exactly one message. Notification delivery belongs to the caller. */
    public static function submit(array $input, ?int $customerId = null): array
    {
        $ip = self::clientIp();
        $ipBucket = 'contact:ip:' . hash('sha256', $ip ?? 'unknown');
        if (!RateLimiter::hit($ipBucket, 3, 15 * 60)) {
            return self::failure('rate_limited', 'Too many messages were sent from this connection. Wait 15 minutes and try again.');
        }

        $token = trim((string) ($input['submission_token'] ?? ''));
        $tokenHash = preg_match('/^[a-f0-9]{64}$/', $token) === 1 ? hash('sha256', $token) : '';
        $issuedAt = $tokenHash !== '' ? self::tokenIssuedAt($tokenHash) : null;
        if ($issuedAt === null) {
            return self::failure('invalid_submission', 'This form is no longer valid. Reload the page and try again.');
        }
        if (trim((string) ($input['website'] ?? '')) !== '') {
            return self::failure('spam_rejected', 'We could not accept that message. Reload the page and try again.');
        }
        $clean = self::validateFields($input);
        if (empty($clean['ok'])) {
            return $clean;
        }
        if (time() - $issuedAt < self::MINIMUM_FORM_SECONDS) {
            return self::failure('too_fast', 'Please check your message, then send it again.');
        }
        $identity = (string) ($clean['email'] ?? $clean['phone'] ?? '');
        $identityBucket = 'contact:id:' . hash('sha256', mb_strtolower($identity));
        if (!RateLimiter::hit($identityBucket, 10, 24 * 60 * 60)) {
            return self::failure('rate_limited', 'Too many messages were sent with these contact details. Try again tomorrow.');
        }

        $source = in_array((string) ($input['source'] ?? ''), ['support_widget', 'contact_page'], true)
            ? (string) $input['source']
            : 'storefront_contact_form';
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            Database::run(
                'INSERT INTO contact_messages
                    (name, email, phone, subject, message, source, status, ip_address, submission_token_hash)
                 VALUES (:name, :email, :phone, :subject, :message, :source, :status, :ip, :token)',
                [
                    ':name' => $clean['name'],
                    ':email' => $clean['email'],
                    ':phone' => $clean['phone'],
                    ':subject' => $clean['subject'],
                    ':message' => $clean['message'],
                    ':source' => $source,
                    ':status' => 'new',
                    ':ip' => $ip,
                    ':token' => $tokenHash,
                ]
            );
            $messageId = (int) $pdo->lastInsertId();
            Audit::record(
                'contact_messages.create',
                'contact_message',
                $messageId,
                null,
                ['source' => $source, 'customer_id' => $customerId],
                $customerId
            );
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((string) $e->getCode() === '23000') {
                self::consumeToken($tokenHash);
                return self::failure('duplicate', 'That message was already received.');
            }
            throw $e;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        self::consumeToken($tokenHash);
        return ['ok' => true, 'code' => 'submitted', 'message_id' => $messageId];
    }

    public static function clientIp(): ?string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        return is_string($raw) && filter_var($raw, FILTER_VALIDATE_IP) ? mb_substr($raw, 0, 45) : null;
    }

    public static function countForStaff(string $search = '', string $status = '', string $from = '', string $to = ''): int
    {
        [$where, $params] = self::staffWhere($search, $status, $from, $to);
        $row = Database::one(
            'SELECT COUNT(*) AS n FROM contact_messages m' . ($where ? ' WHERE ' . implode(' AND ', $where) : ''),
            $params
        );
        return (int) ($row['n'] ?? 0);
    }

    /** Newest-first staff list with integer-bound pagination. */
    public static function forStaff(string $search = '', string $status = '', string $from = '', string $to = '', int $page = 1): array
    {
        [$where, $params] = self::staffWhere($search, $status, $from, $to);
        $sql = 'SELECT m.id, m.name, m.email, m.phone, m.subject, m.status, m.created_at,
                       m.handled_at, TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS handled_by_name
                  FROM contact_messages m
             LEFT JOIN users u ON u.id = m.handled_by'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY m.created_at DESC, m.id DESC LIMIT :limit OFFSET :offset';
        $stmt = Database::getInstance()->getConnection()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', self::PER_PAGE, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (max(1, $page) - 1) * self::PER_PAGE, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function findForStaff(int $messageId): ?array
    {
        return Database::one(
            'SELECT m.*, TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS handled_by_name
               FROM contact_messages m
          LEFT JOIN users u ON u.id = m.handled_by
              WHERE m.id = :id',
            [':id' => $messageId]
        );
    }

    /** Append-only audit projection for one message, newest first. */
    public static function handlingHistory(int $messageId): array
    {
        $rows = Database::all(
            'SELECT a.action, a.old_values, a.new_values, a.created_at,
                    TRIM(CONCAT(COALESCE(u.first_name, \'\'), \' \', COALESCE(u.last_name, \'\'))) AS actor_name
               FROM audit_logs a
          LEFT JOIN users u ON u.id = a.actor_user_id
              WHERE a.entity_type = :type AND a.entity_id = :id
                AND a.action IN (:created, :note, :handled, :reopened)
           ORDER BY a.created_at DESC, a.id DESC',
            [
                ':type' => 'contact_message',
                ':id' => $messageId,
                ':created' => 'contact_messages.create',
                ':note' => 'contact_messages.note.update',
                ':handled' => 'contact_messages.handle',
                ':reopened' => 'contact_messages.reopen',
            ]
        );
        foreach ($rows as &$row) {
            $row['old'] = json_decode((string) ($row['old_values'] ?? ''), true) ?: [];
            $row['new'] = json_decode((string) ($row['new_values'] ?? ''), true) ?: [];
        }
        unset($row);
        return $rows;
    }

    public static function saveNote(int $messageId, string $note, string $expectedNote, int $actorId): array
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));
        if (mb_strlen($note) > 500) {
            return self::failure('note_too_long', 'Keep the internal note to 500 characters or fewer.', 'admin_note');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $current = Database::one('SELECT id, admin_note FROM contact_messages WHERE id = :id FOR UPDATE', [':id' => $messageId]);
            if (!$current) {
                $pdo->rollBack();
                return self::failure('not_found', 'That message could not be found.');
            }
            $oldNote = (string) ($current['admin_note'] ?? '');
            if ($oldNote !== $expectedNote) {
                $pdo->rollBack();
                return self::failure('stale', 'This message changed after the page loaded. Reload it before saving.');
            }
            if ($oldNote === $note) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'unchanged'];
            }
            Database::run(
                'UPDATE contact_messages SET admin_note = :note WHERE id = :id',
                [':note' => $note !== '' ? $note : null, ':id' => $messageId]
            );
            Audit::record(
                'contact_messages.note.update',
                'contact_message',
                $messageId,
                ['admin_note' => $oldNote],
                ['admin_note' => $note],
                $actorId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => 'note_saved'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    public static function handle(int $messageId, string $expectedStatus, int $actorId): array
    {
        return self::changeStatus($messageId, $expectedStatus, 'handled', $actorId);
    }

    public static function reopen(int $messageId, string $expectedStatus, int $actorId): array
    {
        return self::changeStatus($messageId, $expectedStatus, 'new', $actorId);
    }

    public static function validDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function changeStatus(int $messageId, string $expectedStatus, string $targetStatus, int $actorId): array
    {
        if (!in_array($expectedStatus, self::STATUSES, true) || !in_array($targetStatus, self::STATUSES, true)) {
            return self::failure('bad_status', 'Choose a valid message status.');
        }
        $pdo = Database::getInstance()->getConnection();
        $pdo->beginTransaction();
        try {
            $current = Database::one(
                'SELECT id, status, handled_by, handled_at FROM contact_messages WHERE id = :id FOR UPDATE',
                [':id' => $messageId]
            );
            if (!$current) {
                $pdo->rollBack();
                return self::failure('not_found', 'That message could not be found.');
            }
            $oldStatus = (string) $current['status'];
            if ($oldStatus === $targetStatus) {
                $pdo->commit();
                return ['ok' => true, 'code' => 'unchanged'];
            }
            if ($oldStatus !== $expectedStatus) {
                $pdo->rollBack();
                return self::failure('stale', 'This message changed after the page loaded. Reload it before changing the status.');
            }
            $handled = $targetStatus === 'handled';
            $handledAt = $handled ? date('Y-m-d H:i:s') : null;
            Database::run(
                'UPDATE contact_messages
                    SET status = :status, handled_by = :handler, handled_at = :handled_at
                  WHERE id = :id',
                [
                    ':status' => $targetStatus,
                    ':handler' => $handled ? $actorId : null,
                    ':handled_at' => $handledAt,
                    ':id' => $messageId,
                ]
            );
            Audit::record(
                $handled ? 'contact_messages.handle' : 'contact_messages.reopen',
                'contact_message',
                $messageId,
                ['status' => $oldStatus, 'handled_by' => $current['handled_by'], 'handled_at' => $current['handled_at']],
                ['status' => $targetStatus, 'handled_by' => $handled ? $actorId : null, 'handled_at' => $handledAt],
                $actorId
            );
            $pdo->commit();
            return ['ok' => true, 'code' => $handled ? 'handled' : 'reopened'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
    }

    private static function staffWhere(string $search, string $status, string $from, string $to): array
    {
        $where = [];
        $params = [];
        $search = mb_substr(trim($search), 0, 100);
        if ($search !== '') {
            $like = '%' . $search . '%';
            $parts = [
                'm.name LIKE :search_name', 'm.email LIKE :search_email', 'm.phone LIKE :search_phone',
                'm.subject LIKE :search_subject', 'm.message LIKE :search_message',
            ];
            foreach (['name', 'email', 'phone', 'subject', 'message'] as $field) {
                $params[':search_' . $field] = $like;
            }
            if (ctype_digit($search)) {
                $parts[] = 'm.id = :search_id';
                $params[':search_id'] = $search;
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (in_array($status, self::STATUSES, true)) {
            $where[] = 'm.status = :status';
            $params[':status'] = $status;
        }
        if (self::validDate($from)) {
            $where[] = 'm.created_at >= :date_from';
            $params[':date_from'] = $from . ' 00:00:00';
        }
        if (self::validDate($to)) {
            $where[] = 'm.created_at < DATE_ADD(:date_to, INTERVAL 1 DAY)';
            $params[':date_to'] = $to . ' 00:00:00';
        }
        return [$where, $params];
    }

    private static function tokenIssuedAt(string $hash): ?int
    {
        $tokens = $_SESSION[self::TOKEN_SESSION_KEY] ?? [];
        return is_array($tokens) && isset($tokens[$hash]) && is_int($tokens[$hash])
            ? $tokens[$hash]
            : null;
    }

    private static function consumeToken(string $hash): void
    {
        if (isset($_SESSION[self::TOKEN_SESSION_KEY]) && is_array($_SESSION[self::TOKEN_SESSION_KEY])) {
            unset($_SESSION[self::TOKEN_SESSION_KEY][$hash]);
        }
    }

    private static function failure(string $code, string $message, ?string $field = null): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, 'field' => $field];
    }
}
