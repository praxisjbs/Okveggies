<?php
/**
 * includes/classes/Paystack.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Paystack client. Amounts are in subunits (kobo), which is exactly
 * what Paystack expects, so no conversion is needed.
 *
 * Every call returns the same shape, and the shape is the point:
 *
 *   ['ok' => true,  'data' => [...], 'http_code' => 200]
 *   ['ok' => false, 'reason' => 'network'|'http'|'malformed'|'api',
 *    'retryable' => bool, 'message' => '...', 'http_code' => int, 'data' => []]
 *
 * A declined payment and an unreachable gateway are not the same event and must
 * never collapse into one false. 'network' means we do not know what happened
 * and the caller must not treat the payment as failed; 'api' means Paystack
 * answered and said no. The old client returned a bare false for both, which
 * would have read a timeout as a refusal.
 *
 * The webhook signature check is the security boundary for incoming events and
 * must pass before any event is trusted. It hashes the RAW request body. Note
 * that Paystack's own Node sample hashes JSON.stringify(req.body), which is the
 * re-serialised body, not the bytes they signed. That works until it does not,
 * on key order or unicode escaping. Always pass php://input here, unparsed.
 * -----------------------------------------------------------------------------
 */

final class Paystack
{
    private const BASE = 'https://api.paystack.co';

    /** Connect and total timeouts, in seconds. */
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT   = 30;

    /** How many times a read may be retried after a network failure. */
    private const READ_RETRIES = 2;

