-- OK Veggies: the 69 September-October invoices as paid, delivered historical orders.
-- Run customers (02) and products (03) first. Run ONCE (re-run needs the wipe again).
-- Revenue = cash received (sum of paid amounts); the one partially-paid invoice keeps its balance as Outstanding.

START TRANSACTION;

-- INV550/601  VSP Lounge  2026-09-01  total 38295000 paid 38295000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26001',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',38295000,38295000,38295000,0,'2026-09-01','Legacy invoice INV550/601','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-01 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',2.0,150000,300000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',9.0,250000,2250000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',50.0,240000,12000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.0,400000,2000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',4.0,80000,320000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Irish Potatoes' ORDER BY id LIMIT 1),'Irish Potatoes','WHL-IRISH-POTATOES','unit',5.0,250000,1250000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',7.0,200000,1400000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',20.0,150000,3000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',5.0,200000,1000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',2.0,150000,300000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lime' ORDER BY id LIMIT 1),'Lime','WHL-LIME','unit',2.0,75000,150000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',3.0,100000,300000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,750000,1500000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,110000,110000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',3.0,110000,330000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Celery' ORDER BY id LIMIT 1),'Celery','WHL-CELERY','unit',1.0,100000,100000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',2.0,150000,300000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',1.0,225000,225000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',2.0,230000,460000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Watermelon' ORDER BY id LIMIT 1),'Watermelon','WHL-WATERMELON','unit',1.0,250000,250000,'2026-09-01 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,450000,450000,'2026-09-01 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-01 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-001',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',38295000,38295000,'NGN','paid','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-001','live','success',38295000,38295000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00');

-- INV602  VSP Lounge  2026-09-01  total 3465000 paid 3465000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26002',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',3465000,3465000,3465000,0,'2026-09-01','Legacy invoice INV602','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-01 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Pomo' ORDER BY id LIMIT 1),'Pomo','WHL-POMO','unit',30.0,25000,750000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Stock fish' ORDER BY id LIMIT 1),'Stock fish','WHL-STOCK-FISH','unit',27.0,45000,1215000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',10.0,150000,1500000,'2026-09-01 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-01 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-002',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',3465000,3465000,'NGN','paid','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-002','live','success',3465000,3465000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00');

-- INV603  Citysubs  2026-09-01  total 9200000 paid 9200000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26003',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',9200000,9200000,9200000,0,'2026-09-01','Legacy invoice INV603','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-01 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-01 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lemon grass' ORDER BY id LIMIT 1),'Lemon grass','WHL-LEMON-GRASS','unit',1.0,200000,200000,'2026-09-01 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-01 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-003',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',9200000,9200000,'NGN','paid','2026-09-01 12:00:00','2026-09-01 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-003','live','success',9200000,9200000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00','2026-09-01 12:00:00');

-- INV604/605  VSP Lounge  2026-09-04  total 45480000 paid 45480000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26004',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',45480000,45480000,45480000,0,'2026-09-04','Legacy invoice INV604/605','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-04 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',3.0,150000,450000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',10.0,250000,2500000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',59.0,240000,14160000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,400000,1600000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',3.0,80000,240000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',15.0,310000,4650000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',7.0,110000,770000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',7.0,200000,1400000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',20.0,150000,3000000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',5.0,250000,1250000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Coloured Cabbage' ORDER BY id LIMIT 1),'Coloured Cabbage','WHL-COLOURED-CABBAGE','unit',3.0,350000,1050000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',2.0,50000,100000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',3.0,150000,450000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',3.0,100000,300000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,750000,750000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',2.0,110000,220000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',3.0,150000,450000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Eforiro (Shoko)' ORDER BY id LIMIT 1),'Eforiro (Shoko)','WHL-EFORIRO-SHOKO','unit',6.0,100000,600000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,200000,400000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',1.0,250000,250000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Watermelon' ORDER BY id LIMIT 1),'Watermelon','WHL-WATERMELON','unit',1.0,250000,250000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cauliflower' ORDER BY id LIMIT 1),'Cauliflower','WHL-CAULIFLOWER','unit',3.0,500000,1500000,'2026-09-04 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-04 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-04 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-004',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',45480000,45480000,'NGN','paid','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-004','live','success',45480000,45480000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00');

-- INV606  Nostalgia  2026-09-04  total 11360000 paid 11360000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26005',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',11360000,11360000,11360000,0,'2026-09-04','Legacy invoice INV606','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-04 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.5,800000,4400000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.2,500000,2600000,'2026-09-04 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.45,800000,4360000,'2026-09-04 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-04 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-005',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',11360000,11360000,'NGN','paid','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-005','live','success',11360000,11360000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00');

-- INV607  Citysubs  2026-09-04  total 2700000 paid 2700000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26006',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2700000,2700000,2700000,0,'2026-09-04','Legacy invoice INV607','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-04 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,270000,2700000,'2026-09-04 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-04 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-006',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2700000,2700000,'NGN','paid','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-006','live','success',2700000,2700000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00');

-- INV608  Citysubs  2026-09-04  total 2700000 paid 2700000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26007',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2700000,2700000,2700000,0,'2026-09-04','Legacy invoice INV608','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-04 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,270000,2700000,'2026-09-04 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-04 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-007',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2700000,2700000,'NGN','paid','2026-09-04 12:00:00','2026-09-04 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-007','live','success',2700000,2700000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00','2026-09-04 12:00:00');

-- INV609  Citysubs  2026-09-05  total 5600000 paid 5600000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26008',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',5600000,5600000,5600000,0,'2026-09-05','Legacy invoice INV609','2026-09-05 12:00:00','2026-09-05 12:00:00','2026-09-05 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-05 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',3.0,200000,600000,'2026-09-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-05 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-05 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-008',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',5600000,5600000,'NGN','paid','2026-09-05 12:00:00','2026-09-05 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-008','live','success',5600000,5600000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-05 12:00:00','2026-09-05 12:00:00','2026-09-05 12:00:00','2026-09-05 12:00:00');

-- INV610  Citysubs  2026-09-06  total 9000000 paid 9000000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26009',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',9000000,9000000,9000000,0,'2026-09-06','Legacy invoice INV610','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-009',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',9000000,9000000,'NGN','paid','2026-09-06 12:00:00','2026-09-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-009','live','success',9000000,9000000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00');

