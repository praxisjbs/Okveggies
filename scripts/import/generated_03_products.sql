-- OK Veggies: create the 23 wholesale products not already in the catalogue.
-- Idempotent (skips any that already exist by slug). Category is a best guess; recategorise in Products.
-- Price is the product's first legacy sale price, a starting point only.

INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Parsley','parsley','WHL-PARSLEY',80000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='parsley');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Plantain','plantain','WHL-PLANTAIN',250000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='plantain');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 3,1,'Sweet potatoes','sweet-potatoes','WHL-SWEET-POTATOES',100000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='sweet-potatoes');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Carrot','carrot','WHL-CARROT',200000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='carrot');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 4,1,'Pineapple','pineapple','WHL-PINEAPPLE',150000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='pineapple');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 4,1,'Lime','lime','WHL-LIME',75000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='lime');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Basil','basil','WHL-BASIL',100000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='basil');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 4,1,'Banana','banana','WHL-BANANA',225000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='banana');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Green beans','green-beans','WHL-GREEN-BEANS',230000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='green-beans');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 4,1,'Watermelon','watermelon','WHL-WATERMELON',250000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='watermelon');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Pomo','pomo','WHL-POMO',25000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='pomo');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Stock fish','stock-fish','WHL-STOCK-FISH',45000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='stock-fish');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Lemon grass','lemon-grass','WHL-LEMON-GRASS',200000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='lemon-grass');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Coloured Cabbage','coloured-cabbage','WHL-COLOURED-CABBAGE',350000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='coloured-cabbage');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Cucumber','cucumber','WHL-CUCUMBER',50000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='cucumber');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Eforiro','eforiro','WHL-EFORIRO',100000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='eforiro');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Cauliflower','cauliflower','WHL-CAULIFLOWER',500000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='cauliflower');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Coriander','coriander','WHL-CORIANDER',110000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='coriander');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Fresh rosemary','fresh-rosemary','WHL-FRESH-ROSEMARY',220000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='fresh-rosemary');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Locust beans','locust-beans','WHL-LOCUST-BEANS',150000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='locust-beans');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 4,1,'Avocado','avocado','WHL-AVOCADO',1400000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='avocado');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 1,1,'Brocoli','brocoli','WHL-BROCOLI',780000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='brocoli');
INSERT INTO products (category_id,unit_id,name,slug,sku,current_price_subunit,is_active)
SELECT 2,1,'Rosemary','rosemary','WHL-ROSEMARY',120000,1
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM products WHERE slug='rosemary');