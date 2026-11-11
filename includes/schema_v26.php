<?php
/**
 * Database update 2.6.0 - Janet's Quality Catering System
 *   - email_verifications.token_hash: the verification link's token (only its hash is stored)
 *   - terms_acceptances: who accepted which version of the Terms and Conditions, and when
 *   - the Suppliers module's data is removed: suppliers and foodstocks.supplier_id
 *
 * Custom event types need no change: event_types already stores them, and its unique
 * name index ignores letter case, so duplicates are refused by the database too.
 */

/** Structure statements, shared with database/migration_v2_6_terms_links_suppliers.sql. */
function jq_v26_statements(): array
{
    return [
        "ALTER TABLE `email_verifications` ADD COLUMN IF NOT EXISTS `token_hash` CHAR(64) NULL DEFAULT NULL AFTER `code_hash`",
        "ALTER TABLE `email_verifications` ADD UNIQUE KEY IF NOT EXISTS `uq_ev_token` (`token_hash`)",
        "CREATE TABLE IF NOT EXISTS `terms_acceptances` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `terms_version` VARCHAR(20) NOT NULL,
            `accepted_at` DATETIME NOT NULL COMMENT 'First acceptance of this version',
            `last_accepted_at` DATETIME NOT NULL COMMENT 'Most recent sign-in that accepted it',
            `ip_address` VARCHAR(45) NULL DEFAULT NULL,
            `user_agent` VARCHAR(255) NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_terms_user_version` (`user_id`, `terms_version`),
            CONSTRAINT `fk_terms_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

function jq_migrate_v26(PDO $pdo, array &$result): void
{
    if (!jq_table_exists($pdo, 'users') || !jq_table_exists($pdo, 'email_verifications')) {
        return;
    }
    $hasStocks = jq_table_exists($pdo, 'foodstocks');
    $done = jq_column_exists_fresh($pdo, 'email_verifications', 'token_hash')
        && jq_table_exists($pdo, 'terms_acceptances')
        && !jq_table_exists($pdo, 'suppliers')
        && !($hasStocks && jq_column_exists_fresh($pdo, 'foodstocks', 'supplier_id'));
    if ($done) {
        return;
    }
    foreach (jq_v26_statements() as $sql) {
        $pdo->exec($sql);
    }

    // Triggers are rebuilt from the remaining columns by the next schema step.
    foreach (['foodstocks', 'suppliers'] as $table) {
        foreach (['ai', 'au', 'ad'] as $suffix) {
            $pdo->exec("DROP TRIGGER IF EXISTS `trg_jq_{$table}_{$suffix}`");
        }
    }
    // Every foreign key that points at suppliers goes first, whatever it is named.
    $fks = $pdo->query("SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'suppliers'")->fetchAll(PDO::FETCH_NUM);
    foreach ($fks as [$table, $constraint]) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` DROP FOREIGN KEY `' . str_replace('`', '', $constraint) . '`');
    }
    if ($hasStocks) {
        $pdo->exec("ALTER TABLE `foodstocks` DROP COLUMN IF EXISTS `supplier_id`");
    }
    $pdo->exec("DROP TABLE IF EXISTS `suppliers`");
    $result['messages'][] = 'Database updated to 2.6.0.';
}
