<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$projectId = $_GET['project_id'] ?? '';
$path = $_GET['path'] ?? '';
$baseDir = realpath(__DIR__ . '/../../../../servers/' . $projectId);
if (!$baseDir) {
    die('Projekt nicht gefunden');
}
$currentDir = realpath($baseDir . '/' . $path);
if (!$currentDir || strpos($currentDir, $baseDir) !== 0) {
    die('Ungültiger Pfad');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['newfolder']) && $_POST['newfolder'] !== '') {
        mkdir($currentDir . '/' . basename($_POST['newfolder']));
    } elseif (isset($_POST['newfile']) && $_POST['newfile'] !== '') {
        file_put_contents($currentDir . '/' . basename($_POST['newfile']), '');
    } elseif (isset($_POST['delete'])) {
        $target = $currentDir . '/' . basename($_POST['delete']);
        if (is_dir($target)) {
            rmdir($target);
        } elseif (is_file($target)) {
            unlink($target);
        }
    } elseif (isset($_FILES['upload'])) {
        $file = $_FILES['upload'];
        if ($file['error'] === UPLOAD_ERR_OK) {
            move_uploaded_file($file['tmp_name'], $currentDir . '/' . basename($file['name']));
        }
    }
    header('Location: file_manager.php?project_id=' . urlencode($projectId) . '&path=' . urlencode($path));
    exit;
}

$items = array_diff(scandir($currentDir), ['.', '..']);
require 'templates/header.php';
?>
<h1>Dateimanager für Projekt <?php echo htmlspecialchars($projectId); ?></h1>
<p><a href="server_control.php?project_id=<?php echo urlencode($projectId); ?>">Zurück</a></p>
<ul>
<?php foreach ($items as $item): $itemPath = $path === '' ? $item : "$path/$item"; ?>
    <li>
        <?php if (is_dir($currentDir . '/' . $item)): ?>
            [DIR] <a href="file_manager.php?project_id=<?php echo urlencode($projectId); ?>&path=<?php echo urlencode($itemPath); ?>"><?php echo htmlspecialchars($item); ?></a>
        <?php else: ?>
            <?php echo htmlspecialchars($item); ?>
        <?php endif; ?>
        <form style="display:inline" method="post">
            <input type="hidden" name="delete" value="<?php echo htmlspecialchars($item); ?>">
            <button type="submit">Löschen</button>
        </form>
    </li>
<?php endforeach; ?>
</ul>
<form method="post">
    Neuer Ordner: <input type="text" name="newfolder">
    <button type="submit">Erstellen</button>
</form>
<form method="post">
    Neue Datei: <input type="text" name="newfile">
    <button type="submit">Erstellen</button>
</form>
<form method="post" enctype="multipart/form-data">
    <input type="file" name="upload">
    <button type="submit">Hochladen</button>
</form>
<?php require 'templates/footer.php'; ?>
