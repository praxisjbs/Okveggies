-- OK Veggies product seed (enriched with descriptions; Garlic corrected to kg).
-- The canonical seed the app runs is migrations/003_reference_seed.sql +
-- migrations/004_product_seed.sql. This standalone file mirrors them for reference.
-- Money is in subunits (kobo). Categories: 1 Vegetables, 2 Herbs & Spices, 3 Tubers & Roots.
-- Units: 1 Kilogramme, 2 Bunch, 3 Head, 4 Tuber.

INSERT INTO `product_categories` (`id`,`name`,`slug`) VALUES
(1,'Vegetables','vegetables'),(2,'Herbs & Spices','herbs-spices'),(3,'Tubers & Roots','tubers-roots'),(4,'Fruits','fruits'),(5,'Grains & Cereals','grains-cereals');

INSERT INTO `units_of_measurement` (`id`,`name`,`symbol`,`allows_decimal`) VALUES
(1,'Kilogramme','kg',TRUE),(2,'Bunch','bunch',FALSE),(3,'Head','head',FALSE),(4,'Tuber','tuber',FALSE);

INSERT INTO `products` (`id`,`category_id`,`unit_id`,`name`,`slug`,`sku`,`short_description`,`description`,`current_price_subunit`) VALUES
(1,2,2,'Celery','celery','PRD-CELERY-001','Firm, green celery with a clean snap.','Firm, green celery with a clean snap. Sold by the bunch. Good for stocks, stir-fries and blended pepper sauces.',100000),
(2,1,2,'Eforiro (Shoko)','eforiro-shoko','PRD-EFORIR-002','Tender shoko leaves for efo riro and vegetable soups.','Tender shoko leaves for efo riro and vegetable soups. Sold by the bunch, washed and ready to prep.',100000),
(3,2,2,'Fresh Thyme','fresh-thyme','PRD-FRESH--003','Fresh thyme sprigs with a strong, warm aroma.','Fresh thyme sprigs with a strong, warm aroma. Sold by the bunch. A base note for stews, jollof and marinades.',120000),
(4,1,1,'Fresh Tomatoes','fresh-tomatoes','PRD-FRESH--004','Ripe, firm tomatoes with no soft spots, per kilogramme.','Ripe, firm tomatoes with no soft spots, per kilogramme. The base of every stew and jollof. Sourced from Ogun State.',270000),
(5,3,1,'Garlic','garlic','PRD-GARLIC-005','Plump garlic bulbs, per kilogramme.','Plump garlic bulbs, per kilogramme. Peels clean and pounds easily for stews, sauces and marinades.',450000),
(6,3,1,'Ginger','ginger','PRD-GINGER-006','Firm ginger with a sharp bite, per kilogramme.','Firm ginger with a sharp bite, per kilogramme. For pepper soups, marinades, drinks and blended stew bases.',800000),
(7,1,1,'Green bell pepper','green-bell-pepper','PRD-GREEN--007','Firm green bell peppers, per kilogramme.','Firm green bell peppers, per kilogramme. Mild and crunchy for sauces, sautes and garnishes.',550000),
(8,3,1,'Irish Potatoes','irish-potatoes','PRD-IRISH--008','Clean, firm Irish potatoes, per kilogramme.','Clean, firm Irish potatoes, per kilogramme. For frying, boiling, porridge and pepper soup.',250000),
(9,1,3,'Lettuce','lettuce','PRD-LETTUC-009','Crisp lettuce, sold by the head.','Crisp lettuce, sold by the head. For salads, wraps and burgers. Kept cool from farm to door.',100000),
(10,1,1,'Marrow','marrow','PRD-MARROW-010','Tender marrow, per kilogramme.','Tender marrow, per kilogramme. Good for sautes, sauces and light vegetable dishes.',200000),
(11,2,2,'Mint leaf','mint-leaf','PRD-MINT-L-011','Fragrant mint leaves, sold by the bunch.','Fragrant mint leaves, sold by the bunch. For drinks, salads, chutneys and garnishes.',100000),
(12,1,1,'Okro','okro','PRD-OKRO-012','Young, tender okro, per kilogramme.','Young, tender okro, per kilogramme. For okro soup and stews. Cut fresh, no woody pods.',170000),
(13,1,1,'Onion','onion','PRD-ONION-013','Firm onions with dry skins, per kilogramme.','Firm onions with dry skins, per kilogramme. The everyday base for stews, sauces and jollof.',140000),
(14,1,3,'Purple Cabbage','purple-cabbage','PRD-PURPLE-014','Firm purple cabbage, sold by the head.','Firm purple cabbage, sold by the head. Adds colour and crunch to salads, slaws and stir-fries.',450000),
(15,1,1,'Red bell Pepper','red-bell-pepper','PRD-RED-BE-015','Sweet red bell peppers, per kilogramme.','Sweet red bell peppers, per kilogramme. Firm, no soft spots. For sauces, roasting and blended stew bases.',850000),
(16,1,1,'Red habanero','red-habanero','PRD-RED-HA-016','Hot red habanero, per kilogramme.','Hot red habanero, per kilogramme. A sharp heat for pepper sauces and stews. Handle with care.',700000),
(17,1,1,'Rodo','rodo','PRD-RODO-017','Fiery rodo, per kilogramme.','Fiery rodo, per kilogramme. The heat in the classic blended stew base. Sourced weekly at a price we hold.',400000),
(18,1,1,'Shombo','shombo','PRD-SHOMBO-018','Long red shombo peppers, per kilogramme.','Long red shombo peppers, per kilogramme. Mild heat and deep colour for stew and jollof bases.',450000),
(19,1,1,'Spring Onion','spring-onion','PRD-SPRING-019','Fresh spring onions, per kilogramme.','Fresh spring onions, per kilogramme. For garnishes, stir-fries, sauces and fried rice.',120000),
(20,1,1,'Sweet Corn','sweet-corn','PRD-SWEET--020','Fresh sweet corn, per kilogramme.','Fresh sweet corn, per kilogramme. For boiling, roasting, salads and pepper soup.',300000),
(21,1,1,'Tatashe','tatashe','PRD-TATASH-021','Long red tatashe, per kilogramme.','Long red tatashe, per kilogramme. The body and colour of a proper stew base. Firm and sweet, no soft spots.',450000),
(22,1,3,'White Cabbage','white-cabbage','PRD-WHITE--022','Firm white cabbage, sold by the head.','Firm white cabbage, sold by the head. For coleslaw, stir-fries and vegetable sides.',250000),
(23,3,4,'Yam','yam','PRD-YAM-023','Solid, clean yam, sold per tuber.','Solid, clean yam, sold per tuber. For pounded yam, boiled yam, porridge and frying.',450000),
(24,1,1,'Yellow bell Pepper','yellow-bell-pepper','PRD-YELLOW-024','Sweet yellow bell peppers, per kilogramme.','Sweet yellow bell peppers, per kilogramme. Firm and bright for sauces, roasting and salads.',850000);

