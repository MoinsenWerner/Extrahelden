<?php
// index.php – öffentliche Startseite (Auto-Refresh + Klick zum Kopieren der Server-IP)
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

/**
 * Cache-TTL für Minecraft-Status (Sekunden)
 * (Die Live-Ansicht nutzt unten die JSON-API mit TTL=1s.)
 */
const STATUS_TTL_SECONDS = 30;

/* =========================
 *  Minecraft Server Ping
 *  (1.7+ Server List Ping)
 * ========================= */

function writeVarInt(int $value): string {
    $out = '';
    while (true) {
        $temp = $value & 0x7F;
        $value >>= 7;
        if ($value !== 0) $temp |= 0x80;
        $out .= chr($temp);
        if ($value === 0) break;
    }
    return $out;
}
function readVarInt($fp): int {
    $numRead = 0; $result = 0;
    do {
        $b = fread($fp, 1);
        if ($b === '' || $b === false) { throw new RuntimeException('read fail'); }
        $byte = ord($b);
        $value = ($byte & 0x7F);
        $result |= ($value << (7 * $numRead));
        $numRead++;
        if ($numRead > 5) throw new RuntimeException('VarInt too big');
    } while (($byte & 0x80) !== 0);
    return $result;
}

/**
 * Führt einen Status-Ping durch (fehlertolerant).
 */
function mc_ping_raw(string $host, int $port = 25565, float $timeout = 1.5): array {
    $start = microtime(true);
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$fp) return ['online'=>false,'error'=>"connect: $errstr ($errno)"];

    stream_set_timeout($fp, (int)ceil($timeout), (int)((($timeout - floor($timeout)) * 1e6)));

    $protocol = 47; // Dummy; Server liefert echte Version
    $serverAddress = $host;

    // Handshake (pkt id 0x00, next state=1)
    $data = "\x00"
          . writeVarInt($protocol)
          . writeVarInt(strlen($serverAddress)) . $serverAddress
          . pack('n', $port)
          . writeVarInt(1);
    $packet = writeVarInt(strlen($data)) . $data;
    fwrite($fp, $packet);

    // Status request (id 0x00)
    fwrite($fp, "\x01\x00");

    // Response
    try {
        $length   = readVarInt($fp);
        $packetId = readVarInt($fp);
        if ($packetId !== 0x00) { fclose($fp); return ['online'=>false,'error'=>'invalid packet id']; }

        $jsonLen = readVarInt($fp);
        $json = '';
        while (strlen($json) < $jsonLen) {
            $chunk = fread($fp, $jsonLen - strlen($json));
            if ($chunk === '' || $chunk === false) break;
            $json .= $chunk;
        }
        fclose($fp);

        $arr = json_decode($json, true);
        if (!is_array($arr)) return ['online'=>false,'error'=>'invalid json'];

        $lat = (microtime(true) - $start) * 1000.0;
        return [
            'online'         => true,
            'players_online' => (int)($arr['players']['online'] ?? 0),
            'players_max'    => (int)($arr['players']['max'] ?? 0),
            'version'        => (string)($arr['version']['name'] ?? ''),
            'latency_ms'     => round($lat, 1),
            'raw'            => $arr,
        ];
    } catch (Throwable $e) {
        @fclose($fp);
        return ['online'=>false,'error'=>$e->getMessage()];
    }
}

/**
 * Holt den Status aus dem Cache oder pingt live und speichert neu.
 * Gibt zusätzlich 'cached' => bool zurück.
 */
