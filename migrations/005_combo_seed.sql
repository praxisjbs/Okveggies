-- =============================================================================
-- 005_combo_seed.sql
-- OK Veggies. The Stew Combo. A blended pepper-tomato base for a pot of stew.
-- Component total at seed prices is 1,755,000 subunits (17,550 naira). The
-- combo sells for 1,690,000 subunits (16,900 naira), a visible saving.
-- Admin can reprice it any time in the combo builder. Idempotent.
-- =============================================================================

START TRANSACTION;

INSERT INTO combo_packages
  (id, name, slug, sku, description, price_subunit, currency, image_url, is_featured, is_active)
VALUES
  (1, 'The Stew Combo', 'the-stew-combo', 'CMB-STEW-001',
   'Everything for this week''s pot of stew, in one basket. Firm tomatoes, tatashe, rodo, shombo, onions and ginger. Priced together to save you money against buying each item on its own.',
   1690000, 'NGN', 'assets/img/product_images/Fresh Tomatoes.jpeg', TRUE, TRUE)
ON DUPLICATE KEY UPDATE name=VALUES(name), description=VALUES(description), price_subunit=VALUES(price_subunit), image_url=VALUES(image_url), is_featured=VALUES(is_featured);

-- Components (all sold by the kilogramme, unit_id 1)
INSERT INTO combo_package_items (combo_package_id, product_id, quantity, unit_id) VALUES
  (1,  4, 2.000, 1),   -- Fresh Tomatoes 2 kg
  (1, 21, 1.000, 1),   -- Tatashe 1 kg
  (1, 17, 0.500, 1),   -- Rodo 0.5 kg
  (1, 18, 0.500, 1),   -- Shombo 0.5 kg
  (1, 13, 1.000, 1),   -- Onion 1 kg
  (1,  6, 0.250, 1)    -- Ginger 0.25 kg
ON DUPLICATE KEY UPDATE quantity=VALUES(quantity), unit_id=VALUES(unit_id);

INSERT INTO combo_price_history (combo_package_id, new_price_subunit, change_reason)
SELECT 1, 1690000, 'Initial pricing'
WHERE NOT EXISTS (SELECT 1 FROM combo_price_history WHERE combo_package_id = 1);

COMMIT;

-- Verification: SELECT COUNT(*) FROM combo_package_items WHERE combo_package_id = 1; -- 6
