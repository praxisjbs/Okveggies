<?php
/**
 * includes/config/permissions.php
 * -----------------------------------------------------------------------------
 * OK Veggies. The canonical permission catalogue, grouped by module. This is the
 * developer-facing reference; the database seed (migrations/002_rbac_seed.sql)
 * must stay in step with it. To add a permission: add the key here, add it to a
 * new migration, and (if a default role should get it) grant it there too.
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
        'kitchen_runs.quote'           => 'Price a kitchen run',
        'kitchen_runs.approve'         => 'Approve a kitchen run',
        'kitchen_runs.convert'         => 'Turn a kitchen run into an order',
        'kitchen_runs.decline'         => 'Decline a kitchen run',
    ],
    'customers' => [
        'customers.view'               => 'View customers',
        'customers.edit'               => 'Edit a customer',
        'customers.addresses.view'     => 'View customer addresses',
    ],
    'payments' => [
        'payments.view'                => 'View payments',
        'payments.record'              => 'Record a cash or transfer payment',
        'payments.proof.review'        => 'Review a manual payment proof',
        'payments.refund'              => 'Issue a refund',
    ],
    'credit' => [
        'credit.view'                  => 'View credit accounts',
        'credit.apply.review'          => 'Review a credit application',
        'credit.grant'                 => 'Grant credit to a business',
        'credit.limit.set'             => 'Set a credit limit',
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
    'payments.refund',
    'credit.apply.review', 'credit.grant', 'credit.limit.set',
];
