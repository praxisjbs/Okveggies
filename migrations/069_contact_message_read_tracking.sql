-- =============================================================================
-- 069_contact_message_read_tracking.sql
-- OK Veggies. The Content and Messages badge stayed lit after the messages had
-- been read.
--
-- The number on the sidebar counted messages whose status was still "new". A
-- message only left "new" when somebody pressed Mark handled, so opening and
-- reading a message changed nothing and the badge could never go down on its
-- own. Reading and answering are different things, so this records them
-- separately:
--
--   read_at, read_by   the first time a colleague opened the message
--   status             still new or handled, still means answered
--
-- The sidebar and the Messages tab now count messages nobody has opened.
--
-- Backfill, honestly: a message that was already handled was read by whoever
-- handled it, and a thread staff started was read by the person who started it.
-- Messages still marked new stay unread, because nothing records that anyone
-- opened them. Opening one clears it, and the Messages tab has a Mark all read
-- button for the first time round.
--
-- Idempotent. Each column, the index and the foreign key are added only when
-- missing, and the backfill only touches rows that are still unread.
-- =============================================================================

START TRANSACTION;

SET @has_read_at := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND COLUMN_NAME = 'read_at'
);
SET @read_at_ddl := IF(
  @has_read_at = 0,
  'ALTER TABLE `contact_messages` ADD COLUMN `read_at` DATETIME NULL AFTER `handled_at`',
  'DO 0'
);
PREPARE read_at_stmt FROM @read_at_ddl;
EXECUTE read_at_stmt;
DEALLOCATE PREPARE read_at_stmt;

SET @has_read_by := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND COLUMN_NAME = 'read_by'
);
SET @read_by_ddl := IF(
  @has_read_by = 0,
  'ALTER TABLE `contact_messages` ADD COLUMN `read_by` BIGINT UNSIGNED NULL AFTER `read_at`',
  'DO 0'
);
PREPARE read_by_stmt FROM @read_by_ddl;
EXECUTE read_by_stmt;
DEALLOCATE PREPARE read_by_stmt;

SET @has_read_index := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages' AND INDEX_NAME = 'ix_contact_messages_unread'
);
SET @read_index_ddl := IF(
  @has_read_index = 0,
  'CREATE INDEX `ix_contact_messages_unread` ON `contact_messages` (`read_at`, `status`)',
  'DO 0'
);
PREPARE read_index_stmt FROM @read_index_ddl;
EXECUTE read_index_stmt;
DEALLOCATE PREPARE read_index_stmt;

SET @has_read_fk := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages'
     AND CONSTRAINT_NAME = 'fk_contact_messages_read_by' AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @read_fk_ddl := IF(
  @has_read_fk = 0,
  'ALTER TABLE `contact_messages` ADD CONSTRAINT `fk_contact_messages_read_by` FOREIGN KEY (`read_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE',
  'DO 0'
);
PREPARE read_fk_stmt FROM @read_fk_ddl;
EXECUTE read_fk_stmt;
DEALLOCATE PREPARE read_fk_stmt;

-- Already answered, or started by staff: somebody has read it.
UPDATE `contact_messages`
   SET `read_at` = COALESCE(`handled_at`, `created_at`),
       `read_by` = `handled_by`
 WHERE `read_at` IS NULL
   AND `status` = 'handled';

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages'
--      AND COLUMN_NAME IN ('read_at', 'read_by');                      -- 2 rows
--   SELECT COUNT(*) FROM contact_messages WHERE status = 'handled' AND read_at IS NULL;   -- 0
