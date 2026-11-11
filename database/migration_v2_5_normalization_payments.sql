-- ============================================================================
-- Janet's Quality Catering: database update 2.5.0
-- Supervisor role, normalized catalogue, clients, payments, attachments, event types, preparation.
--
-- The application applies this same change by itself (includes/schema_v25.php). Run this file only to
-- update a database by hand. Take a backup first. It removes no data and is safe to run again.
-- Afterwards open any signed-in page, so the system rebuilds its activity log triggers.
-- ============================================================================

SET NAMES utf8mb4;

DROP TRIGGER IF EXISTS trg_jq_users_ai;
DROP TRIGGER IF EXISTS trg_jq_users_au;
DROP TRIGGER IF EXISTS trg_jq_users_ad;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_ai;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_au;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_ad;
DROP TRIGGER IF EXISTS trg_jq_booking_items_ai;
DROP TRIGGER IF EXISTS trg_jq_booking_items_au;
DROP TRIGGER IF EXISTS trg_jq_booking_items_ad;
DROP TRIGGER IF EXISTS trg_jq_menu_packages_ai;
DROP TRIGGER IF EXISTS trg_jq_menu_packages_au;
DROP TRIGGER IF EXISTS trg_jq_menu_packages_ad;
DROP TRIGGER IF EXISTS trg_jq_menu_items_ai;
DROP TRIGGER IF EXISTS trg_jq_menu_items_au;
DROP TRIGGER IF EXISTS trg_jq_menu_items_ad;
DROP TRIGGER IF EXISTS trg_jq_menu_bundles_ai;
DROP TRIGGER IF EXISTS trg_jq_menu_bundles_au;
DROP TRIGGER IF EXISTS trg_jq_menu_bundles_ad;

ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role;

ALTER TABLE users MODIFY role ENUM('ADMIN','OWNER','SUPERVISOR') NOT NULL DEFAULT 'ADMIN';

ALTER TABLE users ADD CONSTRAINT IF NOT EXISTS chk_users_role CHECK (role IN ('ADMIN','OWNER','SUPERVISOR'));

