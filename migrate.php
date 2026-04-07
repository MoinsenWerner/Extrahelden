<?php
// migrate.php — idempotente Migrationen für fehlende Tabellen/Defaults
declare(strict_types=1);
require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

$pdo = db();
$pdo->beginTransaction();

try {
    // --- Kern-Tabellen sicherstellen ----------------------------------------------------------
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      username TEXT NOT NULL UNIQUE,
      password_hash TEXT NOT NULL,
      is_admin INTEGER NOT NULL DEFAULT 0
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
      published INTEGER NOT NULL DEFAULT 1
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

    /* Abstimmungen (Grundschema ohne kingdom_id; Spalte wird unten ggf. per ALTER ergänzt) */
    CREATE TABLE IF NOT EXISTS votes (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      title TEXT NOT NULL,
      description TEXT,
      type TEXT NOT NULL DEFAULT 'law',      -- 'law' | 'war'
      status TEXT NOT NULL DEFAULT 'open',   -- 'open' | 'closed'
      ends_at TEXT,
      created_by INTEGER NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS vote_options (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      vote_id INTEGER NOT NULL,
      label TEXT NOT NULL,
      value TEXT,
      position INTEGER NOT NULL DEFAULT 0,
      FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
    );
    CREATE UNIQUE INDEX IF NOT EXISTS idx_voteopt_sort ON vote_options(vote_id, position);

    CREATE TABLE IF NOT EXISTS vote_ballots (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      vote_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      option_id INTEGER NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      FOREIGN KEY (option_id) REFERENCES vote_options(id) ON DELETE CASCADE
    );
    CREATE UNIQUE INDEX IF NOT EXISTS idx_ballot_unique ON vote_ballots(vote_id, user_id);

    /* Königreiche & Mitgliedschaften */
    CREATE TABLE IF NOT EXISTS kingdoms (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL UNIQUE,
      description TEXT,
      created_by INTEGER NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
    );

    CREATE TABLE IF NOT EXISTS kingdom_memberships (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      kingdom_id INTEGER NOT NULL,
      user_id INTEGER NOT NULL,
      role TEXT NOT NULL,
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (kingdom_id) REFERENCES kingdoms(id) ON DELETE CASCADE,
      FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
      UNIQUE(kingdom_id, user_id)
    );

    /* Rollen-Guard (erlaubte Rollen) */
    CREATE TABLE IF NOT EXISTS _km_role_guard(role TEXT PRIMARY KEY);
    ");

    // Rollen-Werte (beide Schreibweisen für (Mi|ie)nenarbeiter erlauben)
    $insRole = $pdo->prepare("INSERT OR IGNORE INTO _km_role_guard(role) VALUES(?)");
    foreach (['König','Ritter','Spion','Bote','Bauer','Minenarbeiter','Mienenarbeiter'] as $r) {
        $insRole->execute([$r]);
    }

    // Trigger & Indizes für Mitgliedschaften
    $pdo->exec("
    CREATE TRIGGER IF NOT EXISTS km_role_check_ins
    BEFORE INSERT ON kingdom_memberships
    FOR EACH ROW
    BEGIN
      SELECT CASE WHEN NEW.role NOT IN (SELECT role FROM _km_role_guard)
        THEN RAISE(ABORT,'INVALID_ROLE') END;
    END;

    CREATE TRIGGER IF NOT EXISTS km_role_check_upd
    BEFORE UPDATE OF role ON kingdom_memberships
    FOR EACH ROW
    BEGIN
      SELECT CASE WHEN NEW.role NOT IN (SELECT role FROM _km_role_guard)
        THEN RAISE(ABORT,'INVALID_ROLE') END;
    END;

    /* Max. 2 Königreiche pro Nutzer */
    CREATE TRIGGER IF NOT EXISTS km_max2_ins
    BEFORE INSERT ON kingdom_memberships
    FOR EACH ROW
    WHEN (SELECT COUNT(*) FROM kingdom_memberships WHERE user_id = NEW.user_id) >= 2
    BEGIN
      SELECT RAISE(ABORT,'USER_MAX_2_KINGDOMS');
    END;

    CREATE TRIGGER IF NOT EXISTS km_max2_upd
    BEFORE UPDATE OF user_id ON kingdom_memberships
    FOR EACH ROW
    WHEN (SELECT COUNT(*) FROM kingdom_memberships WHERE user_id = NEW.user_id AND id <> NEW.id) >= 2
    BEGIN
      SELECT RAISE(ABORT,'USER_MAX_2_KINGDOMS');
    END;

    CREATE INDEX IF NOT EXISTS idx_km_user ON kingdom_memberships(user_id);
    CREATE INDEX IF NOT EXISTS idx_km_kingdom ON kingdom_memberships(kingdom_id);
    ");

    // --- votes.kingdom_id nachrüsten (nur wenn Spalte fehlt) -------------------------------
    $cols = $pdo->query("PRAGMA table_info(votes)")->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static fn($r) => $r['name'], $cols);
    if (!in_array('kingdom_id', $colNames, true)) {
        $pdo->exec("ALTER TABLE votes ADD COLUMN kingdom_id INTEGER NULL REFERENCES kingdoms(id) ON DELETE SET NULL");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_votes_kingdom ON votes(kingdom_id)");
    }

    // --- Settings / Bewerbungen (wie gehabt) ------------------------------------------------
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS site_settings (
      key TEXT PRIMARY KEY,
      value TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS applications (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      youtube_url TEXT NOT NULL,
      youtube_video_id TEXT,
      mc_name TEXT NOT NULL,
      mc_uuid TEXT,
      discord_name TEXT NOT NULL,
      status TEXT NOT NULL DEFAULT 'pending',
      created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Defaults nur setzen, wenn nicht vorhanden
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO site_settings(key, value) VALUES(?, ?)");
    $stmt->execute(['apply_enabled', '0']);
    $stmt->execute(['apply_title', 'Projekt-Anmeldung']);

    $pdo->commit();

    echo "OK: Migration abgeschlossen.\n";
    echo "- Tabellen: users, documents, user_documents, posts, minecraft_servers, server_status_cache, votes(+options+ballots), kingdoms, kingdom_memberships, site_settings, applications\n";
    echo "- Indizes/Trigger: Mitgliedschaften + votes.kingdom_id\n";
    echo "- Defaults: site_settings.apply_enabled=0, apply_title='Projekt-Anmeldung'\n";

    // OPTIONAL: Admin-Bootstrap über URL-Parameter ?bootstrap_admin=1
    if (isset($_GET['bootstrap_admin']) && $_GET['bootstrap_admin'] === '1') {
        $pdo->beginTransaction();
        $exists = $pdo->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        $exists->execute(['admin']);
        if (!$exists->fetch()) {
            $pdo->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?,?,1)")
                ->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);
            echo "Admin-Benutzer 'admin' mit Passwort 'admin' angelegt. Bitte sofort ändern.\n";
        } else {
            echo "Hinweis: Benutzer 'admin' existiert bereits.\n";
        }
        $pdo->commit();
    }

} catch (Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo "FEHLER: " . $e->getMessage() . "\n";
    exit;
}
