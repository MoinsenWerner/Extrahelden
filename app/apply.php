<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* -------- Ensure tables/settings (idempotent) -------- */
function ensure_apply_schema(): void {
    $pdo = db();

    // Settings + Applications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS site_settings (
          key TEXT PRIMARY KEY,
          value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS applications (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          youtube_url TEXT NOT NULL,
          outube_video_id TEXT,
          mc_name TEXT NOT NULL,
          mc_uuid TEXT,
          discord_name TEXT NOT NULL,
          status TEXT NOT NULL DEFAULT 'pending',
          generated_password TEXT,
          created_user_id INTEGER,
          project_name TEXT,
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Defaults
    $pdo->prepare("INSERT OR IGNORE INTO site_settings(key, value) VALUES('apply_enabled','0')")->execute();
    $pdo->prepare("INSERT OR IGNORE INTO site_settings(key, value) VALUES('apply_title','Projekt-Anmeldung')")->execute();

    // Columns nachrüsten (falls alte DB)
    $cols = $pdo->query("PRAGMA table_info(applications)")->fetchAll();
    $have = array_map(fn($r) => $r['name'], $cols);
    if (!in_array('generated_password', $have, true)) {
        $pdo->exec("ALTER TABLE applications ADD COLUMN generated_password TEXT");
    }
    if (!in_array('created_user_id', $have, true)) {
        $pdo->exec("ALTER TABLE applications ADD COLUMN created_user_id INTEGER");
    }
    if (!in_array('project_name', $have, true)) {
        $pdo->exec("ALTER TABLE applications ADD COLUMN project_name TEXT");
    }

    // Einmal-Bewerbung (Unique pro MC-Name / Discord-Name)
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_app_unique_mc ON applications(lower(mc_name));");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_app_unique_discord ON applications(lower(discord_name));");
}
ensure_apply_schema();

$enabled = (get_setting('apply_enabled','0') === '1');
$title   = get_setting('apply_title','Projekt-Anmeldung');

/* ------------------ Helpers ------------------ */
function extract_youtube_id_strict(string $url): ?string {
    // Muss mit https://www.youtube.com/watch? beginnen, gültiger v-Parameter
    if (stripos($url, 'https://www.youtube.com/watch?') !== 0) return null;
    $parts = parse_url($url);
    if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'www.youtube.com' || ($parts['path'] ?? '') !== '/watch') {
        return null;
    }
    parse_str($parts['query'] ?? '', $q);
    $v = $q['v'] ?? '';
    return (is_string($v) && preg_match('~^[A-Za-z0-9_-]{6,}$~', $v)) ? $v : null;
}
function mcname_is_valid_syntax(string $name): bool {
    return (bool)preg_match('/^[A-Za-z0-9_]{3,16}$/', $name);
}

/**
 * HTTP GET (cURL bevorzugt, sonst stream). Rückgabe: [statusCode, body] oder null bei hartem Fehler.
 */
function http_get(string $url, int $timeoutSec = 4): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => $timeoutSec,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'ProjectApply/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => false,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            error_log('HTTP cURL error for '.$url.': '.curl_error($ch));
            curl_close($ch);
            return null;
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $hsz  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = $hsz ? substr($resp, $hsz) : $resp;
        curl_close($ch);
        return [$code, $body];
    }

    $ctx  = stream_context_create(['http'=>['timeout'=>$timeoutSec, 'ignore_errors'=>true, 'header'=>"Accept: application/json\r\n"]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        error_log('HTTP fopen error for '.$url);
        return null;
    }
    $code = 0;
    if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m)) $code = (int)$m[1];
    return [$code, $body];
}

/**
 * MC-UUID-Lookup: Mojang → Ashcon → PlayerDB
 * Rückgabe: ['status' => 'ok'|'not_found'|'error', 'uuid' => string|null]
 */
