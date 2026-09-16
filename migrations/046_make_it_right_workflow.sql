-- =============================================================================
-- 046_make_it_right_workflow.sql
-- OK Veggies. M10 staff ownership, terminal notes, and append-only history.
-- =============================================================================

START TRANSACTION;

SET @okv_has_handled_at := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'issue_reports'
     AND column_name = 'handled_at'
);
SET @okv_sql := IF(
  @okv_has_handled_at = 0,
  'ALTER TABLE issue_reports ADD COLUMN handled_at DATETIME NULL AFTER handled_by',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

SET @okv_resolution_note_type := (
  SELECT data_type FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'issue_reports'
     AND column_name = 'resolution_note'
   LIMIT 1
);
SET @okv_sql := IF(
  COALESCE(@okv_resolution_note_type, '') <> 'text',
  'ALTER TABLE issue_reports MODIFY COLUMN resolution_note TEXT NULL',
  'SELECT 1'
);
PREPARE okv_stmt FROM @okv_sql;
EXECUTE okv_stmt;
DEALLOCATE PREPARE okv_stmt;

CREATE TABLE IF NOT EXISTS issue_report_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  issue_id BIGINT UNSIGNED NOT NULL,
  old_status VARCHAR(30) NULL,
  new_status VARCHAR(30) NOT NULL,
  action VARCHAR(40) NOT NULL,
  note TEXT NULL,
  acted_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_issue_report_history_issue
    FOREIGN KEY (issue_id) REFERENCES issue_reports (id)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_issue_report_history_actor
    FOREIGN KEY (acted_by) REFERENCES users (id)
    ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX idx_issue_report_history_issue (issue_id, created_at, id),
  INDEX idx_issue_report_history_status (new_status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Existing reports pre-date their dedicated history. Preserve their original
-- report time and actor once without changing the report itself.
INSERT INTO issue_report_history
  (issue_id, old_status, new_status, action, acted_by, created_at)
SELECT i.id, NULL, 'open', 'reported', i.user_id, i.created_at
  FROM issue_reports i
 WHERE NOT EXISTS (
   SELECT 1 FROM issue_report_history h
    WHERE h.issue_id = i.id AND h.action = 'reported'
 );

INSERT INTO issue_report_history
  (issue_id, old_status, new_status, action, note, acted_by, created_at)
SELECT i.id, 'in_progress', i.status,
       CASE WHEN i.status = 'declined' THEN 'declined' ELSE 'resolved' END,
       i.resolution_note, i.handled_by, i.resolved_at
  FROM issue_reports i
 WHERE i.resolved_at IS NOT NULL
   AND i.status IN ('resolved', 'declined')
   AND NOT EXISTS (
     SELECT 1 FROM issue_report_history h
      WHERE h.issue_id = i.id AND h.action IN ('resolved', 'declined')
   );

COMMIT;

-- Verification:
--   SELECT column_name, data_type FROM information_schema.columns
--    WHERE table_schema = DATABASE() AND table_name = 'issue_reports'
--      AND column_name IN ('handled_at','resolution_note');                   -- 2 rows
--   SHOW CREATE TABLE issue_report_history;                                  -- 2 foreign keys
--   SELECT issue_id, action, new_status, acted_by, created_at
--     FROM issue_report_history ORDER BY issue_id, created_at, id;
