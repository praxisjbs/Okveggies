-- M9 contact submission identity and staff alert. Numbered after M8 migrations.

SET @contact_token_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'contact_messages'
     AND COLUMN_NAME = 'submission_token_hash'
);
SET @contact_token_ddl := IF(
  @contact_token_col = 0,
  'ALTER TABLE `contact_messages` ADD COLUMN `submission_token_hash` CHAR(64) NULL AFTER `ip_address`',
  'DO 0'
);
PREPARE okv_032_token_col FROM @contact_token_ddl;
EXECUTE okv_032_token_col;
DEALLOCATE PREPARE okv_032_token_col;

SET @contact_token_idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'contact_messages'
     AND INDEX_NAME = 'uq_contact_messages_submission_token'
);
SET @contact_token_idx_ddl := IF(
  @contact_token_idx = 0,
  'ALTER TABLE `contact_messages` ADD UNIQUE INDEX `uq_contact_messages_submission_token` (`submission_token_hash`)',
  'DO 0'
);
PREPARE okv_032_token_idx FROM @contact_token_idx_ddl;
EXECUTE okv_032_token_idx;
DEALLOCATE PREPARE okv_032_token_idx;

START TRANSACTION;

INSERT INTO notification_templates
  (template_key, channel, subject_template, body_template, is_active)
VALUES
  ('admin_new_contact', 'email',
   'New message from {{contact_name}}',
   '{{contact_name}} sent a contact message.\n\nReply through: {{contact_method}}\nSubject: {{subject}}\n\n{{message_preview}}\n\nOpen the message in the admin panel before replying so another team member can see who is handling it.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template = VALUES(body_template),
  is_active = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT COLUMN_NAME FROM information_schema.COLUMNS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages'
--      AND COLUMN_NAME = 'submission_token_hash';
--   Expect 1 row.
--   SELECT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contact_messages'
--      AND INDEX_NAME = 'uq_contact_messages_submission_token';
--   Expect 1 row after any number of migration runs.
--   SELECT template_key, is_active FROM notification_templates
--    WHERE template_key = 'admin_new_contact';
--   Expect 1 active row.
