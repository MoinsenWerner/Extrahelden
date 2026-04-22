<?php
// admin_calendar.php – Admin-Kalender mit Wiederholungen, Owner-Rechten, Farben & Detail-Seitenleiste
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

$ME    = current_user();                 // [id, username, is_admin]
$MY_ID = (int)($ME['id'] ?? 0);

/* ==========================================
   Schema-Upgrade / -Sicherung (idempotent)
   - nutzt start_at / end_at
   - backfillt alte Spalten start / end
   ========================================== */
function ensure_calendar_schema(): void {
    $pdo = db();

    // users.calendar_color
    $uCols  = $pdo->query("PRAGMA table_info(users)")->fetchAll();
    $uNames = array_map(fn($r)=>$r['name'], $uCols);
    if (!in_array('calendar_color', $uNames, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN calendar_color TEXT");
    }

    // Haupttabelle
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_events (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          owner_id INTEGER,
          title TEXT,
          type TEXT,                         -- 'event' | 'absence'
          start_at TEXT,                     -- ISO-String
          end_at   TEXT,                     -- ISO-String
          all_day INTEGER NOT NULL DEFAULT 0,
          color   TEXT,
          notes   TEXT,
          rec_interval_weeks INTEGER NOT NULL DEFAULT 0,
          rec_weekdays TEXT,                 -- CSV: MO,TU,WE,TH,FR,SA,SU
          rec_until TEXT,                    -- YYYY-MM-DD
          created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");

    // Fehlende Spalten ergänzen (falls Tabelle älter ist)
    $cols  = $pdo->query("PRAGMA table_info(calendar_events)")->fetchAll();
    $names = array_map(fn($r)=>$r['name'], $cols);
    $add = function(string $sql) use ($pdo){ try { $pdo->exec($sql); } catch (Throwable $e) {} };

    foreach ([
        'owner_id INTEGER',
        'title TEXT',
        'type TEXT',
        'start_at TEXT',
        'end_at TEXT',
        'all_day INTEGER NOT NULL DEFAULT 0',
        'color TEXT',
        'notes TEXT',
        'rec_interval_weeks INTEGER NOT NULL DEFAULT 0',
        'rec_weekdays TEXT',
        'rec_until TEXT',
        'created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ] as $def) {
        [$col] = explode(' ', $def, 2);
        if (!in_array($col, $names, true)) $add("ALTER TABLE calendar_events ADD COLUMN $def");
    }

    // Falls es aus sehr alten Versionen noch start/end gibt: Inhalte nach start_at/end_at kopieren
    $hadStart = in_array('start', $names, true);
    $hadEnd   = in_array('end',   $names, true);
    if ($hadStart) { try { $pdo->exec("UPDATE calendar_events SET start_at = COALESCE(start_at, start) WHERE start_at IS NULL AND start IS NOT NULL"); } catch (Throwable $e) {} }
    if ($hadEnd)   { try { $pdo->exec("UPDATE calendar_events SET end_at   = COALESCE(end_at,   end)   WHERE end_at   IS NULL AND end   IS NOT NULL"); } catch (Throwable $e) {} }

    // Overrides (Einzelinstanz-Änderungen) & Exceptions (Einzelinstanz-Löschungen)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_event_overrides (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          event_id INTEGER NOT NULL,
          inst_date TEXT NOT NULL,          -- YYYY-MM-DD der Instanz
          start_at TEXT, end_at TEXT, all_day INTEGER,
          title TEXT, notes TEXT,
          FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
          UNIQUE(event_id, inst_date)
        );
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS calendar_event_exceptions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          event_id INTEGER NOT NULL,
          inst_date TEXT NOT NULL,
          FOREIGN KEY (event_id) REFERENCES calendar_events(id) ON DELETE CASCADE,
          UNIQUE(event_id, inst_date)
        );
    ");

    // Backfill auch für Overrides, falls dort noch start/end existieren
    $oCols  = $pdo->query("PRAGMA table_info(calendar_event_overrides)")->fetchAll();
    $oNames = array_map(fn($r)=>$r['name'], $oCols);
    if (in_array('start', $oNames, true) && !in_array('start_at', $oNames, true)) { $add("ALTER TABLE calendar_event_overrides ADD COLUMN start_at TEXT"); }
    if (in_array('end',   $oNames, true) && !in_array('end_at',   $oNames, true)) { $add("ALTER TABLE calendar_event_overrides ADD COLUMN end_at   TEXT"); }
    try { $pdo->exec("UPDATE calendar_event_overrides SET start_at = COALESCE(start_at, start) WHERE start_at IS NULL AND start IS NOT NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("UPDATE calendar_event_overrides SET end_at   = COALESCE(end_at,   end)   WHERE end_at   IS NULL AND end   IS NOT NULL"); } catch (Throwable $e) {}
}
ensure_calendar_schema();

/* ========= Farben ========= */
function user_color(int $uid): string {
    $st = db()->prepare("SELECT calendar_color FROM users WHERE id=?");
    $st->execute([$uid]);
    $c = (string)($st->fetchColumn() ?: '');
    if ($c !== '') return $c;

    // deterministisch aus UID wählen
    $palette = ['#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#14b8a6','#eab308','#f43f5e','#22c55e','#0ea5e9','#a855f7','#84cc16'];
    $pick = $palette[$uid % count($palette)];
    db()->prepare("UPDATE users SET calendar_color=? WHERE id=?")->execute([$pick, $uid]);
    return $pick;
}

/* ========= Helpers ========= */
function esc(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function parse_bool($v): int { return (isset($v) && ($v==='1' || $v===1 || $v===true || $v==='true' || $v==='on')) ? 1 : 0; }
function csv_to_array(?string $csv): array { if (!$csv) return []; return array_values(array_filter(array_map('trim', explode(',', strtoupper($csv))))); }
function array_to_csv(array $arr): string { $arr = array_values(array_unique(array_map(fn($x)=>strtoupper(trim((string)$x)), $arr))); return implode(',', $arr); }
function ymd(DateTime $d): string { return $d->format('Y-m-d'); }
/** Wochenabstand (Montag-basiert) */
function weeks_between_mondays(DateTime $a, DateTime $b): int {
    $a = (clone $a)->modify('monday this week')->setTime(0,0,0);
    $b = (clone $b)->modify('monday this week')->setTime(0,0,0);
    return (int)floor(($b->getTimestamp() - $a->getTimestamp()) / 604800);
}
/** Instanz-ID für FullCalendar */
function inst_id(int $eventId, string $date): string { return "e{$eventId}@{$date}"; }

/* =========================
   API: list/get/create/update/delete
   ========================= */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = $MY_ID;

    try {
        $api = $_GET['api'];

        if ($api === 'list') {
            $start = new DateTime($_GET['start'] ?? 'first day of this month', new DateTimeZone('UTC'));
            $end   = new DateTime($_GET['end']   ?? 'last day of this month',  new DateTimeZone('UTC'));

            $rows = db()->query("
                SELECT e.*, u.username AS owner_name
                FROM calendar_events e
                JOIN users u ON u.id = e.owner_id
            ")->fetchAll();

            $ov = db()->query("SELECT * FROM calendar_event_overrides")->fetchAll();
            $ex = db()->query("SELECT * FROM calendar_event_exceptions")->fetchAll();
            $ovMap = [];
            foreach ($ov as $o) { $ovMap[(int)$o['event_id']][(string)$o['inst_date']] = $o; }
            $exMap = [];
            foreach ($ex as $x) { $exMap[(int)$x['event_id']][(string)$x['inst_date']] = true; }

            $out = [];
            foreach ($rows as $e) {
                $eid = (int)$e['id'];
                $allDay = ((int)$e['all_day']===1);
                // ACHTUNG: neue Spalten
                $baseStart = new DateTime($e['start_at'], new DateTimeZone('UTC'));
                $baseEnd   = new DateTime($e['end_at'],   new DateTimeZone('UTC'));
                $color = $e['color'] ?: user_color((int)$e['owner_id']);

                $recWeeks = (int)($e['rec_interval_weeks'] ?? 0);
                $weekdays = csv_to_array((string)($e['rec_weekdays'] ?? ''));
                $until = !empty($e['rec_until']) ? new DateTime($e['rec_until'].' 23:59:59', new DateTimeZone('UTC')) : null;

                $emit = function(DateTime $s, DateTime $en, ?array $override, ?string $instDate) use (&$e,$color,$uid,$allDay) {
                    $id  = $instDate ? inst_id((int)$e['id'], $instDate) : (string)$e['id'];
                    $ttl = $override['title'] ?? $e['title'];
                    $nts = $override['notes'] ?? $e['notes'];
                    $ss  = !empty($override['start_at']) ? new DateTime($override['start_at']) : $s;
                    $ee  = !empty($override['end_at'])   ? new DateTime($override['end_at'])   : $en;
                    return [
                        'id'    => $id,
                        'title' => $ttl,
                        'start' => $ss->format('c'),
                        'end'   => $ee->format('c'),
                        'allDay'=> $override['all_day']!==null ? ((int)$override['all_day']===1) : $allDay,
                        'backgroundColor' => $color,
                        'borderColor'     => $color,
                        'extendedProps'   => [
                            'type' => $e['type'],
                            'notes'=> $nts,
                            'owner_id'   => (int)$e['owner_id'],
                            'owner_name' => $e['owner_name'],
                            'can_edit'   => ((int)$e['owner_id'] === $uid),
                            'series_id'  => (int)$e['id'],
                            'rec_interval_weeks' => (int)($e['rec_interval_weeks'] ?? 0),
                            'rec_weekdays'       => csv_to_array((string)$e['rec_weekdays']),
                            'rec_desc'           => ($e['rec_interval_weeks']>0 ? "alle {$e['rec_interval_weeks']} Woche(n) am ".implode(', ',csv_to_array((string)$e['rec_weekdays'])) : ''),
                            'inst_date' => $instDate
                        ]
                    ];
                };

                if ($recWeeks > 0 && !empty($weekdays)) {
                    $iter = (clone $start)->setTime(0,0,0);
                    $seriesAnchor = (clone $baseStart);
                    while ($iter < $end) {
                        $dow = strtoupper($iter->format('D')); // MON,TUE...
                        $map = ['MON'=>'MO','TUE'=>'TU','WED'=>'WE','THU'=>'TH','FRI'=>'FR','SAT'=>'SA','SUN'=>'SU'];
                        $wd  = $map[$dow] ?? '';
                        $instDate = ymd($iter);

                        if (in_array($wd, $weekdays, true) && $iter >= (clone $baseStart)->setTime(0,0,0)) {
                            if ($until && $iter > $until) break;

                            $wDiff = weeks_between_mondays($seriesAnchor, $iter);
                            if ($wDiff % $recWeeks === 0) {
                                if (!empty($exMap[$eid][$instDate])) { $iter->modify('+1 day'); continue; }

                                $s = (clone $iter)->setTime((int)$baseStart->format('H'), (int)$baseStart->format('i'), (int)$baseStart->format('s'));
                                if ($allDay) {
                                    $en = (clone $s)->modify('+1 day');
                                } else {
                                    $en = (clone $iter)->setTime((int)$baseEnd->format('H'), (int)$baseEnd->format('i'), (int)$baseEnd->format('s'));
                                }
                                $override = $ovMap[$eid][$instDate] ?? null;
                                $out[] = $emit($s,$en,$override,$instDate);
                            }
                        }
                        $iter->modify('+1 day');
                    }
                } else {
                    if ($baseEnd > $start && $baseStart < $end) {
                        $out[] = $emit($baseStart,$baseEnd,null,null);
                    }
                }
            }

            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($api === 'get') {
            $id = (string)($_GET['id'] ?? '');
            if ($id === '') throw new RuntimeException('id fehlt');

            if (preg_match('/^e(\d+)@(\d{4}-\d{2}-\d{2})$/', $id, $m)) {
                $eid = (int)$m[1]; $inst = $m[2];
                $e = db()->prepare("SELECT * FROM calendar_events WHERE id=?"); $e->execute([$eid]); $ev=$e->fetch();
                if (!$ev) throw new RuntimeException('Event nicht gefunden');
                $ov = db()->prepare("SELECT * FROM calendar_event_overrides WHERE event_id=? AND inst_date=?");
                $ov->execute([$eid,$inst]); $o=$ov->fetch();
                $ev['inst_date']=$inst; $ev['override']=$o ?: null; $ev['owner_can_edit']=((int)$ev['owner_id']===$MY_ID);
                $ev['owner_color'] = user_color((int)$ev['owner_id']);
                echo json_encode(['ok'=>true,'event'=>$ev]); exit;
            } else {
                $e = db()->prepare("SELECT * FROM calendar_events WHERE id=?"); $e->execute([(int)$id]); $ev=$e->fetch();
                if (!$ev) throw new RuntimeException('Event nicht gefunden');
                $ev['owner_can_edit']=((int)$ev['owner_id']===$MY_ID);
                $ev['owner_color'] = user_color((int)$ev['owner_id']);
                echo json_encode(['ok'=>true,'event'=>$ev]); exit;
            }
        }

        if ($api === 'create' && $_SERVER['REQUEST_METHOD']==='POST') {
            $title = trim((string)($_POST['title'] ?? ''));
            $type  = in_array($_POST['type'] ?? 'event', ['event','absence'], true) ? (string)$_POST['type'] : 'event';
            $all   = parse_bool($_POST['all_day'] ?? '0');

            $dateStart = (string)($_POST['date_start'] ?? '');
            $timeStart = (string)($_POST['time_start'] ?? '00:00');
            $dateEnd   = (string)($_POST['date_end']   ?? $dateStart);
            $timeEnd   = (string)($_POST['time_end']   ?? '00:00');

            if ($title==='' || $dateStart==='') throw new RuntimeException('Titel/Startdatum fehlt');

            $startAt = $all ? "{$dateStart} 00:00:00" : "{$dateStart} {$timeStart}:00";
            $endAt   = $all ? (date('Y-m-d', strtotime($dateStart.' +1 day')).' 00:00:00') : "{$dateEnd} {$timeEnd}:00";

            $notes = (string)($_POST['notes'] ?? '');
            $color = null;

            $rec_int   = max(0, (int)($_POST['rec_interval_weeks'] ?? 0));
            $rec_wds   = isset($_POST['rec_weekdays']) ? array_to_csv((array)$_POST['rec_weekdays']) : '';
            $rec_until = (string)($_POST['rec_until'] ?? '');
            if ($rec_int === 0) { $rec_wds=''; $rec_until=''; }

            $st = db()->prepare("INSERT INTO calendar_events
                (owner_id,title,type,start_at,end_at,all_day,color,notes,rec_interval_weeks,rec_weekdays,rec_until)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->execute([$MY_ID,$title,$type,$startAt,$endAt,$all,$color,$notes,$rec_int,$rec_wds,($rec_until?:null)]);
            echo json_encode(['ok'=>true]); exit;
        }

        if ($api === 'update' && $_SERVER['REQUEST_METHOD']==='POST') {
            $idRaw = (string)($_POST['id'] ?? '');
            $scopeSeries = parse_bool($_POST['scope_series'] ?? '0'); // 1 = ganze Serie, 0 = nur diese Instanz

            $title = trim((string)($_POST['title'] ?? ''));
            $type  = in_array($_POST['type'] ?? 'event', ['event','absence'], true) ? (string)$_POST['type'] : 'event';
            $all   = parse_bool($_POST['all_day'] ?? '0');
            $dateStart = (string)($_POST['date_start'] ?? '');
            $timeStart = (string)($_POST['time_start'] ?? '00:00');
            $dateEnd   = (string)($_POST['date_end']   ?? $dateStart);
            $timeEnd   = (string)($_POST['time_end']   ?? '00:00');
            $notes = (string)($_POST['notes'] ?? '');

            $startAt = $all ? "{$dateStart} 00:00:00" : "{$dateStart} {$timeStart}:00";
            $endAt   = $all ? (date('Y-m-d', strtotime($dateStart.' +1 day')).' 00:00:00') : "{$dateEnd} {$timeEnd}:00";

            if (preg_match('/^e(\d+)@(\d{4}-\d{2}-\d{2})$/', $idRaw, $m)) {
                $eid = (int)$m[1]; $inst = $m[2];
            } else { $eid = (int)$idRaw; $inst = null; }

            $r = db()->prepare("SELECT owner_id FROM calendar_events WHERE id=?"); $r->execute([$eid]);
            $owner = (int)($r->fetchColumn() ?? 0);
            if ($owner !== $MY_ID) throw new RuntimeException('Nur eigene Termine bearbeitbar.');

            if ($inst && !$scopeSeries) {
                // Einzelinstanz-Override
                $st = db()->prepare("
                    INSERT INTO calendar_event_overrides (event_id,inst_date,start_at,end_at,all_day,title,notes)
                    VALUES (?,?,?,?,?,?,?)
                    ON CONFLICT(event_id,inst_date) DO UPDATE SET
                      start_at=excluded.start_at, end_at=excluded.end_at, all_day=excluded.all_day,
                      title=excluded.title, notes=excluded.notes
                ");
                $st->execute([$eid,$inst,$startAt,$endAt,$all,$title,$notes]);
            } else {
                // Ganze Serie / Einzeltermin
                $rec_int   = max(0,(int)($_POST['rec_interval_weeks'] ?? 0));
                $rec_wds   = isset($_POST['rec_weekdays']) ? array_to_csv((array)$_POST['rec_weekdays']) : '';
                $rec_until = (string)($_POST['rec_until'] ?? '');
                if ($rec_int === 0) { $rec_wds=''; $rec_until=''; }

                $st = db()->prepare("UPDATE calendar_events
                    SET title=?, type=?, start_at=?, end_at=?, all_day=?, notes=?, rec_interval_weeks=?, rec_weekdays=?, rec_until=?
                    WHERE id=?");
                $st->execute([$title,$type,$startAt,$endAt,$all,$notes,$rec_int,$rec_wds,($rec_until?:null),$eid]);
            }
            echo json_encode(['ok'=>true]); exit;
        }

        if ($api === 'delete' && $_SERVER['REQUEST_METHOD']==='POST') {
            $idRaw = (string)($_POST['id'] ?? '');
            $scopeSeries = parse_bool($_POST['scope_series'] ?? '0');

            if (preg_match('/^e(\d+)@(\d{4}-\d{2}-\d{2})$/', $idRaw, $m)) {
                $eid = (int)$m[1]; $inst = $m[2];
            } else { $eid = (int)$idRaw; $inst = null; }

            $r = db()->prepare("SELECT owner_id, rec_interval_weeks FROM calendar_events WHERE id=?"); $r->execute([$eid]);
            $row = $r->fetch();
            if (!$row) throw new RuntimeException('Event nicht gefunden.');
            if ((int)$row['owner_id'] !== $MY_ID) throw new RuntimeException('Nur eigene Termine löschbar.');

            if ($inst && !$scopeSeries && (int)$row['rec_interval_weeks']>0) {
                // einzelne Instanz löschen → Exception
                db()->prepare("INSERT OR IGNORE INTO calendar_event_exceptions (event_id,inst_date) VALUES (?,?)")->execute([$eid,$inst]);
            } else {
                db()->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$eid]);
            }
            echo json_encode(['ok'=>true]); exit;
        }

        echo json_encode(['ok'=>false,'error'=>'Unbekannte API']); exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

/* ========= Render ========= */
render_header('Kalender');

foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.esc($t).'">'.esc($m).'</div>';
}
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.css">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.11/index.global.min.js"></script>

<style>
  /* Nur kurzer Titel im Raster (Punkt + Text, Text ellipsen) */
  .fc .fc-daygrid-event { white-space: nowrap; overflow: hidden; }
  .cal-ev { display:flex; align-items:center; gap:6px; width:100%; overflow:hidden }
  .cal-ev-dot { width:8px; height:8px; border-radius:999px; flex:0 0 8px }
  .cal-ev-title { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; display:block }

  /* Seitenleiste */
  .details-card p{ margin:.35rem 0 }
  .details-muted{ opacity:.7 }

  /* gleich hohe Tagesfelder (Monatsansicht) */
  .fc .fc-daygrid-body { min-height: 680px; }
</style>

<section class="row">
  <div class="card" style="flex:2; min-width:640px">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:8px">
      <h2 style="margin:0">Kalender</h2>
      <button class="btn btn-primary" id="btnNew">+ Neuer Eintrag</button>
    </div>
    <div id="calendar" style="margin-top:10px"></div>
  </div>

  <aside class="card details-card" style="flex:1; max-width:420px">
    <h2>Details</h2>
    <div id="evDetails">
      <p class="details-muted"><em>Termin/Abwesenheit im Kalender anklicken…</em></p>
    </div>
  </aside>
</section>

<script>
(function(){
  // ---- kleine Helfer ----
  function $(sel, root=document){ return root.querySelector(sel); }
  function esc(s){ return (s??'').toString().replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function fmtRange(ev){
    const optDate = { dateStyle:'medium' };
    const optDT   = { dateStyle:'medium', timeStyle:'short' };
    const s = new Date(ev.start);
    const e = new Date(ev.end ?? ev.start);
    if (ev.allDay) return `${s.toLocaleDateString('de-DE',optDate)} – ${e.toLocaleDateString('de-DE',optDate)}`;
    return `${s.toLocaleString('de-DE',optDT)} – ${e.toLocaleString('de-DE',optDT)}`;
  }
  function weekdayNames(list){
    const map = {MO:'Mo',TU:'Di',WE:'Mi',TH:'Do',FR:'Fr',SA:'Sa',SU:'So'};
    return (list||[]).map(x=>map[x]||x).join(', ');
  }

  // ---- Details rechts anzeigen ----
  const details = document.getElementById('evDetails');
  function renderDetails(ev){
    const p   = ev.extendedProps || {};
    const typ = p.type === 'absence' ? 'Abwesenheit' : 'Termin';
    const who = p.owner_name ? `<p><strong>Erstellt von:</strong> ${esc(p.owner_name)}</p>` : '';
    const notes = p.notes ? `<p><strong>Notizen:</strong><br>${esc(p.notes).replace(/\n/g,'<br>')}</p>` : '';
    let rec = '';
    if (p.rec_interval_weeks && p.rec_weekdays && p.rec_weekdays.length) {
      rec = `<p><strong>Wiederholung:</strong> alle ${p.rec_interval_weeks} Woche(n) am ${weekdayNames(p.rec_weekdays)}</p>`;
    } else if (p.rec_desc) {
      rec = `<p><strong>Wiederholung:</strong> ${esc(p.rec_desc)}</p>`;
    }
    const actions = p.can_edit ? `
      <div class="stack-sm" style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap">
        <button class="btn btn-sm" onclick="editCalendarEvent('${ev.id}')">Bearbeiten</button>
        <button class="btn btn-sm btn-danger" onclick="deleteCalendarEvent('${ev.id}')">Löschen</button>
      </div>` : '';

    details.innerHTML = `
      <h3 style="margin-top:0">${esc(ev.title)}</h3>
      <p><strong>Typ:</strong> ${typ}${ev.allDay?' (ganztägig)':''}</p>
      <p><strong>Wann:</strong> ${fmtRange(ev)}</p>
      ${who}${rec}${notes}${actions}
    `;
  }

  // ---- Formular (in der Seitenleiste) ----
  function weekdayChecks(selected){
    const all = ['MO','TU','WE','TH','FR','SA','SU'];
    const s = new Set(selected||[]);
    return all.map(c=>{
      const lbl = {MO:'Mo',TU:'Di',WE:'Mi',TH:'Do',FR:'Fr',SU:'So'}[c];
      return `<label style="display:inline-flex;align-items:center;gap:6px;margin-right:6px">
                <input type="checkbox" name="rec_weekdays[]" value="${c}" ${s.has(c)?'checked':''}>
                ${lbl}
              </label>`;
    }).join('');
  }

  function openForm(mode, data){
    const isSeries = !!(data?.extendedProps?.rec_interval_weeks);
    const instDate = data?.extendedProps?.inst_date || null;
    const canSeriesToggle = isSeries && !!data?.extendedProps?.can_edit;

    const start = data ? new Date(data.start) : new Date();
    const end   = data ? new Date(data.end)   : new Date(start.getTime()+60*60*1000);

    const toDate = d => d.toISOString().slice(0,10);
    const toTime = d => d.toTimeString().slice(0,5);

    const p = data?.extendedProps || {};
    const checked = (v)=> v ? 'checked' : '';

    details.innerHTML = `
      <h3 style="margin:0 0 8px">${mode==='new'?'Neuer Eintrag':'Eintrag bearbeiten'}</h3>
      <form id="calForm">
        ${data ? `<input type="hidden" name="id" value="${esc(data.id)}">` : ''}
        <label>Titel<br><input type="text" name="title" required value="${esc(data?.title||'')}"></label><br><br>
        <label>Typ<br>
          <select name="type">
            <option value="event" ${p.type!=='absence'?'selected':''}>Termin</option>
            <option value="absence" ${p.type==='absence'?'selected':''}>Abwesenheit</option>
          </select>
        </label><br><br>

        <label><input type="checkbox" name="all_day" ${checked(data?.allDay)}> Ganztägig</label><br><br>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <label>Datum (Start)<br><input type="date" name="date_start" value="${esc(toDate(start))}" required></label>
          <label>Uhrzeit (Start)<br><input type="time" name="time_start" value="${esc(toTime(start))}"></label>
          <label>Datum (Ende)<br><input type="date" name="date_end" value="${esc(toDate(end))}" required></label>
          <label>Uhrzeit (Ende)<br><input type="time" name="time_end" value="${esc(toTime(end))}"></label>
        </div>
        <p><small>Bei „Ganztägig“ wird Ende automatisch auf den Folgetag gesetzt.</small></p>

        <h4>Wiederholung</h4>
        <label>Alle <input type="number" min="0" step="1" name="rec_interval_weeks" value="${p.rec_interval_weeks||0}" style="width:70px"> Woche(n)</label>
        <div style="margin:6px 0">${weekdayChecks(p.rec_weekdays||[])}</div>
        <label>Bis (optional)<br><input type="date" name="rec_until" value="${esc(p.rec_until||'')}"></label>
        <p><small>0 Woche(n) = keine Wiederholung</small></p>

        ${canSeriesToggle ? `
          <label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px">
            <input type="checkbox" name="scope_series">
            Wiederholung bearbeiten (alle)
          </label>
          ${instDate ? `<p><small>Diese Instanz: ${esc(instDate)}</small></p>` : ''}
        ` : (isSeries && instDate ? `<p><small>Nur diese Instanz wird bearbeitet (Besitz erforderlich für Serien-Änderung).</small></p>` : '')}

        <label>Notizen<br><textarea name="notes" rows="5">${esc(p.notes||'')}</textarea></label><br><br>

        <div class="stack-sm" style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn btn-primary" type="submit">${mode==='new'?'Speichern':'Aktualisieren'}</button>
          <button class="btn" type="button" id="cancelForm">Abbrechen</button>
        </div>
      </form>
    `;

    $('#cancelForm', details).addEventListener('click', ()=> {
      details.innerHTML = '<p class="details-muted"><em>Termin/Abwesenheit im Kalender anklicken…</em></p>';
    });

    const form = $('#calForm', details);
    form.addEventListener('submit', async (e)=>{
      e.preventDefault();
      const fd = new FormData(form);
      if (mode==='new') {
        const res = await fetch('admin_calendar.php?api=create', {method:'POST', body:fd});
        const js  = await res.json();
        if (!js.ok) return alert(js.error||'Fehler');
      } else {
        if (!fd.has('scope_series')) fd.set('scope_series','0'); else fd.set('scope_series','1');
        const res = await fetch('admin_calendar.php?api=update', {method:'POST', body:fd});
        const js  = await res.json();
        if (!js.ok) return alert(js.error||'Fehler');
      }
      calendar.refetchEvents();
      details.innerHTML = '<p class="details-muted"><em>Gespeichert. Termin im Kalender ausgewählt…</em></p>';
    });
  }

  // ---- FullCalendar ----
  const calendarEl = document.getElementById('calendar');
  const calendar = new FullCalendar.Calendar(calendarEl, {
    initialView: 'dayGridMonth',
    height: 'auto',
    firstDay: 1,
    locale: 'de',
    buttonText: { today:'Heute', month:'Monat', week:'Woche', day:'Tag' },
    headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay' },
    dayMaxEventRows: 4,
    expandRows: true,
    displayEventTime: false,

    eventContent(arg){
      const color = arg.event.backgroundColor || arg.event.borderColor || '#64748b';
      const wrap  = document.createElement('div');
      wrap.className = 'cal-ev';
      wrap.innerHTML = `<span class="cal-ev-dot" style="background:${color}"></span>
                        <span class="cal-ev-title" title="${esc(arg.event.title)}">${esc(arg.event.title)}</span>`;
      return { domNodes: [wrap] };
    },

    eventClick(info){
      info.jsEvent.preventDefault();
      renderDetails(info.event);
    },

    events: 'admin_calendar.php?api=list'
  });
  calendar.render();

  // Neuer Termin
  document.getElementById('btnNew').addEventListener('click', ()=> openForm('new', null));

  // Exporte für Buttons aus der Detailansicht
  window.editCalendarEvent = async function(id){
    const r = await fetch('admin_calendar.php?api=get&id='+encodeURIComponent(id));
    const js = await r.json();
    if (!js.ok) return alert(js.error||'Fehler');
    const ev = js.event;

    const data = {
      id,
      title: ev.title,
      start: ev.override?.start_at || ev.start_at,
      end:   ev.override?.end_at   || ev.end_at,
      allDay: (ev.override?.all_day!==undefined && ev.override?.all_day!==null) ? (ev.override.all_day===1) : (ev.all_day===1),
      extendedProps: {
        type: ev.type,
        notes: ev.override?.notes ?? ev.notes,
        rec_interval_weeks: parseInt(ev.rec_interval_weeks||0,10),
        rec_weekdays: (ev.rec_weekdays||'').split(',').filter(Boolean),
        rec_until: ev.rec_until || '',
        can_edit: !!ev.owner_can_edit,
        inst_date: ev.inst_date || null
      }
    };
    openForm('edit', data);
  };

  window.deleteCalendarEvent = async function(id){
    if (!confirm('Eintrag wirklich löschen? (Bei Serien: ohne Haken nur diese eine Vorkommnis)')) return;

    const form = new FormData();
    form.set('id', id);
    let scopeSeries = false;
    if (/^e\d+@\d{4}-\d{2}-\d{2}$/.test(id)) {
      scopeSeries = confirm('Stattdessen die ganze Wiederholung löschen?');
    }
    form.set('scope_series', scopeSeries ? '1' : '0');

    const r = await fetch('admin_calendar.php?api=delete', {method:'POST', body:form});
    const js = await r.json();
    if (!js.ok) return alert(js.error||'Fehler');
    calendar.refetchEvents();
    details.innerHTML = '<p class="details-muted"><em>Gelöscht.</em></p>';
  };
})();
</script>

<?php render_footer(); ?>
