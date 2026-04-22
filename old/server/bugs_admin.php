<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Admin-Check (muss in deinem System definiert sein, z.B. in der db.php oder session)
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    die("Zugriff verweigert. Nur Administratoren.");
}

$pdo = db();

// Löschen-Funktion (optional, aber hilfreich)
if (isset($_GET['delete'])) {
    $stmt = $pdo->prepare("DELETE FROM bug_reports WHERE id = ?");
    $stmt->execute([(int)$_GET['delete']]);
    header('Location: bugs_admin.php');
    exit;
}

$reports = $pdo->query("SELECT * FROM bug_reports ORDER BY created_at DESC")->fetchAll();

render_header('Bug-Verwaltung');
?>

<section class="row">
    <div class="card" style="width:100%">
        <h2>Eingegangene Bug-Reports</h2>
        
        <?php if (empty($reports)): ?>
            <p><em>Keine Reports gefunden.</em></p>
        <?php else: ?>
            <table style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                        <th style="padding:10px">Datum</th>
                        <th style="padding:10px">Bereich</th>
                        <th style="padding:10px">Betreff</th>
                        <th style="padding:10px">Aktion</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $r): 
                        $pics = json_decode($r['pictures'] ?? '[]', true);
                    ?>
                        <tr style="border-bottom: 1px solid var(--border);">
                            <td style="padding:10px"><?=htmlspecialchars($r['created_at'])?></td>
                            <td style="padding:10px"><span class="badge"><?=htmlspecialchars($r['appl'])?></span></td>
                            <td style="padding:10px"><strong><?=htmlspecialchars($r['title'])?></strong></td>
                            <td style="padding:10px">
                                <button onclick="document.getElementById('details-<?=$r['id']?>').style.display='block'" class="btn">Ansehen</button>
                                <a href="?delete=<?=$r['id']?>" class="btn" style="color:red" onclick="return confirm('Wirklich löschen?')">Löschen</a>
                            </td>
                        </tr>
                        <tr id="details-<?=$r['id']?>" style="display:none; background: rgba(0,0,0,0.05);">
                            <td colspan="4" style="padding:20px;">
                                <strong>Beschreibung:</strong><br>
                                <p style="white-space: pre-wrap; margin:10px 0;"><?=htmlspecialchars($r['description'])?></p>
                                
                                <?php if (!empty($pics)): ?>
                                    <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:10px;">
                                        <?php foreach ($pics as $p): ?>
                                            <a href="uploads/bugs/<?=$p?>" target="_blank">
                                                <img src="uploads/bugs/<?=$p?>" style="width:150px; height:100px; object-fit:cover; border-radius:5px; border:1px solid var(--border)">
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                <br>
                                <button onclick="document.getElementById('details-<?=$r['id']?>').style.display='none'" class="btn">Schließen</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</section>

<?php render_footer(); ?>
