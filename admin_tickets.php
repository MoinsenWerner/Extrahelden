<?php
// admin_tickets.php – Admin: Tickets einsehen, beantworten, schließen/wiederöffnen
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

function ensure_support_schema_admin(): void {
    $pdo = db();
    // wie in support.php – idempotent
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
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tickets_status  ON tickets(status);");

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
          details  TEXT
        );
    ");
    $cols = $pdo->query("PRAGMA table_info(calendar_events)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($r)=>$r['name'], $cols);
    if (!in_array('user_id', $names, true)) {
        $pdo->exec("ALTER TABLE calendar_events ADD COLUMN user_id INTEGER;");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cal_user ON calendar_events(user_id);");
    }
    if (!in_array('type', $names, true)) {
        $pdo->exec("ALTER TABLE calendar_events ADD COLUMN type TEXT NOT NULL DEFAULT 'event';");
    }
}
ensure_support_schema_admin();

/* ---- Liste ---- */
$st = db()->query("
  SELECT
    t.id, t.subject, t.status, t.created_at,
    u.username AS creator,
    (SELECT MAX(created_at) FROM ticket_messages m WHERE m.ticket_id=t.id) AS last_msg_at
  FROM tickets t
  JOIN users u ON u.id = t.creator_user_id
  ORDER BY (t.status='open') DESC, datetime(last_msg_at) DESC, datetime(t.created_at) DESC
");
$tickets = $st->fetchAll(PDO::FETCH_ASSOC);

/* ---- Render ---- */
render_header('Admin – Tickets');
foreach (consume_flashes() as [$t,$m]) { echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>'; }
?>
<section class="row">
  <div class="card" style="flex:1">
    <h2>Tickets</h2>
    <?php if (empty($tickets)): ?>
      <p><em>Keine Tickets vorhanden.</em></p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Betreff</th><th>Von</th><th>Status</th><th>Letzte Aktivität</th><th>Aktion</th></tr></thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td><?=$t['id']?></td>
                <td><?=htmlspecialchars($t['subject'])?></td>
                <td><?=htmlspecialchars($t['creator'])?></td>
                <td>
                  <?php if ($t['status']==='open'): ?>
                    <span class="badge pending">Offen</span>
                  <?php else: ?>
                    <span class="badge rejected">Geschlossen</span>
                  <?php endif; ?>
                </td>
                <td><?=htmlspecialchars($t['last_msg_at'] ?? $t['created_at'])?></td>
                <td>
                  <a class="btn btn-sm" href="support_view.php?id=<?=$t['id']?>">Ansehen</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php render_footer(); ?>
