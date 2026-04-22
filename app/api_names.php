<?php
$logFile = 'request_log.txt';

// FALL 1: Ein Request kommt per POST rein (von deinem cURL-Script)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $timestamp = date('Y-m-d H:i:s');
    
    // Wir speichern den Inhalt und die Header in die Log-Datei
    $logEntry = "--- NEUER REQUEST ($timestamp) ---\n";
    $logEntry .= "METHOD: POST\n";
    $logEntry .= "BODY: " . ($body ?: "[LEER]") . "\n";
    $logEntry .= "---------------------------------\n\n";
    
    file_put_contents($logFile, $logEntry); // Überschreibt die Datei immer mit dem aktuellsten Call
    exit("Logged");
}

// FALL 2: Du rufst die Seite im Browser auf (GET)
?>
<!DOCTYPE html>
<html>
<head>
    <title>Live Request Debugger</title>
    <meta http-equiv="refresh" content="2"> <style>
        body { background: #121212; color: #00ff00; font-family: monospace; padding: 20px; }
        .container { border: 1px solid #333; padding: 15px; background: #1e1e1e; }
        h2 { color: #fff; border-bottom: 1px solid #333; padding-bottom: 10px; }
        .payload { color: #ffca28; font-size: 1.2em; word-break: break-all; }
    </style>
</head>
<body>
    <h2>Letzter empfangener Request (Live):</h2>
    <div class="container">
        <?php 
        if (file_exists($logFile)) {
            echo nl2br(htmlspecialchars(file_get_contents($logFile)));
        } else {
            echo "Noch kein Request empfangen. Warte auf Daten...";
        }
        ?>
    </div>
    <p><small>Diese Seite aktualisiert sich alle 2 Sekunden automatisch.</small></p>
</body>
</html>
