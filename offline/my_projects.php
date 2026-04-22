<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

// API Call → Projekte abholen
$api_url = "http://localhost:5000/projects?user_id=" . urlencode($user_id);
$projects_json = file_get_contents($api_url);

if ($projects_json === FALSE) {
    die('API Fehler beim Laden der Projekte!');
}

$projects = json_decode($projects_json, true);
?>

<h1>Meine Projekte</h1>
<p>Eingeloggt als: <?php echo htmlspecialchars($_SESSION['username']); ?> | <a href="logout.php">Logout</a></p>
<p><a href="project_configurator.php">Neues Projekt anlegen</a></p>

<?php if (count($projects) === 0): ?>
    <p>Du hast noch keine Projekte angelegt.</p>
<?php else: ?>
    <table border="1" cellpadding="5" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Projektname</th>
            <th>Serverart</th>
            <th>RAM (GB)</th>
            <th>Preis (€)</th>
            <th>Status</th>
            <th>Erstellt am</th>
            <th>Aktionen</th>
        </tr>
        <?php foreach ($projects as $project): ?>
        <tr>
            <td><?php echo $project['id']; ?></td>
            <td><?php echo htmlspecialchars($project['project_name']); ?></td>
            <td><?php echo htmlspecialchars($project['server_type']); ?></td>
            <td><?php echo $project['ram']; ?></td>
            <td><?php echo number_format($project['price'], 2); ?></td>
            <td><?php echo htmlspecialchars($project['status']); ?></td>
            <td><?php echo $project['created_at']; ?></td>
            <td><a href="server_control.php?project_id=<?php echo $project['id']; ?>">Verwalten</a></td>
        </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
