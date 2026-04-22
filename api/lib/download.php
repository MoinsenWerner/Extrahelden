<?php


require_once __DIR__ . '/lib/security.php';

$allowedIP = '37.44.215.123';

$clientIP = getClientIP();

if ($clientIP !== $allowedIP) {
    http_response_code(403);
    exit('Access denied');
}

$secretKey = '112358';
$basePath  = '/usr/share/icons/cecolor/';

// Client-IP sauber ermitteln
function getClientIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

$file    = $_GET['file'] ?? '';
$expires = $_GET['expires'] ?? '';
$sig     = $_GET['sig'] ?? '';

if (!$file || !$expires || !$sig) {
    http_response_code(400);
    exit('Invalid request');
}

if (!ctype_digit($expires) || $expires < time()) {
    http_response_code(403);
    exit('Link expired');
}

// Directory Traversal verhindern
$file = basename($file);
$fullPath = $basePath . $file;

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

$ip = getClientIP();

// Erwartete Signatur berechnen
$data = $file . $expires . $ip;
$expectedSig = hash_hmac('sha256', $data, $secretKey);

if (!hash_equals($expectedSig, $sig)) {
    http_response_code(403);
    exit('Invalid signature');
}

// Optional: Download-Header
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store');

readfile($fullPath);
exit;
