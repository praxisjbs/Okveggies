<?php
/**
 * public/documents/invoice.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The invoice for an order: what it came to, what has been paid,
 * and what is still owed.
 *
 * Reached by the trail token emailed with the order, by the signed-in customer
 * who owns it, or by staff with payments.view. There is no plain "?order=12"
 * that any number can walk, because this page carries a name, a phone number,
 * an address and what someone paid. See OrderDocument for the access rules.
 *
 * Every amount is read from the order snapshot written at checkout, never from
 * today's prices, so reprinting an old invoice shows the figures the customer
 * was actually given. Money goes through Money, the order number is whatever
 * OrderNumber issued, and the mark is the single-ink mono-green lockup.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/documents/document.php';

$document = OrderDocument::load();

if (!$document) {
    // Says the same thing whether the order does not exist or is not yours, so
    // the page never confirms which.
    okv_document_open(['title' => 'Invoice', 'print' => false]);
    okv_document_letterhead([
        'kind'      => 'Invoice',
        'title'     => 'We could not open this invoice',
        'reference' => null,
        'issued_on' => date('j M Y'),
    ]);
    ?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p class="okv-doc-stamp">Not available</p>
      <p class="okv-doc-gap">
        This invoice link is not one we can open. It may have been mistyped, or it may belong to
        someone else. Use the link in your order email, or sign in and open the order from your account.
      </p>
      <p class="okv-doc-gap">Still stuck? Send us a message and we will get it to you.</p>
    </div>
    <?php
    okv_document_close();
    return;
}

$order   = $document['order'];
$paid    = (int) $order['amount_paid_subunit'];
$total   = (int) $order['order_total_subunit'];
$balance = Money::balance($total, $paid);

okv_document_open(['title' => 'Invoice ' . (string) $order['order_number'], 'print' => true]);
okv_document_letterhead([
    'kind'      => 'Invoice',
    'title'     => $balance > 0 ? 'What is owed on this order' : 'This order is fully paid',
    'reference' => okv_document_reference((string) $order['order_number']),
    'issued_on' => date('j M Y', strtotime((string) $order['created_at'])),
]);

okv_document_meta([
    'Billed to'     => OrderDocument::addressBlock($document['address']),
    'Delivery day'  => date('l jS F Y', strtotime((string) $order['preferred_delivery_date'])),
    'Order placed'  => date('j M Y', strtotime((string) $order['created_at'])),
]);

okv_document_lines(OrderDocument::lines($document['items']));
okv_document_totals(okv_document_totals_rows(
    (int) $order['subtotal_subunit'],
    0,
    $paid
));
?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <?php if ($balance > 0): ?>
        <p><strong><?= okv_e(Money::format($balance)) ?></strong> is still to pay on this order.</p>
        <?php if ((string) $order['payment_option'] === 'deposit'): ?>
          <p class="okv-doc-gap">
            You paid a deposit to confirm the order. The balance is settled on delivery.
          </p>
        <?php endif; ?>
      <?php else: ?>
        <p>Nothing further is owed on this order. Thank you.</p>
      <?php endif; ?>

      <p class="okv-doc-gap"><?= okv_e((string) ($order['delivery_fee_note'] ?? '')) ?></p>
    </div>
<?php
okv_document_close();
