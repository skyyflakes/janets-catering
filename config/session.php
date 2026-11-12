<?php
/**
 * Starts the PHP session with secure cookie settings. Require this instead of calling session_start().
 * When hosted (Vercel + Supabase), sessions are kept in the app_sessions table, because each request may run
 * on a different short-lived server and files saved on one are not seen by the next. XAMPP keeps using files.
 */
require_once __DIR__ . '/database.php';

if (!class_exists('JqDbSessionHandler')) {
    class JqDbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
    {
        private ?PDO $pdo = null;

        private function db(): PDO
        {
            if (!$this->pdo) {
                $this->pdo = getDBConnection();
                if (!$this->pdo) {
                    throw new RuntimeException('Session storage is unavailable.');
                }
            }
            return $this->pdo;
        }

        public function open(string $path, string $name): bool { return true; }
        public function close(): bool { return true; }

        public function read(string $id): string|false
        {
            $st = $this->db()->prepare('SELECT data FROM app_sessions WHERE id = ? AND updated_at > ?');
            $st->execute([$id, time() - (int)ini_get('session.gc_maxlifetime')]);
            $v = $st->fetchColumn();
            return $v === false ? '' : (string)$v;
        }

        public function write(string $id, string $data): bool
        {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack(); // a page that stopped mid-change must not take the session down with it
            }
            $this->db()->prepare(jq_is_pg()
                ? 'INSERT INTO app_sessions (id, data, updated_at) VALUES (?, ?, ?) ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data, updated_at = EXCLUDED.updated_at'
                : 'INSERT INTO app_sessions (id, data, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)')
                ->execute([$id, $data, time()]);
            return true;
        }

        public function destroy(string $id): bool
        {
            $this->db()->prepare('DELETE FROM app_sessions WHERE id = ?')->execute([$id]);
            return true;
        }

        public function gc(int $max_lifetime): int|false
        {
            $st = $this->db()->prepare('DELETE FROM app_sessions WHERE updated_at < ?');
            $st->execute([time() - $max_lifetime]);
            return $st->rowCount();
        }

        // use_strict_mode: only ids this server created are accepted
        public function validateId(string $id): bool
        {
            $st = $this->db()->prepare('SELECT 1 FROM app_sessions WHERE id = ?');
            $st->execute([$id]);
            return (bool)$st->fetchColumn();
        }

        public function updateTimestamp(string $id, string $data): bool
        {
            $this->db()->prepare('UPDATE app_sessions SET updated_at = ? WHERE id = ?')->execute([time(), $id]);
            return true;
        }
    }
}

if (session_status() === PHP_SESSION_NONE) {
    $jq_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');   // refuse session ids the server did not create
    ini_set('session.use_only_cookies', '1');  // never accept a session id from the URL
    if (jq_is_hosted()) {
        ini_set('session.gc_maxlifetime', '7200');
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_set_save_handler(new JqDbSessionHandler(), true);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $jq_https,
        'httponly' => true,                    // page scripts cannot read the cookie
        'samesite' => 'Lax',                   // other sites cannot post with it
    ]);
    session_start();
}
