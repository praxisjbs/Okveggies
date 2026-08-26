
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Customer, administrator and staff identities.
CREATE TABLE IF NOT EXISTS `users` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(30) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `user_type` VARCHAR(30) NOT NULL DEFAULT 'household',
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `paystack_customer_code` VARCHAR(100) NULL,
  `email_verified_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  UNIQUE KEY `uq_users_phone` (`phone`),
  UNIQUE KEY `uq_users_paystack_customer_code` (`paystack_customer_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Named access roles used by administrators and staff.
CREATE TABLE IF NOT EXISTS `roles` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Many-to-many assignment of users to roles.
CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` BIGINT UNSIGNED NOT NULL,
  `role_id` BIGINT UNSIGNED NOT NULL,
  `assigned_by` BIGINT UNSIGNED NULL,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `role_id`),
  CONSTRAINT `fk_user_roles_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_role_id` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_roles_assigned_by` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2B profile, requested credit and approved credit terms.
CREATE TABLE IF NOT EXISTS `business_customers` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `business_name` VARCHAR(200) NOT NULL,
  `business_type` VARCHAR(80) NULL,
  `registration_number` VARCHAR(100) NULL,
  `contact_person` VARCHAR(150) NOT NULL,
  `credit_requested` BOOLEAN NOT NULL DEFAULT FALSE,
  `credit_status` VARCHAR(30) NOT NULL DEFAULT 'not_requested',
  `credit_days` SMALLINT UNSIGNED NULL,
  `credit_limit_subunit` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_business_customers_user_id` (`user_id`),
  CONSTRAINT `fk_business_customers_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable delivery addresses belonging to a customer.
CREATE TABLE IF NOT EXISTS `customer_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NOT NULL,
  `label` VARCHAR(60) NULL,
  `recipient_name` VARCHAR(150) NOT NULL,
  `recipient_phone` VARCHAR(30) NOT NULL,
  `address_line_1` VARCHAR(255) NOT NULL,
  `address_line_2` VARCHAR(255) NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) NOT NULL,
  `landmark` VARCHAR(255) NULL,
  `is_default` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_customer_addresses_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX (user_id, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hierarchical catalogue categories such as vegetables, grains and tubers.
CREATE TABLE IF NOT EXISTS `product_categories` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` BIGINT UNSIGNED NULL,
  `name` VARCHAR(120) NOT NULL,
  `slug` VARCHAR(140) NOT NULL,
  `description` TEXT NULL,
  `image_url` VARCHAR(500) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_categories_slug` (`slug`),
  CONSTRAINT `fk_product_categories_parent_id` FOREIGN KEY (`parent_id`) REFERENCES `product_categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Selling units such as kilogram, bunch, head and tuber.
CREATE TABLE IF NOT EXISTS `units_of_measurement` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(80) NOT NULL,
  `symbol` VARCHAR(20) NOT NULL,
  `allows_decimal` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_units_of_measurement_name` (`name`),
  UNIQUE KEY `uq_units_of_measurement_symbol` (`symbol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products sold individually on the storefront.
CREATE TABLE IF NOT EXISTS `products` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `name` VARCHAR(180) NOT NULL,
  `slug` VARCHAR(200) NOT NULL,
  `sku` VARCHAR(80) NOT NULL,
  `short_description` VARCHAR(300) NULL,
  `description` TEXT NULL,
  `current_price_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `minimum_quantity` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `quantity_increment` DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `is_featured` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_slug` (`slug`),
  UNIQUE KEY `uq_products_sku` (`sku`),
  CONSTRAINT `fk_products_category_id` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_products_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units_of_measurement` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (category_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple ordered images for each product.
CREATE TABLE IF NOT EXISTS `product_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `image_url` VARCHAR(500) NOT NULL,
  `alt_text` VARCHAR(255) NULL,
  `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_primary` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_product_images_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit history of volatile product price changes.
