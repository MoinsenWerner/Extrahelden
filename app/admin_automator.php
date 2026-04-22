<?php
// admin_automator.php – Verknüpfung von Triggern und Events
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

$csrf = $_SESSION['csrf'] ?? ( $_SESSION['csrf'] = bin2hex(random_bytes(32)) );

// Daten laden
$rules  = json_decode(get_setting('auto_rules', '[]'), true) ?: [];
$events = json_decode(get_setting('custom_discord_events', '[]'), true) ?: [];

// Definition der verfügbaren Trigger im Code
$available_triggers = [
    'new_application'        => 'Neue Bewerbung eingegangen',
    'app_accepted'           => 'Bewerbung angenommen',
    'app_rejected'           => 'Bewerbung abgelehnt',
    'doc_uploaded'           => 'Neues Dokument hochgeladen',
    'server_status_change'   => 'Minecraft Server Status geändert (Öffentlich/Privat)',
    'news_posted'            => 'News-Update auf der Startseite',
    'ticket_created'         => 'Neues Support-Ticket erstellt',
    'ticket_admin_reply'     => 'Admin hat auf Ticket geantwortet',
    'vote_started'           => 'Neue Abstimmung gestartet',
    'post_created'           => 'News-Update auf der Startseite'
];

// Zuordnung welcher Trigger welche Platzhalter bietet
$trigger_vars = [
    'new_application'      => '{player}, {discord}, {video}',
    'app_accepted'         => '{player}',
    'app_rejected'         => '{player}',
    'doc_uploaded'         => '{filename}, {scope}',
    'server_status_change' => '{status}, {max_players}',
    'news_posted'          => '{content}',
    'ticket_created'       => '{title}, {creator}',
    'vote_started'         => '{title}, {type}, {creator}',
    'post_created'         => '{content}, {title}'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (hash_equals($csrf, $_POST['csrf'] ?? '')) {
        if (($_POST['action'] ?? '') === 'save_rule') {
            $rules[] = [
                'trigger'  => $_POST['trigger'],
                'event_id' => (int)$_POST['event_id'],
                'active'   => isset($_POST['active']) ? 1 : 0
            ];
            set_setting('auto_rules', json_encode(array_values($rules)));
            flash('Automatisierung hinzugefügt.', 'success');
        }
        if (($_POST['action'] ?? '') === 'delete_rule') {
            $id = (int)$_POST['rule_id'];
            array_splice($rules, $id, 1);
            set_setting('auto_rules', json_encode(array_values($rules)));
            flash('Regel entfernt.', 'success');
        }
    }
    header("Location: admin_automator.php"); exit;
}

render_header('Automatisierung');
?>

<div class="card">
    <h2>Neue Automatisierungs-Regel</h2>
    <form method="post">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="save_rule">
        
        <div class="grid">
            <label>Wenn dieses Ereignis eintritt (Trigger):<br>
                <select name="trigger" required onchange="updateHint(this.value)">
                    <option value="">-- Bitte wählen --</option>
                    <?php foreach($available_triggers as $key => $label): ?>
                        <option value="<?=$key?>"><?=$label?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>Dann sende dieses Discord-Event:<br>
                <select name="event_id" required>
                    <?php if (empty($events)): ?>
                        <option value="">Keine Events definiert!</option>
                    <?php else: ?>
                        <?php foreach($events as $id => $e): ?>
                            <option value="<?=$id?>"><?=htmlspecialchars($e['name'])?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </label>
        </div>
        
        <p id="var_hint" style="font-size: 0.85em; color: #666; margin: 10px 0;">
            Wähle einen Trigger, um verfügbare Platzhalter zu sehen.
        </p>

        <label><input type="checkbox" name="active" checked> Regel aktiv</label><br><br>
        <button type="submit" class="btn btn-primary" <?=empty($events)?'disabled':''?>>Regel speichern</button>
    </form>
</div>

<div class="card" style="margin-top:20px">
    <h2>Aktive Regeln</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Trigger</th><th>Discord-Event</th><th>Status</th><th>Aktion</th></tr>
            </thead>
            <tbody>
                <?php foreach($rules as $id => $r): ?>
                    <tr>
                        <td><strong><?=$available_triggers[$r['trigger']] ?? $r['trigger']?></strong><br>
                            <small style="color:#888"><?=$trigger_vars[$r['trigger']] ?? ''?></small>
                        </td>
                        <td><?=htmlspecialchars($events[$r['event_id']]['name'] ?? 'Unbekanntes Event')?></td>
                        <td><?=$r['active'] ? '✅ Aktiv' : '❌ Inaktiv'?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Löschen?')">
                                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                                <input type="hidden" name="action" value="delete_rule">
                                <input type="hidden" name="rule_id" value="<?=$id?>">
                                <button class="btn btn-sm btn-danger">Entfernen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const hints = <?=json_encode($trigger_vars)?>;
function updateHint(val) {
    const hintBox = document.getElementById('var_hint');
    if (hints[val]) {
        hintBox.innerHTML = "Verfügbare Platzhalter für diesen Trigger: <strong>" + hints[val] + "</strong>";
    } else {
        hintBox.innerHTML = "Wähle einen Trigger, um verfügbare Platzhalter zu sehen.";
    }
}
</script>

<?php render_footer(); ?>
