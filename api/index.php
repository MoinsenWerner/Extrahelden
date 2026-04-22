<?php
header('Content-Type: application/json');

// Ordner für die Video-Segmente definieren
$storageDir = __DIR__ . '/../storage/recordings/';
if (!file_exists($storageDir)) {
    mkdir($storageDir, 0777, true);
}

$state = $_GET['state'] ?? '';
$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

// Pfad für Session-Logs (optional zur Nachverfolgung)
$sessionLog = __DIR__ . '/../storage/sessions.json';

if ($state === 'start') {
    // Entspricht api.py -> start_session()
    $user = $data['user'] ?? 'unknown';
    $sessionId = bin2hex(random_bytes(16)); // Erzeugt die vom Client erwartete session_id
    
    $response = [
        'session_id' => $sessionId,
        'started_at' => gmdate('Y-m-d H:i:s')
    ];
    
    echo json_encode($response);
    exit;

} elseif ($state === 'finish') {
    // Entspricht api.py -> finish_session()
    $sessionId = $data['session_id'] ?? null;
    
    if (!$sessionId) {
        http_response_code(400);
        echo json_encode(['error' => 'No session_id provided']);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Session closed',
        'session_id' => $sessionId
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid state']);
