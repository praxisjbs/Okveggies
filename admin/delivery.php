<?php
/**
 * admin/delivery.php
 * OK Veggies. Days, zones and the packing manifest.
 * Status: scaffold placeholder. Build in milestone M6. See docs/PRD.md Section 13.
 * Before writing logic here: read the PRD section, then ask at least five
 * clarifying questions (see CLAUDE.md). No em dash, no jargon, on brand.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
Rbac::requirePermission('delivery.view');
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Delivery . OK Veggies</title>
<?php okv_head_meta(); ?>
  <link rel="stylesheet" href="<?= okv_e(okv_asset('/assets/css/tailwind.css')) ?>"></head>
<body class="bg-forest-tint min-h-screen">
<div class="okv-container py-16">
  <p class="uppercase tracking-[0.2em] text-gold text-xs font-semibold">OK Veggies</p>
  <h1 class="font-display font-extrabold text-3xl text-ink mt-2">Delivery</h1>
  <p class="text-ink-60 mt-3 max-w-xl">This screen is scaffolded and waiting to be built in milestone M6. The plan for it is in docs/PRD.md Section 13.</p>
  <a href="/" class="okv-btn mt-6">Back to the shop</a>
</div>
</body></html>
