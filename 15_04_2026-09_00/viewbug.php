<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Admin-Check (Muss in deinem System definiert sein)
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

// Bilder-Array aus der DB entschlüsseln
$pics = json_decode($bug['pictures'] ?? '[]', true);

// NEU: Basis-Pfad für Bilder auf dem Server und für den Browser.
// Wenn dein PHP-Skript im Hauptverzeichnis liegt, ist das korrekt.
$serverUploadDir = __DIR__ . '/uploads/bugs/';
$browserUrlDir = 'uploads/bugs/'; // Relativ zur Web-Adresse

render_header('Bug #' . $bugId);
?>

<section class="row">
    <div class="card" style="width:100%">
        <div style="display:flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h2>Bug Report #<?= $bugId ?></h2>
            <a href="bugs_admin.php" class="btn">← Zurück zur Liste</a>
        </div>

        <table style="width:100%; margin-bottom: 20px; border-collapse: collapse;">
            <tr>
                <td style="width:150px; font-weight:bold; padding: 5px;">Status:</td>
                <td style="padding: 5px;"><span class="badge"><?= htmlspecialchars($bug['appl']) ?></span></td>
            </tr>
            <tr>
                <td style="font-weight:bold; padding: 5px;">Datum:</td>
                <td style="padding: 5px;"><?= htmlspecialchars($bug['created_at']) ?></td>
            </tr>
            <tr>
                <td style="font-weight:bold; padding: 5px;">Betreff:</td>
                <td style="padding: 5px;"><strong><?= htmlspecialchars($bug['title']) ?></strong></td>
            </tr>
        </table>

        <hr style="border:0; border-top:1px solid var(--border); margin:20px 0;">

        <h3>Beschreibung & Reproduktion</h3>
        <div style="background: var(--card); padding:15px; border-radius:8px; border:1px solid var(--border); white-space: pre-wrap; line-height:1.6; margin-bottom: 30px;">
            <?= htmlspecialchars($bug['description']) ?>
        </div>

        <?php if (!empty($pics)): ?>
            <h3>Angehängte Bilder (anklicken zum Vergrößern)</h3>
            <div style="display:flex; gap:20px; flex-wrap:wrap; margin-top:15px;">
                <?php foreach ($pics as $p): 
                    $serverFilePath = $serverUploadDir . $p;
                    $browserImageUrl = $browserUrlDir . $p;

                    // NEU: Prüfen, ob die Datei auf dem Server wirklich existiert
                    if (file_exists($serverFilePath)): ?>
                        <div style="text-align:center; width:300px;"> <a href="<?= htmlspecialchars($browserImageUrl) ?>" target="_blank">
                                <img src="<?= htmlspecialchars($browserImageUrl) ?>" 
                                     alt="Bugbild: <?= htmlspecialchars($p) ?>" 
                                     style="width:100%; height:auto; border-radius:10px; border:2px solid var(--border); box-shadow: 0 4px 8px rgba(0,0,0,0.3); transition: transform 0.2s;">
                            </a>
                            <div style="font-size:0.8rem; opacity:0.6; margin-top:8px; word-wrap: break-word;">
                                <?= htmlspecialchars($p) ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="color:#b00; border: 1px solid #b00; padding: 10px; border-radius: 8px;">
                            Fehlende Datei:<br><?= htmlspecialchars($p) ?>
                        </div>
                    <?php endif; 
                endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php render_footer(); ?>
