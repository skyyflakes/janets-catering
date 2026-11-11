-- Janet's Quality Catering: complete database structure, version 2.9.0
-- Tables, keys, constraints and triggers only. It holds no accounts, bookings or other records.
-- Create the database first: CREATE DATABASE janets_catering CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Reference data: load database/psgc_address_tables.sql and database/migration_seed_food_menus.sql afterwards.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `activity_log_audit`
--

DROP TABLE IF EXISTS `activity_log_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_log_audit` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `log_id` int(11) NOT NULL,
  `operation` varchar(20) NOT NULL,
  `old_snapshot` longtext DEFAULT NULL,
  `new_snapshot` longtext DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_by_username` varchar(100) DEFAULT NULL,
  `performed_by_role` varchar(20) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_ala_log` (`log_id`),
  KEY `idx_ala_created` (`created_at`),
  KEY `idx_ala_operation` (`operation`),
  CONSTRAINT `fk_ala_log` FOREIGN KEY (`log_id`) REFERENCES `activity_logs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_log_audit_bu` BEFORE UPDATE ON `activity_log_audit` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The activity log audit trail is append-only.' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_log_audit_bd` BEFORE DELETE ON `activity_log_audit` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The activity log audit trail is append-only.' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `activity_logs`
--

DROP TABLE IF EXISTS `activity_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `user_role` varchar(20) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `module` varchar(100) DEFAULT NULL,
  `record_table` varchar(64) DEFAULT NULL,
  `record_id` varchar(64) DEFAULT NULL,
  `record_label` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `old_values` longtext DEFAULT NULL,
  `new_values` longtext DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `page` varchar(100) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'system',
  `notes` text DEFAULT NULL,
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime(3) DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `restored_at` datetime(3) DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  `edited_at` datetime(3) DEFAULT NULL,
  `edited_by` int(11) DEFAULT NULL,
  `edit_count` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_created` (`created_at`),
  KEY `idx_al_action` (`action`),
  KEY `idx_al_module` (`module`),
  KEY `idx_al_record` (`record_table`,`record_id`),
  KEY `idx_al_archived` (`is_archived`,`created_at`),
  KEY `idx_al_username` (`username`),
  CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_activity_logs_bi` BEFORE INSERT ON `activity_logs` FOR EACH ROW
            BEGIN
                SET NEW.created_at = CURRENT_TIMESTAMP(3), NEW.is_archived = 0, NEW.archived_at = NULL, NEW.archived_by = NULL,
                    NEW.restored_at = NULL, NEW.restored_by = NULL, NEW.edited_at = NULL, NEW.edited_by = NULL, NEW.edit_count = 0;
                IF NEW.source = 'manual' AND COALESCE(@app_role, '') <> 'OWNER' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only the OWNER can create manual activity log entries.';
                END IF;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_activity_logs_ai` AFTER INSERT ON `activity_logs` FOR EACH ROW
            BEGIN
                IF NEW.source = 'manual' THEN
                    INSERT INTO `activity_log_audit` (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
                    VALUES (NEW.id, 'CREATE', NULL, JSON_OBJECT('action', NEW.action, 'module', NEW.module, 'description', NEW.description,
        'record_label', NEW.record_label, 'notes', NEW.notes, 'is_archived', NEW.is_archived, 'edit_count', NEW.edit_count), LEFT(COALESCE(@app_log_reason, 'Manual entry'), 500),
                            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(@app_username, 100), @app_role, @app_ip);
                END IF;
                BEGIN
                    -- A notification problem must never block the business operation being logged.
                    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION BEGIN END;
                    IF NEW.action IN ('CREATE','UPDATE','STATUS_CHANGE','ARCHIVE','RESTORE','DELETE','BACKUP_CREATE','BACKUP_RESTORE','BACKUP_FAILED','LOG_ARCHIVE','LOG_RESTORE','RETENTION_PURGE') THEN
                        INSERT IGNORE INTO `notifications` (user_id, type, title, message, reference_id, reference_type, actor_user_id, actor_username, activity_log_id)
                        SELECT u.`id`,
                               CASE WHEN NEW.record_table IN ('event_bookings','booking_payments','booking_attachments','event_types','clients') THEN 'event'
                                    WHEN NEW.record_table = 'reports' THEN 'report'
                                    WHEN NEW.record_table = 'client_surveys' THEN 'feedback'
                                    WHEN NEW.record_table IN ('foodstocks','foodstocks_categories','menu_packages','menu_items','menu_bundles') THEN 'inventory'
                                    ELSE 'system' END,
                               CASE WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'CREATE' THEN 'New booking'
                                    WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'STATUS_CHANGE' THEN 'Booking status changed'
                                    WHEN NEW.record_table = 'event_bookings' AND NEW.action = 'UPDATE' THEN 'Booking updated'
                                    WHEN NEW.record_table = 'users' AND NEW.action = 'CREATE' THEN 'New user account'
                                    WHEN NEW.record_table = 'users' THEN 'User account changed'
                                    WHEN NEW.record_table IN ('menu_packages','menu_items','menu_bundles') THEN 'Menu or package changed'
                                    WHEN NEW.action = 'BACKUP_CREATE' THEN 'Database backup created'
                                    WHEN NEW.action = 'BACKUP_RESTORE' THEN 'Database restored'
                                    WHEN NEW.action = 'BACKUP_FAILED' THEN 'Backup operation failed'
                                    WHEN NEW.action IN ('LOG_ARCHIVE','LOG_RESTORE') THEN 'Activity logs managed'
                                    ELSE CONCAT(COALESCE(NEW.module, 'System'), ' record changed') END,
                               COALESCE(NEW.description, ''),
                               IF(NEW.record_id REGEXP '^[0-9]+$', CAST(NEW.record_id AS UNSIGNED), NULL),
                               NEW.record_table, NEW.user_id, NEW.username, NEW.id
                          FROM `users` u
                         WHERE u.`role` IN ('OWNER','ADMIN') AND u.`status` = 'active' AND u.`is_verified` = 1 AND u.`is_archived` = 0;
                    END IF;
                END;
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_activity_logs_bu` BEFORE UPDATE ON `activity_logs` FOR EACH ROW
            BEGIN
                DECLARE v_op VARCHAR(20);
                IF COALESCE(@app_log_mgmt, 0) <> 1 OR COALESCE(@app_role, '') <> 'OWNER' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log records are protected. Only the OWNER can archive them through the Activity Log module.';
                END IF;
                IF NOT (OLD.user_id <=> NEW.user_id AND OLD.username <=> NEW.username AND OLD.user_role <=> NEW.user_role
                        AND OLD.created_at <=> NEW.created_at AND OLD.ip_address <=> NEW.ip_address AND OLD.user_agent <=> NEW.user_agent
                        AND OLD.record_table <=> NEW.record_table AND OLD.record_id <=> NEW.record_id AND OLD.record_label <=> NEW.record_label
                        AND OLD.old_values <=> NEW.old_values AND OLD.new_values <=> NEW.new_values
                        AND OLD.action <=> NEW.action AND OLD.module <=> NEW.module AND OLD.description <=> NEW.description AND OLD.notes <=> NEW.notes
                        AND OLD.source <=> NEW.source AND OLD.page <=> NEW.page) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log records cannot be edited.';
                END IF;
                IF COALESCE(OLD.is_archived,0) = 0 AND COALESCE(NEW.is_archived,0) = 1 THEN
                    SET v_op = 'ARCHIVE', NEW.archived_at = CURRENT_TIMESTAMP(3), NEW.archived_by = @app_user_id,
                        NEW.restored_at = OLD.restored_at, NEW.restored_by = OLD.restored_by,
                        NEW.edited_at = OLD.edited_at, NEW.edited_by = OLD.edited_by, NEW.edit_count = OLD.edit_count;
                ELSEIF COALESCE(OLD.is_archived,0) = 1 AND COALESCE(NEW.is_archived,0) = 0 THEN
                    SET v_op = 'RESTORE', NEW.restored_at = CURRENT_TIMESTAMP(3), NEW.restored_by = @app_user_id,
                        NEW.archived_at = OLD.archived_at, NEW.archived_by = OLD.archived_by,
                        NEW.edited_at = OLD.edited_at, NEW.edited_by = OLD.edited_by, NEW.edit_count = OLD.edit_count;
                ELSE
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log records cannot be edited.';
                END IF;
                INSERT INTO `activity_log_audit` (log_id, operation, old_snapshot, new_snapshot, reason, performed_by, performed_by_username, performed_by_role, ip_address)
                VALUES (OLD.id, v_op, JSON_OBJECT('action', OLD.action, 'module', OLD.module, 'description', OLD.description,
        'record_label', OLD.record_label, 'notes', OLD.notes, 'is_archived', OLD.is_archived, 'edit_count', OLD.edit_count), JSON_OBJECT('action', NEW.action, 'module', NEW.module, 'description', NEW.description,
        'record_label', NEW.record_label, 'notes', NEW.notes, 'is_archived', NEW.is_archived, 'edit_count', NEW.edit_count), LEFT(@app_log_reason, 500),
                        (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(@app_username, 100), @app_role, @app_ip);
            END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_activity_logs_bd` BEFORE DELETE ON `activity_logs` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Activity log records cannot be deleted. Archive them instead.' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `backdrops`
--

DROP TABLE IF EXISTS `backdrops`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backdrops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `backdrop_name` varchar(255) NOT NULL,
  `event_type` varchar(100) DEFAULT NULL,
  `image_path` varchar(500) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_event_type` (`event_type`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_backdrops_ai` AFTER INSERT ON `backdrops` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Backdrops', 'backdrops', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`backdrop_name`), ''), 255),
            CONCAT('Created backdrop #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`backdrop_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null')), CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_name` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null')), CONCAT('"image_path":', IFNULL(JSON_QUOTE(CAST(NEW.`image_path` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_backdrops_au` AFTER UPDATE ON `backdrops` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`id` <=> NEW.`id`) AND (OLD.`backdrop_name` <=> NEW.`backdrop_name`) AND (OLD.`event_type` <=> NEW.`event_type`) AND (OLD.`image_path` <=> NEW.`image_path`) AND (OLD.`description` <=> NEW.`description`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Backdrops', 'backdrops', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`backdrop_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' backdrop #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`backdrop_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null'))), IF(OLD.`backdrop_name` <=> NEW.`backdrop_name`, NULL, CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_name` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null'))), IF(OLD.`image_path` <=> NEW.`image_path`, NULL, CONCAT('"image_path":', IFNULL(JSON_QUOTE(CAST(OLD.`image_path` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null'))), IF(OLD.`backdrop_name` <=> NEW.`backdrop_name`, NULL, CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_name` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null'))), IF(OLD.`image_path` <=> NEW.`image_path`, NULL, CONCAT('"image_path":', IFNULL(JSON_QUOTE(CAST(NEW.`image_path` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_backdrops_ad` AFTER DELETE ON `backdrops` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Backdrops', 'backdrops', CAST(OLD.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`backdrop_name`), ''), 255),
            CONCAT('Deleted backdrop #', OLD.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`backdrop_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null')), CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_name` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null')), CONCAT('"image_path":', IFNULL(JSON_QUOTE(CAST(OLD.`image_path` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `backup_history`
--

DROP TABLE IF EXISTS `backup_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `backup_id` int(11) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'success',
  `details` text DEFAULT NULL,
  `duration_ms` int(11) DEFAULT NULL,
  `performed_by` int(11) DEFAULT NULL,
  `performed_by_username` varchar(100) DEFAULT NULL,
  `performed_by_role` varchar(20) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  PRIMARY KEY (`id`),
  KEY `idx_bh_backup` (`backup_id`),
  KEY `idx_bh_created` (`created_at`),
  KEY `idx_bh_action` (`action`),
  CONSTRAINT `fk_bh_backup` FOREIGN KEY (`backup_id`) REFERENCES `database_backups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_backup_history_bu` BEFORE UPDATE ON `backup_history` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Backup history is append-only.' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_backup_history_bd` BEFORE DELETE ON `backup_history` FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Backup history is append-only.' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `booking_attachments`
--

DROP TABLE IF EXISTS `booking_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_attachments` (
  `attachment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `document_type` enum('Signed Contract','Client Signature','Approval Document','Confirmation Document','Other') NOT NULL DEFAULT 'Other',
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attachment_id`),
  KEY `idx_attachment_booking` (`booking_id`),
  KEY `fk_attachment_user` (`uploaded_by`),
  CONSTRAINT `fk_attachment_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attachment_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_attachments_ai` AFTER INSERT ON `booking_attachments` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Event Bookings', 'booking_attachments', CAST(NEW.`attachment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`document_type`, NEW.`original_name`), ''), 255),
            CONCAT('Created booking attachment #', NEW.`attachment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`document_type`, NEW.`original_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"attachment_id":', IFNULL(JSON_QUOTE(CAST(NEW.`attachment_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null')), CONCAT('"document_type":', IFNULL(JSON_QUOTE(CAST(NEW.`document_type` AS CHAR)), 'null')), CONCAT('"original_name":', IFNULL(JSON_QUOTE(CAST(NEW.`original_name` AS CHAR)), 'null')), CONCAT('"stored_path":', IFNULL(JSON_QUOTE(CAST(NEW.`stored_path` AS CHAR)), 'null')), CONCAT('"mime_type":', IFNULL(JSON_QUOTE(CAST(NEW.`mime_type` AS CHAR)), 'null')), CONCAT('"file_size":', IFNULL(JSON_QUOTE(CAST(NEW.`file_size` AS CHAR)), 'null')), CONCAT('"uploaded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`uploaded_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_attachments_au` AFTER UPDATE ON `booking_attachments` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`attachment_id` <=> NEW.`attachment_id`) AND (OLD.`booking_id` <=> NEW.`booking_id`) AND (OLD.`document_type` <=> NEW.`document_type`) AND (OLD.`original_name` <=> NEW.`original_name`) AND (OLD.`stored_path` <=> NEW.`stored_path`) AND (OLD.`mime_type` <=> NEW.`mime_type`) AND (OLD.`file_size` <=> NEW.`file_size`) AND (OLD.`uploaded_by` <=> NEW.`uploaded_by`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Event Bookings', 'booking_attachments', CAST(NEW.`attachment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`document_type`, NEW.`original_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' booking attachment #', NEW.`attachment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`document_type`, NEW.`original_name`), ''), ')'), '')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`attachment_id` <=> NEW.`attachment_id`, NULL, CONCAT('"attachment_id":', IFNULL(JSON_QUOTE(CAST(OLD.`attachment_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null'))), IF(OLD.`document_type` <=> NEW.`document_type`, NULL, CONCAT('"document_type":', IFNULL(JSON_QUOTE(CAST(OLD.`document_type` AS CHAR)), 'null'))), IF(OLD.`original_name` <=> NEW.`original_name`, NULL, CONCAT('"original_name":', IFNULL(JSON_QUOTE(CAST(OLD.`original_name` AS CHAR)), 'null'))), IF(OLD.`stored_path` <=> NEW.`stored_path`, NULL, CONCAT('"stored_path":', IFNULL(JSON_QUOTE(CAST(OLD.`stored_path` AS CHAR)), 'null'))), IF(OLD.`mime_type` <=> NEW.`mime_type`, NULL, CONCAT('"mime_type":', IFNULL(JSON_QUOTE(CAST(OLD.`mime_type` AS CHAR)), 'null'))), IF(OLD.`file_size` <=> NEW.`file_size`, NULL, CONCAT('"file_size":', IFNULL(JSON_QUOTE(CAST(OLD.`file_size` AS CHAR)), 'null'))), IF(OLD.`uploaded_by` <=> NEW.`uploaded_by`, NULL, CONCAT('"uploaded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`uploaded_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`attachment_id` <=> NEW.`attachment_id`, NULL, CONCAT('"attachment_id":', IFNULL(JSON_QUOTE(CAST(NEW.`attachment_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null'))), IF(OLD.`document_type` <=> NEW.`document_type`, NULL, CONCAT('"document_type":', IFNULL(JSON_QUOTE(CAST(NEW.`document_type` AS CHAR)), 'null'))), IF(OLD.`original_name` <=> NEW.`original_name`, NULL, CONCAT('"original_name":', IFNULL(JSON_QUOTE(CAST(NEW.`original_name` AS CHAR)), 'null'))), IF(OLD.`stored_path` <=> NEW.`stored_path`, NULL, CONCAT('"stored_path":', IFNULL(JSON_QUOTE(CAST(NEW.`stored_path` AS CHAR)), 'null'))), IF(OLD.`mime_type` <=> NEW.`mime_type`, NULL, CONCAT('"mime_type":', IFNULL(JSON_QUOTE(CAST(NEW.`mime_type` AS CHAR)), 'null'))), IF(OLD.`file_size` <=> NEW.`file_size`, NULL, CONCAT('"file_size":', IFNULL(JSON_QUOTE(CAST(NEW.`file_size` AS CHAR)), 'null'))), IF(OLD.`uploaded_by` <=> NEW.`uploaded_by`, NULL, CONCAT('"uploaded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`uploaded_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_attachments_ad` AFTER DELETE ON `booking_attachments` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Event Bookings', 'booking_attachments', CAST(OLD.`attachment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`document_type`, OLD.`original_name`), ''), 255),
            CONCAT('Deleted booking attachment #', OLD.`attachment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`document_type`, OLD.`original_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"attachment_id":', IFNULL(JSON_QUOTE(CAST(OLD.`attachment_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null')), CONCAT('"document_type":', IFNULL(JSON_QUOTE(CAST(OLD.`document_type` AS CHAR)), 'null')), CONCAT('"original_name":', IFNULL(JSON_QUOTE(CAST(OLD.`original_name` AS CHAR)), 'null')), CONCAT('"stored_path":', IFNULL(JSON_QUOTE(CAST(OLD.`stored_path` AS CHAR)), 'null')), CONCAT('"mime_type":', IFNULL(JSON_QUOTE(CAST(OLD.`mime_type` AS CHAR)), 'null')), CONCAT('"file_size":', IFNULL(JSON_QUOTE(CAST(OLD.`file_size` AS CHAR)), 'null')), CONCAT('"uploaded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`uploaded_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `booking_inclusions`
--

DROP TABLE IF EXISTS `booking_inclusions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_inclusions` (
  `booking_inclusion_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `source_type` enum('package','bundle') NOT NULL,
  `source_id` int(11) DEFAULT NULL,
  `source_name` varchar(200) NOT NULL,
  `inclusion_text` varchar(200) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`booking_inclusion_id`),
  KEY `idx_binc_booking` (`booking_id`),
  CONSTRAINT `fk_binc_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_items`
--

DROP TABLE IF EXISTS `booking_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `ref_type` enum('item','bundle') NOT NULL DEFAULT 'item',
  `ref_id` int(11) NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `price_mode` enum('flat','per_guest') DEFAULT 'per_guest',
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` decimal(12,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_booking` (`booking_id`),
  CONSTRAINT `fk_bi_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `booking_payments`
--

DROP TABLE IF EXISTS `booking_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `booking_payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) NOT NULL,
  `payment_type` enum('Downpayment','Additional Payment') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('Cash','GCash','Bank Transfer','Check','Credit or Debit Card','Other') NOT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `receipt_path` varchar(255) DEFAULT NULL,
  `receipt_name` varchar(255) DEFAULT NULL,
  `receipt_mime` varchar(100) DEFAULT NULL,
  `verification_status` enum('Awaiting Receipt','Receipt Submitted','Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `idx_payment_booking` (`booking_id`,`payment_date`),
  KEY `fk_payment_user` (`recorded_by`),
  KEY `fk_payment_reviewer` (`reviewed_by`),
  CONSTRAINT `fk_payment_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payment_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_payment_user` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_payment_amount` CHECK (`amount` > 0),
  CONSTRAINT `chk_payment_verified_receipt` CHECK (`verification_status` <> 'Verified' or `receipt_path` is not null)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_payments_ai` AFTER INSERT ON `booking_payments` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Event Bookings', 'booking_payments', CAST(NEW.`payment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`payment_type`, NEW.`amount`), ''), 255),
            CONCAT('Created booking payment #', NEW.`payment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`payment_type`, NEW.`amount`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"payment_id":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null')), CONCAT('"payment_type":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_type` AS CHAR)), 'null')), CONCAT('"amount":', IFNULL(JSON_QUOTE(CAST(NEW.`amount` AS CHAR)), 'null')), CONCAT('"payment_date":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_date` AS CHAR)), 'null')), CONCAT('"payment_method":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_method` AS CHAR)), 'null')), CONCAT('"reference_no":', IFNULL(JSON_QUOTE(CAST(NEW.`reference_no` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null')), CONCAT('"receipt_path":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_path` AS CHAR)), 'null')), CONCAT('"receipt_name":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_name` AS CHAR)), 'null')), CONCAT('"receipt_mime":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_mime` AS CHAR)), 'null')), CONCAT('"verification_status":', IFNULL(JSON_QUOTE(CAST(NEW.`verification_status` AS CHAR)), 'null')), CONCAT('"reviewed_by":', IFNULL(JSON_QUOTE(CAST(NEW.`reviewed_by` AS CHAR)), 'null')), CONCAT('"reviewed_at":', IFNULL(JSON_QUOTE(CAST(NEW.`reviewed_at` AS CHAR)), 'null')), CONCAT('"review_notes":', IFNULL(JSON_QUOTE(CAST(NEW.`review_notes` AS CHAR)), 'null')), CONCAT('"recorded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`recorded_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_payments_au` AFTER UPDATE ON `booking_payments` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`payment_id` <=> NEW.`payment_id`) AND (OLD.`booking_id` <=> NEW.`booking_id`) AND (OLD.`payment_type` <=> NEW.`payment_type`) AND (OLD.`amount` <=> NEW.`amount`) AND (OLD.`payment_date` <=> NEW.`payment_date`) AND (OLD.`payment_method` <=> NEW.`payment_method`) AND (OLD.`reference_no` <=> NEW.`reference_no`) AND (OLD.`notes` <=> NEW.`notes`) AND (OLD.`receipt_path` <=> NEW.`receipt_path`) AND (OLD.`receipt_name` <=> NEW.`receipt_name`) AND (OLD.`receipt_mime` <=> NEW.`receipt_mime`) AND (OLD.`verification_status` <=> NEW.`verification_status`) AND (OLD.`reviewed_by` <=> NEW.`reviewed_by`) AND (OLD.`reviewed_at` <=> NEW.`reviewed_at`) AND (OLD.`review_notes` <=> NEW.`review_notes`) AND (OLD.`recorded_by` <=> NEW.`recorded_by`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Event Bookings', 'booking_payments', CAST(NEW.`payment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`payment_type`, NEW.`amount`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' booking payment #', NEW.`payment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`payment_type`, NEW.`amount`), ''), ')'), '')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`payment_id` <=> NEW.`payment_id`, NULL, CONCAT('"payment_id":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null'))), IF(OLD.`payment_type` <=> NEW.`payment_type`, NULL, CONCAT('"payment_type":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_type` AS CHAR)), 'null'))), IF(OLD.`amount` <=> NEW.`amount`, NULL, CONCAT('"amount":', IFNULL(JSON_QUOTE(CAST(OLD.`amount` AS CHAR)), 'null'))), IF(OLD.`payment_date` <=> NEW.`payment_date`, NULL, CONCAT('"payment_date":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_date` AS CHAR)), 'null'))), IF(OLD.`payment_method` <=> NEW.`payment_method`, NULL, CONCAT('"payment_method":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_method` AS CHAR)), 'null'))), IF(OLD.`reference_no` <=> NEW.`reference_no`, NULL, CONCAT('"reference_no":', IFNULL(JSON_QUOTE(CAST(OLD.`reference_no` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null'))), IF(OLD.`receipt_path` <=> NEW.`receipt_path`, NULL, CONCAT('"receipt_path":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_path` AS CHAR)), 'null'))), IF(OLD.`receipt_name` <=> NEW.`receipt_name`, NULL, CONCAT('"receipt_name":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_name` AS CHAR)), 'null'))), IF(OLD.`receipt_mime` <=> NEW.`receipt_mime`, NULL, CONCAT('"receipt_mime":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_mime` AS CHAR)), 'null'))), IF(OLD.`verification_status` <=> NEW.`verification_status`, NULL, CONCAT('"verification_status":', IFNULL(JSON_QUOTE(CAST(OLD.`verification_status` AS CHAR)), 'null'))), IF(OLD.`reviewed_by` <=> NEW.`reviewed_by`, NULL, CONCAT('"reviewed_by":', IFNULL(JSON_QUOTE(CAST(OLD.`reviewed_by` AS CHAR)), 'null'))), IF(OLD.`reviewed_at` <=> NEW.`reviewed_at`, NULL, CONCAT('"reviewed_at":', IFNULL(JSON_QUOTE(CAST(OLD.`reviewed_at` AS CHAR)), 'null'))), IF(OLD.`review_notes` <=> NEW.`review_notes`, NULL, CONCAT('"review_notes":', IFNULL(JSON_QUOTE(CAST(OLD.`review_notes` AS CHAR)), 'null'))), IF(OLD.`recorded_by` <=> NEW.`recorded_by`, NULL, CONCAT('"recorded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`recorded_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`payment_id` <=> NEW.`payment_id`, NULL, CONCAT('"payment_id":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null'))), IF(OLD.`payment_type` <=> NEW.`payment_type`, NULL, CONCAT('"payment_type":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_type` AS CHAR)), 'null'))), IF(OLD.`amount` <=> NEW.`amount`, NULL, CONCAT('"amount":', IFNULL(JSON_QUOTE(CAST(NEW.`amount` AS CHAR)), 'null'))), IF(OLD.`payment_date` <=> NEW.`payment_date`, NULL, CONCAT('"payment_date":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_date` AS CHAR)), 'null'))), IF(OLD.`payment_method` <=> NEW.`payment_method`, NULL, CONCAT('"payment_method":', IFNULL(JSON_QUOTE(CAST(NEW.`payment_method` AS CHAR)), 'null'))), IF(OLD.`reference_no` <=> NEW.`reference_no`, NULL, CONCAT('"reference_no":', IFNULL(JSON_QUOTE(CAST(NEW.`reference_no` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null'))), IF(OLD.`receipt_path` <=> NEW.`receipt_path`, NULL, CONCAT('"receipt_path":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_path` AS CHAR)), 'null'))), IF(OLD.`receipt_name` <=> NEW.`receipt_name`, NULL, CONCAT('"receipt_name":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_name` AS CHAR)), 'null'))), IF(OLD.`receipt_mime` <=> NEW.`receipt_mime`, NULL, CONCAT('"receipt_mime":', IFNULL(JSON_QUOTE(CAST(NEW.`receipt_mime` AS CHAR)), 'null'))), IF(OLD.`verification_status` <=> NEW.`verification_status`, NULL, CONCAT('"verification_status":', IFNULL(JSON_QUOTE(CAST(NEW.`verification_status` AS CHAR)), 'null'))), IF(OLD.`reviewed_by` <=> NEW.`reviewed_by`, NULL, CONCAT('"reviewed_by":', IFNULL(JSON_QUOTE(CAST(NEW.`reviewed_by` AS CHAR)), 'null'))), IF(OLD.`reviewed_at` <=> NEW.`reviewed_at`, NULL, CONCAT('"reviewed_at":', IFNULL(JSON_QUOTE(CAST(NEW.`reviewed_at` AS CHAR)), 'null'))), IF(OLD.`review_notes` <=> NEW.`review_notes`, NULL, CONCAT('"review_notes":', IFNULL(JSON_QUOTE(CAST(NEW.`review_notes` AS CHAR)), 'null'))), IF(OLD.`recorded_by` <=> NEW.`recorded_by`, NULL, CONCAT('"recorded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`recorded_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_booking_payments_ad` AFTER DELETE ON `booking_payments` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Event Bookings', 'booking_payments', CAST(OLD.`payment_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`payment_type`, OLD.`amount`), ''), 255),
            CONCAT('Deleted booking payment #', OLD.`payment_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`payment_type`, OLD.`amount`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"payment_id":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null')), CONCAT('"payment_type":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_type` AS CHAR)), 'null')), CONCAT('"amount":', IFNULL(JSON_QUOTE(CAST(OLD.`amount` AS CHAR)), 'null')), CONCAT('"payment_date":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_date` AS CHAR)), 'null')), CONCAT('"payment_method":', IFNULL(JSON_QUOTE(CAST(OLD.`payment_method` AS CHAR)), 'null')), CONCAT('"reference_no":', IFNULL(JSON_QUOTE(CAST(OLD.`reference_no` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null')), CONCAT('"receipt_path":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_path` AS CHAR)), 'null')), CONCAT('"receipt_name":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_name` AS CHAR)), 'null')), CONCAT('"receipt_mime":', IFNULL(JSON_QUOTE(CAST(OLD.`receipt_mime` AS CHAR)), 'null')), CONCAT('"verification_status":', IFNULL(JSON_QUOTE(CAST(OLD.`verification_status` AS CHAR)), 'null')), CONCAT('"reviewed_by":', IFNULL(JSON_QUOTE(CAST(OLD.`reviewed_by` AS CHAR)), 'null')), CONCAT('"reviewed_at":', IFNULL(JSON_QUOTE(CAST(OLD.`reviewed_at` AS CHAR)), 'null')), CONCAT('"review_notes":', IFNULL(JSON_QUOTE(CAST(OLD.`review_notes` AS CHAR)), 'null')), CONCAT('"recorded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`recorded_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `bundle_inclusions`
--

DROP TABLE IF EXISTS `bundle_inclusions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bundle_inclusions` (
  `inclusion_id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `inclusion_text` varchar(200) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`inclusion_id`),
  UNIQUE KEY `uq_bundle_inclusion` (`bundle_id`,`inclusion_text`),
  KEY `fk_bin_item` (`item_id`),
  CONSTRAINT `fk_bin_bundle` FOREIGN KEY (`bundle_id`) REFERENCES `menu_bundles` (`bundle_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bin_item` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `client_surveys`
--

DROP TABLE IF EXISTS `client_surveys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `client_surveys` (
  `survey_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `client_name` varchar(255) NOT NULL,
  `event_name` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `rating` tinyint(1) NOT NULL DEFAULT 5 COMMENT 'Star rating 1-5',
  `rating_food` tinyint(4) DEFAULT NULL,
  `rating_service` tinyint(4) DEFAULT NULL,
  `rating_timeliness` tinyint(4) DEFAULT NULL,
  `rating_presentation` tinyint(4) DEFAULT NULL,
  `rating_value` tinyint(4) DEFAULT NULL,
  `would_recommend` tinyint(1) DEFAULT NULL,
  `feedback` text DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL COMMENT 'Path to uploaded photo',
  `is_featured` tinyint(1) DEFAULT 0 COMMENT 'Featured testimonial',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`survey_id`),
  UNIQUE KEY `uq_survey_booking` (`booking_id`),
  KEY `idx_rating` (`rating`),
  KEY `idx_featured` (`is_featured`),
  KEY `idx_created_at` (`created_at`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `client_surveys_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_survey_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings` (`booking_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_survey_criteria` CHECK (coalesce(`rating_food`,1) between 1 and 5 and coalesce(`rating_service`,1) between 1 and 5 and coalesce(`rating_timeliness`,1) between 1 and 5 and coalesce(`rating_presentation`,1) between 1 and 5 and coalesce(`rating_value`,1) between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_client_surveys_ai` AFTER INSERT ON `client_surveys` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Client Surveys', 'client_surveys', CAST(NEW.`survey_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`client_name`, NEW.`event_name`), ''), 255),
            CONCAT('Created client survey #', NEW.`survey_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`client_name`, NEW.`event_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"survey_id":', IFNULL(JSON_QUOTE(CAST(NEW.`survey_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null')), CONCAT('"client_name":', IFNULL(JSON_QUOTE(CAST(NEW.`client_name` AS CHAR)), 'null')), CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_name` AS CHAR)), 'null')), CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(NEW.`event_date` AS CHAR)), 'null')), CONCAT('"rating":', IFNULL(JSON_QUOTE(CAST(NEW.`rating` AS CHAR)), 'null')), CONCAT('"rating_food":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_food` AS CHAR)), 'null')), CONCAT('"rating_service":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_service` AS CHAR)), 'null')), CONCAT('"rating_timeliness":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_timeliness` AS CHAR)), 'null')), CONCAT('"rating_presentation":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_presentation` AS CHAR)), 'null')), CONCAT('"rating_value":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_value` AS CHAR)), 'null')), CONCAT('"would_recommend":', IFNULL(JSON_QUOTE(CAST(NEW.`would_recommend` AS CHAR)), 'null')), CONCAT('"feedback":', IFNULL(JSON_QUOTE(CAST(NEW.`feedback` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null')), CONCAT('"is_featured":', IFNULL(JSON_QUOTE(CAST(NEW.`is_featured` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_client_surveys_au` AFTER UPDATE ON `client_surveys` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`survey_id` <=> NEW.`survey_id`) AND (OLD.`booking_id` <=> NEW.`booking_id`) AND (OLD.`client_name` <=> NEW.`client_name`) AND (OLD.`event_name` <=> NEW.`event_name`) AND (OLD.`event_date` <=> NEW.`event_date`) AND (OLD.`rating` <=> NEW.`rating`) AND (OLD.`rating_food` <=> NEW.`rating_food`) AND (OLD.`rating_service` <=> NEW.`rating_service`) AND (OLD.`rating_timeliness` <=> NEW.`rating_timeliness`) AND (OLD.`rating_presentation` <=> NEW.`rating_presentation`) AND (OLD.`rating_value` <=> NEW.`rating_value`) AND (OLD.`would_recommend` <=> NEW.`would_recommend`) AND (OLD.`feedback` <=> NEW.`feedback`) AND (OLD.`photo_path` <=> NEW.`photo_path`) AND (OLD.`is_featured` <=> NEW.`is_featured`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Client Surveys', 'client_surveys', CAST(NEW.`survey_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`client_name`, NEW.`event_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' client survey #', NEW.`survey_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`client_name`, NEW.`event_name`), ''), ')'), '')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`survey_id` <=> NEW.`survey_id`, NULL, CONCAT('"survey_id":', IFNULL(JSON_QUOTE(CAST(OLD.`survey_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null'))), IF(OLD.`client_name` <=> NEW.`client_name`, NULL, CONCAT('"client_name":', IFNULL(JSON_QUOTE(CAST(OLD.`client_name` AS CHAR)), 'null'))), IF(OLD.`event_name` <=> NEW.`event_name`, NULL, CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_name` AS CHAR)), 'null'))), IF(OLD.`event_date` <=> NEW.`event_date`, NULL, CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(OLD.`event_date` AS CHAR)), 'null'))), IF(OLD.`rating` <=> NEW.`rating`, NULL, CONCAT('"rating":', IFNULL(JSON_QUOTE(CAST(OLD.`rating` AS CHAR)), 'null'))), IF(OLD.`rating_food` <=> NEW.`rating_food`, NULL, CONCAT('"rating_food":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_food` AS CHAR)), 'null'))), IF(OLD.`rating_service` <=> NEW.`rating_service`, NULL, CONCAT('"rating_service":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_service` AS CHAR)), 'null'))), IF(OLD.`rating_timeliness` <=> NEW.`rating_timeliness`, NULL, CONCAT('"rating_timeliness":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_timeliness` AS CHAR)), 'null'))), IF(OLD.`rating_presentation` <=> NEW.`rating_presentation`, NULL, CONCAT('"rating_presentation":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_presentation` AS CHAR)), 'null'))), IF(OLD.`rating_value` <=> NEW.`rating_value`, NULL, CONCAT('"rating_value":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_value` AS CHAR)), 'null'))), IF(OLD.`would_recommend` <=> NEW.`would_recommend`, NULL, CONCAT('"would_recommend":', IFNULL(JSON_QUOTE(CAST(OLD.`would_recommend` AS CHAR)), 'null'))), IF(OLD.`feedback` <=> NEW.`feedback`, NULL, CONCAT('"feedback":', IFNULL(JSON_QUOTE(CAST(OLD.`feedback` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_featured` <=> NEW.`is_featured`, NULL, CONCAT('"is_featured":', IFNULL(JSON_QUOTE(CAST(OLD.`is_featured` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`survey_id` <=> NEW.`survey_id`, NULL, CONCAT('"survey_id":', IFNULL(JSON_QUOTE(CAST(NEW.`survey_id` AS CHAR)), 'null'))), IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null'))), IF(OLD.`client_name` <=> NEW.`client_name`, NULL, CONCAT('"client_name":', IFNULL(JSON_QUOTE(CAST(NEW.`client_name` AS CHAR)), 'null'))), IF(OLD.`event_name` <=> NEW.`event_name`, NULL, CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_name` AS CHAR)), 'null'))), IF(OLD.`event_date` <=> NEW.`event_date`, NULL, CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(NEW.`event_date` AS CHAR)), 'null'))), IF(OLD.`rating` <=> NEW.`rating`, NULL, CONCAT('"rating":', IFNULL(JSON_QUOTE(CAST(NEW.`rating` AS CHAR)), 'null'))), IF(OLD.`rating_food` <=> NEW.`rating_food`, NULL, CONCAT('"rating_food":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_food` AS CHAR)), 'null'))), IF(OLD.`rating_service` <=> NEW.`rating_service`, NULL, CONCAT('"rating_service":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_service` AS CHAR)), 'null'))), IF(OLD.`rating_timeliness` <=> NEW.`rating_timeliness`, NULL, CONCAT('"rating_timeliness":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_timeliness` AS CHAR)), 'null'))), IF(OLD.`rating_presentation` <=> NEW.`rating_presentation`, NULL, CONCAT('"rating_presentation":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_presentation` AS CHAR)), 'null'))), IF(OLD.`rating_value` <=> NEW.`rating_value`, NULL, CONCAT('"rating_value":', IFNULL(JSON_QUOTE(CAST(NEW.`rating_value` AS CHAR)), 'null'))), IF(OLD.`would_recommend` <=> NEW.`would_recommend`, NULL, CONCAT('"would_recommend":', IFNULL(JSON_QUOTE(CAST(NEW.`would_recommend` AS CHAR)), 'null'))), IF(OLD.`feedback` <=> NEW.`feedback`, NULL, CONCAT('"feedback":', IFNULL(JSON_QUOTE(CAST(NEW.`feedback` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_featured` <=> NEW.`is_featured`, NULL, CONCAT('"is_featured":', IFNULL(JSON_QUOTE(CAST(NEW.`is_featured` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_client_surveys_ad` AFTER DELETE ON `client_surveys` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Client Surveys', 'client_surveys', CAST(OLD.`survey_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`client_name`, OLD.`event_name`), ''), 255),
            CONCAT('Deleted client survey #', OLD.`survey_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`client_name`, OLD.`event_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"survey_id":', IFNULL(JSON_QUOTE(CAST(OLD.`survey_id` AS CHAR)), 'null')), CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null')), CONCAT('"client_name":', IFNULL(JSON_QUOTE(CAST(OLD.`client_name` AS CHAR)), 'null')), CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_name` AS CHAR)), 'null')), CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(OLD.`event_date` AS CHAR)), 'null')), CONCAT('"rating":', IFNULL(JSON_QUOTE(CAST(OLD.`rating` AS CHAR)), 'null')), CONCAT('"rating_food":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_food` AS CHAR)), 'null')), CONCAT('"rating_service":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_service` AS CHAR)), 'null')), CONCAT('"rating_timeliness":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_timeliness` AS CHAR)), 'null')), CONCAT('"rating_presentation":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_presentation` AS CHAR)), 'null')), CONCAT('"rating_value":', IFNULL(JSON_QUOTE(CAST(OLD.`rating_value` AS CHAR)), 'null')), CONCAT('"would_recommend":', IFNULL(JSON_QUOTE(CAST(OLD.`would_recommend` AS CHAR)), 'null')), CONCAT('"feedback":', IFNULL(JSON_QUOTE(CAST(OLD.`feedback` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null')), CONCAT('"is_featured":', IFNULL(JSON_QUOTE(CAST(OLD.`is_featured` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `clients`
--

DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clients` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `client_code` varchar(20) DEFAULT NULL,
  `fullname` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `postal_code` varchar(4) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`client_id`),
  UNIQUE KEY `uq_client_code` (`client_code`),
  UNIQUE KEY `uq_client_identity` (`fullname`,`contact`),
  KEY `idx_client_contact` (`contact`),
  KEY `idx_client_email` (`email`),
  KEY `fk_client_created_by` (`created_by`),
  CONSTRAINT `fk_client_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_clients_ai` AFTER INSERT ON `clients` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Client Records', 'clients', CAST(NEW.`client_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`client_code`, NEW.`fullname`), ''), 255),
            CONCAT('Created client record #', NEW.`client_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`client_code`, NEW.`fullname`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(NEW.`client_id` AS CHAR)), 'null')), CONCAT('"client_code":', IFNULL(JSON_QUOTE(CAST(NEW.`client_code` AS CHAR)), 'null')), CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(NEW.`fullname` AS CHAR)), 'null')), CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(NEW.`email` AS CHAR)), 'null')), CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(NEW.`contact` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null')), CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(NEW.`address` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_clients_au` AFTER UPDATE ON `clients` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`client_id` <=> NEW.`client_id`) AND (OLD.`client_code` <=> NEW.`client_code`) AND (OLD.`fullname` <=> NEW.`fullname`) AND (OLD.`email` <=> NEW.`email`) AND (OLD.`contact` <=> NEW.`contact`) AND (OLD.`postal_code` <=> NEW.`postal_code`) AND (OLD.`address` <=> NEW.`address`) AND (OLD.`notes` <=> NEW.`notes`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Client Records', 'clients', CAST(NEW.`client_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`client_code`, NEW.`fullname`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' client record #', NEW.`client_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`client_code`, NEW.`fullname`), ''), ')'), '')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`client_id` <=> NEW.`client_id`, NULL, CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(OLD.`client_id` AS CHAR)), 'null'))), IF(OLD.`client_code` <=> NEW.`client_code`, NULL, CONCAT('"client_code":', IFNULL(JSON_QUOTE(CAST(OLD.`client_code` AS CHAR)), 'null'))), IF(OLD.`fullname` <=> NEW.`fullname`, NULL, CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(OLD.`fullname` AS CHAR)), 'null'))), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(OLD.`email` AS CHAR)), 'null'))), IF(OLD.`contact` <=> NEW.`contact`, NULL, CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(OLD.`contact` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null'))), IF(OLD.`address` <=> NEW.`address`, NULL, CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(OLD.`address` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`client_id` <=> NEW.`client_id`, NULL, CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(NEW.`client_id` AS CHAR)), 'null'))), IF(OLD.`client_code` <=> NEW.`client_code`, NULL, CONCAT('"client_code":', IFNULL(JSON_QUOTE(CAST(NEW.`client_code` AS CHAR)), 'null'))), IF(OLD.`fullname` <=> NEW.`fullname`, NULL, CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(NEW.`fullname` AS CHAR)), 'null'))), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(NEW.`email` AS CHAR)), 'null'))), IF(OLD.`contact` <=> NEW.`contact`, NULL, CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(NEW.`contact` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null'))), IF(OLD.`address` <=> NEW.`address`, NULL, CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(NEW.`address` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_clients_ad` AFTER DELETE ON `clients` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Client Records', 'clients', CAST(OLD.`client_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`client_code`, OLD.`fullname`), ''), 255),
            CONCAT('Deleted client record #', OLD.`client_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`client_code`, OLD.`fullname`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(OLD.`client_id` AS CHAR)), 'null')), CONCAT('"client_code":', IFNULL(JSON_QUOTE(CAST(OLD.`client_code` AS CHAR)), 'null')), CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(OLD.`fullname` AS CHAR)), 'null')), CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(OLD.`email` AS CHAR)), 'null')), CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(OLD.`contact` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null')), CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(OLD.`address` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `database_backups`
--

DROP TABLE IF EXISTS `database_backups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `database_backups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `backup_type` varchar(20) NOT NULL DEFAULT 'manual',
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `checksum_sha256` char(64) DEFAULT NULL,
  `table_count` int(11) NOT NULL DEFAULT 0,
  `row_count` bigint(20) NOT NULL DEFAULT 0,
  `db_name` varchar(64) DEFAULT NULL,
  `server_version` varchar(100) DEFAULT NULL,
  `secondary_copy` tinyint(1) NOT NULL DEFAULT 0,
  `secondary_path` varchar(500) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'available',
  `verify_status` varchar(20) DEFAULT NULL,
  `last_verified_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_username` varchar(100) DEFAULT NULL,
  `created_at` timestamp(3) NOT NULL DEFAULT current_timestamp(3),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_backup_filename` (`filename`),
  KEY `idx_backup_type` (`backup_type`),
  KEY `idx_backup_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_verifications`
--

DROP TABLE IF EXISTS `email_verifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_verifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ev_token` (`token_hash`),
  KEY `idx_ev_user` (`user_id`,`used_at`),
  CONSTRAINT `fk_ev_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=123 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `event_bookings`
--

DROP TABLE IF EXISTS `event_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_ref` varchar(30) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `event_name` varchar(255) NOT NULL,
  `event_type` varchar(100) DEFAULT 'Gala Dinner',
  `event_type_id` int(11) DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT '12:00:00',
  `fullname` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `contact` varchar(20) DEFAULT NULL,
  `region` varchar(150) DEFAULT NULL,
  `province` varchar(150) DEFAULT NULL,
  `city` varchar(150) DEFAULT NULL,
  `barangay` varchar(150) DEFAULT NULL,
  `postal_code` varchar(4) DEFAULT NULL,
  `backdrop_id` int(11) DEFAULT NULL,
  `backdrop_name` varchar(255) DEFAULT NULL,
  `backdrop_path` varchar(255) DEFAULT NULL,
  `event_theme` varchar(100) DEFAULT NULL,
  `event_color` varchar(7) DEFAULT NULL,
  `venue_address` text DEFAULT NULL,
  `headcount` int(11) NOT NULL DEFAULT 100,
  `package_id` int(11) DEFAULT NULL,
  `package_name` varchar(150) DEFAULT NULL,
  `package_price` decimal(10,2) DEFAULT 0.00,
  `base_total` decimal(12,2) DEFAULT 0.00,
  `menu_upgrade_total` decimal(12,2) DEFAULT 0.00,
  `addon_total` decimal(12,2) DEFAULT 0.00,
  `subtotal` decimal(12,2) DEFAULT 0.00,
  `service_tax_rate` decimal(5,2) DEFAULT 12.00,
  `service_tax` decimal(12,2) DEFAULT 0.00,
  `grand_total` decimal(12,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `status` enum('Pending','Approved','Cancelled','Disabled','Completed') DEFAULT 'Pending',
  `preparation_status` enum('Pending Preparation','Preparing','Ready for Event','Completed') NOT NULL DEFAULT 'Pending Preparation',
  `preparation_notes` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archive_expires_at` datetime DEFAULT NULL,
  `pre_archive_status` varchar(20) DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `restored_at` datetime DEFAULT NULL,
  `restored_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `booking_ref` (`booking_ref`),
  KEY `idx_event_date` (`event_date`),
  KEY `idx_status` (`status`),
  KEY `fk_booking_package` (`package_id`),
  KEY `idx_booking_archive` (`is_archived`,`archive_expires_at`),
  KEY `idx_booking_client` (`client_id`),
  KEY `idx_booking_event_type` (`event_type_id`),
  CONSTRAINT `fk_booking_client` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_event_type` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`event_type_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_booking_package` FOREIGN KEY (`package_id`) REFERENCES `menu_packages` (`package_id`) ON DELETE SET NULL,
  CONSTRAINT `chk_booking_postal` CHECK (`postal_code` is null or `postal_code` regexp '^[0-9]{4}$')
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_bookings_ai` AFTER INSERT ON `event_bookings` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Event Bookings', 'event_bookings', CAST(NEW.`booking_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`booking_ref`, NEW.`event_name`), ''), 255),
            CONCAT('Created event booking #', NEW.`booking_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`booking_ref`, NEW.`event_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null')), CONCAT('"booking_ref":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_ref` AS CHAR)), 'null')), CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(NEW.`client_id` AS CHAR)), 'null')), CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_name` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null')), CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_id` AS CHAR)), 'null')), CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(NEW.`event_date` AS CHAR)), 'null')), CONCAT('"event_time":', IFNULL(JSON_QUOTE(CAST(NEW.`event_time` AS CHAR)), 'null')), CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(NEW.`fullname` AS CHAR)), 'null')), CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(NEW.`email` AS CHAR)), 'null')), CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(NEW.`contact` AS CHAR)), 'null')), CONCAT('"region":', IFNULL(JSON_QUOTE(CAST(NEW.`region` AS CHAR)), 'null')), CONCAT('"province":', IFNULL(JSON_QUOTE(CAST(NEW.`province` AS CHAR)), 'null')), CONCAT('"city":', IFNULL(JSON_QUOTE(CAST(NEW.`city` AS CHAR)), 'null')), CONCAT('"barangay":', IFNULL(JSON_QUOTE(CAST(NEW.`barangay` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null')), CONCAT('"backdrop_id":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_id` AS CHAR)), 'null')), CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_name` AS CHAR)), 'null')), CONCAT('"backdrop_path":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_path` AS CHAR)), 'null')), CONCAT('"event_theme":', IFNULL(JSON_QUOTE(CAST(NEW.`event_theme` AS CHAR)), 'null')), CONCAT('"event_color":', IFNULL(JSON_QUOTE(CAST(NEW.`event_color` AS CHAR)), 'null')), CONCAT('"venue_address":', IFNULL(JSON_QUOTE(CAST(NEW.`venue_address` AS CHAR)), 'null')), CONCAT('"headcount":', IFNULL(JSON_QUOTE(CAST(NEW.`headcount` AS CHAR)), 'null')), CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(NEW.`package_id` AS CHAR)), 'null')), CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(NEW.`package_name` AS CHAR)), 'null')), CONCAT('"package_price":', IFNULL(JSON_QUOTE(CAST(NEW.`package_price` AS CHAR)), 'null')), CONCAT('"base_total":', IFNULL(JSON_QUOTE(CAST(NEW.`base_total` AS CHAR)), 'null')), CONCAT('"menu_upgrade_total":', IFNULL(JSON_QUOTE(CAST(NEW.`menu_upgrade_total` AS CHAR)), 'null')), CONCAT('"addon_total":', IFNULL(JSON_QUOTE(CAST(NEW.`addon_total` AS CHAR)), 'null')), CONCAT('"subtotal":', IFNULL(JSON_QUOTE(CAST(NEW.`subtotal` AS CHAR)), 'null')), CONCAT('"service_tax_rate":', IFNULL(JSON_QUOTE(CAST(NEW.`service_tax_rate` AS CHAR)), 'null')), CONCAT('"service_tax":', IFNULL(JSON_QUOTE(CAST(NEW.`service_tax` AS CHAR)), 'null')), CONCAT('"grand_total":', IFNULL(JSON_QUOTE(CAST(NEW.`grand_total` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null')), CONCAT('"preparation_status":', IFNULL(JSON_QUOTE(CAST(NEW.`preparation_status` AS CHAR)), 'null')), CONCAT('"preparation_notes":', IFNULL(JSON_QUOTE(CAST(NEW.`preparation_notes` AS CHAR)), 'null')), CONCAT('"completed_at":', IFNULL(JSON_QUOTE(CAST(NEW.`completed_at` AS CHAR)), 'null')), CONCAT('"completed_by":', IFNULL(JSON_QUOTE(CAST(NEW.`completed_by` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null')), CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archive_expires_at` AS CHAR)), 'null')), CONCAT('"pre_archive_status":', IFNULL(JSON_QUOTE(CAST(NEW.`pre_archive_status` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null')), CONCAT('"restored_at":', IFNULL(JSON_QUOTE(CAST(NEW.`restored_at` AS CHAR)), 'null')), CONCAT('"restored_by":', IFNULL(JSON_QUOTE(CAST(NEW.`restored_by` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_bookings_au` AFTER UPDATE ON `event_bookings` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`booking_id` <=> NEW.`booking_id`) AND (OLD.`booking_ref` <=> NEW.`booking_ref`) AND (OLD.`client_id` <=> NEW.`client_id`) AND (OLD.`event_name` <=> NEW.`event_name`) AND (OLD.`event_type` <=> NEW.`event_type`) AND (OLD.`event_type_id` <=> NEW.`event_type_id`) AND (OLD.`event_date` <=> NEW.`event_date`) AND (OLD.`event_time` <=> NEW.`event_time`) AND (OLD.`fullname` <=> NEW.`fullname`) AND (OLD.`email` <=> NEW.`email`) AND (OLD.`contact` <=> NEW.`contact`) AND (OLD.`region` <=> NEW.`region`) AND (OLD.`province` <=> NEW.`province`) AND (OLD.`city` <=> NEW.`city`) AND (OLD.`barangay` <=> NEW.`barangay`) AND (OLD.`postal_code` <=> NEW.`postal_code`) AND (OLD.`backdrop_id` <=> NEW.`backdrop_id`) AND (OLD.`backdrop_name` <=> NEW.`backdrop_name`) AND (OLD.`backdrop_path` <=> NEW.`backdrop_path`) AND (OLD.`event_theme` <=> NEW.`event_theme`) AND (OLD.`event_color` <=> NEW.`event_color`) AND (OLD.`venue_address` <=> NEW.`venue_address`) AND (OLD.`headcount` <=> NEW.`headcount`) AND (OLD.`package_id` <=> NEW.`package_id`) AND (OLD.`package_name` <=> NEW.`package_name`) AND (OLD.`package_price` <=> NEW.`package_price`) AND (OLD.`base_total` <=> NEW.`base_total`) AND (OLD.`menu_upgrade_total` <=> NEW.`menu_upgrade_total`) AND (OLD.`addon_total` <=> NEW.`addon_total`) AND (OLD.`subtotal` <=> NEW.`subtotal`) AND (OLD.`service_tax_rate` <=> NEW.`service_tax_rate`) AND (OLD.`service_tax` <=> NEW.`service_tax`) AND (OLD.`grand_total` <=> NEW.`grand_total`) AND (OLD.`notes` <=> NEW.`notes`) AND (OLD.`status` <=> NEW.`status`) AND (OLD.`preparation_status` <=> NEW.`preparation_status`) AND (OLD.`preparation_notes` <=> NEW.`preparation_notes`) AND (OLD.`completed_at` <=> NEW.`completed_at`) AND (OLD.`completed_by` <=> NEW.`completed_by`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archive_expires_at` <=> NEW.`archive_expires_at`) AND (OLD.`pre_archive_status` <=> NEW.`pre_archive_status`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`restored_at` <=> NEW.`restored_at`) AND (OLD.`restored_by` <=> NEW.`restored_by`)) THEN
                SET v_action = CASE WHEN COALESCE(OLD.`is_archived`,0) = 0 AND COALESCE(NEW.`is_archived`,0) = 1 THEN 'ARCHIVE' WHEN COALESCE(OLD.`is_archived`,0) = 1 AND COALESCE(NEW.`is_archived`,0) = 0 THEN 'RESTORE' WHEN NOT (OLD.`status` <=> NEW.`status`) AND ((OLD.`booking_id` <=> NEW.`booking_id`) AND (OLD.`booking_ref` <=> NEW.`booking_ref`) AND (OLD.`client_id` <=> NEW.`client_id`) AND (OLD.`event_name` <=> NEW.`event_name`) AND (OLD.`event_type` <=> NEW.`event_type`) AND (OLD.`event_type_id` <=> NEW.`event_type_id`) AND (OLD.`event_date` <=> NEW.`event_date`) AND (OLD.`event_time` <=> NEW.`event_time`) AND (OLD.`fullname` <=> NEW.`fullname`) AND (OLD.`email` <=> NEW.`email`) AND (OLD.`contact` <=> NEW.`contact`) AND (OLD.`region` <=> NEW.`region`) AND (OLD.`province` <=> NEW.`province`) AND (OLD.`city` <=> NEW.`city`) AND (OLD.`barangay` <=> NEW.`barangay`) AND (OLD.`postal_code` <=> NEW.`postal_code`) AND (OLD.`backdrop_id` <=> NEW.`backdrop_id`) AND (OLD.`backdrop_name` <=> NEW.`backdrop_name`) AND (OLD.`backdrop_path` <=> NEW.`backdrop_path`) AND (OLD.`event_theme` <=> NEW.`event_theme`) AND (OLD.`event_color` <=> NEW.`event_color`) AND (OLD.`venue_address` <=> NEW.`venue_address`) AND (OLD.`headcount` <=> NEW.`headcount`) AND (OLD.`package_id` <=> NEW.`package_id`) AND (OLD.`package_name` <=> NEW.`package_name`) AND (OLD.`package_price` <=> NEW.`package_price`) AND (OLD.`base_total` <=> NEW.`base_total`) AND (OLD.`menu_upgrade_total` <=> NEW.`menu_upgrade_total`) AND (OLD.`addon_total` <=> NEW.`addon_total`) AND (OLD.`subtotal` <=> NEW.`subtotal`) AND (OLD.`service_tax_rate` <=> NEW.`service_tax_rate`) AND (OLD.`service_tax` <=> NEW.`service_tax`) AND (OLD.`grand_total` <=> NEW.`grand_total`) AND (OLD.`notes` <=> NEW.`notes`) AND (OLD.`preparation_status` <=> NEW.`preparation_status`) AND (OLD.`preparation_notes` <=> NEW.`preparation_notes`) AND (OLD.`completed_at` <=> NEW.`completed_at`) AND (OLD.`completed_by` <=> NEW.`completed_by`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archive_expires_at` <=> NEW.`archive_expires_at`) AND (OLD.`pre_archive_status` <=> NEW.`pre_archive_status`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`restored_at` <=> NEW.`restored_at`) AND (OLD.`restored_by` <=> NEW.`restored_by`)) THEN 'STATUS_CHANGE' ELSE 'UPDATE' END;
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Event Bookings', 'event_bookings', CAST(NEW.`booking_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`booking_ref`, NEW.`event_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' event booking #', NEW.`booking_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`booking_ref`, NEW.`event_name`), ''), ')'), ''), IF(OLD.`status` <=> NEW.`status`, '', CONCAT(' - status: ', IFNULL(OLD.`status`,'none'), ' -> ', IFNULL(NEW.`status`,'none')))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null'))), IF(OLD.`booking_ref` <=> NEW.`booking_ref`, NULL, CONCAT('"booking_ref":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_ref` AS CHAR)), 'null'))), IF(OLD.`client_id` <=> NEW.`client_id`, NULL, CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(OLD.`client_id` AS CHAR)), 'null'))), IF(OLD.`event_name` <=> NEW.`event_name`, NULL, CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_name` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null'))), IF(OLD.`event_type_id` <=> NEW.`event_type_id`, NULL, CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_id` AS CHAR)), 'null'))), IF(OLD.`event_date` <=> NEW.`event_date`, NULL, CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(OLD.`event_date` AS CHAR)), 'null'))), IF(OLD.`event_time` <=> NEW.`event_time`, NULL, CONCAT('"event_time":', IFNULL(JSON_QUOTE(CAST(OLD.`event_time` AS CHAR)), 'null'))), IF(OLD.`fullname` <=> NEW.`fullname`, NULL, CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(OLD.`fullname` AS CHAR)), 'null'))), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(OLD.`email` AS CHAR)), 'null'))), IF(OLD.`contact` <=> NEW.`contact`, NULL, CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(OLD.`contact` AS CHAR)), 'null'))), IF(OLD.`region` <=> NEW.`region`, NULL, CONCAT('"region":', IFNULL(JSON_QUOTE(CAST(OLD.`region` AS CHAR)), 'null'))), IF(OLD.`province` <=> NEW.`province`, NULL, CONCAT('"province":', IFNULL(JSON_QUOTE(CAST(OLD.`province` AS CHAR)), 'null'))), IF(OLD.`city` <=> NEW.`city`, NULL, CONCAT('"city":', IFNULL(JSON_QUOTE(CAST(OLD.`city` AS CHAR)), 'null'))), IF(OLD.`barangay` <=> NEW.`barangay`, NULL, CONCAT('"barangay":', IFNULL(JSON_QUOTE(CAST(OLD.`barangay` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null'))), IF(OLD.`backdrop_id` <=> NEW.`backdrop_id`, NULL, CONCAT('"backdrop_id":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_id` AS CHAR)), 'null'))), IF(OLD.`backdrop_name` <=> NEW.`backdrop_name`, NULL, CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_name` AS CHAR)), 'null'))), IF(OLD.`backdrop_path` <=> NEW.`backdrop_path`, NULL, CONCAT('"backdrop_path":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_path` AS CHAR)), 'null'))), IF(OLD.`event_theme` <=> NEW.`event_theme`, NULL, CONCAT('"event_theme":', IFNULL(JSON_QUOTE(CAST(OLD.`event_theme` AS CHAR)), 'null'))), IF(OLD.`event_color` <=> NEW.`event_color`, NULL, CONCAT('"event_color":', IFNULL(JSON_QUOTE(CAST(OLD.`event_color` AS CHAR)), 'null'))), IF(OLD.`venue_address` <=> NEW.`venue_address`, NULL, CONCAT('"venue_address":', IFNULL(JSON_QUOTE(CAST(OLD.`venue_address` AS CHAR)), 'null'))), IF(OLD.`headcount` <=> NEW.`headcount`, NULL, CONCAT('"headcount":', IFNULL(JSON_QUOTE(CAST(OLD.`headcount` AS CHAR)), 'null'))), IF(OLD.`package_id` <=> NEW.`package_id`, NULL, CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(OLD.`package_id` AS CHAR)), 'null'))), IF(OLD.`package_name` <=> NEW.`package_name`, NULL, CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(OLD.`package_name` AS CHAR)), 'null'))), IF(OLD.`package_price` <=> NEW.`package_price`, NULL, CONCAT('"package_price":', IFNULL(JSON_QUOTE(CAST(OLD.`package_price` AS CHAR)), 'null'))), IF(OLD.`base_total` <=> NEW.`base_total`, NULL, CONCAT('"base_total":', IFNULL(JSON_QUOTE(CAST(OLD.`base_total` AS CHAR)), 'null'))), IF(OLD.`menu_upgrade_total` <=> NEW.`menu_upgrade_total`, NULL, CONCAT('"menu_upgrade_total":', IFNULL(JSON_QUOTE(CAST(OLD.`menu_upgrade_total` AS CHAR)), 'null'))), IF(OLD.`addon_total` <=> NEW.`addon_total`, NULL, CONCAT('"addon_total":', IFNULL(JSON_QUOTE(CAST(OLD.`addon_total` AS CHAR)), 'null'))), IF(OLD.`subtotal` <=> NEW.`subtotal`, NULL, CONCAT('"subtotal":', IFNULL(JSON_QUOTE(CAST(OLD.`subtotal` AS CHAR)), 'null'))), IF(OLD.`service_tax_rate` <=> NEW.`service_tax_rate`, NULL, CONCAT('"service_tax_rate":', IFNULL(JSON_QUOTE(CAST(OLD.`service_tax_rate` AS CHAR)), 'null'))), IF(OLD.`service_tax` <=> NEW.`service_tax`, NULL, CONCAT('"service_tax":', IFNULL(JSON_QUOTE(CAST(OLD.`service_tax` AS CHAR)), 'null'))), IF(OLD.`grand_total` <=> NEW.`grand_total`, NULL, CONCAT('"grand_total":', IFNULL(JSON_QUOTE(CAST(OLD.`grand_total` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null'))), IF(OLD.`preparation_status` <=> NEW.`preparation_status`, NULL, CONCAT('"preparation_status":', IFNULL(JSON_QUOTE(CAST(OLD.`preparation_status` AS CHAR)), 'null'))), IF(OLD.`preparation_notes` <=> NEW.`preparation_notes`, NULL, CONCAT('"preparation_notes":', IFNULL(JSON_QUOTE(CAST(OLD.`preparation_notes` AS CHAR)), 'null'))), IF(OLD.`completed_at` <=> NEW.`completed_at`, NULL, CONCAT('"completed_at":', IFNULL(JSON_QUOTE(CAST(OLD.`completed_at` AS CHAR)), 'null'))), IF(OLD.`completed_by` <=> NEW.`completed_by`, NULL, CONCAT('"completed_by":', IFNULL(JSON_QUOTE(CAST(OLD.`completed_by` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archive_expires_at` <=> NEW.`archive_expires_at`, NULL, CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archive_expires_at` AS CHAR)), 'null'))), IF(OLD.`pre_archive_status` <=> NEW.`pre_archive_status`, NULL, CONCAT('"pre_archive_status":', IFNULL(JSON_QUOTE(CAST(OLD.`pre_archive_status` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null'))), IF(OLD.`restored_at` <=> NEW.`restored_at`, NULL, CONCAT('"restored_at":', IFNULL(JSON_QUOTE(CAST(OLD.`restored_at` AS CHAR)), 'null'))), IF(OLD.`restored_by` <=> NEW.`restored_by`, NULL, CONCAT('"restored_by":', IFNULL(JSON_QUOTE(CAST(OLD.`restored_by` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`booking_id` <=> NEW.`booking_id`, NULL, CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_id` AS CHAR)), 'null'))), IF(OLD.`booking_ref` <=> NEW.`booking_ref`, NULL, CONCAT('"booking_ref":', IFNULL(JSON_QUOTE(CAST(NEW.`booking_ref` AS CHAR)), 'null'))), IF(OLD.`client_id` <=> NEW.`client_id`, NULL, CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(NEW.`client_id` AS CHAR)), 'null'))), IF(OLD.`event_name` <=> NEW.`event_name`, NULL, CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_name` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null'))), IF(OLD.`event_type_id` <=> NEW.`event_type_id`, NULL, CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_id` AS CHAR)), 'null'))), IF(OLD.`event_date` <=> NEW.`event_date`, NULL, CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(NEW.`event_date` AS CHAR)), 'null'))), IF(OLD.`event_time` <=> NEW.`event_time`, NULL, CONCAT('"event_time":', IFNULL(JSON_QUOTE(CAST(NEW.`event_time` AS CHAR)), 'null'))), IF(OLD.`fullname` <=> NEW.`fullname`, NULL, CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(NEW.`fullname` AS CHAR)), 'null'))), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(NEW.`email` AS CHAR)), 'null'))), IF(OLD.`contact` <=> NEW.`contact`, NULL, CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(NEW.`contact` AS CHAR)), 'null'))), IF(OLD.`region` <=> NEW.`region`, NULL, CONCAT('"region":', IFNULL(JSON_QUOTE(CAST(NEW.`region` AS CHAR)), 'null'))), IF(OLD.`province` <=> NEW.`province`, NULL, CONCAT('"province":', IFNULL(JSON_QUOTE(CAST(NEW.`province` AS CHAR)), 'null'))), IF(OLD.`city` <=> NEW.`city`, NULL, CONCAT('"city":', IFNULL(JSON_QUOTE(CAST(NEW.`city` AS CHAR)), 'null'))), IF(OLD.`barangay` <=> NEW.`barangay`, NULL, CONCAT('"barangay":', IFNULL(JSON_QUOTE(CAST(NEW.`barangay` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null'))), IF(OLD.`backdrop_id` <=> NEW.`backdrop_id`, NULL, CONCAT('"backdrop_id":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_id` AS CHAR)), 'null'))), IF(OLD.`backdrop_name` <=> NEW.`backdrop_name`, NULL, CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_name` AS CHAR)), 'null'))), IF(OLD.`backdrop_path` <=> NEW.`backdrop_path`, NULL, CONCAT('"backdrop_path":', IFNULL(JSON_QUOTE(CAST(NEW.`backdrop_path` AS CHAR)), 'null'))), IF(OLD.`event_theme` <=> NEW.`event_theme`, NULL, CONCAT('"event_theme":', IFNULL(JSON_QUOTE(CAST(NEW.`event_theme` AS CHAR)), 'null'))), IF(OLD.`event_color` <=> NEW.`event_color`, NULL, CONCAT('"event_color":', IFNULL(JSON_QUOTE(CAST(NEW.`event_color` AS CHAR)), 'null'))), IF(OLD.`venue_address` <=> NEW.`venue_address`, NULL, CONCAT('"venue_address":', IFNULL(JSON_QUOTE(CAST(NEW.`venue_address` AS CHAR)), 'null'))), IF(OLD.`headcount` <=> NEW.`headcount`, NULL, CONCAT('"headcount":', IFNULL(JSON_QUOTE(CAST(NEW.`headcount` AS CHAR)), 'null'))), IF(OLD.`package_id` <=> NEW.`package_id`, NULL, CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(NEW.`package_id` AS CHAR)), 'null'))), IF(OLD.`package_name` <=> NEW.`package_name`, NULL, CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(NEW.`package_name` AS CHAR)), 'null'))), IF(OLD.`package_price` <=> NEW.`package_price`, NULL, CONCAT('"package_price":', IFNULL(JSON_QUOTE(CAST(NEW.`package_price` AS CHAR)), 'null'))), IF(OLD.`base_total` <=> NEW.`base_total`, NULL, CONCAT('"base_total":', IFNULL(JSON_QUOTE(CAST(NEW.`base_total` AS CHAR)), 'null'))), IF(OLD.`menu_upgrade_total` <=> NEW.`menu_upgrade_total`, NULL, CONCAT('"menu_upgrade_total":', IFNULL(JSON_QUOTE(CAST(NEW.`menu_upgrade_total` AS CHAR)), 'null'))), IF(OLD.`addon_total` <=> NEW.`addon_total`, NULL, CONCAT('"addon_total":', IFNULL(JSON_QUOTE(CAST(NEW.`addon_total` AS CHAR)), 'null'))), IF(OLD.`subtotal` <=> NEW.`subtotal`, NULL, CONCAT('"subtotal":', IFNULL(JSON_QUOTE(CAST(NEW.`subtotal` AS CHAR)), 'null'))), IF(OLD.`service_tax_rate` <=> NEW.`service_tax_rate`, NULL, CONCAT('"service_tax_rate":', IFNULL(JSON_QUOTE(CAST(NEW.`service_tax_rate` AS CHAR)), 'null'))), IF(OLD.`service_tax` <=> NEW.`service_tax`, NULL, CONCAT('"service_tax":', IFNULL(JSON_QUOTE(CAST(NEW.`service_tax` AS CHAR)), 'null'))), IF(OLD.`grand_total` <=> NEW.`grand_total`, NULL, CONCAT('"grand_total":', IFNULL(JSON_QUOTE(CAST(NEW.`grand_total` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null'))), IF(OLD.`preparation_status` <=> NEW.`preparation_status`, NULL, CONCAT('"preparation_status":', IFNULL(JSON_QUOTE(CAST(NEW.`preparation_status` AS CHAR)), 'null'))), IF(OLD.`preparation_notes` <=> NEW.`preparation_notes`, NULL, CONCAT('"preparation_notes":', IFNULL(JSON_QUOTE(CAST(NEW.`preparation_notes` AS CHAR)), 'null'))), IF(OLD.`completed_at` <=> NEW.`completed_at`, NULL, CONCAT('"completed_at":', IFNULL(JSON_QUOTE(CAST(NEW.`completed_at` AS CHAR)), 'null'))), IF(OLD.`completed_by` <=> NEW.`completed_by`, NULL, CONCAT('"completed_by":', IFNULL(JSON_QUOTE(CAST(NEW.`completed_by` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archive_expires_at` <=> NEW.`archive_expires_at`, NULL, CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archive_expires_at` AS CHAR)), 'null'))), IF(OLD.`pre_archive_status` <=> NEW.`pre_archive_status`, NULL, CONCAT('"pre_archive_status":', IFNULL(JSON_QUOTE(CAST(NEW.`pre_archive_status` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null'))), IF(OLD.`restored_at` <=> NEW.`restored_at`, NULL, CONCAT('"restored_at":', IFNULL(JSON_QUOTE(CAST(NEW.`restored_at` AS CHAR)), 'null'))), IF(OLD.`restored_by` <=> NEW.`restored_by`, NULL, CONCAT('"restored_by":', IFNULL(JSON_QUOTE(CAST(NEW.`restored_by` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_bookings_ad` AFTER DELETE ON `event_bookings` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Event Bookings', 'event_bookings', CAST(OLD.`booking_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`booking_ref`, OLD.`event_name`), ''), 255),
            CONCAT('Deleted event booking #', OLD.`booking_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`booking_ref`, OLD.`event_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"booking_id":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_id` AS CHAR)), 'null')), CONCAT('"booking_ref":', IFNULL(JSON_QUOTE(CAST(OLD.`booking_ref` AS CHAR)), 'null')), CONCAT('"client_id":', IFNULL(JSON_QUOTE(CAST(OLD.`client_id` AS CHAR)), 'null')), CONCAT('"event_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_name` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null')), CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_id` AS CHAR)), 'null')), CONCAT('"event_date":', IFNULL(JSON_QUOTE(CAST(OLD.`event_date` AS CHAR)), 'null')), CONCAT('"event_time":', IFNULL(JSON_QUOTE(CAST(OLD.`event_time` AS CHAR)), 'null')), CONCAT('"fullname":', IFNULL(JSON_QUOTE(CAST(OLD.`fullname` AS CHAR)), 'null')), CONCAT('"email":', IFNULL(JSON_QUOTE(CAST(OLD.`email` AS CHAR)), 'null')), CONCAT('"contact":', IFNULL(JSON_QUOTE(CAST(OLD.`contact` AS CHAR)), 'null')), CONCAT('"region":', IFNULL(JSON_QUOTE(CAST(OLD.`region` AS CHAR)), 'null')), CONCAT('"province":', IFNULL(JSON_QUOTE(CAST(OLD.`province` AS CHAR)), 'null')), CONCAT('"city":', IFNULL(JSON_QUOTE(CAST(OLD.`city` AS CHAR)), 'null')), CONCAT('"barangay":', IFNULL(JSON_QUOTE(CAST(OLD.`barangay` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null')), CONCAT('"backdrop_id":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_id` AS CHAR)), 'null')), CONCAT('"backdrop_name":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_name` AS CHAR)), 'null')), CONCAT('"backdrop_path":', IFNULL(JSON_QUOTE(CAST(OLD.`backdrop_path` AS CHAR)), 'null')), CONCAT('"event_theme":', IFNULL(JSON_QUOTE(CAST(OLD.`event_theme` AS CHAR)), 'null')), CONCAT('"event_color":', IFNULL(JSON_QUOTE(CAST(OLD.`event_color` AS CHAR)), 'null')), CONCAT('"venue_address":', IFNULL(JSON_QUOTE(CAST(OLD.`venue_address` AS CHAR)), 'null')), CONCAT('"headcount":', IFNULL(JSON_QUOTE(CAST(OLD.`headcount` AS CHAR)), 'null')), CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(OLD.`package_id` AS CHAR)), 'null')), CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(OLD.`package_name` AS CHAR)), 'null')), CONCAT('"package_price":', IFNULL(JSON_QUOTE(CAST(OLD.`package_price` AS CHAR)), 'null')), CONCAT('"base_total":', IFNULL(JSON_QUOTE(CAST(OLD.`base_total` AS CHAR)), 'null')), CONCAT('"menu_upgrade_total":', IFNULL(JSON_QUOTE(CAST(OLD.`menu_upgrade_total` AS CHAR)), 'null')), CONCAT('"addon_total":', IFNULL(JSON_QUOTE(CAST(OLD.`addon_total` AS CHAR)), 'null')), CONCAT('"subtotal":', IFNULL(JSON_QUOTE(CAST(OLD.`subtotal` AS CHAR)), 'null')), CONCAT('"service_tax_rate":', IFNULL(JSON_QUOTE(CAST(OLD.`service_tax_rate` AS CHAR)), 'null')), CONCAT('"service_tax":', IFNULL(JSON_QUOTE(CAST(OLD.`service_tax` AS CHAR)), 'null')), CONCAT('"grand_total":', IFNULL(JSON_QUOTE(CAST(OLD.`grand_total` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null')), CONCAT('"preparation_status":', IFNULL(JSON_QUOTE(CAST(OLD.`preparation_status` AS CHAR)), 'null')), CONCAT('"preparation_notes":', IFNULL(JSON_QUOTE(CAST(OLD.`preparation_notes` AS CHAR)), 'null')), CONCAT('"completed_at":', IFNULL(JSON_QUOTE(CAST(OLD.`completed_at` AS CHAR)), 'null')), CONCAT('"completed_by":', IFNULL(JSON_QUOTE(CAST(OLD.`completed_by` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null')), CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archive_expires_at` AS CHAR)), 'null')), CONCAT('"pre_archive_status":', IFNULL(JSON_QUOTE(CAST(OLD.`pre_archive_status` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null')), CONCAT('"restored_at":', IFNULL(JSON_QUOTE(CAST(OLD.`restored_at` AS CHAR)), 'null')), CONCAT('"restored_by":', IFNULL(JSON_QUOTE(CAST(OLD.`restored_by` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `event_types`
--

DROP TABLE IF EXISTS `event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `event_types` (
  `event_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_type_name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`event_type_id`),
  UNIQUE KEY `uq_event_type_name` (`event_type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=107 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_types_ai` AFTER INSERT ON `event_types` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Event Bookings', 'event_types', CAST(NEW.`event_type_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`event_type_name`), ''), 255),
            CONCAT('Created event type #', NEW.`event_type_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`event_type_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_id` AS CHAR)), 'null')), CONCAT('"event_type_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_name` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(NEW.`sort_order` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_types_au` AFTER UPDATE ON `event_types` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`event_type_id` <=> NEW.`event_type_id`) AND (OLD.`event_type_name` <=> NEW.`event_type_name`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`sort_order` <=> NEW.`sort_order`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Event Bookings', 'event_types', CAST(NEW.`event_type_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`event_type_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' event type #', NEW.`event_type_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`event_type_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`event_type_id` <=> NEW.`event_type_id`, NULL, CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_id` AS CHAR)), 'null'))), IF(OLD.`event_type_name` <=> NEW.`event_type_name`, NULL, CONCAT('"event_type_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_name` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`sort_order` <=> NEW.`sort_order`, NULL, CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(OLD.`sort_order` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`event_type_id` <=> NEW.`event_type_id`, NULL, CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_id` AS CHAR)), 'null'))), IF(OLD.`event_type_name` <=> NEW.`event_type_name`, NULL, CONCAT('"event_type_name":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type_name` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`sort_order` <=> NEW.`sort_order`, NULL, CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(NEW.`sort_order` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_event_types_ad` AFTER DELETE ON `event_types` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Event Bookings', 'event_types', CAST(OLD.`event_type_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`event_type_name`), ''), 255),
            CONCAT('Deleted event type #', OLD.`event_type_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`event_type_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"event_type_id":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_id` AS CHAR)), 'null')), CONCAT('"event_type_name":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type_name` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(OLD.`sort_order` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `foodstocks`
--

DROP TABLE IF EXISTS `foodstocks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `foodstocks` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 0.00,
  `unit` varchar(50) DEFAULT 'kg',
  `unit_price` decimal(10,2) DEFAULT 0.00,
  `reorder_level` int(11) DEFAULT 10,
  `expiry_date` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  KEY `category_id` (`category_id`),
  CONSTRAINT `foodstocks_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `foodstocks_categories` (`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_ai` AFTER INSERT ON `foodstocks` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Food Stocks', 'foodstocks', CAST(NEW.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), 255),
            CONCAT('Created food stock item #', NEW.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(NEW.`item_id` AS CHAR)), 'null')), CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(NEW.`category_id` AS CHAR)), 'null')), CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(NEW.`item_name` AS CHAR)), 'null')), CONCAT('"quantity":', IFNULL(JSON_QUOTE(CAST(NEW.`quantity` AS CHAR)), 'null')), CONCAT('"unit":', IFNULL(JSON_QUOTE(CAST(NEW.`unit` AS CHAR)), 'null')), CONCAT('"unit_price":', IFNULL(JSON_QUOTE(CAST(NEW.`unit_price` AS CHAR)), 'null')), CONCAT('"reorder_level":', IFNULL(JSON_QUOTE(CAST(NEW.`reorder_level` AS CHAR)), 'null')), CONCAT('"expiry_date":', IFNULL(JSON_QUOTE(CAST(NEW.`expiry_date` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_au` AFTER UPDATE ON `foodstocks` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`item_id` <=> NEW.`item_id`) AND (OLD.`category_id` <=> NEW.`category_id`) AND (OLD.`item_name` <=> NEW.`item_name`) AND (OLD.`quantity` <=> NEW.`quantity`) AND (OLD.`unit` <=> NEW.`unit`) AND (OLD.`unit_price` <=> NEW.`unit_price`) AND (OLD.`reorder_level` <=> NEW.`reorder_level`) AND (OLD.`expiry_date` <=> NEW.`expiry_date`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`notes` <=> NEW.`notes`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Food Stocks', 'foodstocks', CAST(NEW.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' food stock item #', NEW.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`item_id` <=> NEW.`item_id`, NULL, CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(OLD.`item_id` AS CHAR)), 'null'))), IF(OLD.`category_id` <=> NEW.`category_id`, NULL, CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(OLD.`category_id` AS CHAR)), 'null'))), IF(OLD.`item_name` <=> NEW.`item_name`, NULL, CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(OLD.`item_name` AS CHAR)), 'null'))), IF(OLD.`quantity` <=> NEW.`quantity`, NULL, CONCAT('"quantity":', IFNULL(JSON_QUOTE(CAST(OLD.`quantity` AS CHAR)), 'null'))), IF(OLD.`unit` <=> NEW.`unit`, NULL, CONCAT('"unit":', IFNULL(JSON_QUOTE(CAST(OLD.`unit` AS CHAR)), 'null'))), IF(OLD.`unit_price` <=> NEW.`unit_price`, NULL, CONCAT('"unit_price":', IFNULL(JSON_QUOTE(CAST(OLD.`unit_price` AS CHAR)), 'null'))), IF(OLD.`reorder_level` <=> NEW.`reorder_level`, NULL, CONCAT('"reorder_level":', IFNULL(JSON_QUOTE(CAST(OLD.`reorder_level` AS CHAR)), 'null'))), IF(OLD.`expiry_date` <=> NEW.`expiry_date`, NULL, CONCAT('"expiry_date":', IFNULL(JSON_QUOTE(CAST(OLD.`expiry_date` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`item_id` <=> NEW.`item_id`, NULL, CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(NEW.`item_id` AS CHAR)), 'null'))), IF(OLD.`category_id` <=> NEW.`category_id`, NULL, CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(NEW.`category_id` AS CHAR)), 'null'))), IF(OLD.`item_name` <=> NEW.`item_name`, NULL, CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(NEW.`item_name` AS CHAR)), 'null'))), IF(OLD.`quantity` <=> NEW.`quantity`, NULL, CONCAT('"quantity":', IFNULL(JSON_QUOTE(CAST(NEW.`quantity` AS CHAR)), 'null'))), IF(OLD.`unit` <=> NEW.`unit`, NULL, CONCAT('"unit":', IFNULL(JSON_QUOTE(CAST(NEW.`unit` AS CHAR)), 'null'))), IF(OLD.`unit_price` <=> NEW.`unit_price`, NULL, CONCAT('"unit_price":', IFNULL(JSON_QUOTE(CAST(NEW.`unit_price` AS CHAR)), 'null'))), IF(OLD.`reorder_level` <=> NEW.`reorder_level`, NULL, CONCAT('"reorder_level":', IFNULL(JSON_QUOTE(CAST(NEW.`reorder_level` AS CHAR)), 'null'))), IF(OLD.`expiry_date` <=> NEW.`expiry_date`, NULL, CONCAT('"expiry_date":', IFNULL(JSON_QUOTE(CAST(NEW.`expiry_date` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`notes` <=> NEW.`notes`, NULL, CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(NEW.`notes` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_ad` AFTER DELETE ON `foodstocks` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Food Stocks', 'foodstocks', CAST(OLD.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`item_name`), ''), 255),
            CONCAT('Deleted food stock item #', OLD.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`item_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(OLD.`item_id` AS CHAR)), 'null')), CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(OLD.`category_id` AS CHAR)), 'null')), CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(OLD.`item_name` AS CHAR)), 'null')), CONCAT('"quantity":', IFNULL(JSON_QUOTE(CAST(OLD.`quantity` AS CHAR)), 'null')), CONCAT('"unit":', IFNULL(JSON_QUOTE(CAST(OLD.`unit` AS CHAR)), 'null')), CONCAT('"unit_price":', IFNULL(JSON_QUOTE(CAST(OLD.`unit_price` AS CHAR)), 'null')), CONCAT('"reorder_level":', IFNULL(JSON_QUOTE(CAST(OLD.`reorder_level` AS CHAR)), 'null')), CONCAT('"expiry_date":', IFNULL(JSON_QUOTE(CAST(OLD.`expiry_date` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"notes":', IFNULL(JSON_QUOTE(CAST(OLD.`notes` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `foodstocks_categories`
--

DROP TABLE IF EXISTS `foodstocks_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `foodstocks_categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_categories_ai` AFTER INSERT ON `foodstocks_categories` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Food Stocks', 'foodstocks_categories', CAST(NEW.`category_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`category_name`), ''), 255),
            CONCAT('Created food stock category #', NEW.`category_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`category_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(NEW.`category_id` AS CHAR)), 'null')), CONCAT('"category_name":', IFNULL(JSON_QUOTE(CAST(NEW.`category_name` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_categories_au` AFTER UPDATE ON `foodstocks_categories` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`category_id` <=> NEW.`category_id`) AND (OLD.`category_name` <=> NEW.`category_name`) AND (OLD.`description` <=> NEW.`description`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Food Stocks', 'foodstocks_categories', CAST(NEW.`category_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`category_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' food stock category #', NEW.`category_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`category_name`), ''), ')'), '')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`category_id` <=> NEW.`category_id`, NULL, CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(OLD.`category_id` AS CHAR)), 'null'))), IF(OLD.`category_name` <=> NEW.`category_name`, NULL, CONCAT('"category_name":', IFNULL(JSON_QUOTE(CAST(OLD.`category_name` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`category_id` <=> NEW.`category_id`, NULL, CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(NEW.`category_id` AS CHAR)), 'null'))), IF(OLD.`category_name` <=> NEW.`category_name`, NULL, CONCAT('"category_name":', IFNULL(JSON_QUOTE(CAST(NEW.`category_name` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_foodstocks_categories_ad` AFTER DELETE ON `foodstocks_categories` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Food Stocks', 'foodstocks_categories', CAST(OLD.`category_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`category_name`), ''), 255),
            CONCAT('Deleted food stock category #', OLD.`category_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`category_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"category_id":', IFNULL(JSON_QUOTE(CAST(OLD.`category_id` AS CHAR)), 'null')), CONCAT('"category_name":', IFNULL(JSON_QUOTE(CAST(OLD.`category_name` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `menu_bundles`
--

DROP TABLE IF EXISTS `menu_bundles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_bundles` (
  `bundle_id` int(11) NOT NULL AUTO_INCREMENT,
  `bundle_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `bundle_type` enum('Food Bundle','Add-on','Service') DEFAULT 'Food Bundle',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `price_mode` enum('flat','per_guest') DEFAULT 'flat',
  `guests_covered` smallint(5) unsigned DEFAULT NULL COMMENT 'Guests served by one order (per table); NULL when not set. Not used for per-guest prices.',
  `order_note` varchar(150) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`bundle_id`),
  UNIQUE KEY `uq_bundle_name` (`bundle_name`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_bundles_ai` AFTER INSERT ON `menu_bundles` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Food Menus', 'menu_bundles', CAST(NEW.`bundle_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`bundle_name`), ''), 255),
            CONCAT('Created menu bundle #', NEW.`bundle_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`bundle_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"bundle_id":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_id` AS CHAR)), 'null')), CONCAT('"bundle_name":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_name` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null')), CONCAT('"bundle_type":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_type` AS CHAR)), 'null')), CONCAT('"price":', IFNULL(JSON_QUOTE(CAST(NEW.`price` AS CHAR)), 'null')), CONCAT('"price_mode":', IFNULL(JSON_QUOTE(CAST(NEW.`price_mode` AS CHAR)), 'null')), CONCAT('"guests_covered":', IFNULL(JSON_QUOTE(CAST(NEW.`guests_covered` AS CHAR)), 'null')), CONCAT('"order_note":', IFNULL(JSON_QUOTE(CAST(NEW.`order_note` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_bundles_au` AFTER UPDATE ON `menu_bundles` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`bundle_id` <=> NEW.`bundle_id`) AND (OLD.`bundle_name` <=> NEW.`bundle_name`) AND (OLD.`description` <=> NEW.`description`) AND (OLD.`bundle_type` <=> NEW.`bundle_type`) AND (OLD.`price` <=> NEW.`price`) AND (OLD.`price_mode` <=> NEW.`price_mode`) AND (OLD.`guests_covered` <=> NEW.`guests_covered`) AND (OLD.`order_note` <=> NEW.`order_note`) AND (OLD.`photo_path` <=> NEW.`photo_path`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Food Menus', 'menu_bundles', CAST(NEW.`bundle_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`bundle_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' menu bundle #', NEW.`bundle_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`bundle_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`bundle_id` <=> NEW.`bundle_id`, NULL, CONCAT('"bundle_id":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_id` AS CHAR)), 'null'))), IF(OLD.`bundle_name` <=> NEW.`bundle_name`, NULL, CONCAT('"bundle_name":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_name` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null'))), IF(OLD.`bundle_type` <=> NEW.`bundle_type`, NULL, CONCAT('"bundle_type":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_type` AS CHAR)), 'null'))), IF(OLD.`price` <=> NEW.`price`, NULL, CONCAT('"price":', IFNULL(JSON_QUOTE(CAST(OLD.`price` AS CHAR)), 'null'))), IF(OLD.`price_mode` <=> NEW.`price_mode`, NULL, CONCAT('"price_mode":', IFNULL(JSON_QUOTE(CAST(OLD.`price_mode` AS CHAR)), 'null'))), IF(OLD.`guests_covered` <=> NEW.`guests_covered`, NULL, CONCAT('"guests_covered":', IFNULL(JSON_QUOTE(CAST(OLD.`guests_covered` AS CHAR)), 'null'))), IF(OLD.`order_note` <=> NEW.`order_note`, NULL, CONCAT('"order_note":', IFNULL(JSON_QUOTE(CAST(OLD.`order_note` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`bundle_id` <=> NEW.`bundle_id`, NULL, CONCAT('"bundle_id":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_id` AS CHAR)), 'null'))), IF(OLD.`bundle_name` <=> NEW.`bundle_name`, NULL, CONCAT('"bundle_name":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_name` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null'))), IF(OLD.`bundle_type` <=> NEW.`bundle_type`, NULL, CONCAT('"bundle_type":', IFNULL(JSON_QUOTE(CAST(NEW.`bundle_type` AS CHAR)), 'null'))), IF(OLD.`price` <=> NEW.`price`, NULL, CONCAT('"price":', IFNULL(JSON_QUOTE(CAST(NEW.`price` AS CHAR)), 'null'))), IF(OLD.`price_mode` <=> NEW.`price_mode`, NULL, CONCAT('"price_mode":', IFNULL(JSON_QUOTE(CAST(NEW.`price_mode` AS CHAR)), 'null'))), IF(OLD.`guests_covered` <=> NEW.`guests_covered`, NULL, CONCAT('"guests_covered":', IFNULL(JSON_QUOTE(CAST(NEW.`guests_covered` AS CHAR)), 'null'))), IF(OLD.`order_note` <=> NEW.`order_note`, NULL, CONCAT('"order_note":', IFNULL(JSON_QUOTE(CAST(NEW.`order_note` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_bundles_ad` AFTER DELETE ON `menu_bundles` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Food Menus', 'menu_bundles', CAST(OLD.`bundle_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`bundle_name`), ''), 255),
            CONCAT('Deleted menu bundle #', OLD.`bundle_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`bundle_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"bundle_id":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_id` AS CHAR)), 'null')), CONCAT('"bundle_name":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_name` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null')), CONCAT('"bundle_type":', IFNULL(JSON_QUOTE(CAST(OLD.`bundle_type` AS CHAR)), 'null')), CONCAT('"price":', IFNULL(JSON_QUOTE(CAST(OLD.`price` AS CHAR)), 'null')), CONCAT('"price_mode":', IFNULL(JSON_QUOTE(CAST(OLD.`price_mode` AS CHAR)), 'null')), CONCAT('"guests_covered":', IFNULL(JSON_QUOTE(CAST(OLD.`guests_covered` AS CHAR)), 'null')), CONCAT('"order_note":', IFNULL(JSON_QUOTE(CAST(OLD.`order_note` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `menu_items`
--

DROP TABLE IF EXISTS `menu_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_items` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(200) NOT NULL,
  `course` enum('Appetizers','Main Courses','Desserts','Beverages','Sides') NOT NULL DEFAULT 'Main Courses',
  `description` varchar(255) DEFAULT NULL,
  `availability_note` varchar(150) DEFAULT NULL COMMENT 'e.g. In Gold/Platinum, Included',
  `upgrade_price` decimal(10,2) DEFAULT 0.00 COMMENT 'Extra per-guest cost when selected',
  `photo_path` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uq_item_name` (`item_name`)
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_items_ai` AFTER INSERT ON `menu_items` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Food Menus', 'menu_items', CAST(NEW.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), 255),
            CONCAT('Created menu item #', NEW.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(NEW.`item_id` AS CHAR)), 'null')), CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(NEW.`item_name` AS CHAR)), 'null')), CONCAT('"course":', IFNULL(JSON_QUOTE(CAST(NEW.`course` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null')), CONCAT('"availability_note":', IFNULL(JSON_QUOTE(CAST(NEW.`availability_note` AS CHAR)), 'null')), CONCAT('"upgrade_price":', IFNULL(JSON_QUOTE(CAST(NEW.`upgrade_price` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_items_au` AFTER UPDATE ON `menu_items` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`item_id` <=> NEW.`item_id`) AND (OLD.`item_name` <=> NEW.`item_name`) AND (OLD.`course` <=> NEW.`course`) AND (OLD.`description` <=> NEW.`description`) AND (OLD.`availability_note` <=> NEW.`availability_note`) AND (OLD.`upgrade_price` <=> NEW.`upgrade_price`) AND (OLD.`photo_path` <=> NEW.`photo_path`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Food Menus', 'menu_items', CAST(NEW.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' menu item #', NEW.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`item_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`item_id` <=> NEW.`item_id`, NULL, CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(OLD.`item_id` AS CHAR)), 'null'))), IF(OLD.`item_name` <=> NEW.`item_name`, NULL, CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(OLD.`item_name` AS CHAR)), 'null'))), IF(OLD.`course` <=> NEW.`course`, NULL, CONCAT('"course":', IFNULL(JSON_QUOTE(CAST(OLD.`course` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null'))), IF(OLD.`availability_note` <=> NEW.`availability_note`, NULL, CONCAT('"availability_note":', IFNULL(JSON_QUOTE(CAST(OLD.`availability_note` AS CHAR)), 'null'))), IF(OLD.`upgrade_price` <=> NEW.`upgrade_price`, NULL, CONCAT('"upgrade_price":', IFNULL(JSON_QUOTE(CAST(OLD.`upgrade_price` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`item_id` <=> NEW.`item_id`, NULL, CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(NEW.`item_id` AS CHAR)), 'null'))), IF(OLD.`item_name` <=> NEW.`item_name`, NULL, CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(NEW.`item_name` AS CHAR)), 'null'))), IF(OLD.`course` <=> NEW.`course`, NULL, CONCAT('"course":', IFNULL(JSON_QUOTE(CAST(NEW.`course` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null'))), IF(OLD.`availability_note` <=> NEW.`availability_note`, NULL, CONCAT('"availability_note":', IFNULL(JSON_QUOTE(CAST(NEW.`availability_note` AS CHAR)), 'null'))), IF(OLD.`upgrade_price` <=> NEW.`upgrade_price`, NULL, CONCAT('"upgrade_price":', IFNULL(JSON_QUOTE(CAST(NEW.`upgrade_price` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_items_ad` AFTER DELETE ON `menu_items` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Food Menus', 'menu_items', CAST(OLD.`item_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`item_name`), ''), 255),
            CONCAT('Deleted menu item #', OLD.`item_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`item_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"item_id":', IFNULL(JSON_QUOTE(CAST(OLD.`item_id` AS CHAR)), 'null')), CONCAT('"item_name":', IFNULL(JSON_QUOTE(CAST(OLD.`item_name` AS CHAR)), 'null')), CONCAT('"course":', IFNULL(JSON_QUOTE(CAST(OLD.`course` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null')), CONCAT('"availability_note":', IFNULL(JSON_QUOTE(CAST(OLD.`availability_note` AS CHAR)), 'null')), CONCAT('"upgrade_price":', IFNULL(JSON_QUOTE(CAST(OLD.`upgrade_price` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `menu_packages`
--

DROP TABLE IF EXISTS `menu_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_packages` (
  `package_id` int(11) NOT NULL AUTO_INCREMENT,
  `package_name` varchar(150) NOT NULL,
  `tier` varchar(50) DEFAULT 'Standard',
  `price_per_guest` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pricing_note` varchar(150) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT 'bx-restaurant',
  `event_type` varchar(100) DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`package_id`),
  UNIQUE KEY `uq_package_name` (`package_name`),
  KEY `idx_event_type` (`event_type`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_packages_ai` AFTER INSERT ON `menu_packages` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Food Menus', 'menu_packages', CAST(NEW.`package_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`package_name`), ''), 255),
            CONCAT('Created menu package #', NEW.`package_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`package_name`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(NEW.`package_id` AS CHAR)), 'null')), CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(NEW.`package_name` AS CHAR)), 'null')), CONCAT('"tier":', IFNULL(JSON_QUOTE(CAST(NEW.`tier` AS CHAR)), 'null')), CONCAT('"price_per_guest":', IFNULL(JSON_QUOTE(CAST(NEW.`price_per_guest` AS CHAR)), 'null')), CONCAT('"pricing_note":', IFNULL(JSON_QUOTE(CAST(NEW.`pricing_note` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null')), CONCAT('"icon":', IFNULL(JSON_QUOTE(CAST(NEW.`icon` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null')), CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(NEW.`sort_order` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_packages_au` AFTER UPDATE ON `menu_packages` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`package_id` <=> NEW.`package_id`) AND (OLD.`package_name` <=> NEW.`package_name`) AND (OLD.`tier` <=> NEW.`tier`) AND (OLD.`price_per_guest` <=> NEW.`price_per_guest`) AND (OLD.`pricing_note` <=> NEW.`pricing_note`) AND (OLD.`description` <=> NEW.`description`) AND (OLD.`icon` <=> NEW.`icon`) AND (OLD.`event_type` <=> NEW.`event_type`) AND (OLD.`photo_path` <=> NEW.`photo_path`) AND (OLD.`sort_order` <=> NEW.`sort_order`) AND (OLD.`is_active` <=> NEW.`is_active`) AND (OLD.`created_at` <=> NEW.`created_at`)) THEN
                SET v_action = 'UPDATE';
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Food Menus', 'menu_packages', CAST(NEW.`package_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`package_name`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' menu package #', NEW.`package_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`package_name`), ''), ')'), ''), IF(OLD.`is_active` <=> NEW.`is_active`, '', IF(NEW.`is_active` = 1, ' - activated', ' - deactivated'))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`package_id` <=> NEW.`package_id`, NULL, CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(OLD.`package_id` AS CHAR)), 'null'))), IF(OLD.`package_name` <=> NEW.`package_name`, NULL, CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(OLD.`package_name` AS CHAR)), 'null'))), IF(OLD.`tier` <=> NEW.`tier`, NULL, CONCAT('"tier":', IFNULL(JSON_QUOTE(CAST(OLD.`tier` AS CHAR)), 'null'))), IF(OLD.`price_per_guest` <=> NEW.`price_per_guest`, NULL, CONCAT('"price_per_guest":', IFNULL(JSON_QUOTE(CAST(OLD.`price_per_guest` AS CHAR)), 'null'))), IF(OLD.`pricing_note` <=> NEW.`pricing_note`, NULL, CONCAT('"pricing_note":', IFNULL(JSON_QUOTE(CAST(OLD.`pricing_note` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null'))), IF(OLD.`icon` <=> NEW.`icon`, NULL, CONCAT('"icon":', IFNULL(JSON_QUOTE(CAST(OLD.`icon` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null'))), IF(OLD.`sort_order` <=> NEW.`sort_order`, NULL, CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(OLD.`sort_order` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`package_id` <=> NEW.`package_id`, NULL, CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(NEW.`package_id` AS CHAR)), 'null'))), IF(OLD.`package_name` <=> NEW.`package_name`, NULL, CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(NEW.`package_name` AS CHAR)), 'null'))), IF(OLD.`tier` <=> NEW.`tier`, NULL, CONCAT('"tier":', IFNULL(JSON_QUOTE(CAST(NEW.`tier` AS CHAR)), 'null'))), IF(OLD.`price_per_guest` <=> NEW.`price_per_guest`, NULL, CONCAT('"price_per_guest":', IFNULL(JSON_QUOTE(CAST(NEW.`price_per_guest` AS CHAR)), 'null'))), IF(OLD.`pricing_note` <=> NEW.`pricing_note`, NULL, CONCAT('"pricing_note":', IFNULL(JSON_QUOTE(CAST(NEW.`pricing_note` AS CHAR)), 'null'))), IF(OLD.`description` <=> NEW.`description`, NULL, CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(NEW.`description` AS CHAR)), 'null'))), IF(OLD.`icon` <=> NEW.`icon`, NULL, CONCAT('"icon":', IFNULL(JSON_QUOTE(CAST(NEW.`icon` AS CHAR)), 'null'))), IF(OLD.`event_type` <=> NEW.`event_type`, NULL, CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(NEW.`event_type` AS CHAR)), 'null'))), IF(OLD.`photo_path` <=> NEW.`photo_path`, NULL, CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(NEW.`photo_path` AS CHAR)), 'null'))), IF(OLD.`sort_order` <=> NEW.`sort_order`, NULL, CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(NEW.`sort_order` AS CHAR)), 'null'))), IF(OLD.`is_active` <=> NEW.`is_active`, NULL, CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(NEW.`is_active` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_menu_packages_ad` AFTER DELETE ON `menu_packages` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Food Menus', 'menu_packages', CAST(OLD.`package_id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`package_name`), ''), 255),
            CONCAT('Deleted menu package #', OLD.`package_id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`package_name`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"package_id":', IFNULL(JSON_QUOTE(CAST(OLD.`package_id` AS CHAR)), 'null')), CONCAT('"package_name":', IFNULL(JSON_QUOTE(CAST(OLD.`package_name` AS CHAR)), 'null')), CONCAT('"tier":', IFNULL(JSON_QUOTE(CAST(OLD.`tier` AS CHAR)), 'null')), CONCAT('"price_per_guest":', IFNULL(JSON_QUOTE(CAST(OLD.`price_per_guest` AS CHAR)), 'null')), CONCAT('"pricing_note":', IFNULL(JSON_QUOTE(CAST(OLD.`pricing_note` AS CHAR)), 'null')), CONCAT('"description":', IFNULL(JSON_QUOTE(CAST(OLD.`description` AS CHAR)), 'null')), CONCAT('"icon":', IFNULL(JSON_QUOTE(CAST(OLD.`icon` AS CHAR)), 'null')), CONCAT('"event_type":', IFNULL(JSON_QUOTE(CAST(OLD.`event_type` AS CHAR)), 'null')), CONCAT('"photo_path":', IFNULL(JSON_QUOTE(CAST(OLD.`photo_path` AS CHAR)), 'null')), CONCAT('"sort_order":', IFNULL(JSON_QUOTE(CAST(OLD.`sort_order` AS CHAR)), 'null')), CONCAT('"is_active":', IFNULL(JSON_QUOTE(CAST(OLD.`is_active` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` enum('report','event','inventory','system','feedback') NOT NULL DEFAULT 'system',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `reference_type` varchar(50) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `activity_log_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notification_event` (`user_id`,`activity_log_id`),
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  KEY `type` (`type`),
  KEY `idx_notification_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3829 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `package_event_types`
--

DROP TABLE IF EXISTS `package_event_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `package_event_types` (
  `package_id` int(11) NOT NULL,
  `event_type_id` int(11) NOT NULL,
  PRIMARY KEY (`package_id`,`event_type_id`),
  KEY `fk_pet_type` (`event_type_id`),
  CONSTRAINT `fk_pet_package` FOREIGN KEY (`package_id`) REFERENCES `menu_packages` (`package_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pet_type` FOREIGN KEY (`event_type_id`) REFERENCES `event_types` (`event_type_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `package_inclusions`
--

DROP TABLE IF EXISTS `package_inclusions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `package_inclusions` (
  `inclusion_id` int(11) NOT NULL AUTO_INCREMENT,
  `package_id` int(11) NOT NULL,
  `item_id` int(11) DEFAULT NULL,
  `inclusion_text` varchar(200) NOT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`inclusion_id`),
  UNIQUE KEY `uq_package_inclusion` (`package_id`,`inclusion_text`),
  KEY `fk_pi_item` (`item_id`),
  CONSTRAINT `fk_pi_item` FOREIGN KEY (`item_id`) REFERENCES `menu_items` (`item_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pi_package` FOREIGN KEY (`package_id`) REFERENCES `menu_packages` (`package_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_receipt_history`
--

DROP TABLE IF EXISTS `payment_receipt_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payment_receipt_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `payment_id` int(11) NOT NULL,
  `receipt_path` varchar(255) NOT NULL,
  `receipt_name` varchar(255) DEFAULT NULL,
  `receipt_mime` varchar(100) DEFAULT NULL,
  `verification_status` varchar(30) NOT NULL COMMENT 'Status when it was replaced',
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `review_notes` varchar(255) DEFAULT NULL,
  `replaced_by` int(11) DEFAULT NULL,
  `replaced_at` datetime NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_receipt_history_payment` (`payment_id`),
  KEY `fk_receipt_history_reviewer` (`reviewed_by`),
  KEY `fk_receipt_history_replacer` (`replaced_by`),
  CONSTRAINT `fk_receipt_history_payment` FOREIGN KEY (`payment_id`) REFERENCES `booking_payments` (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_history_replacer` FOREIGN KEY (`replaced_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_receipt_history_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `psgc_barangays`
--

DROP TABLE IF EXISTS `psgc_barangays`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `psgc_barangays` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `barangay_code` varchar(15) NOT NULL,
  `barangay_name` varchar(150) NOT NULL,
  `municipality_code` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barangay_code` (`barangay_code`),
  KEY `idx_brgy_municipality` (`municipality_code`)
) ENGINE=InnoDB AUTO_INCREMENT=42012 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `psgc_municipalities`
--

DROP TABLE IF EXISTS `psgc_municipalities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `psgc_municipalities` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `municipality_code` varchar(10) NOT NULL,
  `municipality_name` varchar(150) NOT NULL,
  `province_code` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `municipality_code` (`municipality_code`),
  KEY `idx_mun_province` (`province_code`)
) ENGINE=InnoDB AUTO_INCREMENT=1657 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `psgc_provinces`
--

DROP TABLE IF EXISTS `psgc_provinces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `psgc_provinces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `province_code` varchar(10) NOT NULL,
  `province_name` varchar(150) NOT NULL,
  `region_code` varchar(5) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `province_code` (`province_code`),
  KEY `idx_prov_region` (`region_code`)
) ENGINE=InnoDB AUTO_INCREMENT=116 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `psgc_regions`
--

DROP TABLE IF EXISTS `psgc_regions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `psgc_regions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `region_code` varchar(5) NOT NULL,
  `region_name` varchar(150) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `region_code` (`region_code`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reports`
--

DROP TABLE IF EXISTS `reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `report_type` enum('inventory','feedback','complaint','suggestion','inquiry','general') DEFAULT 'general',
  `created_by` varchar(100) DEFAULT NULL,
  `status` enum('Generated','Draft','Pending','Reviewed','Resolved') DEFAULT 'Generated',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(11) DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `archive_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `idx_report_archive` (`is_archived`,`archive_expires_at`),
  CONSTRAINT `fk_report_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_reports_ai` AFTER INSERT ON `reports` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Reports', 'reports', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`title`), ''), 255),
            CONCAT('Created report #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`title`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null')), CONCAT('"user_id":', IFNULL(JSON_QUOTE(CAST(NEW.`user_id` AS CHAR)), 'null')), CONCAT('"title":', IFNULL(JSON_QUOTE(CAST(NEW.`title` AS CHAR)), 'null')), CONCAT('"content":', IFNULL(JSON_QUOTE(CAST(NEW.`content` AS CHAR)), 'null')), CONCAT('"report_type":', IFNULL(JSON_QUOTE(CAST(NEW.`report_type` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null')), CONCAT('"admin_response":', IFNULL(JSON_QUOTE(CAST(NEW.`admin_response` AS CHAR)), 'null')), CONCAT('"responded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`responded_by` AS CHAR)), 'null')), CONCAT('"responded_at":', IFNULL(JSON_QUOTE(CAST(NEW.`responded_at` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null')), CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archive_expires_at` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_reports_au` AFTER UPDATE ON `reports` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`id` <=> NEW.`id`) AND (OLD.`user_id` <=> NEW.`user_id`) AND (OLD.`title` <=> NEW.`title`) AND (OLD.`content` <=> NEW.`content`) AND (OLD.`report_type` <=> NEW.`report_type`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`status` <=> NEW.`status`) AND (OLD.`admin_response` <=> NEW.`admin_response`) AND (OLD.`responded_by` <=> NEW.`responded_by`) AND (OLD.`responded_at` <=> NEW.`responded_at`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`archive_expires_at` <=> NEW.`archive_expires_at`)) THEN
                SET v_action = CASE WHEN COALESCE(OLD.`is_archived`,0) = 0 AND COALESCE(NEW.`is_archived`,0) = 1 THEN 'ARCHIVE' WHEN COALESCE(OLD.`is_archived`,0) = 1 AND COALESCE(NEW.`is_archived`,0) = 0 THEN 'RESTORE' WHEN NOT (OLD.`status` <=> NEW.`status`) AND ((OLD.`id` <=> NEW.`id`) AND (OLD.`user_id` <=> NEW.`user_id`) AND (OLD.`title` <=> NEW.`title`) AND (OLD.`content` <=> NEW.`content`) AND (OLD.`report_type` <=> NEW.`report_type`) AND (OLD.`created_by` <=> NEW.`created_by`) AND (OLD.`admin_response` <=> NEW.`admin_response`) AND (OLD.`responded_by` <=> NEW.`responded_by`) AND (OLD.`responded_at` <=> NEW.`responded_at`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`archive_expires_at` <=> NEW.`archive_expires_at`)) THEN 'STATUS_CHANGE' ELSE 'UPDATE' END;
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Reports', 'reports', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`title`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' report #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`title`), ''), ')'), ''), IF(OLD.`status` <=> NEW.`status`, '', CONCAT(' - status: ', IFNULL(OLD.`status`,'none'), ' -> ', IFNULL(NEW.`status`,'none')))),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null'))), IF(OLD.`user_id` <=> NEW.`user_id`, NULL, CONCAT('"user_id":', IFNULL(JSON_QUOTE(CAST(OLD.`user_id` AS CHAR)), 'null'))), IF(OLD.`title` <=> NEW.`title`, NULL, CONCAT('"title":', IFNULL(JSON_QUOTE(CAST(OLD.`title` AS CHAR)), 'null'))), IF(OLD.`content` <=> NEW.`content`, NULL, CONCAT('"content":', IFNULL(JSON_QUOTE(CAST(OLD.`content` AS CHAR)), 'null'))), IF(OLD.`report_type` <=> NEW.`report_type`, NULL, CONCAT('"report_type":', IFNULL(JSON_QUOTE(CAST(OLD.`report_type` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null'))), IF(OLD.`admin_response` <=> NEW.`admin_response`, NULL, CONCAT('"admin_response":', IFNULL(JSON_QUOTE(CAST(OLD.`admin_response` AS CHAR)), 'null'))), IF(OLD.`responded_by` <=> NEW.`responded_by`, NULL, CONCAT('"responded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`responded_by` AS CHAR)), 'null'))), IF(OLD.`responded_at` <=> NEW.`responded_at`, NULL, CONCAT('"responded_at":', IFNULL(JSON_QUOTE(CAST(OLD.`responded_at` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null'))), IF(OLD.`archive_expires_at` <=> NEW.`archive_expires_at`, NULL, CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archive_expires_at` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null'))), IF(OLD.`user_id` <=> NEW.`user_id`, NULL, CONCAT('"user_id":', IFNULL(JSON_QUOTE(CAST(NEW.`user_id` AS CHAR)), 'null'))), IF(OLD.`title` <=> NEW.`title`, NULL, CONCAT('"title":', IFNULL(JSON_QUOTE(CAST(NEW.`title` AS CHAR)), 'null'))), IF(OLD.`content` <=> NEW.`content`, NULL, CONCAT('"content":', IFNULL(JSON_QUOTE(CAST(NEW.`content` AS CHAR)), 'null'))), IF(OLD.`report_type` <=> NEW.`report_type`, NULL, CONCAT('"report_type":', IFNULL(JSON_QUOTE(CAST(NEW.`report_type` AS CHAR)), 'null'))), IF(OLD.`created_by` <=> NEW.`created_by`, NULL, CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(NEW.`created_by` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null'))), IF(OLD.`admin_response` <=> NEW.`admin_response`, NULL, CONCAT('"admin_response":', IFNULL(JSON_QUOTE(CAST(NEW.`admin_response` AS CHAR)), 'null'))), IF(OLD.`responded_by` <=> NEW.`responded_by`, NULL, CONCAT('"responded_by":', IFNULL(JSON_QUOTE(CAST(NEW.`responded_by` AS CHAR)), 'null'))), IF(OLD.`responded_at` <=> NEW.`responded_at`, NULL, CONCAT('"responded_at":', IFNULL(JSON_QUOTE(CAST(NEW.`responded_at` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null'))), IF(OLD.`archive_expires_at` <=> NEW.`archive_expires_at`, NULL, CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archive_expires_at` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_reports_ad` AFTER DELETE ON `reports` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Reports', 'reports', CAST(OLD.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`title`), ''), 255),
            CONCAT('Deleted report #', OLD.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`title`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null')), CONCAT('"user_id":', IFNULL(JSON_QUOTE(CAST(OLD.`user_id` AS CHAR)), 'null')), CONCAT('"title":', IFNULL(JSON_QUOTE(CAST(OLD.`title` AS CHAR)), 'null')), CONCAT('"content":', IFNULL(JSON_QUOTE(CAST(OLD.`content` AS CHAR)), 'null')), CONCAT('"report_type":', IFNULL(JSON_QUOTE(CAST(OLD.`report_type` AS CHAR)), 'null')), CONCAT('"created_by":', IFNULL(JSON_QUOTE(CAST(OLD.`created_by` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null')), CONCAT('"admin_response":', IFNULL(JSON_QUOTE(CAST(OLD.`admin_response` AS CHAR)), 'null')), CONCAT('"responded_by":', IFNULL(JSON_QUOTE(CAST(OLD.`responded_by` AS CHAR)), 'null')), CONCAT('"responded_at":', IFNULL(JSON_QUOTE(CAST(OLD.`responded_at` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null')), CONCAT('"archive_expires_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archive_expires_at` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `terms_acceptances`
--

DROP TABLE IF EXISTS `terms_acceptances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `terms_acceptances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `terms_version` varchar(20) NOT NULL,
  `accepted_at` datetime NOT NULL COMMENT 'First acceptance of this version',
  `last_accepted_at` datetime NOT NULL COMMENT 'Most recent sign-in that accepted it',
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_terms_user_version` (`user_id`,`terms_version`),
  CONSTRAINT `fk_terms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=73 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `role` enum('ADMIN','OWNER','SUPERVISOR') NOT NULL DEFAULT 'ADMIN',
  `region_code` varchar(5) DEFAULT NULL,
  `province_code` varchar(10) DEFAULT NULL,
  `city_code` varchar(10) DEFAULT NULL,
  `barangay_code` varchar(10) DEFAULT NULL,
  `street_address` text DEFAULT NULL,
  `postal_code` varchar(4) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `archived_by` int(11) DEFAULT NULL,
  `credentials_deleted_at` datetime DEFAULT NULL,
  `credentials_deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_users_contact` (`contact_number`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `role` (`role`),
  KEY `region_code` (`region_code`),
  KEY `province_code` (`province_code`),
  KEY `idx_users_archived` (`is_archived`),
  CONSTRAINT `chk_users_postal` CHECK (`postal_code` is null or `postal_code` regexp '^[0-9]{4}$'),
  CONSTRAINT `chk_users_role` CHECK (`role` in ('ADMIN','OWNER','SUPERVISOR'))
) ENGINE=InnoDB AUTO_INCREMENT=132 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_users_ai` AFTER INSERT ON `users` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'CREATE', 'Accounts', 'users', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`username`), ''), 255),
            CONCAT('Created user account #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`username`), ''), ')'), '')),
            NULL, CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null')), CONCAT('"username":', IFNULL(JSON_QUOTE(CAST(NEW.`username` AS CHAR)), 'null')), CONCAT('"password":', '"[protected]"'), CONCAT('"email":', '"[protected]"'), CONCAT('"first_name":', IFNULL(JSON_QUOTE(CAST(NEW.`first_name` AS CHAR)), 'null')), CONCAT('"last_name":', IFNULL(JSON_QUOTE(CAST(NEW.`last_name` AS CHAR)), 'null')), CONCAT('"contact_number":', IFNULL(JSON_QUOTE(CAST(NEW.`contact_number` AS CHAR)), 'null')), CONCAT('"profile_photo":', IFNULL(JSON_QUOTE(CAST(NEW.`profile_photo` AS CHAR)), 'null')), CONCAT('"role":', IFNULL(JSON_QUOTE(CAST(NEW.`role` AS CHAR)), 'null')), CONCAT('"region_code":', IFNULL(JSON_QUOTE(CAST(NEW.`region_code` AS CHAR)), 'null')), CONCAT('"province_code":', IFNULL(JSON_QUOTE(CAST(NEW.`province_code` AS CHAR)), 'null')), CONCAT('"city_code":', IFNULL(JSON_QUOTE(CAST(NEW.`city_code` AS CHAR)), 'null')), CONCAT('"barangay_code":', IFNULL(JSON_QUOTE(CAST(NEW.`barangay_code` AS CHAR)), 'null')), CONCAT('"street_address":', IFNULL(JSON_QUOTE(CAST(NEW.`street_address` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null')), CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(NEW.`address` AS CHAR)), 'null')), CONCAT('"is_verified":', IFNULL(JSON_QUOTE(CAST(NEW.`is_verified` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(NEW.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null')), CONCAT('"credentials_deleted_at":', IFNULL(JSON_QUOTE(CAST(NEW.`credentials_deleted_at` AS CHAR)), 'null')), CONCAT('"credentials_deleted_by":', IFNULL(JSON_QUOTE(CAST(NEW.`credentials_deleted_by` AS CHAR)), 'null'))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_users_au` AFTER UPDATE ON `users` FOR EACH ROW
        BEGIN
            DECLARE v_action VARCHAR(20);
            IF NOT ((OLD.`id` <=> NEW.`id`) AND (OLD.`username` <=> NEW.`username`) AND (OLD.`password` <=> NEW.`password`) AND (OLD.`email` <=> NEW.`email`) AND (OLD.`first_name` <=> NEW.`first_name`) AND (OLD.`last_name` <=> NEW.`last_name`) AND (OLD.`contact_number` <=> NEW.`contact_number`) AND (OLD.`profile_photo` <=> NEW.`profile_photo`) AND (OLD.`role` <=> NEW.`role`) AND (OLD.`region_code` <=> NEW.`region_code`) AND (OLD.`province_code` <=> NEW.`province_code`) AND (OLD.`city_code` <=> NEW.`city_code`) AND (OLD.`barangay_code` <=> NEW.`barangay_code`) AND (OLD.`street_address` <=> NEW.`street_address`) AND (OLD.`postal_code` <=> NEW.`postal_code`) AND (OLD.`address` <=> NEW.`address`) AND (OLD.`is_verified` <=> NEW.`is_verified`) AND (OLD.`status` <=> NEW.`status`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`credentials_deleted_at` <=> NEW.`credentials_deleted_at`) AND (OLD.`credentials_deleted_by` <=> NEW.`credentials_deleted_by`)) THEN
                SET v_action = CASE WHEN COALESCE(OLD.`is_archived`,0) = 0 AND COALESCE(NEW.`is_archived`,0) = 1 THEN 'ARCHIVE' WHEN COALESCE(OLD.`is_archived`,0) = 1 AND COALESCE(NEW.`is_archived`,0) = 0 THEN 'RESTORE' WHEN NOT (OLD.`status` <=> NEW.`status`) AND ((OLD.`id` <=> NEW.`id`) AND (OLD.`username` <=> NEW.`username`) AND (OLD.`password` <=> NEW.`password`) AND (OLD.`email` <=> NEW.`email`) AND (OLD.`first_name` <=> NEW.`first_name`) AND (OLD.`last_name` <=> NEW.`last_name`) AND (OLD.`contact_number` <=> NEW.`contact_number`) AND (OLD.`profile_photo` <=> NEW.`profile_photo`) AND (OLD.`role` <=> NEW.`role`) AND (OLD.`region_code` <=> NEW.`region_code`) AND (OLD.`province_code` <=> NEW.`province_code`) AND (OLD.`city_code` <=> NEW.`city_code`) AND (OLD.`barangay_code` <=> NEW.`barangay_code`) AND (OLD.`street_address` <=> NEW.`street_address`) AND (OLD.`postal_code` <=> NEW.`postal_code`) AND (OLD.`address` <=> NEW.`address`) AND (OLD.`is_verified` <=> NEW.`is_verified`) AND (OLD.`created_at` <=> NEW.`created_at`) AND (OLD.`is_archived` <=> NEW.`is_archived`) AND (OLD.`archived_at` <=> NEW.`archived_at`) AND (OLD.`archived_by` <=> NEW.`archived_by`) AND (OLD.`credentials_deleted_at` <=> NEW.`credentials_deleted_at`) AND (OLD.`credentials_deleted_by` <=> NEW.`credentials_deleted_by`)) THEN 'STATUS_CHANGE' ELSE 'UPDATE' END;
                INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
                    (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, v_action, 'Accounts', 'users', CAST(NEW.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', NEW.`username`), ''), 255),
                    CONCAT(CASE v_action WHEN 'ARCHIVE' THEN 'Archived' WHEN 'RESTORE' THEN 'Restored' WHEN 'STATUS_CHANGE' THEN 'Changed status of' ELSE 'Updated' END, ' user account #', NEW.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', NEW.`username`), ''), ')'), ''), IF(OLD.`status` <=> NEW.`status`, '', CONCAT(' - status: ', IFNULL(OLD.`status`,'none'), ' -> ', IFNULL(NEW.`status`,'none'))), IF(OLD.`password` <=> NEW.`password`, '', ' - password changed'), IF(OLD.`email` <=> NEW.`email`, '', ' - email changed')),
                    CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null'))), IF(OLD.`username` <=> NEW.`username`, NULL, CONCAT('"username":', IFNULL(JSON_QUOTE(CAST(OLD.`username` AS CHAR)), 'null'))), IF(OLD.`password` <=> NEW.`password`, NULL, CONCAT('"password":', '"[protected]"')), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', '"[protected]"')), IF(OLD.`first_name` <=> NEW.`first_name`, NULL, CONCAT('"first_name":', IFNULL(JSON_QUOTE(CAST(OLD.`first_name` AS CHAR)), 'null'))), IF(OLD.`last_name` <=> NEW.`last_name`, NULL, CONCAT('"last_name":', IFNULL(JSON_QUOTE(CAST(OLD.`last_name` AS CHAR)), 'null'))), IF(OLD.`contact_number` <=> NEW.`contact_number`, NULL, CONCAT('"contact_number":', IFNULL(JSON_QUOTE(CAST(OLD.`contact_number` AS CHAR)), 'null'))), IF(OLD.`profile_photo` <=> NEW.`profile_photo`, NULL, CONCAT('"profile_photo":', IFNULL(JSON_QUOTE(CAST(OLD.`profile_photo` AS CHAR)), 'null'))), IF(OLD.`role` <=> NEW.`role`, NULL, CONCAT('"role":', IFNULL(JSON_QUOTE(CAST(OLD.`role` AS CHAR)), 'null'))), IF(OLD.`region_code` <=> NEW.`region_code`, NULL, CONCAT('"region_code":', IFNULL(JSON_QUOTE(CAST(OLD.`region_code` AS CHAR)), 'null'))), IF(OLD.`province_code` <=> NEW.`province_code`, NULL, CONCAT('"province_code":', IFNULL(JSON_QUOTE(CAST(OLD.`province_code` AS CHAR)), 'null'))), IF(OLD.`city_code` <=> NEW.`city_code`, NULL, CONCAT('"city_code":', IFNULL(JSON_QUOTE(CAST(OLD.`city_code` AS CHAR)), 'null'))), IF(OLD.`barangay_code` <=> NEW.`barangay_code`, NULL, CONCAT('"barangay_code":', IFNULL(JSON_QUOTE(CAST(OLD.`barangay_code` AS CHAR)), 'null'))), IF(OLD.`street_address` <=> NEW.`street_address`, NULL, CONCAT('"street_address":', IFNULL(JSON_QUOTE(CAST(OLD.`street_address` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null'))), IF(OLD.`address` <=> NEW.`address`, NULL, CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(OLD.`address` AS CHAR)), 'null'))), IF(OLD.`is_verified` <=> NEW.`is_verified`, NULL, CONCAT('"is_verified":', IFNULL(JSON_QUOTE(CAST(OLD.`is_verified` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null'))), IF(OLD.`credentials_deleted_at` <=> NEW.`credentials_deleted_at`, NULL, CONCAT('"credentials_deleted_at":', IFNULL(JSON_QUOTE(CAST(OLD.`credentials_deleted_at` AS CHAR)), 'null'))), IF(OLD.`credentials_deleted_by` <=> NEW.`credentials_deleted_by`, NULL, CONCAT('"credentials_deleted_by":', IFNULL(JSON_QUOTE(CAST(OLD.`credentials_deleted_by` AS CHAR)), 'null')))), '}'), CONCAT('{', CONCAT_WS(',', IF(OLD.`id` <=> NEW.`id`, NULL, CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(NEW.`id` AS CHAR)), 'null'))), IF(OLD.`username` <=> NEW.`username`, NULL, CONCAT('"username":', IFNULL(JSON_QUOTE(CAST(NEW.`username` AS CHAR)), 'null'))), IF(OLD.`password` <=> NEW.`password`, NULL, CONCAT('"password":', '"[changed]"')), IF(OLD.`email` <=> NEW.`email`, NULL, CONCAT('"email":', '"[changed]"')), IF(OLD.`first_name` <=> NEW.`first_name`, NULL, CONCAT('"first_name":', IFNULL(JSON_QUOTE(CAST(NEW.`first_name` AS CHAR)), 'null'))), IF(OLD.`last_name` <=> NEW.`last_name`, NULL, CONCAT('"last_name":', IFNULL(JSON_QUOTE(CAST(NEW.`last_name` AS CHAR)), 'null'))), IF(OLD.`contact_number` <=> NEW.`contact_number`, NULL, CONCAT('"contact_number":', IFNULL(JSON_QUOTE(CAST(NEW.`contact_number` AS CHAR)), 'null'))), IF(OLD.`profile_photo` <=> NEW.`profile_photo`, NULL, CONCAT('"profile_photo":', IFNULL(JSON_QUOTE(CAST(NEW.`profile_photo` AS CHAR)), 'null'))), IF(OLD.`role` <=> NEW.`role`, NULL, CONCAT('"role":', IFNULL(JSON_QUOTE(CAST(NEW.`role` AS CHAR)), 'null'))), IF(OLD.`region_code` <=> NEW.`region_code`, NULL, CONCAT('"region_code":', IFNULL(JSON_QUOTE(CAST(NEW.`region_code` AS CHAR)), 'null'))), IF(OLD.`province_code` <=> NEW.`province_code`, NULL, CONCAT('"province_code":', IFNULL(JSON_QUOTE(CAST(NEW.`province_code` AS CHAR)), 'null'))), IF(OLD.`city_code` <=> NEW.`city_code`, NULL, CONCAT('"city_code":', IFNULL(JSON_QUOTE(CAST(NEW.`city_code` AS CHAR)), 'null'))), IF(OLD.`barangay_code` <=> NEW.`barangay_code`, NULL, CONCAT('"barangay_code":', IFNULL(JSON_QUOTE(CAST(NEW.`barangay_code` AS CHAR)), 'null'))), IF(OLD.`street_address` <=> NEW.`street_address`, NULL, CONCAT('"street_address":', IFNULL(JSON_QUOTE(CAST(NEW.`street_address` AS CHAR)), 'null'))), IF(OLD.`postal_code` <=> NEW.`postal_code`, NULL, CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(NEW.`postal_code` AS CHAR)), 'null'))), IF(OLD.`address` <=> NEW.`address`, NULL, CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(NEW.`address` AS CHAR)), 'null'))), IF(OLD.`is_verified` <=> NEW.`is_verified`, NULL, CONCAT('"is_verified":', IFNULL(JSON_QUOTE(CAST(NEW.`is_verified` AS CHAR)), 'null'))), IF(OLD.`status` <=> NEW.`status`, NULL, CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(NEW.`status` AS CHAR)), 'null'))), IF(OLD.`created_at` <=> NEW.`created_at`, NULL, CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(NEW.`created_at` AS CHAR)), 'null'))), IF(OLD.`is_archived` <=> NEW.`is_archived`, NULL, CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(NEW.`is_archived` AS CHAR)), 'null'))), IF(OLD.`archived_at` <=> NEW.`archived_at`, NULL, CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_at` AS CHAR)), 'null'))), IF(OLD.`archived_by` <=> NEW.`archived_by`, NULL, CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(NEW.`archived_by` AS CHAR)), 'null'))), IF(OLD.`credentials_deleted_at` <=> NEW.`credentials_deleted_at`, NULL, CONCAT('"credentials_deleted_at":', IFNULL(JSON_QUOTE(CAST(NEW.`credentials_deleted_at` AS CHAR)), 'null'))), IF(OLD.`credentials_deleted_by` <=> NEW.`credentials_deleted_by`, NULL, CONCAT('"credentials_deleted_by":', IFNULL(JSON_QUOTE(CAST(NEW.`credentials_deleted_by` AS CHAR)), 'null')))), '}'), @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db'));
            END IF;
        END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 */ /*!50003 TRIGGER `trg_jq_users_ad` AFTER DELETE ON `users` FOR EACH ROW
        INSERT INTO `activity_logs` (user_id, username, user_role, action, module, record_table, record_id, record_label, description, old_values, new_values, ip_address, user_agent, page, source) VALUES (
            (SELECT u.`id` FROM `users` u WHERE u.`id` = @app_user_id LIMIT 1), LEFT(COALESCE(@app_username, SUBSTRING_INDEX(USER(), '@', 1)), 100), @app_role, 'DELETE', 'Accounts', 'users', CAST(OLD.`id` AS CHAR), LEFT(NULLIF(CONCAT_WS(' - ', OLD.`username`), ''), 255),
            CONCAT('Deleted user account #', OLD.`id`, IFNULL(CONCAT(' (', NULLIF(CONCAT_WS(' - ', OLD.`username`), ''), ')'), '')),
            CONCAT('{', CONCAT_WS(',', CONCAT('"id":', IFNULL(JSON_QUOTE(CAST(OLD.`id` AS CHAR)), 'null')), CONCAT('"username":', IFNULL(JSON_QUOTE(CAST(OLD.`username` AS CHAR)), 'null')), CONCAT('"password":', '"[protected]"'), CONCAT('"email":', '"[protected]"'), CONCAT('"first_name":', IFNULL(JSON_QUOTE(CAST(OLD.`first_name` AS CHAR)), 'null')), CONCAT('"last_name":', IFNULL(JSON_QUOTE(CAST(OLD.`last_name` AS CHAR)), 'null')), CONCAT('"contact_number":', IFNULL(JSON_QUOTE(CAST(OLD.`contact_number` AS CHAR)), 'null')), CONCAT('"profile_photo":', IFNULL(JSON_QUOTE(CAST(OLD.`profile_photo` AS CHAR)), 'null')), CONCAT('"role":', IFNULL(JSON_QUOTE(CAST(OLD.`role` AS CHAR)), 'null')), CONCAT('"region_code":', IFNULL(JSON_QUOTE(CAST(OLD.`region_code` AS CHAR)), 'null')), CONCAT('"province_code":', IFNULL(JSON_QUOTE(CAST(OLD.`province_code` AS CHAR)), 'null')), CONCAT('"city_code":', IFNULL(JSON_QUOTE(CAST(OLD.`city_code` AS CHAR)), 'null')), CONCAT('"barangay_code":', IFNULL(JSON_QUOTE(CAST(OLD.`barangay_code` AS CHAR)), 'null')), CONCAT('"street_address":', IFNULL(JSON_QUOTE(CAST(OLD.`street_address` AS CHAR)), 'null')), CONCAT('"postal_code":', IFNULL(JSON_QUOTE(CAST(OLD.`postal_code` AS CHAR)), 'null')), CONCAT('"address":', IFNULL(JSON_QUOTE(CAST(OLD.`address` AS CHAR)), 'null')), CONCAT('"is_verified":', IFNULL(JSON_QUOTE(CAST(OLD.`is_verified` AS CHAR)), 'null')), CONCAT('"status":', IFNULL(JSON_QUOTE(CAST(OLD.`status` AS CHAR)), 'null')), CONCAT('"created_at":', IFNULL(JSON_QUOTE(CAST(OLD.`created_at` AS CHAR)), 'null')), CONCAT('"updated_at":', IFNULL(JSON_QUOTE(CAST(OLD.`updated_at` AS CHAR)), 'null')), CONCAT('"is_archived":', IFNULL(JSON_QUOTE(CAST(OLD.`is_archived` AS CHAR)), 'null')), CONCAT('"archived_at":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_at` AS CHAR)), 'null')), CONCAT('"archived_by":', IFNULL(JSON_QUOTE(CAST(OLD.`archived_by` AS CHAR)), 'null')), CONCAT('"credentials_deleted_at":', IFNULL(JSON_QUOTE(CAST(OLD.`credentials_deleted_at` AS CHAR)), 'null')), CONCAT('"credentials_deleted_by":', IFNULL(JSON_QUOTE(CAST(OLD.`credentials_deleted_by` AS CHAR)), 'null'))), '}'), NULL, @app_ip, @app_ua, @app_page, IF(COALESCE(@app_context, 0) = 1, 'app', 'direct_db')) */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;

--
-- Dumping routines for database 'janets_catering'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