-- INV611  Citysubs  2026-09-06  total 4000000 paid 4000000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26010',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',4000000,4000000,4000000,0,'2026-09-06','Legacy invoice INV611','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-010',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',4000000,4000000,'NGN','paid','2026-09-06 12:00:00','2026-09-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-010','live','success',4000000,4000000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00','2026-09-06 12:00:00');

-- INV612  Nostalgia  2026-09-07  total 10472000 paid 10472000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26011',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10472000,10472000,10472000,0,'2026-09-07','Legacy invoice INV612','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-07 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',32.8,240000,7872000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.2,500000,2600000,'2026-09-07 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-07 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-011',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',10472000,10472000,'NGN','paid','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-011','live','success',10472000,10472000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00');

-- INV613  Citysubs  2026-09-07  total 3690000 paid 3690000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26012',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',3690000,3690000,3690000,0,'2026-09-07','Legacy invoice INV613','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-07 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,260000,2600000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Coriander' ORDER BY id LIMIT 1),'Coriander','WHL-CORIANDER','unit',1.0,110000,110000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',1.0,100000,100000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',1.0,110000,110000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,250000,250000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,300000,300000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rosemary' ORDER BY id LIMIT 1),'Rosemary','WHL-ROSEMARY','unit',1.0,220000,220000,'2026-09-07 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-07 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-012',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',3690000,3690000,'NGN','paid','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-012','live','success',3690000,3690000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00');

-- INV614  Citysubs  2026-09-07  total 3150000 paid 3150000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26013',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',3150000,3150000,3150000,0,'2026-09-07','Legacy invoice INV614','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-07 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,260000,2600000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,250000,250000,'2026-09-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,300000,300000,'2026-09-07 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-07 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-013',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',3150000,3150000,'NGN','paid','2026-09-07 12:00:00','2026-09-07 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-013','live','success',3150000,3150000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00','2026-09-07 12:00:00');

-- INV615/616  VSP Lounge  2026-09-08  total 41970000 paid 41970000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26014',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',41970000,41970000,41970000,0,'2026-09-08','Legacy invoice INV615/616','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-08 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',3.0,150000,450000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',50.0,235000,11750000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',11.0,250000,2750000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,400000,1600000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',5.0,80000,400000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',4.0,150000,600000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',5.0,110000,550000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',7.0,200000,1400000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',9.0,150000,1350000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',10.0,280000,2800000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',3.0,50000,150000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',3.0,150000,450000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Tatashe' ORDER BY id LIMIT 1),'Tatashe','WHL-TATASHE','unit',3.0,280000,840000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Shombo' ORDER BY id LIMIT 1),'Shombo','WHL-SHOMBO','unit',3.0,250000,750000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',5.0,100000,500000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,650000,1300000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',5.0,120000,600000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Garlic' ORDER BY id LIMIT 1),'Garlic','WHL-GARLIC','unit',3.0,430000,1290000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,250000,500000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',3.0,230000,690000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Locust beans' ORDER BY id LIMIT 1),'Locust beans','WHL-LOCUST-BEANS','unit',3.0,150000,450000,'2026-09-08 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lime' ORDER BY id LIMIT 1),'Lime','WHL-LIME','unit',1.0,100000,100000,'2026-09-08 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-08 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-014',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',41970000,41970000,'NGN','paid','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-014','live','success',41970000,41970000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00');

-- INV617  VSP Lounge  2026-09-08  total 10550000 paid 10550000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26015',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10550000,10550000,10550000,0,'2026-09-08','Legacy invoice INV617','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-08 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',11.0,250000,2750000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pomo' ORDER BY id LIMIT 1),'Pomo','WHL-POMO','unit',30.0,25000,750000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',30.0,235000,7050000,'2026-09-08 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-08 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-015',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',10550000,10550000,'NGN','paid','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-015','live','success',10550000,10550000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00');

-- INV618  Nostalgia  2026-09-08  total 8440000 paid 8440000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26016',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',8440000,8440000,8440000,0,'2026-09-08','Legacy invoice INV618','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-08 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.2,800000,4160000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.35,800000,4280000,'2026-09-08 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-08 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-016',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',8440000,8440000,'NGN','paid','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-016','live','success',8440000,8440000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00');

-- INV620  Citysubs  2026-09-08  total 1700000 paid 1700000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26017',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',1700000,1700000,1700000,0,'2026-09-08','Legacy invoice INV620','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-08 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Lettuce' ORDER BY id LIMIT 1),'Lettuce','WHL-LETTUCE','unit',5.0,220000,1100000,'2026-09-08 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',3.0,200000,600000,'2026-09-08 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-08 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-017',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',1700000,1700000,'NGN','paid','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-017','live','success',1700000,1700000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00');

-- INV622  Alhaji Sabo  2026-09-08  total 8888000 paid 8888000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26018',(SELECT id FROM users WHERE email='alhajisabo@okveggies.com.ng'),'business','delivered','pay_in_full','paid',8888000,8888000,8888000,0,'2026-09-08','Legacy invoice INV622','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Alhaji Sabo','+2348000000006','To be confirmed','Lekki','Lagos','2026-09-08 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',40.4,220000,8888000,'2026-09-08 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-08 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-018',(SELECT id FROM users WHERE email='alhajisabo@okveggies.com.ng'),@oid,'manual','full',8888000,8888000,'NGN','paid','2026-09-08 12:00:00','2026-09-08 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-018','live','success',8888000,8888000,'NGN','alhajisabo@okveggies.com.ng','cash','Legacy September-October import','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00','2026-09-08 12:00:00');

-- INV623  King of Fruits  2026-09-10  total 5500000 paid 5500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26019',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),'business','delivered','pay_in_full','paid',5500000,5500000,5500000,0,'2026-09-10','Legacy invoice INV623','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'King of Fruits','+2348000000005','To be confirmed','Lekki','Lagos','2026-09-10 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',25.0,220000,5500000,'2026-09-10 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-10 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-019',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),@oid,'manual','full',5500000,5500000,'NGN','paid','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-019','live','success',5500000,5500000,'NGN','kingoffruits@okveggies.com.ng','cash','Legacy September-October import','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00');

