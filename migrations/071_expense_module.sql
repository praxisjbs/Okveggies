-- =============================================================================
-- 071_expense_module.sql
-- OK Veggies. The Expense module's two tables and its enforced category seed.
--
--   expense_categories  A fixed, code-owned list, derived from the business's
--                       own spreadsheet lines and named in their own words.
--                       Every expense must pick one. `kind` separates the
--                       produce they buy to resell (cost_of_goods) from running
--                       costs (operating), which is what lets the reporting
--                       dashboard show a gross-margin line.
--   expenses            One row per money-out event. Amount is integer kobo,
--                       never a float. An expense is voided, never deleted, so
--                       the ledger is append-only like orders and payments.
--
-- The taxonomy here mirrors includes/classes/Expenses::CATEGORIES exactly. That
-- constant is the single source of truth the app and the tests read; this seed
-- keeps the database in step with it.
--
-- Idempotent and MySQL 8 safe: CREATE TABLE IF NOT EXISTS and
-- INSERT ... ON DUPLICATE KEY UPDATE, so re-applying this file is a no-op. DDL
-- cannot be rolled back, so the file keeps no explicit transaction of its own.
-- See docs/EXPENSES_AND_REPORTING_ENGINEERING_GUIDE.md and docs/PRD.md.
-- =============================================================================

CREATE TABLE IF NOT EXISTS `expense_categories` (
  `id`           SMALLINT UNSIGNED NOT NULL,
  `slug`         VARCHAR(40)  NOT NULL,
  `name`         VARCHAR(80)  NOT NULL,
  `kind`         ENUM('cost_of_goods','operating') NOT NULL DEFAULT 'operating',
  `colour_token` VARCHAR(24)  NOT NULL DEFAULT 'forest',
  `sort_order`   SMALLINT     NOT NULL DEFAULT 0,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expense_categories_slug` (`slug`),
  KEY `ix_expense_categories_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `spent_on`          DATE            NOT NULL,
  `category_id`       SMALLINT UNSIGNED NOT NULL,
  `supplier_name`     VARCHAR(120)    NULL,
  `supplier_key`      VARCHAR(120)    NULL,
  `description`       VARCHAR(255)    NULL,
  `quantity`          DECIMAL(12,3)   NULL,
  `unit_cost_subunit` BIGINT UNSIGNED NULL,
  `amount_subunit`    BIGINT UNSIGNED NOT NULL,
  `source`            ENUM('manual','migration') NOT NULL DEFAULT 'manual',
  `external_ref`      VARCHAR(80)     NULL,
  `is_void`           TINYINT(1)      NOT NULL DEFAULT 0,
  `voided_at`         DATETIME        NULL,
  `voided_by`         BIGINT UNSIGNED NULL,
  `void_reason`       VARCHAR(255)    NULL,
  `created_by`        BIGINT UNSIGNED NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_expenses_external_ref` (`external_ref`),
  KEY `ix_expenses_spent_on` (`spent_on`),
  KEY `ix_expenses_category_date` (`category_id`, `spent_on`),
  KEY `ix_expenses_supplier_key` (`supplier_key`),
  KEY `ix_expenses_live` (`is_void`, `spent_on`),
  CONSTRAINT `fk_expenses_category` FOREIGN KEY (`category_id`)
    REFERENCES `expense_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_expenses_voided_by` FOREIGN KEY (`voided_by`)
    REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The enforced taxonomy. Mirrors Expenses::CATEGORIES. Fixed ids, so the
-- migration (PR4) and any future reference can point at a stable row.
INSERT INTO `expense_categories` (`id`, `slug`, `name`, `kind`, `colour_token`, `sort_order`) VALUES
  (1,  'stock-purchase',        'Stock Purchase',        'cost_of_goods', 'forest',  1),
  (2,  'transport-logistics',   'Transport & Logistics', 'operating',     'foliage', 2),
  (3,  'fuel',                  'Fuel',                  'operating',     'gold',    3),
  (4,  'vehicle-repairs',       'Vehicle & Repairs',     'operating',     'clay',    4),
  (5,  'airtime-data',          'Airtime & Data',        'operating',     'forest',  5),
  (6,  'bank-pos-charges',      'Bank & POS Charges',    'operating',     'ink',     6),
  (7,  'staff-welfare',         'Staff & Welfare',       'operating',     'foliage', 7),
  (8,  'government-levies',     'Government & Levies',   'operating',     'tomato',  8),
  (9,  'professional-services', 'Professional Services', 'operating',     'clay',    9),
  (10, 'giving',                'Giving',                'operating',     'gold',    10),
  (11, 'loan-repayment',        'Loan Repayment',        'operating',     'ink',     11),
  (12, 'other',                 'Other',                 'operating',     'tomato',  12)
ON DUPLICATE KEY UPDATE
  `slug`         = VALUES(`slug`),
  `name`         = VALUES(`name`),
  `kind`         = VALUES(`kind`),
  `colour_token` = VALUES(`colour_token`),
  `sort_order`   = VALUES(`sort_order`);

-- Verification:
--   SELECT COUNT(*) FROM expense_categories;                              -- 12
--   SELECT COUNT(*) FROM expense_categories WHERE kind = 'cost_of_goods'; -- 1
--   SHOW CREATE TABLE expenses;                                           -- 3 foreign keys
