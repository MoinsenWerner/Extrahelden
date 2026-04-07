<?php
// votes.php — Königreichs-Abstimmungen: Rechte, Live-Updates, Auto-Close, TZ-Autodetect & zweizeilige Badges
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$user = current_user();
if (!$user) { flash('Bitte melde dich an, um auf Abstimmungen zuzugreifen.', 'error'); header('Location: login.php'); exit; }
$uid      = (int)$user['id'];
$is_admin = (int)($user['is_admin'] ?? 0) === 1;

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

function post(string $k,$d=''){ return $_POST[$k] ?? $d; }
function getv(string $k,$d=''){ return $_GET[$k]  ?? $d; }

$pdo = db();

/* ---------- Tabellen-Safety ---------- */
$pdo->exec("
CREATE TABLE IF NOT EXISTS votes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT,
  type TEXT NOT NULL DEFAULT 'law',   -- 'law' | 'war'
  status TEXT NOT NULL DEFAULT 'open',-- 'open' | 'closed'
  ends_at TEXT,                       -- gespeicherte UTC-Zeit 'YYYY-MM-DD HH:MM:SS'
  created_by INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, -- UTC
  kingdom_id INTEGER NULL,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (kingdom_id) REFERENCES kingdoms(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS vote_options (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vote_id INTEGER NOT NULL,
  label TEXT NOT NULL,
  value TEXT,
  position INTEGER NOT NULL DEFAULT 0,
  FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_voteopt_sort ON vote_options(vote_id, position);
CREATE TABLE IF NOT EXISTS vote_ballots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vote_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL,
  option_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (vote_id) REFERENCES votes(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (option_id) REFERENCES vote_options(id) ON DELETE CASCADE
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_ballot_unique ON vote_ballots(vote_id, user_id);
");

/* ---------- Zeitzonen-Helpers ---------- */
function user_tz(): string {
    $tz = $_COOKIE['tz'] ?? 'UTC';
    // validieren
    static $valid = null;
    if ($valid === null) $valid = array_flip(\DateTimeZone::listIdentifiers());
    return isset($valid[$tz]) ? $tz : 'UTC';
}
function to_utc_sql(?string $local, string $tz): ?string {
    $s = trim((string)$local);
    if ($s === '') return null;
    try {
        // erlaubte Formate: 'YYYY-MM-DD HH:MM' oder 'YYYY-MM-DD HH:MM:SS'
        if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}(:\d{2})?$/', $s) !== 1) return null;
        $dt = new DateTime($s, new DateTimeZone($tz));
        $dt->setTimezone(new DateTimeZone('UTC'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) { return null; }
}
function utc_to_local_fmt(?string $utc, string $tz, string $fmt='d.m.Y H:i'): string {
    if (!$utc) return '';
    try {
        $dt = new DateTime($utc, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($tz));
        return $dt->format($fmt);
    } catch (Throwable) { return ''; }
}

/* ---------- Eigene Königreiche ---------- */
function user_kingdom_ids(PDO $pdo, int $uid): array {
    $st=$pdo->prepare("SELECT kingdom_id FROM kingdom_memberships WHERE user_id=?");
    $st->execute([$uid]);
    return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
function user_king_kingdom_ids(PDO $pdo, int $uid): array {
    $st=$pdo->prepare("SELECT kingdom_id FROM kingdom_memberships WHERE user_id=? AND role='König'");
    $st->execute([$uid]);
    return array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN));
}
$my_kingships = user_king_kingdom_ids($pdo,$uid);
$my_kingdoms  = user_kingdom_ids($pdo,$uid);

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

/* ---------- AUTO-CLOSE (UTC) ---------- */
$pdo->exec("
  UPDATE votes
     SET status = 'closed'
   WHERE status = 'open'
     AND ends_at IS NOT NULL
     AND ends_at <> ''
     AND datetime(ends_at) <= datetime('now')   -- SQLite now() ist UTC
");

/* ========== AJAX: Live-Stats (JSON) ========== */
if (isset($_GET['ajax']) && $_GET['ajax'] === 'stats') {
    header('Content-Type: application/json; charset=utf-8');
    $vote_id = (int)($_GET['id'] ?? 0);

    $st = $pdo->prepare("
        SELECT v.*, k.name AS kingdom_name
        FROM votes v
        LEFT JOIN kingdoms k ON k.id=v.kingdom_id
        WHERE v.id=?
    ");
    $st->execute([$vote_id]);
    $vote = $st->fetch(PDO::FETCH_ASSOC);
    if (!$vote) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

    // Sichtbarkeit prüfen
    $is_admin_ajax = (int)($user['is_admin'] ?? 0) === 1;
    if (!$is_admin_ajax) {
        $stU = $pdo->prepare("SELECT kingdom_id FROM kingdom_memberships WHERE user_id=?");
        $stU->execute([$uid]);
        $my_kingdoms_ajax = array_map('intval',$stU->fetchAll(PDO::FETCH_COLUMN));
        $kid = $vote['kingdom_id'];
        if (!($kid === null || in_array((int)$kid,$my_kingdoms_ajax,true))) {
            echo json_encode(['ok'=>false,'error'=>'forbidden']); exit;
        }
    }

    // Auto-Close Single
    if ($vote['status'] === 'open' && !empty($vote['ends_at']) && strtotime($vote['ends_at'].' UTC') < time()) {
        $pdo->prepare("UPDATE votes SET status='closed' WHERE id=?")->execute([$vote_id]);
        $vote['status'] = 'closed';
    }

    // Optionen & Stimmen
    $opts = $pdo->prepare("
        SELECT o.id, o.label,
               (SELECT COUNT(*) FROM vote_ballots b WHERE b.option_id=o.id) AS votes
        FROM vote_options o
        WHERE o.vote_id=?
        ORDER BY o.position ASC
    ");
    $opts->execute([$vote_id]);
    $options = $opts->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_map(fn($x)=>(int)$x['votes'],$options)) ?: 0;

    // Führende Option
    $leader = ['label'=>'—','count'=>0,'pct'=>0];
    foreach ($options as $o) {
        $c = (int)$o['votes'];
        if ($c > $leader['count']) $leader = ['label'=>$o['label'],'count'=>$c,'pct'=>0];
    }
    if ($total > 0) { $leader['pct'] = (int)round($leader['count']*100/$total); }

    // Eigene Stimme
    $stB=$pdo->prepare("SELECT option_id FROM vote_ballots WHERE vote_id=? AND user_id=?");
    $stB->execute([$vote_id,$uid]); $my_option=(int)($stB->fetchColumn()?:0);

    // Badges (lokale Anzeigezeit)
    $tz = user_tz();
    $typeBadge   = $vote['type']==='war' ? '⚔️ Krieg' : '📜 Gesetz';
    $statusBadge = $vote['status']==='open' ? '🟢 offen' : '🔒 geschlossen';
    $scopeBadge  = $vote['kingdom_name'] ? ('👑 '.$vote['kingdom_name']) : '🌐 Global';
    $endBadge    = !empty($vote['ends_at']) ? ('⏳ endet: '.utc_to_local_fmt($vote['ends_at'], $tz)) : '';

    echo json_encode([
        'ok'=>true,
        'status'=>$vote['status'],
        'is_open'=>($vote['status']==='open'),
        'type'=>$vote['type'],
        'kingdom_name'=>$vote['kingdom_name'],
        'ends_at_local'=>$endBadge, // nur zu Anzeigezwecken
        'badges'=>[
            'type'=>$typeBadge,
            'status'=>$statusBadge,
            'scope'=>$scopeBadge,
            'end'=>$endBadge,
            'leader'=>($leader['label']==='—' ? '' : ('🏆 '.$leader['label'].' — '.$leader['pct'].'%'))
        ],
        'total'=>$total,
        'my_option'=>$my_option,
        'options'=>array_map(function($o) use ($total){
            $c=(int)$o['votes']; $pct=$total>0?(int)round($c*100/$total):0;
            return ['id'=>(int)$o['id'],'label'=>$o['label'],'votes'=>$c,'pct'=>$pct];
        }, $options)
    ]);
    exit;
}

/* ========== POST Aktionen ========== */
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf,(string)($_POST['csrf'] ?? ''))) throw new RuntimeException('Ungültiges CSRF-Token.');

        // Erstellen
        if ($action === 'create') {
            $title = trim((string)post('title'));
            $desc  = trim((string)post('description'));
            $type  = in_array((string)post('type','law'),['law','war'],true) ? (string)post('type','law') : 'law';
            $ends  = trim((string)post('ends_at')); // LOKALE Zeit vom Client
            $opts  = array_values(array_filter(array_map('trim', explode("\n",(string)post('options'))), fn($s)=>$s!==''));

            $kidRaw = post('kingdom_id','');
            $kid = ($kidRaw === '' || $kidRaw === 'null') ? null : (int)$kidRaw;

            if ($title === '' || count($opts) < 2) { flash('Titel und mindestens zwei Optionen sind erforderlich.','error'); header('Location: votes.php'); exit; }

            if ($is_admin) {
                // Admin: global (NULL) oder beliebiges Reich erlaubt
            } else {
                if ($kid === null) throw new RuntimeException('Nur Admins dürfen globale Abstimmungen erstellen.');
                if (!in_array($kid,$my_kingships,true)) throw new RuntimeException('Nur Könige dürfen für ihr eigenes Königreich Abstimmungen erstellen.');
            }

            // Lokale Zeit -> UTC
            $tz = user_tz();
            $endsUtc = to_utc_sql($ends, $tz); // kann null sein, wenn leer/ungültig

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO votes(title,description,type,status,ends_at,created_by,kingdom_id) VALUES(?,?,?,?,?,?,?)")
                ->execute([$title,$desc,$type,'open',$endsUtc,$uid,$kid]);
            $vote_id = (int)$pdo->lastInsertId();

            $pos=0; $stOpt=$pdo->prepare("INSERT INTO vote_options(vote_id,label,value,position) VALUES(?,?,?,?)");
            foreach($opts as $line){ $stOpt->execute([$vote_id,$line,null,$pos++]); }
            $pdo->commit();

            flash('Abstimmung erstellt.','success'); header('Location: votes.php?v='.$vote_id); exit;
        }

        // Abstimmen
        if ($action === 'vote') {
            $vote_id   = (int)post('vote_id');
            $option_id = (int)post('option_id');

            $st = $pdo->prepare("SELECT id,status,IFNULL(ends_at,'') AS ends_at, kingdom_id FROM votes WHERE id=?");
            $st->execute([$vote_id]);
            $vote = $st->fetch(PDO::FETCH_ASSOC);
            if (!$vote) throw new RuntimeException('Abstimmung nicht gefunden.');

            if (!$is_admin) {
                $kid = $vote['kingdom_id'];
                if (!($kid === null || in_array((int)$kid,$my_kingdoms,true))) {
                    throw new RuntimeException('Du darfst an dieser Abstimmung nicht teilnehmen.');
                }
            }

            // Ablauf prüfen (UTC)
            if ($vote['status'] !== 'open') throw new RuntimeException('Abstimmung ist geschlossen.');
            if ($vote['ends_at'] !== '' && strtotime($vote['ends_at'].' UTC') < time()) {
                $pdo->prepare("UPDATE votes SET status='closed' WHERE id=?")->execute([$vote_id]);
                throw new RuntimeException('Abstimmung bereits abgelaufen.');
            }

            $st=$pdo->prepare("SELECT 1 FROM vote_options WHERE id=? AND vote_id=?");
            $st->execute([$option_id,$vote_id]);
            if(!$st->fetch()) throw new RuntimeException('Option ungültig.');

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM vote_ballots WHERE vote_id=? AND user_id=?")->execute([$vote_id,$uid]);
            $pdo->prepare("INSERT INTO vote_ballots(vote_id,user_id,option_id) VALUES(?,?,?)")->execute([$vote_id,$uid,$option_id]);
            $pdo->commit();

            flash('Stimme gezählt.','success'); header('Location: votes.php?v='.$vote_id); exit;
        }

        // Schließen (nur Ersteller)
        if ($action === 'close') {
            $vote_id=(int)post('vote_id');
            $st=$pdo->prepare("SELECT created_by,status FROM votes WHERE id=?");
            $st->execute([$vote_id]);
            $v=$st->fetch(PDO::FETCH_ASSOC);
            if(!$v) throw new RuntimeException('Abstimmung nicht gefunden.');
            if((int)$v['created_by'] !== $uid) throw new RuntimeException('Nur der Ersteller darf schließen.');
            if($v['status'] !== 'open') throw new RuntimeException('Abstimmung ist bereits geschlossen.');
            $pdo->prepare("UPDATE votes SET status='closed' WHERE id=?")->execute([$vote_id]);
            flash('Abstimmung geschlossen.','success'); header('Location: votes.php?v='.$vote_id); exit;
        }

        // Löschen (Ersteller oder Admin)
        if ($action === 'delete') {
            $vote_id=(int)post('vote_id');
            $st=$pdo->prepare("SELECT created_by FROM votes WHERE id=?");
            $st->execute([$vote_id]);
            $creator=$st->fetchColumn();
            if($creator===false) throw new RuntimeException('Abstimmung nicht gefunden.');
            if(!$is_admin && (int)$creator !== $uid) throw new RuntimeException('Nur Ersteller oder Admin dürfen löschen.');
            $pdo->prepare("DELETE FROM votes WHERE id=?")->execute([$vote_id]);
            flash('Abstimmung gelöscht.','success'); header('Location: votes.php'); exit;
        }
    }

    /* ---------- Liste + Detail ---------- */
    $current_id = (int)getv('v',0);
    $tz = user_tz();

    if ($is_admin) {
        $stList = $pdo->query("
            SELECT v.id,v.title,v.type,v.status,COALESCE(v.ends_at,'') AS ends_at,
                   v.created_at, v.kingdom_id, k.name AS kingdom_name
            FROM votes v
            LEFT JOIN kingdoms k ON k.id=v.kingdom_id
            ORDER BY (v.status='open') DESC, v.created_at DESC
        ");
    } else {
        if (empty($my_kingdoms)) {
            $stList = $pdo->query("
                SELECT v.id,v.title,v.type,v.status,COALESCE(v.ends_at,'') AS ends_at,
                       v.created_at, v.kingdom_id, k.name AS kingdom_name
                FROM votes v
                LEFT JOIN kingdoms k ON k.id=v.kingdom_id
                WHERE v.kingdom_id IS NULL
                ORDER BY (v.status='open') DESC, v.created_at DESC
            ");
        } else {
            $place = implode(',', array_fill(0,count($my_kingdoms),'?'));
            $sql = "
                SELECT v.id,v.title,v.type,v.status,COALESCE(v.ends_at,'') AS ends_at,
                       v.created_at, v.kingdom_id, k.name AS kingdom_name
                FROM votes v
                LEFT JOIN kingdoms k ON k.id=v.kingdom_id
                WHERE v.kingdom_id IN ($place) OR v.kingdom_id IS NULL
                ORDER BY (v.status='open') DESC, v.created_at DESC
            ";
            $stList = $pdo->prepare($sql);
            $stList->execute($my_kingdoms);
        }
    }

    $stTotal = $pdo->prepare("SELECT COUNT(*) FROM vote_ballots WHERE vote_id=?");
    $stLead  = $pdo->prepare("
        SELECT o.label AS label, COUNT(b.id) AS c
        FROM vote_options o
        LEFT JOIN vote_ballots b ON b.option_id = o.id
        WHERE o.vote_id = ?
        GROUP BY o.id
        ORDER BY c DESC, o.position ASC
        LIMIT 1
    ");

    render_header('Abstimmungen', true);
    foreach (consume_flashes() as [$t,$m]) echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';

    echo '<section class="row">';
    echo '<div class="card" style="min-width:320px;max-width:520px">';
    echo '<h2>Aktive Abstimmungen</h2>';

    echo '<div class="list list-compact">';
    if ($stList instanceof Traversable) {
        foreach ($stList as $r) {
            $isOpen = ($r['status']==='open');
            $typeBadge = $r['type']==='war' ? '⚔️ Krieg' : '📜 Gesetz';
            $statusBadge = $isOpen ? '🟢 offen' : '🔒 geschlossen';
            $scopeBadge = $r['kingdom_name'] ? '👑 '.htmlspecialchars($r['kingdom_name']) : '🌐 Global';

            $endBadge = '';
            if ($r['ends_at']!=='') { $endBadge = '⏳ endet: '.utc_to_local_fmt($r['ends_at'], $tz); }

            $createdLocal = utc_to_local_fmt($r['created_at'], $tz);

            // Führt aktuell
            $stTotal->execute([$r['id']]);
            $totalVotes = (int)$stTotal->fetchColumn();
            $leaderLabel = '—';
            $leaderPct   = 0;
            if ($totalVotes > 0) {
                $stLead->execute([$r['id']]);
                if ($lead = $stLead->fetch(PDO::FETCH_ASSOC)) {
                    $leaderLabel = (string)$lead['label'];
                    $leaderCount = (int)$lead['c'];
                    $leaderPct   = (int)round($leaderCount * 100 / $totalVotes);
                }
            }

            echo '<a class="list-item" href="votes.php?v='.$r['id'].'">';
            echo   '<div class="list-main">';
            echo     '<div class="list-title">'.htmlspecialchars($r['title']).'</div>';
            echo     '<div class="list-sub">'.htmlspecialchars($createdLocal).'</div>';
            echo   '</div>';
            echo   '<div class="list-meta">';
            echo     '<span class="badge">'.$typeBadge.'</span>';
            echo     '<span class="badge '.($isOpen?'badge-open':'badge-closed').'">'.$statusBadge.'</span>';
            echo     '<span class="badge">'.$scopeBadge.'</span>';
            if ($endBadge !== '') echo '<span class="badge">'.htmlspecialchars($endBadge).'</span>';
            echo     '<span class="badge badge-lead">🏆 '.htmlspecialchars($leaderLabel).' — '.$leaderPct.'%</span>';
            echo   '</div>';
            echo '</a>';
        }
    }
    echo '</div>'; // list
    echo '</div>'; // card left

    // Detail
    if ($current_id > 0) {
        $st = $pdo->prepare("
            SELECT v.*, k.name AS kingdom_name
            FROM votes v
            LEFT JOIN kingdoms k ON k.id=v.kingdom_id
            WHERE v.id=?
        ");
        $st->execute([$current_id]);
        $vote = $st->fetch(PDO::FETCH_ASSOC);

        if ($vote && !$is_admin) {
            $kid = $vote['kingdom_id'];
            if (!($kid === null || in_array((int)$kid,$my_kingdoms,true))) {
                $vote = null;
            }
        }

        if ($vote) {
            $isCreator = ((int)$vote['created_by'] === $uid);

            echo '<div class="card" style="flex:1" id="vote-card" data-vote-id="'.$current_id.'">';
            echo '<h2>'.htmlspecialchars($vote['title']).'</h2>';
            if (!empty($vote['description'])) echo '<p>'.nl2br(htmlspecialchars($vote['description'])).'</p>';

            echo '<div id="vote-badges" class="badge-row" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:6px"></div>';

            $opts = $pdo->prepare("SELECT id,label FROM vote_options WHERE vote_id=? ORDER BY position ASC");
            $opts->execute([$current_id]);
            echo '<form method="post" class="vote-form" id="vote-form">';
            echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
            echo '<input type="hidden" name="action" value="vote">';
            echo '<input type="hidden" name="vote_id" value="'.$current_id.'">';
            echo '<div class="options" id="vote-options">';
            while ($o = $opts->fetch(PDO::FETCH_ASSOC)) {
                echo '<label class="option" data-option-id="'.$o['id'].'">';
                echo '<input type="radio" name="option_id" value="'.$o['id'].'"> '.htmlspecialchars($o['label']);
                echo '<div class="bar"><span style="width:0%"></span></div>';
                echo '<small>0 Stimme(n) – 0%</small>';
                echo '</label>';
            }
            echo '</div>';
            echo '<button class="btn btn-primary" type="submit" id="vote-submit">Abstimmen</button>';
            echo '</form>';

            echo '<hr><div style="display:flex;gap:10px;flex-wrap:wrap" id="vote-actions">';
            if ($isCreator) {
                echo '<form method="post" id="close-form" onsubmit="return confirm(\'Abstimmung wirklich schließen?\')">';
                echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
                echo '<input type="hidden" name="action" value="close">';
                echo '<input type="hidden" name="vote_id" value="'.$current_id.'">';
                echo '<button class="btn" id="close-btn">🔒 Abstimmung schließen</button>';
                echo '</form>';
            }
            if ($isCreator || $is_admin) {
                echo '<form method="post" onsubmit="return confirm(\'Abstimmung endgültig löschen?\')">';
                echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
                echo '<input type="hidden" name="action" value="delete">';
                echo '<input type="hidden" name="vote_id" value="'.$current_id.'">';
                echo '<button class="btn btn-danger">🗑️ Löschen</button>';
                echo '</form>';
            }
            echo '</div>';

            echo '</div>';
        }
    }
    echo '</section><br>';

    // Erstellen
    $canCreateAsAdmin = $is_admin;
    $canCreateAsKing  = !$is_admin && !empty($my_kingships);
    if ($canCreateAsAdmin || $canCreateAsKing) {
        echo '<section class="row"><div class="card" style="flex:1;min-width:420px">';
        echo '<h2>Neue Abstimmung</h2><form method="post">';
        echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
        echo '<input type="hidden" name="action" value="create">';
        echo '<label>Titel<br><input type="text" name="title" required></label><br><br>';
        echo '<label>Beschreibung (optional)<br><textarea name="description" rows="4"></textarea></label><br><br>';
        echo '<label>Typ<br><select name="type"><option value="law">📜 Gesetz</option><option value="war">⚔️ Krieg</option></select></label><br><br>';
        echo '<label>Ende (optional, lokale Zeit; z. B. 2025-09-30 20:00)<br><input type="text" name="ends_at" placeholder="YYYY-MM-DD HH:MM"></label><br><br>';
        echo '<label>Gilt für<br><select name="kingdom_id" required>';
        if ($canCreateAsAdmin) {
            echo '<option value="null">🌐 Global (alle Königreiche)</option>';
            $kgs=$pdo->query("SELECT id,name FROM kingdoms ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach($kgs as $k){ echo '<option value="'.$k['id'].'">👑 '.htmlspecialchars($k['name']).'</option>'; }
        } else {
            $place=implode(',',array_fill(0,count($my_kingships),'?'));
            $st=$pdo->prepare("SELECT id,name FROM kingdoms WHERE id IN ($place) ORDER BY name ASC");
            $st->execute($my_kingships);
            foreach($st->fetchAll(PDO::FETCH_ASSOC) as $k){ echo '<option value="'.$k['id'].'">👑 '.htmlspecialchars($k['name']).'</option>'; }
        }
        echo '</select></label><br><br>';
        echo '<label>Optionen (eine pro Zeile, min. 2)<br><textarea name="options" rows="4" required>Ja
Nein</textarea></label><br><br>';
        echo '<button class="btn btn-primary" type="submit">Erstellen</button></form></div></section>';
    }

    /* ===== Styles (inkl. zweizeilige Badge-Liste) & JS ===== */
    echo '<style>
    .vote-form .options{display:flex;flex-direction:column;gap:12px;}
    .vote-form .option{display:block;padding:8px 10px;border:1px solid #2a2f3d;border-radius:8px;background:rgba(0,0,0,0.08);}
    .vote-form .bar{height:8px;background:#222;border-radius:4px;overflow:hidden;margin-top:6px;}
    .vote-form .bar span{display:block;height:100%;background:linear-gradient(90deg,#7d5cff,#c79aff);}
    .btn-danger{background:#8b1d1d;color:#fff;}

    /* Liste */
    .list{display:flex;flex-direction:column;gap:8px;margin:6px 0 10px 0}
    .list-item{
      display:flex;gap:12px;
      padding:10px 12px;border:1px solid #2a2f3d;border-radius:10px;
      background:rgba(0,0,0,0.08);text-decoration:none;
      transition:background .15s,border-color .15s
    }
    .list-item:hover{background:rgba(255,255,255,0.06);border-color:#3a4154}

    .list-main{display:flex;flex-direction:column;min-width:0;flex:1 1 280px}
    .list-title{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .list-sub{opacity:.75;font-size:.9rem}

    /* Badges: zweizeilig möglich */
    .list-meta{
      display:flex;flex-wrap:wrap;gap:8px;row-gap:6px;
      align-items:center;justify-content:flex-end;
      flex:1 1 320px;min-width:260px
    }

    .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(125,92,255,.18);
      border:1px solid rgba(125,92,255,.5);font-size:.85rem;white-space:nowrap}
    .badge-open{background:rgba(46,204,113,.18);border-color:rgba(46,204,113,.5)}
    .badge-closed{background:rgba(231,76,60,.18);border-color:rgba(231,76,60,.5)}
    .badge-lead{background:rgba(255,215,0,.18);border-color:rgba(255,215,0,.55)}

    @media (max-width: 700px){
      .list-item{flex-direction:column;align-items:stretch}
      .list-meta{justify-content:flex-start;min-width:0}
    }
    </style>';

    // JS: Schreibe Client-TZ in Cookie; Live-Update mit Pause
    echo '<script>
    (function(){
      // 1) TZ-Autodetect -> Cookie (1 Jahr)
      try{
        var tz = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
        var cur = document.cookie.match(/(?:^|; )tz=([^;]+)/);
        if (!cur || decodeURIComponent(cur[1]) !== tz){
          document.cookie = "tz="+encodeURIComponent(tz)+"; Path=/; Max-Age=31536000; SameSite=Lax";
        }
      }catch(e){ /* ignore */ }

      // 2) Live-Update (wie gehabt, pausierbar)
      const card = document.getElementById("vote-card");
      if (!card) return;
      const id = card.getAttribute("data-vote-id");
      const optsContainer = document.getElementById("vote-options");
      const form = document.getElementById("vote-form");
      const submitBtn = document.getElementById("vote-submit");
      const badges = document.getElementById("vote-badges");
      const closeForm = document.getElementById("close-form");
      const closeBtn  = document.getElementById("close-btn");

      let intervalId = null;
      let paused = false;

      function stopPolling(){ if (intervalId){ clearInterval(intervalId); intervalId = null; } }
      function startPolling(){ if (!paused && !intervalId){ intervalId = setInterval(tick, 1000); } }

      function badge(text, extraCls){ return `<span class="badge ${extraCls||""}">${text}</span>`; }

      function apply(data){
        if (!data || !data.ok) return;
        const statusCls = data.status === "open" ? "badge-open" : "badge-closed";
        const end = data.badges.end ? badge(data.badges.end) : "";
        const lead = data.badges.leader ? badge(data.badges.leader,"badge-lead") : "";
        badges.innerHTML = [
          badge(data.badges.type),
          badge(data.badges.status, statusCls),
          badge(data.badges.scope),
          end,
          lead
        ].filter(Boolean).join(" ");

        if (!paused){
          const inputs = optsContainer.querySelectorAll("label.option");
          const byId = {};
          inputs.forEach(l => byId[+l.getAttribute("data-option-id")] = l);
          data.options.forEach(o => {
            const row = byId[o.id];
            if (!row) return;
            const bar = row.querySelector(".bar span");
            const small = row.querySelector("small");
            bar.style.width = o.pct + "%";
            small.textContent = `${o.votes} Stimme(n) – ${o.pct}%`;
            const radio = row.querySelector("input[type=radio]");
            if (radio) {
              radio.checked = (data.my_option === o.id);
              radio.disabled = !data.is_open;
            }
          });
          if (submitBtn){ submitBtn.disabled = !data.is_open; submitBtn.style.display = data.is_open ? "" : "none"; }
          if (closeForm && closeBtn){
            if (data.is_open){ closeForm.style.display = ""; closeBtn.disabled = false; }
            else { closeForm.style.display = "none"; }
          }
        }
      }

      async function tick(){
        try{
          const res = await fetch(`votes.php?ajax=stats&id=${id}`, {cache:"no-store"});
          const json = await res.json();
          apply(json);
        }catch(e){}
      }

      tick();
      startPolling();

      optsContainer.addEventListener("change", function(ev){
        const t = ev.target;
        if (t && t.name === "option_id"){
          paused = true;
          stopPolling();
        }
      });
      form.addEventListener("submit", function(){
        paused = true;
        stopPolling();
      });
    })();
    </script>';

    render_footer();

} catch (Throwable $e) {
    error_log('votes.php error: '.$e->getMessage());
    http_response_code(500);
    render_header('Fehler');
    echo '<p>Interner Fehler: '.htmlspecialchars($e->getMessage()).'</p>';
    render_footer();
}
