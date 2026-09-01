<?php
/**
 * admin/content.php
 * OK Veggies. Page copy and contact messages.
 * Status: scaffold placeholder inside the real admin shell. Build in milestone M9.
 * See docs/PRD.md Section 18. Before writing logic here: read the PRD section, then
 * ask at least five clarifying questions (see CLAUDE.md). No em dash, no
 * jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/admin/placeholder.php';
Rbac::requirePermission('content.view');

$okv_admin_title = 'Content and Messages';
$okv_admin_note  = 'The page copy customers read, and the messages they send you.';
require __DIR__ . '/../includes/components/admin/header.php';

okv_admin_placeholder(
    'M9',
    'Section 18',
    'Our Story, How It Works, the questions and answers and the legal pages are edited here rather than in code, alongside the messages that come in from the contact form and the support widget.',
    [
        ['label' => 'See the shop as a customer does', 'href' => '/'],
        ['label' => 'Manage the catalogue', 'href' => '/admin/products.php'],
    ]
);

require __DIR__ . '/../includes/components/admin/footer.php';
