-- =============================================================================
-- 004_product_seed.sql
-- OK Veggies. The 24 launch products with descriptions in the brand voice,
-- their images, an initial price-history row, and availability. Garlic and
-- Irish potatoes are sold by the kilogramme. Money is in subunits (kobo).
-- Idempotent.
-- =============================================================================

START TRANSACTION;

INSERT INTO products
  (id, category_id, unit_id, name, slug, sku, short_description, description,
   current_price_subunit, minimum_quantity, quantity_increment, is_featured, is_active)
VALUES
  (1, 2, 2, 'Celery', 'celery', 'PRD-CELERY-001', 'Crisp celery, sold by the bunch.', 'Firm, green celery with a clean snap. Sold by the bunch. Good for stocks, stir-fries and blended pepper sauces.', 100000, 1.000, 1.000, 0, TRUE),
  (2, 1, 2, 'Eforiro (Shoko)', 'eforiro-shoko', 'PRD-EFORIR-002', 'Fresh shoko greens, by the bunch.', 'Tender shoko leaves for efo riro and vegetable soups. Sold by the bunch, washed and ready to prep.', 100000, 1.000, 1.000, 0, TRUE),
  (3, 2, 2, 'Fresh Thyme', 'fresh-thyme', 'PRD-FRESH--003', 'Aromatic fresh thyme, by the bunch.', 'Fresh thyme sprigs with a strong, warm aroma. Sold by the bunch. A base note for stews, jollof and marinades.', 120000, 1.000, 1.000, 0, TRUE),
  (4, 1, 1, 'Fresh Tomatoes', 'fresh-tomatoes', 'PRD-FRESH--004', 'Firm fresh tomatoes, per kilogramme.', 'Ripe, firm tomatoes with no soft spots, per kilogramme. The base of every stew and jollof. Sourced from Ogun State.', 270000, 0.500, 0.500, 1, TRUE),
  (5, 3, 1, 'Garlic', 'garlic', 'PRD-GARLIC-005', 'Fresh garlic, per kilogramme.', 'Plump garlic bulbs, per kilogramme. Peels clean and pounds easily for stews, sauces and marinades.', 450000, 0.500, 0.500, 0, TRUE),
  (6, 3, 1, 'Ginger', 'ginger', 'PRD-GINGER-006', 'Fresh ginger root, per kilogramme.', 'Firm ginger with a sharp bite, per kilogramme. For pepper soups, marinades, drinks and blended stew bases.', 800000, 0.500, 0.500, 0, TRUE),
  (7, 1, 1, 'Green bell pepper', 'green-bell-pepper', 'PRD-GREEN--007', 'Green bell pepper, per kilogramme.', 'Firm green bell peppers, per kilogramme. Mild and crunchy for sauces, sautes and garnishes.', 550000, 0.500, 0.500, 0, TRUE),
  (8, 3, 1, 'Irish Potatoes', 'irish-potatoes', 'PRD-IRISH--008', 'Irish potatoes, per kilogramme.', 'Clean, firm Irish potatoes, per kilogramme. For frying, boiling, porridge and pepper soup.', 250000, 0.500, 0.500, 0, TRUE),
  (9, 1, 3, 'Lettuce', 'lettuce', 'PRD-LETTUC-009', 'Fresh lettuce, per head.', 'Crisp lettuce, sold by the head. For salads, wraps and burgers. Kept cool from farm to door.', 100000, 1.000, 1.000, 0, TRUE),
  (10, 1, 1, 'Marrow', 'marrow', 'PRD-MARROW-010', 'Fresh marrow, per kilogramme.', 'Tender marrow, per kilogramme. Good for sautes, sauces and light vegetable dishes.', 200000, 0.500, 0.500, 0, TRUE),
  (11, 2, 2, 'Mint leaf', 'mint-leaf', 'PRD-MINT-L-011', 'Fresh mint, by the bunch.', 'Fragrant mint leaves, sold by the bunch. For drinks, salads, chutneys and garnishes.', 100000, 1.000, 1.000, 0, TRUE),
  (12, 1, 1, 'Okro', 'okro', 'PRD-OKRO-012', 'Fresh okro, per kilogramme.', 'Young, tender okro, per kilogramme. For okro soup and stews. Cut fresh, no woody pods.', 170000, 0.500, 0.500, 0, TRUE),
  (13, 1, 1, 'Onion', 'onion', 'PRD-ONION-013', 'Onions, per kilogramme.', 'Firm onions with dry skins, per kilogramme. The everyday base for stews, sauces and jollof.', 140000, 0.500, 0.500, 1, TRUE),
  (14, 1, 3, 'Purple Cabbage', 'purple-cabbage', 'PRD-PURPLE-014', 'Purple cabbage, per head.', 'Firm purple cabbage, sold by the head. Adds colour and crunch to salads, slaws and stir-fries.', 450000, 1.000, 1.000, 0, TRUE),
  (15, 1, 1, 'Red bell Pepper', 'red-bell-pepper', 'PRD-RED-BE-015', 'Red bell pepper, per kilogramme.', 'Sweet red bell peppers, per kilogramme. Firm, no soft spots. For sauces, roasting and blended stew bases.', 850000, 0.500, 0.500, 1, TRUE),
  (16, 1, 1, 'Red habanero', 'red-habanero', 'PRD-RED-HA-016', 'Red habanero, per kilogramme.', 'Hot red habanero, per kilogramme. A sharp heat for pepper sauces and stews. Handle with care.', 700000, 0.500, 0.500, 0, TRUE),
  (17, 1, 1, 'Rodo', 'rodo', 'PRD-RODO-017', 'Rodo (scotch bonnet), per kilogramme.', 'Fiery rodo, per kilogramme. The heat in the classic blended stew base. Sourced weekly at a price we hold.', 400000, 0.500, 0.500, 1, TRUE),
  (18, 1, 1, 'Shombo', 'shombo', 'PRD-SHOMBO-018', 'Shombo (long red pepper), per kilogramme.', 'Long red shombo peppers, per kilogramme. Mild heat and deep colour for stew and jollof bases.', 450000, 0.500, 0.500, 0, TRUE),
  (19, 1, 1, 'Spring Onion', 'spring-onion', 'PRD-SPRING-019', 'Spring onions, per kilogramme.', 'Fresh spring onions, per kilogramme. For garnishes, stir-fries, sauces and fried rice.', 120000, 0.500, 0.500, 0, TRUE),
  (20, 1, 1, 'Sweet Corn', 'sweet-corn', 'PRD-SWEET--020', 'Sweet corn, per kilogramme.', 'Fresh sweet corn, per kilogramme. For boiling, roasting, salads and pepper soup.', 300000, 0.500, 0.500, 0, TRUE),
  (21, 1, 1, 'Tatashe', 'tatashe', 'PRD-TATASH-021', 'Tatashe (red bell), per kilogramme.', 'Long red tatashe, per kilogramme. The body and colour of a proper stew base. Firm and sweet, no soft spots.', 450000, 0.500, 0.500, 1, TRUE),
  (22, 1, 3, 'White Cabbage', 'white-cabbage', 'PRD-WHITE--022', 'White cabbage, per head.', 'Firm white cabbage, sold by the head. For coleslaw, stir-fries and vegetable sides.', 250000, 1.000, 1.000, 0, TRUE),
  (23, 3, 4, 'Yam', 'yam', 'PRD-YAM-023', 'Yam, per tuber.', 'Solid, clean yam, sold per tuber. For pounded yam, boiled yam, porridge and frying.', 450000, 1.000, 1.000, 0, TRUE),
  (24, 1, 1, 'Yellow bell Pepper', 'yellow-bell-pepper', 'PRD-YELLOW-024', 'Sweet yellow bell pepper, per kilogramme.', 'Sweet yellow bell peppers, per kilogramme. Firm and bright for sauces, roasting and salads.', 850000, 0.500, 0.500, 0, TRUE)
