<?php
/**
 * public/documents/invoice.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The invoice a customer or a business is sent for an order.
 *
 * Status: the branded frame is built and the letterhead is on brand. The rows
 * are not, because nothing creates an order yet: the basket lands in M4 and
 * payments in M5. What is here is what M5 fills in, not a mock of it.
 *
 * When M5 builds the real invoice it must add, before anything is printed:
 *   1. The access check. An invoice is reached by the token link emailed with
 *      the order, or by a signed-in customer who owns the order, or by staff
 *      with payments.view. No plain "?order=12" that any number can walk.
 *   2. The order read, with every amount taken from the order snapshot rather
 *      than from today's prices, so a reprint never changes an old invoice.
 *   3. dompdf for the PDF, rendering this same markup (which is why it is
 *      blocks and tables, not flex or grid).
 *
 * Money goes through Money, the order number is whatever OrderNumber issued and
 * the order stored, and the mark is the single-ink mono-green lockup (bible
 * 3.7a). See docs/PRD.md Section 11 and Section 12.
 * -----------------------------------------------------------------------------
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/components/documents/document.php';

okv_document_open(['title' => 'Invoice', 'print' => false]);
okv_document_letterhead([
    'kind'      => 'Invoice',
    'title'     => 'What is owed on this order',
    'reference' => null,
    'issued_on' => date('j M Y'),
]);
?>
    <div class="okv-doc-foot okv-doc-gap-lg">
      <p class="okv-doc-stamp">Not available yet</p>
      <p class="okv-doc-gap">
        An invoice is raised against a real order. Ordering and payment are still being built,
        so there is nothing to invoice today. The letterhead, the layout and the print rules above
        are the ones every invoice will carry.
      </p>
      <p class="okv-doc-gap">
        Need an invoice for something you have already arranged with us? Ask and we will send it.
      </p>
    </div>
<?php
okv_document_close();
