<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$storageRoot = getenv('REC_SYNC_STORAGE') ?: dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'storage';

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    return rtrim($path ?: '/', '/');
}

function request_state(): string
{
    return trim((string) ($_GET['state'] ?? ''));
}

function request_json(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sanitize_name(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9._-]+/', '_', $value);
    return trim($value ?: 'anonymous', '_');
}

function ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        json_response(['error' => 'unable to create directory', 'path' => $path], 500);
    }
}

function recordings_root(string $storageRoot, string $user): string
{
    return $storageRoot . DIRECTORY_SEPARATOR . 'recordings' . DIRECTORY_SEPARATOR . sanitize_name($user);
}

function session_dir(string $storageRoot, string $user, string $sessionId): string
{
    return recordings_root($storageRoot, $user) . DIRECTORY_SEPARATOR . $sessionId;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = request_path();
$state = request_state();

if ($method === 'POST' && $path === '/api' && $state === 'start') {
    $data = request_json();
    $user = $data['user'] ?? 'anonymous';
    $sessionId = bin2hex(random_bytes(16));
    $startedAt = gmdate(DATE_ATOM);
    $sessionPath = session_dir($storageRoot, $user, $sessionId);
    ensure_dir($sessionPath);
    file_put_contents(
        $sessionPath . DIRECTORY_SEPARATOR . 'session.json',
        json_encode([
            'session_id' => $sessionId,
            'user' => $user,
            'started_at' => $startedAt,
            'status' => 'recording',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    json_response([
        'session_id' => $sessionId,
        'started_at' => $startedAt,
        'upload_url' => '/api/segments/upload',
    ]);
}

if ($method === 'POST' && $path === '/api/segments/upload') {
    $sessionId = trim((string) ($_POST['session_id'] ?? ''));
    $segmentIndex = (int) ($_POST['segment_index'] ?? -1);
    $user = trim((string) ($_POST['user'] ?? 'anonymous'));
    if ($sessionId === '' || $segmentIndex < 0 || !isset($_FILES['file'])) {
        json_response(['error' => 'missing required fields'], 422);
    }
    $sessionPath = session_dir($storageRoot, $user, $sessionId);
    ensure_dir($sessionPath);
    $segmentName = sprintf('seg_%06d.mp4', $segmentIndex);
    $targetPath = $sessionPath . DIRECTORY_SEPARATOR . $segmentName;
    if (file_exists($targetPath)) {
        json_response(['status' => 'duplicate', 'segment' => $segmentName]);
    }
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        json_response(['error' => 'unable to store segment'], 500);
    }
    $metaPath = $sessionPath . DIRECTORY_SEPARATOR . sprintf('seg_%06d.json', $segmentIndex);
    file_put_contents(
        $metaPath,
        json_encode([
            'session_id' => $sessionId,
            'segment_index' => $segmentIndex,
            'timestamp_start' => $_POST['timestamp_start'] ?? null,
            'timestamp_end' => $_POST['timestamp_end'] ?? null,
            'user' => $user,
            'stored_at' => gmdate(DATE_ATOM),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    json_response(['status' => 'stored', 'segment' => $segmentName]);
}

if ($method === 'POST' && $path === '/api' && $state === 'finish') {
    $data = request_json();
    $sessionId = trim((string) ($data['session_id'] ?? ''));
    $user = trim((string) ($data['user'] ?? 'anonymous'));
    if ($sessionId === '') {
        json_response(['error' => 'missing session_id'], 422);
    }
    $sessionPath = session_dir($storageRoot, $user, $sessionId);
    if (!is_dir($sessionPath)) {
        json_response(['error' => 'session not found'], 404);
    }
    $segments = glob($sessionPath . DIRECTORY_SEPARATOR . 'seg_*.mp4');
    sort($segments, SORT_NATURAL);
    if (!$segments) {
        json_response(['error' => 'no segments uploaded'], 422);
    }
    $videosDir = recordings_root($storageRoot, $user) . DIRECTORY_SEPARATOR . 'videos';
    ensure_dir($videosDir);
    $targetFile = $videosDir . DIRECTORY_SEPARATOR . gmdate('Ymd_His') . '.mp4';
    $concatFile = $sessionPath . DIRECTORY_SEPARATOR . 'concat.txt';
    $concatContent = '';
    foreach ($segments as $segment) {
        $concatContent .= "file '" . str_replace("'", "'\\''", $segment) . "'\n";
    }
    file_put_contents($concatFile, $concatContent);
    $command = sprintf(
        'ffmpeg -y -f concat -safe 0 -i %s -c copy %s 2>&1',
        escapeshellarg($concatFile),
        escapeshellarg($targetFile)
    );
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        json_response(['error' => 'ffmpeg concat failed', 'details' => implode("\n", $output)], 500);
    }
    $sessionMeta = [
        'session_id' => $sessionId,
        'user' => $user,
        'started_at' => $data['started_at'] ?? null,
        'ended_at' => $data['ended_at'] ?? null,
        'status' => 'finished',
        'video_path' => $targetFile,
    ];
    file_put_contents(
        $sessionPath . DIRECTORY_SEPARATOR . 'session.json',
        json_encode($sessionMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    json_response(['status' => 'finished', 'video_path' => $targetFile, 'segments' => count($segments)]);
}

json_response(['error' => 'not found', 'path' => $path], 404);