-- INV624  Citysubs  2026-09-10  total 10082000 paid 10082000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26020',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10082000,10082000,10082000,0,'2026-09-10','Legacy invoice INV624','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-10 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.9,780000,3822000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,250000,250000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,350000,350000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lettuce' ORDER BY id LIMIT 1),'Lettuce','WHL-LETTUCE','unit',3.0,220000,660000,'2026-09-10 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-10 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-020',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',10082000,10082000,'NGN','paid','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-020','live','success',10082000,10082000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00');

-- INV625  Citysubs  2026-09-10  total 1400000 paid 1400000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26021',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',1400000,1400000,1400000,0,'2026-09-10','Legacy invoice INV625','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-10 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,250000,500000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-10 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',2.0,100000,200000,'2026-09-10 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-10 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-021',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',1400000,1400000,'NGN','paid','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-021','live','success',1400000,1400000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00');

-- INV626  King of Fruits  2026-09-10  total 5500000 paid 5500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26022',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),'business','delivered','pay_in_full','paid',5500000,5500000,5500000,0,'2026-09-10','Legacy invoice INV626','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'King of Fruits','+2348000000005','To be confirmed','Lekki','Lagos','2026-09-10 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',25.0,220000,5500000,'2026-09-10 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-10 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-022',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),@oid,'manual','full',5500000,5500000,'NGN','paid','2026-09-10 12:00:00','2026-09-10 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-022','live','success',5500000,5500000,'NGN','kingoffruits@okveggies.com.ng','cash','Legacy September-October import','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00','2026-09-10 12:00:00');

-- INV628/629  VSP Lounge  2026-09-11  total 47860000 paid 47860000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26023',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',47860000,47860000,47860000,0,'2026-09-11','Legacy invoice INV628/629','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-11 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',80.0,240000,19200000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',10.0,250000,2500000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.0,400000,2000000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',2.0,150000,300000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',8.0,250000,2000000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',11.0,100000,1100000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',10.0,300000,3000000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',5.0,200000,1000000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',16.0,160000,2560000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',10.0,260000,2600000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',3.0,150000,450000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',3.0,100000,300000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,750000,1500000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,250000,500000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',3.0,250000,750000,'2026-09-11 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-11 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-11 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-023',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',47860000,47860000,'NGN','paid','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-023','live','success',47860000,47860000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00');

-- INV629  Citysubs  2026-09-11  total 3800000 paid 2600000 bal 1200000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26024',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','on_account','part_paid',3800000,3800000,2600000,1200000,'2026-09-11','Legacy invoice INV629','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-11 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',5.0,160000,800000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',5.0,80000,400000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,260000,2600000,'2026-09-11 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-11 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-024',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',3800000,2600000,'NGN','part_paid','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-024','live','success',2600000,2600000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00');

-- INV631  Alhaji Sabo  2026-09-11  total 11033000 paid 9033000 bal 2000000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26025',(SELECT id FROM users WHERE email='alhajisabo@okveggies.com.ng'),'business','delivered','on_account','part_paid',11033000,11033000,9033000,2000000,'2026-09-11','Legacy invoice INV631','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Alhaji Sabo','+2348000000006','To be confirmed','Lekki','Lagos','2026-09-11 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',37.9,220000,8338000,'2026-09-11 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.9,550000,2695000,'2026-09-11 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-11 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-025',(SELECT id FROM users WHERE email='alhajisabo@okveggies.com.ng'),@oid,'manual','full',11033000,9033000,'NGN','part_paid','2026-09-11 12:00:00','2026-09-11 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-025','live','success',9033000,9033000,'NGN','alhajisabo@okveggies.com.ng','cash','Legacy September-October import','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00','2026-09-11 12:00:00');

-- INV632  Citysubs  2026-09-12  total 8760000 paid 8760000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26026',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',8760000,8760000,8760000,0,'2026-09-12','Legacy invoice INV632','2026-09-12 12:00:00','2026-09-12 12:00:00','2026-09-12 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-12 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-12 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.7,800000,3760000,'2026-09-12 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-12 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-026',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',8760000,8760000,'NGN','paid','2026-09-12 12:00:00','2026-09-12 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-026','live','success',8760000,8760000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-12 12:00:00','2026-09-12 12:00:00','2026-09-12 12:00:00','2026-09-12 12:00:00');

-- INV633  Nostalgia  2026-09-14  total 13354000 paid 13354000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26027',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',13354000,13354000,13354000,0,'2026-09-14','Legacy invoice INV633','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-14 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',44.6,240000,10704000,'2026-09-14 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.3,500000,2650000,'2026-09-14 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-14 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-027',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',13354000,13354000,'NGN','paid','2026-09-14 12:00:00','2026-09-14 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-027','live','success',13354000,13354000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00');

-- INVX  Ms. Memunat  2026-09-14  total 500000 paid 500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26028',(SELECT id FROM users WHERE email='msmemunat@okveggies.com.ng'),'business','delivered','pay_in_full','paid',500000,500000,500000,0,'2026-09-14','Legacy invoice INVX','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Ms. Memunat','+2348000000007','To be confirmed','Lekki','Lagos','2026-09-14 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',2.0,250000,500000,'2026-09-14 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-14 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-028',(SELECT id FROM users WHERE email='msmemunat@okveggies.com.ng'),@oid,'manual','full',500000,500000,'NGN','paid','2026-09-14 12:00:00','2026-09-14 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-028','live','success',500000,500000,'NGN','msmemunat@okveggies.com.ng','cash','Legacy September-October import','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00','2026-09-14 12:00:00');

-- INV634/635  VSP Lounge  2026-09-15  total 42740000 paid 42740000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26029',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',42740000,42740000,42740000,0,'2026-09-15','Legacy invoice INV634/635','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-15 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',2.0,150000,300000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',60.0,240000,14400000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.0,400000,2000000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',3.0,80000,240000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Irish Potatoes' ORDER BY id LIMIT 1),'Irish Potatoes','WHL-IRISH-POTATOES','unit',5.0,250000,1250000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',10.0,300000,3000000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',10.0,200000,2000000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',30.0,170000,5100000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',4.0,100000,400000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,750000,1500000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Celery' ORDER BY id LIMIT 1),'Celery','WHL-CELERY','unit',1.0,100000,100000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',3.0,150000,450000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Eforiro (Shoko)' ORDER BY id LIMIT 1),'Eforiro (Shoko)','WHL-EFORIRO-SHOKO','unit',6.0,100000,600000,'2026-09-15 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',10.0,170000,1700000,'2026-09-15 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-15 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-029',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',42740000,42740000,'NGN','paid','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-029','live','success',42740000,42740000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00');

