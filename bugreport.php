<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Datenbank-Tabelle sicherstellen
$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS bug_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    appl TEXT,
    title TEXT,
    description TEXT,
    pictures TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$status = "";
$errors = [];

// Mapping für URL-Parameter
$types = [
    'mc'   => 'MC-Server-Bug',
    'dc'   => 'DC-Bug',
    'web'  => 'Website-Bug',
    'diff' => 'Anderer Bug'
];

$selectedBug = $_GET['bug'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appl = $_POST['appl'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    
    // Validierung
    if (!array_key_exists($appl, $types)) $errors[] = "Ungültige Bug-Art.";
    if (strlen($title) < 3) $errors[] = "Betreff muss mindestens 3 Zeichen lang sein.";
    if (strlen($desc) < 20) $errors[] = "Beschreibung muss mindestens 20 Zeichen lang sein.";

    $uploadedFiles = [];
    if (empty($errors) && !empty($_FILES['pics']['name'][0])) {
        $totalSize = 0;
        $files = $_FILES['pics'];
        
        foreach ($files['size'] as $size) $totalSize += $size;

        if (count($files['name']) > 20) {
            $errors[] = "Maximal 20 Bilder erlaubt.";
        } elseif ($totalSize > 25 * 1024 * 1024) {
            $errors[] = "Gesamtgröße darf 25MB nicht überschreiten.";
        } else {
            // Upload-Verzeichnis sicherstellen
            if (!is_dir('uploads/bugs')) mkdir('uploads/bugs', 0777, true);

            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] === 0) {
                    $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                    // 7-stellige Hex-Zahl generieren
                    $newName = str_pad(dechex(random_int(0, 0xFFFFFFF)), 7, '0', STR_PAD_LEFT) . '.' . $ext;
                    $target = 'uploads/bugs/' . $newName;
                    if (move_uploaded_file($files['tmp_name'][$i], $target)) {
                        $uploadedFiles[] = $newName;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("INSERT INTO bug_reports (appl, title, description, pictures) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $types[$appl],
            $title,
            $desc,
            json_encode($uploadedFiles)
        ]);
        set_flash('success', 'Bug-Report wurde erfolgreich übermittelt.');
        header('Location: bugreport.php');
        exit;
    }
}

render_header('Bug melden');
?>

<section class="row">
    <div class="card" style="width:100%; max-width:800px; margin: 0 auto;">
        <h2>Bug melden</h2>
        
        <?php foreach (consume_flashes() as [$t,$m]): ?>
            <div class="flash <?=htmlspecialchars($t)?>"><?=htmlspecialchars($m)?></div>
        <?php endforeach; ?>

        <?php if (!empty($errors)): ?>
            <div class="flash error">
                <ul><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul>
            </div>
        <?php endif; ?>

        <form action="bugreport.php" method="post" enctype="multipart/form-data">
            <div style="margin-bottom:15px">
                <label>Art des Bugs:</label><br>
                <select name="appl" style="width:100%; padding:8px; border-radius:5px; border:1px solid var(--border); background:var(--bg); color:var(--text)">
                    <?php foreach ($types as $key => $val): ?>
                        <option value="<?=$key?>" <?=($selectedBug === $key ? 'selected' : '')?>><?=$val?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:15px">
                <label>Betreff (min. 3 Zeichen):</label><br>
                <input type="text" name="title" required minlength="3" style="width:100%; padding:8px; border-radius:5px; border:1px solid var(--border); background:var(--bg); color:var(--text)">
            </div>

            <div style="margin-bottom:15px">
                <label>Beschreibung & Reproduktion (min. 20 Zeichen):</label><br>
                <textarea name="description" required minlength="20" rows="6" style="width:100%; padding:8px; border-radius:5px; border:1px solid var(--border); background:var(--bg); color:var(--text)"></textarea>
            </div>

            <div style="margin-bottom:15px">
                <label>Bilder (Optional, max. 20 Stk / 25MB gesamt):</label><br>
                <input type="file" name="pics[]" multiple accept="image/*">
            </div>

            <button type="submit" class="btn btn-primary">Report absenden</button>
        </form>
    </div>
</section>

<?php render_footer(); ?>
