<?php
/**
 * api/v1/kitchen_runs.php
 * OK Veggies. Submit, quote, approve and convert kitchen runs.
 * Status: scaffold placeholder. Build in milestone M7. See docs/PRD.md Section 8.
 * Before writing logic here: read the PRD section, then ask at least five
 * clarifying questions (see CLAUDE.md). No em dash, no jargon, on brand.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Mixed. Customer submits; staff quote, approve, convert (kitchen_runs.*).
// $action = okv_action();
// switch ($action) { ... }  gate each action with Rbac::requirePermission or a customer auth check

okv_json(['status' => 'error', 'code' => 'not_implemented', 'message' => 'This endpoint is scaffolded and not built yet.'], 501);