-- INV636  Citysubs  2026-09-15  total 2010000 paid 2010000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26030',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2010000,2010000,2010000,0,'2026-09-15','Legacy invoice INV636','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-15 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Avocado' ORDER BY id LIMIT 1),'Avocado','WHL-AVOCADO','unit',1.0,1400000,1400000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,350000,350000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,260000,260000,'2026-09-15 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-15 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-030',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2010000,2010000,'NGN','paid','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-030','live','success',2010000,2010000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00');

-- INV637  Nostalgia  2026-09-15  total 8240000 paid 8240000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26031',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',8240000,8240000,8240000,0,'2026-09-15','Legacy invoice INV637','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-15 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.15,800000,4120000,'2026-09-15 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.15,800000,4120000,'2026-09-15 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-15 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-031',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',8240000,8240000,'NGN','paid','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-031','live','success',8240000,8240000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00');

-- INV638  Renee Supermarket  2026-09-15  total 20631000 paid 20631000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26032',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','pay_in_full','paid',20631000,20631000,20631000,0,'2026-09-15','Legacy invoice INV638','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Lekki','Lagos','2026-09-15 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',89.7,230000,20631000,'2026-09-15 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-15 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-032',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',20631000,20631000,'NGN','paid','2026-09-15 12:00:00','2026-09-15 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-032','live','success',20631000,20631000,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00','2026-09-15 12:00:00');

-- INV639/640  VSP Lounge  2026-09-18  total 37846000 paid 37846000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26033',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',37846000,37846000,37846000,0,'2026-09-18','Legacy invoice INV639/640','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-18 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',2.0,150000,300000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',10.0,240000,2400000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',3.0,780000,2340000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',55.2,230000,12696000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',1.0,400000,400000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',2.0,80000,160000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',10.0,300000,3000000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',4.0,120000,480000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',7.0,200000,1400000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',8.0,270000,2160000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',4.0,40000,160000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',2.0,150000,300000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Tatashe' ORDER BY id LIMIT 1),'Tatashe','WHL-TATASHE','unit',3.0,350000,1050000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Shombo' ORDER BY id LIMIT 1),'Shombo','WHL-SHOMBO','unit',3.0,250000,750000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lime' ORDER BY id LIMIT 1),'Lime','WHL-LIME','unit',1.0,250000,250000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',1.0,100000,100000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,750000,750000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',3.0,110000,330000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',2.0,150000,300000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cauliflower' ORDER BY id LIMIT 1),'Cauliflower','WHL-CAULIFLOWER','unit',3.0,500000,1500000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,250000,500000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',2.0,250000,500000,'2026-09-18 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-18 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-18 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-033',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',37846000,37846000,'NGN','paid','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-033','live','success',37846000,37846000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00');

-- INV641  Citysubs  2026-09-18  total 13550000 paid 13550000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26034',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',13550000,13550000,13550000,0,'2026-09-18','Legacy invoice INV641','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-18 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,260000,2600000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,250000,500000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,750000,750000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-18 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-18 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-034',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',13550000,13550000,'NGN','paid','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-034','live','success',13550000,13550000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00');

-- INV643  Citysubs  2026-09-18  total 11600000 paid 11600000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26035',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',11600000,11600000,11600000,0,'2026-09-18','Legacy invoice INV643','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-18 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,260000,2600000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-18 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,500000,5000000,'2026-09-18 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-18 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-035',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',11600000,11600000,'NGN','paid','2026-09-18 12:00:00','2026-09-18 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-035','live','success',11600000,11600000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00','2026-09-18 12:00:00');

-- INV644  Citysubs  2026-09-21  total 11920000 paid 11920000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26036',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',11920000,11920000,11920000,0,'2026-09-21','Legacy invoice INV644','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-21 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,250000,2500000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,350000,350000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,270000,270000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-09-21 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-21 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-036',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',11920000,11920000,'NGN','paid','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-036','live','success',11920000,11920000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00');

-- INV645  Citysubs  2026-09-21  total 10150000 paid 10150000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26037',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10150000,10150000,10150000,0,'2026-09-21','Legacy invoice INV645','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-21 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,270000,540000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',1.0,110000,110000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-09-21 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-21 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-037',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',10150000,10150000,'NGN','paid','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-037','live','success',10150000,10150000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00');

-- INV646  Nostalgia  2026-09-21  total 18021000 paid 18021000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26038',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',18021000,18021000,18021000,0,'2026-09-21','Legacy invoice INV646','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-21 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',31.7,230000,7291000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.1,800000,4080000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-21 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',5.3,500000,2650000,'2026-09-21 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-21 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-038',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',18021000,18021000,'NGN','paid','2026-09-21 12:00:00','2026-09-21 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-038','live','success',18021000,18021000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00','2026-09-21 12:00:00');

-- INV647/INV648  VSP Lounge  2026-09-22  total 66440000 paid 66440000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26039',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',66440000,66440000,66440000,0,'2026-09-22','Legacy invoice INV647/INV648','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-22 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',4.0,150000,600000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',20.0,200000,4000000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',124.0,230000,28520000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,780000,3900000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',2.0,780000,1560000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,400000,1600000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',5.0,80000,400000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',3.0,150000,450000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',10.0,300000,3000000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',3.0,200000,600000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',31.0,170000,5270000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',10.0,260000,2600000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',3.0,40000,120000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',3.0,150000,450000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Tatashe' ORDER BY id LIMIT 1),'Tatashe','WHL-TATASHE','unit',3.0,300000,900000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Shombo' ORDER BY id LIMIT 1),'Shombo','WHL-SHOMBO','unit',3.0,250000,750000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',5.0,100000,500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,750000,1500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',4.0,110000,440000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Celery' ORDER BY id LIMIT 1),'Celery','WHL-CELERY','unit',1.0,100000,100000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Garlic' ORDER BY id LIMIT 1),'Garlic','WHL-GARLIC','unit',3.0,430000,1290000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cauliflower' ORDER BY id LIMIT 1),'Cauliflower','WHL-CAULIFLOWER','unit',3.0,500000,1500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,250000,500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',3.0,250000,750000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Brocoli' ORDER BY id LIMIT 1),'Brocoli','WHL-BROCOLI','unit',3.0,780000,2340000,'2026-09-22 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-22 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-22 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-039',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',66440000,66440000,'NGN','paid','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-039','live','success',66440000,66440000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00');

