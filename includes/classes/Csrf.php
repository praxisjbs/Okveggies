<?php
/**
 * includes/classes/Csrf.php
 * -----------------------------------------------------------------------------
 * OK Veggies. CSRF protection. A token is minted once per session. Every state
 * changing request (POST, PUT, DELETE) must present it, either as the hidden
 * field name okv_csrf or the X-CSRF-Token header. Read requests never need it.
 * -----------------------------------------------------------------------------
 */

final class Csrf
{
    private const SESSION_KEY = 'okv_csrf_token';
    private const FIELD_NAME  = 'okv_csrf';
    private const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';

    public static function init(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (empty($_SESSION[self::SESSION_KEY])) {
            $len = (int) (defined('CSRF_TOKEN_LENGTH') ? CSRF_TOKEN_LENGTH : 32);
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(max(16, $len)));
        }
    }

    public static function token(): string
    {
        self::init();
        return $_SESSION[self::SESSION_KEY] ?? '';
    }

    /** Hidden input for a form. */
    public static function field(): string
    {
        $t = htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="' . self::FIELD_NAME . '" value="' . $t . '">';
    }

    public static function fieldName(): string { return self::FIELD_NAME; }

    /** True if the submitted token matches the session token. */
    public static function validate(): bool
    {
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        if ($expected === '') {
            return false;
        }
        $given = $_POST[self::FIELD_NAME] ?? $_SERVER[self::HEADER_NAME] ?? '';
        if (!is_string($given) || $given === '') {
            // Some clients send JSON bodies; allow a token in the decoded body too.
            $raw = file_get_contents('php://input');
            if ($raw) {
                $body = json_decode($raw, true);
                if (is_array($body) && !empty($body[self::FIELD_NAME])) {
                    $given = (string) $body[self::FIELD_NAME];
                }
            }
        }
        return is_string($given) && $given !== '' && hash_equals($expected, $given);
    }

    /**
     * Enforce a valid token or stop the request. For an API path it returns a
     * 419 JSON body; for a page it redirects to the storefront with a notice.
     */
    public static function requireValid(): void
    {
        if (self::validate()) {
            return;
        }
        $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false
              || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
        if ($isApi) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'code' => 'csrf_expired', 'message' => 'Your session expired. Reload the page and try again.']);
        } else {
            http_response_code(419);
            header('Location: /?notice=csrf_expired');
        }
        exit;
    }
}
