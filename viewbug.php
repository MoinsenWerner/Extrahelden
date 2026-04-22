<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    die("Zugriff verweigert.");
}

$pdo = db();
$bugId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['new_status'])) {
        $pdo->prepare("UPDATE bug_reports SET status = ? WHERE id = ?")->execute([$_POST['new_status'], $bugId]);
    } elseif (isset($_POST['action_archive'])) {
        $pdo->prepare("UPDATE bug_reports SET archived = 1 WHERE id = ?")->execute([$bugId]);
        header('Location: bugs_admin.php');
        exit;
    } elseif (isset($_POST['action_delete'])) {
        $pdo->prepare("DELETE FROM bug_reports WHERE id = ?")->execute([$bugId]);
        header('Location: bugs_admin.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM bug_reports WHERE id = ?");
$stmt->execute([$bugId]);
$bug = $stmt->fetch();

if (!$bug) {
    header('Location: bugs_admin.php');
    exit;
}

$pics = json_decode($bug['pictures'] ?? '[]', true);
$serverUploadDir = __DIR__ . '/uploads/bugs/';
$browserUrlDir = 'uploads/bugs/';

render_header('Bug #' . $bugId);
?>

<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h2>Bug Report #<?= $bugId ?></h2>
            <div style="display:flex; gap:10px;">
                <form method="post" style="display:contents">
                    <button type="submit" name="action_archive" class="btn">Archivieren</button>
                    <button type="submit" name="action_delete" class="btn" style="color:#f44" onclick="return confirm('Wirklich löschen?')">Löschen</button>
                </form>
                <a href="bugs_admin.php" class="btn">← Zurück</a>
            </div>
        </div>

        <div style="background: var(--bg); padding:15px; border-radius:10px; border:1px solid var(--border); margin-bottom:25px;">
            <form method="post" id="statusForm" style="display:flex; align-items:center; gap:15px;">
                <label style="font-weight:bold">Status setzen:</label>
                <select name="new_status" onchange="this.form.submit()" style="padding:8px; border-radius:5px; background:var(--card); color:var(--text); border:1px solid var(--border);">
                    <?php 
                    $stati = ['unbearbeitet', 'in Bearbeitung', 'Fertig', 'Größeres Problem'];
                    foreach ($stati as $s): ?>
                        <option value="<?= $s ?>" <?= ($bug['status'] === $s ? 'selected' : '') ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <table style="width:100%; margin-bottom: 20px; border-collapse: collapse;">
            <tr><td style="width:150px; font-weight:bold; padding:8px;">Bereich:</td><td style="padding:8px;"><?= htmlspecialchars($bug['appl']) ?></td></tr>
            <tr><td style="font-weight:bold; padding:8px;">Datum:</td><td style="padding:8px;"><?= htmlspecialchars($bug['created_at']) ?></td></tr>
            <tr><td style="font-weight:bold; padding:8px;">Betreff:</td><td style="padding:8px;"><strong><?= htmlspecialchars($bug['title']) ?></strong></td></tr>
        </table>

        <hr style="border:0; border-top:1px solid var(--border); margin:20px 0;">

        <h3>Beschreibung & Reproduktion</h3>
        <div style="background: var(--bg); padding:15px; border-radius:8px; border:1px solid var(--border); white-space: pre-wrap; line-height:1.6; margin-bottom: 30px;">
            <?= htmlspecialchars($bug['description']) ?>
        </div>

        <?php if (!empty($pics)): ?>
            <h3>Angehängte Bilder</h3>
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:15px;">
                <?php foreach ($pics as $p): 
                    $browserImageUrl = $browserUrlDir . $p;
                    if (file_exists($serverUploadDir . $p)): ?>
                        <div style="text-align:center; width:300px;">
                            <a href="<?= htmlspecialchars($browserImageUrl) ?>" target="_blank">
                                <img src="<?= htmlspecialchars($browserImageUrl) ?>" style="width:100%; height:auto; border-radius:10px; border:2px solid var(--border); box-shadow: var(--shadow);">
                            </a>
                            <div style="font-size:0.8rem; opacity:0.6; margin-top:8px;"><?= htmlspecialchars($p) ?></div>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php render_footer(); ?>
