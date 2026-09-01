<?php
/**
 * includes/components/documents/document.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The frame every printed document sits in: the invoice, the
 * receipt, and whatever else has to survive a printer or a PDF.
 *
 * What it gives a document:
 *   okv_document_open()        the head, the page shell, the print button
 *   okv_document_letterhead()  the single-ink mark, the business block, the rule
 *   okv_document_meta()        the three-column block of who, when and how
 *   okv_document_lines()       the item table, figures in the mono face
 *   okv_document_totals()      subtotal, delivery, paid, balance due
 *   okv_document_close()       the footer and the page end
 *
 * Three rules it holds to, so a document can never drift:
 *   1. Money only ever comes through the Money helper, and a document always
 *      shows the kobo, because a printed figure is a figure someone reconciles.
 *   2. An order number is whatever OrderNumber issued and the order stored. A
 *      document never builds one and never invents one: no number means the
 *      document says so.
 *   3. The mark is the single-ink mono-green lockup (bible 3.7a names receipts
 *      and invoices as its use), never the photographic seal desaturated, which
 *      3.8 forbids. It prints correctly on a mono laser and on a colour one.
 *
 * The markup is plain blocks and tables, not flex or grid, because the same
 * markup goes through dompdf when M5 builds the real documents and dompdf reads
 * neither. The styles live in assets/css/src/input.css under .okv-doc.
 * -----------------------------------------------------------------------------
 */

if (!function_exists('okv_document_money')) {
    /**
     * Money on a document. Always through the Money helper, always with the
     * kobo shown, because ₦8,000 and ₦8,000.00 are the same amount but only one
     * of them reconciles cleanly against a bank statement.
     */
    function okv_document_money(int $subunit): string
    {
        return Money::format($subunit, true);
    }
}

if (!function_exists('okv_document_reference')) {
    /**
     * The order number as it will be printed. Whatever OrderNumber issued and
     * the order stored, or an honest line when the order has no number yet.
     * A document never builds a number by hand.
     */
    function okv_document_reference(?string $orderNumber): string
    {
        $orderNumber = trim((string) $orderNumber);
        return $orderNumber === '' ? 'Not issued yet' : $orderNumber;
    }
}

if (!function_exists('okv_document_line_total')) {
    /**
     * One line's total in subunits. Rounded the same way the combo component
     * total is (ROUND(quantity * unit price)), so a document, the basket and the
     * admin builder can never disagree about a line by a kobo.
     */
    function okv_document_line_total(int $unitPriceSubunit, float $quantity): int
    {
        return (int) round($unitPriceSubunit * $quantity);
    }
}

if (!function_exists('okv_document_totals_rows')) {
    /**
     * The totals block for a document, in the order it is read.
     * Every amount is subunits in, formatted naira out, and the balance is
     * computed here rather than in a template, so the arithmetic is testable.
     *
     * @return array<int, array{label: string, amount: string, is_total: bool}>
     */
    function okv_document_totals_rows(int $subtotalSubunit, int $deliverySubunit = 0, int $paidSubunit = 0): array
    {
        $rows = [
            ['label' => 'Items', 'amount' => okv_document_money($subtotalSubunit), 'is_total' => false],
        ];
        if ($deliverySubunit !== 0) {
            $rows[] = ['label' => 'Delivery', 'amount' => okv_document_money($deliverySubunit), 'is_total' => false];
        }
        $due = $subtotalSubunit + $deliverySubunit;
        if ($paidSubunit !== 0) {
            $rows[] = ['label' => 'Total', 'amount' => okv_document_money($due), 'is_total' => false];
            $rows[] = ['label' => 'Paid', 'amount' => okv_document_money($paidSubunit), 'is_total' => false];
            $rows[] = ['label' => 'Balance due', 'amount' => okv_document_money($due - $paidSubunit), 'is_total' => true];
            return $rows;
        }
        $rows[] = ['label' => 'Total', 'amount' => okv_document_money($due), 'is_total' => true];
        return $rows;
    }
}

if (!function_exists('okv_document_open')) {
    /**
     * @param array{title: string, print: bool} $o
     *   title  what the browser tab says
     *   print  show the print button. False when there is nothing to print yet.
     */
    function okv_document_open(array $o): void
    {
        $title    = (string) ($o['title'] ?? 'Document');
        $showPrint = (bool) ($o['print'] ?? true);
        ?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= okv_e($title) ?> . OK Veggies</title>
  <meta name="robots" content="noindex, nofollow">
  <?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>">
</head>
<body class="bg-forest-tint">
  <div class="okv-no-print okv-container flex flex-wrap items-center justify-between gap-3 py-4">
    <a href="/" class="okv-btn-text text-sm">Back to the shop</a>
    <?php if ($showPrint): ?>
      <button type="button" class="okv-btn-sm" data-okv-print>Print this</button>
    <?php endif; ?>
  </div>
  <article class="okv-doc rounded-lg shadow-okv-1">
        <?php
    }
}