INSERT INTO `product_images` (`product_id`,`image_url`,`alt_text`,`is_primary`) VALUES
(1,'assets/img/product_images/Celery.jpeg','Celery',TRUE),
(2,'assets/img/product_images/Eforiro (Shoko).jpeg','Eforiro (Shoko)',TRUE),
(3,'assets/img/product_images/Fresh Thyme.jpeg','Fresh Thyme',TRUE),
(4,'assets/img/product_images/Fresh Tomatoes.jpeg','Fresh Tomatoes',TRUE),
(5,'assets/img/product_images/Garlic.jpeg','Garlic',TRUE),
(6,'assets/img/product_images/Ginger.jpeg','Ginger',TRUE),
(7,'assets/img/product_images/Green bell pepper.jpeg','Green bell pepper',TRUE),
(8,'assets/img/product_images/Irish Potatoes.jpeg','Irish Potatoes',TRUE),
(9,'assets/img/product_images/Lettuce.jpeg','Lettuce',TRUE),
(10,'assets/img/product_images/Marrow.jpeg','Marrow',TRUE),
(11,'assets/img/product_images/Mint leaf.jpeg','Mint leaf',TRUE),
(12,'assets/img/product_images/Okro.jpeg','Okro',TRUE),
(13,'assets/img/product_images/Onion.jpeg','Onion',TRUE),
(14,'assets/img/product_images/Purple Cabbage.jpeg','Purple Cabbage',TRUE),
(15,'assets/img/product_images/Red bell Pepper.jpeg','Red bell Pepper',TRUE),
(16,'assets/img/product_images/Red habanero.jpeg','Red habanero',TRUE),
(17,'assets/img/product_images/Rodo.jpeg','Rodo',TRUE),
(18,'assets/img/product_images/Shombo.jpeg','Shombo',TRUE),
(19,'assets/img/product_images/Spring Onion.jpeg','Spring Onion',TRUE),
(20,'assets/img/product_images/Sweet Corn.jpeg','Sweet Corn',TRUE),
(21,'assets/img/product_images/Tatashe.jpeg','Tatashe',TRUE),
(22,'assets/img/product_images/White Cabbage.jpeg','White Cabbage',TRUE),
(23,'assets/img/product_images/Yam.jpeg','Yam',TRUE),
(24,'assets/img/product_images/Yellow bell Pepper.jpeg','Yellow bell Pepper',TRUE);

INSERT INTO `product_price_history` (`product_id`,`new_price_subunit`,`change_reason`) VALUES
(1,100000,'Initial pricing'),
(2,100000,'Initial pricing'),
(3,120000,'Initial pricing'),
(4,270000,'Initial pricing'),
(5,450000,'Initial pricing'),
(6,800000,'Initial pricing'),
(7,550000,'Initial pricing'),
(8,250000,'Initial pricing'),
(9,100000,'Initial pricing'),
(10,200000,'Initial pricing'),
(11,100000,'Initial pricing'),
(12,170000,'Initial pricing'),
(13,140000,'Initial pricing'),
(14,450000,'Initial pricing'),
(15,850000,'Initial pricing'),
(16,700000,'Initial pricing'),
(17,400000,'Initial pricing'),
(18,450000,'Initial pricing'),
(19,120000,'Initial pricing'),
(20,300000,'Initial pricing'),
(21,450000,'Initial pricing'),
(22,250000,'Initial pricing'),
(23,450000,'Initial pricing'),
(24,850000,'Initial pricing');

INSERT INTO `product_availability` (`product_id`,`availability_status`,`available_quantity`) VALUES
(1,'available',100.000),
(2,'available',100.000),
(3,'available',100.000),
(4,'available',100.000),
(5,'available',100.000),
(6,'available',100.000),
(7,'available',100.000),
(8,'available',100.000),
(9,'available',100.000),
(10,'available',100.000),
(11,'available',100.000),
(12,'available',100.000),
(13,'available',100.000),
(14,'available',100.000),
(15,'available',100.000),
(16,'available',100.000),
(17,'available',100.000),
(18,'available',100.000),
(19,'available',100.000),
(20,'available',100.000),
(21,'available',100.000),
(22,'available',100.000),
(23,'available',100.000),
(24,'available',100.000);