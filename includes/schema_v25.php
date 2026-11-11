<?php
/**
 * Database update 2.5.0: Supervisor role, normalized catalogue, clients, payments, attachments,
 * event types and preparation tracking.
 *
 * jq_v25_statements() is the single source of the change. includes/activity_logger.php runs it, and
 * database/migration_v2_5_normalization_payments.sql is generated from it. Every statement can run again safely.
 * No existing column or row is removed here. Columns replaced by these tables are dropped only by
 * database/optional_cleanup_after_v2_5.sql, which a person runs after checking the results.
 */

/** Triggers on tables whose columns change. They are rebuilt afterwards, so this maintenance is not logged as activity. */
function jq_v25_trigger_tables(): array
{
    return ['users', 'event_bookings', 'booking_items', 'menu_packages', 'menu_items', 'menu_bundles'];
}

function jq_v25_statements(): array
{
    $tbl = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        // ---------- Roles ----------
        "ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role",
        "ALTER TABLE users MODIFY role ENUM('ADMIN','OWNER','SUPERVISOR') NOT NULL DEFAULT 'ADMIN'",
        "ALTER TABLE users ADD CONSTRAINT IF NOT EXISTS chk_users_role CHECK (role IN ('ADMIN','OWNER','SUPERVISOR'))",

        // ---------- Event types ----------
        "CREATE TABLE IF NOT EXISTS event_types (
            event_type_id INT AUTO_INCREMENT PRIMARY KEY,
            event_type_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_type_name (event_type_name)
        ) $tbl",
        "INSERT IGNORE INTO event_types (event_type_name, sort_order) VALUES
            ('Wedding',1),('Birthday',2),('Debut',3),('Kiddie Birthday',4),('Christmas Party',5),('Corporate Event',6),
            ('Gala Dinner',7),('Graduation',8),('Seminar',9),('Anniversary',10),('Renewal of Vows',11),('General Party',12)",
        "INSERT IGNORE INTO event_types (event_type_name, sort_order)
            SELECT DISTINCT TRIM(event_type), 50 FROM event_bookings WHERE event_type IS NOT NULL AND TRIM(event_type) <> ''",
        "INSERT IGNORE INTO event_types (event_type_name, sort_order)
            SELECT DISTINCT TRIM(event_type), 50 FROM backdrops WHERE event_type IS NOT NULL AND TRIM(event_type) <> ''",
        "CREATE TABLE IF NOT EXISTS package_event_types (
            package_id INT NOT NULL,
            event_type_id INT NOT NULL,
            PRIMARY KEY (package_id, event_type_id),
            CONSTRAINT fk_pet_package FOREIGN KEY (package_id) REFERENCES menu_packages (package_id) ON DELETE CASCADE,
            CONSTRAINT fk_pet_type FOREIGN KEY (event_type_id) REFERENCES event_types (event_type_id) ON DELETE CASCADE
        ) $tbl",
        "INSERT IGNORE INTO package_event_types (package_id, event_type_id)
            SELECT p.package_id, t.event_type_id FROM menu_packages p JOIN event_types t ON FIND_IN_SET(t.event_type_name, p.event_type) > 0",

        // ---------- Catalogue inclusions, one row each ----------
        "CREATE TABLE IF NOT EXISTS package_inclusions (
            inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NOT NULL,
            item_id INT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_package_inclusion (package_id, inclusion_text),
            CONSTRAINT fk_pi_package FOREIGN KEY (package_id) REFERENCES menu_packages (package_id) ON DELETE CASCADE,
            CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES menu_items (item_id) ON DELETE SET NULL
        ) $tbl",
        "CREATE TABLE IF NOT EXISTS bundle_inclusions (
            inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            bundle_id INT NOT NULL,
            item_id INT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_bundle_inclusion (bundle_id, inclusion_text),
            CONSTRAINT fk_bin_bundle FOREIGN KEY (bundle_id) REFERENCES menu_bundles (bundle_id) ON DELETE CASCADE,
            CONSTRAINT fk_bin_item FOREIGN KEY (item_id) REFERENCES menu_items (item_id) ON DELETE SET NULL
        ) $tbl",
        "ALTER TABLE menu_packages ADD COLUMN IF NOT EXISTS pricing_note VARCHAR(150) NULL AFTER price_per_guest, ADD UNIQUE KEY IF NOT EXISTS uq_package_name (package_name)",
        "ALTER TABLE menu_bundles ADD COLUMN IF NOT EXISTS order_note VARCHAR(150) NULL AFTER price_mode, ADD UNIQUE KEY IF NOT EXISTS uq_bundle_name (bundle_name)",
        "ALTER TABLE menu_items ADD UNIQUE KEY IF NOT EXISTS uq_item_name (item_name)",

        // ---------- Clients ----------
        "CREATE TABLE IF NOT EXISTS clients (
            client_id INT AUTO_INCREMENT PRIMARY KEY,
            client_code VARCHAR(20) NULL,
            fullname VARCHAR(255) NOT NULL,
            email VARCHAR(100) NULL,
            contact VARCHAR(20) NULL,
            postal_code VARCHAR(4) NULL,
            address TEXT NULL,
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_client_code (client_code),
            UNIQUE KEY uq_client_identity (fullname, contact),
            KEY idx_client_contact (contact),
            KEY idx_client_email (email),
            CONSTRAINT fk_client_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) $tbl",

        // ---------- Event bookings ----------
        "ALTER TABLE event_bookings
            ADD COLUMN IF NOT EXISTS client_id INT NULL AFTER booking_ref,
            ADD COLUMN IF NOT EXISTS event_type_id INT NULL AFTER event_type,
            ADD COLUMN IF NOT EXISTS region VARCHAR(150) NULL AFTER contact,
            ADD COLUMN IF NOT EXISTS preparation_status ENUM('Pending Preparation','Preparing','Ready for Event','Completed') NOT NULL DEFAULT 'Pending Preparation' AFTER status,
            ADD COLUMN IF NOT EXISTS preparation_notes TEXT NULL AFTER preparation_status,
            ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER preparation_notes,
            ADD COLUMN IF NOT EXISTS completed_by INT NULL AFTER completed_at,
            MODIFY status ENUM('Pending','Approved','Cancelled','Disabled','Completed') DEFAULT 'Pending',
            ADD INDEX IF NOT EXISTS idx_booking_client (client_id),
            ADD INDEX IF NOT EXISTS idx_booking_event_type (event_type_id)",
        "ALTER TABLE event_bookings ADD CONSTRAINT fk_booking_client FOREIGN KEY IF NOT EXISTS (client_id) REFERENCES clients (client_id) ON DELETE SET NULL",
        "ALTER TABLE event_bookings ADD CONSTRAINT fk_booking_event_type FOREIGN KEY IF NOT EXISTS (event_type_id) REFERENCES event_types (event_type_id) ON DELETE SET NULL",
        "UPDATE event_bookings b JOIN event_types t ON t.event_type_name = TRIM(b.event_type) SET b.event_type_id = t.event_type_id WHERE b.event_type_id IS NULL",
        "UPDATE event_bookings SET preparation_status = 'Completed', completed_at = COALESCE(completed_at, event_date) WHERE status = 'Completed'",

        // ---------- Booking lines: the quantity was only implied by the price ----------
        "ALTER TABLE booking_items ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1 AFTER price_mode",
        "UPDATE booking_items i JOIN event_bookings b ON b.booking_id = i.booking_id
            SET i.quantity = GREATEST(1, ROUND(i.line_total / IF(i.price_mode = 'per_guest', i.unit_price * GREATEST(b.headcount, 1), i.unit_price)))
          WHERE i.quantity = 1 AND i.unit_price > 0 AND i.line_total > 0",

        // ---------- What a booking included when it was saved. Later catalogue changes do not touch it. ----------
        "CREATE TABLE IF NOT EXISTS booking_inclusions (
            booking_inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            source_type ENUM('package','bundle') NOT NULL,
            source_id INT NULL,
            source_name VARCHAR(200) NOT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_binc_booking (booking_id),
            CONSTRAINT fk_binc_booking FOREIGN KEY (booking_id) REFERENCES event_bookings (booking_id) ON DELETE CASCADE
        ) $tbl",

        // ---------- Payments and files ----------
        "CREATE TABLE IF NOT EXISTS booking_payments (
            payment_id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            payment_type ENUM('Downpayment','Additional Payment') NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            payment_date DATE NOT NULL,
            payment_method ENUM('Cash','GCash','Bank Transfer','Check','Credit or Debit Card','Other') NOT NULL,
            reference_no VARCHAR(100) NULL,
            notes VARCHAR(255) NULL,
            receipt_path VARCHAR(255) NULL,
            receipt_name VARCHAR(255) NULL,
            receipt_mime VARCHAR(100) NULL,
            recorded_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_payment_booking (booking_id, payment_date),
            CONSTRAINT fk_payment_booking FOREIGN KEY (booking_id) REFERENCES event_bookings (booking_id) ON DELETE CASCADE,
            CONSTRAINT fk_payment_user FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
            CONSTRAINT chk_payment_amount CHECK (amount > 0)
        ) $tbl",
        "CREATE TABLE IF NOT EXISTS booking_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            document_type ENUM('Signed Contract','Client Signature','Approval Document','Confirmation Document','Other') NOT NULL DEFAULT 'Other',
            original_name VARCHAR(255) NOT NULL,
            stored_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            file_size INT NOT NULL,
            uploaded_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_attachment_booking (booking_id),
            CONSTRAINT fk_attachment_booking FOREIGN KEY (booking_id) REFERENCES event_bookings (booking_id) ON DELETE CASCADE,
            CONSTRAINT fk_attachment_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
        ) $tbl",
    ];
}

