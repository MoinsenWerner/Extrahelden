<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

$username = 'admin';
$password = 'admin'; // danach im UI ändern!
$is_admin = 1;

try {
    $stmt = db()->prepare('SELECT COUNT(*) AS c FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $exists = (int)$stmt->fetch()['c'] > 0;

    if ($exists) {
        echo "Nutzer '{$username}' existiert bereits.";
        exit;
    }

    $stmt = db()->prepare('INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $is_admin]);

    echo "Admin-Nutzer '{$username}' angelegt. Bitte sofort Passwort ändern!";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Fehler: " . htmlspecialchars($e->getMessage());
}