-- INV649  VSP Lounge  2026-09-22  total 2860000 paid 2860000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26040',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2860000,2860000,2860000,0,'2026-09-22','Legacy invoice INV649','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-22 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Pomo' ORDER BY id LIMIT 1),'Pomo','WHL-POMO','unit',30.0,50000,1500000,'2026-09-22 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',8.0,170000,1360000,'2026-09-22 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-22 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-040',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',2860000,2860000,'NGN','paid','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-040','live','success',2860000,2860000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00');

-- INV650  Citysubs  2026-09-22  total 2500000 paid 2500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26041',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2500000,2500000,2500000,0,'2026-09-22','Legacy invoice INV650','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-22 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,250000,2500000,'2026-09-22 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-22 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-041',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2500000,2500000,'NGN','paid','2026-09-22 12:00:00','2026-09-22 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-041','live','success',2500000,2500000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00','2026-09-22 12:00:00');

-- INV562/INV563  VSP Lounge  2026-09-25  total 29050000 paid 29050000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26042',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',29050000,29050000,29050000,0,'2026-09-25','Legacy invoice INV562/INV563','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-25 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',3.0,150000,450000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',33.0,230000,7590000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,400000,1600000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',3.0,80000,240000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',2.0,150000,300000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',9.0,250000,2250000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',10.0,100000,1000000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',7.0,200000,1400000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',11.0,170000,1870000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',5.0,260000,1300000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',3.0,100000,300000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',3.0,110000,330000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cauliflower' ORDER BY id LIMIT 1),'Cauliflower','WHL-CAULIFLOWER','unit',3.0,500000,1500000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,250000,500000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Brocoli' ORDER BY id LIMIT 1),'Brocoli','WHL-BROCOLI','unit',1.0,780000,780000,'2026-09-25 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Eforiro (Shoko)' ORDER BY id LIMIT 1),'Eforiro (Shoko)','WHL-EFORIRO-SHOKO','unit',4.0,100000,400000,'2026-09-25 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-25 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-042',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',29050000,29050000,'NGN','paid','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-042','live','success',29050000,29050000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00');

-- INV564  Renee Supermarket  2026-09-25  total 7700000 paid 7700000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26043',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','pay_in_full','paid',7700000,7700000,7700000,0,'2026-09-25','Legacy invoice INV564','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Lekki','Lagos','2026-09-25 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Sweet Corn' ORDER BY id LIMIT 1),'Sweet Corn','WHL-SWEET-CORN','unit',38.5,200000,7700000,'2026-09-25 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-25 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-043',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',7700000,7700000,'NGN','paid','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-043','live','success',7700000,7700000,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00');

-- INV565  Citysubs  2026-09-25  total 3300000 paid 3300000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26044',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',3300000,3300000,3300000,0,'2026-09-25','Legacy invoice INV565','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-25 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,250000,2500000,'2026-09-25 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lettuce' ORDER BY id LIMIT 1),'Lettuce','WHL-LETTUCE','unit',4.0,200000,800000,'2026-09-25 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-25 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-044',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',3300000,3300000,'NGN','paid','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-044','live','success',3300000,3300000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00');

-- INV566  Citysubs  2026-09-25  total 2500000 paid 2500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26045',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2500000,2500000,2500000,0,'2026-09-25','Legacy invoice INV566','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-25 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,250000,2500000,'2026-09-25 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-25 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-045',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2500000,2500000,'NGN','paid','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-045','live','success',2500000,2500000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00');

-- INV567  King of Fruits  2026-09-25  total 4000073 paid 4000073 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26046',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),'business','delivered','pay_in_full','paid',4000073,4000073,4000073,0,'2026-09-25','Legacy invoice INV567','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'King of Fruits','+2348000000005','To be confirmed','Lekki','Lagos','2026-09-25 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',20.85,191850,4000073,'2026-09-25 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-25 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-046',(SELECT id FROM users WHERE email='kingoffruits@okveggies.com.ng'),@oid,'manual','full',4000073,4000073,'NGN','paid','2026-09-25 12:00:00','2026-09-25 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-046','live','success',4000073,4000073,'NGN','kingoffruits@okveggies.com.ng','cash','Legacy September-October import','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00','2026-09-25 12:00:00');

-- INV568  Citysubs  2026-09-26  total 9300000 paid 9300000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26047',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',9300000,9300000,9300000,0,'2026-09-26','Legacy invoice INV568','2026-09-26 12:00:00','2026-09-26 12:00:00','2026-09-26 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-26 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-09-26 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-26 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',1.0,300000,300000,'2026-09-26 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',1.0,200000,200000,'2026-09-26 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-26 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-047',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',9300000,9300000,'NGN','paid','2026-09-26 12:00:00','2026-09-26 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-047','live','success',9300000,9300000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-26 12:00:00','2026-09-26 12:00:00','2026-09-26 12:00:00','2026-09-26 12:00:00');

-- INV569  Nostalgia  2026-09-28  total 17850000 paid 17850000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26048',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','pay_in_full','paid',17850000,17850000,17850000,0,'2026-09-28','Legacy invoice INV569','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-09-28 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',39.0,230000,8970000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.7,800000,3760000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',3.9,800000,3120000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,500000,2000000,'2026-09-28 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-28 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-048',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',17850000,17850000,'NGN','paid','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-048','live','success',17850000,17850000,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00');

-- INV570  Citysubs  2026-09-28  total 10040000 paid 10040000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26049',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10040000,10040000,10040000,0,'2026-09-28','Legacy invoice INV570','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-09-28 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,270000,540000,'2026-09-28 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-28 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-28 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-049',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',10040000,10040000,'NGN','paid','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-049','live','success',10040000,10040000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00');

