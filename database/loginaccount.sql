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
('admin', 'pbkdf2_sha256$310000$x4aIq72GGaG7ELczuSYn7Q==$CfIjqQc5HVMXtFt9lYY/KXIhpEinqDG3HyU1zER+fWc=', 'veranosean91@gmail.com', 'Admin', 'User', '09171234567', 1, 'ADMIN', 'active', 'Manila, Philippines'),
('owner', 'pbkdf2_sha256$310000$v+7Qx4gci1BnRI1GWP6ykQ==$zrhuF0IvNcatFButn492p11ei6dJZxyiWQukf6uycK4=', 'naesleirgabonarev@gmail.com', 'Janet', 'Santos', '09181234567', 1, 'OWNER', 'active', 'Bulacan, Philippines')
ON DUPLICATE KEY UPDATE
    `password` = VALUES(`password`),
    `email`    = VALUES(`email`),
    `role`     = VALUES(`role`),
    `status`   = 'active';

-- Check the result
SELECT id, username, email, role, status FROM `users` WHERE username IN ('admin', 'owner');