CREATE TABLE IF NOT EXISTS `product_price_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `old_price_subunit` BIGINT UNSIGNED NULL,
  `new_price_subunit` BIGINT UNSIGNED NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `effective_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `effective_to` DATETIME NULL,
  `change_reason` VARCHAR(255) NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_product_price_history_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_product_price_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (product_id, effective_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lightweight stock/sourcing availability without a full warehouse system.
CREATE TABLE IF NOT EXISTS `product_availability` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `availability_status` VARCHAR(30) NOT NULL DEFAULT 'available',
  `available_quantity` DECIMAL(12,3) NULL,
  `restock_date` DATE NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_availability_product_id` (`product_id`),
  CONSTRAINT `fk_product_availability_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_product_availability_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pre-created product bundles such as stew combos.
CREATE TABLE IF NOT EXISTS `combo_packages` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(180) NOT NULL,
  `slug` VARCHAR(200) NOT NULL,
  `sku` VARCHAR(80) NOT NULL,
  `description` TEXT NULL,
  `price_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `image_url` VARCHAR(500) NULL,
  `is_featured` BOOLEAN NOT NULL DEFAULT FALSE,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `available_from` DATE NULL,
  `available_until` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_combo_packages_slug` (`slug`),
  UNIQUE KEY `uq_combo_packages_sku` (`sku`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Products and quantities contained in a combo.
CREATE TABLE IF NOT EXISTS `combo_package_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `combo_package_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_combo_package_items_combo_package_id` FOREIGN KEY (`combo_package_id`) REFERENCES `combo_packages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_combo_package_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_combo_package_items_unit_id` FOREIGN KEY (`unit_id`) REFERENCES `units_of_measurement` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  UNIQUE (combo_package_id, product_id, unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- History of bundle price changes.
CREATE TABLE IF NOT EXISTS `combo_price_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `combo_package_id` BIGINT UNSIGNED NOT NULL,
  `old_price_subunit` BIGINT UNSIGNED NULL,
  `new_price_subunit` BIGINT UNSIGNED NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `effective_from` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `effective_to` DATETIME NULL,
  `change_reason` VARCHAR(255) NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_combo_price_history_combo_package_id` FOREIGN KEY (`combo_package_id`) REFERENCES `combo_packages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_combo_price_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Active, converted and abandoned carts for users or guest sessions.
CREATE TABLE IF NOT EXISTS `shopping_carts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` BIGINT UNSIGNED NULL,
  `session_token` VARCHAR(255) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'active',
  `expires_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shopping_carts_session_token` (`session_token`),
  CONSTRAINT `fk_shopping_carts_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual products and combos placed in a cart.
CREATE TABLE IF NOT EXISTS `cart_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `cart_id` BIGINT UNSIGNED NOT NULL,
  `item_type` VARCHAR(20) NOT NULL,
  `product_id` BIGINT UNSIGNED NULL,
  `combo_package_id` BIGINT UNSIGNED NULL,
  `quantity` DECIMAL(10,3) NOT NULL,
  `unit_price_subunit` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_cart_items_cart_id` FOREIGN KEY (`cart_id`) REFERENCES `shopping_carts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cart_items_combo_package_id` FOREIGN KEY (`combo_package_id`) REFERENCES `combo_packages` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  INDEX (cart_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configurable weekdays available to household or business customers.
CREATE TABLE IF NOT EXISTS `allowed_delivery_days` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_type` VARCHAR(30) NOT NULL,
  `day_of_week` TINYINT UNSIGNED NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `cutoff_time` TIME NULL,
  `minimum_lead_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE (customer_type, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Holiday, closure, capacity and special delivery-date overrides.
CREATE TABLE IF NOT EXISTS `delivery_date_exceptions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exception_date` DATE NOT NULL,
  `is_available` BOOLEAN NOT NULL DEFAULT FALSE,
  `reason` VARCHAR(255) NULL,
  `replacement_date` DATE NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_date_exceptions_exception_date` (`exception_date`),
  CONSTRAINT `fk_delivery_date_exceptions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Commercial order retained throughout its lifecycle, including cancellation.
CREATE TABLE IF NOT EXISTS `orders` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(40) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `customer_type` VARCHAR(30) NOT NULL,
  `order_status` VARCHAR(40) NOT NULL DEFAULT 'pending',
  `payment_option` VARCHAR(30) NOT NULL,
  `payment_status` VARCHAR(30) NOT NULL DEFAULT 'unpaid',
  `subtotal_subunit` BIGINT UNSIGNED NOT NULL,
  `discount_amount_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `order_total_subunit` BIGINT UNSIGNED NOT NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `deposit_percentage` DECIMAL(5,2) NULL,
  `deposit_required_subunit` BIGINT UNSIGNED NULL,
  `amount_paid_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `balance_due_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `preferred_delivery_date` DATE NOT NULL,
  `delivery_fee_note` VARCHAR(255) NULL DEFAULT 'Delivery fee may apply and is settled separately.',
  `customer_note` TEXT NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `confirmed_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `cancelled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_number` (`order_number`),
  CONSTRAINT `fk_orders_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (user_id, created_at),
  INDEX (order_status, preferred_delivery_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable delivery address snapshot captured at checkout.
CREATE TABLE IF NOT EXISTS `order_addresses` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `recipient_name` VARCHAR(150) NOT NULL,
  `recipient_phone` VARCHAR(30) NOT NULL,
  `address_line_1` VARCHAR(255) NOT NULL,
  `address_line_2` VARCHAR(255) NULL,
  `city` VARCHAR(100) NOT NULL,
  `state` VARCHAR(100) NOT NULL,
  `landmark` VARCHAR(255) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_addresses_order_id` (`order_id`),
  CONSTRAINT `fk_order_addresses_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Immutable item and price snapshot for every order line.
CREATE TABLE IF NOT EXISTS `order_items` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `item_type` VARCHAR(20) NOT NULL,
  `product_id` BIGINT UNSIGNED NULL,
  `combo_package_id` BIGINT UNSIGNED NULL,
  `item_name` VARCHAR(180) NOT NULL,
  `sku` VARCHAR(80) NOT NULL,
  `unit_name` VARCHAR(80) NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL,
  `unit_price_subunit` BIGINT UNSIGNED NOT NULL,
  `line_total_subunit` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_order_items_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_items_combo_package_id` FOREIGN KEY (`combo_package_id`) REFERENCES `combo_packages` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Snapshot of the products inside a combo order line.
CREATE TABLE IF NOT EXISTS `order_item_components` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_item_id` BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NULL,
  `product_name` VARCHAR(180) NOT NULL,
  `quantity` DECIMAL(10,3) NOT NULL,
  `unit_name` VARCHAR(80) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_order_item_components_order_item_id` FOREIGN KEY (`order_item_id`) REFERENCES `order_items` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_item_components_product_id` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of every order status transition.
CREATE TABLE IF NOT EXISTS `order_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `old_status` VARCHAR(40) NULL,
  `new_status` VARCHAR(40) NOT NULL,
  `source` VARCHAR(30) NOT NULL DEFAULT 'admin',
  `note` VARCHAR(500) NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_order_status_history_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_status_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cancellation details; cancelling never deletes the order or financial history.
CREATE TABLE IF NOT EXISTS `order_cancellations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `cancelled_by` BIGINT UNSIGNED NULL,
  `cancelled_by_type` VARCHAR(20) NOT NULL,
  `reason_code` VARCHAR(50) NULL,
  `reason_text` TEXT NULL,
  `fulfilment_stage` VARCHAR(30) NOT NULL,
  `inventory_action` VARCHAR(30) NOT NULL DEFAULT 'none',
  `refund_required` BOOLEAN NOT NULL DEFAULT FALSE,
  `refund_status` VARCHAR(30) NOT NULL DEFAULT 'not_required',
  `cancelled_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_cancellations_order_id` (`order_id`),
  CONSTRAINT `fk_order_cancellations_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_cancellations_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Confirmed fulfilment schedule for an order; delivery fees remain off-system.
CREATE TABLE IF NOT EXISTS `delivery_schedules` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `delivery_date` DATE NOT NULL,
  `delivery_window` VARCHAR(80) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'scheduled',
  `admin_note` VARCHAR(500) NULL,
  `delivered_at` DATETIME NULL,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_schedules_order_id` (`order_id`),
  CONSTRAINT `fk_delivery_schedules_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_delivery_schedules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (delivery_date, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Internal payment obligation for a deposit, balance, full payment or credit repayment.
CREATE TABLE IF NOT EXISTS `payments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_number` VARCHAR(50) NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(30) NOT NULL DEFAULT 'paystack',
  `payment_type` VARCHAR(30) NOT NULL,
  `expected_amount_subunit` BIGINT UNSIGNED NOT NULL,
  `paid_amount_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `refunded_amount_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `status` VARCHAR(30) NOT NULL DEFAULT 'unpaid',
  `due_at` DATETIME NULL,
  `confirmed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payments_payment_number` (`payment_number`),
  CONSTRAINT `fk_payments_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (order_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One Paystack or manual attempt against a payment obligation.
CREATE TABLE IF NOT EXISTS `payment_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `provider` VARCHAR(30) NOT NULL DEFAULT 'paystack',
  `provider_transaction_id` BIGINT UNSIGNED NULL,
  `reference` VARCHAR(120) NOT NULL,
  `access_code` VARCHAR(120) NULL,
  `authorization_url` VARCHAR(500) NULL,
  `domain` VARCHAR(10) NOT NULL DEFAULT 'test',
  `status` VARCHAR(30) NOT NULL DEFAULT 'initialized',
  `requested_amount_subunit` BIGINT UNSIGNED NOT NULL,
  `amount_subunit` BIGINT UNSIGNED NULL,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `channel` VARCHAR(40) NULL,
  `provider_fee_subunit` BIGINT UNSIGNED NULL,
  `gateway_response` VARCHAR(255) NULL,
  `gateway_response_code` VARCHAR(30) NULL,
  `customer_email` VARCHAR(255) NOT NULL,
  `customer_code` VARCHAR(100) NULL,
  `ip_address` VARCHAR(45) NULL,
  `callback_url` VARCHAR(500) NULL,
  `card_type` VARCHAR(50) NULL,
  `last4` CHAR(4) NULL,
  `bank_name` VARCHAR(150) NULL,
  `metadata` JSON NULL,
  `initialization_response` JSON NULL,
  `verification_response` JSON NULL,
  `initialized_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paid_at` DATETIME NULL,
  `verified_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_transactions_provider_transaction_id` (`provider_transaction_id`),
  UNIQUE KEY `uq_payment_transactions_reference` (`reference`),
  CONSTRAINT `fk_payment_transactions_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (payment_id, status),
  INDEX (domain, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Evidence and approval for manual balance or pay-on-delivery payments.
CREATE TABLE IF NOT EXISTS `manual_payment_proofs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_transaction_id` BIGINT UNSIGNED NOT NULL,
  `proof_url` VARCHAR(500) NULL,
  `bank_reference` VARCHAR(150) NULL,
  `payer_name` VARCHAR(150) NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `reviewed_by` BIGINT UNSIGNED NULL,
  `review_note` VARCHAR(500) NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_manual_payment_proofs_payment_transaction_id` (`payment_transaction_id`),
  CONSTRAINT `fk_manual_payment_proofs_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_manual_payment_proofs_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Durable, idempotent inbox for signed Paystack events.
CREATE TABLE IF NOT EXISTS `payment_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_transaction_id` BIGINT UNSIGNED NULL,
  `event_type` VARCHAR(100) NOT NULL,
  `provider_resource_id` BIGINT UNSIGNED NULL,
  `reference` VARCHAR(120) NULL,
  `deduplication_key` VARCHAR(255) NOT NULL,
  `signature` VARCHAR(255) NOT NULL,
  `signature_valid` BOOLEAN NOT NULL DEFAULT FALSE,
  `payload_hash` CHAR(64) NOT NULL,
  `payload` JSON NOT NULL,
  `processing_status` VARCHAR(30) NOT NULL DEFAULT 'received',
  `duplicate_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME NULL,
  `next_retry_at` DATETIME NULL,
  `error_message` TEXT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_webhook_events_deduplication_key` (`deduplication_key`),
  UNIQUE KEY `uq_payment_webhook_events_payload_hash` (`payload_hash`),
  CONSTRAINT `fk_payment_webhook_events_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (processing_status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only payment and gateway status transitions.
CREATE TABLE IF NOT EXISTS `payment_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id` BIGINT UNSIGNED NOT NULL,
  `payment_transaction_id` BIGINT UNSIGNED NULL,
  `old_status` VARCHAR(30) NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `source` VARCHAR(30) NOT NULL,
  `webhook_event_id` BIGINT UNSIGNED NULL,
  `reason` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_payment_status_history_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_status_history_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_payment_status_history_webhook_event_id` FOREIGN KEY (`webhook_event_id`) REFERENCES `payment_webhook_events` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Full or partial Paystack/manual refunds and their asynchronous lifecycle.
CREATE TABLE IF NOT EXISTS `refunds` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_transaction_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `provider_refund_id` BIGINT UNSIGNED NULL,
  `amount_subunit` BIGINT UNSIGNED NOT NULL,
  `deducted_amount_subunit` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `currency` CHAR(3) NOT NULL DEFAULT 'NGN',
  `status` VARCHAR(30) NOT NULL DEFAULT 'requested',
  `customer_note` TEXT NULL,
  `merchant_note` TEXT NULL,
  `requested_by` BIGINT UNSIGNED NULL,
  `approved_by` BIGINT UNSIGNED NULL,
  `refunded_by_provider` VARCHAR(255) NULL,
  `fully_deducted` BOOLEAN NOT NULL DEFAULT FALSE,
  `expected_at` DATETIME NULL,
  `refunded_at` DATETIME NULL,
  `provider_response` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refunds_provider_refund_id` (`provider_refund_id`),
  CONSTRAINT `fk_refunds_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refunds_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (payment_transaction_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only refund status transitions.
CREATE TABLE IF NOT EXISTS `refund_status_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `refund_id` BIGINT UNSIGNED NOT NULL,
  `old_status` VARCHAR(30) NULL,
  `new_status` VARCHAR(30) NOT NULL,
  `webhook_event_id` BIGINT UNSIGNED NULL,
  `note` TEXT NULL,
  `changed_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_refund_status_history_refund_id` FOREIGN KEY (`refund_id`) REFERENCES `refunds` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_status_history_webhook_event_id` FOREIGN KEY (`webhook_event_id`) REFERENCES `payment_webhook_events` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_refund_status_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paystack payouts to the business bank account for reconciliation.
CREATE TABLE IF NOT EXISTS `settlements` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `provider_settlement_id` BIGINT UNSIGNED NOT NULL,
  `domain` VARCHAR(10) NOT NULL,
  `status` VARCHAR(30) NOT NULL,
  `currency` CHAR(3) NOT NULL,
  `total_amount_subunit` BIGINT NOT NULL,
  `effective_amount_subunit` BIGINT NOT NULL,
  `total_fees_subunit` BIGINT NOT NULL,
  `total_processed_subunit` BIGINT NOT NULL,
  `deductions` JSON NULL,
  `settlement_date` DATETIME NULL,
  `settled_by` VARCHAR(255) NULL,
  `provider_response` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settlements_provider_settlement_id` (`provider_settlement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transactions included in a Paystack settlement and reconciliation result.
CREATE TABLE IF NOT EXISTS `settlement_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `settlement_id` BIGINT UNSIGNED NOT NULL,
  `payment_transaction_id` BIGINT UNSIGNED NULL,
  `provider_transaction_id` BIGINT UNSIGNED NOT NULL,
  `gross_amount_subunit` BIGINT NOT NULL,
  `fee_subunit` BIGINT NOT NULL,
  `net_amount_subunit` BIGINT NOT NULL,
  `reconciliation_status` VARCHAR(30) NOT NULL DEFAULT 'unmatched',
  `reconciled_at` DATETIME NULL,
  `reconciliation_note` TEXT NULL,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_settlement_transactions_settlement_id` FOREIGN KEY (`settlement_id`) REFERENCES `settlements` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_settlement_transactions_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  UNIQUE (settlement_id, provider_transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Paystack charge disputes associated with successful transactions.
CREATE TABLE IF NOT EXISTS `payment_disputes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_transaction_id` BIGINT UNSIGNED NOT NULL,
  `provider_dispute_id` BIGINT UNSIGNED NOT NULL,
  `status` VARCHAR(50) NOT NULL,
  `domain` VARCHAR(10) NOT NULL,
  `currency` CHAR(3) NULL,
  `refund_amount_subunit` BIGINT UNSIGNED NULL,
  `resolution` VARCHAR(50) NULL,
  `resolution_message` TEXT NULL,
  `evidence_deadline_at` DATETIME NULL,
  `resolved_at` DATETIME NULL,
  `provider_response` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_disputes_provider_dispute_id` (`provider_dispute_id`),
  CONSTRAINT `fk_payment_disputes_payment_transaction_id` FOREIGN KEY (`payment_transaction_id`) REFERENCES `payment_transactions` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery and service evidence submitted for a payment dispute.
CREATE TABLE IF NOT EXISTS `dispute_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dispute_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `customer_name` VARCHAR(150) NOT NULL,
  `customer_email` VARCHAR(255) NOT NULL,
  `customer_phone` VARCHAR(30) NOT NULL,
  `service_details` TEXT NOT NULL,
  `delivery_address` TEXT NULL,
  `delivery_date` DATE NULL,
  `document_url` VARCHAR(500) NULL,
  `provider_evidence_id` BIGINT UNSIGNED NULL,
  `submitted_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_dispute_evidence_dispute_id` FOREIGN KEY (`dispute_id`) REFERENCES `payment_disputes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_dispute_evidence_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2B application for delayed payment terms.
CREATE TABLE IF NOT EXISTS `credit_applications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_customer_id` BIGINT UNSIGNED NOT NULL,
  `requested_days` SMALLINT UNSIGNED NOT NULL,
  `requested_limit_subunit` BIGINT UNSIGNED NULL,
  `reason` TEXT NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'pending',
  `reviewed_by` BIGINT UNSIGNED NULL,
  `reviewed_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_credit_applications_business_customer_id` FOREIGN KEY (`business_customer_id`) REFERENCES `business_customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_applications_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- B2B credit charges, repayments and adjustments tied to orders.
CREATE TABLE IF NOT EXISTS `credit_transactions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `business_customer_id` BIGINT UNSIGNED NOT NULL,
  `order_id` BIGINT UNSIGNED NULL,
  `payment_id` BIGINT UNSIGNED NULL,
  `transaction_type` VARCHAR(30) NOT NULL,
  `amount_subunit` BIGINT NOT NULL,
  `due_date` DATE NULL,
  `paid_at` DATETIME NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'open',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_credit_transactions_business_customer_id` FOREIGN KEY (`business_customer_id`) REFERENCES `business_customers` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_transactions_order_id` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_credit_transactions_payment_id` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reusable templates for email, admin/in-app, SMS and messaging channels.
CREATE TABLE IF NOT EXISTS `notification_templates` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_key` VARCHAR(100) NOT NULL,
  `channel` VARCHAR(30) NOT NULL,
  `subject_template` VARCHAR(255) NULL,
  `body_template` TEXT NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_templates_template_key` (`template_key`),
  CONSTRAINT `fk_notification_templates_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global business notification generated from an order, payment, refund or system event.
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event_type` VARCHAR(100) NOT NULL,
  `related_type` VARCHAR(80) NULL,
  `related_id` BIGINT UNSIGNED NULL,
  `template_id` BIGINT UNSIGNED NULL,
  `title` VARCHAR(255) NULL,
  `body` TEXT NOT NULL,
  `priority` VARCHAR(20) NOT NULL DEFAULT 'normal',
  `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
  `created_by` BIGINT UNSIGNED NULL,
  `scheduled_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notifications_template_id` FOREIGN KEY (`template_id`) REFERENCES `notification_templates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (related_type, related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One recipient/channel delivery attempt for a global notification.
CREATE TABLE IF NOT EXISTS `notification_deliveries` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` BIGINT UNSIGNED NOT NULL,
  `user_id` BIGINT UNSIGNED NULL,
  `channel` VARCHAR(30) NOT NULL,
  `recipient_address` VARCHAR(255) NOT NULL,
  `provider` VARCHAR(80) NULL,
  `provider_message_id` VARCHAR(255) NULL,
  `status` VARCHAR(30) NOT NULL DEFAULT 'queued',
  `attempt_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `queued_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` DATETIME NULL,
  `delivered_at` DATETIME NULL,
  `read_at` DATETIME NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_notification_deliveries_notification_id` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_notification_deliveries_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (status, queued_at),
  INDEX (notification_id, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Global record of sensitive create, update, cancel, approval and configuration actions.
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` BIGINT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(100) NOT NULL,
  `entity_id` BIGINT UNSIGNED NULL,
  `old_values` JSON NULL,
  `new_values` JSON NULL,
  `request_id` VARCHAR(100) NULL,
  `ip_address` VARCHAR(45) NULL,
  `user_agent` VARCHAR(500) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `fk_audit_logs_actor_user_id` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  INDEX (entity_type, entity_id, created_at),
  INDEX (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Non-secret configurable application settings.
CREATE TABLE IF NOT EXISTS `site_settings` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(120) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `value_type` VARCHAR(20) NOT NULL DEFAULT 'string',
  `is_public` BOOLEAN NOT NULL DEFAULT FALSE,
  `updated_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_site_settings_setting_key` (`setting_key`),
  CONSTRAINT `fk_site_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed the client-approved household delivery weekdays.
INSERT IGNORE INTO allowed_delivery_days (customer_type, day_of_week, is_active, minimum_lead_days) VALUES
('household',1,TRUE,0),('household',3,TRUE,0),('household',4,TRUE,0),('household',6,TRUE,0);

-- Cancellation workflow (perform inside one application transaction):
-- 1. UPDATE orders SET order_status='cancelled', cancelled_at=NOW() WHERE id=?;
-- 2. INSERT order_cancellations(...);
-- 3. INSERT order_status_history(... old_status, 'cancelled' ...);
-- 4. create a refund when paid_amount_subunit > refunded_amount_subunit and policy requires it;
-- 5. INSERT audit_logs with old_values and new_values. Never DELETE the order.
