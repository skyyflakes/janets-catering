-- Janet's Quality Catering: database update 2.9.0
-- Guests covered per order (per table) for bundles and add-ons, shown in Step 3 of the booking wizard.
--
-- You normally do not need to run this. The system applies the same update by itself the next time a page opens.
-- Use it only to update a database by hand, after update 2.8.0, and take a backup first.
-- Safe to run more than once. Open the system once afterwards, so it rebuilds its activity log triggers.

DROP TRIGGER IF EXISTS `trg_jq_menu_bundles_ai`;
DROP TRIGGER IF EXISTS `trg_jq_menu_bundles_au`;
DROP TRIGGER IF EXISTS `trg_jq_menu_bundles_ad`;

ALTER TABLE `menu_bundles` ADD COLUMN IF NOT EXISTS `guests_covered` SMALLINT UNSIGNED NULL DEFAULT NULL
    COMMENT 'Guests served by one order (per table); NULL when not set. Not used for per-guest prices.' AFTER `price_mode`;

-- Fill it in where the order note already states it, for example "Good for 10 pax".
UPDATE `menu_bundles`
   SET `guests_covered` = CAST(REGEXP_SUBSTR(LOWER(`order_note`), '(?<=good for )[0-9]+') AS UNSIGNED)
 WHERE `price_mode` = 'flat' AND `guests_covered` IS NULL AND LOWER(`order_note`) REGEXP 'good for [0-9]+';
