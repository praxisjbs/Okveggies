-- =============================================================================
-- 028. Additional secure Order Trail share links.
--
-- Only SHA-256 hashes are stored. The original order token remains valid, and
-- issuing another link never reveals or replaces its hash.
-- =============================================================================

START TRANSACTION;

CREATE TABLE IF NOT EXISTS `order_trail_share_links` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` BIGINT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `created_by` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_trail_share_links_hash` (`token_hash`),
  KEY `idx_order_trail_share_links_order` (`order_id`, `created_at`),
  CONSTRAINT `fk_order_trail_share_links_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_order_trail_share_links_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;

-- Verification:
--   SELECT TABLE_NAME FROM information_schema.TABLES
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_trail_share_links';
--   SELECT INDEX_NAME FROM information_schema.STATISTICS
--    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'order_trail_share_links'
--      AND INDEX_NAME IN ('uq_order_trail_share_links_hash','idx_order_trail_share_links_order');