/** Hand-checked lists, so that "lights & sounds" stays one inclusion. Rows not named here use jq_v25_split_text(). */
function jq_v25_curated(): array
{
    return [
        'packages' => [
            'Classica Package (Wedding & Debut)' => ['', ['4-hour all-in service', 'Fresh garden salad', 'Soup', 'Pasta', 'Vegetables', 'Seafood', 'Chicken', 'Pork', 'Dessert with steamed rice and drinks', 'Elegant buffet set-up', 'Banquet chairs', 'VIP plated service for 20 pax', 'Host', 'Lights and sounds', '2-tier fondant cake', 'Photobooth', 'HMUA', 'Photo and video coverage', 'On-the-day coordination']],
            'Royale Package (Wedding & Debut)' => ['', ['4-hour premium all-in service', '2 appetizers', 'Salad', 'Soup', 'Pasta', 'Vegetable', 'Seafoods', 'Chicken', 'Pork', 'Beef', '2 desserts with steamed rice and drinks', 'Upgraded stylish stage backdrop', 'Tiffany and ghost chairs', 'LED wall', '3-tier cake', 'Magnetic photobooth', 'Professional photo and video', 'Upgraded styling', 'Full coordination team']],
            'Catering Menu 1' => ["\u{20B1}635-750 per head, depending on pax", ['Nacho cheese dip', 'Creamy pumpkin soup', 'Cheesy baked macaroni', 'Buttered broccoli', 'Grilled blue marlin', 'Grilled chicken in mushroom sauce', 'Korean beef stew', 'Parsley rice', 'Mango pandan salad', 'Cucumber lemonade']],
            'Catering Menu 2' => ["\u{20B1}600-825 per head", ['Tuna canape', 'Nacho cheese dip', 'Ham and cheese roll', 'Cheesy baked macaroni', 'Roasted potato mushroom', 'Baked prawn in pesto', 'Creamy chicken in sundried tomato', 'Korean beef stew', 'Parsley rice', 'Mango pandan salad', 'House blend iced tea']],
            'Catering Menu 3' => ["\u{20B1}750-950 per head", ['Beef wrap', 'Mini clubhouse sandwich', 'Fresh garden salad', 'Chicken and corn soup', 'Cheesy baked macaroni', 'Stir fry mixed vegetables', 'Baked fish fillet with parmesan', 'Grilled chicken teriyaki de luxe', 'Smoked pork belly', 'Creamy beef mushroom', 'Parsley rice', 'Custard cream puff', 'Mango panna cotta', 'Cucumber lemonade']],
            'Catering Menu 4' => ["\u{20B1}750-950 per head", ['Ham and cheese roll or tuna canape', 'Kani mango salad', 'Cream of mushroom soup', 'Meaty lasagna', 'Roasted broccoli zucchini mushroom', 'Baked prawn in pesto', 'Creamy chicken in sundried tomato', 'Pork belly in Asian sauce', 'Roast beef with mashed potato', 'Parsley rice', 'Custard cream puff', 'Mango panna cotta']],
            'Catering Menu 5' => ["\u{20B1}750-950 per head", ['Cheesy mini burger', 'Roka salata', 'Creamy pumpkin soup', 'Fusilli pasta with 2 sauces', 'Mixed vegetable with shrimp', 'Spicy shrimp in salted egg', 'Roast basil chicken in chimichurri', 'Pork mongolian de luxe', 'Beef lengua green olive', 'Parsley rice', 'Custard flan', 'Buko pandan con lychee', 'Cucumber lemonade']],
            'Catering Menu 6' => ["\u{20B1}750-950 per head", ['Kani mango salad', 'Creamy pumpkin soup', 'Fettuccine pasta with alfredo and marinara', 'Fresh lumpiang ubod roll', 'Roast basil lemon grass chicken', 'Fish fillet with cilantro and mango salsa', 'Pork mongolian de luxe', 'Beef lengua with green olive', 'Parsley rice', 'Custard flan', 'Mango panna cotta', 'Cucumber lemonade']],
        ],
        'bundles' => [
            'Packed Meal Set 1' => ['Minimum 20 packs', ['Pork barbeque', 'Chicken sotanghon guisado', 'Vietnamese spring roll with nuoc sauce', 'Steamed rice']],
            'Packed Meal Set 4' => ['Minimum 20 packs', ['Grilled chicken in mushroom steak sauce', 'Pork belly in hickory sauce', 'Roasted potato and mushroom with bacon bits', 'Steamed rice']],
            'Packed Meal Set 5' => ['Minimum 20 packs', ['Fall-off-the-bone pork ribs in special BBQ sauce', 'Baked fish fillet with parmesan', 'Fettuccini in alfredo sauce', 'Steamed rice']],
            'Packed Meal Set 7' => ['Minimum 20 packs', ['Grilled blue marlin in lemon sauce', 'Chicken cordon bleu', 'Cheesy baked macaroni', 'Leche flan', 'Steamed rice']],
            'Packed Meal Set 8' => ['Minimum 20 packs', ['Grilled chicken teriyaki de luxe', 'Roast beef with mashed potato', 'Fettucine mushroom in alfredo', 'Steamed rice']],
            'Salu Salo Meal 1' => ['Good for 10 pax', ['Pansit canton', 'Chopsuey', "Janet's fried chicken", 'Plain rice', 'Leche flan', 'Softdrinks']],
            'Salu Salo Meal 3' => ['Good for 10 pax', ['Bihon canton guisado', 'Chopsuey special', 'Chicken BBQ', 'Siomai', 'Pork sinigang', 'Shrimp tempura', 'Beef kare-kare', 'Plain rice', 'Leche flan', 'Softdrinks']],
            'Salu Salo Meal 5' => ['Good for 10 pax', ['Chami', 'Lumpiang ubod', 'Inihaw bangus', 'Inihaw tilapia', "Janet's fried chicken", 'Sinigang na baka', 'Beef broccoli', 'Plain rice', 'Leche flan', 'Softdrinks']],
            'Lucky Bundle 1' => ['', ['Patatim braised pork leg', 'Buttered chicken', 'Miki bihon', 'Special seafoods fried rice', 'Free steamed siomai']],
            'Lucky Bundle 3' => ['', ["Janet's fried chicken", 'Pork asado', 'Miki bihon', 'Special seafoods fried rice', 'Free steamed siomai']],
            'Barkada Meal A' => ['', ['Pork asado', 'Boneless bangus', 'Chopsuey', '4 rice', '1.5 softdrinks', 'Squidballs']],
            'Barkada Meal C' => ['', ['Pork sinigang', 'Mixed vegetables', '4pcs chicken BBQ', 'Shanghai', 'Squidballs', '4 rice', '1.5 softdrinks']],
            'Family Bundle 3' => ['', ['Chicken inasal', 'Vegetable with pork asado', 'Beef mushroom', 'Steamed rice']],
            'Family Bundle 6' => ['', ['Crispy pata (good for 4-6 pax)', 'Chopsuey special', 'Sinigang sa miso', 'Chicken barbeque', 'Steamed rice']],
            'Ceremony Arrangement' => ['', ['1 wedding arch', '12 standee with flower arrangement', '50 pcs banquet chairs with cover']],
        ],
    ];
}

