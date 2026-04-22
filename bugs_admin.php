<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();

// Hilfsfunktion: Prüft ob eine Spalte existiert, um den 500er Error zu vermeiden
function addColumnIfMissing($pdo, $table, $column, $definition) {
    $check = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    $exists = false;
    foreach ($check as $col) {
        if ($col['name'] === $column) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    }
}

// Spalten sicher hinzufügen
addColumnIfMissing($pdo, 'bug_reports', 'archived', 'INTEGER DEFAULT 0');
addColumnIfMissing($pdo, 'bug_reports', 'status', "TEXT DEFAULT 'unbearbeitet'");

// Massenaktionen verarbeiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bug_ids'])) {
    $ids = array_map('intval', $_POST['bug_ids']);
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        if (isset($_POST['action_archive'])) {
            $pdo->prepare("UPDATE bug_reports SET archived = 1 WHERE id IN ($placeholders)")->execute($ids);
        } elseif (isset($_POST['action_delete'])) {
            $pdo->prepare("DELETE FROM bug_reports WHERE id IN ($placeholders)")->execute($ids);
        }
        header('Location: bugs_admin.php');
        exit;
    }
}

$reports = $pdo->query("SELECT * FROM bug_reports WHERE archived = 0 ORDER BY created_at DESC")->fetchAll();

render_header('Bug-Verwaltung');
?>

<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Eingegangene Bug-Reports</h2>
            <a href="archived_bugs.php" class="btn">Zum Archiv</a>
        </div>

        <form method="post">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding:10px; width:40px;"><input type="checkbox" onclick="toggleAll(this)"></th>
                        <th style="padding:10px; width:40px;">Status</th>
                        <th style="padding:10px; width:60px;">ID</th>
                        <th style="padding:10px">Datum</th>
                        <th style="padding:10px">Bereich</th>
                        <th style="padding:10px">Betreff</th>
                        <th style="padding:10px; text-align:right;">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): 
                        $statusColors = [
                            'unbearbeitet' => 'gray',
                            'in Bearbeitung' => 'orange',
                            'Fertig' => 'green',
                            'Größeres Problem' => 'red'
                        ];
                        $color = $statusColors[$r['status']] ?? 'gray';
                    ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding:10px"><input type="checkbox" name="bug_ids[]" value="<?= $r['id'] ?>"></td>
                            <td style="padding:10px"><span title="<?= htmlspecialchars($r['status']) ?>" style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= $color ?>"></span></td>
                            <td style="padding:10px">#<?= (int)$r['id'] ?></td>
                            <td style="padding:10px"><?= htmlspecialchars($r['created_at']) ?></td>
                            <td style="padding:10px"><?= htmlspecialchars($r['appl']) ?></td>
                            <td style="padding:10px"><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                            <td style="padding:10px; text-align:right;">
                                <a href="viewbug.php?id=<?= (int)$r['id'] ?>" class="btn btn-primary">Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:20px; display:flex; gap:10px;">
                <button type="submit" name="action_archive" class="btn">Markierte archivieren</button>
                <button type="submit" name="action_delete" class="btn" style="color:var(--error-color, #f44)" onclick="return confirm('Markierte wirklich löschen?')">Markierte löschen</button>
            </div>
        </form>
    </div>
</section>

<script>
function toggleAll(source) {
    const checkboxes = document.getElementsByName('bug_ids[]');
    for(let i=0; i < checkboxes.length; i++) checkboxes[i].checked = source.checked;
}
</script>

<?php render_footer(); ?>
