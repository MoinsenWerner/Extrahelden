<?php

require_once __DIR__ . '/lib/security.php';

$secretKey = '112358';
$basePath  = '/usr/share/icons/cecolor/';

// Die IP des Clients abrufen
$rawIP = getClientIP(); //

// WICHTIG: Whitelist-Logik
// Wenn Sie den Zugriff auf die IPv6 '2a13:7e80:0:145::' erlauben wollen, 
// muss diese hier eingetragen oder die Prüfung entfernt werden.
$allowedIPs = ['37.44.215.123', '2a13:7e80:0:145::', '88.130.201.18'];

if (!in_array($rawIP, $allowedIPs)) {
    http_response_code(403);
    exit("Access denied: Unauthorized IP $rawIP");
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

// Directory Traversal Schutz
$file = basename($file); //
$fullPath = $basePath . $file;

if (!is_file($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

/**
 * FIX: Normalisierung der IP zur Signaturprüfung.
 * Da generate_link.php die IP normalisiert (IPv6 /64 Kürzung),
 * muss dies hier identisch geschehen.
 */
$normalizedIP = normalizeIP($rawIP); //

// Erwartete Signatur berechnen
$data = $file . $expires . $normalizedIP;
$expectedSig = hash_hmac('sha256', $data, $secretKey); //

if (!hash_equals($expectedSig, $sig)) {
    http_response_code(403);
    exit('Invalid signature');
}

// Download-Header
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $file . '"');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: no-store');

readfile($fullPath);
exit;
