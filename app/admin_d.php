<?php
// admin_d.php – Verwaltung & Linked-Hearts mit Live-API UUID Abfrage
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

$pdo = db();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/**
 * NEU: Holt API-Daten und filtert gültige Spieler
 */
function getValidPlayersFromApi(array $players): array {
    $names = array_column($players, 'username');
    $payload = implode(',', $names);

    $ch = curl_init('http://extrahelden.de:8965/api/get');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return [];

    $result = [];
    foreach ($players as $p) {
        $name = $p['username'];

        preg_match('/name:' . preg_quote($name, '/') . '.*?hearts:(.*?);.*?linkedheart_activated:(.*?);/s', $response, $m);

        $hearts = (float)($m[1] ?? 0);
        $linked = trim($m[2] ?? 'true') === 'true';

        if ($hearts > 0 && !$linked) {
            $result[] = $p;
        }
    }

    return $result;
}

/**
 * Zählt die aktuellen Spieler in der Datenbank
 */
function getActivePlayerCount(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_player = 1")->fetchColumn();
}

/**
 * Holt die UUID zu einem Minecraft-Namen via Mojang API
 */
function getUuidByName(string $name): string {
    $url = "https://api.mojang.com/users/profiles/minecraft/" . urlencode($name);
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    
    if ($resp) {
        $data = json_decode($resp, true);
        if (!empty($data['id'])) {
            return $data['id']; 
        }
    }
    return $name; 
}

/**
 * Kernlogik zur Paarbildung und Dateierstellung
 */