ON DUPLICATE KEY UPDATE category_id=VALUES(category_id), unit_id=VALUES(unit_id), name=VALUES(name), short_description=VALUES(short_description), description=VALUES(description), current_price_subunit=VALUES(current_price_subunit), minimum_quantity=VALUES(minimum_quantity), quantity_increment=VALUES(quantity_increment), is_featured=VALUES(is_featured);

INSERT INTO product_images (product_id, image_url, alt_text, sort_order, is_primary) VALUES
  (1, 'assets/img/product_images/Celery.jpeg', 'Celery', 0, TRUE),
  (2, 'assets/img/product_images/Eforiro (Shoko).jpeg', 'Eforiro (Shoko)', 0, TRUE),
  (3, 'assets/img/product_images/Fresh Thyme.jpeg', 'Fresh Thyme', 0, TRUE),
  (4, 'assets/img/product_images/Fresh Tomatoes.jpeg', 'Fresh Tomatoes', 0, TRUE),
  (5, 'assets/img/product_images/Garlic.jpeg', 'Garlic', 0, TRUE),
  (6, 'assets/img/product_images/Ginger.jpeg', 'Ginger', 0, TRUE),
  (7, 'assets/img/product_images/Green bell pepper.jpeg', 'Green bell pepper', 0, TRUE),
  (8, 'assets/img/product_images/Irish Potatoes.jpeg', 'Irish Potatoes', 0, TRUE),
  (9, 'assets/img/product_images/Lettuce.jpeg', 'Lettuce', 0, TRUE),
  (10, 'assets/img/product_images/Marrow.jpeg', 'Marrow', 0, TRUE),
  (11, 'assets/img/product_images/Mint leaf.jpeg', 'Mint leaf', 0, TRUE),
  (12, 'assets/img/product_images/Okro.jpeg', 'Okro', 0, TRUE),
  (13, 'assets/img/product_images/Onion.jpeg', 'Onion', 0, TRUE),
  (14, 'assets/img/product_images/Purple Cabbage.jpeg', 'Purple Cabbage', 0, TRUE),
  (15, 'assets/img/product_images/Red bell Pepper.jpeg', 'Red bell Pepper', 0, TRUE),
  (16, 'assets/img/product_images/Red habanero.jpeg', 'Red habanero', 0, TRUE),
  (17, 'assets/img/product_images/Rodo.jpeg', 'Rodo', 0, TRUE),
  (18, 'assets/img/product_images/Shombo.jpeg', 'Shombo', 0, TRUE),
  (19, 'assets/img/product_images/Spring Onion.jpeg', 'Spring Onion', 0, TRUE),
  (20, 'assets/img/product_images/Sweet Corn.jpeg', 'Sweet Corn', 0, TRUE),
  (21, 'assets/img/product_images/Tatashe.jpeg', 'Tatashe', 0, TRUE),
  (22, 'assets/img/product_images/White Cabbage.jpeg', 'White Cabbage', 0, TRUE),
  (23, 'assets/img/product_images/Yam.jpeg', 'Yam', 0, TRUE),
  (24, 'assets/img/product_images/Yellow bell Pepper.jpeg', 'Yellow bell Pepper', 0, TRUE)
