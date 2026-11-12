-- =====================================================
-- FIX LOGIN ACCOUNTS  (run this once on your EXISTING database)
-- phpMyAdmin > select database `janets_catering` > SQL tab > paste > Go
--
--   ADMIN  -> username: admin  (or veranosean91@gmail.com)       password: (kept privately by the Owner)
--   OWNER  -> username: owner  (or naesleirgabonarev@gmail.com)   password: (kept privately by the Owner)
--
-- The login code is emailed to the account's email. Change the passwords after your first login.
-- Safe to run more than once: it updates the rows if they exist, creates them if they don't.
-- =====================================================
USE janets_catering;

INSERT INTO `users` (`username`, `password`, `email`, `first_name`, `last_name`, `contact_number`, `phone_verified`, `role`, `status`, `address`) VALUES
('admin', '$2y$12$ZyOT/DHemk.deJtyqSSxfezYGzRoQrT5gWxUvnc74xCR.Qv.A/3/S', 'veranosean91@gmail.com', 'Admin', 'User', '09171234567', 1, 'ADMIN', 'active', 'Manila, Philippines'),
('owner', '$2y$12$zBjNAyKJMLjKvn9GOlEy/O1Zoq8MYVtAq7HdKWKlIpSMssvkg6sXK', 'naesleirgabonarev@gmail.com', 'Janet', 'Santos', '09181234567', 1, 'OWNER', 'active', 'Bulacan, Philippines')
ON DUPLICATE KEY UPDATE
    `password` = VALUES(`password`),
    `email`    = VALUES(`email`),
    `role`     = VALUES(`role`),
    `status`   = 'active';

-- Check the result
SELECT id, username, email, role, status FROM `users` WHERE username IN ('admin', 'owner');