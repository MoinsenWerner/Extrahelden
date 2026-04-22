<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$db = new PDO('sqlite:/var/www/html/offline/includes/users.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $email && $password) {
        // Vorher prüfen ob username oder email schon existiert
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        $count = $stmt->fetchColumn();

        if ($count > 0) {
            $message = 'Benutzername oder E-Mail existiert bereits!';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->execute([$username, $email, $hashedPassword]);

            $message = 'Registrierung erfolgreich! Du kannst dich nun einloggen.';
        }
    } else {
        $message = 'Bitte alle Felder ausfüllen.';
    }
}
?>

<h1>Registrieren</h1>

<form method="post">
    Benutzername: <input type="text" name="username" required><br><br>
    E-Mail: <input type="email" name="email" required><br><br>
    Passwort: <input type="password" name="password" required><br><br>
    <button type="submit">Registrieren</button>
</form>

<p><?php echo htmlspecialchars($message); ?></p>
<p><a href="login.php">Zum Login</a></p>
