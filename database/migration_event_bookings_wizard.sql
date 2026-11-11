-- ============================================================
-- Event Bookings Schema Update - 4-Step Wizard (FIXED)
-- Janet's Quality Catering System
-- ============================================================

-- Update event_bookings table with PSGC address fields and theme support
ALTER TABLE `event_bookings` 
ADD COLUMN IF NOT EXISTS `province` VARCHAR(150) AFTER `contact`,
ADD COLUMN IF NOT EXISTS `city` VARCHAR(150) AFTER `province`,
ADD COLUMN IF NOT EXISTS `barangay` VARCHAR(150) AFTER `city`,
ADD COLUMN IF NOT EXISTS `backdrop_id` INT AFTER `barangay`,
ADD COLUMN IF NOT EXISTS `backdrop_name` VARCHAR(255) AFTER `backdrop_id`,
ADD COLUMN IF NOT EXISTS `backdrop_path` VARCHAR(255) AFTER `backdrop_name`,
ADD COLUMN IF NOT EXISTS `event_theme` VARCHAR(100) AFTER `backdrop_path`,
ADD COLUMN IF NOT EXISTS `event_color` VARCHAR(7) AFTER `event_theme`;

-- Create Backdrops table if not exists (for Step 2)
CREATE TABLE IF NOT EXISTS `backdrops` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `backdrop_name` VARCHAR(255) NOT NULL,
  `event_type` VARCHAR(100),
  `image_path` VARCHAR(500),
  `description` TEXT,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_event_type` (`event_type`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample backdrops for different event types
INSERT IGNORE INTO `backdrops` (`backdrop_name`, `event_type`, `image_path`, `description`, `is_active`) VALUES
('Classic White', 'Wedding', 'static/images/Weddings/backdrop1.jpg', 'Elegant classic white backdrop for weddings', 1),
('Golden Elegance', 'Wedding', 'static/images/Weddings/backdrop2.jpg', 'Luxurious gold-themed wedding backdrop', 1),
('Floral Garden', 'Wedding', 'static/images/Weddings/backdrop3.jpg', 'Beautiful floral arrangement for romantic weddings', 1),
('Colorful Balloons', 'Birthday', 'static/images/Birthdays/backdrop1.jpg', 'Fun and colorful balloon backdrop', 1),
('Birthday Banner', 'Birthday', 'static/images/Birthdays/backdrop2.jpg', 'Festive birthday banner backdrop', 1),
('Cake Delight', 'Birthday', 'static/images/Birthdays/backdrop3.jpg', 'Cake-themed birthday celebration backdrop', 1),
('Corporate Blue', 'Corporate Event', 'static/images/Parties/backdrop1.jpg', 'Professional blue backdrop for corporate events', 1),
('Business Banner', 'Corporate Event', 'static/images/Parties/backdrop2.jpg', 'Business-themed event backdrop', 1),
('Gala Gold', 'Gala Dinner', 'static/images/Parties/backdrop3.jpg', 'Luxurious gold backdrop for gala dinners', 1),
('Elegant Silver', 'Gala Dinner', 'static/images/Parties/backdrop4.jpg', 'Sophisticated silver gala backdrop', 1),
('Graduation Glory', 'Graduation', 'static/images/Parties/backdrop5.jpg', 'Celebratory graduation themed backdrop', 1),
('Class of 2024', 'Graduation', 'static/images/Parties/backdrop6.jpg', 'Custom graduation class backdrop', 1),
('Seminar Professional', 'Seminar', 'static/images/Seminar/backdrop1.jpg', 'Professional seminar event backdrop', 1),
('Conference Blue', 'Seminar', 'static/images/Seminar/backdrop2.jpg', 'Business conference backdrop', 1);

COMMIT;
