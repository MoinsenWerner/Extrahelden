<?php
// Fehleranzeige aktivieren
ini_set('display_errors', 1);
error_reporting(E_ALL);

function db() {
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('sqlite:/var/www/html/database.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $pdo;
}

// 1. Namen aus der DB holen (wie im echten Script)
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT mc_name FROM applications WHERE status = 'accepted'");
    $stmt->execute();
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $payload = implode(',', $names);
} catch (Exception $e) {
    die("Datenbank-Fehler: " . $e->getMessage());
}

// 2. Den Request an die Mod vorbereiten
$url = 'http://extrahelden.de:8965';
$startTime = microtime(true);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);

$response = curl_exec($ch);
$info = curl_getinfo($ch);
$error = curl_error($ch);
curl_close($ch);

$duration = round(microtime(true) - $startTime, 3);
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>API Test: Web -> Minecraft</title>
    <style>
        body { font-family: sans-serif; background: #f4f4f9; padding: 20px; line-height: 1.6; }
        .card { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h2 { color: #333; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        pre { background: #272822; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 14px; }
        .status { padding: 10px; border-radius: 4px; font-weight: bold; margin-bottom: 15px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info-label { font-weight: bold; color: #666; }
    </style>
</head>
<body>

<div class="card">
    <h2>Minecraft API Debugger</h2>

    <div class="info-label">Gesendete Namen (Payload):</div>
    <pre><?php echo htmlspecialchars($payload ?: '[Keine Namen mit Status "accepted" gefunden]'); ?></pre>

    <div class="info-label">Anfrage-Details:</div>
    <ul>
        <li>URL: <code><?php echo $url; ?></code></li>
        <li>Dauer: <?php echo $duration; ?> Sekunden</li>
        <li>HTTP Status-Code: <strong><?php echo $info['http_code']; ?></strong></li>
    </ul>

    <?php if ($error): ?>
        <div class="status error">
            <strong>cURL Fehler:</strong> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php elseif ($info['http_code'] == 200): ?>
        <div class="status success">Verbindung erfolgreich! Mod hat geantwortet.</div>
    <?php else: ?>
        <div class="status error">Server erreichbar, aber gab Code <?php echo $info['http_code']; ?> zurück.</div>
    <?php endif; ?>

    <div class="info-label">Antwort der Mod (Rohdaten):</div>
    <pre><?php 
        if ($response === false || $response === "") {
            echo "--- KEINE DATEN ERHALTEN ---";
        } else {
            echo htmlspecialchars($response); 
        }
    ?></pre>
    
    <button onclick="window.location.reload()" style="padding: 10px 20px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 4px;">Test erneut ausführen</button>
</div>

</body>
</html>