function generateLinkedHearts(array $players): void {

    $logFile = '/var/www/html/remlog.txt';

    function logmsg($msg) {
        global $logFile;
        file_put_contents($logFile, "[" . date("Y-m-d H:i:s") . "] " . $msg . "\n", FILE_APPEND);
    }

    logmsg("=== START REMATCH ===");

    // === 1. API CALL ===
    $names = array_column($players, 'username');
    $payload = implode(',', $names);

    $ch = curl_init('http://extrahelden.de:8965/api/get');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) {
        logmsg("API RESPONSE EMPTY");
        return;
    }

    logmsg("API RAW: " . $response);

    // === 2. PARSE API ===
    $apiData = [];
    $parts = explode(';', trim($response));

    $current = [];
    foreach ($parts as $part) {
        if (strpos($part, ':') === false) continue;

        list($k, $v) = explode(':', $part, 2);
        $current[$k] = $v;

        if (isset($current['name'], $current['hearts'], $current['linkedheart_activated'])) {
            $apiData[$current['name']] = [
                'hearts' => (float)$current['hearts'],
                'linked' => trim($current['linkedheart_activated']) === 'true'
            ];
            $current = [];
        }
    }

    logmsg("API PARSED: " . json_encode($apiData));

    // === 3. LOAD EXISTING ===
    $file = '/usr/share/icons/cecolor/plain/linkedhearts.txt';
    $existing = [];

    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);

        if (!empty($json['linked_hearts']['headOverrides'])) {
            foreach ($json['linked_hearts']['headOverrides'] as $entry) {
                if (preg_match('/name:(.*?)\-\>(.*)/', $entry, $m)) {
                    $existing[$m[1]] = $m[2];
                }
            }
        }
    }

    logmsg("EXISTING: " . json_encode($existing));

    $used = [];
    $fixedPairs = [];
    $rematchPool = [];

    // === 4. SPLIT LOGIC ===
    foreach ($existing as $a => $b) {

        if (isset($used[$a]) || isset($used[$b])) continue;

        $aData = $apiData[$a] ?? ['hearts'=>0,'linked'=>true];
        $bData = $apiData[$b] ?? ['hearts'=>0,'linked'=>true];

        $isFixed =
            $aData['hearts'] <= 0 || $bData['hearts'] <= 0 ||
            $aData['linked'] || $bData['linked'];

        if ($isFixed) {
            $fixedPairs[] = [$a, $b];
            $used[$a] = $used[$b] = true;
        } else {
            $rematchPool[] = $a;
            $rematchPool[] = $b;
            $used[$a] = $used[$b] = true;
        }
    }

    logmsg("FIXED: " . json_encode($fixedPairs));
    logmsg("REMATCH POOL BEFORE NEW: " . json_encode($rematchPool));

    // === 5. ADD NEW PLAYERS ===
    foreach ($players as $p) {
        $name = $p['username'];

        if (isset($used[$name])) continue;

        $data = $apiData[$name] ?? null;
        if (!$data) continue;

        if ($data['hearts'] > 0 && !$data['linked']) {
            $rematchPool[] = $name;
        }
    }

    logmsg("REMATCH POOL FINAL: " . json_encode($rematchPool));

    // === 6. REMATCH ===
    shuffle($rematchPool);

    $newPairs = [];
    for ($i = 0; $i < count($rematchPool); $i += 2) {
        if (isset($rematchPool[$i+1])) {
            $newPairs[] = [$rematchPool[$i], $rematchPool[$i+1]];
        }
    }

    logmsg("NEW PAIRS: " . json_encode($newPairs));

    // === 7. MERGE ===
    $overrides = [];

    foreach (array_merge($fixedPairs, $newPairs) as [$a, $b]) {
        $overrides[] = "name:$a->$b";
        $overrides[] = "name:$b->$a";
    }

    logmsg("FINAL OVERRIDES: " . json_encode($overrides));

    // === 8. SAVE ===
    $json = json_encode([
        "linked_hearts" => [
            "headOverrides" => $overrides
        ]
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    file_put_contents('/usr/share/icons/cecolor/plain/linkedhearts.txt', $json);

    logmsg("=== END REMATCH ===");
}
// POST-Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        flash("Ungültiges CSRF-Token.", "error");
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_settings') {
            $maxPlayers = (int)($_POST['max_players_count'] ?? 0);
            set_setting('max_players_count', (string)$maxPlayers);
            
            if ($maxPlayers > 0) {
                $stmt = $pdo->prepare("SELECT username FROM users WHERE is_player = 1 AND username IS NOT NULL");
                $stmt->execute();
                $players = $stmt->fetchAll();

                if (count($players) >= $maxPlayers) {

                    // NEU: Filter über API
                    $players = getValidPlayersFromApi($players);

                    if (count($players) >= 2) {
                        generateLinkedHearts($players);
                        flash("Gefilterte Spieler neu gematcht!", "success");
                    } else {
                        flash("Nicht genug gültige Spieler für Rematch.", "error");
                    }
                }
            }
            flash("Einstellungen gespeichert.", "success");
        }
    }
}

render_header("Admin Dashboard");
$cfg_max = get_setting('max_players_count', '0');
$currentPlayerCount = getActivePlayerCount($pdo);
?>
<section class="row">
  <div class="card">
    <h2>Linked-Hearts & API Integration</h2>
    
    <div style="margin-bottom: 1.5rem; padding: 1rem; background: rgba(0,0,0,0.05); border-radius: 4px;">
        <strong>Status:</strong> Aktuell sind <strong><?= $currentPlayerCount ?></strong> Spieler registriert.
    </div>

    <?php foreach (consume_flashes() as [$type, $msg]): ?>
      <div class="flash <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endforeach; ?>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
      <input type="hidden" name="action" value="save_settings">
      
      <label>Benötigte Spieleranzahl (Zielwert)<br>
        <input type="number" name="max_players_count" value="<?= htmlspecialchars($cfg_max) ?>" min="0">
      </label>
      <button class="btn btn-primary" type="submit">Speichern & Generieren</button>
    </form>
    <p><small>Hinweis: Die Generierung wird erst ausgelöst, wenn die registrierten Spieler die Zielanzahl erreichen.</small></p>
  </div>
</section>
<?php render_footer(); ?>