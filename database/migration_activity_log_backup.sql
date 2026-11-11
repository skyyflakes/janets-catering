-- =====================================================
-- ACTIVITY LOG + DATABASE BACKUP MODULE
-- Janet's Quality Catering Services  (MariaDB 10.2+ / MySQL 8)
-- =====================================================
-- You normally do NOT need to import this file: includes/activity_logger.php
-- installs/upgrades these tables automatically on the first page load and
-- generates the audit triggers from the LIVE table columns
-- (Activity Log > "Re-sync tracking" regenerates them on demand).
-- This file documents the schema and can be imported manually if the
-- web database user is not allowed to run CREATE / ALTER / TRIGGER.
-- =====================================================

USE janets_catering;

-- Existing, previously unused table: extended (not replaced)
ALTER TABLE `activity_logs`
  ADD COLUMN IF NOT EXISTS `username`     VARCHAR(100) NULL AFTER `user_id`,
  ADD COLUMN IF NOT EXISTS `user_role`    VARCHAR(20)  NULL AFTER `username`,
  ADD COLUMN IF NOT EXISTS `module`       VARCHAR(100) NULL AFTER `action`,
  ADD COLUMN IF NOT EXISTS `record_table` VARCHAR(64)  NULL AFTER `module`,
  ADD COLUMN IF NOT EXISTS `record_id`    VARCHAR(64)  NULL AFTER `record_table`,
  ADD COLUMN IF NOT EXISTS `record_label` VARCHAR(255) NULL AFTER `record_id`,
  ADD COLUMN IF NOT EXISTS `old_values`   LONGTEXT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `new_values`   LONGTEXT NULL AFTER `old_values`,
  ADD COLUMN IF NOT EXISTS `user_agent`   VARCHAR(255) NULL AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `page`         VARCHAR(100) NULL AFTER `user_agent`,
  ADD COLUMN IF NOT EXISTS `source`       VARCHAR(20) NOT NULL DEFAULT 'system' AFTER `page`,
  ADD COLUMN IF NOT EXISTS `notes`        TEXT NULL AFTER `source`,
  ADD COLUMN IF NOT EXISTS `is_archived`  TINYINT(1) NOT NULL DEFAULT 0 AFTER `notes`,
  ADD COLUMN IF NOT EXISTS `archived_at`  DATETIME(3) NULL AFTER `is_archived`,
  ADD COLUMN IF NOT EXISTS `archived_by`  INT NULL AFTER `archived_at`,
  ADD COLUMN IF NOT EXISTS `restored_at`  DATETIME(3) NULL AFTER `archived_by`,
  ADD COLUMN IF NOT EXISTS `restored_by`  INT NULL AFTER `restored_at`,
  ADD COLUMN IF NOT EXISTS `edited_at`    DATETIME(3) NULL AFTER `restored_by`,
  ADD COLUMN IF NOT EXISTS `edited_by`    INT NULL AFTER `edited_at`,
  ADD COLUMN IF NOT EXISTS `edit_count`   INT NOT NULL DEFAULT 0 AFTER `edited_by`,
  MODIFY `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  ADD COLUMN IF NOT EXISTS `updated_at`   TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`,
  ADD INDEX IF NOT EXISTS `idx_al_action` (`action`),
  ADD INDEX IF NOT EXISTS `idx_al_module` (`module`),
  ADD INDEX IF NOT EXISTS `idx_al_record` (`record_table`, `record_id`),
  ADD INDEX IF NOT EXISTS `idx_al_archived` (`is_archived`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_al_username` (`username`);

-- Who archived / restored a booking
ALTER TABLE `event_bookings`
  ADD COLUMN IF NOT EXISTS `archived_by` INT NULL,
  ADD COLUMN IF NOT EXISTS `restored_at` DATETIME NULL,
  ADD COLUMN IF NOT EXISTS `restored_by` INT NULL;

CREATE TABLE IF NOT EXISTS `system_settings` (
  `setting_key`   VARCHAR(100) NOT NULL PRIMARY KEY,
  `setting_value` TEXT NULL,
  `updated_by`    INT NULL,
  `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only history of activity-log management (edit / archive / restore / manual create)
CREATE TABLE IF NOT EXISTS `activity_log_audit` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `log_id` INT NOT NULL,
  `operation` VARCHAR(20) NOT NULL,
  `old_snapshot` LONGTEXT NULL,
  `new_snapshot` LONGTEXT NULL,
  `reason` VARCHAR(500) NULL,
  `performed_by` INT NULL,
  `performed_by_username` VARCHAR(100) NULL,
  `performed_by_role` VARCHAR(20) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY `idx_ala_log` (`log_id`), KEY `idx_ala_created` (`created_at`), KEY `idx_ala_operation` (`operation`),
  CONSTRAINT `fk_ala_log` FOREIGN KEY (`log_id`) REFERENCES `activity_logs` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `database_backups` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `filename` VARCHAR(255) NOT NULL,
  `backup_type` VARCHAR(20) NOT NULL DEFAULT 'manual',
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `checksum_sha256` CHAR(64) NULL,
  `table_count` INT NOT NULL DEFAULT 0,
  `row_count` BIGINT NOT NULL DEFAULT 0,
  `db_name` VARCHAR(64) NULL,
  `server_version` VARCHAR(100) NULL,
  `secondary_copy` TINYINT(1) NOT NULL DEFAULT 0,
  `secondary_path` VARCHAR(500) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'available',
  `verify_status` VARCHAR(20) NULL,
  `last_verified_at` DATETIME NULL,
  `notes` VARCHAR(500) NULL,
  `created_by` INT NULL,
  `created_by_username` VARCHAR(100) NULL,
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_backup_filename` (`filename`),
  KEY `idx_backup_type` (`backup_type`), KEY `idx_backup_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `backup_history` (
  `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `backup_id` INT NULL,
  `filename` VARCHAR(255) NULL,
  `action` VARCHAR(30) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'success',
  `details` TEXT NULL,
  `duration_ms` INT NULL,
  `performed_by` INT NULL,
  `performed_by_username` VARCHAR(100) NULL,
  `performed_by_role` VARCHAR(20) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  KEY `idx_bh_backup` (`backup_id`), KEY `idx_bh_created` (`created_at`), KEY `idx_bh_action` (`action`),
  CONSTRAINT `fk_bh_backup` FOREIGN KEY (`backup_id`) REFERENCES `database_backups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Triggers (generated by the application – see jq_install_triggers()):
--   trg_jq_<table>_ai / _au / _ad   on event_bookings, users, menu_packages, menu_items, menu_bundles,
--                                    foodstocks, foodstocks_categories, suppliers, client_surveys, reports, backdrops
--   trg_jq_activity_logs_bi/_ai/_bu/_bd, trg_jq_log_audit_bu/_bd, trg_jq_backup_history_bu/_bd  (tamper protection)
