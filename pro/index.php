<?php
/**
 * pro/index.php
 * OK Veggies. Your business at a glance.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Your business at a glance';
$okv_pro_note   = 'What you have on order, what is due, and what your kitchen usually needs.';
$okv_pro_active = '/pro/';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Your dashboard: the orders you have in flight, what is due to be delivered next, your credit balance, and the lists you order from most.');

require __DIR__ . '/../includes/components/pro/footer.php';