/** Splits a description written as a sentence: "a, b & c. (note)". Used for rows that were not hand-checked. */
function jq_v25_split_text(?string $text): array
{
    $text = trim((string)preg_replace('/\([^)]*\)\s*\.?\s*$/u', '', (string)$text));
    $parts = [];
    foreach (preg_split('/[,;:]|\.(?:\s|$)/u', $text) as $part) {
        $part = trim($part, " \t\n\r.");
        if ($part !== '') {
            $parts[] = mb_strtoupper(mb_substr($part, 0, 1)) . mb_substr($part, 1);
        }
    }
    return array_values(array_unique($parts));
}

/**
 * Creates inclusion rows for any package or bundle that has none. Existing rows are never changed,
 * so edits made in Food Menus survive a later run. Original descriptions stay in place.
 * @return int rows created
 */
function jq_v25_fill_inclusions(PDO $pdo): int
{
    $curated = jq_v25_curated();
    $made = 0;
    $sets = [
        ['menu_packages', 'package_id', 'package_name', 'package_inclusions', 'pricing_note', $curated['packages']],
        ['menu_bundles', 'bundle_id', 'bundle_name', 'bundle_inclusions', 'order_note', $curated['bundles']],
    ];
    foreach ($sets as [$table, $pk, $nameCol, $incTable, $noteCol, $known]) {
        $rows = $pdo->query("SELECT t.`$pk` AS id, t.`$nameCol` AS name, t.description FROM `$table` t
                              WHERE NOT EXISTS (SELECT 1 FROM `$incTable` i WHERE i.`$pk` = t.`$pk`)")->fetchAll(PDO::FETCH_ASSOC);
        $insert = $pdo->prepare("INSERT IGNORE INTO `$incTable` (`$pk`, inclusion_text, sort_order) VALUES (?, ?, ?)");
        $note = $pdo->prepare("UPDATE `$table` SET `$noteCol` = ? WHERE `$pk` = ? AND (`$noteCol` IS NULL OR `$noteCol` = '')");
        foreach ($rows as $row) {
            [$rowNote, $list] = $known[$row['name']] ?? ['', jq_v25_split_text($row['description'])];
            foreach (array_values($list) as $i => $text) {
                $insert->execute([$row['id'], mb_substr($text, 0, 200), $i + 1]);
                $made += $insert->rowCount();
            }
            if ($rowNote !== '') {
                $note->execute([$rowNote, $row['id']]);
            }
        }
    }
    jq_v25_link_inclusions($pdo);
    return $made;
}

/** Links an inclusion to its food item when exactly one item's name starts with the inclusion's text. */
function jq_v25_link_inclusions(PDO $pdo): void
{
    foreach (['package_inclusions', 'bundle_inclusions'] as $t) {
        $pdo->exec("UPDATE `$t` i
                      JOIN (SELECT n.inclusion_id, MIN(m.item_id) AS item_id
                              FROM `$t` n JOIN menu_items m ON m.item_name LIKE CONCAT(n.inclusion_text, '%')
                             WHERE n.item_id IS NULL GROUP BY n.inclusion_id HAVING COUNT(*) = 1) x ON x.inclusion_id = i.inclusion_id
                       SET i.item_id = x.item_id");
    }
}

/** Records what each existing booking includes, for bookings that have no record yet. */
function jq_v25_backfill_booking_inclusions(PDO $pdo): int
{
    $before = (int)$pdo->query("SELECT COUNT(*) FROM booking_inclusions")->fetchColumn();
    $pdo->exec("INSERT INTO booking_inclusions (booking_id, source_type, source_id, source_name, inclusion_text, sort_order)
                SELECT b.booking_id, 'package', b.package_id, COALESCE(b.package_name, p.package_name), i.inclusion_text, i.sort_order
                  FROM event_bookings b JOIN menu_packages p ON p.package_id = b.package_id JOIN package_inclusions i ON i.package_id = p.package_id
                 WHERE NOT EXISTS (SELECT 1 FROM booking_inclusions x WHERE x.booking_id = b.booking_id AND x.source_type = 'package')");
    $pdo->exec("INSERT INTO booking_inclusions (booking_id, source_type, source_id, source_name, inclusion_text, sort_order)
                SELECT bi.booking_id, 'bundle', bi.ref_id, bi.item_name, i.inclusion_text, i.sort_order
                  FROM booking_items bi JOIN bundle_inclusions i ON i.bundle_id = bi.ref_id
                 WHERE bi.ref_type = 'bundle'
                   AND NOT EXISTS (SELECT 1 FROM booking_inclusions x WHERE x.booking_id = bi.booking_id AND x.source_type = 'bundle' AND x.source_id = bi.ref_id)");
    return (int)$pdo->query("SELECT COUNT(*) FROM booking_inclusions")->fetchColumn() - $before;
}

/** Runs the update. Safe to run on a database that already has it. */
function jq_migrate_v25(PDO $pdo, array &$result): void
{
    foreach (['users', 'event_bookings', 'menu_packages', 'menu_bundles', 'menu_items', 'booking_items'] as $t) {
        if (!jq_table_exists($pdo, $t)) {
            return;
        }
    }
    $roleType = (string)($pdo->query("SHOW COLUMNS FROM `users` LIKE 'role'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
    $statusType = (string)($pdo->query("SHOW COLUMNS FROM `event_bookings` LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
    $done = stripos($roleType, 'SUPERVISOR') !== false && stripos($statusType, 'Completed') !== false
        && jq_table_exists($pdo, 'booking_attachments') && jq_column_exists_fresh($pdo, 'booking_items', 'quantity');
    if (!$done) {
        foreach (jq_v25_trigger_tables() as $table) {
            foreach (['ai', 'au', 'ad'] as $suffix) {
                $pdo->exec("DROP TRIGGER IF EXISTS `trg_jq_{$table}_{$suffix}`");
            }
        }
        foreach (jq_v25_statements() as $sql) {
            $pdo->exec($sql);
        }
        $result['messages'][] = 'Database updated to 2.5.0.';
    }
    // Rows can be missing even when the structure is current, for example after restoring an older backup.
    if (jq_table_exists($pdo, 'package_inclusions')) {
        $empty = (int)$pdo->query("SELECT (SELECT COUNT(*) FROM menu_packages p WHERE NOT EXISTS (SELECT 1 FROM package_inclusions i WHERE i.package_id = p.package_id))
                                        + (SELECT COUNT(*) FROM menu_bundles b WHERE NOT EXISTS (SELECT 1 FROM bundle_inclusions i WHERE i.bundle_id = b.bundle_id))")->fetchColumn();
        if ($empty > 0 || !$done) {
            jq_v25_fill_inclusions($pdo);
        }
        if (!$done) {
            jq_v25_backfill_booking_inclusions($pdo);
        }
    }
}
