<?php
// support.php – Mitglieder können Tickets erstellen & ihre Tickets sehen
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

/* ---------- Discord-Helper (Fallback, falls nicht global geladen) ---------- */
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
    }}
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
    }
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

/* ---------- Schema/Migration (idempotent) ---------- */
function ensure_support_schema_member(): void {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tickets (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          creator_user_id INTEGER NOT NULL,
          subject TEXT NOT NULL,
          body TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT 'open',
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          closed_at  TEXT
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_creator ON tickets(creator_user_id);");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ticket_messages (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          ticket_id INTEGER NOT NULL,
          user_id   INTEGER NOT NULL,
          body      TEXT NOT NULL,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tm_ticket ON ticket_messages(ticket_id);");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_events (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          title TEXT NOT NULL,
          type  TEXT NOT NULL DEFAULT 'event',
          start_at TEXT NOT NULL,
          end_at   TEXT NOT NULL,
          details  TEXT,
          user_id  INTEGER
        );
    ");
}
ensure_support_schema_member();

/* ---------- Helper: verfügbare Admins (mit Discord) ---------- */
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
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/* ---------- Neues Ticket anlegen ---------- */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.','error'); header('Location: support.php'); exit;
    }
    $subject = trim((string)($_POST['subject'] ?? ''));
    $body    = trim((string)($_POST['body'] ?? ''));

    if ($subject === '' || $body === '') {
        flash('Betreff und Nachricht dürfen nicht leer sein.','error');
        header('Location: support.php'); exit;
    }

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $ins = $pdo->prepare("INSERT INTO tickets (creator_user_id,subject,body,status) VALUES (?,?,?,'open')");
        $ins->execute([$user_id, $subject, $body]);
        $tid = (int)$pdo->lastInsertId();
        // erste Nachricht auch in den Verlauf schreiben (optional)
        $pdo->prepare("INSERT INTO ticket_messages (ticket_id,user_id,body) VALUES (?,?,?)")
            ->execute([$tid, $user_id, $body]);
        $pdo->commit();

        // Discord: alle verfügbaren Admins benachrichtigen – mit Direklink
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost');
        $link = $base . '/support_view.php?id=' . $tid;

        $admins = get_available_admins_with_discord($pdo);
        $preview = mb_substr($body, 0, 300);
        foreach ($admins as $a) {
            $dn = (string)$a['discord_name'];
            if ($dn === '') continue;
            $msg = "🆘 **Neues Ticket** von **{$username}**\n".
                   "Betreff: **{$subject}**\n".
                   "Nachricht: {$preview}\n".
                   "🔗 {$link}";
            discord_notify_by_name($dn, $msg);
        }

        flash('Ticket erstellt. Die Admins wurden benachrichtigt.','success');
        header('Location: support_view.php?id='.$tid); exit;
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('CREATE TICKET ERROR: '.$e->getMessage());
        flash('Fehler: '.$e->getMessage(),'error');
        header('Location: support.php'); exit;
    }
}

/* ---------- Eigene Tickets laden ---------- */
$my = db()->prepare("
    SELECT id, subject, status, created_at, closed_at
    FROM tickets
    WHERE creator_user_id = ?
    ORDER BY (status='open') DESC, datetime(created_at) DESC
");
$my->execute([$user_id]);
$tickets = $my->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Render ---------- */
render_header('Support');
foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; }
?>
<section class="row">
  <div class="card" style="min-width:100%">
    <h2>Neues Ticket</h2>
    <form method="post" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <label>Betreff<br><input type="text" name="subject" required></label><br><br>
      <label>Nachricht<br><textarea name="body" rows="6" required></textarea></label><br><br>
      <button class="btn btn-primary" type="submit">Absenden</button>
    </form>
  </div>

  <div class="card" style="flex:2">
    <h2>Meine Tickets</h2>
    <?php if (empty($tickets)): ?>
      <p><em>Du hast noch keine Tickets.</em></p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Betreff</th><th>Status</th><th>Erstellt</th><th>Aktion</th></tr></thead>
          <tbody>
          <?php foreach ($tickets as $t): ?>
            <tr>
              <td><?=$t['id']?></td>
              <td><?=htmlspecialchars($t['subject'])?></td>
              <td><?= $t['status']==='open' ? '<span class="badge accepted">Offen</span>' : '<span class="badge rejected">Geschlossen</span>' ?></td>
              <td><?=htmlspecialchars($t['created_at'])?></td>
              <td><a class="btn btn-sm" href="support_view.php?id=<?=$t['id']?>">Ansehen</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php render_footer(); ?>
