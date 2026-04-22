<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php'); exit;
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bug_ids'])) {
    $ids = array_map('intval', $_POST['bug_ids']);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    if (isset($_POST['action_unarchive'])) {
        $pdo->prepare("UPDATE bug_reports SET archived = 0 WHERE id IN ($placeholders)")->execute($ids);
    } elseif (isset($_POST['action_delete'])) {
        $pdo->prepare("DELETE FROM bug_reports WHERE id IN ($placeholders)")->execute($ids);
    }
    header('Location: archived_bugs.php'); exit;
}

$reports = $pdo->query("SELECT * FROM bug_reports WHERE archived = 1 ORDER BY created_at DESC")->fetchAll();
render_header('Archiv');
?>
<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Archivierte Bug-Reports</h2>
            <a href="bugs_admin.php" class="btn">Zurück zur Verwaltung</a>
        </div>
        <form method="post">
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding:10px"><input type="checkbox" onclick="toggleAll(this)"></th>
                        <th></th><th style="padding:10px">ID</th><th style="padding:10px">Datum</th><th style="padding:10px">Bereich</th><th style="padding:10px">Betreff</th><th style="padding:10px">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): 
                        $statusColors = ['unbearbeitet'=>'gray','in Bearbeitung'=>'orange','Fertig'=>'green','Größeres Problem'=>'red'];
                    ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding:10px"><input type="checkbox" name="bug_ids[]" value="<?= $r['id'] ?>"></td>
                            <td><span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:<?= $statusColors[$r['status']] ?? 'gray' ?>"></span></td>
                            <td style="padding:10px">#<?= (int)$r['id'] ?></td>
                            <td style="padding:10px"><?= htmlspecialchars($r['created_at']) ?></td>
                            <td style="padding:10px"><?= htmlspecialchars($r['appl']) ?></td>
                            <td style="padding:10px"><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                            <td style="padding:10px"><a href="viewarchivedbug.php?id=<?= (int)$r['id'] ?>" class="btn">Details</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top:20px">
                <button type="submit" name="action_unarchive" class="btn">Markierte wiederherstellen</button>
                <button type="submit" name="action_delete" class="btn" style="color:red" onclick="return confirm('Endgültig löschen?')">Markierte löschen</button>
            </div>
        </form>
    </div>
</section>
<script>function toggleAll(source) { checkboxes = document.getElementsByName('bug_ids[]'); for(var i=0; n=checkboxes[i]; i++) n.checked = source.checked; }</script>
<?php render_footer(); ?>
