<?php
/**
 * pro/orders.php
 * OK Veggies. Your orders and their invoices.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Orders and Invoices';
$okv_pro_note   = 'Every order you have placed, with its invoice and its delivery day.';
$okv_pro_active = '/pro/orders.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Every order for every branch, what it cost, when it arrived, and the invoice to download for your records.');

require __DIR__ . '/../includes/components/pro/footer.php';
