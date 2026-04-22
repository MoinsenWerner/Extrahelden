<?php
declare(strict_types=1);
session_start();

/* ============================
   KONFIGURATION
   ============================ */
$DISCORD_WEBHOOK_URL = 'https://discord.com/api/webhooks/1404570967715348573/32muRc4vJKrsTjHjs_oAuXbUBNibp2dOUfTthO26ATNMdrtO4SWMPZEbkMSLp-Y3UGH4'; // <— HIER DEIN WEBHOOK
$DISCORD_THREAD_ID   = '';    // optional: Thread-ID oder leer lassen
$PAGE_TITLE          = 'Mc-Server Bug Report';
$RATE_LIMIT_MAX      = 10;    // max. Requests je Fenster
$RATE_LIMIT_WINDOW_S = 300;   // Fenster in Sekunden (5 min)

// Erlaubte MC-Server-Auswahl
$ALLOWED_MC_SERVERS  = ['Nova SMP', 'SMP'];

/* ============================
   HILFSFUNKTIONEN
   ============================ */
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function csrf_token_get(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_token_check(?string $token): bool {
    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function rate_limit_key(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return sys_get_temp_dir() . '/dw_rate_' . hash('sha256', $ip . '|' . PHP_SAPI);
}
function rate_limit_allow(int $max, int $window): bool {
    $file = rate_limit_key();
    $now  = time();
    $data = [];

    if (is_file($file)) {
        $raw = @file_get_contents($file);
        if ($raw !== false) {
            $data = array_filter(array_map('intval', explode(',', $raw)), fn($t) => $t > $now - $window);
        }
    }
    if (count($data) >= $max) {
        return false;
    }
    $data[] = $now;
    @file_put_contents($file, implode(',', $data), LOCK_EX);
    return true;
}

function send_to_discord(string $webhook, string $threadId, string $username, string $content, int $timeoutMs = 5000): array {
    $payload = [
        'username'          => mb_substr($username, 0, 80),
        'content'           => $content,
        'allowed_mentions'  => ['parse' => []], // Mentions deaktivieren
    ];
    $url = $webhook;
    if ($threadId !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'thread_id=' . rawurlencode($threadId);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS     => $timeoutMs,
    ]);
    $respBody = curl_exec($ch);
    $errno    = curl_errno($ch);
    $errmsg   = curl_error($ch);
    $status   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return [false, "cURL-Fehler: $errmsg", $status, $respBody];
    }
    if ($status < 200 || $status >= 300) {
        return [false, "Discord-Status $status", $status, $respBody];
    }
    return [true, 'OK', $status, $respBody];
}

/* ============================
   REQUEST-HANDLING
   ============================ */
$errors = [];
$ok     = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (!csrf_token_check($_POST['csrf'] ?? null)) {
        $errors[] = 'Ungültiger Sicherheits-Token (CSRF). Bitte Seite neu laden.';
    }

    // Rate-Limit
    if (!$errors && !rate_limit_allow($RATE_LIMIT_MAX, $RATE_LIMIT_WINDOW_S)) {
        $errors[] = 'Zu viele Anfragen. Bitte in ein paar Minuten erneut versuchen.';
    }

    // Honeypot
    $website = trim((string)($_POST['website'] ?? ''));
    if (!$errors && $website !== '') {
        $errors[] = 'Anfrage blockiert.';
    }

    // Validierung Felder
    $name     = trim((string)($_POST['name'] ?? ''));
    $message  = trim((string)($_POST['message'] ?? ''));
    $mcServer = trim((string)($_POST['mc_server'] ?? ''));

    if (!$errors) {
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors[] = 'Anzeigename muss zwischen 2 und 80 Zeichen lang sein.';
        }
        if ($message === '' || mb_strlen($message) > 2000) {
            $errors[] = 'Nachricht darf nicht leer sein und max. 2000 Zeichen haben.';
        }
        if (!in_array($mcServer, $ALLOWED_MC_SERVERS, true)) {
            $errors[] = 'Ungültige Serverauswahl.';
        }
    }

    // Versand
    if (!$errors) {
        if ($DISCORD_WEBHOOK_URL === 'https://discord.com/api/webhooks/REPLACE_ME/REPLACE_ME') {
            $errors[] = 'Bitte zuerst die Webhook-URL in der Datei konfigurieren.';
        } else {
            // Inhalt klar strukturieren: oben Server, dann Nachricht
            $content = "**Server:** {$mcServer}\n\n{$message}";
            [$success, $msg, $status, $body] = send_to_discord(
                $DISCORD_WEBHOOK_URL,
                $DISCORD_THREAD_ID,
                $name,
                $content
            );
            if ($success) {
                $ok = true;
                $_SESSION['flash_ok'] = 'Nachricht gesendet.';
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            } else {
                $errors[] = 'Senden fehlgeschlagen. ' . $msg;
                if ($status) {
                    $errors[] = 'HTTP-Status: ' . $status;
                }
            }
        }
    }
}

$flash_ok = $_SESSION['flash_ok'] ?? null;
unset($_SESSION['flash_ok']);

