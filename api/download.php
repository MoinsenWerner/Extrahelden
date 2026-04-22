<?php

require_once __DIR__ . '/lib/security.php';

/**
 * Zentralisiertes Logging in SQLite
 */
function logApiError($message, $expected = null, $file = null, $line = null, $function = null) {
    try {
        $dbPath = __DIR__ . '/var/www/html/database.sqlite';
        $db = new PDO("sqlite:" . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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
            $message, $file, $line, $function, 
            $_SERVER['REQUEST_METHOD'] ?? 'N/A', 
            $_SERVER['HTTP_USER_AGENT'] ?? 'N/A', 
            $_SERVER['REMOTE_ADDR'] ?? 'unknown', 
            $expected
        ]);
    } catch (Exception $e) {
        error_log("Logging failed: " . $e->getMessage());
    }
}

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    logApiError("PHP Runtime Error: $errstr", "Code execution without warnings", $errfile, $errline);
    return false;
});

$secretKey = '112358';
$basePath  = '/usr/share/icons/cecolor/plain/';

$rawIP = getClientIP();
$allowedIPs = ['37.44.215.123', '2a13:7e80:0:145::', '88.130.201.18', '2003:d3:872a:7000:d970:136b:71e:b0db', '2003:d3:8717:a800:dcb2:f915:eed3:a37e'];

// 1. IP Check
if (!in_array($rawIP, $allowedIPs)) {
    logApiError("Unauthorized IP: $rawIP", "Allowed: " . implode(',', $allowedIPs), __FILE__, __LINE__);
    http_response_code(403);
    exit("Access denied. " . $rawIP);
}

$file    = $_GET['file'] ?? '';
$expires = $_GET['expires'] ?? '';
$sig     = $_GET['sig'] ?? '';

// 2. Parameter Check
if (!$file || !$expires || !$sig) {
    logApiError("Request parameters incomplete", "file, expires, sig", __FILE__, __LINE__);
    http_response_code(400);
    exit('Invalid request');
}

// 3. Expiration Check
if (!ctype_digit($expires) || $expires < time()) {
    logApiError("Link expired", "Current time < $expires", __FILE__, __LINE__);
    http_response_code(403);
    exit('Link expired');
}

$file = basename($file);
$fullPath = $basePath . $file;

// 4. File Existence
if (!is_file($fullPath)) {
    logApiError("File not found on disk: $file", "File exists in $basePath", __FILE__, __LINE__);
    http_response_code(404);
    exit('File not found');
}

$normalizedIP = normalizeIP($rawIP);
$data = $file . $expires . $normalizedIP;
$expectedSig = hash_hmac('sha256', $data, $secretKey);

// 5. Signature Check
if (!hash_equals($expectedSig, $sig)) {
    logApiError("Signature mismatch", "Expected Hash: $expectedSig", __FILE__, __LINE__);
    http_response_code(403);
    exit('Invalid signature');
}

// Anzeige-Logik
if ($file === 'linkedhearts.txt') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: inline; filename="' . $file . '"');
} else {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
}

header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store');

if (!readfile($fullPath)) {
    logApiError("IO Error: readfile failed", "Successful stream", __FILE__, __LINE__);
}
exit;
