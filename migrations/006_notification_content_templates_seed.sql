// placeholders for template variables. 

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


INSERT INTO `notification_templates`('template_key', 'channel', 'subject_template', 'body_template', 'is_active') VALUES
  ('order_confirmation_email', 'email', 'Order {{order_number}} received',
   'Hello {{first_name}}, we have received order {{order_number}}. Total: {{order_total}}.', TRUE),
  ('order_status_email', 'email', 'Order {{order_number}} update',
   'Your order status is now {{order_status}}.', TRUE),
  ('payment_success_email', 'email', 'Payment received for {{order_number}}',
   'We received {{amount_paid}} for order {{order_number}}.', TRUE),
  ('delivery_reminder_sms', 'sms', NULL,
   'Reminder: order {{order_number}} is scheduled for delivery on {{delivery_date}}.', TRUE),
  ('refund_update_email', 'email', 'Refund update for {{order_number}}',
   'Your refund status is now {{refund_status}}.', TRUE)
ON DUPLICATE KEY UPDATE
  channel = VALUES(channel), subject_template = VALUES(subject_template),
  body_template = VALUES(body_template), is_active = VALUES(is_active);


  -- "Goes well with" pairings for the stew-base products ------------------------
INSERT IGNORE INTO product_pairings (product_id, paired_product_id, sort_order) VALUES
  (4, 21, 1), (4, 17, 2), (4, 13, 3), (4, 18, 4), (4, 6, 5),
  (21, 4, 1), (21, 17, 2), (21, 13, 3), (21, 18, 4),
  (17, 4, 1), (17, 21, 2), (17, 13, 3), (17, 6, 4),
  (13, 4, 1), (13, 21, 2), (13, 17, 3), (13, 5, 4),
  (15, 4, 1), (15, 13, 2), (15, 6, 3);

COMMIT;

-- Verification: SELECT COUNT(*) FROM content_pages; SELECT COUNT(*) FROM notification_templates;