// Security-Header
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($PAGE_TITLE)?></title>
<style>
:root{
  --bg:#0f172a; --card:#111827; --text:#e5e7eb; --muted:#94a3b8; --accent:#4f46e5;
}
*{box-sizing:border-box}
body{margin:0;background:transparent;color:var(--text);font:16px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Arial}
.container{max-width:720px;margin:2rem auto;background:var(--card);padding:1.5rem;border-radius:12px;border:1px solid #1f2937}
h1{margin:0 0 1rem;font-size:1.4rem}
.field{margin-bottom:1rem}
label{display:block;font-size:.95rem;color:var(--muted);margin-bottom:.25rem}
input,textarea,select{
  width:100%;padding:.8rem 1rem;border-radius:.6rem;border:1px solid #243244;background:#0b1220;color:var(--text);outline:none;
}
input:focus,textarea:focus,select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,70,229,.25)}
button{padding:.9rem 1.4rem;border:none;border-radius:.7rem;background:linear-gradient(90deg,var(--accent),#6366f1);color:#fff;font-weight:700;cursor:pointer}
button[disabled]{opacity:.6;cursor:not-allowed}
.note{margin-top:1rem;color:var(--muted);font-size:.9rem}
.hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}
.alert{border:1px solid;padding:.8rem 1rem;border-radius:.6rem;margin-bottom:1rem}
.alert.ok{border-color:#166534;background:#0a1a12}
.alert.err{border-color:#7f1d1d;background:#1a0f10}
#toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#0b1220;color:var(--text);border:1px solid #1f2937;border-radius:.75rem;padding:.9rem 1.2rem;min-width:260px;max-width:90vw;text-align:center;box-shadow:0 10px 25px rgba(0,0,0,.3);opacity:0;pointer-events:none;transition:opacity .2s ease, transform .2s ease;}
#toast.show{opacity:1;transform:translateX(-50%) translateY(-6px)}
#toast.success{border-color:#166534}
#toast.error{border-color:#7f1d1d}
</style>
</head>
<body>
  <main class="container">
    <h1><?=h($PAGE_TITLE)?></h1>

    <?php if ($flash_ok): ?>
      <div class="alert ok"><?=h($flash_ok)?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert err">
        <strong>Fehler:</strong>
        <ul>
          <?php foreach ($errors as $e): ?>
            <li><?=h($e)?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
    <p class="note"><a href="https://nova.cube-kingdom.de/">Startseite</a></p>

    <form id="msgForm" method="post" autocomplete="off" novalidate>
      <input type="hidden" name="csrf" value="<?=h(csrf_token_get())?>">

      <div class="field">
        <label for="mc_server">Betroffener MC-Server</label>
        <select id="mc_server" name="mc_server" required>
          <?php
            $current = isset($_POST['mc_server']) ? (string)$_POST['mc_server'] : '';
            foreach ($ALLOWED_MC_SERVERS as $srv):
              $sel = ($current === $srv) ? ' selected' : '';
              echo '<option value="'.h($srv).'"'.$sel.'>'.h($srv).'</option>';
            endforeach;
          ?>
        </select>
      </div>

      <div class="field">
        <label for="name">Anzeigename</label>
        <input id="name" name="name" type="text" minlength="2" maxlength="80" required placeholder="Dein Name"
               value="<?= isset($_POST['name']) ? h((string)$_POST['name']) : '' ?>">
      </div>

      <div class="field">
        <label for="message">Nachricht</label>
        <textarea id="message" name="message" rows="6" maxlength="2000" required
                  placeholder="Deine Nachricht …"><?= isset($_POST['message']) ? h((string)$_POST['message']) : '' ?></textarea>
      </div>

      <!-- Honeypot -->
      <div class="hp">
        <label for="website">Website</label>
        <input id="website" name="website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <button id="sendBtn" type="submit">Senden</button>
      <p class="note">Hinweis: Erwähnungen (@everyone/@here/@Rollen) sind deaktiviert. Max. 2000 Zeichen.</p>
      <br>
      <p class="note">Diese Nachricht wird an einen Admin-Kanal des <a href="https://discord.gg/YgM8JnSVy"a>Discord Servers</a> weitergeleitet.</p>
      <p class="note">Unsere Admins kümmern sich so schnell wie möglich darum</p>
    </form>
  </main>

  <div id="toast" role="status" aria-live="polite"></div>

<script>
(function(){
  const form = document.getElementById('msgForm');
  const btn  = document.getElementById('sendBtn');

  form.addEventListener('submit', function(e){
    const name = document.getElementById('name').value.trim();
    const msg  = document.getElementById('message').value.trim();
    const sel  = document.getElementById('mc_server').value;
    if (!sel) { e.preventDefault(); toast('Bitte einen MC-Server wählen.', 'error'); return false; }
    if (name.length < 2) { e.preventDefault(); toast('Name zu kurz (min. 2).', 'error'); return false; }
    if (msg.length < 1)  { e.preventDefault(); toast('Nachricht darf nicht leer sein.', 'error'); return false; }
    btn.disabled = true;
    setTimeout(()=>btn.disabled=false, 4000);
  });

  let toastTimer=null;
  function toast(msg, type='success'){
    const el = document.getElementById('toast');
    el.className = ''; el.textContent = msg; el.classList.add('show', type);
    clearTimeout(toastTimer); toastTimer = setTimeout(()=>el.classList.remove('show'), 2000);
  }

  <?php if ($flash_ok): ?>
    (function(){ const el=document.getElementById('toast'); el.textContent='<?=h($flash_ok)?>'; el.classList.add('show','success'); setTimeout(()=>el.classList.remove('show'),2000); })();
  <?php endif; ?>
})();
</script>
</body>
</html>
