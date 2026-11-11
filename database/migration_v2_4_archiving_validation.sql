-- ============================================================================
-- Janet's Quality Catering: database update 2.4.0
-- Booking statuses, archive retention, account archiving, postal codes,
-- +63 contact numbers, report archiving and client survey criteria.
--
-- The application applies this same change by itself (jq_migrate_v24 in
-- includes/activity_logger.php). Run this file only if you want to update a
-- database by hand. Take a backup first. It is safe to run more than once.
--
-- After running it, open any page of the system while signed in, or run
--   php cli/maintenance_cli.php purge --dry-run
-- so the system rebuilds its activity log triggers for the new columns.
-- ============================================================================

SET NAMES utf8mb4;

-- These triggers refer to the old columns and would log this maintenance as user activity.
DROP TRIGGER IF EXISTS trg_jq_users_ai;
DROP TRIGGER IF EXISTS trg_jq_users_au;
DROP TRIGGER IF EXISTS trg_jq_users_ad;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_ai;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_au;
DROP TRIGGER IF EXISTS trg_jq_event_bookings_ad;
DROP TRIGGER IF EXISTS trg_jq_reports_ai;
DROP TRIGGER IF EXISTS trg_jq_reports_au;
DROP TRIGGER IF EXISTS trg_jq_reports_ad;
DROP TRIGGER IF EXISTS trg_jq_client_surveys_ai;
DROP TRIGGER IF EXISTS trg_jq_client_surveys_au;
DROP TRIGGER IF EXISTS trg_jq_client_surveys_ad;
DROP TRIGGER IF EXISTS trg_jq_suppliers_ai;
DROP TRIGGER IF EXISTS trg_jq_suppliers_au;
DROP TRIGGER IF EXISTS trg_jq_suppliers_ad;

-- ---------- Accounts ----------
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(4) NULL AFTER street_address,
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL,
    ADD COLUMN IF NOT EXISTS credentials_deleted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS credentials_deleted_by INT NULL,
    MODIFY password VARCHAR(255) NULL,
    ADD INDEX IF NOT EXISTS idx_users_archived (is_archived),
    ADD CONSTRAINT IF NOT EXISTS chk_users_postal CHECK (postal_code IS NULL OR postal_code REGEXP '^[0-9]{4}$');

-- ---------- Event bookings ----------
ALTER TABLE event_bookings
    ADD COLUMN IF NOT EXISTS postal_code VARCHAR(4) NULL AFTER barangay,
    ADD COLUMN IF NOT EXISTS archive_expires_at DATETIME NULL AFTER archived_at,
    ADD COLUMN IF NOT EXISTS pre_archive_status VARCHAR(20) NULL AFTER archive_expires_at,
    MODIFY status ENUM('Pending','Approved','Cancelled','Disabled','Confirmed','Completed') DEFAULT 'Pending';

UPDATE event_bookings SET status = 'Approved' WHERE status IN ('Confirmed','Completed');

ALTER TABLE event_bookings
    MODIFY status ENUM('Pending','Approved','Cancelled','Disabled') DEFAULT 'Pending',
    ADD INDEX IF NOT EXISTS idx_booking_archive (is_archived, archive_expires_at),
    ADD CONSTRAINT IF NOT EXISTS chk_booking_postal CHECK (postal_code IS NULL OR postal_code REGEXP '^[0-9]{4}$');

-- Bookings already in the archive become Cancelled and get a 30-day deletion date.
UPDATE event_bookings
   SET pre_archive_status = status,
       status = 'Cancelled',
       archived_at = COALESCE(archived_at, NOW()),
       archive_expires_at = DATE_ADD(COALESCE(archived_at, NOW()), INTERVAL 30 DAY)
 WHERE is_archived = 1 AND archive_expires_at IS NULL;

-- ---------- Reports ----------
ALTER TABLE reports
    ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS archived_by INT NULL,
    ADD COLUMN IF NOT EXISTS archive_expires_at DATETIME NULL;

UPDATE reports
   SET is_archived = 1, archived_at = NOW(), archive_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY), status = 'Generated'
 WHERE status = 'Archived';

UPDATE reports r LEFT JOIN users u ON u.id = r.user_id SET r.user_id = NULL WHERE r.user_id IS NOT NULL AND u.id IS NULL;

ALTER TABLE reports
    MODIFY status ENUM('Generated','Draft','Pending','Reviewed','Resolved') DEFAULT 'Generated',
    ADD INDEX IF NOT EXISTS idx_report_archive (is_archived, archive_expires_at),
    ADD CONSTRAINT fk_report_user FOREIGN KEY IF NOT EXISTS (user_id) REFERENCES users (id) ON DELETE SET NULL;

-- ---------- Client surveys ----------
ALTER TABLE client_surveys
    ADD COLUMN IF NOT EXISTS rating_food TINYINT NULL AFTER rating,
    ADD COLUMN IF NOT EXISTS rating_service TINYINT NULL AFTER rating_food,
    ADD COLUMN IF NOT EXISTS rating_timeliness TINYINT NULL AFTER rating_service,
    ADD COLUMN IF NOT EXISTS rating_presentation TINYINT NULL AFTER rating_timeliness,
    ADD COLUMN IF NOT EXISTS rating_value TINYINT NULL AFTER rating_presentation,
    ADD COLUMN IF NOT EXISTS would_recommend TINYINT(1) NULL AFTER rating_value;

UPDATE client_surveys s LEFT JOIN event_bookings b ON b.booking_id = s.booking_id
   SET s.booking_id = NULL
 WHERE s.booking_id IS NOT NULL AND b.booking_id IS NULL;

ALTER TABLE client_surveys
    ADD CONSTRAINT fk_survey_booking FOREIGN KEY IF NOT EXISTS (booking_id) REFERENCES event_bookings (booking_id) ON DELETE SET NULL,
    ADD CONSTRAINT IF NOT EXISTS chk_survey_criteria CHECK (
        COALESCE(rating_food,1) BETWEEN 1 AND 5 AND COALESCE(rating_service,1) BETWEEN 1 AND 5
        AND COALESCE(rating_timeliness,1) BETWEEN 1 AND 5 AND COALESCE(rating_presentation,1) BETWEEN 1 AND 5
        AND COALESCE(rating_value,1) BETWEEN 1 AND 5);

-- ---------- Contact numbers become +639XXXXXXXXX. Other values are left unchanged. ----------
UPDATE users SET contact_number = CONCAT('+63', RIGHT(REGEXP_REPLACE(contact_number, '[ ().-]', ''), 10))
 WHERE REGEXP_REPLACE(contact_number, '[ ().-]', '') REGEXP '^(\\+?63|0)?9[0-9]{9}$';
UPDATE event_bookings SET contact = CONCAT('+63', RIGHT(REGEXP_REPLACE(contact, '[ ().-]', ''), 10))
 WHERE REGEXP_REPLACE(contact, '[ ().-]', '') REGEXP '^(\\+?63|0)?9[0-9]{9}$';
UPDATE suppliers SET contact_number = CONCAT('+63', RIGHT(REGEXP_REPLACE(contact_number, '[ ().-]', ''), 10))
 WHERE REGEXP_REPLACE(contact_number, '[ ().-]', '') REGEXP '^(\\+?63|0)?9[0-9]{9}$';