if (!function_exists('okv_document_letterhead')) {
    /**
     * @param array{kind: string, title: string, reference: ?string, issued_on: ?string} $o
     */
    function okv_document_letterhead(array $o): void
    {
        $name    = Settings::str('business_name', 'OK Veggies');
        $tagline = Settings::str('business_tagline', 'Sourced right. Priced right. Delivered right.');
        $email   = Settings::str('support_email', 'hello@okveggies.com.ng');
        // Through the Phone helper, so a document reads 0800 000 0000 rather
        // than the E.164 string the setting stores.
        $phone   = Phone::display(Settings::str('support_whatsapp_number', '2348000000000'));
        $site    = preg_replace('#^https?://#', '', rtrim((string) APP_URL, '/'));
        $ref     = okv_document_reference($o['reference'] ?? null);
        $issued  = (string) ($o['issued_on'] ?? date('j M Y'));
        ?>
    <table class="okv-doc-head">
      <tr>
        <td>
          <img src="<?= okv_e(okv_asset('/assets/img/brand/lockup-mono-green.svg')) ?>"
               alt="<?= okv_e($name) ?>" class="okv-doc-mark" width="260" height="68">
        </td>
        <td class="okv-doc-org">
          <strong><?= okv_e($name) ?></strong><br>
          <?= okv_e($tagline) ?><br>
          <?= okv_e($email) ?><br>
          <?= okv_e($phone) ?><br>
          <?= okv_e($site) ?>
        </td>
      </tr>
    </table>

    <div class="okv-doc-rule"></div>

    <table class="okv-doc-head">
      <tr>
        <td>
          <p class="okv-doc-kind"><?= okv_e((string) ($o['kind'] ?? 'Document')) ?></p>
          <h1 class="okv-doc-title"><?= okv_e((string) ($o['title'] ?? '')) ?></h1>
        </td>
        <td class="okv-doc-org">
          <p class="okv-doc-label">Order number</p>
          <p class="okv-doc-ref"><?= okv_e($ref) ?></p>
          <p class="okv-doc-label okv-doc-gap">Issued</p>
          <p><?= okv_e($issued) ?></p>
        </td>
      </tr>
    </table>
        <?php
    }
}

if (!function_exists('okv_document_meta')) {
    /**
     * Up to three columns of label and value: who it is for, where it goes,
     * how it was paid. @param array<string, string> $columns label => value
     */
    function okv_document_meta(array $columns): void
    {
        ?>
    <table class="okv-doc-meta">
      <tr>
        <?php foreach ($columns as $label => $value): ?>
          <td>
            <p class="okv-doc-label"><?= okv_e((string) $label) ?></p>
            <p><?= nl2br(okv_e((string) $value)) ?></p>
          </td>
        <?php endforeach; ?>
      </tr>
    </table>
        <?php
    }
}

if (!function_exists('okv_document_lines')) {
    /**
     * The items on the document.
     * @param array<int, array{name: string, unit: string, quantity: string, amount: string}> $lines
     *        Amounts arrive already formatted through okv_document_money().
     */
    function okv_document_lines(array $lines): void
    {
        ?>
    <table class="okv-doc-lines">
      <thead>
        <tr>
          <th scope="col">Item</th>
          <th scope="col">Quantity</th>
          <th scope="col" class="okv-doc-figure">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <tr>
            <td><?= okv_e((string) ($line['name'] ?? '')) ?></td>
            <td><?= okv_e((string) ($line['quantity'] ?? '')) ?> <?= okv_e((string) ($line['unit'] ?? '')) ?></td>
            <td class="okv-doc-figure"><?= okv_e((string) ($line['amount'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
        <?php
    }
}

if (!function_exists('okv_document_totals')) {
    /** @param array<int, array{label: string, amount: string, is_total: bool}> $rows From okv_document_totals_rows(). */
    function okv_document_totals(array $rows): void
    {
        ?>
    <table class="okv-doc-totals">
      <?php foreach ($rows as $row): ?>
        <tr<?= !empty($row['is_total']) ? ' class="okv-doc-total-row"' : '' ?>>
          <td><?= okv_e((string) $row['label']) ?></td>
          <td class="okv-doc-figure"><?= okv_e((string) $row['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
        <?php
    }
}

if (!function_exists('okv_document_close')) {
    /** @param string $note One plain line at the foot, for example how to reach us. */
    function okv_document_close(string $note = ''): void
    {
        $name  = Settings::str('business_name', 'OK Veggies');
        $email = Settings::str('support_email', 'hello@okveggies.com.ng');
        ?>
    <div class="okv-doc-foot">
      <?php if ($note !== ''): ?><p><?= okv_e($note) ?></p><?php endif; ?>
      <p>
        If anything is not right, tell us and we will make it right.
        Reach <?= okv_e($name) ?> at <?= okv_e($email) ?>.
      </p>
    </div>
  </article>
  <script>
  (function () {
    var button = document.querySelector('[data-okv-print]');
    if (button) { button.addEventListener('click', function () { window.print(); }); }
  })();
  </script>
</body>
</html>
        <?php
    }
}
