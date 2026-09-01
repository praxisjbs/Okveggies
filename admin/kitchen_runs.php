<?php
/**
 * admin/kitchen_runs.php
 * OK Veggies. Quote, approve, convert.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M7.
 * See docs/PRD.md Section 8. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('kitchen_runs.view');

$okv_admin_title = 'Kitchen Runs';
$okv_admin_note  = 'Kitchen run requests, the quote you send back, and turning an approved quote into an order.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M7',
    'Section 8',
    'A customer sends a shopping list, priced by us or priced by them, or an open budget they trust us to spend. You quote it, they approve it, and it becomes an order.',
    [
        ['label' => 'Set this week\'s prices', 'href' => '/admin/pricing.php'],
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