-- INV571  Citysubs  2026-09-28  total 2500000 paid 2500000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26050',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2500000,2500000,2500000,0,'2026-09-28','Legacy invoice INV571','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-09-28 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,250000,2500000,'2026-09-28 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-28 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-050',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2500000,2500000,'NGN','paid','2026-09-28 12:00:00','2026-09-28 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-050','live','success',2500000,2500000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00','2026-09-28 12:00:00');

-- INV572/573  VSP Lounge  2026-09-29  total 42011000 paid 42011000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26051',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',42011000,42011000,42011000,0,'2026-09-29','Legacy invoice INV572/573','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-29 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',4.0,150000,600000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',63.6,220000,13992000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,780000,3120000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',3.2,400000,1280000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',4.0,80000,320000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',9.0,100000,900000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',5.0,300000,1500000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',6.0,110000,660000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',5.0,200000,1000000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',29.7,170000,5049000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',5.0,40000,200000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lime' ORDER BY id LIMIT 1),'Lime','WHL-LIME','unit',1.0,200000,200000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',4.0,80000,320000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',2.0,600000,1200000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',5.0,110000,550000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Celery' ORDER BY id LIMIT 1),'Celery','WHL-CELERY','unit',1.0,100000,100000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Garlic' ORDER BY id LIMIT 1),'Garlic','WHL-GARLIC','unit',2.0,400000,800000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',3.0,150000,450000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,200000,400000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',2.0,250000,500000,'2026-09-29 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,450000,450000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',10.0,150000,1500000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',5.0,300000,1500000,'2026-09-29 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-29 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-051',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',42011000,42011000,'NGN','paid','2026-09-29 12:00:00','2026-09-29 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-051','live','success',42011000,42011000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00');

-- INV574  VSP Lounge  2026-09-29  total 10940000 paid 10940000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26052',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10940000,10940000,10940000,0,'2026-09-29','Legacy invoice INV574','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-09-29 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',42.0,220000,9240000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',8.0,150000,1200000,'2026-09-29 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pomo' ORDER BY id LIMIT 1),'Pomo','WHL-POMO','unit',20.0,25000,500000,'2026-09-29 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-09-29 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-052',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',10940000,10940000,'NGN','paid','2026-09-29 12:00:00','2026-09-29 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-052','live','success',10940000,10940000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00','2026-09-29 12:00:00');

-- INV575  Renee Supermarket  2026-10-01  total 10800000 paid 0 bal 10800000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26053',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','on_account','part_paid',10800000,10800000,0,10800000,'2026-10-01','Legacy invoice INV575','2026-10-01 12:00:00','2026-10-01 12:00:00','2026-10-01 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Hakeem Dickson','Lagos','2026-10-01 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',60.0,180000,10800000,'2026-10-01 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-01 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-053',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',10800000,0,'NGN','part_paid','2026-10-01 12:00:00','2026-10-01 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-053','live','success',0,0,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-10-01 12:00:00','2026-10-01 12:00:00','2026-10-01 12:00:00','2026-10-01 12:00:00');

-- INV576/571  VSP Lounge  2026-10-02  total 38463500 paid 38463500 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26054',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',38463500,38463500,38463500,0,'2026-10-02','Legacy invoice INV576/571','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',1.0,150000,150000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',9.85,150000,1477500,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',3.2,780000,2496000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',65.6,210000,13776000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',3.2,780000,2496000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.2,400000,1680000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',3.0,80000,240000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',8.0,250000,2000000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',9.6,100000,960000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yam' ORDER BY id LIMIT 1),'Yam','WHL-YAM','unit',5.0,300000,1500000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',4.0,110000,440000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',10.0,200000,2000000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',5.0,400000,2000000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cucumber' ORDER BY id LIMIT 1),'Cucumber','WHL-CUCUMBER','unit',4.0,40000,160000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',2.0,150000,300000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',4.0,100000,400000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,600000,600000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Mint leaf' ORDER BY id LIMIT 1),'Mint leaf','WHL-MINT-LEAF','unit',1.0,100000,100000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',3.0,110000,330000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Celery' ORDER BY id LIMIT 1),'Celery','WHL-CELERY','unit',2.0,100000,200000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',1.0,150000,150000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Cauliflower' ORDER BY id LIMIT 1),'Cauliflower','WHL-CAULIFLOWER','unit',3.2,500000,1600000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,220000,440000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',1.0,250000,250000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Brocoli' ORDER BY id LIMIT 1),'Brocoli','WHL-BROCOLI','unit',3.1,780000,2418000,'2026-10-02 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-054',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',38463500,38463500,'NGN','paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-054','live','success',38463500,38463500,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV578  VSP Lounge  2026-10-02  total 2249000 paid 2249000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26055',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2249000,2249000,2249000,0,'2026-10-02','Legacy invoice INV578','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,600000,600000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',9.7,170000,1649000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-055',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',2249000,2249000,'NGN','paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-055','live','success',2249000,2249000,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV579  Nostalgia  2026-10-02  total 12507000 paid 0 bal 12507000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26056',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','on_account','part_paid',12507000,12507000,0,12507000,'2026-10-02','Legacy invoice INV579','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',30.7,210000,6447000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',3.0,500000,1500000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',3.0,800000,2400000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',2.7,800000,2160000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-056',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',12507000,0,'NGN','part_paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-056','live','success',0,0,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV581  Renee Supermarket  2026-10-02  total 17100000 paid 0 bal 17100000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26057',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','on_account','part_paid',17100000,17100000,0,17100000,'2026-10-02','Legacy invoice INV581','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Lekki','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',95.0,180000,17100000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-057',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',17100000,0,'NGN','part_paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-057','live','success',0,0,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV582  Citysubs  2026-10-02  total 3300000 paid 3300000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26058',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',3300000,3300000,3300000,0,'2026-10-02','Legacy invoice INV582','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',15.0,220000,3300000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-058',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',3300000,3300000,'NGN','paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-058','live','success',3300000,3300000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV583  Citysubs  2026-10-02  total 2860000 paid 2860000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26059',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2860000,2860000,2860000,0,'2026-10-02','Legacy invoice INV583','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-10-02 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,220000,2200000,'2026-10-02 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lettuce' ORDER BY id LIMIT 1),'Lettuce','WHL-LETTUCE','unit',3.0,220000,660000,'2026-10-02 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-02 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-059',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2860000,2860000,'NGN','paid','2026-10-02 12:00:00','2026-10-02 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-059','live','success',2860000,2860000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00','2026-10-02 12:00:00');

