-- Janet's Quality Catering: database update 2.6.0
-- Adds verification link tokens and Terms and Conditions acceptance records, and removes the Suppliers data.
--
-- You normally do not need to run this. The system applies the same update by itself the next time a page opens.
-- Use it only to update a database by hand. Take a backup first: removing suppliers cannot be undone without one.
-- Safe to run more than once. Open the system once afterwards, so it rebuilds its activity log triggers.
--
-- Custom event types need no change: event_types already stores them, and its unique name index ignores
-- letter case, so the database refuses duplicates.

-- 1. Verification links. Only a SHA-256 hash of each token is stored, never the token in the email.
ALTER TABLE `email_verifications` ADD COLUMN IF NOT EXISTS `token_hash` CHAR(64) NULL DEFAULT NULL AFTER `code_hash`;
ALTER TABLE `email_verifications` ADD UNIQUE KEY IF NOT EXISTS `uq_ev_token` (`token_hash`);

-- 2. Terms and Conditions acceptance, one row per account and version of the terms.
CREATE TABLE IF NOT EXISTS `terms_acceptances` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL,
  `terms_version` VARCHAR(20) NOT NULL,
  `accepted_at` DATETIME NOT NULL COMMENT 'First acceptance of this version',
  `last_accepted_at` DATETIME NOT NULL COMMENT 'Most recent sign-in that accepted it',
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terms_user_version` (`user_id`, `terms_version`),
  CONSTRAINT `fk_terms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Suppliers. The only foreign key pointing at this table is foodstocks.supplier_id, so it goes first.
--    The foodstocks rows stay. The foodstocks table may already be gone if the optional clean-up was run.
DROP TRIGGER IF EXISTS `trg_jq_foodstocks_ai`;
DROP TRIGGER IF EXISTS `trg_jq_foodstocks_au`;
DROP TRIGGER IF EXISTS `trg_jq_foodstocks_ad`;
DROP TRIGGER IF EXISTS `trg_jq_suppliers_ai`;
DROP TRIGGER IF EXISTS `trg_jq_suppliers_au`;
DROP TRIGGER IF EXISTS `trg_jq_suppliers_ad`;

SET @jq_has_stocks := (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foodstocks');
SET @jq_sql := IF(@jq_has_stocks > 0, 'ALTER TABLE `foodstocks` DROP FOREIGN KEY IF EXISTS `foodstocks_ibfk_2`', 'DO 0');
PREPARE jq_stmt FROM @jq_sql; EXECUTE jq_stmt; DEALLOCATE PREPARE jq_stmt;
SET @jq_sql := IF(@jq_has_stocks > 0, 'ALTER TABLE `foodstocks` DROP COLUMN IF EXISTS `supplier_id`', 'DO 0');
PREPARE jq_stmt FROM @jq_sql; EXECUTE jq_stmt; DEALLOCATE PREPARE jq_stmt;

DROP TABLE IF EXISTS `suppliers`;
