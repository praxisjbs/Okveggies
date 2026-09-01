<?php
/**
 * pro/standing_orders.php
 * OK Veggies. Repeat orders on a schedule.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'Standing Orders';
$okv_pro_note   = 'The same delivery, on the same days, without asking twice.';
$okv_pro_active = '/pro/standing_orders.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Set a list to repeat on the days you want it, Tuesday and Friday for most kitchens, and we prepare it without you asking each week. Pause it or change it whenever you need.');

require __DIR__ . '/../includes/components/pro/footer.php';
