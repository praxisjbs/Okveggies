<?php
/**
 * includes/classes/Paystack.php
 * -----------------------------------------------------------------------------
 * OK Veggies. Thin Paystack client. Amounts are in subunits (kobo), which is
 * exactly what Paystack expects, so no conversion is needed. All channels are
 * enabled. The webhook signature check is the security boundary for incoming
 * events and must pass before any event is trusted.
 * -----------------------------------------------------------------------------
 */

final class Paystack
{
    private const BASE = 'https://api.paystack.co';

    private static function secret(): string
    {
        return (string) env('PAYSTACK_SECRET_KEY', '');
    }

    public static function publicKey(): string
    {
        return (string) env('PAYSTACK_PUBLIC_KEY', '');
    }

    /**
     * Initialise a transaction. $amountSubunit is kobo. Returns the decoded
     * Paystack response (authorization_url, access_code, reference).
     */
    public static function initializeTransaction(string $email, int $amountSubunit, array $extra = []): array
    {
        $payload = array_merge([
            'email'    => $email,
            'amount'   => $amountSubunit,
            'currency' => Money::CODE,
        ], $extra);
        return self::request('POST', '/transaction/initialize', $payload);
    }

    /** Verify a transaction by its reference. */
    public static function verifyTransaction(string $reference): array
    {
        return self::request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    /**
     * Verify an incoming webhook. Paystack signs the raw body with HMAC SHA512
     * using the secret key and sends it in the X-Paystack-Signature header.
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

    private static function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $headers = [
            'Authorization: Bearer ' . self::secret(),
            'Cache-Control: no-cache',
        ];
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($body !== null) {
            $json = json_encode($body);
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log('Paystack request failed: ' . $err);
            return ['status' => false, 'message' => 'Payment provider is unreachable right now.'];
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log('Paystack bad response (' . $httpCode . '): ' . substr((string) $response, 0, 500));
            return ['status' => false, 'message' => 'Unexpected response from the payment provider.'];
        }
        return $decoded;
    }
}
