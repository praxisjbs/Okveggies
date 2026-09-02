<?php
/**
 * api/v1/paystack_webhook.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The signed Paystack event inbox. See docs/PRD.md Section 11.
 *
 * Order of operations matters here and is not negotiable:
 *
 *   1. Read the RAW body first, before anything parses it. The signature is
 *      over the bytes Paystack sent. Paystack's own Node sample hashes
 *      JSON.stringify(req.body), the re-serialised body, which passes until key
 *      order or unicode escaping makes it fail. We never do that.
 *   2. Verify the signature. This is the security boundary. A bad signature is
 *      answered 401, not 200, so that a genuine event sent while our secret is
 *      misconfigured keeps being retried for 72 hours instead of being silently
 *      swallowed.
 *   3. Record the event in the idempotent inbox. Paystack sends no idempotency
 *      header and retries the same event every 3 minutes for 4 tries then
 *      hourly for 72 hours, so the same event WILL arrive many times. Two
 *      unique keys catch it: the dedupe key and the payload hash.
 *   4. Return 200 and flush, before any slow work. Paystack times an attempt
 *      out at 30 seconds and their guidance is to acknowledge first.
 *   5. Only then verify against Paystack and apply. The payload is never
 *      trusted for money: we re-verify by reference and credit from that.
 *
 * The source IP is recorded and flagged when it is not one of Paystack's, but
 * it never refuses a correctly signed event. The signature is the real
 * boundary, and a hard IP block is how a live integration dies the day the
 * sender moves.
 * -----------------------------------------------------------------------------
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// 1. Raw body, unparsed.
$rawBody   = file_get_contents('php://input');
$signature = (string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '');
$sourceIp  = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

if (!okv_is_post()) {
    okv_json(['status' => 'error', 'message' => 'Use POST for this endpoint.'], 405);
}
if ($rawBody === false || $rawBody === '' || strlen($rawBody) > 1048576) {
    error_log('paystack_webhook: empty or oversized body from ' . $sourceIp);
    okv_json(['status' => 'error', 'message' => 'Bad request.'], 400);
}

// 2. Signature. Nothing below this line runs for an unsigned request.
if (!Paystack::verifyWebhookSignature($rawBody, $signature)) {
    error_log('paystack_webhook: signature rejected from ' . $sourceIp);
    okv_json(['status' => 'error', 'message' => 'Signature rejected.'], 401);
}

$event = json_decode($rawBody, true);
if (!is_array($event) || !isset($event['event'])) {
    error_log('paystack_webhook: signed but unparseable body from ' . $sourceIp);
    okv_json(['status' => 'error', 'message' => 'Bad request.'], 400);
}

$eventType = substr((string) $event['event'], 0, 100);
$data      = is_array($event['data'] ?? null) ? $event['data'] : [];
$reference = substr((string) ($data['reference'] ?? ''), 0, 120);
$resourceId = ($data['id'] ?? null) !== null ? (int) $data['id'] : null;
$dedupeKey = substr(Payments::deduplicationKey($eventType, $data), 0, 255);
$payloadHash = hash('sha256', $rawBody);
$ipKnown   = Paystack::isKnownWebhookIp($sourceIp);

if (!$ipKnown) {
    // Recorded and flagged, never refused. The signature already passed.
    error_log('paystack_webhook: valid signature from unlisted ip ' . $sourceIp . ' for ' . $eventType);
}

// 3. The idempotent inbox. rowCount is 1 for a fresh insert and 2 when MySQL
//    applied the ON DUPLICATE KEY UPDATE, which is how a repeat is detected.
$eventId  = null;
$isRepeat = false;
try {
    $affected = Database::run(
        'INSERT INTO payment_webhook_events
            (event_type, provider_resource_id, reference, deduplication_key, signature,
             signature_valid, payload_hash, payload, processing_status, attempt_count, error_message)
         VALUES (:type, :resource, :ref, :dedupe, :signature,
                 1, :hash, :payload, \'received\', 1, :note)
         ON DUPLICATE KEY UPDATE
            duplicate_count  = duplicate_count + 1,
            last_received_at = NOW()',
        [
            ':type'      => $eventType,
            ':resource'  => $resourceId,
            ':ref'       => $reference !== '' ? $reference : null,
            ':dedupe'    => $dedupeKey,
            ':signature' => substr($signature, 0, 255),
            ':hash'      => $payloadHash,
            ':payload'   => $rawBody,
            ':note'      => $ipKnown ? null : 'Received from unlisted source ip ' . $sourceIp,
        ]
    );
    $isRepeat = $affected !== 1;
    $row = Database::one(
        'SELECT id, processing_status FROM payment_webhook_events WHERE deduplication_key = :k',
        [':k' => $dedupeKey]
    );
    $eventId = $row ? (int) $row['id'] : null;
    if ($row && $row['processing_status'] === 'processed') {
        $isRepeat = true;
    }
} catch (Throwable $e) {
    error_log('paystack_webhook: inbox write failed: ' . $e->getMessage());
    // A 500 keeps Paystack retrying, which is what we want when we could not
    // even record the event.
    okv_json(['status' => 'error', 'message' => 'Could not record the event.'], 500);
}

// 4. Acknowledge now, before any call to Paystack. Their guidance is explicit:
//    acknowledge first, then do the work, or the attempt times out at 30s.
okv_webhook_acknowledge(['status' => 'ok', 'received' => $eventType]);

// 5. Everything below runs after the response has gone.
if ($isRepeat) {
    return;
}

try {
    if ($eventType === 'charge.success' && $reference !== '') {
        $verified = Paystack::verifyTransaction($reference);
        if (!$verified['ok']) {
            // Unknown or refused. Leave the row for the sweep to retry rather
            // than recording an outcome we are not sure of.
            Database::run(
                'UPDATE payment_webhook_events
                    SET processing_status = :status, error_message = :message, next_retry_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
                  WHERE id = :id',
                [
                    ':status'  => $verified['reason'] === 'network' ? 'retry' : 'failed',
                    ':message' => substr($verified['message'], 0, 1000),
                    ':id'      => $eventId,
                ]
            );
            return;
        }

        $result = Payments::applyVerifiedCharge($reference, $verified['data'], 'webhook', $eventId);
        $status = $result['ok'] ? 'processed' : ($result['code'] === 'unmatched' ? 'unmatched' : 'processed');
        Database::run(
            'UPDATE payment_webhook_events
                SET processing_status = :status, processed_at = NOW(), payment_transaction_id = (
                        SELECT id FROM payment_transactions WHERE reference = :ref
                    ), error_message = :message
              WHERE id = :id',
            [
                ':status'  => $status,
                ':ref'     => $reference,
                ':message' => $result['ok'] ? null : substr((string) $result['code'] . ': ' . (string) $result['message'], 0, 1000),
                ':id'      => $eventId,
            ]
        );
        return;
    }

    // A refund moving through Paystack's four states. The payload identifies
    // which refund and nothing more: the state written is read back from
    // Paystack, so this holds whatever shape their refund payload takes.
    if (Refunds::statusFromEvent($eventType) !== null) {
        $result = Refunds::applyWebhook($eventType, $data, $eventId);
        Database::run(
            'UPDATE payment_webhook_events
                SET processing_status = :status, processed_at = NOW(), error_message = :message
              WHERE id = :id',
            [
                ':status'  => $result['code'] === 'unmatched' ? 'unmatched' : 'processed',
                ':message' => $result['ok'] ? null : substr((string) $result['code'] . ': ' . (string) $result['message'], 0, 1000),
                ':id'      => $eventId,
            ]
        );
        return;
    }

    // Every other event is recorded for the milestones that will read it.
    // Disputes are modelled in the schema and surfaced in a later milestone.
    Database::run(
        'UPDATE payment_webhook_events SET processing_status = \'ignored\', processed_at = NOW() WHERE id = :id',
        [':id' => $eventId]
    );
} catch (Throwable $e) {
    error_log('paystack_webhook: processing ' . $eventType . ' failed: ' . $e->getMessage());
    try {
        Database::run(
            'UPDATE payment_webhook_events
                SET processing_status = \'retry\', error_message = :message, next_retry_at = DATE_ADD(NOW(), INTERVAL 5 MINUTE)
              WHERE id = :id',
            [':message' => substr($e->getMessage(), 0, 1000), ':id' => $eventId]
        );
    } catch (Throwable $ignored) {
        error_log('paystack_webhook: could not mark event for retry.');
    }
}
