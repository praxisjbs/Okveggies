-- MySQL DAYOFWEEK convention used here: 1=Sunday ... 7=Saturday.
-- =============================================================================
-- 003_reference_seed.sql
-- OK Veggies. Reference data: categories, units, delivery days, Lagos zones,
-- and the configurable order/site settings. Idempotent.
-- Day of week convention: Monday = 1 ... Sunday = 7.
-- =============================================================================

START TRANSACTION;

-- Categories (5 product categories; Combos and Kitchen Runs have their own tables)
INSERT INTO product_categories (id, name, slug, description, sort_order) VALUES
  (1, 'Vegetables',      'vegetables',      'Fresh vegetables, sold by the kilogramme.', 1),
  (2, 'Herbs & Spices',  'herbs-spices',    'Fresh herbs and aromatics, sold by the bunch.', 2),
  (3, 'Tubers & Roots',  'tubers-roots',    'Yam, potatoes, ginger and garlic.', 3),
  (4, 'Fruits',          'fruits',          'Fresh fruit, sold by the head or the bunch.', 4),
  (5, 'Grains & Cereals','grains-cereals',  'Rice, beans and pantry staples.', 5)
ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), description = VALUES(description), sort_order = VALUES(sort_order);

-- Units of measurement. Only the kilogramme allows decimal quantities.
INSERT INTO units_of_measurement (id, name, symbol, allows_decimal) VALUES
  (1, 'Kilogramme', 'kg',    TRUE),
  (2, 'Bunch',      'bunch', FALSE),
  (3, 'Head',       'head',  FALSE),
  (4, 'Tuber',      'tuber', FALSE)
ON DUPLICATE KEY UPDATE name = VALUES(name), symbol = VALUES(symbol), allows_decimal = VALUES(allows_decimal);

-- Allowed delivery days.
-- Households: Monday, Wednesday, Thursday, Saturday.
-- Businesses: Tuesday, Friday (restaurant and mart supply).
INSERT INTO allowed_delivery_days (customer_type, day_of_week, is_active, cutoff_time, minimum_lead_days) VALUES
  ('household', 1, TRUE, '16:00:00', 1),
  ('household', 3, TRUE, '16:00:00', 1),
  ('household', 4, TRUE, '16:00:00', 1),
  ('household', 6, TRUE, '16:00:00', 1),
  ('business',  2, TRUE, '16:00:00', 1),
  ('business',  5, TRUE, '16:00:00', 1)
ON DUPLICATE KEY UPDATE is_active = VALUES(is_active), cutoff_time = VALUES(cutoff_time), minimum_lead_days = VALUES(minimum_lead_days);

-- Delivery zones across Lagos. Admin-editable; these are starting placeholders.
INSERT INTO delivery_zones (name, slug, sort_order) VALUES
  ('Lekki Phase 1', 'lekki-phase-1', 1),
  ('Lekki Phase 2', 'lekki-phase-2', 2),
  ('Ajah', 'ajah', 3),
  ('Sangotedo', 'sangotedo', 4),
  ('Ibeju-Lekki', 'ibeju-lekki', 5),
  ('Victoria Island', 'victoria-island', 6),
  ('Ikoyi', 'ikoyi', 7),
  ('Lagos Island', 'lagos-island', 8),
  ('Yaba', 'yaba', 9),
  ('Surulere', 'surulere', 10),
  ('Apapa', 'apapa', 11),
  ('Ebute Metta', 'ebute-metta', 12),
  ('Ikeja', 'ikeja', 13),
  ('Maryland', 'maryland', 14),
  ('Ojota', 'ojota', 15),
  ('Ketu', 'ketu', 16),
  ('Magodo', 'magodo', 17),
  ('Gbagada', 'gbagada', 18),
  ('Ogudu', 'ogudu', 19),
  ('Oshodi', 'oshodi', 20),
  ('Isolo', 'isolo', 21),
  ('Festac', 'festac', 22),
  ('Amuwo Odofin', 'amuwo-odofin', 23),
  ('Agege', 'agege', 24),
  ('Egbeda', 'egbeda', 25),
  ('Ikotun', 'ikotun', 26),
  ('Alimosho', 'alimosho', 27),
  ('Ojodu Berger', 'ojodu-berger', 28),
  ('Ikorodu', 'ikorodu', 29),
  ('Epe', 'epe', 30)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);

-- Configurable order and site settings. Everything here is editable in the
-- admin Settings screen; nothing is hardcoded in the application.
INSERT INTO site_settings (setting_key, setting_value, value_type, is_public) VALUES
  ('business_name',                     'OK Veggies',                                  'string', TRUE),
  ('business_tagline',                  'Sourced right. Priced right. Delivered right.','string', TRUE),
  ('currency',                          'NGN',                                         'string', TRUE),
  ('source_regions',                    'Ogun State, Jos',                             'string', TRUE),
  ('support_email',                     'hello@okveggies.com.ng',                      'string', TRUE),
  ('support_whatsapp_number',           '2348000000000',                               'string', TRUE),
  ('order_number_prefix',               'OKV',                                         'string', FALSE),
  ('deposit_percentage_default',        '30',                                          'int',    TRUE),
  ('delivery_cutoff_time',              '16:00',                                       'string', TRUE),
  ('delivery_min_lead_days',            '1',                                           'int',    TRUE),
  ('min_order_subunit',                 '0',                                           'int',    FALSE),
  ('pay_on_delivery_requires_activation','true',                                       'bool',   FALSE)
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), value_type = VALUES(value_type), is_public = VALUES(is_public);

COMMIT;

-- Verification:
--   SELECT COUNT(*) FROM product_categories;  -- 5
--   SELECT COUNT(*) FROM units_of_measurement; -- 4
--   SELECT COUNT(*) FROM delivery_zones;       -- 30
--   SELECT customer_type, COUNT(*) FROM allowed_delivery_days GROUP BY customer_type;