    /**
     * Paystack reference charset, from the API reference: "Only -, . = and
     * alphanumeric characters allowed." Anything else is rejected before it
     * reaches the wire, because a rejected reference at Paystack means an
     * orphaned local row.
     */
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9.=-]{1,100}$/';

    private static function secret(): string
    {
        return (string) env('PAYSTACK_SECRET_KEY', '');
    }

    public static function publicKey(): string
    {
        return (string) env('PAYSTACK_PUBLIC_KEY', '');
    }

    /** True when this integration is pointed at Paystack's test domain. */
    public static function isTestMode(): bool
    {
        return str_starts_with(self::secret(), 'sk_test_');
    }

    /** The domain string Paystack stamps on a transaction: 'test' or 'live'. */
    public static function domain(): string
    {
        return self::isTestMode() ? 'test' : 'live';
    }

    /** Whether a reference is legal for Paystack. */
    public static function isValidReference(string $reference): bool
    {
        return (bool) preg_match(self::REFERENCE_PATTERN, $reference);
    }

    /**
     * The channels to offer, from Payment Settings. An empty list means send no
     * channels parameter at all and let the Paystack dashboard decide, which is
     * the default and keeps us from going stale as Paystack adds channels.
     */
    public static function channels(): array
    {
        $configured = trim(Settings::str('payment_channels', ''));
        if ($configured === '') {
            return [];
        }
        $channels = array_filter(array_map('trim', explode(',', $configured)));
        return array_values(array_intersect($channels, self::SUPPORTED_CHANNELS));
    }

    /** The channel enum, from the Paystack OpenAPI specification. */
    public const SUPPORTED_CHANNELS = [
        'apple_pay', 'bank', 'bank_transfer', 'capitec_pay', 'card',
        'eft', 'mobile_money', 'payattitude', 'qr', 'ussd',
    ];

    /**
     * Initialise a transaction. $amountSubunit is kobo. The reference is ours,
     * minted before the call, so the local row always exists before Paystack
     * hears of it.
     */
    public static function initializeTransaction(
        string $email,
        int $amountSubunit,
        string $reference,
        string $callbackUrl,
        array $metadata = []
    ): array {
        if (!self::isValidReference($reference)) {
            return self::failure('api', 'That payment reference is not valid.', 0, false);
        }
        if ($amountSubunit < 1) {
            return self::failure('api', 'A payment amount must be greater than zero.', 0, false);
        }

        $payload = [
            'email'     => $email,
            'amount'    => $amountSubunit,
            'currency'  => Money::CODE,
            'reference' => $reference,
        ];
        if ($callbackUrl !== '') {
            $payload['callback_url'] = $callbackUrl;
        }
        $channels = self::channels();
        if ($channels) {
            $payload['channels'] = $channels;
        }
        if ($metadata) {
            // The API reference describes metadata as a stringified JSON object,
            // while the OpenAPI spec types it as an object. A JSON string
            // satisfies both readings.
            $payload['metadata'] = json_encode($metadata);
        }

        // Never retried. An initialise that reached Paystack has spent its
        // reference, so a blind retry would be refused as a duplicate anyway.
        return self::request('POST', '/transaction/initialize', $payload, 0);
    }

    /** Verify a transaction by its reference. Safe to retry: it is a read. */
    public static function verifyTransaction(string $reference): array
    {
        if (!self::isValidReference($reference)) {
            return self::failure('api', 'That payment reference is not valid.', 0, false);
        }
        return self::request('GET', '/transaction/verify/' . rawurlencode($reference), null, self::READ_RETRIES);
    }

    /**
     * Verify an incoming webhook. Paystack signs the raw body with HMAC SHA512
     * using the secret key and sends it in the X-Paystack-Signature header.
     * $rawBody must be the unparsed request body.
     */
    public static function verifyWebhookSignature(string $rawBody, string $signature): bool
    {
        $secret = self::secret();
        if ($secret === '' || $signature === '') {
            return false;
        }
        $computed = hash_hmac('sha512', $rawBody, $secret);
        return hash_equals($computed, $signature);
    }

    /**
     * The IP addresses Paystack sends webhooks from, for both test and live.
     * Recorded and flagged, never used to refuse a correctly signed event: the
     * signature is the real boundary, and a hard IP block is how a live
     * integration dies the day the sender moves.
     */
    public const WEBHOOK_IPS = ['52.31.139.75', '52.49.173.169', '52.214.14.220'];

    public static function isKnownWebhookIp(string $ip): bool
    {
        return in_array($ip, self::WEBHOOK_IPS, true);
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    private static function request(string $method, string $path, ?array $body, int $retries): array
    {
        $attempt = 0;
        while (true) {
            $result = self::send($method, $path, $body);
            $canRetry = $result['ok'] === false
                && !empty($result['retryable'])
                && $attempt < $retries;
            if (!$canRetry) {
                return $result;
            }
            // 200ms, then 400ms. Short: a customer may be waiting on this.
            usleep((int) (200000 * (2 ** $attempt)));
            $attempt++;
        }
    }

    private static function send(string $method, string $path, ?array $body): array
    {
        $ch = curl_init(self::BASE . $path);
        if ($ch === false) {
            return self::failure('network', 'Payment provider is unreachable right now.', 0, true);
        }

        $headers = [
            'Authorization: Bearer ' . self::secret(),
            'Cache-Control: no-cache',
            'Accept: application/json',
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TOTAL_TIMEOUT);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // We do not know whether Paystack acted. Retryable, and the caller
            // must not record a failure from this.
            error_log('Paystack transport failure on ' . $method . ' ' . $path . ': ' . $err);
            return self::failure('network', 'Payment provider is unreachable right now.', $httpCode, true);
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            error_log('Paystack malformed response (' . $httpCode . ') on ' . $path . ': ' . substr((string) $response, 0, 500));
            return self::failure('malformed', 'Unexpected response from the payment provider.', $httpCode, $httpCode >= 500);
        }

        $message = isset($decoded['message']) && is_string($decoded['message'])
            ? $decoded['message']
            : 'The payment provider refused that request.';

        if ($httpCode >= 500) {
            error_log('Paystack server error (' . $httpCode . ') on ' . $path . ': ' . $message);
            return self::failure('http', 'Payment provider is unreachable right now.', $httpCode, true);
        }
        if ($httpCode < 200 || $httpCode >= 300 || ($decoded['status'] ?? false) !== true) {
            // Paystack answered and said no. Never retried, never a network
            // unknown: this is a real, final answer.
            return self::failure('api', $message, $httpCode, false) + ['data' => (array) ($decoded['data'] ?? [])];
        }

        return [
            'ok'        => true,
            'data'      => (array) ($decoded['data'] ?? []),
            'message'   => $message,
            'http_code' => $httpCode,
        ];
    }

    private static function failure(string $reason, string $message, int $httpCode, bool $retryable): array
    {
        return [
            'ok'        => false,
            'reason'    => $reason,
            'retryable' => $retryable,
            'message'   => $message,
            'http_code' => $httpCode,
            'data'      => [],
        ];
    }
}
