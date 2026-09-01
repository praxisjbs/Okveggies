<?php
/**
 * admin/customers.php
 * OK Veggies. Households and businesses.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M8.
 * See docs/PRD.md Section 17. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('customers.view');

$okv_admin_title = 'Customers';
$okv_admin_note  = 'Households and businesses, their addresses, their orders and their credit.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M8',
    'Section 17',
    'One place for every buyer: households and businesses, the addresses they deliver to, the orders they have placed, and the credit terms a business is on.',
    [
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
        ['label' => 'See the shop as a customer does', 'href' => '/'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
