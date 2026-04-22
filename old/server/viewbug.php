<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Admin-Check
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    die("Zugriff verweigert.");
}

$pdo = db();
$bugId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Bug-Daten abrufen
$stmt = $pdo->prepare("SELECT * FROM bug_reports WHERE id = ?");
$stmt->execute([$bugId]);
$bug = $stmt->fetch();

if (!$bug) {
    render_header('Fehler');
    echo '<section class="row"><div class="card"><h2>Bug nicht gefunden.</h2><a href="bugs_admin.php" class="btn">Zurück</a></div></section>';
    render_footer();
    exit;
}

$pics = json_decode($bug['pictures'] ?? '[]', true);

render_header('Bug #' . $bugId);
?>

<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Bug Report #<?= $bugId ?></h2>
            <a href="bugs_admin.php" class="btn">← Zurück zur Liste</a>
        </div>

        <table style="width:100%; margin-bottom: 20px;">
            <tr>
                <td style="width:150px; font-weight:bold;">Status:</td>
                <td><span class="badge"><?= htmlspecialchars($bug['appl']) ?></span></td>
            </tr>
            <tr>
                <td style="font-weight:bold;">Datum:</td>
                <td><?= htmlspecialchars($bug['created_at']) ?></td>
            </tr>
            <tr>
                <td style="font-weight:bold;">Betreff:</td>
                <td><strong><?= htmlspecialchars($bug['title']) ?></strong></td>
            </tr>
        </table>

        <hr style="border:0; border-top:1px solid var(--border); margin:20px 0;">

        <h3>Beschreibung & Reproduktion</h3>
        <div style="background: var(--bg); padding:15px; border-radius:8px; border:1px solid var(--border); white-space: pre-wrap; line-height:1.6;">
            <?= htmlspecialchars($bug['description']) ?>
        </div>

        <?php if (!empty($pics)): ?>
            <h3 style="margin-top:30px;">Angehängte Bilder</h3>
            <div style="display:flex; gap:15px; flex-wrap:wrap;">
                <?php foreach ($pics as $p): ?>
                    <div style="text-align:center">
                        <a href="uploads/bugs/<?= htmlspecialchars($p) ?>" target="_blank">
                            <img src="uploads/bugs/<?= htmlspecialchars($p) ?>" 
                                 style="width:200px; height:auto; border-radius:8px; border:1px solid var(--border); box-shadow: var(--shadow);">
                        </a>
                        <div style="font-size:0.7rem; opacity:0.5; margin-top:5px;"><?= htmlspecialchars($p) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php render_footer(); ?>
