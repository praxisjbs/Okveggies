<?php
/**
 * admin/credit.php
 * OK Veggies. Applications, limits and balances.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M8.
 * See docs/PRD.md Section 12. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('credit.view');

$okv_admin_title = 'Credit';
$okv_admin_note  = 'Credit applications, limits, balances and what each business still owes.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M8',
    'Section 12',
    'A business applies for credit here or the Owner grants it by hand, with a limit per customer, the balance drawn against it, and how old each unpaid amount is.',
    [
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
        ['label' => 'Set this week\'s prices', 'href' => '/admin/pricing.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
