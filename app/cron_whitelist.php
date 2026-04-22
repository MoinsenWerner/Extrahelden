<?php
// cron_whitelist.php – alle 5 Min. per Cron aufrufen
declare(strict_types=1);
require __DIR__ . '/db.php';

/* einfache REST-Funktionen (wie in admin.php) */
function http_json(string $method, string $url, array $headers, ?array $body=null, int $timeout=8): ?array {
    if (!function_exists('curl_init')) return null;
    $ch=curl_init($url);
    $headers=array_merge(['Accept: application/json'],$headers);
    curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>$timeout,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>$headers]);
    if($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE));
    $resp=curl_exec($ch); if($resp===false){curl_close($ch); return null;}
    $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    return ['code'=>$code,'json'=>json_decode($resp,true)];
}
function discord_find_user_id_by_name(string $name):?string{
    $tok=get_setting('discord_bot_token',''); $gid=get_setting('discord_guild_id',''); if($tok===''||$gid==='') return null;
    $r=http_json('GET',"https://discord.com/api/v10/guilds/{$gid}/members/search?query=".rawurlencode($name)."&limit=5",['Authorization: Bot '.$tok]);
    if(!$r||$r['code']!==200||!is_array($r['json'])) return null; $nl=mb_strtolower($name);
    foreach($r['json'] as $m){$u=$m['user']??[];$cand=[mb_strtolower($u['global_name']??''),mb_strtolower($u['username']??''),mb_strtolower($m['nick']??'')]; if(in_array($nl,$cand,true)) return $u['id']??null;}
    return $r['json'][0]['user']['id'] ?? null;
}
function discord_dm_user_id(string $uid,string $msg):bool{
    $tok=get_setting('discord_bot_token',''); if($tok==='') return false;
    $dm=http_json('POST','https://discord.com/api/v10/users/@me/channels',['Authorization: Bot '.$tok,'Content-Type: application/json'],['recipient_id'=>$uid]);
    $ch=$dm['json']['id']??null; if(!$ch) return false;
    $s=http_json('POST',"https://discord.com/api/v10/channels/{$ch}/messages",['Authorization: Bot '.$tok,'Content-Type: application/json'],['content'=>$msg]);
    return ($s && $s['code']>=200 && $s['code']<300);
}
function discord_send_to_fallback(string $msg):bool{
    $tok=get_setting('discord_bot_token',''); $ch=get_setting('discord_fallback_channel_id',''); if($tok===''||$ch==='') return false;
    $s=http_json('POST',"https://discord.com/api/v10/channels/{$ch}/messages",['Authorization: Bot '.$tok,'Content-Type: application/json'],['content'=>$msg]);
    return ($s && $s['code']>=200 && $s['code']<300);
}
function discord_notify_by_name(string $name,string $msg):void{
    $id=discord_find_user_id_by_name($name); $ok=false; if($id) $ok=discord_dm_user_id($id,$msg);
    if(!$ok) discord_send_to_fallback("Benachrichtigung für **{$name}** (DM nicht möglich): ".$msg);
}
function normalize_uuid(string $u): string { return strtolower(str_replace('-', '', trim($u))); }

/* Tabelle für "gesehen" sicherstellen */
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS server_whitelist_seen (uuid TEXT PRIMARY KEY, first_seen_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);");

/* Whitelist lesen */
$path = get_setting('whitelist_json_path', '/home/crafty/crafty-4/servers/8c66e586-dbda-4c99-a447-b944b8677c88/whitelist.json');
if (!is_readable($path)) { echo "whitelist not readable\n"; exit(0); }
$json = @file_get_contents($path);
$arr  = json_decode($json, true);
if (!is_array($arr)) { echo "invalid json\n"; exit(0); }

$notified = 0;
foreach ($arr as $entry) {
    $uuidDash = (string)($entry['uuid'] ?? '');
    $name     = (string)($entry['name'] ?? '');
    if ($uuidDash === '' || $name === '') continue;

    $uuid = normalize_uuid($uuidDash);
    $chk = $pdo->prepare("SELECT 1 FROM server_whitelist_seen WHERE uuid=?"); $chk->execute([$uuid]);
    if ($chk->fetch()) continue; // schon benachrichtigt

    $pdo->prepare("INSERT INTO server_whitelist_seen(uuid) VALUES(?)")->execute([$uuid]);

    // passenden Discord ermitteln
    $disc = '';
    $app = $pdo->prepare("SELECT discord_name FROM applications WHERE lower(mc_uuid)=? OR lower(mc_name)=? ORDER BY datetime(created_at) DESC LIMIT 1");
    $app->execute([$uuid, strtolower($name)]);
    $disc = (string)($app->fetch()['discord_name'] ?? '');
    if ($disc === '') {
        $usr = $pdo->prepare("SELECT u.discord_name FROM users u WHERE lower(u.username)=? LIMIT 1");
        $usr->execute([strtolower($name)]);
        $disc = (string)($usr->fetch()['discord_name'] ?? '');
    }
    if ($disc !== '') {
        discord_notify_by_name($disc, "✅ **{$name}** wurde auf dem Server **whitelisted**.");
        $notified++;
    }
}
echo "done: {$notified}\n";
