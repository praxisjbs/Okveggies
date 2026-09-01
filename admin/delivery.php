<?php
/**
 * admin/delivery.php
 * OK Veggies. Days, zones and the packing manifest.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M6.
 * See docs/PRD.md Section 13. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('delivery.view');

$okv_admin_title = 'Delivery';
$okv_admin_note  = 'Delivery days, the cutoff, the Lagos zones, and the printable list for a packing day.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M6',
    'Section 13',
    'The days you deliver on, the cutoff and lead time, the Lagos zones and their fees, the exceptions for a public holiday, and the packing manifest for a day, grouped by zone and ready to print.',
    [
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
        ['label' => 'Build a combo', 'href' => '/admin/combos.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
