<?php
declare(strict_types=1);

// Keine Ausgabe vor Headern!
ob_start();

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Datenbank-Tabelle für Passkeys sicherstellen
try {
    db()->exec('CREATE TABLE IF NOT EXISTS passkeys (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        credential_id TEXT NOT NULL UNIQUE,
        public_key TEXT NOT NULL,
        sign_count INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');
} catch (Throwable $e) {
    error_log('DB Error (Passkeys Table): ' . $e->getMessage());
}

// Session sicherstellen
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// Bereits eingeloggt? -> weiter zur Startseite
if (!empty($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// CSRF bereitstellen
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf'];

// POST: Loginversuch via Passwort
if (is_post()) {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error');
        header('Location: login.php'); exit;
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        flash('Bitte Benutzername und Passwort ausfüllen.', 'error');
        header('Location: login.php'); exit;
    }

    try {
        $stmt = db()->prepare('SELECT id, username, password_hash, is_admin FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        if ($row && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id']  = (int)$row['id'];
            $_SESSION['username'] = (string)$row['username'];
            $_SESSION['is_admin'] = (int)$row['is_admin'];

            // PRÜFUNG: Hat der User schon einen Passkey?
            $pkStmt = db()->prepare('SELECT id FROM passkeys WHERE user_id = ? LIMIT 1');
            $pkStmt->execute([$_SESSION['user_id']]);
            if (!$pkStmt->fetch()) {
                // Flag setzen, um im UI nach Passkey-Erstellung zu fragen
                $_SESSION['ask_for_passkey'] = true;
            }

            flash('Erfolgreich angemeldet.', 'success');
            header('Location: index.php'); exit;
        } else {
            flash('Ungültige Zugangsdaten.', 'error');
            header('Location: login.php'); exit;
        }
    } catch (Throwable $e) {
        error_log('LOGIN ERROR: ' . $e->getMessage());
        flash('Interner Fehler beim Login.', 'error');
        header('Location: login.php'); exit;
    }
}

// GET: Formular rendern
render_header('Login', false);
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>

<section class="row">
  <div class="card" style="min-width:50%">
    <h2>Anmelden</h2>
    <form method="post" autocomplete="off" id="loginForm">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <label>Benutzername<br><input type="text" name="username" required></label><br><br>
      <label>Passwort<br><input type="password" name="password" required></label><br><br>
      <button class="btn btn-primary" type="submit">Login</button>
      <button class="btn" type="button" disabled onclick="loginWithPasskey()" style="margin-left:8px">Mit Passkey anmelden</button>
    </form>
    <p style="margin-top:12px"><a class="btn" href="index.php">← Zurück</a></p>
  </div>
</section>

<script>
/**
 * Hilfsfunktionen zur Konvertierung von Binärdaten für WebAuthn
 */
const bufferToBase64 = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)));
const base64ToBuffer = (base64) => Uint8Array.from(atob(base64), c => c.charCodeAt(0));

async function registerPasskey() {
    try {
        const res = await fetch('passkey_handler.php?action=get_reg_options');
        const options = await res.json();

        // Binär-Felder konvertieren
        options.challenge = base64ToBuffer(options.challenge);
        options.user.id = base64ToBuffer(options.user.id);

        const credential = await navigator.credentials.create({ publicKey: options });

        const body = {
            id: credential.id,
            rawId: bufferToBase64(credential.rawId),
            type: credential.type,
            response: {
                attestationObject: bufferToBase64(credential.response.attestationObject),
                clientDataJSON: bufferToBase64(credential.response.clientDataJSON)
            }
        };

        const verifyRes = await fetch('passkey_handler.php?action=verify_reg', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });

        if (verifyRes.ok) {
            alert('Passkey erfolgreich verknüpft!');
            location.href = 'index.php';
        } else {
            alert('Fehler bei der Verifizierung.');
        }
    } catch (err) {
        console.error('Passkey Error:', err);
    }
}

async function loginWithPasskey() {
    // Logik für den Login per Passkey (erfordert passkey_handler.php?action=get_login_options)
    // Ähnlich wie registerPasskey, nutzt aber navigator.credentials.get
    alert('Passkey-Login-Funktion muss im passkey_handler implementiert sein.');
}
</script>

<?php 
// Falls der User gerade eingeloggt wurde und keinen Passkey hat, Banner einblenden
// (Hinweis: Normalerweise wird hier bereits auf index.php umgeleitet. 
//  Diese Abfrage ist nützlich, falls Sie die Logik auf der Zielseite index.php einbauen möchten).
render_footer();
ob_end_flush();
