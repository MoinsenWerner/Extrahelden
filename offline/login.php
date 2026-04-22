<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$db = new PDO('sqlite:/var/www/html/offline/includes/users.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? ''); // E-Mail oder Username
    $password = $_POST['password'] ?? '';

    // Suche User → entweder Username ODER E-Mail
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        header('Location: project_configurator.php');
        exit;
    } else {
        $message = 'Login fehlgeschlagen. Bitte überprüfen.';
    }
}
?>

<h1>Login</h1>

<form method="post">
    Benutzername oder E-Mail: <input type="text" name="login" required><br><br>
    Passwort: <input type="password" name="password" required><br><br>
    <button type="submit">Login</button>
</form>

<p><?php echo htmlspecialchars($message); ?></p>
<p><a href="register.php">Noch kein Account? Registrieren!</a></p>
