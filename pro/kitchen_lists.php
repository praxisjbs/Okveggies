<?php
/**
 * pro/kitchen_lists.php
 * OK Veggies. Saved and reusable lists.
 * Status: scaffold placeholder inside the Pro Portal shell. Build in milestone
 * M8. See docs/PRD.md Section 3 and Section 4.2. Before writing logic here:
 * read the PRD section, then ask at least five clarifying questions (see
 * CLAUDE.md), and add the server-side access check this screen needs. No em
 * dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/components/pro/placeholder.php';

$okv_pro_title  = 'My Kitchen Lists';
$okv_pro_note   = 'The lists you order from every week, saved so you never rebuild them.';
$okv_pro_active = '/pro/kitchen_lists.php';
require __DIR__ . '/../includes/components/pro/header.php';

okv_pro_placeholder('Save the list your kitchen orders every week, name it, and send it again in one tap. Change a quantity, drop an item, and it is ready for the next run.');

require __DIR__ . '/../includes/components/pro/footer.php';
