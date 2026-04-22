<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) { die("Zugriff verweigert."); }

$pdo = db();
$bugId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_unarchive'])) {
        $pdo->prepare("UPDATE bug_reports SET archived = 0 WHERE id = ?")->execute([$bugId]);
        header('Location: archived_bugs.php'); exit;
    } elseif (isset($_POST['action_delete'])) {
        $pdo->prepare("DELETE FROM bug_reports WHERE id = ?")->execute([$bugId]);
        header('Location: archived_bugs.php'); exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM bug_reports WHERE id = ?");
$stmt->execute([$bugId]);
$bug = $stmt->fetch();
if (!$bug) { header('Location: archived_bugs.php'); exit; }

render_header('Archivierter Bug #' . $bugId);
?>
<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; margin-bottom: 20px;">
            <h2>Archivierter Report #<?= $bugId ?></h2>
            <form method="post" style="display:flex; gap:10px">
                <button type="submit" name="action_unarchive" class="btn">Aus Archiv entfernen</button>
                <button type="submit" name="action_delete" class="btn" style="color:red" onclick="return confirm('Endgültig löschen?')">Löschen</button>
                <a href="archived_bugs.php" class="btn">Zurück</a>
            </form>
        </div>
        <p><strong>Status:</strong> <?= htmlspecialchars($bug['status']) ?></p>
        <p><strong>Betreff:</strong> <?= htmlspecialchars($bug['title']) ?></p>
        <p><strong>Inhalt:</strong><br><?= nl2br(htmlspecialchars($bug['description'])) ?></p>
    </div>
</section>
<?php render_footer(); ?>
