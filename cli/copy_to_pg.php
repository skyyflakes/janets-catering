<?php
/**
 * Copies every row from the MySQL database (XAMPP) into a PostgreSQL database created from
 * database/postgres/janets_catering_pg.sql (for example Supabase). Run on the XAMPP computer:
 *
 *   set TARGET_HOST=aws-0-xx.pooler.supabase.com   (PowerShell: $env:TARGET_HOST = '...')
 *   set TARGET_PORT=5432
 *   set TARGET_DB=postgres
 *   set TARGET_USER=postgres.xxxxxxxx
 *   set TARGET_PASS=your-database-password
 *   php -d extension=pdo_pgsql cli/copy_to_pg.php
 *
 * The target tables must be empty. Activity-log triggers are paused while copying so the copy itself is not logged,
 * and the ID counters are moved past the copied rows so new records continue the same numbering.
 * Uploaded files (receipts, photos) are copied into the stored_files table so the hosted system can show them.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../config/database.php';

$my = getDBConnection();
if (!$my || jq_is_pg()) exit("The source must be the MySQL database (DB_DRIVER must be mysql).\n");
$env = static fn(string $k, string $d = '') => getenv($k) !== false && getenv($k) !== '' ? getenv($k) : $d;
if ($env('TARGET_HOST') === '') exit("Set TARGET_HOST, TARGET_PORT, TARGET_DB, TARGET_USER and TARGET_PASS first (see the top of this file).\n");
$pg = new PDO('pgsql:host=' . $env('TARGET_HOST') . ';port=' . $env('TARGET_PORT', '5432') . ';dbname=' . $env('TARGET_DB', 'postgres') . ';sslmode=' . $env('TARGET_SSL', 'require'),
    $env('TARGET_USER', 'postgres'), $env('TARGET_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true]);
$pg->exec("SET TIME ZONE 'Asia/Manila'");

$pgTables = $pg->query("SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$myTables = $my->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
$tables = array_values(array_intersect($myTables, $pgTables));

// Parents before children (foreign keys)
$deps = array_fill_keys($tables, []);
foreach ($my->query("SELECT TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL") as $r) {
    if ($r['TABLE_NAME'] !== $r['REFERENCED_TABLE_NAME'] && isset($deps[$r['TABLE_NAME']])) $deps[$r['TABLE_NAME']][] = $r['REFERENCED_TABLE_NAME'];
}
$order = [];
$visit = static function ($t) use (&$visit, &$order, $deps) { if (in_array($t, $order, true)) return; foreach ($deps[$t] ?? [] as $p) $visit($p); $order[] = $t; };
foreach ($tables as $t) $visit($t);

foreach ($order as $t) {
    // system_settings only holds the schema version written by the schema file; the copy replaces it.
    if ($t !== 'system_settings' && (int)$pg->query("SELECT COUNT(*) FROM \"$t\"")->fetchColumn() > 0) exit("Table $t in the target is not empty. Copy into a freshly created database.\n");
}
$pg->exec("DELETE FROM system_settings");

$pg->beginTransaction();
foreach ($order as $t) $pg->exec("ALTER TABLE \"$t\" DISABLE TRIGGER USER");
$total = 0;
foreach ($order as $t) {
    $cols = $pg->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = " . $pg->quote($t) . " ORDER BY ordinal_position")->fetchAll(PDO::FETCH_COLUMN);
    $myCols = $my->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $my->quote($t))->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_values(array_intersect($cols, $myCols));
    $list = implode(', ', array_map(static fn($c) => "\"$c\"", $cols));
    $rows = $my->query("SELECT " . implode(', ', array_map(static fn($c) => "`$c`", $cols)) . " FROM `$t`", PDO::FETCH_NUM);
    $batch = [];
    $n = 0;
    $flush = static function () use (&$batch, $pg, $t, $list, $cols) {
        if (!$batch) return;
        $ph = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';
        $st = $pg->prepare("INSERT INTO \"$t\" ($list) OVERRIDING SYSTEM VALUE VALUES " . implode(', ', array_fill(0, count($batch), $ph)));
        $st->execute(array_merge(...$batch));
        $batch = [];
    };
    foreach ($rows as $row) {
        $batch[] = array_map(static fn($v) => ($v === '0000-00-00 00:00:00' || $v === '0000-00-00') ? null : $v, $row);
        $n++;
        if (count($batch) >= 500) $flush();
    }
    $flush();
    // Identity counters continue after the copied rows
    foreach ($pg->query("SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = " . $pg->quote($t) . " AND is_identity = 'YES'")->fetchAll(PDO::FETCH_COLUMN) as $c) {
        $pg->query("SELECT setval(pg_get_serial_sequence('\"$t\"', '$c'), COALESCE((SELECT MAX(\"$c\") FROM \"$t\"), 0) + 1, false)");
    }
    printf("%-26s %7d rows\n", $t, $n);
    $total += $n;
}
foreach ($order as $t) $pg->exec("ALTER TABLE \"$t\" ENABLE TRIGGER USER");

// Uploaded files: the hosted system reads them from the database
$root = realpath(__DIR__ . '/..');
$files = 0;
if (in_array('stored_files', $pgTables, true)) {
    $ins = $pg->prepare("INSERT INTO stored_files (path, mime, size, data) VALUES (?, ?, ?, ?) ON CONFLICT (path) DO NOTHING");
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/uploads", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || str_starts_with($f->getFilename(), '.')) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f->getPathname()) ?: 'application/octet-stream';
        $ins->bindValue(1, $rel); $ins->bindValue(2, $mime); $ins->bindValue(3, $f->getSize(), PDO::PARAM_INT);
        $ins->bindValue(4, file_get_contents($f->getPathname()), PDO::PARAM_LOB);
        $ins->execute();
        $files++;
    }
}
$pg->commit();
echo "Done: $total rows in " . count($order) . " tables, $files uploaded files.\n";
