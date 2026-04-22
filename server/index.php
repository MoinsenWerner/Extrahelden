<?php
declare(strict_types=1);

$host = isset($_POST['host']) ? htmlspecialchars($_POST['host']) : '';
$port = isset($_POST['port']) ? (int)$_POST['port'] : 18888;
$queryPort = isset($_POST['query_port']) ? (int)$_POST['query_port'] : 28888;
$data = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($host)) {
    $data = getMinecraftQuery($host, $queryPort);
    if (!$data) {
        $error = "Verbindung zum Query-Port ($queryPort) fehlgeschlagen. Ist UDP in der Firewall offen?";
    }
}

function getMinecraftQuery(string $host, int $port, int $timeout = 2): ?array {
    $socket = @fsockopen("udp://$host", $port, $errno, $errstr, (float)$timeout);
    if (!$socket) return null;
    stream_set_timeout($socket, $timeout);

    // 1. Handshake
    $payload = pack("c3N", 0xFE, 0xFD, 0x09, 0x01020304);
    fwrite($socket, $payload);
    $response = fread($socket, 1492);
    if (!$response) return null;

    $challenge = substr($response, 5);
    
    // 2. Full Stat Request
    $payload = pack("c3NNN", 0xFE, 0xFD, 0x00, 0x01020304, (int)$challenge, 0x00000000);
    fwrite($socket, $payload);
    $response = fread($socket, 1492);
    fclose($socket);

    if (!$response) return null;

    // Daten parsen (sehr vereinfacht für die Spielerliste)
    $parts = explode("\x00\x00\x01player_\x00\x00", $response);
    $infoPart = explode("\x00", $parts[0]);
    
    $items = [];
    for ($i = 2; $i < count($infoPart); $i += 2) {
        if (isset($infoPart[$i+1])) $items[$infoPart[$i]] = $infoPart[$i+1];
    }

    $players = isset($parts[1]) ? explode("\x00", trim($parts[1], "\x00")) : [];
    
    return [
        'online' => $items['numplayers'] ?? '0',
        'max' => $items['maxplayers'] ?? '0',
        'version' => $items['version'] ?? 'Unbekannt',
        'list' => array_filter($players)
    ];
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: sans-serif; background: #121212; color: #eee; display: flex; justify-content: center; padding: 20px; }
        .card { background: #1e1e1e; padding: 20px; border-radius: 10px; width: 450px; border: 1px solid #333; }
        input { width: 100%; padding: 8px; margin: 5px 0 15px; background: #2a2a2a; border: 1px solid #444; color: #fff; border-radius: 4px; }
        button { width: 100%; padding: 10px; background: #27ae60; border: none; color: white; cursor: pointer; border-radius: 4px; }
        .info { background: #252525; padding: 10px; border-radius: 4px; border-left: 4px solid #27ae60; margin-top: 15px; font-size: 0.9em; }
        .error { color: #ff6b6b; margin-top: 15px; font-size: 0.9em; }
        .player { display: inline-block; background: #444; padding: 2px 8px; margin: 2px; border-radius: 3px; font-size: 0.8em; }
    </style>
</head>
<body>
<div class="card">
    <h2>MC Server Query</h2>
    <form method="POST">
        <label>Server IP</label>
        <input type="text" name="host" value="<?= $host ?>" required>
        <div style="display:flex; gap:10px;">
            <div style="flex:1"><label>Server Port</label><input type="number" name="port" value="<?= $port ?>"></div>
            <div style="flex:1"><label>Query Port</label><input type="number" name="query_port" value="<?= $queryPort ?>"></div>
        </div>
        <button type="submit">Server abfragen</button>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="info">
            <strong>Eingestellte Daten:</strong><br>
            Host: <?= $host ?><br>
            Port: <?= $port ?> | Query-Port: <?= $queryPort ?>
        </div>

        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php elseif ($data): ?>
            <p>Status: <span style="color:#2ecc71">Online</span> (<?= $data['version'] ?>)</p>
            <p>Spieler: <?= $data['online'] ?> / <?= $data['max'] ?></p>
            <div>
                <?php foreach ($data['list'] as $p): ?>
                    <span class="player"><?= htmlspecialchars($p) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
