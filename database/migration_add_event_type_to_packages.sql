-- ============================================================
-- Add event_type column to menu_packages table
-- Allows filtering packages by event type
-- ============================================================

ALTER TABLE `menu_packages` 
ADD COLUMN IF NOT EXISTS `event_type` VARCHAR(100) DEFAULT NULL AFTER `icon`,
ADD INDEX `idx_event_type` (`event_type`);

-- Update existing packages with event types
UPDATE `menu_packages` 
SET `event_type` = 'Wedding,Gala Dinner,Graduation'
WHERE `package_name` LIKE '%Classica%' OR `package_name` LIKE '%Royale%';

UPDATE `menu_packages` 
SET `event_type` = 'Wedding,Gala Dinner,Graduation,Corporate Event,Seminar'
WHERE `package_name` LIKE '%Catering Menu%';

COMMIT;
