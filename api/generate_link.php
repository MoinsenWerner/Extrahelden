<?php

require_once __DIR__ . '/lib/security.php';

/**
 * Zentralisiertes Logging in SQLite
 */
function logApiError($message, $expected = null, $file = null, $line = null, $function = null) {
    try {
        $dbPath = __DIR__ . '/../database.sqlite';
        $db = new PDO("sqlite:" . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Erstellt die Tabelle bei Bedarf automatisch
        $db->exec("CREATE TABLE IF NOT EXISTS api_errors (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            error_message TEXT,
            file_path TEXT,
            line_number INTEGER,
            function_name TEXT,
            request_method TEXT,
            user_agent TEXT,
            remote_ip TEXT,
            expected_value TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $stmt = $db->prepare("INSERT INTO api_errors 
            (error_message, file_path, line_number, function_name, request_method, user_agent, remote_ip, expected_value) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $message, 
            $file ?? 'N/A', 
            $line, 
            $function, 
            $_SERVER['REQUEST_METHOD'] ?? 'N/A', 
            $_SERVER['HTTP_USER_AGENT'] ?? 'N/A', 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
            $expected
        ]);
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

// Globaler Error-Handler für PHP-Laufzeitfehler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logApiError("PHP Error [$errno]: $errstr", "Clean Execution", $errfile, $errline);
    return false;
});

$secretKey = '112358';
$basePath  = '/usr/share/icons/cecolor/plain/';
$file = 'linkedhearts.txt';

// Überprüfung der Dateipräsenz vor Link-Erstellung
if (!is_file($basePath . $file)) {
    logApiError("Abbruch: Datei für Link-Generierung existiert nicht", "Existenz in $basePath", __FILE__, __LINE__);
    http_response_code(500);
    exit("Internal Server Error");
}

$expires = time() + 300; 
$ip = normalizeIP(getClientIP());

$data = $file . $expires . $ip;
$sig = hash_hmac('sha256', $data, $secretKey);

$url = "https://www.extrahelden.de/api/download.php?file=$file&expires=$expires&sig=$sig";

echo $url;
