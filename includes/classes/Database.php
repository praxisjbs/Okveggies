<?php
/**
 * includes/classes/Database.php
 * -----------------------------------------------------------------------------
 * OK Veggies. PDO singleton. Reads the DB_* constants defined in
 * includes/config/db.php. One connection per request, created lazily.
 * Prepared statements only, everywhere in the app. Never build SQL by
 * interpolation.
 * -----------------------------------------------------------------------------
 */

final class Database
{
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $port = defined('DB_PORT') ? (int) DB_PORT : 3306;
        $dsn  = 'mysql:host=' . DB_HOST
              . ($port ? ';port=' . $port : '')
              . ';dbname='  . DB_NAME
              . ';charset=' . DB_CHARSET;

        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /** Shortcut: prepared SELECT returning all rows. */
    public static function all(string $sql, array $params = []): array
    {
        $stmt = self::getInstance()->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Shortcut: prepared SELECT returning one row or null. */
    public static function one(string $sql, array $params = []): ?array
    {
        $stmt = self::getInstance()->getConnection()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Shortcut: prepared write, returns affected row count. */
    public static function run(string $sql, array $params = []): int
    {
        $stmt = self::getInstance()->getConnection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    private function __clone() {}
    public function __wakeup() { throw new RuntimeException('Cannot unserialize a singleton.'); }
}
