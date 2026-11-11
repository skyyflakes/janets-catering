<?php
/**
 * Database update 2.8.0 - Janet's Quality Catering System
 *   - Payment review statuses: Awaiting Receipt, Receipt Submitted, Pending Verification, Verified, Rejected.
 *     Receipt Submitted means uploaded and not yet opened; Pending Verification means an Admin or the Owner opened it.
 *   - payment_receipt_history keeps every receipt that a replacement superseded, with its review.
 */

/** Shared with database/migration_v2_8_receipt_review.sql. */
function jq_v28_statements(): array
{
    return [
        "ALTER TABLE `booking_payments` MODIFY `verification_status` ENUM('Awaiting Receipt','Pending Review','Receipt Submitted','Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt'",
        "UPDATE `booking_payments` SET `verification_status` = 'Receipt Submitted' WHERE `verification_status` = 'Pending Review'",
        "ALTER TABLE `booking_payments` MODIFY `verification_status` ENUM('Awaiting Receipt','Receipt Submitted','Pending Verification','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt'",
        "CREATE TABLE IF NOT EXISTS `payment_receipt_history` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function jq_migrate_v28(PDO $pdo, array &$result): void
{
    if (!jq_table_exists($pdo, 'booking_payments') || !jq_column_exists_fresh($pdo, 'booking_payments', 'verification_status')) {
        return;
    }
    $type = (string)($pdo->query("SHOW COLUMNS FROM `booking_payments` LIKE 'verification_status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
    if (stripos($type, 'Receipt Submitted') !== false && stripos($type, 'Pending Review') === false && jq_table_exists($pdo, 'payment_receipt_history')) {
        return;
    }
    foreach (['ai', 'au', 'ad'] as $suffix) {
        $pdo->exec("DROP TRIGGER IF EXISTS `trg_jq_booking_payments_{$suffix}`");
    }
    foreach (jq_v28_statements() as $sql) {
        $pdo->exec($sql);
    }
    $result['messages'][] = 'Database updated to 2.8.0.';
}
