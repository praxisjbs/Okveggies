-- Stable access roles. Run after the schema migration.


SET NAMES utf8mb4;
SET @OLD_FOREIGN_KEY_CHECKS = @@FOREIGN_KEY_CHECKS;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;



INSERT INTO `roles`(`id`, `name`, `description`) VALUES
  (1, 'super_admin', 'Full access to all administrative functions.'),
  (2, 'catalogue_manager', 'Manages products, categories, prices, and availability.'),
  (3, 'order_manager', 'Manages orders, delivery schedules, and cancellations.'),
  (4, 'finance_manager', 'Manages payments, refunds, credit, and reconciliation.'),
  (5, 'support_agent', 'Views customers and assists with order enquiries.')
ON DUPLICATE KEY UPDATE description = VALUES(description);


-- Permission catalogue (module.entity.action) --------------------------------
INSERT INTO permissions (`key`, `module`, `description`) VALUES
  ('dashboard.view',              'dashboard',    'See the admin dashboard'),
  ('dashboard.analytics.view',    'dashboard',    'See analytics charts'),

  ('orders.view',                 'orders',       'View orders'),
  ('orders.update',               'orders',       'Edit an order'),
  ('orders.status.update',        'orders',       'Move an order through its stages'),
  ('orders.cancel',               'orders',       'Cancel an order'),

  ('products.view',               'products',     'View products'),
  ('products.create',             'products',     'Add a product'),
  ('products.edit',               'products',     'Edit a product'),
  ('products.delete',             'products',     'Remove a product'),
  ('products.availability.update','products',     'Change product availability'),

  ('pricing.view',                'pricing',      'View pricing'),
  ('pricing.update',              'pricing',      'Change this week''s prices'),
  ('pricing.import',              'pricing',      'Import prices from a spreadsheet'),
  ('pricing.export',              'pricing',      'Export the price list'),

  ('combos.view',                 'combos',       'View combos'),
  ('combos.create',               'combos',       'Create a combo'),
  ('combos.edit',                 'combos',       'Edit a combo'),
  ('combos.publish',              'combos',       'Publish or unpublish a combo'),
  ('combos.delete',               'combos',       'Delete a combo'),

  ('kitchen_runs.view',           'kitchen_runs', 'View kitchen runs'),
  ('kitchen_runs.quote',          'kitchen_runs', 'Price a kitchen run'),
  ('kitchen_runs.approve',        'kitchen_runs', 'Approve a kitchen run'),
  ('kitchen_runs.convert',        'kitchen_runs', 'Turn a kitchen run into an order'),
  ('kitchen_runs.decline',        'kitchen_runs', 'Decline a kitchen run'),

  ('customers.view',              'customers',    'View customers'),
  ('customers.edit',              'customers',    'Edit a customer'),
  ('customers.addresses.view',    'customers',    'View customer addresses'),

  ('payments.view',               'payments',     'View payments'),
  ('payments.record',             'payments',     'Record a cash or transfer payment'),
  ('payments.proof.review',       'payments',     'Review a manual payment proof'),
  ('payments.refund',             'payments',     'Issue a refund'),

  ('credit.view',                 'credit',       'View credit accounts'),
  ('credit.apply.review',         'credit',       'Review a credit application'),
  ('credit.grant',                'credit',       'Grant credit to a business'),
  ('credit.limit.set',            'credit',       'Set a credit limit'),

  ('delivery.view',               'delivery',     'View delivery planning'),
  ('delivery.manifest.view',      'delivery',     'View and print the day manifest'),
  ('delivery.days.edit',          'delivery',     'Edit allowed delivery days'),
  ('delivery.zones.edit',         'delivery',     'Edit delivery zones'),
  ('delivery.exceptions.edit',    'delivery',     'Edit delivery date exceptions'),

  ('content.view',                'content',      'View content pages'),
  ('content.edit',                'content',      'Edit content pages'),
  ('messages.view',               'content',      'View contact messages'),
  ('messages.handle',             'content',      'Respond to contact messages'),

  ('issues.view',                 'make_it_right','View issue reports'),
  ('issues.resolve',              'make_it_right','Resolve an issue report'),

  ('settings.view',               'settings',     'View settings'),
  ('settings.edit',               'settings',     'Edit site settings'),
  ('settings.order.edit',         'settings',     'Edit order and deposit settings'),
  ('settings.notifications.edit', 'settings',     'Edit notification templates'),

  ('users.view',                  'users',        'View staff users'),
  ('users.create',                'users',        'Add a staff user'),
  ('users.edit',                  'users',        'Edit a staff user'),
  ('users.roles.edit',            'users',        'Assign roles to a user'),

  ('rbac.roles.view',             'rbac',         'View roles and permissions'),
  ('rbac.roles.edit',             'rbac',         'Edit roles and permissions')
ON DUPLICATE KEY UPDATE `module` = VALUES(`module`), `description` = VALUES(`description`);

-- Owner gets every permission -------------------------------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'owner';

-- Manager gets everything except the Owner-only set ---------------------------
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r CROSS JOIN permissions p
WHERE r.name = 'manager'
  AND p.`key` NOT IN (
    'users.view','users.create','users.edit','users.roles.edit',
    'rbac.roles.view','rbac.roles.edit',
    'settings.edit','settings.order.edit','settings.notifications.edit',
    'products.delete','combos.delete',
    'payments.refund',
    'credit.apply.review','credit.grant','credit.limit.set'
  );

COMMIT;

-- Verification:
--   SELECT name, COUNT(*) FROM roles r JOIN role_permissions rp ON rp.role_id=r.id GROUP BY name;
--   Expect owner = full catalogue, manager = catalogue minus 15 owner-only keys.