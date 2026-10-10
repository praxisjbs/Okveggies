-- OK Veggies: import the 7 wholesale business customers. Idempotent (skips any that already exist by email).
-- Placeholder email and phone, no usable password: invite them to the Pro portal later from Customers.

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'VSP Lounge','','vsplounge@okveggies.com.ng','+2348000000001','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='vsplounge@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'VSP Lounge','VSP Lounge' FROM users u WHERE u.email='vsplounge@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'Citysubs','','citysubs@okveggies.com.ng','+2348000000002','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='citysubs@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'Citysubs','Citysubs' FROM users u WHERE u.email='citysubs@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'Nostalgia','','nostalgia@okveggies.com.ng','+2348000000003','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='nostalgia@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'Nostalgia','Nostalgia' FROM users u WHERE u.email='nostalgia@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'Renee Supermarket','','reneesupermarket@okveggies.com.ng','+2348000000004','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='reneesupermarket@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'Renee Supermarket','Renee Supermarket' FROM users u WHERE u.email='reneesupermarket@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'King of Fruits','','kingoffruits@okveggies.com.ng','+2348000000005','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='kingoffruits@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'King of Fruits','King of Fruits' FROM users u WHERE u.email='kingoffruits@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'Alhaji Sabo','','alhajisabo@okveggies.com.ng','+2348000000006','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='alhajisabo@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'Alhaji Sabo','Alhaji Sabo' FROM users u WHERE u.email='alhajisabo@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);

INSERT INTO users (first_name,last_name,email,phone,password_hash,user_type,status)
SELECT 'Ms. Memunat','','msmemunat@okveggies.com.ng','+2348000000007','$2y$10$CZmdIngrVtdHSth5EsFKYuN0BlMygNs9dITtEkbFINLxXAVse/zCC','business','active'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email='msmemunat@okveggies.com.ng');
INSERT INTO business_customers (user_id,business_name,contact_person)
SELECT u.id,'Ms. Memunat','Ms. Memunat' FROM users u WHERE u.email='msmemunat@okveggies.com.ng'
  AND NOT EXISTS (SELECT 1 FROM business_customers b WHERE b.user_id=u.id);
