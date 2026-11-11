-- Janet's Quality Catering: database update 2.7.0
-- Proof of payment: each payment gets a review status, and who reviewed it and when.
--
-- You normally do not need to run this. The system applies the same update by itself the next time a page opens.
-- Use it only to update a database by hand, and take a backup first.
-- Safe to run more than once. Open the system once afterwards, so it rebuilds its activity log triggers.

DROP TRIGGER IF EXISTS `trg_jq_booking_payments_ai`;
DROP TRIGGER IF EXISTS `trg_jq_booking_payments_au`;
DROP TRIGGER IF EXISTS `trg_jq_booking_payments_ad`;

ALTER TABLE `booking_payments`
  ADD COLUMN IF NOT EXISTS `verification_status` ENUM('Awaiting Receipt','Pending Review','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt' AFTER `receipt_mime`,
  ADD COLUMN IF NOT EXISTS `reviewed_by` INT(11) NULL DEFAULT NULL AFTER `verification_status`,
  ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL DEFAULT NULL AFTER `reviewed_by`,
  ADD COLUMN IF NOT EXISTS `review_notes` VARCHAR(255) NULL DEFAULT NULL AFTER `reviewed_at`;

ALTER TABLE `booking_payments` ADD CONSTRAINT `fk_payment_reviewer` FOREIGN KEY IF NOT EXISTS (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

-- Receipts already on file wait for review. None is approved automatically.
UPDATE `booking_payments` SET `verification_status` = 'Pending Review' WHERE `receipt_path` IS NOT NULL AND `verification_status` = 'Awaiting Receipt';

-- A payment can only be Verified when a receipt is on file.
ALTER TABLE `booking_payments` ADD CONSTRAINT IF NOT EXISTS `chk_payment_verified_receipt` CHECK (`verification_status` <> 'Verified' OR `receipt_path` IS NOT NULL);
