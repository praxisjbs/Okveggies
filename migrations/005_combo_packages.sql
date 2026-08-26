-- Starter bundle and its contents.

INSERT INTO `combo_packages`('id', `name`, `slug`, `sku`, `description`, `price_subunit`, `currency`, `is_featured`, `is_active`)
VALUES
  (1, 'Family Stew Combo', 'family-stew-combo', 'COMBO-STEW-001',
   'Tomatoes and peppers portioned for a family-size stew.',
   700000, 'NGN', TRUE, TRUE)
ON DUPLICATE KEY UPDATE
  name = VALUES(name), description = VALUES(description),
  price_subunit = VALUES(price_subunit), currency = VALUES(currency),
  is_featured = VALUES(is_featured), is_active = VALUES(is_active);

INSERT INTO `combo_package_items`(`combo_package_id`, `product_id`, `quantity`, `unit_id`) VALUES
  ((SELECT id FROM combo_packages WHERE sku = 'COMBO-STEW-001'),
   (SELECT id FROM products WHERE sku = 'VEG-TOM-001'), 2.000,
   (SELECT id FROM units_of_measurement WHERE symbol = 'kg')),
  ((SELECT id FROM combo_packages WHERE sku = 'COMBO-STEW-001'),
   (SELECT id FROM products WHERE sku = 'PEP-RBP-001'), 0.500,
   (SELECT id FROM units_of_measurement WHERE symbol = 'kg')),
  ((SELECT id FROM combo_packages WHERE sku = 'COMBO-STEW-001'),
   (SELECT id FROM products WHERE sku = 'PEP-SBP-001'), 0.500,
   (SELECT id FROM units_of_measurement WHERE symbol = 'kg'))
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO `combo_price_history` (`combo_package_id`, `old_price_subunit`, `new_price_subunit`, `currency`, `change_reason`)
SELECT c.id, NULL, c.price_subunit, c.currency, 'Initial combo seed'
FROM combo_packages c
WHERE c.sku = 'COMBO-STEW-001'
  AND NOT EXISTS (
    SELECT 1 FROM combo_price_history h
    WHERE h.combo_package_id = c.id AND h.change_reason = 'Initial combo seed'
  );