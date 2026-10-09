<?php
/**
 * includes/config/permissions.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The canonical permission catalogue, grouped by module. This is the
 * developer-facing reference; the database seed (migrations/002_rbac_seed.sql)
 * must stay in step with it. To add a permission: add the key here, add it to a
 * new migration, and (if a default role should get it) grant it there too.
 *
 * That rule runs both ways, and this file is the half that is easy to forget: a
 * key a migration inserts and this file never lists leaves the database ahead of
 * the catalogue, and /admin/permissions.php reports the two do not agree. The
 * keys added after their migrations, rather than with them, are the four this
 * file now carries for the reversal, resend and typed-in kitchen run work.
 * scripts/tests/PermissionMatrixTest.php asserts both directions, so the next
 * one fails the unit suite instead of waiting to be noticed on the screen.
 *
 * Naming: module.entity.action, lower snake, dot separated.
 * Wildcards understood by Rbac::hasPermission(): '*' (superuser) and 'module.*'.
 * -----------------------------------------------------------------------------
 */

$OKV_PERMISSIONS = [
    'dashboard' => [
        'dashboard.view'               => 'See the admin dashboard',
        'dashboard.analytics.view'     => 'See analytics charts',
    ],
    'orders' => [
        'orders.view'                  => 'View orders',
        'orders.update'                => 'Edit an order',
        'orders.status.update'         => 'Move an order through its stages',
        'orders.cancel'                => 'Cancel an order',
        'orders.reschedule'            => 'Reschedule an order delivery date',
        'orders.create'                => 'Create an order by hand, for a phone order',
        'orders.source.override'       => 'Source an order the payment gate would refuse, with a reason',
        'orders.shortage.record'       => 'Mark an order line out of stock and settle it for the customer',
    ],
    'products' => [
        'products.view'                => 'View products',
        'products.create'              => 'Add a product',
        'products.edit'                => 'Edit a product',
        'products.delete'              => 'Remove a product',
        'products.availability.update' => 'Change product availability',
    ],
    'pricing' => [
        'pricing.view'                 => 'View pricing',
        'pricing.update'               => 'Change this week\'s prices',
        'pricing.import'               => 'Import prices from a spreadsheet',
        'pricing.export'               => 'Export the price list',
    ],
    'combos' => [
        'combos.view'                  => 'View combos',
        'combos.create'                => 'Create a combo',
        'combos.edit'                  => 'Edit a combo',
        'combos.publish'               => 'Publish or unpublish a combo',
        'combos.delete'                => 'Delete a combo',
    ],
    'kitchen_runs' => [
        'kitchen_runs.view'            => 'View kitchen runs',
        'kitchen_runs.create'          => 'Start a kitchen run on a customer\'s behalf',
        'kitchen_runs.quote'           => 'Price a kitchen run',
        'kitchen_runs.approve'         => 'Approve a kitchen run',
        'kitchen_runs.convert'         => 'Turn a kitchen run into an order',
        'kitchen_runs.decline'         => 'Decline a kitchen run',
    ],
    'customers' => [
        'customers.view'               => 'View customers',
        'customers.create'             => 'Create a customer account',
        'customers.edit'               => 'Edit a customer',
        'customers.addresses.view'     => 'View customer addresses',
        'wallet.view'                  => 'See a customer wallet, its ledger and its credit notes',
        'wallet.credit'                => 'Give a customer goodwill credit in their wallet',
    ],
    'payments' => [
        'payments.view'                => 'View payments',
        'payments.record'              => 'Record a cash or transfer payment',
        'payments.reversal.request'    => 'Ask for a recorded payment to be reversed',
        'payments.reversal.approve'    => 'Approve a reversal of a recorded payment',
        'payments.proof.review'        => 'Review a manual payment proof',
        'payments.refund'              => 'Issue a refund',
    ],
    'credit' => [
        'credit.view'                  => 'View credit accounts',
        'credit.apply.review'          => 'Review a credit application',
        'credit.grant'                 => 'Grant credit to a business',
        'credit.limit.set'             => 'Set a credit limit',
    ],
    'expenses' => [
        'expenses.view'                => 'See the expense list and totals',
        'expenses.manage'              => 'Record an expense and void one',
    ],
    'reports' => [
        'reports.view'                 => 'See the financial dashboard',
    ],
    'delivery' => [
        'delivery.view'                => 'View delivery planning',
        'delivery.manifest.view'       => 'View and print the day manifest',
        'delivery.days.edit'           => 'Edit allowed delivery days',
        'delivery.zones.edit'          => 'Edit delivery zones',
        'delivery.exceptions.edit'     => 'Edit delivery date exceptions',
    ],
    'content' => [
        'content.view'                 => 'View content pages',
        'content.edit'                 => 'Edit content pages',
        'messages.view'                => 'View contact messages',
        'messages.handle'              => 'Respond to contact messages',
    ],
    'make_it_right' => [
        'issues.view'                  => 'View issue reports',
        'issues.resolve'               => 'Resolve an issue report',
    ],
    'settings' => [
        'settings.view'                => 'View settings',
        'settings.edit'                => 'Edit site settings',
        'settings.order.edit'          => 'Edit order and deposit settings',
        'settings.notifications.edit'  => 'Edit notification templates',
        'notifications.resend'         => 'Resend a notification that failed',
    ],
    'users' => [
        'users.view'                   => 'View staff users',
        'users.create'                 => 'Add a staff user',
        'users.edit'                   => 'Edit a staff user',
        'users.roles.edit'             => 'Assign roles to a user',
    ],
    'rbac' => [
        'rbac.roles.view'              => 'View roles and permissions',
        'rbac.roles.edit'              => 'Edit roles and permissions',
    ],
];

// Keys the Manager role does NOT get (Owner only). Kept in step with 002 seed.
$OKV_OWNER_ONLY = [
    'users.view', 'users.create', 'users.edit', 'users.roles.edit',
    'rbac.roles.view', 'rbac.roles.edit',
    'settings.edit', 'settings.order.edit', 'settings.notifications.edit',
    'products.delete', 'combos.delete',
    'payments.refund', 'orders.source.override', 'wallet.credit',
    'credit.apply.review', 'credit.grant', 'credit.limit.set',
];