-- INV584  Citysubs  2026-10-03  total 8800000 paid 8800000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26060',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',8800000,8800000,8800000,0,'2026-10-03','Legacy invoice INV584','2026-10-03 12:00:00','2026-10-03 12:00:00','2026-10-03 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-10-03 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-10-03 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-10-03 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-03 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-060',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',8800000,8800000,'NGN','paid','2026-10-03 12:00:00','2026-10-03 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-060','live','success',8800000,8800000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-03 12:00:00','2026-10-03 12:00:00','2026-10-03 12:00:00','2026-10-03 12:00:00');

-- INV585  Nostalgia  2026-10-05  total 15660000 paid 0 bal 15660000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26061',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),'business','delivered','on_account','part_paid',15660000,15660000,0,15660000,'2026-10-05','Legacy invoice INV585','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Nostalgia','+2348000000003','To be confirmed','Lekki','Lagos','2026-10-05 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',33.0,220000,7260000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',4.0,800000,3200000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,800000,3200000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,500000,2000000,'2026-10-05 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-05 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-061',(SELECT id FROM users WHERE email='nostalgia@okveggies.com.ng'),@oid,'manual','full',15660000,0,'NGN','part_paid','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-061','live','success',0,0,'NGN','nostalgia@okveggies.com.ng','cash','Legacy September-October import','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00');

-- INV587  Citysubs  2026-10-05  total 10280000 paid 10280000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26062',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',10280000,10280000,10280000,0,'2026-10-05','Legacy invoice INV587','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-10-05 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,390000,780000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-10-05 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-05 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-062',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',10280000,10280000,'NGN','paid','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-062','live','success',10280000,10280000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00');

-- INV588  Citysubs  2026-10-05  total 13630000 paid 13630000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26063',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',13630000,13630000,13630000,0,'2026-10-05','Legacy invoice INV588','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Lekki','Lagos','2026-10-05 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,220000,2200000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.0,800000,4000000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',10.0,480000,4800000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',1.0,100000,100000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Thyme' ORDER BY id LIMIT 1),'Fresh Thyme','WHL-FRESH-THYME','unit',1.0,110000,110000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Coriander' ORDER BY id LIMIT 1),'Coriander','WHL-CORIANDER','unit',1.0,120000,120000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rosemary' ORDER BY id LIMIT 1),'Rosemary','WHL-ROSEMARY','unit',1.0,120000,120000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Avocado' ORDER BY id LIMIT 1),'Avocado','WHL-AVOCADO','unit',7.0,100000,700000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',2.0,390000,780000,'2026-10-05 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Purple Cabbage' ORDER BY id LIMIT 1),'Purple Cabbage','WHL-PURPLE-CABBAGE','unit',2.0,350000,700000,'2026-10-05 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-05 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-063',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',13630000,13630000,'NGN','paid','2026-10-05 12:00:00','2026-10-05 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-063','live','success',13630000,13630000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00','2026-10-05 12:00:00');

-- INV589/590  VSP Lounge  2026-10-06  total 42821000 paid 0 bal 42821000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26064',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','on_account','part_paid',42821000,42821000,0,42821000,'2026-10-06','Legacy invoice INV589/590','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-10-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Okro' ORDER BY id LIMIT 1),'Okro','WHL-OKRO','unit',5.0,150000,750000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',47.1,200000,9420000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rodo' ORDER BY id LIMIT 1),'Rodo','WHL-RODO','unit',8.7,150000,1305000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Red bell Pepper' ORDER BY id LIMIT 1),'Red bell Pepper','WHL-RED-BELL-PEPPER','unit',5.1,780000,3978000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Yellow bell Pepper' ORDER BY id LIMIT 1),'Yellow bell Pepper','WHL-YELLOW-BELL-PEPPER','unit',4.0,780000,3120000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green bell pepper' ORDER BY id LIMIT 1),'Green bell pepper','WHL-GREEN-BELL-PEPPER','unit',4.0,400000,1600000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',5.0,80000,400000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Marrow' ORDER BY id LIMIT 1),'Marrow','WHL-MARROW','unit',3.0,150000,450000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Plantain' ORDER BY id LIMIT 1),'Plantain','WHL-PLANTAIN','unit',6.0,250000,1500000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Irish Potatoes' ORDER BY id LIMIT 1),'Irish Potatoes','WHL-IRISH-POTATOES','unit',5.0,250000,1250000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',11.0,80000,880000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Carrot' ORDER BY id LIMIT 1),'Carrot','WHL-CARROT','unit',5.0,250000,1250000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',25.8,200000,5160000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='White Cabbage' ORDER BY id LIMIT 1),'White Cabbage','WHL-WHITE-CABBAGE','unit',10.0,350000,3500000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pineapple' ORDER BY id LIMIT 1),'Pineapple','WHL-PINEAPPLE','unit',3.0,150000,450000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Tatashe' ORDER BY id LIMIT 1),'Tatashe','WHL-TATASHE','unit',4.0,200000,800000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Shombo' ORDER BY id LIMIT 1),'Shombo','WHL-SHOMBO','unit',3.0,200000,600000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lime' ORDER BY id LIMIT 1),'Lime','WHL-LIME','unit',2.0,100000,200000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Basil' ORDER BY id LIMIT 1),'Basil','WHL-BASIL','unit',5.0,80000,400000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Spring Onion' ORDER BY id LIMIT 1),'Spring Onion','WHL-SPRING-ONION','unit',4.0,110000,440000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Garlic' ORDER BY id LIMIT 1),'Garlic','WHL-GARLIC','unit',3.0,400000,1200000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Locust beans' ORDER BY id LIMIT 1),'Locust beans','WHL-LOCUST-BEANS','unit',2.0,150000,300000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Banana' ORDER BY id LIMIT 1),'Banana','WHL-BANANA','unit',2.0,200000,400000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Green beans' ORDER BY id LIMIT 1),'Green beans','WHL-GREEN-BEANS','unit',3.0,250000,750000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Brocoli' ORDER BY id LIMIT 1),'Brocoli','WHL-BROCOLI','unit',3.1,780000,2418000,'2026-10-06 12:00:00'),
(@oid,'product',NULL,'Delivery fee','DELIVERY-FEE','service',1.0,300000,300000,'2026-10-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-064',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',42821000,0,'NGN','part_paid','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-064','live','success',0,0,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00');

