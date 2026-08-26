<?php
/**
 * api/v1/catalog.php
 * OK Veggies. Product and category reads for the storefront.
 * Status: scaffold placeholder. Build in milestone M2. See docs/PRD.md Section 5.
 * Before writing logic here: read the PRD section, then ask at least five
 * clarifying questions (see CLAUDE.md). No em dash, no jargon, on brand.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Public reads.
// $action = okv_action();
// switch ($action) { ... }  gate each action with Rbac::requirePermission or a customer auth check

okv_json(['status' => 'error', 'code' => 'not_implemented', 'message' => 'This endpoint is scaffolded and not built yet.'], 501);
