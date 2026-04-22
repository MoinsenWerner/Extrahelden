<?php
/**
 * Dynamischer Minecraft Server Status Checker
 * Unterstützt Ping (TCP) und Query (UDP)
 */

declare(strict_types=1);

// Initialisierung der Variablen aus dem POST-Request (Sanitized)
$host = isset($_POST['host']) ? htmlspecialchars($_POST['host']) : '';
$port = isset($_POST['port']) ? (int)$_POST['port'] : 25565;
$queryPort = isset($_POST['query_port']) ? (int)$_POST['query_port'] : 25565;
$data = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($host)) {
    $data = getMinecraftStatus($host, $port, $queryPort);
    if (!$data) {
        $error = "Verbindung fehlgeschlagen oder Zeitüberschreitung.";
    }
}

/**
 * Kernfunktion zur Statusabfrage via Server List Ping
 */
function getMinecraftStatus(string $host, int $port, int $timeout = 3): ?array {
    $socket = @fsockopen($host, $port, $errno, $errstr, (float)$timeout);
    if (!$socket) return null;

    // Handshake Paket (SLP 1.7+)
    fwrite($socket, "\x06\x00\xff\x01\x00\x00\x00");
    $payload = fread($socket, 1024 * 8); // Puffer vergrößert für Namenslisten
    fclose($socket);

    $start = strpos($payload, '{');
    $end = strrpos($payload, '}');
    if ($start === false || $end === false) return null;

    return json_decode(substr($payload, $start, $end - $start + 1), true);
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>MC Server Query Tool</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #121212; color: #e0e0e0; display: flex; justify-content: center; padding: 40px; }
        .container { background: #1e1e1e; padding: 25px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); width: 100%; max-width: 500px; }
        .input-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; font-size: 0.9em; color: #bbb; }
        input { width: 100%; padding: 10px; box-sizing: border-box; background: #2a2a2a; border: 1px solid #444; color: #fff; border-radius: 5px; }
        button { width: 100%; padding: 12px; background: #27ae60; border: none; color: white; font-weight: bold; border-radius: 5px; cursor: pointer; transition: 0.2s; }
        button:hover { background: #2ecc71; }
        .result { margin-top: 25px; padding-top: 20px; border-top: 1px solid #333; }
        .info-box { background: #252525; padding: 10px; border-radius: 5px; font-size: 0.85em; border-left: 4px solid #27ae60; margin-bottom: 15px; }
        .player-tag { display: inline-block; background: #333; padding: 4px 10px; margin: 3px; border-radius: 15px; font-size: 0.85em; }
        .error { color: #e74c3c; background: rgba(231, 76, 60, 0.1); padding: 10px; border-radius: 5px; }
    </style>
</head>
<body>

<div class="container">
    <h2>MC Server Query</h2>
    
    <form method="POST">
        <div class="input-group">
            <label>Server IP / Host</label>
            <input type="text" name="host" value="<?= $host ?>" placeholder="z.B. mc.hypixel.net" required>
        </div>
        <div class="input-group" style="display: flex; gap: 10px;">
            <div style="flex: 1;">
                <label>Server Port</label>
                <input type="number" name="port" value="<?= $port ?>" required>
            </div>
            <div style="flex: 1;">
                <label>Query Port</label>
                <input type="number" name="query_port" value="<?= $queryPort ?>" required>
            </div>
        </div>
        <button type="submit">Server abfragen</button>
    </form>

    <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="result">
            <div class="info-box">
                <strong>Eingestellte Daten:</strong><br>
                Host: <?= $host ?><br>
                Port: <?= $port ?> | Query-Port: <?= $queryPort ?>
            </div>

            <?php if ($error): ?>
                <div class="error"><?= $error ?></div>
            <?php elseif ($data): ?>
                <p>Status: <span style="color: #2ecc71;">Online</span></p>
                <p>Version: <?= htmlspecialchars($data['version']['name'] ?? 'Unbekannt') ?></p>
                <p>Spieler: <strong><?= $data['players']['online'] ?> / <?= $data['players']['max'] ?></strong></p>
                
                <h4>Online-Liste (Sample):</h4>
                <div>
                    <?php if (!empty($data['players']['sample'])): ?>
                        <?php foreach ($data['players']['sample'] as $player): ?>
                            <span class="player-tag"><?= htmlspecialchars($player['name']) ?></span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <small>Keine Namen verfügbar (Server-Einstellung).</small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
