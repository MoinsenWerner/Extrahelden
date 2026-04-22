<?php
header('Content-Type: application/json');

// Zielverzeichnis
$uploadBase = __DIR__ . '/../../storage/recordings/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Only POST allowed']);
    exit;
}

// Metadaten aus dem Multipart-Request (uploader.py -> payload)
$sessionId = $_POST['session_id'] ?? 'unknown';
$index     = $_POST['segment_index'] ?? '0';
$user      = $_POST['user'] ?? 'anonymous';

// Erstelle User- und Session-spezifische Ordner (innovative Struktur)
$targetDir = $uploadBase . $user . '/' . $sessionId . '/';
if (!file_exists($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (isset($_FILES['file'])) {
    $file = $_FILES['file'];
    // Dateiname: segment_000001.mp4
    $fileName = "segment_" . str_pad($index, 6, "0", STR_PAD_LEFT) . ".mp4";
    $targetFile = $targetDir . $fileName;

    if (move_uploaded_file($file['tmp_name'], $targetFile)) {
        echo json_encode([
            'status' => 'success',
            'saved_as' => $fileName,
            'index' => $index
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Upload failed']);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'File missing in request']);
}
