<?php
/**
 * api/v1/checkout.php
 * OK Veggies. Place an order, choose payment, pick a delivery day.
 * Status: scaffold placeholder. Build in milestone M4. See docs/PRD.md Section 9.
 * Before writing logic here: read the PRD section, then ask at least five
 * clarifying questions (see CLAUDE.md). No em dash, no jargon, on brand.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Public. Enforce delivery-day eligibility and OTP for pay-on-delivery.
// $action = okv_action();
// switch ($action) { ... }  gate each action with Rbac::requirePermission or a customer auth check

okv_json(['status' => 'error', 'code' => 'not_implemented', 'message' => 'This endpoint is scaffolded and not built yet.'], 501);
