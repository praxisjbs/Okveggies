<?php
/**
 * pro/account.php
 * OK Veggies. Your business profile.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Account and Branches';
$okv_pro_note   = 'Your business details, the people who order, and the branches we deliver to.';
$okv_pro_active = '/pro/account.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Your business details, the people allowed to order on the account, and every branch we deliver to, each with its own address and delivery day.');

require __DIR__ . '/../includes/components/pro/footer.php';
