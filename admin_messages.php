<?php
// admin_messages.php – Bearbeitung der Discord-Statustexte, Test-Funktionen & Live-Vorschau
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

/* --- Hilfsfunktionen für Discord (da admin.php nicht inkludiert ist) --- */
if (!function_exists('discord_cfg')) {
    function discord_cfg(): array {
        return [
            'token'    => get_setting('discord_bot_token', ''),
            'guild'    => get_setting('discord_guild_id', ''),
            'fallback' => get_setting('discord_fallback_channel_id', ''),
        ];
    }
}

if (!function_exists('http_json')) {
    function http_json(string $method, string $url, array $headers, array $body): ?array {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $res];
    }
}

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

$targetChannel = '1477945366417641646';
$targetTestChannel = '1484127245420200056';
$projectName   = get_setting('apply_title', 'Projekt-Anmeldung');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        flash('Ungültiges CSRF-Token.', 'error');
    } else {
        $a = $_POST['action'] ?? '';

        if ($a === 'save_templates') {
            set_setting('msg_apply_opened', trim($_POST['msg_apply_opened']));
            set_setting('msg_apply_closed', trim($_POST['msg_apply_closed']));
            flash('Nachrichtenvorlagen gespeichert.', 'success');
        } 
        elseif ($a === 'test_msg') {
            $type = $_POST['type'] ?? '';
            $template = ($type === 'opened') 
                ? get_setting('msg_apply_opened', '@everyone Die Bewerbungen für {Projektname} sind eröffnet. Ihr könnt euch unter https://www.extrahelden.de/apply.php jetzt Bewerben.')
                : get_setting('msg_apply_closed', '@everyone Die Bewerbungen für {Projektname} sind geschlossen. Viel Spaß bei der aktuellen Season.');
            
            $finalMsg = str_replace('{Projektname}', $projectName, $template);
            
            $cfg = discord_cfg();
            if ($cfg['token'] !== '') {
                $res = http_json('POST', "https://discord.com/api/v10/channels/{$targetTestChannel}/messages",
                    ['Authorization: Bot '.$cfg['token'], 'Content-Type: application/json'],
                    ['content' => $finalMsg]
                );
                if ($res && $res['code'] >= 200 && $res['code'] < 300) {
                    flash('Testnachricht erfolgreich gesendet.', 'success');
                } else {
                    flash('Discord Fehler: Code ' . ($res['code'] ?? 'unbekannt'), 'error');
                }
            } else {
                flash('Discord-Bot Token fehlt in den Einstellungen.', 'error');
            }
        }
    }
    header('Location: admin_messages.php'); exit;
}

$msgOpened = get_setting('msg_apply_opened', '@everyone Die Bewerbungen für {Projektname} sind eröffnet. Ihr könnt euch unter https://www.extrahelden.de/apply.php jetzt Bewerben.');
$msgClosed = get_setting('msg_apply_closed', '@everyone Die Bewerbungen für {Projektname} sind geschlossen. Viel Spaß bei der aktuellen Season.');

render_header('Discord Nachrichten bearbeiten');
?>

<style>
    .preview-box {
        background: #2f3136;
        color: #dcddde;
        padding: 15px;
        border-radius: 5px;
        font-family: sans-serif;
        margin-top: 10px;
        border-left: 4px solid #5865f2;
        white-space: pre-wrap;
    }
    .preview-label { font-size: 0.75rem; text-transform: uppercase; font-weight: bold; color: #8e9297; margin-top: 10px; }
    .mention { color: #e3e5e8; background: rgba(88, 101, 242, 0.3); padding: 0 2px; border-radius: 3px; }
</style>

<div class="container">
    <?php foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; } ?>

    <div class="card">
        <h2>Discord Statustexte anpassen</h2>
        
        <form method="post" id="msgForm">
            <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
            <input type="hidden" name="action" value="save_templates">
            
            <div style="margin-bottom: 25px;">
                <label><strong>Nachricht bei Aktivierung (Öffnung):</strong></label><br>
                <textarea name="msg_apply_opened" id="input_opened" rows="3" style="width:100%; padding:10px; margin-top:5px; border-radius:8px; border:1px solid var(--border);"><?=htmlspecialchars($msgOpened)?></textarea>
                <div class="preview-label">Live-Vorschau:</div>
                <div id="preview_opened" class="preview-box"></div>
            </div>
            
            <div style="margin-bottom: 25px;">
                <label><strong>Nachricht bei Deaktivierung (Schließung):</strong></label><br>
                <textarea name="msg_apply_closed" id="input_closed" rows="3" style="width:100%; padding:10px; margin-top:5px; border-radius:8px; border:1px solid var(--border);"><?=htmlspecialchars($msgClosed)?></textarea>
                <div class="preview-label">Live-Vorschau:</div>
                <div id="preview_closed" class="preview-box"></div>
            </div>
            
            <button type="submit" class="btn btn-primary">Speichern</button>
            <a href="admin.php" class="btn">Abbrechen</a>
        </form>

        <hr style="border:0; border-top:1px solid var(--border); margin:30px 0;">

        <h3>Nachrichten testen</h3>
        <p>Senden an Kanal: <code><?=$targetChannel?></code></p>
        
        <div style="display: flex; gap: 10px;">
            <form method="post">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="test_msg">
                <input type="hidden" name="type" value="opened">
                <button type="submit" class="btn">Test: Bewerbung offen</button>
            </form>

            <form method="post">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="test_msg">
                <input type="hidden" name="type" value="closed">
                <button type="submit" class="btn">Test: Bewerbung geschlossen</button>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const projectName = <?=json_encode($projectName)?>;
    const inputs = { opened: document.getElementById('input_opened'), closed: document.getElementById('input_closed') };
    const previews = { opened: document.getElementById('preview_opened'), closed: document.getElementById('preview_closed') };

    function updatePreview(key) {
        let text = inputs[key].value.replace(/{Projektname}/g, projectName)
                                   .replace(/@everyone/g, '<span class="mention">@everyone</span>');
        previews[key].innerHTML = text;
    }
    inputs.opened.addEventListener('input', () => updatePreview('opened'));
    inputs.closed.addEventListener('input', () => updatePreview('closed'));
    updatePreview('opened'); updatePreview('closed');
});
</script>

<?php render_footer(); ?>
        