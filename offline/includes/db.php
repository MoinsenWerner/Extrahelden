<?php
// Aktiviere Fehleranzeige
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$dbPath = __DIR__ . '/../includes/users.db';

// Datenbankverbindung herstellen
try {
    $db = new SQLite3($dbPath);
    if (!$db) {
        throw new Exception("Datenbankverbindung fehlgeschlagen.");
    }

    // Überprüfen, ob die Tabelle `users` existiert
    $query = "SELECT name FROM sqlite_master WHERE type='table' AND name='users'";
    $result = $db->query($query);
    if ($result->fetchArray() === false) {
        // Tabelle `users` erstellen
        $createTableQuery = "
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                username TEXT NOT NULL UNIQUE,
                password TEXT NOT NULL,
                minecraft_username TEXT
            );
        ";
        $db->exec($createTableQuery);
    }
} catch (Exception $e) {
    die("Fehler: " . $e->getMessage());
}
?>