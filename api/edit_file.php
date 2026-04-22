<?php

require_once __DIR__ . '/lib/security.php';

$allowedIPs = ['37.44.215.123', '2a13:7e80:0:145::', '88.130.201.18', '2003:d3:872a:7000:d970:136b:71e:b0db'];
$rawIP = getClientIP();

// Autorisierung prüfen
if (!in_array($rawIP, $allowedIPs)) {
    http_response_code(403);
    exit("Access denied: Unauthorized IP $rawIP");
}

$basePath = '/usr/share/icons/cecolor/plain/';
$file = 'linkedhearts.txt';
$fullPath = $basePath . $file;

if (!is_file($fullPath)) {
    exit("Fehler: Datei $file nicht gefunden.");
}

$message = "";

// Speichervorgang bei POST-Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['content'])) {
    
    // BACKUP-LOGIK: Erstellt eine Kopie vor dem Überschreiben
    $timestamp = date('Y-m-d_H-i-s');
    $backupPath = $fullPath . '.' . $timestamp . '.bak';
    
    if (copy($fullPath, $backupPath)) {
        // Eigentliches Speichern
        if (file_put_contents($fullPath, $_POST['content']) !== false) {
            $message = "Datei erfolgreich gespeichert. Backup erstellt: " . basename($backupPath);
        } else {
            $message = "Fehler beim Speichern der Datei.";
        }
    } else {
        $message = "Backup fehlgeschlagen. Speichervorgang abgebrochen (Sicherheitsmaßnahme).";
    }
}

// Aktuellen Inhalt laden
$content = file_get_contents($fullPath);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Editor: <?= htmlspecialchars($file) ?></title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 30px; background: #1a1a1a; color: #e0e0e0; }
        .container { max-width: 900px; margin: auto; }
        textarea { 
            width: 100%; height: 500px; 
            font-family: 'Consolas', 'Monaco', monospace; 
            background: #2d2d2d; color: #63ff63; 
            border: 1px solid #444; padding: 10px;
            resize: vertical;
        }
        .alert { 
            padding: 15px; background: #2e7d32; border: 1px solid #1b5e20; 
            color: #fff; margin-bottom: 20px; border-radius: 4px; 
        }
        button { 
            padding: 12px 25px; background: #0078d4; border: none; 
            color: white; cursor: pointer; font-weight: bold; border-radius: 4px;
        }
        button:hover { background: #005a9e; }
        .header { margin-bottom: 20px; border-bottom: 1px solid #444; padding-bottom: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h2>Editor: <?= htmlspecialchars($file) ?></h2>
            <small>Verbunden als: <?= htmlspecialchars($rawIP) ?></small>
        </div>
        
        <?php if ($message): ?>
            <div class="alert"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <textarea name="content"><?= htmlspecialchars($content) ?></textarea>
            <br><br>
            <button type="submit">Änderungen speichern & Backup erstellen</button>
        </form>
    </div>
</body>
</html>
