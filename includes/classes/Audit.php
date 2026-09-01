<?php
/**
 * includes/classes/Audit.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Writes audit_logs, the append-only record of who changed a
 * sensitive value, when, and what it was before.
 *
 * The table shipped in migration 001 and sat empty until the Settings screen was
 * built, so this class is its first writer and sets the pattern every later
 * module follows: one row per thing that actually changed, old and new values as
 * JSON, and the write inside the caller's transaction. If the audit row cannot
 * be written the caller rolls back, because a settings change nobody can trace
 * is worse than a save that failed and said so.
 *
 * Nothing here ever deletes or updates a row. History is append-only (CLAUDE.md).
 * -----------------------------------------------------------------------------
 */

final class Audit
{
    /** Longest user agent we keep. The column is VARCHAR(500). */
    private const USER_AGENT_MAX = 500;

    /**
     * Record one change. Call it inside the transaction that makes the change,
     * so the record and the change land together or not at all.
     *
     * @param string     $action     Dot-notation verb, for example 'settings.update'.
     * @param string     $entityType The table or domain object, for example 'site_settings'.
     * @param int|null   $entityId   The row it happened to, when there is one.
     * @param array|null $old        Values before, keyed the way the entity is keyed.
     * @param array|null $new        Values after.
     * @param int|null   $actorId    Who did it. Defaults to the signed-in user.
     */
    public static function record(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $old = null,
        ?array $new = null,
        ?int $actorId = null
    ): void {
        Database::run(
            'INSERT INTO audit_logs
                (actor_user_id, action, entity_type, entity_id, old_values, new_values, ip_address, user_agent)
             VALUES (:actor, :action, :entity_type, :entity_id, :old, :new, :ip, :ua)',
            [
                ':actor'       => $actorId ?? Rbac::userId(),
                ':action'      => $action,
                ':entity_type' => $entityType,
                ':entity_id'   => $entityId,
                ':old'         => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
                ':new'         => $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
                ':ip'          => self::ip(),
                ':ua'          => self::userAgent(),
            ]
        );
    }

    /**
     * The most recent changes to one kind of thing, newest first, with the name
     * of whoever made each one. Read-only, for the "what changed" panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(string $entityType, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        return Database::all(
            'SELECT a.id, a.action, a.entity_id, a.old_values, a.new_values, a.created_at,
                    TRIM(CONCAT(u.first_name, \' \', u.last_name)) AS actor_name
               FROM audit_logs a
          LEFT JOIN users u ON u.id = a.actor_user_id
              WHERE a.entity_type = :entity_type
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT ' . $limit,
            [':entity_type' => $entityType]
        );
    }

    /**
     * The caller's IP, as far as we can honestly tell. The column takes an IPv6
     * address, and an unparseable value is stored as null rather than as noise.
     */
    private static function ip(): ?string
    {
        $raw = $_SERVER['REMOTE_ADDR'] ?? '';
        return (is_string($raw) && filter_var($raw, FILTER_VALIDATE_IP)) ? $raw : null;
    }

    private static function userAgent(): ?string
    {
        $raw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return mb_substr($raw, 0, self::USER_AGENT_MAX);
    }
}
