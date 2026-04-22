<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Admin-Check (muss in deinem System definiert sein)
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

$pdo = db();
$reports = $pdo->query("SELECT id, appl, title, created_at FROM bug_reports ORDER BY created_at DESC")->fetchAll();

render_header('Bug-Verwaltung');
?>

<section class="row">
    <div class="card" style="width:100%">
        <h2>Eingegangene Bug-Reports</h2>
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                    <th style="padding:10px">ID</th>
                    <th style="padding:10px">Datum</th>
                    <th style="padding:10px">Bereich</th>
                    <th style="padding:10px">Betreff</th>
                    <th style="padding:10px">Aktion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reports as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td style="padding:10px">#<?= (int)$r['id'] ?></td>
                        <td style="padding:10px"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td style="padding:10px"><span class="badge"><?= htmlspecialchars($r['appl']) ?></span></td>
                        <td style="padding:10px"><strong><?= htmlspecialchars($r['title']) ?></strong></td>
                        <td style="padding:10px">
                            <a href="viewbug.php?id=<?= (int)$r['id'] ?>" class="btn btn-primary">Details</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
