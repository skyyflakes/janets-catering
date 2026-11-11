-- =====================================================
-- CLIENT SURVEYS TABLE
-- For Janet's Quality Catering Services
-- Admin: Full CRUD + Photo Upload
-- Owner: View Only
-- =====================================================

CREATE TABLE IF NOT EXISTS `client_surveys` (
  `survey_id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_name` VARCHAR(255) NOT NULL,
  `event_name` VARCHAR(255),
  `event_date` DATE,
  `rating` TINYINT(1) NOT NULL DEFAULT 5 COMMENT 'Star rating 1-5',
  `feedback` TEXT,
  `photo_path` VARCHAR(500) DEFAULT NULL COMMENT 'Path to uploaded photo',
  `is_featured` TINYINT(1) DEFAULT 0 COMMENT 'Featured testimonial',
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rating` (`rating`),
  INDEX `idx_featured` (`is_featured`),
  INDEX `idx_created_at` (`created_at`),
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample Client Surveys
INSERT INTO `client_surveys` (`client_name`, `event_name`, `event_date`, `rating`, `feedback`, `is_featured`, `created_by`) VALUES
('Maria Santos', 'Santos Wedding Reception', '2026-03-15', 5, 'Absolutely amazing service! The food was delicious and the staff was very professional. Highly recommended!', 1, 1),
('Juan Cruz', 'Cruz Family Reunion', '2026-02-20', 5, 'Janet''s Catering made our family reunion unforgettable. Everyone loved the food!', 1, 1),
('Ana Reyes', '18th Birthday Debut', '2026-01-10', 4, 'Great service and beautiful presentation. The team was very accommodating to our requests.', 0, 1),
('Roberto Garcia', 'Corporate Seminar', '2026-04-05', 5, 'Professional service from start to finish. Perfect for our corporate event.', 1, 1),
('Lisa Mendoza', 'Golden Wedding Anniversary', '2025-12-25', 5, 'Thank you Janet''s Catering for making our 50th anniversary celebration so special!', 0, 1);
