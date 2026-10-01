<?php
/**
 * public/documents/credit_note.php
 * -----------------------------------------------------------------------------
 * OK Veggies. A credit note: the paper for one credit in a customer's wallet.
 *
 * Who may open it: the signed-in customer it was issued to, or staff holding
 * wallet.view. There is deliberately no token link and no plain guessable path
 * for anyone else: a credit note carries a name, an order and an amount.
 *
 * Every figure comes from the credit note row written when the credit was made,
 * so reprinting it next year shows exactly what was issued.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/documents/document.php';

$note = Wallet::creditNote((int) okv_input('id', 0));
$mayOpen = $note !== null && (
    (Customer::isLoggedIn() && (int) Customer::id() === (int) $note['user_id'])
    || (Rbac::isStaff() && Rbac::can('wallet.view'))
);

if (!$mayOpen) {
    okv_document_open(['title' => 'Credit note', 'print' => false]);
    okv_document_letterhead([
        'kind'      => 'Credit note',
        'title'     => 'We could not open this credit note',
        'reference' => null,
        'issued_on' => date('j M Y'),
    ]);
    ?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p class="okv-doc-stamp">Not available</p>
      <p class="okv-doc-gap">
        This credit note is not one we can open. It may have been mistyped, or it may belong to
        someone else. Sign in and open it from your wallet.
      </p>
    </div>
    <?php
    okv_document_close();
    return;
}

$reason = Wallet::reasonLabel((string) $note['reason_code']);
$who    = trim((string) ($note['business_name'] ?? '')) !== ''
    ? trim((string) $note['business_name'])
    : trim((string) $note['customer_name']);
$amount = (int) $note['amount_subunit'];

okv_document_open(['title' => 'Credit note ' . (string) $note['credit_note_number'], 'print' => true]);
okv_document_letterhead([
    'kind'      => 'Credit note',
    'title'     => 'Credit added to your wallet',
    'reference' => (string) $note['credit_note_number'],
    'reference_label' => 'Credit note number',
    'issued_on' => date('j M Y', strtotime((string) $note['created_at'])),
]);

okv_document_meta([
    'Issued to' => $who,
    'Order'     => (string) ($note['order_number'] ?? '') !== '' ? (string) $note['order_number'] : 'Not tied to an order',
    'Reason'    => $reason,
]);

okv_document_lines([[
    // The reason is already in the block above. The line says what it was for.
    'name'     => (string) ($note['reason_text'] ?? '') !== '' ? (string) $note['reason_text'] : $reason,
    'unit'     => '',
    'quantity' => '',
    'amount'   => okv_document_money($amount),
]]);

okv_document_totals([
    ['label' => 'Credited to your wallet', 'amount' => okv_document_money($amount), 'is_total' => true],
]);
?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p>This credit is in your OK Veggies wallet. It is offered first the next time you pay, and you can use part of it or all of it.</p>
      <p class="okv-doc-gap">Thank you for shopping with OK Veggies.</p>
    </div>
<?php
okv_document_close();
