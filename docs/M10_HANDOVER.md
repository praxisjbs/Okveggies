# Milestone 10 Trust and Make It Right Handover

Milestone 10 is complete in development. It gives a customer a private way to report a delivery problem against an order, lets authorised staff take and resolve the report, records the outcome permanently, and tells the customer what happened. The public Order Trail does not expose reports or outcomes.

## What shipped

- A signed-in customer can report a wrong item, missing item, quality problem, short quantity, damage, late delivery, or another delivery problem from an order they own.
- Reports are allowed only after dispatch or delivery and inside the managed reporting window.
- Up to 5 optional JPEG, PNG or WebP photos are stored privately and served through an ownership and permission checked route.
- The staff queue is oldest first and supports status, category and date filters with 25 reports per page.
- Staff can take a report and finish it as a refund, account credit, replacement, or decline with a reason the customer sees.
- Refunds use the M5 refund engine. Account credit uses M8 and is available only to an approved business. A replacement links an existing active manual order for the same customer.
- The customer sees report history and the latest outcome on their signed-in order. Refund wording follows the live M5 refund status.
- Customer receipt, customer outcome, and staff alert emails use the M6 dispatcher and editable notification templates.
- Checkout and the storefront carry the required Paystack, sourcing, photography, Order Trail, and Make It Right trust signals.

## Access and privacy

- Customer report writes require a signed-in owning customer, POST and a valid CSRF token.
- Staff reads require `issues.view`.
- Staff workflow writes require `issues.view`, `issues.resolve`, POST and a valid CSRF token.
- Refund resolution also requires `payments.refund`.
- Account-credit resolution also requires `credit.grant`.
- Only the Owner can take over a report assigned to another colleague.
- The Owner and Manager roles have the operational issue permissions seeded for Phase 1.
- Report words, state, photos, outcome, amount and internal identifiers are absent from the public token trail.

## Database migrations

M10 stays inside its reserved range:

- `045_make_it_right_reporting.sql`: report records, photos, reporting setting and initial templates.
- `046_make_it_right_workflow.sql`: handler timestamps, append-only status history and workflow support.
- `047_make_it_right_resolutions.sql`: durable resolution evidence, refund, credit and replacement links.
- `048_make_it_right_notification_copy.sql`: richer receipt copy while preserving Owner-edited wording.
- `049` remains unused.

Run migrations through the normal deployment process. Do not apply individual statements by hand.

## Verification

The normal gate is:

```bash
php scripts/migrate.php
bash scripts/tests/run_all.sh
bash scripts/verify.sh https://okveggies.com.ng
```

`scripts/tests/run_all.sh` includes all M10 reporting, photo, workflow, resolution, customer-outcome and notification suites. The tests cover ownership, the exact reporting-window boundary, the complete state graph, refund amount limits and idempotency, hostile file uploads, public-trail privacy, seeded roles, partial custom roles, request methods and CSRF.

## Deployment checks

After deployment:

1. Run the migrations and confirm a second run reports nothing pending.
2. Run `scripts/verify.sh` against the deployed HTTPS address.
3. Confirm `/migrations/` and `/docs/` return 403 or 404 under Apache or cPanel. PHP's built-in server does not apply `.htaccess`, so these 2 checks are meaningful only on the deployment web server.
4. Sign in as an Owner and a Manager and open the Make It Right queue.
5. Open an eligible customer order, submit a harmless test report, take it, decline it with clear test wording, and confirm the customer receipt and outcome emails arrive.
6. Confirm the same order's public token trail contains no report or outcome details.
7. Remove or clearly retain the test report according to the production data policy. Never delete a real customer report.

## Operations

- Failed email deliveries remain in `notification_deliveries` for the existing retry worker. A failed email does not remove a report or outcome.
- Reporting-window changes are made in Admin Settings.
- Notification wording is changed in Admin Settings under Notifications.
- Staff should use the explicit Owner takeover action when responsibility changes. Do not edit `handled_by` directly.
- Refund progress is owned by M5. Staff should not promise that money has arrived before the gateway status confirms it.
