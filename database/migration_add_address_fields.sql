-- =====================================================
-- MIGRATION: Add Philippine Address Fields
-- Run this script to add PSGC address fields to suppliers table
-- =====================================================

-- Add address columns to suppliers table if they don't exist
ALTER TABLE `suppliers` 
ADD COLUMN IF NOT EXISTS `region_code` VARCHAR(5) DEFAULT NULL AFTER `address`,
ADD COLUMN IF NOT EXISTS `province_code` VARCHAR(10) DEFAULT NULL AFTER `region_code`,
ADD COLUMN IF NOT EXISTS `city_code` VARCHAR(10) DEFAULT NULL AFTER `province_code`,
ADD COLUMN IF NOT EXISTS `barangay_code` VARCHAR(10) DEFAULT NULL AFTER `city_code`,
ADD COLUMN IF NOT EXISTS `street_address` TEXT AFTER `barangay_code`;

-- Add indexes for faster lookups
CREATE INDEX IF NOT EXISTS `idx_supplier_region` ON `suppliers` (`region_code`);
CREATE INDEX IF NOT EXISTS `idx_supplier_province` ON `suppliers` (`province_code`);
