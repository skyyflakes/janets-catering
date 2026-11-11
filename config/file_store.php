<?php
/**
 * Uploaded files (receipts, attachments, menu, profile and survey photos).
 * On XAMPP they are ordinary files under uploads/. When hosted (Vercel), the server's disk is temporary,
 * so the same paths are kept as rows of the stored_files table instead. Pages always use these functions
 * with the project-relative path stored in the database, e.g. "uploads/menus/package_123.jpg".
 */

/** Absolute path of a project-relative path on XAMPP. */
function jq_file_abs(string $rel): string
{
    return dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $rel), '/');
}

/** Normalised key: "uploads/..." only, never "..". Null when the path is not an upload. */
function jq_file_key(?string $rel): ?string
{
    $rel = ltrim(str_replace('\\', '/', (string)$rel), '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return null;
    }
    $pos = strpos($rel, 'uploads/');
    return $pos === false ? null : substr($rel, $pos);
}

function jq_file_pdo(): ?PDO
{
    static $pdo = null;
    return $pdo ??= getDBConnection();
}

/** Makes an uploads/ folder on XAMPP (nothing to do when hosted). */
function jq_file_dir(string $relDir): void
{
    if (!jq_is_hosted() && !is_dir(jq_file_abs($relDir))) {
        mkdir(jq_file_abs($relDir), 0755, true);
    }
}

/** Stores an uploaded temporary file at $rel. */
function jq_file_save_upload(string $tmp, string $rel): bool
{
    $key = jq_file_key($rel);
    if ($key === null || !is_uploaded_file($tmp)) {
        return false;
    }
    if (!jq_is_hosted()) {
        jq_file_dir(dirname($key));
        return move_uploaded_file($tmp, jq_file_abs($key));
    }
    $data = file_get_contents($tmp);
    if ($data === false) {
        return false;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
    $st = jq_file_pdo()->prepare('INSERT INTO stored_files (path, mime, size, data) VALUES (?, ?, ?, ?)');
    $st->bindValue(1, $key);
    $st->bindValue(2, $mime);
    $st->bindValue(3, strlen($data), PDO::PARAM_INT);
    $st->bindValue(4, $data, PDO::PARAM_LOB);
    return $st->execute();
}

function jq_file_exists(?string $rel): bool
{
    $key = jq_file_key($rel);
    if ($key === null) {
        return $rel !== null && $rel !== '' && !str_contains($rel, '..') && is_file(jq_file_abs($rel)); // e.g. static/images/...
    }
    if (!jq_is_hosted()) {
        return is_file(jq_file_abs($key));
    }
    // One query per page: pages check many photos
    global $jq_file_paths;
    $jq_file_paths ??= array_flip(jq_file_pdo()->query('SELECT path FROM stored_files')->fetchAll(PDO::FETCH_COLUMN));
    return isset($jq_file_paths[$key]);
}

function jq_file_delete(?string $rel): void
{
    $key = jq_file_key($rel);
    if ($key === null) {
        return;
    }
    if (!jq_is_hosted()) {
        @unlink(jq_file_abs($key));
        @rmdir(dirname(jq_file_abs($key))); // only succeeds once the folder is empty
        return;
    }
    jq_file_pdo()->prepare('DELETE FROM stored_files WHERE path = ?')->execute([$key]);
}

/** ['mime' => ..., 'data' => ...] of a stored file, or null. */
function jq_file_read(?string $rel): ?array
{
    $key = jq_file_key($rel);
    if ($key === null) {
        return null;
    }
    if (!jq_is_hosted()) {
        $abs = jq_file_abs($key);
        return is_file($abs) ? ['mime' => (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream', 'data' => file_get_contents($abs)] : null;
    }
    $st = jq_file_pdo()->prepare('SELECT mime, data FROM stored_files WHERE path = ?');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    if (is_resource($row['data'])) {
        $row['data'] = stream_get_contents($row['data']);
    }
    return $row;
}