function get_server_status_cached(int $serverId, string $host, int $port, int $ttlSec = STATUS_TTL_SECONDS): array {
    $pdo = db();

    // Cache lesen
    $stmt = $pdo->prepare("SELECT online, players_online, players_max, version, latency_ms, raw_json, checked_at
                           FROM server_status_cache WHERE server_id = ?");
    $stmt->execute([$serverId]);
    $row = $stmt->fetch();

    $now = time();
    $isFresh = false;
    if ($row) {
        $checked = strtotime($row['checked_at'] ?? '1970-01-01 00:00:00');
        $isFresh = ($now - $checked) <= $ttlSec;
    }

    if ($row && $isFresh) {
        return [
            'online'         => (int)$row['online'] === 1,
            'players_online' => isset($row['players_online']) ? (int)$row['players_online'] : null,
            'players_max'    => isset($row['players_max']) ? (int)$row['players_max'] : null,
            'version'        => $row['version'] ?? null,
            'latency_ms'     => isset($row['latency_ms']) ? (float)$row['latency_ms'] : null,
            'raw'            => $row['raw_json'] ? json_decode($row['raw_json'], true) : null,
            'cached'         => true,
        ];
    }

    // Live-Ping (robust)
    try {
        $st = mc_ping_raw($host, $port, 1.5);
    } catch (Throwable $e) {
        $st = ['online'=>false];
    }

    // In DB schreiben/aktualisieren
    $pdo->prepare("
        INSERT INTO server_status_cache (server_id, online, players_online, players_max, version, latency_ms, raw_json, checked_at)
        VALUES (:id, :onl, :pon, :pmx, :ver, :lat, :raw, datetime('now'))
        ON CONFLICT(server_id) DO UPDATE SET
            online=excluded.online,
            players_online=excluded.players_online,
            players_max=excluded.players_max,
            version=excluded.version,
            latency_ms=excluded.latency_ms,
            raw_json=excluded.raw_json,
            checked_at=excluded.checked_at
    ")->execute([
        ':id'  => $serverId,
        ':onl' => !empty($st['online']) ? 1 : 0,
        ':pon' => $st['players_online'] ?? null,
        ':pmx' => $st['players_max'] ?? null,
        ':ver' => $st['version'] ?? null,
        ':lat' => $st['latency_ms'] ?? null,
        ':raw' => isset($st['raw']) ? json_encode($st['raw']) : null,
    ]);

    $st['cached'] = false;
    // Felder normalisieren
    $st['players_online'] = $st['players_online'] ?? null;
    $st['players_max']    = $st['players_max'] ?? null;
    $st['version']        = $st['version'] ?? null;
    $st['latency_ms']     = $st['latency_ms'] ?? null;
    $st['raw']            = $st['raw'] ?? null;

    return $st;
}

