<?php
/**
 * admin/payments.php
 * OK Veggies. Paystack, proofs and refunds.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M5.
 * See docs/PRD.md Section 11. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('payments.view');

$okv_admin_title = 'Payments';
$okv_admin_note  = 'Paystack transactions, manual payment proofs and refunds, with what has settled.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M5',
    'Section 11',
    'Card, transfer and USSD payments through Paystack, the deposit and the balance on each order, a manual proof recorded by staff for pay on delivery, refunds, and the settlement view.',
    [
        ['label' => 'Set this week\'s prices', 'href' => '/admin/pricing.php'],
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
