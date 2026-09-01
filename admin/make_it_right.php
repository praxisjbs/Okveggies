<?php
/**
 * admin/make_it_right.php
 * OK Veggies. Order issues and resolutions.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M10.
 * See docs/PRD.md Section 16. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('issues.view');

$okv_admin_title = 'Make It Right';
$okv_admin_note  = 'What arrived wrong, and how it was put right.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M10',
    'Section 16',
    'A customer reports what was not right about an order, with a note and photos. You resolve it with a refund, a credit or a replacement, and they see the outcome. No ticket numbers, no three day wait.',
    [
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
        ['label' => 'See the shop as a customer does', 'href' => '/'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
