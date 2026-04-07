<?php
// admin_application.php – Detailansicht + Aktionen (accept/reject/shortlist/unshortlist/reset/delete)
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* --- Fallback-Discord-Helfer, falls nicht bereits in admin.php geladen --- */
if (!function_exists('discord_notify_by_name')) {
    function discord_cfg(): array {
        return [
            'token'      => get_setting('discord_bot_token', ''),
            'guild_id'   => get_setting('discord_guild_id', ''),
            'fallback_ch'=> get_setting('discord_fallback_channel_id',''),
        ];
    }
    if (!function_exists('http_json')) {
    function http_json(string $method, string $url, array $headers, ?array $body=null, int $timeout=8): ?array {
        if (!function_exists('curl_init')) { error_log('Discord: php-curl missing'); return null; }
        $ch = curl_init($url);
        $hdr = array_merge(['Accept: application/json'], $headers);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST   => $method,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_CONNECTTIMEOUT  => $timeout,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_HTTPHEADER      => $hdr
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
        $uid = discord_find_user_id_by_name($discordName); $ok = false;
        if ($uid) $ok = discord_dm_user_id($uid, $message);
        if (!$ok) discord_send_to_fallback("Benachrichtigung für **{$discordName}** (DM nicht möglich): ".$message);
    }
}

/* --- Hilfsfunktionen --- */
function app_get(int $id): ?array {
    $st = db()->prepare("SELECT * FROM applications WHERE id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}
function generate_password(int $len = 14): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $out = ''; for ($i=0;$i<$len;$i++) $out .= $chars[random_int(0, strlen($chars)-1)];
    return $out;
}
/** Transaktions-sicher: startet nur, wenn noch keine Transaktion läuft */
function delete_user_completely(int $uid): void {
    $pdo = db();
    $row = $pdo->prepare('SELECT is_admin FROM users WHERE id=?'); $row->execute([$uid]); $u=$row->fetch();
    if ($u && (int)$u['is_admin'] === 1) {
        $c = $pdo->query('SELECT COUNT(*) AS n FROM users WHERE is_admin=1')->fetch();
        if ((int)$c['n'] <= 1) return; // letzten Admin nie löschen
    }
    $started = false;
    if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }
    try {
        $pdo->prepare('DELETE FROM user_documents WHERE user_id=?')->execute([$uid]);
        $pdo->prepare('UPDATE applications SET created_user_id=NULL, generated_password=NULL WHERE created_user_id=?')->execute([$uid]);
        $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        if ($started) $pdo->commit();
    } catch (Throwable $e) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/* --- Request-Parameter --- */
$app_id = (int)($_GET['id'] ?? 0);
if ($app_id <= 0) { flash('Ungültige Bewerbungs-ID.', 'error'); header('Location: admin.php'); exit; }

$app = app_get($app_id);
if (!$app) { flash('Bewerbung nicht gefunden.', 'error'); header('Location: admin.php'); exit; }

$projectName = $app['project_name'] ?: get_setting('apply_title','Projekt-Anmeldung');
$loginUrl    = 'https://www.extrahelden.de/login.php';
$acceptLink  = get_setting('accept_dm_link', ''); // <- kommt aus admin.php Einstellungen

/* --- POST Aktionen --- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.','error'); header('Location: admin_application.php?id='.$app_id); exit;
    }
    $a = $_POST['action'] ?? '';

    try {
        if ($a === 'shortlist_app') {
            db()->prepare('UPDATE applications SET status="shortlisted" WHERE id=?')->execute([$app_id]);
            flash('In engere Auswahl verschoben.','success');
            header('Location: admin_application.php?id='.$app_id); exit;

        } elseif ($a === 'unshortlist_app') {
            // zurück auf pending nur, wenn nicht accepted/rejected
            db()->prepare('UPDATE applications SET status="pending" WHERE id=? AND status="shortlisted"')->execute([$app_id]);
            flash('Aus enger Auswahl entfernt.','success');
            header('Location: admin_application.php?id='.$app_id); exit;

        } elseif ($a === 'accept_app') {
            $pdo = db();
            $pdo->beginTransaction();

            $app = app_get($app_id); // frisch ziehen
            if (!$app) { $pdo->rollBack(); throw new RuntimeException('Bewerbung nicht gefunden.'); }

            $userId = (int)($app['created_user_id'] ?? 0);
            $plain  = (string)($app['generated_password'] ?? '');

            if ($userId <= 0) {
                // neuen Nutzer + Passwort erzeugen
                $plain = generate_password(14);
                $hash  = password_hash($plain, PASSWORD_DEFAULT);
                $ins   = $pdo->prepare('INSERT INTO users (username,password_hash,is_admin,discord_name,is_player) VALUES (?,?,0,?,1)');
                $ins->execute([$app['mc_name'], $hash, $app['discord_name']]);
                $userId = (int)$pdo->lastInsertId();

                $upd = $pdo->prepare('UPDATE applications SET created_user_id=?, generated_password=?, status="accepted" WHERE id=?');
                $upd->execute([$userId, $plain, $app_id]);
            } else {
                // nur Status setzen (Passwort evtl. schon vorhanden)
                $pdo->prepare('UPDATE applications SET status="accepted" WHERE id=?')->execute([$app_id]);
                trigger_auto_task('app_accepted', ['player' => $app['mc_name']]);
            }

            $pdo->commit();

            // Nachricht bauen (Zugangsdaten nur anhängen, wenn ein Passwort vorliegt)
            $msg  = "✅ Deine Bewerbung für **{$projectName}** wurde **angenommen**.\n\n";
            if ($plain !== '') {
                $msg .= "Deine Zugangsdaten:\n";
                $msg .= "• Benutzername: **{$app['mc_name']}**\n";
                $msg .= "• Passwort: **{$plain}** (bitte direkt ändern)\n";
            } else {
                $msg .= "Dein Account **{$app['mc_name']}** ist nun freigeschaltet.\n";
            }
            $msg .= "\nAnmeldung: {$loginUrl}";
            if ($acceptLink !== '') {
                $msg .= "\n🔗 Zum Discord von **{$projectName}**: {$acceptLink}";
            }

            if (!empty($app['discord_name'])) {
                discord_notify_by_name($app['discord_name'], $msg);
            }

            flash('Bewerbung angenommen. Nutzer angelegt/aktiviert und per Discord informiert (falls möglich).','success');
            header('Location: admin_application.php?id='.$app_id); exit;

        } elseif ($a === 'reject_app') {
            $pdo = db();
            $started = false;
            if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }

            $app = app_get($app_id);
            if (!$app) { if ($started) $pdo->rollBack(); throw new RuntimeException('Bewerbung nicht gefunden.'); }

            if (!empty($app['created_user_id'])) {
                // löscht sicher ohne verschachtelte Transaktionen
                delete_user_completely((int)$app['created_user_id']);
            }
            $pdo->prepare('UPDATE applications SET status="rejected", created_user_id=NULL, generated_password=NULL WHERE id=?')->execute([$app_id]);
            trigger_auto_task('app_rejected', ['player' => $app['mc_name']]);

            if ($started) $pdo->commit();

            $msg = "❌ Deine Bewerbung für **{$projectName}** wurde leider **abgelehnt**.";
            if (!empty($app['discord_name'])) {
                discord_notify_by_name($app['discord_name'], $msg);
            }

            flash('Bewerbung abgelehnt. Nutzer (falls vorhanden) entfernt und per Discord informiert.','success');
            header('Location: admin_application.php?id='.$app_id); exit;

        } elseif ($a === 'reset_app') {
            $app = app_get($app_id);
            if ($app && !empty($app['created_user_id'])) {
                delete_user_completely((int)$app['created_user_id']);
            }
            db()->prepare('UPDATE applications SET status="pending", created_user_id=NULL, generated_password=NULL WHERE id=?')->execute([$app_id]);
            flash('Bewerbung zurückgesetzt (pending).','success');
            header('Location: admin_application.php?id='.$app_id); exit;

        } elseif ($a === 'delete_app') {
            $app = app_get($app_id);
            if ($app && !empty($app['created_user_id'])) {
                delete_user_completely((int)$app['created_user_id']);
            }
            db()->prepare('DELETE FROM applications WHERE id=?')->execute([$app_id]);
            flash('Bewerbung gelöscht.','success');
            header('Location: admin.php'); exit;

        } else {
            flash('Unbekannte Aktion.','error');
            header('Location: admin_application.php?id='.$app_id); exit;
        }
    } catch (Throwable $e) {
        error_log('ADMIN APPLICATION ERROR: '.$e->getMessage());
        if (db()->inTransaction()) { db()->rollBack(); }
        flash('Fehler: '.$e->getMessage(), 'error');
        header('Location: admin_application.php?id='.$app_id); exit;
    }
}

/* --- Render --- */
render_header('Bewerbung – Details');
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
$avatar = $app['mc_uuid'] ? 'https://minotar.net/avatar/'.htmlspecialchars(strtolower($app['mc_uuid'])).'/64' : '';
$stClass = ($app['status']==='accepted'?'accepted':($app['status']==='rejected'?'rejected':($app['status']==='shortlisted'?'shortlisted':'pending')));
?>
<style>
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px}
  .kv{margin:0;list-style:none;padding:0}
  .kv li{display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--border)}
  .kv strong{min-width:140px}
  .badge.shortlisted{background:#fff7e6;border-color:#ffd08a;color:#8a5a00}
  .theme-dark .badge.shortlisted{background:#413214;border-color:#7a5a1e;color:#ffdca3}
</style>

<section class="row">
  <div class="card" style="flex:1">
    <h2>Details</h2>
    <div class="grid">
      <div>
        <ul class="kv">
          <li><strong>Status</strong><span class="badge <?=$stClass?>"><?=htmlspecialchars($app['status'])?></span></li>
          <li><strong>Projekt</strong><?=htmlspecialchars($projectName)?></li>
          <li><strong>Minecraft</strong>
            <span><?=htmlspecialchars($app['mc_name'])?></span>
            <?php if ($avatar): ?><img src="<?=$avatar?>" alt="" style="width:24px;height:24px;border-radius:6px;margin-left:8px"><?php endif; ?>
          </li>
          <li><strong>UUID</strong><span class="mc-uuid"><?=htmlspecialchars($app['mc_uuid'] ?: '—')?></span></li>
          <li><strong>Discord</strong><?=htmlspecialchars($app['discord_name'])?></li>
          <li><strong>Datum</strong><?=htmlspecialchars($app['created_at'])?></li>
          <li><strong>YouTube</strong>
            <?php if (!empty($app['youtube_url'])): ?>
              <a href="<?=htmlspecialchars($app['youtube_url'])?>" target="_blank" rel="noopener">Video öffnen</a>
            <?php else: ?>—<?php endif; ?>
          </li>
        </ul>
      </div>

      <div>
        <h3>Login (falls erstellt)</h3>
        <?php if (!empty($app['created_user_id']) && !empty($app['generated_password'])): ?>
          <p>
            <strong>Benutzer:</strong> <?=htmlspecialchars($app['mc_name'])?><br>
            <strong>Passwort:</strong> <code><?=htmlspecialchars($app['generated_password'])?></code><br>
            <small>Login unter <a href="<?=$loginUrl?>" target="_blank" rel="noopener"><?=$loginUrl?></a></small>
          </p>
        <?php else: ?>
          <p><em>Noch kein Nutzer erzeugt.</em></p>
        <?php endif; ?>
        <?php if ($acceptLink !== ''): ?>
          <p><small>DM-Link (aus den Einstellungen): <a href="<?=htmlspecialchars($acceptLink)?>" target="_blank" rel="noopener"><?=htmlspecialchars($acceptLink)?></a></small></p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card" style="max-width:420px">
    <h2>Aktionen</h2>

    <?php if ($app['status'] !== 'shortlisted'): ?>
      <form method="post" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="shortlist_app">
        <button class="btn" type="submit">★ In engere Auswahl</button>
      </form>
    <?php else: ?>
      <form method="post" style="margin-bottom:8px">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <input type="hidden" name="action" value="unshortlist_app">
        <button class="btn" type="submit">☆ Aus enger Auswahl entfernen</button>
      </form>
    <?php endif; ?>

    <form method="post" style="margin-bottom:8px">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="accept_app">
      <button class="btn btn-primary" type="submit">✓ Annehmen</button>
    </form>

    <form method="post" style="margin-bottom:8px" onsubmit="return confirm('Bewerbung wirklich ablehnen? Der ggf. angelegte Nutzer wird gelöscht.')">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="reject_app">
      <button class="btn btn-danger" type="submit">✕ Ablehnen</button>
    </form>

    <form method="post" style="margin-bottom:8px" onsubmit="return confirm('Auf pending zurücksetzen? Angelegter Nutzer wird gelöscht.')">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="reset_app">
      <button class="btn" type="submit">↺ Zurücksetzen</button>
    </form>

    <form method="post" onsubmit="return confirm('Bewerbung endgültig löschen? (entfernt ggf. auch den angelegten Nutzer)')">
      <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
      <input type="hidden" name="action" value="delete_app">
      <button class="btn" type="submit">🗑️ Löschen</button>
    </form>

    <p style="margin-top:10px"><a class="btn" href="admin.php">← Zurück</a></p>
  </div>
</section>

<?php render_footer(); ?>