CREATE TABLE IF NOT EXISTS event_types (
            event_type_id INT AUTO_INCREMENT PRIMARY KEY,
            event_type_name VARCHAR(100) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_event_type_name (event_type_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO event_types (event_type_name, sort_order) VALUES
            ('Wedding',1),('Birthday',2),('Debut',3),('Kiddie Birthday',4),('Christmas Party',5),('Corporate Event',6),
            ('Gala Dinner',7),('Graduation',8),('Seminar',9),('Anniversary',10),('Renewal of Vows',11),('General Party',12);

INSERT IGNORE INTO event_types (event_type_name, sort_order)
            SELECT DISTINCT TRIM(event_type), 50 FROM event_bookings WHERE event_type IS NOT NULL AND TRIM(event_type) <> '';

INSERT IGNORE INTO event_types (event_type_name, sort_order)
            SELECT DISTINCT TRIM(event_type), 50 FROM backdrops WHERE event_type IS NOT NULL AND TRIM(event_type) <> '';

CREATE TABLE IF NOT EXISTS package_event_types (
            package_id INT NOT NULL,
            event_type_id INT NOT NULL,
            PRIMARY KEY (package_id, event_type_id),
            CONSTRAINT fk_pet_package FOREIGN KEY (package_id) REFERENCES menu_packages (package_id) ON DELETE CASCADE,
            CONSTRAINT fk_pet_type FOREIGN KEY (event_type_id) REFERENCES event_types (event_type_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO package_event_types (package_id, event_type_id)
            SELECT p.package_id, t.event_type_id FROM menu_packages p JOIN event_types t ON FIND_IN_SET(t.event_type_name, p.event_type) > 0;

CREATE TABLE IF NOT EXISTS package_inclusions (
            inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            package_id INT NOT NULL,
            item_id INT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_package_inclusion (package_id, inclusion_text),
            CONSTRAINT fk_pi_package FOREIGN KEY (package_id) REFERENCES menu_packages (package_id) ON DELETE CASCADE,
            CONSTRAINT fk_pi_item FOREIGN KEY (item_id) REFERENCES menu_items (item_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bundle_inclusions (
            inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            bundle_id INT NOT NULL,
            item_id INT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_bundle_inclusion (bundle_id, inclusion_text),
            CONSTRAINT fk_bin_bundle FOREIGN KEY (bundle_id) REFERENCES menu_bundles (bundle_id) ON DELETE CASCADE,
            CONSTRAINT fk_bin_item FOREIGN KEY (item_id) REFERENCES menu_items (item_id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE menu_packages ADD COLUMN IF NOT EXISTS pricing_note VARCHAR(150) NULL AFTER price_per_guest, ADD UNIQUE KEY IF NOT EXISTS uq_package_name (package_name);

ALTER TABLE menu_bundles ADD COLUMN IF NOT EXISTS order_note VARCHAR(150) NULL AFTER price_mode, ADD UNIQUE KEY IF NOT EXISTS uq_bundle_name (bundle_name);

ALTER TABLE menu_items ADD UNIQUE KEY IF NOT EXISTS uq_item_name (item_name);

CREATE TABLE IF NOT EXISTS clients (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE event_bookings
            ADD COLUMN IF NOT EXISTS client_id INT NULL AFTER booking_ref,
            ADD COLUMN IF NOT EXISTS event_type_id INT NULL AFTER event_type,
            ADD COLUMN IF NOT EXISTS region VARCHAR(150) NULL AFTER contact,
            ADD COLUMN IF NOT EXISTS preparation_status ENUM('Pending Preparation','Preparing','Ready for Event','Completed') NOT NULL DEFAULT 'Pending Preparation' AFTER status,
            ADD COLUMN IF NOT EXISTS preparation_notes TEXT NULL AFTER preparation_status,
            ADD COLUMN IF NOT EXISTS completed_at DATETIME NULL AFTER preparation_notes,
            ADD COLUMN IF NOT EXISTS completed_by INT NULL AFTER completed_at,
            MODIFY status ENUM('Pending','Approved','Cancelled','Disabled','Completed') DEFAULT 'Pending',
            ADD INDEX IF NOT EXISTS idx_booking_client (client_id),
            ADD INDEX IF NOT EXISTS idx_booking_event_type (event_type_id);

ALTER TABLE event_bookings ADD CONSTRAINT fk_booking_client FOREIGN KEY IF NOT EXISTS (client_id) REFERENCES clients (client_id) ON DELETE SET NULL;

ALTER TABLE event_bookings ADD CONSTRAINT fk_booking_event_type FOREIGN KEY IF NOT EXISTS (event_type_id) REFERENCES event_types (event_type_id) ON DELETE SET NULL;

UPDATE event_bookings b JOIN event_types t ON t.event_type_name = TRIM(b.event_type) SET b.event_type_id = t.event_type_id WHERE b.event_type_id IS NULL;

UPDATE event_bookings SET preparation_status = 'Completed', completed_at = COALESCE(completed_at, event_date) WHERE status = 'Completed';

ALTER TABLE booking_items ADD COLUMN IF NOT EXISTS quantity INT NOT NULL DEFAULT 1 AFTER price_mode;

UPDATE booking_items i JOIN event_bookings b ON b.booking_id = i.booking_id
            SET i.quantity = GREATEST(1, ROUND(i.line_total / IF(i.price_mode = 'per_guest', i.unit_price * GREATEST(b.headcount, 1), i.unit_price)))
          WHERE i.quantity = 1 AND i.unit_price > 0 AND i.line_total > 0;

CREATE TABLE IF NOT EXISTS booking_inclusions (
            booking_inclusion_id INT AUTO_INCREMENT PRIMARY KEY,
            booking_id INT NOT NULL,
            source_type ENUM('package','bundle') NOT NULL,
            source_id INT NULL,
            source_name VARCHAR(200) NOT NULL,
            inclusion_text VARCHAR(200) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            KEY idx_binc_booking (booking_id),
            CONSTRAINT fk_binc_booking FOREIGN KEY (booking_id) REFERENCES event_bookings (booking_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_payments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_attachments (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hand-checked inclusions: each one is its own row.
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '4-hour all-in service', 1 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fresh garden salad', 2 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Soup', 3 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pasta', 4 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Vegetables', 5 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Seafood', 6 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Chicken', 7 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pork', 8 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Dessert with steamed rice and drinks', 9 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Elegant buffet set-up', 10 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Banquet chairs', 11 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'VIP plated service for 20 pax', 12 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Host', 13 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Lights and sounds', 14 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '2-tier fondant cake', 15 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Photobooth', 16 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'HMUA', 17 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Photo and video coverage', 18 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'On-the-day coordination', 19 FROM menu_packages WHERE package_name = 'Classica Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '4-hour premium all-in service', 1 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '2 appetizers', 2 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Salad', 3 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Soup', 4 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pasta', 5 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Vegetable', 6 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Seafoods', 7 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Chicken', 8 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pork', 9 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Beef', 10 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '2 desserts with steamed rice and drinks', 11 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Upgraded stylish stage backdrop', 12 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Tiffany and ghost chairs', 13 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'LED wall', 14 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, '3-tier cake', 15 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Magnetic photobooth', 16 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Professional photo and video', 17 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Upgraded styling', 18 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Full coordination team', 19 FROM menu_packages WHERE package_name = 'Royale Package (Wedding & Debut)';
UPDATE menu_packages SET pricing_note = '₱635-750 per head, depending on pax' WHERE package_name = 'Catering Menu 1' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Nacho cheese dip', 1 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy pumpkin soup', 2 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cheesy baked macaroni', 3 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Buttered broccoli', 4 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Grilled blue marlin', 5 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Grilled chicken in mushroom sauce', 6 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Korean beef stew', 7 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 8 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mango pandan salad', 9 FROM menu_packages WHERE package_name = 'Catering Menu 1';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cucumber lemonade', 10 FROM menu_packages WHERE package_name = 'Catering Menu 1';
UPDATE menu_packages SET pricing_note = '₱600-825 per head' WHERE package_name = 'Catering Menu 2' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Tuna canape', 1 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Nacho cheese dip', 2 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Ham and cheese roll', 3 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cheesy baked macaroni', 4 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roasted potato mushroom', 5 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Baked prawn in pesto', 6 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy chicken in sundried tomato', 7 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Korean beef stew', 8 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 9 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mango pandan salad', 10 FROM menu_packages WHERE package_name = 'Catering Menu 2';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'House blend iced tea', 11 FROM menu_packages WHERE package_name = 'Catering Menu 2';
UPDATE menu_packages SET pricing_note = '₱750-950 per head' WHERE package_name = 'Catering Menu 3' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Beef wrap', 1 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mini clubhouse sandwich', 2 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fresh garden salad', 3 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Chicken and corn soup', 4 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cheesy baked macaroni', 5 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Stir fry mixed vegetables', 6 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Baked fish fillet with parmesan', 7 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Grilled chicken teriyaki de luxe', 8 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Smoked pork belly', 9 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy beef mushroom', 10 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 11 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Custard cream puff', 12 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mango panna cotta', 13 FROM menu_packages WHERE package_name = 'Catering Menu 3';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cucumber lemonade', 14 FROM menu_packages WHERE package_name = 'Catering Menu 3';
UPDATE menu_packages SET pricing_note = '₱750-950 per head' WHERE package_name = 'Catering Menu 4' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Ham and cheese roll or tuna canape', 1 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Kani mango salad', 2 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cream of mushroom soup', 3 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Meaty lasagna', 4 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roasted broccoli zucchini mushroom', 5 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Baked prawn in pesto', 6 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy chicken in sundried tomato', 7 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pork belly in Asian sauce', 8 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roast beef with mashed potato', 9 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 10 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Custard cream puff', 11 FROM menu_packages WHERE package_name = 'Catering Menu 4';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mango panna cotta', 12 FROM menu_packages WHERE package_name = 'Catering Menu 4';
UPDATE menu_packages SET pricing_note = '₱750-950 per head' WHERE package_name = 'Catering Menu 5' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cheesy mini burger', 1 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roka salata', 2 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy pumpkin soup', 3 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fusilli pasta with 2 sauces', 4 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mixed vegetable with shrimp', 5 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Spicy shrimp in salted egg', 6 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roast basil chicken in chimichurri', 7 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pork mongolian de luxe', 8 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Beef lengua green olive', 9 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 10 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Custard flan', 11 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Buko pandan con lychee', 12 FROM menu_packages WHERE package_name = 'Catering Menu 5';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cucumber lemonade', 13 FROM menu_packages WHERE package_name = 'Catering Menu 5';
UPDATE menu_packages SET pricing_note = '₱750-950 per head' WHERE package_name = 'Catering Menu 6' AND (pricing_note IS NULL OR pricing_note = '');
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Kani mango salad', 1 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Creamy pumpkin soup', 2 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fettuccine pasta with alfredo and marinara', 3 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fresh lumpiang ubod roll', 4 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Roast basil lemon grass chicken', 5 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Fish fillet with cilantro and mango salsa', 6 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Pork mongolian de luxe', 7 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Beef lengua with green olive', 8 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Parsley rice', 9 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Custard flan', 10 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Mango panna cotta', 11 FROM menu_packages WHERE package_name = 'Catering Menu 6';
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order) SELECT package_id, 'Cucumber lemonade', 12 FROM menu_packages WHERE package_name = 'Catering Menu 6';

-- Other rows keep their description as a single inclusion. Split them in Food Menus if needed.
INSERT IGNORE INTO package_inclusions (package_id, inclusion_text, sort_order)
  SELECT t.package_id, LEFT(TRIM(TRAILING '.' FROM t.description), 200), 1 FROM menu_packages t
   WHERE t.description IS NOT NULL AND t.description <> '' AND NOT EXISTS (SELECT 1 FROM package_inclusions i WHERE i.package_id = t.package_id);

UPDATE menu_bundles SET order_note = 'Minimum 20 packs' WHERE bundle_name = 'Packed Meal Set 1' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork barbeque', 1 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chicken sotanghon guisado', 2 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Vietnamese spring roll with nuoc sauce', 3 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 4 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 1';
UPDATE menu_bundles SET order_note = 'Minimum 20 packs' WHERE bundle_name = 'Packed Meal Set 4' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Grilled chicken in mushroom steak sauce', 1 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 4';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork belly in hickory sauce', 2 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 4';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Roasted potato and mushroom with bacon bits', 3 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 4';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 4 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 4';
UPDATE menu_bundles SET order_note = 'Minimum 20 packs' WHERE bundle_name = 'Packed Meal Set 5' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Fall-off-the-bone pork ribs in special BBQ sauce', 1 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Baked fish fillet with parmesan', 2 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Fettuccini in alfredo sauce', 3 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 4 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 5';
UPDATE menu_bundles SET order_note = 'Minimum 20 packs' WHERE bundle_name = 'Packed Meal Set 7' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Grilled blue marlin in lemon sauce', 1 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 7';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chicken cordon bleu', 2 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 7';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Cheesy baked macaroni', 3 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 7';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Leche flan', 4 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 7';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 5 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 7';
UPDATE menu_bundles SET order_note = 'Minimum 20 packs' WHERE bundle_name = 'Packed Meal Set 8' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Grilled chicken teriyaki de luxe', 1 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 8';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Roast beef with mashed potato', 2 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 8';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Fettucine mushroom in alfredo', 3 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 8';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 4 FROM menu_bundles WHERE bundle_name = 'Packed Meal Set 8';
UPDATE menu_bundles SET order_note = 'Good for 10 pax' WHERE bundle_name = 'Salu Salo Meal 1' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pansit canton', 1 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chopsuey', 2 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Janet''s fried chicken', 3 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Plain rice', 4 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Leche flan', 5 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Softdrinks', 6 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 1';
UPDATE menu_bundles SET order_note = 'Good for 10 pax' WHERE bundle_name = 'Salu Salo Meal 3' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Bihon canton guisado', 1 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chopsuey special', 2 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chicken BBQ', 3 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Siomai', 4 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork sinigang', 5 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Shrimp tempura', 6 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Beef kare-kare', 7 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Plain rice', 8 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Leche flan', 9 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Softdrinks', 10 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 3';
UPDATE menu_bundles SET order_note = 'Good for 10 pax' WHERE bundle_name = 'Salu Salo Meal 5' AND (order_note IS NULL OR order_note = '');
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chami', 1 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Lumpiang ubod', 2 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Inihaw bangus', 3 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Inihaw tilapia', 4 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Janet''s fried chicken', 5 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Sinigang na baka', 6 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Beef broccoli', 7 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Plain rice', 8 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Leche flan', 9 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Softdrinks', 10 FROM menu_bundles WHERE bundle_name = 'Salu Salo Meal 5';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Patatim braised pork leg', 1 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Buttered chicken', 2 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Miki bihon', 3 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Special seafoods fried rice', 4 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Free steamed siomai', 5 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 1';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Janet''s fried chicken', 1 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork asado', 2 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Miki bihon', 3 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Special seafoods fried rice', 4 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Free steamed siomai', 5 FROM menu_bundles WHERE bundle_name = 'Lucky Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork asado', 1 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Boneless bangus', 2 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chopsuey', 3 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '4 rice', 4 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '1.5 softdrinks', 5 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Squidballs', 6 FROM menu_bundles WHERE bundle_name = 'Barkada Meal A';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Pork sinigang', 1 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Mixed vegetables', 2 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '4pcs chicken BBQ', 3 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Shanghai', 4 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Squidballs', 5 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '4 rice', 6 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '1.5 softdrinks', 7 FROM menu_bundles WHERE bundle_name = 'Barkada Meal C';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chicken inasal', 1 FROM menu_bundles WHERE bundle_name = 'Family Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Vegetable with pork asado', 2 FROM menu_bundles WHERE bundle_name = 'Family Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Beef mushroom', 3 FROM menu_bundles WHERE bundle_name = 'Family Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 4 FROM menu_bundles WHERE bundle_name = 'Family Bundle 3';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Crispy pata (good for 4-6 pax)', 1 FROM menu_bundles WHERE bundle_name = 'Family Bundle 6';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chopsuey special', 2 FROM menu_bundles WHERE bundle_name = 'Family Bundle 6';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Sinigang sa miso', 3 FROM menu_bundles WHERE bundle_name = 'Family Bundle 6';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Chicken barbeque', 4 FROM menu_bundles WHERE bundle_name = 'Family Bundle 6';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, 'Steamed rice', 5 FROM menu_bundles WHERE bundle_name = 'Family Bundle 6';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '1 wedding arch', 1 FROM menu_bundles WHERE bundle_name = 'Ceremony Arrangement';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '12 standee with flower arrangement', 2 FROM menu_bundles WHERE bundle_name = 'Ceremony Arrangement';
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order) SELECT bundle_id, '50 pcs banquet chairs with cover', 3 FROM menu_bundles WHERE bundle_name = 'Ceremony Arrangement';

-- Other rows keep their description as a single inclusion. Split them in Food Menus if needed.
INSERT IGNORE INTO bundle_inclusions (bundle_id, inclusion_text, sort_order)
  SELECT t.bundle_id, LEFT(TRIM(TRAILING '.' FROM t.description), 200), 1 FROM menu_bundles t
   WHERE t.description IS NOT NULL AND t.description <> '' AND NOT EXISTS (SELECT 1 FROM bundle_inclusions i WHERE i.bundle_id = t.bundle_id);

UPDATE package_inclusions i JOIN (SELECT n.inclusion_id, MIN(m.item_id) AS item_id FROM package_inclusions n JOIN menu_items m ON m.item_name LIKE CONCAT(n.inclusion_text, '%') WHERE n.item_id IS NULL GROUP BY n.inclusion_id HAVING COUNT(*) = 1) x ON x.inclusion_id = i.inclusion_id SET i.item_id = x.item_id;
UPDATE bundle_inclusions i JOIN (SELECT n.inclusion_id, MIN(m.item_id) AS item_id FROM bundle_inclusions n JOIN menu_items m ON m.item_name LIKE CONCAT(n.inclusion_text, '%') WHERE n.item_id IS NULL GROUP BY n.inclusion_id HAVING COUNT(*) = 1) x ON x.inclusion_id = i.inclusion_id SET i.item_id = x.item_id;

-- What each existing booking includes.
INSERT INTO booking_inclusions (booking_id, source_type, source_id, source_name, inclusion_text, sort_order)
  SELECT b.booking_id, 'package', b.package_id, COALESCE(b.package_name, p.package_name), i.inclusion_text, i.sort_order
    FROM event_bookings b JOIN menu_packages p ON p.package_id = b.package_id JOIN package_inclusions i ON i.package_id = p.package_id
   WHERE NOT EXISTS (SELECT 1 FROM booking_inclusions x WHERE x.booking_id = b.booking_id AND x.source_type = 'package');
INSERT INTO booking_inclusions (booking_id, source_type, source_id, source_name, inclusion_text, sort_order)
  SELECT bi.booking_id, 'bundle', bi.ref_id, bi.item_name, i.inclusion_text, i.sort_order
    FROM booking_items bi JOIN bundle_inclusions i ON i.bundle_id = bi.ref_id
   WHERE bi.ref_type = 'bundle' AND NOT EXISTS (SELECT 1 FROM booking_inclusions x WHERE x.booking_id = bi.booking_id AND x.source_type = 'bundle' AND x.source_id = bi.ref_id);
