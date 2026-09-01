<?php
/**
 * admin/settings.php
 * OK Veggies. Order, site and notification settings.
 * Status: scaffold placeholder inside the real admin shell. Build in a milestone still to be scheduled.
 * See docs/PRD.md Section 17. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('settings.view');

$okv_admin_title = 'Settings';
$okv_admin_note  = 'The deposit percentage, the delivery cutoff, the business details and the email templates.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    '',
    'Section 17',
    'Order settings including the deposit percentage and the delivery cutoff, the site details customers see, and the wording of the transactional emails. The values are seeded already and read live by the app; this is the screen that will edit them.',
    [
        ['label' => 'Set this week\'s prices', 'href' => '/admin/pricing.php'],
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
