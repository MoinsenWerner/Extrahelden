<?php
// discord_outbox_worker.php – einmaliger Outbox-Durchlauf (für systemd timer)
declare(strict_types=1);

function http_json_dc(string $method, string $url, array $headers, ?array $body=null, int $timeout=8): ?array {
    if (!function_exists('curl_init')) return null;
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
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code'=>$code,'json'=>json_decode($resp,true)];
}

require __DIR__ . '/db.php';

/* ------- Lock, damit nicht parallel läuft ------- */
$lockFile = __DIR__.'/.discord_outbox.lock';
$lf = fopen($lockFile, 'c+');
if (!$lf || !flock($lf, LOCK_EX | LOCK_NB)) { fwrite(STDOUT, "another run is active\n"); exit(0); }

/* ------- Discord helpers (Multi-Guild) ------- */
function cfg(): array {
    $idsCsv = get_setting('discord_guild_ids', '');
    $idsArr = array_values(array_filter(array_map('trim', explode(',', $idsCsv))));
    if (empty($idsArr)) {
        $one = trim((string)get_setting('discord_guild_id',''));
        if ($one !== '') $idsArr = [$one];
    }
    return ['token'=>get_setting('discord_bot_token',''),'guild_ids'=>$idsArr];
}
/*function http_json_dc(string $method, string $url, array $headers, ?array $body=null, int $timeout=8): ?array {
    if (!function_exists('curl_init')) return null;
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
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code'=>$code,'json'=>json_decode($resp,true)];
}*/
function find_uid(string $name): ?string {
    $c = cfg(); if ($c['token']==='' || empty($c['guild_ids'])) return null;
    $needle = mb_strtolower($name);
    foreach ($c['guild_ids'] as $gid) {
        $url = "https://discord.com/api/v10/guilds/{$gid}/members/search?query=".rawurlencode($name)."&limit=5";
        $res = http_json_dc('GET', $url, ['Authorization: Bot '.$c['token']]);
        if (!$res || $res['code'] !== 200 || !is_array($res['json'])) continue;
        foreach ($res['json'] as $m) {
            $u = $m['user'] ?? [];
            $cands=[mb_strtolower($u['global_name']??''),mb_strtolower($u['username']??''),mb_strtolower($m['nick']??'')];
            if (in_array($needle,$cands,true)) return (string)($u['id']??'');
        }
        $first = $res['json'][0]['user']['id'] ?? null;
        if ($first) return (string)$first;
    }
    return null;
}
function dm_uid(string $uid, string $msg): bool {
    $c = cfg(); if ($c['token']==='') return false;
    $dm = http_json_dc('POST','https://discord.com/api/v10/users/@me/channels',
        ['Authorization: Bot '.$c['token'],'Content-Type: application/json'],
        ['recipient_id'=>$uid]
    );
    $ch = $dm['json']['id'] ?? null; if (!$ch) return false;
    $send = http_json_dc('POST',"https://discord.com/api/v10/channels/{$ch}/messages",
        ['Authorization: Bot '.$c['token'],'Content-Type: application/json'],
        ['content'=>$msg]
    );
    return ($send && $send['code']>=200 && $send['code']<300);
}

/* ------- Batch holen (mit Cooldown 10 min) ------- */
$pdo = db();
$st = $pdo->prepare("
  SELECT id, discord_name, message, COALESCE(user_id,'') AS user_id, attempts
  FROM discord_outbox
  WHERE delivered_at IS NULL
    AND (last_attempt_at IS NULL OR datetime(last_attempt_at) <= datetime('now','-10 minutes'))
  ORDER BY id ASC
  LIMIT 100
");
$st->execute();
$rows = $st->fetchAll();

$sent=0; $failed=0;
foreach ($rows as $r) {
    $id   = (int)$r['id'];
    $disc = (string)$r['discord_name'];
    $msg  = (string)$r['message'];
    $uid  = (string)$r['user_id'];

    if ($uid === '') $uid = find_uid($disc) ?? '';

    $ok = ($uid !== '') ? dm_uid($uid,$msg) : false;

    if ($ok) {
        $pdo->prepare("UPDATE discord_outbox SET user_id=?, delivered_at=datetime('now'), last_attempt_at=datetime('now'), attempts=attempts+1 WHERE id=?")
            ->execute([$uid,$id]);
        $sent++;
    } else {
        $pdo->prepare("UPDATE discord_outbox SET user_id=?, last_attempt_at=datetime('now'), attempts=attempts+1 WHERE id=?")
            ->execute([$uid,$id]);
        $failed++;
    }
}

fwrite(STDOUT, "outbox: sent={$sent} failed={$failed} batch=".count($rows)."\n");
flock($lf, LOCK_UN);
