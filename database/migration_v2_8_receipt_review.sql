-- Janet's Quality Catering: database update 2.8.0
-- Payment review statuses Receipt Submitted and Pending Verification, and a history of replaced receipts.
--
-- You normally do not need to run this. The system applies the same update by itself the next time a page opens.
-- Use it only to update a database by hand, after update 2.7.0, and take a backup first.
-- Safe to run more than once. Open the system once afterwards, so it rebuilds its activity log triggers.

DROP TRIGGER IF EXISTS `trg_jq_booking_payments_ai`;
DROP TRIGGER IF EXISTS `trg_jq_booking_payments_au`;
DROP TRIGGER IF EXISTS `trg_jq_booking_payments_ad`;

-- Receipt Submitted: uploaded, not yet opened. Pending Verification: opened by an Admin or the Owner.
ALTER TABLE `booking_payments` MODIFY `verification_status` ENUM('Awaiting Receipt','Pending Review','Receipt Submitted','Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt';
UPDATE `booking_payments` SET `verification_status` = 'Receipt Submitted' WHERE `verification_status` = 'Pending Review';
ALTER TABLE `booking_payments` MODIFY `verification_status` ENUM('Awaiting Receipt','Receipt Submitted','Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt';

-- Every receipt a replacement superseded, with the review it had.
CREATE TABLE IF NOT EXISTS `payment_receipt_history` (
  `history_id` INT(11) NOT NULL AUTO_INCREMENT,
  `payment_id` INT(11) NOT NULL,
  `receipt_path` VARCHAR(255) NOT NULL,
  `receipt_name` VARCHAR(255) NULL DEFAULT NULL,
  `receipt_mime` VARCHAR(100) NULL DEFAULT NULL,
  `verification_status` VARCHAR(30) NOT NULL COMMENT 'Status when it was replaced',
  `reviewed_by` INT(11) NULL DEFAULT NULL,
  `reviewed_at` DATETIME NULL DEFAULT NULL,
  `review_notes` VARCHAR(255) NULL DEFAULT NULL,
  `replaced_by` INT(11) NULL DEFAULT NULL,
  `replaced_at` DATETIME NOT NULL,
  PRIMARY KEY (`history_id`),
  KEY `idx_receipt_history_payment` (`payment_id`),
  CONSTRAINT `fk_receipt_history_payment` FOREIGN KEY (`payment_id`) REFERENCES `booking_payments` (`payment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_receipt_history_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_receipt_history_replacer` FOREIGN KEY (`replaced_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
