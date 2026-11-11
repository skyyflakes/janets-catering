<?php
/**
 * Database update 2.9.0 - Janet's Quality Catering System
 *   - menu_bundles.guests_covered: how many guests one order of a flat-priced bundle or add-on serves
 *     (for example a meal good for one table of 10). Shown in Step 3 of the booking wizard.
 *     Filled from an existing "Good for N pax" order note; otherwise set by staff in Food Menus.
 */

/** Shared with database/migration_v2_9_bundle_guests.sql. */
function jq_v29_statements(): array
{
    return [
        "ALTER TABLE `menu_bundles` ADD COLUMN IF NOT EXISTS `guests_covered` SMALLINT UNSIGNED NULL DEFAULT NULL
            COMMENT 'Guests served by one order (per table); NULL when not set. Not used for per-guest prices.' AFTER `price_mode`",
    ];
}

/** "Good for 10 pax", "good for 4-6 persons": the guests stated in an order note, or null. */
function jq_guests_from_note(?string $note): ?int
{
    if ($note !== null && preg_match('/good\s+for\s+(\d{1,4})(?:\s*-\s*\d{1,4})?\s*(?:pax|persons?|people|guests?)\b/i', $note, $m)) {
        $n = (int)$m[1];
        return $n > 0 ? $n : null;
    }
    return null;
}

function jq_migrate_v29(PDO $pdo, array &$result): void
{
    if (!jq_table_exists($pdo, 'menu_bundles') || jq_column_exists_fresh($pdo, 'menu_bundles', 'guests_covered')) {
        return;
    }
    foreach (['ai', 'au', 'ad'] as $suffix) {
        $pdo->exec("DROP TRIGGER IF EXISTS `trg_jq_menu_bundles_{$suffix}`");
    }
    foreach (jq_v29_statements() as $sql) {
        $pdo->exec($sql);
    }
    $set = $pdo->prepare("UPDATE `menu_bundles` SET `guests_covered` = ? WHERE `bundle_id` = ?");
    foreach ($pdo->query("SELECT bundle_id, order_note FROM menu_bundles WHERE price_mode = 'flat'")->fetchAll(PDO::FETCH_ASSOC) as $b) {
        if (($n = jq_guests_from_note($b['order_note'])) !== null) {
            $set->execute([$n, $b['bundle_id']]);
        }
    }
    $result['messages'][] = 'Database updated to 2.9.0.';
}
