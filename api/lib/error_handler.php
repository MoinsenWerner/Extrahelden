<?php
// lib/error_handler.php

function logApiError($message, $expected = null, $file = null, $line = null, $function = null) {
    try {
        $dbPath = __DIR__ . '/../database.sqlite';
        $db = new PDO("sqlite:" . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Tabelle erstellen, falls nicht vorhanden
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

        $sql = "INSERT INTO api_errors 
                (error_message, file_path, line_number, function_name, request_method, user_agent, remote_ip, expected_value) 
                VALUES (:msg, :file, :line, :func, :method, :ua, :ip, :exp)";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':msg'    => $message,
            ':file'   => $file ?? 'N/A',
            ':line'   => $line,
            ':func'   => $function,
            ':method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            ':ua'     => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':exp'    => $expected
        ]);
    } catch (PDOException $e) {
        // Notfall-Logging in das Server-Log, falls SQLite-Schreibzugriff fehlschlägt
        error_log("Critical: Could not write to SQLite error log. " . $e->getMessage());
    }
}

// Globaler Error-Handler für PHP-Laufzeitfehler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logApiError("PHP Error [$errno]: $errstr", "Clean Execution", $errfile, $errline);
    return false; 
});
