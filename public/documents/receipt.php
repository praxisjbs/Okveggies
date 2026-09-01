<?php
/**
 * public/documents/receipt.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The receipt for money already taken on an order.
 *
 * Status: the branded frame is built and the letterhead is on brand. The rows
 * are not, because nothing takes a payment yet: the basket lands in M4 and
 * payments in M5. What is here is what M5 fills in, not a mock of it.
 *
 * When M5 builds the real receipt it must add, before anything is printed:
 *   1. The access check. A receipt is reached by the token link emailed with
 *      the payment, or by a signed-in customer who owns the order, or by staff
 *      with payments.view.
 *   2. The payment read: what was taken, when, by which channel, and whether it
 *      was the deposit or the balance, all from the payment record rather than
 *      recomputed, so a reprint always says what actually happened.
 *   3. dompdf for the PDF, rendering this same markup.
 *
 * Money goes through Money, the order number is whatever OrderNumber issued and
 * the order stored, and the mark is the single-ink mono-green lockup (bible
 * 3.7a). See docs/PRD.md Section 11.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/documents/document.php';

okv_document_open(['title' => 'Receipt', 'print' => false]);
okv_document_letterhead([
    'kind'      => 'Receipt',
    'title'     => 'What has been paid',
    'reference' => null,
    'issued_on' => date('j M Y'),
]);
?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p class="okv-doc-stamp">Not available yet</p>
      <p class="okv-doc-gap">
        A receipt is issued against a payment. Taking payment is still being built, so there is
        nothing to receipt today. The letterhead, the layout and the print rules above are the ones
        every receipt will carry.
      </p>
      <p class="okv-doc-gap">
        Paid us for something already? Ask and we will send you the receipt.
      </p>
    </div>
<?php
okv_document_close();
