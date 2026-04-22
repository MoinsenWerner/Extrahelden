<?php
// db.php
declare(strict_types=1);

// --- Grundeinstellungen ---
$BASE_PATH = __DIR__;
$DB_FILE   = $BASE_PATH . '/database.sqlite';
$UPLOADS   = $BASE_PATH . '/uploads';

if (!is_dir($UPLOADS)) {
    @mkdir($UPLOADS, 0775, true);
    @chmod($UPLOADS, 0775);
}

// --- DB-Verbindung ---
try {
    $pdo = new PDO('sqlite:' . $DB_FILE, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    die('DB-Verbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()));
}

// --- Tabellen (Basis) ---
$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  is_admin INTEGER NOT NULL DEFAULT 0,
  discord_name TEXT,
  calendar_color TEXT
);

CREATE TABLE IF NOT EXISTS passkeys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id TEXT NOT NULL UNIQUE,
    public_key TEXT NOT NULL,
    sign_count INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  filename TEXT NOT NULL,
  path TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS user_documents (
  user_id INTEGER NOT NULL,
  document_id INTEGER NOT NULL,
  PRIMARY KEY (user_id, document_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS posts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  content TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  published INTEGER NOT NULL DEFAULT 1,
  image_path TEXT
);

CREATE TABLE IF NOT EXISTS minecraft_servers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  host TEXT NOT NULL,
  port INTEGER NOT NULL DEFAULT 25565,
  enabled INTEGER NOT NULL DEFAULT 1,
  sort_order INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS server_status_cache (
  server_id INTEGER PRIMARY KEY,
  online INTEGER NOT NULL,
  players_online INTEGER,
  players_max INTEGER,
  version TEXT,
  latency_ms REAL,
  raw_json TEXT,
  checked_at TEXT NOT NULL,
  FOREIGN KEY (server_id) REFERENCES minecraft_servers(id) ON DELETE CASCADE
);

-- Key/Value-Settings
CREATE TABLE IF NOT EXISTS site_settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- Bewerbungen
CREATE TABLE IF NOT EXISTS applications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  youtube_url TEXT NOT NULL,
  youtube_video_id TEXT,
  mc_name TEXT NOT NULL,
  mc_uuid TEXT,
  discord_name TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  generated_password TEXT,
  created_user_id INTEGER,
  project_name TEXT,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
");

// --- Migration: Public-Flag für World Downloads nachrüsten ---
$cols = $pdo->query("PRAGMA table_info(documents)")->fetchAll(PDO::FETCH_ASSOC);
$names = array_map(fn($r)=>$r['name'], $cols);
if (!in_array('is_public', $names, true)) {
    $pdo->exec("ALTER TABLE documents ADD COLUMN is_public INTEGER NOT NULL DEFAULT 0");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_documents_is_public ON documents(is_public)");
}

// --- Helper ---
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function db(): PDO { global $pdo; return $pdo; }

function require_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
}
function require_admin(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    if (empty($_SESSION['user_id']) || empty($_SESSION['is_admin']) || (int)$_SESSION['is_admin'] !== 1) {
        header('Location: /login.php'); exit;
    }
}

function get_setting(string $key, ?string $default = null): ?string {
    $st = db()->prepare("SELECT value FROM site_settings WHERE key = ?");
    $st->execute([$key]);
    $row = $st->fetch();
    return $row ? (string)$row['value'] : $default;
}
function set_setting(string $key, string $value): void {
    db()->prepare("
        INSERT INTO site_settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value=excluded.value
    ")->execute([$key, $value]);
}
function ensure_setting_default(string $key, string $default): void {
    if (get_setting($key, null) === null) set_setting($key, $default);
}

// Defaults
ensure_setting_default('apply_enabled', '0');
ensure_setting_default('apply_title', 'Projekt-Anmeldung');

/** Liefert die aktuelle User-ID aus der Session oder 0 */
function current_user_id(): int {
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
}

/** Liefert den aktuellen Nutzer (id, username, is_admin) oder null */
function current_user(): ?array {
    $uid = current_user_id();
    if ($uid <= 0) return null;
    $st = db()->prepare('SELECT id, username, is_admin FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}


/* --- Automatisierungs-Logik (Tasker-Prinzip) --- */

if (!function_exists('http_json')) {
    function http_json(string $method, string $url, array $headers, array $body): ?array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $res];
    }
}


function trigger_auto_task(string $trigger_key, array $data = []) {
    $rules = json_decode(get_setting('auto_rules', '[]'), true) ?: [];
    $events = json_decode(get_setting('custom_discord_events', '[]'), true) ?: [];
    $token = get_setting('discord_bot_token', '');

    if (empty($token)) return;

    foreach ($rules as $rule) {
        if (!empty($rule['active']) && $rule['trigger'] === $trigger_key) {
            $event = $events[$rule['event_id']] ?? null;
            if ($event && !empty($event['channel'])) {
                $message = $event['message'];
                
                // Ersetzt Platzhalter wie {name} durch $data['name']
                foreach ($data as $key => $val) {
                    $message = str_replace('{' . $key . '}', (string)$val, $message);
                }

                $ch = curl_init("https://discord.com/api/v10/channels/{$event['channel']}/messages");
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bot '.$token, 'Content-Type: application/json']);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['content' => $message]));
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
            }
        }
    }
}
