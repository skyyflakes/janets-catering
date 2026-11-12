-- =====================================================
-- JANET'S QUALITY CATERING SERVICES DATABASE
-- Updated with Philippine Address System
-- Based on: https://github.com/wilfredpine/philippine-address-selector
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";

-- Create Database
CREATE DATABASE IF NOT EXISTS janets_catering;
USE janets_catering;

-- =====================================================
-- DROP EXISTING TABLES (if exists) - In correct order
-- =====================================================
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `sms_verifications`;
DROP TABLE IF EXISTS `event_inventory_usage`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `reports`;
DROP TABLE IF EXISTS `activity_logs`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `ph_barangays`;
DROP TABLE IF EXISTS `ph_cities`;
DROP TABLE IF EXISTS `ph_provinces`;
DROP TABLE IF EXISTS `ph_regions`;

-- =====================================================
-- PHILIPPINE ADDRESS TABLES
-- Based on: https://github.com/wilfredpine/philippine-address-selector
-- NOTE: Address data is now stored in JSON files under static/ph-json/
--       (region.json, province.json, city.json, barangay.json)
--       The ph_* tables below are kept for reference/backup only.
--       The application no longer queries these tables directly.
-- =====================================================

-- =====================================================
-- USERS TABLE
-- =====================================================
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100),
  `first_name` VARCHAR(100),
  `last_name` VARCHAR(100),
  `contact_number` VARCHAR(20),
  `phone_verified` TINYINT(1) DEFAULT 0,
  `profile_photo` VARCHAR(255),
  `role` ENUM('ADMIN', 'OWNER', 'STAFF') DEFAULT 'ADMIN',
  `region_code` VARCHAR(5) DEFAULT NULL,
  `province_code` VARCHAR(10) DEFAULT NULL,
  `city_code` VARCHAR(10) DEFAULT NULL,
  `barangay_code` VARCHAR(10) DEFAULT NULL,
  `street_address` TEXT,
  `address` TEXT,
  `is_verified` BOOLEAN DEFAULT FALSE,
  `status` ENUM('active','inactive','pending') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `role` (`role`),
  KEY `region_code` (`region_code`),
  KEY `province_code` (`province_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Users
--   ADMIN  -> username: admin  (or veranosean91@gmail.com)       password: (kept privately by the Owner)
--   OWNER  -> username: owner  (or naesleirgabonarev@gmail.com)   password: (kept privately by the Owner)
-- The 6-digit login code is emailed to the account's email. Change these passwords after your first login.
INSERT INTO `users` (`username`, `password`, `email`, `first_name`, `last_name`, `contact_number`, `phone_verified`, `role`, `status`, `address`) VALUES
('admin', '$2y$12$ZyOT/DHemk.deJtyqSSxfezYGzRoQrT5gWxUvnc74xCR.Qv.A/3/S', 'veranosean91@gmail.com', 'Admin', 'User', '09171234567', 1, 'ADMIN', 'active', 'Manila, Philippines'),
('owner', '$2y$12$zBjNAyKJMLjKvn9GOlEy/O1Zoq8MYVtAq7HdKWKlIpSMssvkg6sXK', 'naesleirgabonarev@gmail.com', 'Janet', 'Santos', '09181234567', 1, 'OWNER', 'active', 'Bulacan, Philippines');

-- =====================================================
-- SMS VERIFICATION TABLE
-- =====================================================
CREATE TABLE `sms_verifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `phone_number` VARCHAR(20) NOT NULL,
  `verification_code` VARCHAR(10) NOT NULL,
  `is_verified` BOOLEAN DEFAULT FALSE,
  `attempts` INT DEFAULT 0,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_phone` (`phone_number`),
  INDEX `idx_code` (`verification_code`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- EMAIL VERIFICATION TABLE
-- =====================================================
CREATE TABLE `email_verifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `email` VARCHAR(100) NOT NULL,
  `verification_code` VARCHAR(10) NOT NULL,
  `is_verified` BOOLEAN DEFAULT FALSE,
  `attempts` INT DEFAULT 0,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_email` (`email`),
  INDEX `idx_code` (`verification_code`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- NOTIFICATIONS TABLE (For Owner Reports)
-- =====================================================
CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `type` ENUM('report', 'event', 'inventory', 'system', 'feedback') NOT NULL DEFAULT 'system',
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `reference_id` INT DEFAULT NULL,
  `reference_type` VARCHAR(50) DEFAULT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `user_id` (`user_id`),
  KEY `is_read` (`is_read`),
  KEY `type` (`type`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- CATEGORIES TABLE
-- =====================================================
CREATE TABLE `categories` (
  `category_id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_name` VARCHAR(100) UNIQUE NOT NULL,
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Categories
INSERT INTO `categories` (`category_name`, `description`) VALUES 
('Silverware', 'Spoons, forks, knives and other metal utensils'),
('Dinnerware', 'Plates, bowls, and serving dishes'),
('Glassware', 'Drinking glasses, goblets, and glass serving items'),
('Linens', 'Tablecloths, napkins, and fabric items'),
('Equipment', 'Chafing dishes, serving equipment, and tools');

-- =====================================================
-- INVENTORY TABLE
-- =====================================================
CREATE TABLE `inventory` (
  `item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `beginning_qty` INT DEFAULT 0,
  `previous_qty` INT DEFAULT 0,
  `extra_qty` INT DEFAULT 0,
  `ending_qty` INT DEFAULT 0,
  `per_pax_usage` DECIMAL(10,4) DEFAULT 1.0000 COMMENT 'How many units used per guest/pax',
  `unit` VARCHAR(50) DEFAULT 'pcs',
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Inventory Items
INSERT INTO `inventory` (`category_id`, `item_name`, `beginning_qty`, `previous_qty`, `extra_qty`, `ending_qty`, `per_pax_usage`, `unit`) VALUES 
-- Silverware
(1, 'Spoon', 100, 95, 10, 105, 1.0000, 'pcs'),
(1, 'Fork', 100, 98, 5, 103, 1.0000, 'pcs'),
(1, 'Teaspoon', 50, 48, 0, 48, 1.0000, 'pcs'),
(1, 'Dinner Knife', 80, 75, 10, 85, 1.0000, 'pcs'),
(1, 'Serving Spoon', 30, 28, 5, 33, 0.1000, 'pcs'),
-- Dinnerware
(2, 'Dinner Plate', 150, 145, 20, 165, 1.0000, 'pcs'),
(2, 'Soup Bowl', 100, 95, 10, 105, 1.0000, 'pcs'),
(2, 'Salad Plate', 100, 98, 5, 103, 1.0000, 'pcs'),
(2, 'Dessert Plate', 80, 78, 0, 78, 1.0000, 'pcs'),
-- Glassware
(3, 'Wine Glass', 60, 55, 10, 65, 1.0000, 'pcs'),
(3, 'Water Goblet', 120, 115, 15, 130, 1.0000, 'pcs'),
(3, 'Juice Glass', 100, 95, 10, 105, 1.0000, 'pcs'),
(3, 'Champagne Flute', 40, 38, 5, 43, 1.0000, 'pcs'),
-- Linens
(4, 'Table Cloth (White)', 40, 38, 5, 43, 0.1000, 'pcs'),
(4, 'Table Cloth (Pink)', 30, 28, 5, 33, 0.1000, 'pcs'),
(4, 'Table Napkin (White)', 200, 190, 20, 210, 1.0000, 'pcs'),
(4, 'Table Napkin (Pink)', 150, 145, 10, 155, 1.0000, 'pcs'),
(4, 'Seat Cover', 150, 145, 10, 155, 1.0000, 'pcs'),
-- Equipment
(5, 'Chafing Dish', 20, 18, 2, 20, 0.0500, 'pcs'),
(5, 'Food Warmer', 15, 14, 1, 15, 0.0300, 'pcs'),
(5, 'Serving Tray', 30, 28, 2, 30, 0.1000, 'pcs');

-- =====================================================
-- EVENTS TABLE
-- =====================================================
CREATE TABLE `events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_name` VARCHAR(255) NOT NULL,
  `event_date` DATE NOT NULL,
  `event_time` TIME DEFAULT '12:00:00',
  `fullname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100),
  `contact` VARCHAR(20),
  -- Philippine Standard Address (PSGC)
  `region_code` VARCHAR(5) DEFAULT NULL,
  `province_code` VARCHAR(10) DEFAULT NULL,
  `city_code` VARCHAR(10) DEFAULT NULL,
  `barangay_code` VARCHAR(10) DEFAULT NULL,
  `street_address` TEXT,
  -- Legacy fields
  `customer_address` VARCHAR(255),
  `province` VARCHAR(100),
  `city` VARCHAR(100),
  `barangay` VARCHAR(100),
  `venue_address` TEXT,
  `pax` INT DEFAULT 50,
  `backdrop` VARCHAR(100),
  `notes` TEXT,
  `status` ENUM('Pending', 'Confirmed', 'Completed', 'Cancelled') DEFAULT 'Pending',
  `inventory_deducted` BOOLEAN DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_event_date` (`event_date`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- EVENT INVENTORY USAGE TABLE
-- =====================================================
CREATE TABLE `event_inventory_usage` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `event_id` INT NOT NULL,
  `item_id` INT NOT NULL,
  `quantity_used` INT DEFAULT 0,
  `pax_count` INT DEFAULT 0,
  `usage_notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`item_id`) REFERENCES `inventory`(`item_id`) ON DELETE CASCADE,
  INDEX `idx_event` (`event_id`),
  INDEX `idx_item` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- REPORTS TABLE
-- =====================================================
CREATE TABLE `reports` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` TEXT,
  `report_type` ENUM('inventory', 'feedback', 'complaint', 'suggestion', 'inquiry', 'general') DEFAULT 'general',
  `created_by` VARCHAR(100),
  `status` ENUM('Generated', 'Draft', 'Archived', 'Pending', 'Reviewed', 'Resolved') DEFAULT 'Generated',
  `admin_response` TEXT DEFAULT NULL,
  `responded_by` INT DEFAULT NULL,
  `responded_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Reports (These will trigger notifications to owner)
INSERT INTO `reports` (`title`, `content`, `report_type`, `created_by`, `status`) VALUES 
('Monthly Inventory Audit - April', 'All silverware accounted for. 2 glasses broken during Cruz event. Recommended to purchase additional wine glasses.', 'inventory', 'admin', 'Generated'),
('Quarterly Sales Summary Q1 2026', 'Total of 15 events handled for Q1 2026. Revenue exceeded target by 12%. Top performing category: Wedding packages.', 'general', 'admin', 'Generated'),
('Customer Feedback: Cruz Wedding', 'The client expressed satisfaction with the service. Food was excellent and staff was professional.', 'feedback', 'admin', 'Pending');

-- =====================================================
-- ACTIVITY LOG TABLE
-- =====================================================
CREATE TABLE `activity_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT,
  `action` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `ip_address` VARCHAR(45),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Events
INSERT INTO `events` (`event_name`, `event_date`, `fullname`, `email`, `contact`, `province`, `city`, `barangay`, `venue_address`, `pax`, `backdrop`, `status`, `region_code`, `province_code`, `city_code`) VALUES 
('Cruz Wedding Reception', '2026-05-20', 'Juan Cruz', 'juan@email.com', '09171234567', 'Bulacan', 'Malolos City', 'Guinhawa', 'Blue Gardens Event Place, Malolos Bulacan', 150, 'photo1.jpg', 'Confirmed', '03', '0314', '031411'),
('Santillan 18th Birthday', '2026-06-15', 'Maria Santillan', 'maria@email.com', '09181234567', 'NCR', 'Quezon City', 'Batasan Hills', 'Casa Milagros, Batasan Hills QC', 100, 'photo5.jpg', 'Pending', '13', '1374', '137404'),
('TechCorp Annual Seminar', '2026-07-02', 'Robert Fox', 'robert@techcorp.com', '09191234567', 'NCR', 'Makati City', 'Poblacion', 'Grand Hotel Makati', 75, 'photo10.jpg', 'Confirmed', '13', '1376', '137602');

-- Sample Notifications for Owner (Report notifications)
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `reference_id`, `reference_type`, `is_read`) VALUES
(2, 'report', 'New Report Generated', 'Monthly Inventory Audit - April has been generated.', 1, 'reports', 0),
(2, 'report', 'New Report Generated', 'Quarterly Sales Summary Q1 2026 has been generated.', 2, 'reports', 0),
(2, 'feedback', 'Customer Feedback Received', 'New feedback from Cruz Wedding has been submitted.', 3, 'reports', 0);

-- Sample Notifications for Admin (Food Stocks notifications)
INSERT INTO `notifications` (`user_id`, `type`, `title`, `message`, `reference_id`, `reference_type`, `is_read`) VALUES
(1, 'inventory', 'Low Stock Alert', 'Ground Beef is running low. Current stock: 15 kg.', 3, 'foodstocks', 0),
(1, 'inventory', 'Stock Update', 'New delivery from CION''S MEATSHOP received.', 1, 'foodstocks', 0),
(1, 'system', 'Welcome Admin', 'You have full access to Food Stocks Inventory management.', NULL, NULL, 0);

-- =====================================================
-- FOODSTOCKS CATEGORIES TABLE (Admin Only)
-- =====================================================
CREATE TABLE `foodstocks_categories` (
  `category_id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_name` VARCHAR(100) UNIQUE NOT NULL,
  `description` VARCHAR(255),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Foodstocks Categories
INSERT INTO `foodstocks_categories` (`category_name`, `description`) VALUES 
('Meat', 'Beef, pork, chicken, and other meat products'),
('Vegetables', 'Fresh vegetables and greens'),
('Fruits', 'Fresh fruits'),
('Dairy', 'Milk, cheese, butter, and dairy products'),
('Dry Goods', 'Rice, pasta, flour, and dry ingredients'),
('Condiments', 'Sauces, seasonings, and condiments'),
('Beverages', 'Drinks, juices, and beverage items');

-- =====================================================
-- SUPPLIERS TABLE (For Foodstocks)
-- =====================================================
CREATE TABLE `suppliers` (
  `supplier_id` INT AUTO_INCREMENT PRIMARY KEY,
  `supplier_name` VARCHAR(255) NOT NULL,
  `contact_person` VARCHAR(100),
  `contact_number` VARCHAR(20),
  `email` VARCHAR(100),
  `address` TEXT,
  `region_code` VARCHAR(5) DEFAULT NULL,
  `province_code` VARCHAR(10) DEFAULT NULL,
  `city_code` VARCHAR(10) DEFAULT NULL,
  `barangay_code` VARCHAR(10) DEFAULT NULL,
  `street_address` TEXT,
  `is_active` BOOLEAN DEFAULT TRUE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_supplier_name` (`supplier_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Suppliers - CION's MEATSHOP for meat
INSERT INTO `suppliers` (`supplier_name`, `contact_person`, `contact_number`, `email`, `address`) VALUES 
("CION'S MEATSHOP", 'Cion Santos', '09171234567', 'cions.meatshop@email.com', 'Malolos Public Market, Bulacan'),
('Fresh Greens Farm', 'Maria Cruz', '09181234567', 'freshgreens@email.com', 'San Miguel, Bulacan'),
('Metro Grocery Supplies', 'Juan Reyes', '09191234567', 'metro.grocery@email.com', 'Caloocan City');

-- =====================================================
-- FOODSTOCKS TABLE (Admin Managed)
-- =====================================================
CREATE TABLE `foodstocks` (
  `item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `category_id` INT NOT NULL,
  `supplier_id` INT DEFAULT NULL,
  `item_name` VARCHAR(255) NOT NULL,
  `quantity` DECIMAL(10,2) DEFAULT 0,
  `unit` VARCHAR(50) DEFAULT 'kg',
  `unit_price` DECIMAL(10,2) DEFAULT 0,
  `reorder_level` INT DEFAULT 10,
  `expiry_date` DATE DEFAULT NULL,
  `is_active` BOOLEAN DEFAULT TRUE,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `foodstocks_categories`(`category_id`) ON DELETE CASCADE,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`supplier_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Foodstocks Items
INSERT INTO `foodstocks` (`category_id`, `supplier_id`, `item_name`, `quantity`, `unit`, `unit_price`, `reorder_level`, `notes`) VALUES 
-- Meat (from CION'S MEATSHOP - supplier_id = 1)
(1, 1, 'Pork Belly', 25.5, 'kg', 280.00, 10, 'Fresh from CION''S MEATSHOP'),
(1, 1, 'Chicken Whole', 30.0, 'kg', 180.00, 15, 'Fresh from CION''S MEATSHOP'),
(1, 1, 'Ground Beef', 15.0, 'kg', 350.00, 8, 'Fresh from CION''S MEATSHOP'),
(1, 1, 'Pork Liempo', 20.0, 'kg', 300.00, 10, 'Fresh from CION''S MEATSHOP'),
(1, 1, 'Beef Brisket', 12.0, 'kg', 380.00, 5, 'Fresh from CION''S MEATSHOP'),
-- Vegetables (from Fresh Greens Farm - supplier_id = 2)
(2, 2, 'Cabbage', 15.0, 'kg', 45.00, 10, 'Fresh vegetables'),
(2, 2, 'Carrots', 10.0, 'kg', 60.00, 8, 'Fresh vegetables'),
(2, 2, 'Onions', 20.0, 'kg', 80.00, 10, 'Fresh vegetables'),
(2, 2, 'Tomatoes', 12.0, 'kg', 70.00, 8, 'Fresh vegetables'),
-- Dry Goods (from Metro Grocery - supplier_id = 3)
(5, 3, 'Rice (Premium)', 100.0, 'kg', 55.00, 50, 'Premium quality rice'),
(5, 3, 'Pasta (Spaghetti)', 20.0, 'packs', 35.00, 15, 'Standard packs'),
(5, 3, 'All-Purpose Flour', 25.0, 'kg', 45.00, 10, 'For baking'),
-- Condiments
(6, 3, 'Soy Sauce', 15.0, 'liters', 40.00, 10, 'Standard brand'),
(6, 3, 'Cooking Oil', 30.0, 'liters', 75.00, 15, 'Vegetable oil');

COMMIT;