function lookup_mc_uuid(string $name): array {
    // Mojang
    $r = http_get('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($name), 4);
    if ($r !== null) {
        [$code, $body] = $r;
        if ($code === 200 && $body) {
            $j = json_decode($body, true);
            if (isset($j['id']) && preg_match('/^[0-9a-fA-F]{32}$/', $j['id'])) {
                return ['status'=>'ok','uuid'=>strtolower($j['id'])];
            }
        } elseif ($code === 204 || $code === 404) {
            return ['status'=>'not_found','uuid'=>null];
        }
    }

    // Ashcon
    $r = http_get('https://api.ashcon.app/mojang/v2/user/' . rawurlencode($name), 4);
    if ($r !== null) {
        [$code, $body] = $r;
        if ($code === 200 && $body) {
            $j = json_decode($body, true);
            if (isset($j['uuid']) && preg_match('/^[0-9a-fA-F-]{32,36}$/', $j['uuid'])) {
                $uuid = strtolower(str_replace('-', '', $j['uuid']));
                return ['status'=>'ok','uuid'=>$uuid];
            }
        } elseif ($code === 404) {
            return ['status'=>'not_found','uuid'=>null];
        }
    }

    // PlayerDB
    $r = http_get('https://playerdb.co/api/player/minecraft/' . rawurlencode($name), 4);
    if ($r !== null) {
        [$code, $body] = $r;
        if ($code === 200 && $body) {
            $j = json_decode($body, true);
            if (!empty($j['success']) && isset($j['data']['player']['id'])) {
                $uuid = strtolower(str_replace('-', '', (string)$j['data']['player']['id']));
                if (preg_match('/^[0-9a-fA-F]{32}$/', $uuid)) return ['status'=>'ok','uuid'=>$uuid];
            } elseif (isset($j['code']) && $j['code'] === 'player.found') {
                $uuid = strtolower(str_replace('-', '', (string)($j['data']['player']['id'] ?? '')));
                if (preg_match('/^[0-9a-fA-F]{32}$/', $uuid)) return ['status'=>'ok','uuid'=>$uuid];
            } elseif (isset($j['code']) && $j['code'] === 'player.missing') {
                return ['status'=>'not_found','uuid'=>null];
            }
        }
    }

    return ['status'=>'error','uuid'=>null];
}

/* ------------------ Verarbeitung ------------------ */
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$enabled) { flash('Die Projekt-Anmeldung ist derzeit deaktiviert.','error'); header('Location: index.php'); exit; }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        $errors[] = 'Ungültiges CSRF-Token, bitte Formular neu laden.';
    } else {
        $youtube = trim((string)($_POST['youtube'] ?? ''));
        $mcname  = trim((string)($_POST['mcname'] ?? ''));
        $discord = trim((string)($_POST['discord'] ?? ''));

        // YouTube (strikt)
        $ytId = extract_youtube_id_strict($youtube);
        if (!$ytId) {
            $errors[] = 'Der Link muss mit https://www.youtube.com/watch? beginnen und einen gültigen v-Parameter enthalten.';
        }

        // Minecraft
        $uuid = null;
        if ($mcname === '' || !mcname_is_valid_syntax($mcname)) {
            $errors[] = 'Bitte gib einen gültigen Minecraft-Java-Namen an (3–16 Zeichen, A–Z, 0–9, _).';
        } else {
            $res = lookup_mc_uuid($mcname);
            if ($res['status'] === 'ok') {
                $uuid = $res['uuid'];
            } elseif ($res['status'] === 'not_found') {
                $errors[] = 'Der angegebene Minecraft-Account existiert nicht.';
            } else {
                $errors[] = 'Verifizierung des Minecraft-Accounts ist derzeit nicht möglich. Bitte später erneut versuchen.';
            }
        }

        // Discord
        if ($discord === '' || strlen($discord) < 2) {
            $errors[] = 'Bitte gib deinen Discord-Namen an.';
        }

        // Einmal-Bewerbung (Vorprüfung)
        if (!$errors) {
            $pdo = db();
            $dup = $pdo->prepare("SELECT 1 FROM applications WHERE lower(mc_name)=? OR lower(discord_name)=? LIMIT 1");
            $dup->execute([mb_strtolower($mcname), mb_strtolower($discord)]);
            if ($dup->fetch()) {
                $errors[] = 'Du hast dich bereits beworben. Doppelbewerbungen sind nicht erlaubt.';
            }
        }

        if (!$errors) {
            try {
                //$ytId = extract_youtube_id_strict($youtube);
                trigger_auto_task('new_application', ['player' => $mcname, 'discord' => $discord, 'video' => $youtube]);
                $project = get_setting('apply_title','Projekt-Anmeldung'); // Projektname zum Zeitpunkt der Bewerbung
                $st = db()->prepare("
                    INSERT INTO applications (youtube_url, youtube_video_id, mc_name, mc_uuid, discord_name, status, project_name)
                    VALUES (?, ?, ?, ?, ?, 'pending', ?)
                ");
                $st->execute([$youtube, $ytId, $mcname, $uuid, $discord, $project]);

                flash('Bewerbung erfolgreich übermittelt. Wir melden uns auf Discord.', 'success');
                header('Location: index.php?applied=1');
                exit;
            } catch (Throwable $e) {
                // UNIQUE-Fehler als Double-Apply behandeln
                if (stripos($e->getMessage(), 'UNIQUE') !== false) {
                    $errors[] = 'Du hast dich bereits beworben. Doppelbewerbungen sind nicht erlaubt.';
                } else {
                    error_log('APPLY DB insert error: '.$e->getMessage());
                    $errors[] = 'Interner Fehler beim Speichern. Bitte später erneut versuchen.';
                }
            }
        }
    }
}

/* ------------------ Render ------------------ */
render_header($title);
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}

if (!$enabled): ?>
  <section class="row">
    <div class="card" style="max-width:680px">
      <h2><?=htmlspecialchars($title)?> Bewerbung</h2>
      <p><em>Die Anmeldung ist derzeit geschlossen.</em></p>
    </div>
  </section>
<?php else: ?>
  <section class="row">
    <div class="card" style="max-width:680px">
      <h2><?=htmlspecialchars($title)?> Bewerbung</h2>

      <?php if (!empty($errors)): ?>
        <div class="flash error">
          <strong>Bitte behebe folgende Punkte:</strong>
          <ul><?php foreach ($errors as $e) echo '<li>'.htmlspecialchars($e).'</li>'; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
        <label>Bewerbungsvideo (YouTube-Link)<br>
          <input type="url" name="youtube" placeholder="https://www.youtube.com/watch?v=VIDEOID" required>
        </label><br><br>
        <label>McName (Minecraft Java)<br>
          <input type="text" name="mcname" placeholder="Spielername" required>
        </label><br><br>
        <label>Discord Name<br>
          <input type="text" name="discord" placeholder="z. B. Felix oder Felix#1234" required>
        </label><br><br>
        <button class="btn btn-primary" type="submit">Bewerbung absenden</button>
        <a href="index.php" class="btn">Zurück</a>
      </form>
      <p style="margin-top:8px"><small>Nur Links wie <code>https://www.youtube.com/watch?v=…</code> werden akzeptiert. Der Minecraft-Account wird online verifiziert. Pro Minecraft- oder Discord-Name ist nur eine Bewerbung möglich.</small></p>
    </div>
  </section>
<?php endif; ?>
<?php render_footer(); ?>
