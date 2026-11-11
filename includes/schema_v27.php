<?php
/**
 * Database update 2.7.0 - Janet's Quality Catering System
 * Proof of payment: each payment in booking_payments now carries a review status, and who reviewed it and when.
 * A payment can only be Verified when a receipt is on file. The database enforces this too.
 */

/** Shared with database/migration_v2_7_payment_receipts.sql. */
function jq_v27_statements(): array
{
    return [
        "ALTER TABLE `booking_payments`
            ADD COLUMN IF NOT EXISTS `verification_status` ENUM('Awaiting Receipt','Pending Review','Verified','Rejected') NOT NULL DEFAULT 'Awaiting Receipt' AFTER `receipt_mime`,
            ADD COLUMN IF NOT EXISTS `reviewed_by` INT(11) NULL DEFAULT NULL AFTER `verification_status`,
            ADD COLUMN IF NOT EXISTS `reviewed_at` DATETIME NULL DEFAULT NULL AFTER `reviewed_by`,
            ADD COLUMN IF NOT EXISTS `review_notes` VARCHAR(255) NULL DEFAULT NULL AFTER `reviewed_at`",
        "ALTER TABLE `booking_payments` ADD CONSTRAINT `fk_payment_reviewer` FOREIGN KEY IF NOT EXISTS (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL",
        // Receipts already on file wait for review. None is approved automatically.
        "UPDATE `booking_payments` SET `verification_status` = 'Pending Review' WHERE `receipt_path` IS NOT NULL AND `verification_status` = 'Awaiting Receipt'",
        "ALTER TABLE `booking_payments` ADD CONSTRAINT IF NOT EXISTS `chk_payment_verified_receipt` CHECK (`verification_status` <> 'Verified' OR `receipt_path` IS NOT NULL)",
    ];
}

function jq_migrate_v27(PDO $pdo, array &$result): void
{
    if (!jq_table_exists($pdo, 'booking_payments') || jq_column_exists_fresh($pdo, 'booking_payments', 'review_notes')) {
        return;
    }
    // Triggers are rebuilt from the new columns by the next schema step.
    foreach (['ai', 'au', 'ad'] as $suffix) {
        $pdo->exec("DROP TRIGGER IF EXISTS `trg_jq_booking_payments_{$suffix}`");
    }
    foreach (jq_v27_statements() as $sql) {
        $pdo->exec($sql);
    }
    $result['messages'][] = 'Database updated to 2.7.0.';
}
