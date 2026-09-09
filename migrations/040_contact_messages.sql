-- M9 contact. The two notification templates a contact message needs.
-- Numbered from the M9 reserved range (040 to 044) so the parallel M7, M8 and
-- M12 branches cannot collide on it. `contact_messages` itself shipped in 001.

START TRANSACTION;

-- The staff alert. PRD Section 15 lists a new contact message among the events
-- the team is told about.
INSERT INTO notification_templates
  (template_key, channel, subject_template, body_template, is_active)
VALUES
  ('admin_new_contact', 'email',
   'New message from {{contact_name}}',
   '{{contact_name}} sent a message through {{source_label}}.\n\nReply to: {{contact_method}}\nSubject: {{subject}}\n\n{{message_preview}}\n\nOpen it in the admin panel before you reply, so the rest of the team can see who is handling it.',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template = VALUES(body_template),
  is_active = VALUES(is_active);

-- The acknowledgement. Sent only when the person left an email address, so a
-- message does not disappear into silence. It repeats nothing they typed, so
-- the mail cannot be used to carry someone else's words to a third party.
INSERT INTO notification_templates
  (template_key, channel, subject_template, body_template, is_active)
VALUES
  ('contact_acknowledgement', 'email',
   'We have your message',
   'Hello {{customer_name}},\n\nThank you for writing to us. Your message reached the team on {{received_at}} and it is on our list.\n\nWe reply within 1 working day, Monday to Saturday. If it is urgent, message us on WhatsApp instead: {{whatsapp_url}}\n\nThere is nothing for you to do. This note is only so you know the message arrived.\n\nOK Veggies',
   TRUE)
ON DUPLICATE KEY UPDATE
  subject_template = VALUES(subject_template),
  body_template = VALUES(body_template),
  is_active = VALUES(is_active);

COMMIT;

-- Verification:
--   SELECT template_key, channel, is_active FROM notification_templates
--    WHERE template_key IN ('admin_new_contact', 'contact_acknowledgement');
--   Expect 2 active email rows, after any number of migration runs.
