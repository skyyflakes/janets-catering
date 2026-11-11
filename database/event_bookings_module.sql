-- =====================================================
-- EVENT BOOKINGS + FOOD MENUS MODULE
-- Janet's Quality Catering Services
-- Replaces: inventory, events, categories modules
-- =====================================================
-- HOW TO INSTALL:
--   1. Import your existing catering_db.sql first (if not already).
--   2. Then import THIS file to remove the old modules and add the new ones.
-- =====================================================

USE janets_catering;

SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------
-- 1) REMOVE OLD MODULES (inventory / events / categories)
-- -----------------------------------------------------
DROP TABLE IF EXISTS `event_inventory_usage`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `categories`;

-- -----------------------------------------------------
-- 2) NEW MODULE TABLES
-- -----------------------------------------------------
DROP TABLE IF EXISTS `booking_items`;
DROP TABLE IF EXISTS `event_bookings`;
DROP TABLE IF EXISTS `menu_items`;
DROP TABLE IF EXISTS `menu_bundles`;
DROP TABLE IF EXISTS `menu_packages`;

-- =====================================================
-- MENU PACKAGES (e.g. Silver Essential, Gold Premium, Platinum Elite)
-- =====================================================
CREATE TABLE `menu_packages` (
  `package_id` INT AUTO_INCREMENT PRIMARY KEY,
  `package_name` VARCHAR(150) NOT NULL,
  `tier` VARCHAR(50) DEFAULT 'Standard',
  `price_per_guest` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `description` TEXT,
  `icon` VARCHAR(50) DEFAULT 'bx-restaurant',
  `photo_path` VARCHAR(500) DEFAULT NULL,
  `sort_order` INT DEFAULT 0,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `menu_packages` (`package_name`, `tier`, `price_per_guest`, `description`, `icon`, `sort_order`) VALUES
('Silver Essential', 'Silver', 45.00, 'Basic buffet, standard AV', 'bx-restaurant', 1),
('Gold Premium', 'Gold', 75.00, '3-course plated, standard decor, open mocktails', 'bx-wine', 2),
('Platinum Elite', 'Platinum', 120.00, '4-course gourmet, full bar, custom decor, dedicated coordinator', 'bx-diamond', 3);

-- =====================================================
-- MENU ITEMS (customizable foods, grouped by course)
-- base_price = 0 means "Included"; upgrade_price = extra charge per guest
-- =====================================================
CREATE TABLE `menu_items` (
  `item_id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_name` VARCHAR(200) NOT NULL,
  `course` ENUM('Appetizers','Main Courses','Desserts','Beverages','Sides') NOT NULL DEFAULT 'Main Courses',
  `description` VARCHAR(255) DEFAULT NULL,
  `availability_note` VARCHAR(150) DEFAULT NULL COMMENT 'e.g. In Gold/Platinum, Included',
  `upgrade_price` DECIMAL(10,2) DEFAULT 0 COMMENT 'Extra per-guest cost when selected',
  `photo_path` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `menu_items` (`item_name`, `course`, `description`, `availability_note`, `upgrade_price`) VALUES
('Herb-Crusted Salmon', 'Main Courses', 'Oven-baked salmon with herb crust and seasonal greens', 'In Gold/Platinum', 0.00),
('Prime Ribeye Steak', 'Main Courses', 'Grilled prime ribeye, chimichurri sauce', '+18 upgrade for Gold', 18.00),
('Chicken Marsala', 'Main Courses', 'Pan-seared chicken in marsala mushroom sauce', 'Included', 0.00),
('Truffle Risotto', 'Main Courses', 'Creamy arborio rice with black truffle', 'Included', 0.00),
('Caprese Salad', 'Appetizers', 'Fresh mozzarella, tomatoes, basil, balsamic glaze', 'Included', 0.00),
('Stuffed Mushrooms', 'Appetizers', 'Baked mushrooms with herbed cream cheese', 'Included', 0.00),
('Tiramisu', 'Desserts', 'Classic Italian coffee-flavored dessert', 'Included', 0.00),
('Chocolate Lava Cake', 'Desserts', 'Warm molten chocolate cake with vanilla ice cream', '+6 upgrade', 6.00),
('Open Mocktail Bar', 'Beverages', 'Unlimited assorted mocktails', 'In Gold/Platinum', 0.00),
('Premium Wine Selection', 'Beverages', 'Curated red and white wines', '+25 upgrade', 25.00);

-- =====================================================
-- MENU BUNDLES (food packages / bundles for catering)
-- Flat priced add-on bundles (e.g. Dessert Station, Photo Booth)
-- =====================================================
CREATE TABLE `menu_bundles` (
  `bundle_id` INT AUTO_INCREMENT PRIMARY KEY,
  `bundle_name` VARCHAR(200) NOT NULL,
  `description` TEXT,
  `bundle_type` ENUM('Food Bundle','Add-on','Service') DEFAULT 'Food Bundle',
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `price_mode` ENUM('flat','per_guest') DEFAULT 'flat',
  `photo_path` VARCHAR(500) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `menu_bundles` (`bundle_name`, `description`, `bundle_type`, `price`, `price_mode`) VALUES
('Photo Booth Package', 'Unlimited prints, props, and digital gallery for 4 hours', 'Add-on', 500.00, 'flat'),
('Dessert Station', 'Assorted cakes, pastries, and chocolate fountain', 'Food Bundle', 8.00, 'per_guest'),
('Lechon Whole Roast', 'Whole roasted pig, Filipino style', 'Food Bundle', 350.00, 'flat'),
('Live Pasta Station', 'Chef-attended pasta bar with multiple sauces', 'Service', 12.00, 'per_guest');

-- =====================================================
-- EVENT BOOKINGS
-- =====================================================
CREATE TABLE `event_bookings` (
  `booking_id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_ref` VARCHAR(30) UNIQUE NOT NULL,
  `event_name` VARCHAR(255) NOT NULL,
  `event_type` VARCHAR(100) DEFAULT 'Gala Dinner',
  `event_date` DATE NOT NULL,
  `event_time` TIME DEFAULT '12:00:00',
  `fullname` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100),
  `contact` VARCHAR(20),
  `venue_address` TEXT,
  `headcount` INT NOT NULL DEFAULT 100,
  `package_id` INT DEFAULT NULL,
  `package_name` VARCHAR(150) DEFAULT NULL,
  `package_price` DECIMAL(10,2) DEFAULT 0,
  `base_total` DECIMAL(12,2) DEFAULT 0,
  `menu_upgrade_total` DECIMAL(12,2) DEFAULT 0,
  `addon_total` DECIMAL(12,2) DEFAULT 0,
  `subtotal` DECIMAL(12,2) DEFAULT 0,
  `service_tax_rate` DECIMAL(5,2) DEFAULT 12.00,
  `service_tax` DECIMAL(12,2) DEFAULT 0,
  `grand_total` DECIMAL(12,2) DEFAULT 0,
  `notes` TEXT,
  `status` ENUM('Pending','Confirmed','Completed','Cancelled') DEFAULT 'Pending',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_event_date` (`event_date`),
  INDEX `idx_status` (`status`),
  CONSTRAINT `fk_booking_package` FOREIGN KEY (`package_id`) REFERENCES `menu_packages`(`package_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- BOOKING ITEMS (selected menu items / bundles per booking)
-- =====================================================
CREATE TABLE `booking_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT NOT NULL,
  `ref_type` ENUM('item','bundle') NOT NULL DEFAULT 'item',
  `ref_id` INT NOT NULL,
  `item_name` VARCHAR(200) NOT NULL,
  `unit_price` DECIMAL(10,2) DEFAULT 0,
  `price_mode` ENUM('flat','per_guest') DEFAULT 'per_guest',
  `line_total` DECIMAL(12,2) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_bi_booking` FOREIGN KEY (`booking_id`) REFERENCES `event_bookings`(`booking_id`) ON DELETE CASCADE,
  INDEX `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample booking
INSERT INTO `event_bookings`
(`booking_ref`, `event_name`, `event_type`, `event_date`, `fullname`, `email`, `contact`, `venue_address`, `headcount`, `package_id`, `package_name`, `package_price`, `base_total`, `menu_upgrade_total`, `addon_total`, `subtotal`, `service_tax_rate`, `service_tax`, `grand_total`, `status`)
VALUES
('BKG-1001', 'Cruz Gala Dinner', 'Gala Dinner', '2026-10-12', 'Juan Cruz', 'juan@email.com', '09171234567', 'Blue Gardens Event Place, Malolos Bulacan', 100, 2, 'Gold Premium', 75.00, 7500.00, 1800.00, 500.00, 9800.00, 12.00, 1176.00, 10976.00, 'Confirmed');

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;
