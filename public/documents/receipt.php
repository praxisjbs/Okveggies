<?php
/**
 * public/documents/receipt.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The receipt for an order: what was paid, when, and how.
 *
 * Same access rules as the invoice (OrderDocument): the trail token, the
 * signed-in owner, or staff with payments.view. Never a plain order id.
 *
 * Where the invoice says what is owed, this says what has actually arrived, so
 * it lists each payment against the order rather than the order total, and it
 * shows a refund where one has been sent. Amounts come from the payment rows,
 * which are the record of what really moved.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/documents/document.php';

$document = OrderDocument::load();

if (!$document) {
    okv_document_open(['title' => 'Receipt', 'print' => false]);
    okv_document_letterhead([
        'kind'      => 'Receipt',
        'title'     => 'We could not open this receipt',
        'reference' => null,
        'issued_on' => date('j M Y'),
    ]);
    ?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p class="okv-doc-stamp">Not available</p>
      <p class="okv-doc-gap">
        This receipt link is not one we can open. It may have been mistyped, or it may belong to
        someone else. Use the link in your order email, or sign in and open the order from your account.
      </p>
    </div>
    <?php
    okv_document_close();
    return;
}

$order    = $document['order'];
$paid     = (int) $order['amount_paid_subunit'];
$refunded = (int) $document['refunded_subunit'];

if ($paid < 1) {
    okv_document_open(['title' => 'Receipt ' . (string) $order['order_number'], 'print' => false]);
    okv_document_letterhead([
        'kind'      => 'Receipt',
        'title'     => 'Nothing has been paid on this order yet',
        'reference' => okv_document_reference((string) $order['order_number']),
        'issued_on' => date('j M Y'),
    ]);
    ?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p>A receipt is raised once money has arrived. Nothing has been paid on this order so far.</p>
      <p class="okv-doc-gap">The invoice shows what is owed.</p>
    </div>
    <?php
    okv_document_close();
    return;
}

/** Each payment that actually holds money, as a document line. */
$lines = [];
foreach ($document['payments'] as $payment) {
    $held = (int) $payment['paid_amount_subunit'];
    if ($held < 1) {
        continue;
    }
    $lines[] = [
        'name'     => ucfirst(str_replace('_', ' ', (string) $payment['payment_type'])),
        'unit'     => (string) $payment['provider'] === 'manual' ? 'recorded by us' : 'through Paystack',
        'quantity' => $payment['confirmed_at'] ? date('j M Y', strtotime((string) $payment['confirmed_at'])) : '',
        'amount'   => okv_document_money($held),
    ];
}

okv_document_open(['title' => 'Receipt ' . (string) $order['order_number'], 'print' => true]);
okv_document_letterhead([
    'kind'      => 'Receipt',
    'title'     => 'What has been paid on this order',
    'reference' => okv_document_reference((string) $order['order_number']),
    'issued_on' => date('j M Y'),
]);

okv_document_meta([
    'Received from' => OrderDocument::addressBlock($document['address']),
    'Delivery day'  => date('l jS F Y', strtotime((string) $order['preferred_delivery_date'])),
    'Order placed'  => date('j M Y', strtotime((string) $order['created_at'])),
]);

okv_document_lines($lines);

$totals = [
    ['label' => 'Order total', 'amount' => okv_document_money((int) $order['order_total_subunit']), 'is_total' => false],
    ['label' => 'Paid',        'amount' => okv_document_money($paid), 'is_total' => false],
];
if ($refunded > 0) {
    $totals[] = ['label' => 'Refunded', 'amount' => okv_document_money($refunded), 'is_total' => false];
    $totals[] = ['label' => 'Net paid', 'amount' => okv_document_money($paid - $refunded), 'is_total' => true];
} else {
    $totals[] = ['label' => 'Balance due', 'amount' => okv_document_money(Money::balance((int) $order['order_total_subunit'], $paid)), 'is_total' => true];
}
okv_document_totals($totals);
?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <?php if ($refunded > 0): ?>
        <p><?= okv_e(Money::format($refunded)) ?> has been refunded to you on this order.</p>
      <?php endif; ?>
      <p class="okv-doc-gap">Thank you for shopping with OK Veggies.</p>
    </div>
<?php
okv_document_close();
