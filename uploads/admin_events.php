<?php
// admin_events.php – Custom Events & Discord Trigger
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

// Hilfsfunktionen (lokal, falls nicht in db.php)
if (!function_exists('discord_cfg')) {
    function discord_cfg(): array {
        return ['token' => get_setting('discord_bot_token', '')];
    }
}
if (!function_exists('http_json')) {
    function http_json(string $m, string $u, array $h, array $b): ?array {
        $ch = curl_init($u); curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $m);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($b));
        $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ['code' => $code, 'body' => $res];
    }
}

// Events laden
$events_json = get_setting('custom_discord_events', '[]');
$events = json_decode($events_json, true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        flash('CSRF-Fehler', 'error');
    } else {
        $action = $_POST['action'] ?? '';

        // EVENT HINZUFÜGEN / BEARBEITEN
        if ($action === 'save_event') {
            $id = $_POST['event_id'] ?: uniqid();
            $events[$id] = [
                'name'    => trim($_POST['event_name']),
                'channel' => trim($_POST['channel_id']),
                'message' => trim($_POST['message_text'])
            ];
            set_setting('custom_discord_events', json_encode($events));
            flash('Event gespeichert.', 'success');
        }
        // EVENT LÖSCHEN
        elseif ($action === 'delete_event') {
            unset($events[$_POST['event_id']]);
            set_setting('custom_discord_events', json_encode($events));
            flash('Event gelöscht.', 'success');
        }
        // SENDEN (LIVE ODER TEST)
        elseif ($action === 'trigger_event') {
            $id = $_POST['event_id'];
            $mode = $_POST['mode']; // 'live' oder 'test'
            $event = $events[$id] ?? null;

            if ($event) {
                $target = ($mode === 'test') ? '1484127245420200056' : $event['channel'];
                $cfg = discord_cfg();
                $res = http_json('POST', "https://discord.com/api/v10/channels/{$target}/messages",
                    ['Authorization: Bot '.$cfg['token'], 'Content-Type: application/json'],
                    ['content' => $event['message']]
                );
                
                if ($res['code'] === 200) flash("Nachricht wurde an ".($mode === 'test' ? 'Testchannel' : 'Live-Kanal')." gesendet.", 'success');
                else flash("Fehler: Discord Code ".$res['code'], 'error');
            }
        }
    }
    header('Location: admin_events.php'); exit;
}

render_header('Custom Events');
?>

<div class="container">
    <?php foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; } ?>

    <div class="card">
        <h2>Neues Event erstellen</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="save_event">
            <input type="hidden" name="event_id" value="">
            
            <label>Event Name (intern)<br><input type="text" name="event_name" required placeholder="z.B. Server Wartung"></label><br><br>
            <label>Discord Channel ID<br><input type="text" name="channel_id" required placeholder="147794..."></label><br><br>
            <label>Nachricht<br><textarea name="message_text" rows="3" style="width:100%" required></textarea></label><br><br>
            
            <button type="submit" class="btn btn-primary">Event hinzufügen</button>
        </form>
    </div>

    <hr>

    <h3>Vorhandene Events</h3>
    <?php if (empty($events)): ?><p>Keine Events konfiguriert.</p><?php endif; ?>
    
    <?php foreach ($events as $id => $e): ?>
        <div class="card" style="margin-bottom: 10px; border-left: 5px solid var(--primary);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                <div>
                    <strong><?=htmlspecialchars($e['name'])?></strong><br>
                    <small>Channel: <?=htmlspecialchars($e['channel'])?></small><br>
                    <p style="background: #f4f4f4; padding: 5px; border-radius: 4px; font-size: 0.9em; margin-top:5px;">
                        <?=nl2br(htmlspecialchars($e['message']))?>
                    </p>
                </div>
                <div style="display: flex; flex-direction: column; gap: 5px;">
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                        <input type="hidden" name="action" value="trigger_event">
                        <input type="hidden" name="event_id" value="<?=$id?>">
                        <input type="hidden" name="mode" value="live">
                        <button type="submit" class="btn btn-success" style="width:100%">Live senden</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                        <input type="hidden" name="action" value="trigger_event">
                        <input type="hidden" name="event_id" value="<?=$id?>">
                        <input type="hidden" name="mode" value="test">
                        <button type="submit" class="btn" style="width:100%">Test senden</button>
                    </form>
                    <form method="post" onsubmit="return confirm('Event wirklich löschen?')">
                        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_id" value="<?=$id?>">
                        <button type="submit" class="btn btn-danger" style="width:100%; padding: 2px 5px; font-size: 0.8em;">Löschen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    
    <br><a href="admin.php" class="btn">Zurück zur Übersicht</a>
</div>

<?php render_footer(); ?>
