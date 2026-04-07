<?php
// admin_events.php – Custom Events & Discord Trigger mit Platzhalter-Referenz
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

$csrf = $_SESSION['csrf'] ?? ( $_SESSION['csrf'] = bin2hex(random_bytes(32)) );

// Events laden
$events = json_decode(get_setting('custom_discord_events', '[]'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_event') {
            $id = isset($_POST['event_id']) && $_POST['event_id'] !== '' ? (int)$_POST['event_id'] : count($events);
            $events[$id] = [
                'name'    => trim($_POST['name']),
                'channel' => trim($_POST['channel']),
                'message' => trim($_POST['message']),
            ];
            set_setting('custom_discord_events', json_encode(array_values($events)));
            flash('Event gespeichert.', 'success');
        } 
        elseif ($action === 'delete_event') {
            $id = (int)$_POST['event_id'];
            array_splice($events, $id, 1);
            set_setting('custom_discord_events', json_encode(array_values($events)));
            flash('Event gelöscht.', 'success');
        }
        elseif ($action === 'trigger_event') {
            $id = (int)$_POST['event_id'];
            $event = $events[$id] ?? null;
            
            if ($event) {
                // Test-Daten für die Platzhalter
                $testData = [
                    'player'      => 'TestSpieler',
                    'discord'     => 'TestUser#1234',
                    'video'       => 'https://youtube.com/watch?v=dQw4w9WgXcQ',
                    'filename'    => 'geheim_plan.pdf',
                    'scope'       => 'Öffentlich',
                    'status'      => 'Online / Aktiv',
                    'max_players' => '20',
                    'content'     => 'Dies ist eine Test-Nachricht vom System.',
                    'title'       => 'Test-Abstimmung',
                    'type'        => 'Gesetz',
                    'creator'     => 'Admin-Vorschau',
                    'title'       => 'Testtitel'
                ];

                // Wir suchen den Trigger-Key, der diesem Event zugeordnet ist
                $rules = json_decode(get_setting('auto_rules', '[]'), true) ?: [];
                $foundTrigger = 'manual_test';
                foreach($rules as $rule) {
                    if ((int)$rule['event_id'] === $id) { $foundTrigger = $rule['trigger']; break; }
                }

                // Nutzt die zentrale Funktion aus der db.php
                trigger_auto_task($foundTrigger, $testData);
                flash('Test-Event ausgelöst (Platzhalter wurden mit Testdaten gefüllt).', 'success');
            }
        }
    }
    header("Location: admin_events.php"); exit;
}

render_header('Discord Events & Platzhalter');
?>

<div class="card">
    <h2>Platzhalter-Referenz</h2>
    <p>Verwende diese Klammern in deinen Nachrichten, um dynamische Daten einzufügen:</p>
    <div class="table-wrap">
        <table style="font-size: 0.9em;">
            <thead>
                <tr><th>Trigger-Bereich</th><th>Verfügbare Platzhalter</th></tr>
            </thead>
            <tbody>
                <tr><td><strong>Bewerbungen</strong></td><td><code>{player}</code>, <code>{discord}</code>, <code>{video}</code></td></tr>
                <tr><td><strong>Dokumente</strong></td><td><code>{filename}</code>, <code>{scope}</code></td></tr>
                <tr><td><strong>Server-Status</strong></td><td><code>{status}</code>, <code>{max_players}</code></td></tr>
                <tr><td><strong>News / Posts</strong></td><td><code>{content}</code></td></tr>
                <tr><td><strong>Abstimmungen</strong></td><td><code>{title}</code>, <code>{type}</code>, <code>{creator}</code></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <h2>Neues / Event bearbeiten</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="save_event">
        <input type="hidden" name="event_id" id="edit_id" value="">
        
        <label>Event Name (intern)<br><input type="text" name="name" id="edit_name" required placeholder="z.B. Bewerbung eingegangen"></label><br><br>
        <label>Discord Channel ID<br><input type="text" name="channel" id="edit_channel" required placeholder="1234567890..."></label><br><br>
        <label>Nachricht (mit Platzhaltern)<br>
            <textarea name="message" id="edit_message" rows="4" required placeholder="Hallo! {player} hat sich beworben..."></textarea>
        </label><br><br>
        <button type="submit" class="btn btn-primary">Event speichern</button>
        <button type="button" class="btn" onclick="resetForm()">Neu erstellen</button>
    </form>
</div>

<div class="grid" style="margin-top:20px">
    <?php foreach ($events as $id => $e): ?>
        <div class="card">
            <h3><?=htmlspecialchars($e['name'])?></h3>
            <p><small>Channel: <?=htmlspecialchars($e['channel'])?></small></p>
            <pre style="background:#eee; padding:10px; font-size:0.85em; white-space:pre-wrap;"><?=htmlspecialchars($e['message'])?></pre>
            
            <div style="display:flex; gap:5px; margin-top:10px">
                <button class="btn btn-sm" onclick="editEvent(<?=$id?>, '<?=addslashes($e['name'])?>', '<?=addslashes($e['channel'])?>', '<?=addslashes(str_replace(["\r","\n"],["","\\n"],$e['message']))?>')">Bearbeiten</button>
                
                <form method="post" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="action" value="trigger_event">
                    <input type="hidden" name="event_id" value="<?=$id?>">
                    <button type="submit" class="btn btn-sm btn-success">Test-Senden</button>
                </form>

                <form method="post" style="display:inline" onsubmit="return confirm('Löschen?')">
                    <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                    <input type="hidden" name="action" value="delete_event">
                    <input type="hidden" name="event_id" value="<?=$id?>">
                    <button type="submit" class="btn btn-sm btn-danger">Löschen</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
function editEvent(id, name, channel, msg) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_channel').value = channel;
    document.getElementById('edit_message').value = msg.replace(/\\n/g, '\n');
    window.scrollTo(0,0);
}
function resetForm() {
    document.getElementById('edit_id').value = '';
    document.getElementById('edit_name').value = '';
    document.getElementById('edit_channel').value = '';
    document.getElementById('edit_message').value = '';
}
</script>

<?php render_footer(); ?>
