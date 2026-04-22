<?php
// support_view.php – Ticket ansehen, antworten, schließen/wiederöffnen/löschen (+Discord-Notify, „letzter Admin“)
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (function_exists('require_login')) { require_login(); }
elseif (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$username = (string)($_SESSION['username'] ?? '');
$is_admin = !empty($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* ---------- Discord Helper (Fallback) ---------- */
if (!function_exists('discord_notify_by_name')) {
    function discord_cfg(): array {
        return [
            'token'       => get_setting('discord_bot_token', ''),
            'guild_id'    => get_setting('discord_guild_id', ''),
            'fallback_ch' => get_setting('discord_fallback_channel_id',''),
        ];
    }
    if (!function_exists('http_json')) {
    function http_json(string $method, string $url, array $headers, ?array $body=null, int $timeout=8): ?array {
        if (!function_exists('curl_init')) { error_log('Discord: php-curl missing'); return null; }
        $ch = curl_init($url);
        $hdr = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST=>$method, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_TIMEOUT=>$timeout, CURLOPT_CONNECTTIMEOUT=>$timeout,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_HTTPHEADER=>$hdr
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $resp = curl_exec($ch); if ($resp === false) { curl_close($ch); return null; }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        return ['code'=>$code,'json'=>json_decode($resp,true)];
    }
    function discord_find_user_id_by_name(string $name): ?string {
        $cfg = discord_cfg(); if ($cfg['token']==='' || $cfg['guild_id']==='') return null;
        $url = "https://discord.com/api/v10/guilds/{$cfg['guild_id']}/members/search?query=".rawurlencode($name)."&limit=5";
        $res = http_json('GET', $url, ['Authorization: Bot '.$cfg['token']]);
        if (!$res || $res['code'] !== 200 || !is_array($res['json'])) return null;
        $nameLower = mb_strtolower($name);
        foreach ($res['json'] as $m) {
            $u = $m['user'] ?? [];
            $cand = [mb_strtolower($u['global_name'] ?? ''), mb_strtolower($u['username'] ?? ''), mb_strtolower($m['nick'] ?? '')];
            if (in_array($nameLower, $cand, true)) return $u['id'] ?? null;
        }
        return $res['json'][0]['user']['id'] ?? null;
    }}
    function discord_dm_user_id(string $userId, string $message): bool {
        $cfg = discord_cfg(); if ($cfg['token']==='') return false;
        $dm = http_json('POST','https://discord.com/api/v10/users/@me/channels',
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['recipient_id'=>$userId]
        );
        $chId = $dm['json']['id'] ?? null; if (!$chId) return false;
        $send = http_json('POST',"https://discord.com/api/v10/channels/{$chId}/messages",
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['content'=>$message]
        );
        return ($send && $send['code']>=200 && $send['code']<300);
    }
    function discord_send_to_fallback(string $message): bool {
        $cfg = discord_cfg(); if ($cfg['token']==='' || $cfg['fallback_ch']==='') return false;
        $send = http_json('POST',"https://discord.com/api/v10/channels/{$cfg['fallback_ch']}/messages",
            ['Authorization: Bot '.$cfg['token'],'Content-Type: application/json'],
            ['content'=>$message]
        );
        return ($send && $send['code']>=200 && $send['code']<300);
    }
    function discord_notify_by_name(string $discordName, string $message): void {
        $uid = discord_find_user_id_by_name($discordName); $ok=false;
        if ($uid) $ok = discord_dm_user_id($uid, $message);
        if (!$ok) discord_send_to_fallback("Benachrichtigung für **{$discordName}** (DM nicht möglich): ".$message);
    }
}

/* ---------- Schema Guards (idempotent) ---------- */
function ensure_support_schema_view(): void {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        creator_user_id INTEGER NOT NULL,
        subject TEXT NOT NULL,
        body TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'open',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ticket_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        body TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tm_ticket ON ticket_messages(ticket_id)");
}
ensure_support_schema_view();

/* ---------- Helper ---------- */
function get_available_admins_with_discord(PDO $pdo): array {
    $sql = "
      SELECT id, username, COALESCE(discord_name,'') AS discord_name
      FROM users
      WHERE is_admin=1
        AND COALESCE(discord_name,'') <> ''
        AND id NOT IN (
          SELECT user_id FROM calendar_events
          WHERE type='absence'
            AND datetime('now') BETWEEN datetime(start_at) AND datetime(end_at)
            AND user_id IS NOT NULL
        )
      ORDER BY username
    ";
    try { return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { return []; }
}

function get_last_admin_replier(int $ticketId): ?array {
    $sql = "
      SELECT u.id, u.username, COALESCE(u.discord_name,'') AS discord_name
      FROM ticket_messages m
      JOIN users u ON u.id = m.user_id
      WHERE m.ticket_id = ?
        AND u.is_admin = 1
      ORDER BY datetime(m.created_at) DESC, m.id DESC
      LIMIT 1
    ";
    $st = db()->prepare($sql);
    $st->execute([$ticketId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

/* ---------- Ticket laden ---------- */
$tid = (int)($_GET['id'] ?? 0);
if ($tid <= 0) { flash('Ungültige Ticket-ID.','error'); header('Location: support.php'); exit; }

$st = db()->prepare("
  SELECT t.*, u.username AS creator_name, COALESCE(u.discord_name,'') AS creator_discord
  FROM tickets t
  JOIN users u ON u.id = t.creator_user_id
  WHERE t.id = ?
");
$st->execute([$tid]);
$ticket = $st->fetch(PDO::FETCH_ASSOC);
if (!$ticket) { flash('Ticket nicht gefunden.','error'); header('Location: support.php'); exit; }

$may_view = $is_admin || (int)$ticket['creator_user_id'] === $user_id;
if (!$may_view) { flash('Keine Berechtigung für dieses Ticket.','error'); header('Location: support.php'); exit; }

/* ---------- Aktionen ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.','error'); header("Location: support_view.php?id={$tid}"); exit;
    }
    $action = $_POST['action'] ?? '';
    $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
    $link = $base . '/support_view.php?id=' . $tid;

    try {
        if ($action === 'reply') {
            $body = trim((string)($_POST['body'] ?? ''));
            if ($body === '') { flash('Antwort darf nicht leer sein.','error'); }
            elseif ($ticket['status'] !== 'open') { flash('Ticket ist geschlossen.','error'); }
            else {
                db()->prepare("INSERT INTO ticket_messages (ticket_id,user_id,body) VALUES (?,?,?)")
                   ->execute([$tid, $user_id, $body]);

                // Discord-Benachrichtigungen
                $preview = mb_substr($body, 0, 300);

                if ($is_admin) {
                    // Admin -> Ersteller
                    $msg = "💬 **Antwort auf dein Ticket #{$tid} – {$ticket['subject']}**\n".
                           "Von **{$username}** (Admin): {$preview}\n".
                           "🔗 {$link}";
                    if (!empty($ticket['creator_discord'])) {
                        discord_notify_by_name($ticket['creator_discord'], $msg);
                    } else {
                        discord_send_to_fallback($msg);
                    }
                } else {
                    // Ersteller -> NUR der Admin, der zuletzt geantwortet hat (Fallback: verfügbare Admins)
                    $last = get_last_admin_replier($tid);
                    if ($last && !empty($last['discord_name'])) {
                        $msg = "📩 **Neue Antwort von {$username}** im Ticket #{$tid} – {$ticket['subject']}\n".
                               "{$preview}\n".
                               "🔗 {$link}";
                        discord_notify_by_name($last['discord_name'], $msg);
                    } else {
                        // Noch kein Admin geantwortet → Fallback an verfügbare Admins
                        $admins = get_available_admins_with_discord(db());
                        foreach ($admins as $a) {
                            $dn = (string)$a['discord_name'];
                            if ($dn === '') continue;
                            $msg = "📩 **Neue Antwort von {$username}** im Ticket #{$tid} – {$ticket['subject']}\n".
                                   "{$preview}\n".
                                   "🔗 {$link}";
                            discord_notify_by_name($dn, $msg);
                        }
                    }
                }

                flash('Antwort gesendet.','success');
            }
        } elseif ($action === 'close') {
            if ($is_admin || (int)$ticket['creator_user_id'] === $user_id) {
                db()->prepare("UPDATE tickets SET status='closed', closed_at=datetime('now') WHERE id=?")->execute([$tid]);
                flash('Ticket geschlossen.','success');
            } else {
                flash('Keine Berechtigung zum Schließen.','error');
            }
        } elseif ($action === 'reopen') {
            if ($is_admin) {
                db()->prepare("UPDATE tickets SET status='open', closed_at=NULL WHERE id=?")->execute([$tid]);
                flash('Ticket wieder geöffnet.','success');
            } else {
                flash('Nur Admins können wieder öffnen.','error');
            }
        } elseif ($action === 'delete_closed') {
            // Löschen NUR wenn geschlossen und Berechtigung: Admin ODER Ersteller
            if ($ticket['status'] !== 'closed') {
                flash('Nur geschlossene Tickets können gelöscht werden.','error');
            } elseif (!$is_admin && (int)$ticket['creator_user_id'] !== $user_id) {
                flash('Keine Berechtigung zum Löschen.','error');
            } else {
                $pdo = db();
                $pdo->beginTransaction();
                $pdo->prepare("DELETE FROM ticket_messages WHERE ticket_id=?")->execute([$tid]);
                $pdo->prepare("DELETE FROM tickets WHERE id=?")->execute([$tid]);
                $pdo->commit();
                flash('Ticket dauerhaft gelöscht.','success');
                header('Location: '.($is_admin ? 'admin_tickets.php' : 'support.php')); exit;
            }
        } else {
            flash('Unbekannte Aktion.','error');
        }
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('SUPPORT VIEW ACTION ERROR: '.$e->getMessage());
        flash('Fehler: '.$e->getMessage(),'error');
    }
    header("Location: support_view.php?id={$tid}"); exit;
}

/* ---------- Nachrichten laden ---------- */
$msgs = db()->prepare("
  SELECT m.id, m.body, m.created_at, u.username
  FROM ticket_messages m
  JOIN users u ON u.id = m.user_id
  WHERE m.ticket_id = ?
  ORDER BY datetime(m.created_at) ASC, m.id ASC
");
$msgs->execute([$tid]);
$messages = $msgs->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Render ---------- */
render_header('Ticket ansehen');
foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; }

$open = ($ticket['status'] === 'open');
?>
<style>
  .chat{border:1px solid var(--border);border-radius:10px;overflow:hidden}
  .chat-item{padding:10px;border-bottom:1px solid var(--border)}
  .chat-item:last-child{border-bottom:none}
  .chat-meta{opacity:.7;font-size:.9rem;margin-bottom:4px}
  .badge.open{background:#e9f7ef;border-color:#c6e6cf;color:#185e2d}
  .badge.closed{background:#fdecea;border-color:#f5c6cb;color:#8a1f1f}
</style>

<section class="row">
  <div class="card" style="flex:2">
    <h2 style="margin:0 0 8px">
      Ticket #<?=$ticket['id']?> – <?=htmlspecialchars($ticket['subject'])?>
      <?php if ($open): ?>
        <span class="badge open">Offen</span>
      <?php else: ?>
        <span class="badge closed">Geschlossen</span>
      <?php endif; ?>
    </h2>
    <div style="opacity:.7;margin-bottom:10px">
      Von <strong><?=htmlspecialchars($ticket['creator_name'])?></strong>,
      erstellt am <?=htmlspecialchars($ticket['created_at'])?>
    </div>

    <div class="chat">
      <?php if (empty($messages)): ?>
        <div class="chat-item"><em>Noch keine Nachrichten.</em></div>
      <?php else: foreach ($messages as $m): ?>
        <div class="chat-item">
          <div class="chat-meta">
            <?=htmlspecialchars($m['username'])?> • <?=htmlspecialchars($m['created_at'])?>
          </div>
          <div><?=nl2br(htmlspecialchars($m['body']))?></div>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php if ($open): ?>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="reply">
        <label>Antwort<br><textarea name="body" rows="5" required></textarea></label><br><br>
        <button class="btn btn-primary" type="submit">Senden</button>
      </form>
    <?php else: ?>
      <p style="margin-top:10px"><em>Dieses Ticket ist geschlossen.</em></p>
    <?php endif; ?>
  </div>

  <div class="card" style="max-width:360px">
    <h2>Aktionen</h2>

    <?php if ($open && ($is_admin || (int)$ticket['creator_user_id'] === $user_id)): ?>
      <form method="post" style="margin-bottom:8px" onsubmit="return confirm('Ticket wirklich schließen?')">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="close">
        <button class="btn btn-danger" type="submit">Ticket schließen</button>
      </form>
    <?php endif; ?>

    <?php if (!$open && $is_admin): ?>
      <form method="post" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="reopen">
        <button class="btn btn-primary" type="submit">Ticket wieder öffnen</button>
      </form>
    <?php endif; ?>

    <?php if (!$open && ($is_admin || (int)$ticket['creator_user_id'] === $user_id)): ?>
      <form method="post" onsubmit="return confirm('Geschlossenes Ticket endgültig löschen? Dieser Vorgang kann nicht rückgängig gemacht werden.')">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="delete_closed">
        <button class="btn btn-danger" type="submit">Ticket löschen</button>
      </form>
    <?php endif; ?>

    <a class="btn" href="<?= $is_admin ? 'admin_tickets.php' : 'support.php' ?>">← Zurück</a>
  </div>
</section>

<?php render_footer(); ?>
