-- =============================================================================
-- 047_make_it_right_resolutions.sql
-- OK Veggies. M10 durable refund, account-credit and replacement outcomes.
-- =============================================================================

START TRANSACTION;

SET @okv_has_resolution_amount := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
     AND column_name = 'resolution_amount_subunit'
);
SET @okv_sql := IF(
  @okv_has_resolution_amount = 0,
  'ALTER TABLE issue_reports ADD COLUMN resolution_amount_subunit BIGINT UNSIGNED NULL AFTER resolution_note',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_credit_transaction := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
     AND column_name = 'credit_transaction_id'
);
SET @okv_sql := IF(
  @okv_has_credit_transaction = 0,
  'ALTER TABLE issue_reports ADD COLUMN credit_transaction_id BIGINT UNSIGNED NULL AFTER resolution_amount_subunit',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_replacement_order := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
     AND column_name = 'replacement_order_id'
);
SET @okv_sql := IF(
  @okv_has_replacement_order = 0,
  'ALTER TABLE issue_reports ADD COLUMN replacement_order_id BIGINT UNSIGNED NULL AFTER credit_transaction_id',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_refund_issue := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE() AND table_name = 'refunds'
     AND column_name = 'issue_report_id'
);
SET @okv_sql := IF(
  @okv_has_refund_issue = 0,
  'ALTER TABLE refunds ADD COLUMN issue_report_id BIGINT UNSIGNED NULL AFTER order_id',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_refund_issue_unique := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE() AND table_name = 'refunds'
     AND index_name = 'uq_refunds_issue_report'
);
SET @okv_sql := IF(
  @okv_has_refund_issue_unique = 0,
  'ALTER TABLE refunds ADD UNIQUE INDEX uq_refunds_issue_report (issue_report_id)',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_credit_fk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'issue_reports'
     AND constraint_name = 'fk_issue_reports_credit_transaction'
);
SET @okv_sql := IF(
  @okv_has_credit_fk = 0,
  'ALTER TABLE issue_reports ADD CONSTRAINT fk_issue_reports_credit_transaction FOREIGN KEY (credit_transaction_id) REFERENCES credit_transactions (id) ON DELETE RESTRICT ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_replacement_fk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'issue_reports'
     AND constraint_name = 'fk_issue_reports_replacement_order'
);
SET @okv_sql := IF(
  @okv_has_replacement_fk = 0,
  'ALTER TABLE issue_reports ADD CONSTRAINT fk_issue_reports_replacement_order FOREIGN KEY (replacement_order_id) REFERENCES orders (id) ON DELETE RESTRICT ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_has_refund_issue_fk := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE() AND table_name = 'refunds'
     AND constraint_name = 'fk_refunds_issue_report'
);
SET @okv_sql := IF(
  @okv_has_refund_issue_fk = 0,
  'ALTER TABLE refunds ADD CONSTRAINT fk_refunds_issue_report FOREIGN KEY (issue_report_id) REFERENCES issue_reports (id) ON DELETE RESTRICT ON UPDATE CASCADE',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

CREATE TABLE IF NOT EXISTS issue_report_resolution_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id BIGINT UNSIGNED NOT NULL,
  order_item_id BIGINT UNSIGNED NOT NULL,
  amount_subunit BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_issue_resolution_item_issue
    FOREIGN KEY (issue_id) REFERENCES issue_reports (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_issue_resolution_item_order_item
    FOREIGN KEY (order_item_id) REFERENCES order_items (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE KEY uq_issue_resolution_item (issue_id, order_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Verification:
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
--      AND column_name IN ('resolution_amount_subunit','credit_transaction_id','replacement_order_id'); -- 3 rows
--   SELECT column_name FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'refunds'
--      AND column_name = 'issue_report_id';                                  -- 1 row
--   SELECT index_name, non_unique FROM information_schema.statistics
--    WHERE table_schema = DATABASE() AND table_name = 'refunds'
--      AND index_name = 'uq_refunds_issue_report';                           -- 1 unique row
--   SHOW CREATE TABLE issue_report_resolution_items;                        -- 2 foreign keys
