<?php
/**
 * pro/credit.php
 * OK Veggies. Your limit, balance and terms.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Credit';
$okv_pro_note   = 'What you can draw, what you owe, and when it is due.';
$okv_pro_active = '/pro/credit.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Your credit limit, what you have drawn against it, what is still outstanding and when each amount falls due. Apply for credit here, or ask us to raise your limit.');

require __DIR__ . '/../includes/components/pro/footer.php';