/* ========= API: JSON-Status für Auto-Refresh =========
   Abfrage: /index.php?status_json=1
   TTL hier absichtlich 1 Sekunde für „live-ish“ Updates.
*/
if (isset($_GET['status_json'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $servers = db()->query("
            SELECT id, name, host, port
            FROM minecraft_servers
            WHERE enabled = 1
            ORDER BY sort_order, name
        ")->fetchAll();

        $out = [];
        foreach ($servers as $s) {
            $sid  = (int)$s['id'];
            $host = (string)$s['host'];
            $port = (int)$s['port'];
            $st   = get_server_status_cached($sid, $host, $port, 1); // TTL=1s
            $out[] = [
                'id'             => $sid,
                'online'         => !empty($st['online']),
                'players_online' => isset($st['players_online']) ? (int)$st['players_online'] : null,
                'players_max'    => isset($st['players_max']) ? (int)$st['players_max'] : null,
                'version'        => $st['version'] ?? null,
                'latency_ms'     => isset($st['latency_ms']) ? (float)$st['latency_ms'] : null,
                'cached'         => !empty($st['cached']),
            ];
        }
        echo json_encode(['ok'=>true,'servers'=>$out], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    }
    exit;
}

/* ========= Daten laden ========= */
$servers = db()->query("
    SELECT id, name, host, port
    FROM minecraft_servers
    WHERE enabled = 1
    ORDER BY sort_order, name
")->fetchAll();

$posts = db()->query("
    SELECT id, title, content, created_at, image_path
    FROM posts
    WHERE published = 1
    ORDER BY datetime(created_at) DESC
    LIMIT 50
")->fetchAll();

/* ========= Render ========= */
render_header('Startseite');

/* Flash-Meldungen (z. B. nach erfolgreicher Bewerbung) */
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>
<style>
  /* Klickbare Zellen + kleiner Toast */
  .srv-info, .status-cell{ cursor:pointer }
  .srv-info:hover, .status-cell:hover{ text-decoration:underline }
  #toast{
    position:fixed; bottom:18px; left:50%; transform:translateX(-50%);
    background:var(--card); color:var(--text);
    border:1px solid var(--border); border-radius:10px; padding:8px 12px;
    box-shadow:var(--shadow); z-index:1000; opacity:0; transition:opacity .15s ease;
    pointer-events:none; font-size:.95rem
  }
  #toast.show{ opacity:1 }
</style>

<section class="row">
  <div class="card" style="flex:2">
    <h2>Neuigkeiten</h2>
    <?php if (empty($posts)): ?>
      <p><em>Keine Posts vorhanden.</em></p>
    <?php else: ?>
      <?php foreach ($posts as $p): ?>
        <article style="border-bottom:1px solid var(--border); padding:8px 0; margin-bottom:12px">
          <h3 style="margin:0 0 6px"><?=htmlspecialchars($p['title'])?></h3>
          <div style="opacity:.7;font-size:0.9rem;margin-bottom:6px"><?=htmlspecialchars($p['created_at'])?></div>
          <?php if (!empty($p['image_path'])): ?>
            <div style="margin:6px 0 10px">
              <img src="<?=htmlspecialchars($p['image_path'])?>" alt="" style="max-width:100%;height:auto;border:1px solid var(--border);border-radius:8px">
            </div>
          <?php endif; ?>
          <div><?=nl2br(htmlspecialchars($p['content']))?></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="card" style="flex:1">
    <h2 id="server-status-copy" style="cursor:pointer" title="extrahelden.de kopieren">Serverstatus</h2>
    <?php if (empty($servers)): ?>
      <p><em>Keine Server konfiguriert.</em></p>
    <?php else: ?>
      <table id="srv-table">
        <thead><tr><th>Server</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($servers as $s):
              $st = get_server_status_cached((int)$s['id'], $s['host'], (int)$s['port'], STATUS_TTL_SECONDS);
              $ok = !empty($st['online']);
              $statusText = $ok
                ? ('Online: ' . (int)($st['players_online'] ?? 0) . '/' . (int)($st['players_max'] ?? 0)
                   . (!empty($st['version']) ? ' • ' . htmlspecialchars((string)$st['version']) : '')
                   . (isset($st['latency_ms']) ? ' • ' . (float)$st['latency_ms'] . 'ms' : ''))
                : 'Offline';
              if (!empty($st['cached'])) $statusText .= ' (Cache)';
              $color = $ok ? '#0a0' : '#b00';
          ?>
            <tr class="srv-row" data-sid="<?=$s['id']?>" data-host="<?=htmlspecialchars($s['host'])?>">
              <td class="srv-info" title="Klicken zum Kopieren">
                <?=htmlspecialchars($s['name'])?><br>
                <small><?=htmlspecialchars($s['host'])?></small>
              </td>
              <td class="status-cell" style="color:<?=$color?>;font-weight:600" title="Klicken zum Kopieren">
                <?=$statusText?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <!-- Discord-Button -->
      <p style="margin-top:12px">
        <a class="btn btn-primary" href="https://discord.gg/FaMFTsMYeG" target="_blank" rel="noopener">Zum Discord</a>
      </p>

      <!--
      <p style="margin-top:8px"><small>Aktualisiert automatisch – 1× pro Sekunde.</small></p>
      <p><small>Klick auf „Serverstatus“ kopiert <code>extrahelden.de</code>. Klick auf Servername/Status kopiert die jeweilige IP.</small></p>
      -->
    <?php endif; ?>
  </div>
</section>

<div id="toast" role="status" aria-live="polite"></div>
<a href="impressum.html">Impressum</a>

<script>
(function(){
  // kleiner Toast
  const toastEl = document.getElementById('toast');
  function toast(msg){
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    setTimeout(()=>toastEl.classList.remove('show'), 1200);
  }
  async function copyText(txt){
    try{
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(txt);
      } else {
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        document.execCommand('copy'); ta.remove();
      }
      toast('IP kopiert: ' + txt);
    } catch(e){
      toast('Kopieren nicht möglich');
    }
  }

  // Überschrift klick → extrahelden.de
  const head = document.getElementById('server-status-copy');
  if (head) head.addEventListener('click', ()=>copyText('extrahelden.de'));

  // Delegiertes Kopieren: Klick auf Servername/Status → Host aus Zeile kopieren
  const table = document.getElementById('srv-table');
  if (table){
    table.addEventListener('click', (ev)=>{
      const cell = ev.target.closest('.srv-info, .status-cell');
      if (!cell) return;
      const row = cell.closest('tr.srv-row');
      const host = row?.dataset?.host;
      if (host) copyText(host);
    });
  }

  // ---- Auto-Refresh: pollt ?status_json=1 jede Sekunde ----
  if (!table) return;

  const rowMap = new Map();
  table.querySelectorAll('tr.srv-row').forEach(tr=>{
    rowMap.set(tr.dataset.sid, {row: tr, statusCell: tr.querySelector('.status-cell')});
  });

  function renderStatusText(d){
    if (!d || !('online' in d)) return '—';
    if (!d.online) return 'Offline' + (d.cached ? ' (Cache)' : '');
    let t = 'Online: ' + (d.players_online ?? 0) + '/' + (d.players_max ?? 0);
    if (d.version) t += ' • ' + d.version;
    if (typeof d.latency_ms !== 'undefined' && d.latency_ms !== null) t += ' • ' + d.latency_ms + 'ms';
    if (d.cached) t += ' (Cache)';
    return t;
  }

  let inFlight = false;
  async function poll(){
    if (inFlight) return; inFlight = true;
    try{
      const res = await fetch('index.php?status_json=1', {cache:'no-store'});
      if (!res.ok) return;
      const js = await res.json();
      const list = js && js.servers ? js.servers : [];
      list.forEach(d=>{
        const ref = rowMap.get(String(d.id));
        if (!ref) return;
        ref.statusCell.textContent = renderStatusText(d);
        ref.statusCell.style.color = d.online ? '#0a0' : '#b00';
        ref.statusCell.style.fontWeight = '600';
      });
    }catch(e){
      /* still show old values */
    }finally{
      inFlight = false;
      setTimeout(poll, 1000);
    }
  }
  poll();
})();
</script>

<?php render_footer(); ?>
