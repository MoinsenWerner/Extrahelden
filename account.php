<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

$user_id = (int)$_SESSION['user_id'];
$db = db();

/* --- Profildaten laden --- */
$stmt = $db->prepare('SELECT u.username, a.mc_name FROM users u LEFT JOIN applications a ON u.id = a.created_user_id WHERE u.id = ?');
$stmt->execute([$user_id]);
$currentUserData = $stmt->fetch();

/* --- Logik: Username / MC-Name ändern --- */
if (is_post() && ($_POST['action'] ?? '') === 'update_profile') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error'); header('Location: account.php'); exit;
    }

    $newUsername = trim((string)($_POST['new_username'] ?? ''));

    if (strlen($newUsername) < 3) {
        flash('Der Name muss mindestens 3 Zeichen lang sein.', 'error');
    } else {
        try {
            $db->beginTransaction();

            // Prüfen, ob Username bereits vergeben (außer an sich selbst)
            $check = $db->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check->execute([$newUsername, $user_id]);
            
            if ($check->fetch()) {
                flash('Dieser Name ist bereits vergeben.', 'error');
                $db->rollBack();
            } else {
                // 1. Update users Tabelle
                $db->prepare('UPDATE users SET username = ? WHERE id = ?')
                   ->execute([$newUsername, $user_id]);

                // 2. Update applications Tabelle (mc_name)
                $db->prepare('UPDATE applications SET mc_name = ? WHERE created_user_id = ?')
                   ->execute([$newUsername, $user_id]);

                $db->commit();
                $_SESSION['username'] = $newUsername; // Session aktualisieren
                flash('Profil erfolgreich aktualisiert.', 'success');
            }
        } catch (Exception $e) {
            $db->rollBack();
            flash('Fehler beim Speichern: ' . $e->getMessage(), 'error');
        }
    }
    header('Location: account.php'); exit;
}

/* --- Passwort ändern --- */
if (is_post() && ($_POST['action'] ?? '') === 'change_password') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error'); header('Location: account.php'); exit;
    }

    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$user_id]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($current, $row['password_hash'])) {
        flash('Aktuelles Passwort ist falsch.', 'error');
    } elseif ($new !== $confirm) {
        flash('Neues Passwort und Bestätigung stimmen nicht überein.', 'error');
    } elseif (strlen($new) < 8) {
        flash('Neues Passwort muss mindestens 8 Zeichen lang sein.', 'error');
    } else {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
           ->execute([password_hash($new, PASSWORD_DEFAULT), $user_id]);
        session_regenerate_id(true);
        flash('Passwort erfolgreich geändert.', 'success');
    }
    header('Location: account.php'); exit;
}

render_header('Konto – Einstellungen');
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>

<section class="row">
  <div class="card" style="min-width:100%; margin-bottom: 2rem;">
    <h2>Profil anpassen</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="update_profile">
      
      <label>
        Minecraft Name / Benutzername<br>
        <input type="text" name="new_username" value="<?=htmlspecialchars($currentUserData['username'] ?? '')?>" required>
        <small style="display:block; color:#666;">Ändert sowohl deinen Login-Namen als auch deinen hinterlegten MC-Namen.</small>
      </label><br>
      
      <button class="btn btn-primary" type="submit">Profil speichern</button>
    </form>
  </div>

  <div class="card" style="min-width:100%">
    <h2>Passwort ändern</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="change_password">
      <label>Aktuelles Passwort<br><input type="password" name="current_password" required></label><br><br>
      <label>Neues Passwort<br><input type="password" name="new_password" required></label><br><br>
      <label>Neues Passwort bestätigen<br><input type="password" name="confirm_password" required></label><br><br>
      <button class="btn btn-primary" type="submit">Passwort ändern</button>
    </form>
  </div>
</section>

<?php render_footer(); ?>