ON DUPLICATE KEY UPDATE image_url=VALUES(image_url), alt_text=VALUES(alt_text);

INSERT INTO product_price_history (product_id, new_price_subunit, change_reason)
SELECT p.id, p.current_price_subunit, 'Initial pricing' FROM products p
WHERE p.id BETWEEN 1 AND 24 AND NOT EXISTS (SELECT 1 FROM product_price_history h WHERE h.product_id = p.id);

INSERT INTO product_availability (product_id, availability_status, available_quantity) VALUES
  (1, 'available', 100.000),
  (2, 'available', 100.000),
  (3, 'available', 100.000),
  (4, 'available', 100.000),
  (5, 'available', 100.000),
  (6, 'available', 100.000),
  (7, 'available', 100.000),
  (8, 'available', 100.000),
  (9, 'available', 100.000),
  (10, 'available', 100.000),
  (11, 'available', 100.000),
  (12, 'available', 100.000),
  (13, 'available', 100.000),
  (14, 'available', 100.000),
  (15, 'available', 100.000),
  (16, 'available', 100.000),
  (17, 'available', 100.000),
  (18, 'available', 100.000),
  (19, 'available', 100.000),
  (20, 'available', 100.000),
  (21, 'available', 100.000),
  (22, 'available', 100.000),
  (23, 'available', 100.000),
  (24, 'available', 100.000)
ON DUPLICATE KEY UPDATE availability_status=VALUES(availability_status);

COMMIT;

-- Verification: SELECT COUNT(*) FROM products; -- 24