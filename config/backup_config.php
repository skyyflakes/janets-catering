<?php
/**
 * Database Backup configuration – Janet's Quality Catering System
 *
 * BACKUP_DIR must be OUTSIDE the public web root so backup files can never be downloaded directly.
 * Default: one level above htdocs  ->  C:\xampp\janets_catering_backups  (XAMPP)
 *                                      /var/www/janets_catering_backups  (typical Linux)
 * Override with the JQ_BACKUP_DIR environment variable or by editing the constant below.
 */

if (!defined('BACKUP_DIR')) {
    define('BACKUP_DIR', getenv('JQ_BACKUP_DIR') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'janets_catering_backups');
}

/**
 * Folder that contains mysqldump / mysql. Leave empty to auto-detect
 * (C:\xampp\mysql\bin, /usr/bin, /usr/local/mysql/bin, /opt/lampp/bin, PATH …).
 */
if (!defined('MYSQL_BIN_DIR')) {
    define('MYSQL_BIN_DIR', getenv('JQ_MYSQL_BIN_DIR') ?: '');
}

/** Largest backup file accepted by "Upload backup" (also limited by php.ini upload_max_filesize). */
if (!defined('BACKUP_MAX_UPLOAD_MB')) {
    define('BACKUP_MAX_UPLOAD_MB', 200);
}

/** Maximum seconds a single dump / import may run. */
if (!defined('BACKUP_PROCESS_TIMEOUT')) {
    define('BACKUP_PROCESS_TIMEOUT', 1800);
}
