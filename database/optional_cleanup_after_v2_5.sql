-- Janet's Quality Catering: OPTIONAL clean-up after update 2.5.0
-- Nothing runs this automatically. Run it only after checking that the update is correct, and take a backup first.
-- It permanently removes data that the system no longer uses. Each block can be run separately.

-- 1. Foodstocks module data. No page uses it now.
--    suppliers was already removed by update 2.6.0.
DROP TABLE IF EXISTS foodstocks;
DROP TABLE IF EXISTS foodstocks_categories;

-- 2. Old paragraph columns, replaced by package_inclusions and bundle_inclusions.
ALTER TABLE menu_packages DROP COLUMN IF EXISTS description;
ALTER TABLE menu_bundles DROP COLUMN IF EXISTS description;

-- 3. Old comma-separated column, replaced by package_event_types.
ALTER TABLE menu_packages DROP COLUMN IF EXISTS event_type;
