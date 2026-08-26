-- =============================================================================
-- 006_content_and_templates_seed.sql
-- OK Veggies. Editable content pages, transactional email templates, and a set
-- of "goes well with" product pairings. Copy here is a starting point the admin
-- edits in the Content module. Plain language, no jargon, no em dash. Idempotent.
-- =============================================================================

START TRANSACTION;

-- Content pages (placeholder copy, editable in admin) -------------------------
INSERT INTO content_pages (slug, title, body, is_published) VALUES
  ('about', 'Our Story',
   'OK Veggies started as a phone number people trusted. We source fresh produce from farms we have checked ourselves in Ogun State and Jos, pack it properly, and bring it on the day you asked for. Sourced right. Priced right. Delivered right.', TRUE),
  ('how-it-works', 'How It Works',
   'Pick your items or a combo, choose a delivery day, and pay in full or with a deposit. We source it, weigh it right, and bring it to your door. You can follow every order from the moment it is placed.', TRUE),
  ('faq', 'Questions and Answers',
   'Answers to the questions we hear most, about pricing, delivery days, payment and returns. If your question is not here, tap the support button and reach us on WhatsApp.', TRUE),
  ('terms', 'Terms of Service',
   'These terms cover how you use the OK Veggies website and place orders. This is placeholder copy to be replaced with the reviewed terms before launch.', TRUE),
  ('privacy', 'Privacy Policy',
   'How we handle your details. We collect only what we need to take your order and deliver it. This is placeholder copy to be replaced with the reviewed policy before launch.', TRUE),
  ('delivery-policy', 'Delivery Policy',
   'Households get Monday, Wednesday, Thursday and Saturday. Restaurant and mart supply runs on Tuesday and Friday. The delivery fee is arranged and settled on delivery. This is placeholder copy to be finalised before launch.', TRUE)
ON DUPLICATE KEY UPDATE title = VALUES(title), body = VALUES(body);

-- Transactional email templates ----------------------------------------------
INSERT INTO notification_templates (template_key, channel, subject_template, body_template, is_active) VALUES
  ('order_placed', 'email',
   'We have your order {{order_number}}',
   'Hi {{customer_name}}, we have your order {{order_number}}. Follow it here any time: {{order_trail_url}}. We will let you know the moment it is on the way.', TRUE),
  ('payment_confirmed', 'email',
   'Payment received for {{order_number}}',
   'Hi {{customer_name}}, we have received your payment for order {{order_number}}. Thank you. Follow your order here: {{order_trail_url}}.', TRUE),
  ('deposit_received', 'email',
   'Deposit received for {{order_number}}',
   'Hi {{customer_name}}, we have received your deposit for order {{order_number}}. The balance is settled on delivery. Follow your order here: {{order_trail_url}}.', TRUE),
  ('order_dispatched', 'email',
   'Your order {{order_number}} is on the way',
   'Hi {{customer_name}}, order {{order_number}} is on the way to you. Follow it here: {{order_trail_url}}.', TRUE),
  ('order_delivered', 'email',
   'Delivered: {{order_number}}',
   'Hi {{customer_name}}, order {{order_number}} has been delivered. If anything is not right, tell us and we will make it right.', TRUE)
ON DUPLICATE KEY UPDATE subject_template = VALUES(subject_template), body_template = VALUES(body_template), is_active = VALUES(is_active);

-- "Goes well with" pairings for the stew-base products ------------------------
INSERT IGNORE INTO product_pairings (product_id, paired_product_id, sort_order) VALUES
  (4, 21, 1), (4, 17, 2), (4, 13, 3), (4, 18, 4), (4, 6, 5),
  (21, 4, 1), (21, 17, 2), (21, 13, 3), (21, 18, 4),
  (17, 4, 1), (17, 21, 2), (17, 13, 3), (17, 6, 4),
  (13, 4, 1), (13, 21, 2), (13, 17, 3), (13, 5, 4),
  (15, 4, 1), (15, 13, 2), (15, 6, 3);

COMMIT;

-- Verification: SELECT COUNT(*) FROM content_pages; SELECT COUNT(*) FROM notification_templates;
