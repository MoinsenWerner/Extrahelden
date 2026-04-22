<?php
// PFAD ANPASSEN: Absoluter Pfad zur SQLite Datenbank des Bots
$db_path = '/home/dcbot/levels.db';

try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tabellen initialisieren, falls sie noch nicht existieren
    $db->exec("CREATE TABLE IF NOT EXISTS config (level INTEGER PRIMARY KEY, xp_needed INTEGER)");
    $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");

    // Verarbeitung von POST-Anfragen
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 1. Level hinzufügen/aktualisieren
        if (isset($_POST['add_level'])) {
            $stmt = $db->prepare("INSERT OR REPLACE INTO config (level, xp_needed) VALUES (?, ?)");
            $stmt->execute([$_POST['level'], $_POST['xp']]);
        }
        
        // 2. Nachrichten-Einstellungen speichern
        if (isset($_POST['update_settings'])) {
            $fields = ['msg_type', 'msg_content', 'embed_color', 'embed_thumbnail', 'embed_image'];
            foreach ($fields as $field) {
                $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
                $stmt->execute([$field, $_POST[$field] ?? '']);
            }
        }
    }

    // Daten für die Anzeige laden
    $levels = $db->query("SELECT * FROM config ORDER BY level ASC")->fetchAll(PDO::FETCH_ASSOC);
    $settings_raw = $db->query("SELECT * FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Default-Werte setzen falls leer
    $settings = array_merge([
        'msg_type' => 'text',
        'msg_content' => 'Glückwunsch {User}! Level {level} erreicht!',
        'embed_color' => '#00ff00',
        'embed_thumbnail' => '',
        'embed_image' => ''
    ], $settings_raw);

} catch (PDOException $e) {
    die("Datenbankfehler: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discord Bot Dashboard</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #2c2f33; color: white; padding: 20px; }
        .container { max-width: 800px; margin: auto; background: #23272a; padding: 20px; border-radius: 8px; }
        section { margin-bottom: 30px; border-bottom: 1px solid #444; padding-bottom: 20px; }
        input, textarea, select { width: 100%; padding: 8px; margin: 5px 0; border-radius: 4px; border: 1px solid #444; background: #40444b; color: white; box-sizing: border-box; }
        button { background: #7289da; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        button:hover { background: #5b6eae; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { text-align: left; padding: 10px; border: 1px solid #444; }
        th { background: #2c2f33; }
        .hint { font-size: 0.85em; color: #b9bbbe; }
    </style>
</head>
<body>

<div class="container">
    <h1>Discord XP Dashboard</h1>

    <section>
        <h2>Level-Stufen verwalten</h2>
        <form method="POST">
            <div style="display: flex; gap: 10px;">
                <input type="number" name="level" placeholder="Level (z.B. 5)" required>
                <input type="number" name="xp" placeholder="Benötigte XP" required>
                <button type="submit" name="add_level">Speichern</button>
            </div>
        </form>
        <table>
            <thead><tr><th>Level</th><th>XP benötigt</th></tr></thead>
            <tbody>
                <?php foreach ($levels as $l): ?>
                <tr><td>Level <?= $l['level'] ?></td><td><?= $l['xp_needed'] ?> XP</td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section>
        <h2>Level-Up Nachricht</h2>
        <form method="POST">
            <label>Nachrichtentyp:</label>
            <select name="msg_type">
                <option value="text" <?= $settings['msg_type'] == 'text' ? 'selected' : '' ?>>Normaler Text</option>
                <option value="embed" <?= $settings['msg_type'] == 'embed' ? 'selected' : '' ?>>Embed</option>
            </select>

            <label>Inhalt:</label>
            <textarea name="msg_content" rows="3"><?= htmlspecialchars($settings['msg_content']) ?></textarea>
            <p class="hint">Platzhalter: {User} (Mention), {level}, {XP}</p>

            <div id="embed-options">
                <label>Embed Farbe:</label>
                <input type="color" name="embed_color" value="<?= $settings['embed_color'] ?>">
                
                <label>Thumbnail URL (Icon rechts oben):</label>
                <input type="url" name="embed_thumbnail" value="<?= htmlspecialchars($settings['embed_thumbnail']) ?>" placeholder="https://...">
                
                <label>Image URL (Großes Bild unten):</label>
                <input type="url" name="embed_image" value="<?= htmlspecialchars($settings['embed_image']) ?>" placeholder="https://...">
            </div>

            <button type="submit" name="update_settings" style="margin-top: 15px;">Einstellungen speichern</button>
        </form>
    </section>
</div>

</body>
</html>