-- INV591  VSP Lounge  2026-10-06  total 1590000 paid 0 bal 1590000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26065',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),'business','delivered','on_account','part_paid',1590000,1590000,0,1590000,'2026-10-06','Legacy invoice INV591','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'VSP Lounge','+2348000000001','To be confirmed','Ikeja','Lagos','2026-10-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Stock fish' ORDER BY id LIMIT 1),'Stock fish','WHL-STOCK-FISH','unit',21.0,40000,840000,'2026-10-06 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Pomo' ORDER BY id LIMIT 1),'Pomo','WHL-POMO','unit',30.0,25000,750000,'2026-10-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-065',(SELECT id FROM users WHERE email='vsplounge@okveggies.com.ng'),@oid,'manual','full',1590000,0,'NGN','part_paid','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-065','live','success',0,0,'NGN','vsplounge@okveggies.com.ng','cash','Legacy September-October import','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00');

-- INV592  Citysubs  2026-10-06  total 2200000 paid 2200000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26066',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',2200000,2200000,2200000,0,'2026-10-06','Legacy invoice INV592','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-10-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Fresh Tomatoes' ORDER BY id LIMIT 1),'Fresh Tomatoes','WHL-FRESH-TOMATOES','unit',10.0,220000,2200000,'2026-10-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-066',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',2200000,2200000,'NGN','paid','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-066','live','success',2200000,2200000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00');

-- INV593  Renee Supermarket  2026-10-06  total 9800000 paid 0 bal 9800000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26067',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','on_account','part_paid',9800000,9800000,0,9800000,'2026-10-06','Legacy invoice INV593','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Lekki','Lagos','2026-10-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',49.0,200000,9800000,'2026-10-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-067',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',9800000,0,'NGN','part_paid','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-067','live','success',0,0,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00');

-- INV594  Renee Supermarket  2026-10-06  total 10526000 paid 0 bal 10526000
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26068',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),'business','delivered','on_account','part_paid',10526000,10526000,0,10526000,'2026-10-06','Legacy invoice INV594','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Renee Supermarket','+2348000000004','To be confirmed','Hakeem Dickson','Lagos','2026-10-06 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Sweet potatoes' ORDER BY id LIMIT 1),'Sweet potatoes','WHL-SWEET-POTATOES','unit',55.4,190000,10526000,'2026-10-06 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-06 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-068',(SELECT id FROM users WHERE email='reneesupermarket@okveggies.com.ng'),@oid,'manual','full',10526000,0,'NGN','part_paid','2026-10-06 12:00:00','2026-10-06 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-068','live','success',0,0,'NGN','reneesupermarket@okveggies.com.ng','cash','Legacy September-October import','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00','2026-10-06 12:00:00');

-- INV595  Citysubs  2026-10-07  total 4020000 paid 4020000 bal 0
INSERT INTO orders (order_number,user_id,customer_type,order_status,payment_option,payment_status,subtotal_subunit,order_total_subunit,amount_paid_subunit,balance_due_subunit,preferred_delivery_date,customer_note,confirmed_at,delivered_at,created_at)
SELECT 'OKV26069',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),'business','delivered','pay_in_full','paid',4020000,4020000,4020000,0,'2026-10-07','Legacy invoice INV595','2026-10-07 12:00:00','2026-10-07 12:00:00','2026-10-07 12:00:00';
SET @oid := LAST_INSERT_ID();
INSERT INTO order_addresses (order_id,recipient_name,recipient_phone,address_line_1,city,state,created_at)
VALUES (@oid,'Citysubs','+2348000000002','To be confirmed','Yaba','Lagos','2026-10-07 12:00:00');
INSERT INTO order_items (order_id,item_type,product_id,item_name,sku,unit_name,quantity,unit_price_subunit,line_total_subunit,created_at) VALUES
(@oid,'product',(SELECT id FROM products WHERE name='Ginger' ORDER BY id LIMIT 1),'Ginger','WHL-GINGER','unit',1.0,700000,700000,'2026-10-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Garlic' ORDER BY id LIMIT 1),'Garlic','WHL-GARLIC','unit',1.0,400000,400000,'2026-10-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Rosemary' ORDER BY id LIMIT 1),'Rosemary','WHL-ROSEMARY','unit',1.0,120000,120000,'2026-10-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Parsley' ORDER BY id LIMIT 1),'Parsley','WHL-PARSLEY','unit',1.0,100000,100000,'2026-10-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Onion' ORDER BY id LIMIT 1),'Onion','WHL-ONION','unit',8.0,200000,1600000,'2026-10-07 12:00:00'),
(@oid,'product',(SELECT id FROM products WHERE name='Lettuce' ORDER BY id LIMIT 1),'Lettuce','WHL-LETTUCE','unit',5.0,220000,1100000,'2026-10-07 12:00:00');
INSERT INTO order_status_history (order_id,old_status,new_status,source,note,created_at)
VALUES (@oid,NULL,'delivered','import','Legacy September-October import','2026-10-07 12:00:00');
INSERT INTO payments (payment_number,user_id,order_id,provider,payment_type,expected_amount_subunit,paid_amount_subunit,currency,status,confirmed_at,created_at)
SELECT 'PAYL26-069',(SELECT id FROM users WHERE email='citysubs@okveggies.com.ng'),@oid,'manual','full',4020000,4020000,'NGN','paid','2026-10-07 12:00:00','2026-10-07 12:00:00';
SET @pid := LAST_INSERT_ID();
INSERT INTO payment_transactions (payment_id,provider,reference,domain,status,requested_amount_subunit,amount_subunit,currency,customer_email,channel,gateway_response,paid_at,verified_at,initialized_at,created_at)
VALUES (@pid,'manual','LEGACYL26-069','live','success',4020000,4020000,'NGN','citysubs@okveggies.com.ng','cash','Legacy September-October import','2026-10-07 12:00:00','2026-10-07 12:00:00','2026-10-07 12:00:00','2026-10-07 12:00:00');

INSERT INTO counters (name,value) VALUES ('order:26',69) ON DUPLICATE KEY UPDATE value=GREATEST(value,69);

COMMIT;