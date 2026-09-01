<?php
/**
 * admin/orders.php
 * OK Veggies. Every order and its trail.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M6.
 * See docs/PRD.md Section 14. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('orders.view');

$okv_admin_title = 'Orders';
$okv_admin_note  = 'Every order, what was paid, the delivery day it is on, and the trail the customer follows.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M6',
    'Section 14',
    'This is where an order lives: the items and the money on it, the delivery day, the status history, and the public trail link the customer follows from the moment it is placed.',
    [
        ['label' => 'Build a combo', 'href' => '/admin/combos.php'],
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
        ['label' => 'See the shop as a customer does', 'href' => '/'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
