<?php
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';
require_login();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

/* -------- POST: Dokument löschen (nur Admin) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$is_admin) {
        flash('Keine Berechtigung zum Löschen von Dokumenten.', 'error');
        header('Location: documents.php'); exit;
    }
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        flash('Ungültiges CSRF-Token.', 'error');
        header('Location: documents.php'); exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'delete_document') {
        $doc_id = (int)($_POST['id'] ?? 0);
        if ($doc_id <= 0) {
            flash('Ungültige Dokument-ID.', 'error');
            header('Location: documents.php'); exit;
        }

        $pdo = db();
        $st  = $pdo->prepare('SELECT filename, path FROM documents WHERE id = ?');
        $st->execute([$doc_id]);
        $doc = $st->fetch();

        if (!$doc) {
            flash('Dokument nicht gefunden.', 'error');
            header('Location: documents.php'); exit;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM user_documents WHERE document_id = ?')->execute([$doc_id]);
            $pdo->prepare('DELETE FROM documents WHERE id = ?')->execute([$doc_id]);
            $pdo->commit();

            $path = (string)($doc['path'] ?? '');
            if ($path !== '' && is_file($path)) { @unlink($path); }

            flash('Dokument gelöscht: ' . (string)$doc['filename'], 'success');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('DELETE DOCUMENT ERROR: '.$e->getMessage());
            flash('Fehler beim Löschen: ' . $e->getMessage(), 'error');
        }

        header('Location: documents.php'); exit;
    }

    flash('Unbekannte Aktion.', 'error');
    header('Location: documents.php'); exit;
}

/* -------- Dokumentlisten -------- */
if ($is_admin) {
    // Admin: alle Dokumente
    $docs = db()->query('SELECT id, filename FROM documents ORDER BY filename ASC')->fetchAll();
} else {
    // User: nur zugewiesene
    $stmt = db()->prepare('
        SELECT d.id, d.filename
        FROM documents d
        JOIN user_documents ud ON ud.document_id = d.id
        WHERE ud.user_id = ?
        ORDER BY d.filename ASC
    ');
    $stmt->execute([$user_id]);
    $docs = $stmt->fetchAll();
}

// Öffentliche Dateien (zusätzliches Segment)
$publicDocs = db()->query('SELECT id, filename FROM documents WHERE COALESCE(is_public,0)=1 ORDER BY filename ASC')->fetchAll();

/* -------- Render -------- */
render_header('Dokumente');
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>
<section>
  <h1>Dokumente</h1>

  <h2><?= $is_admin ? 'Alle Dokumente' : 'Deine Dokumente' ?></h2>
  <?php if (empty($docs)): ?>
    <p><em>Keine Dokumente vorhanden.</em></p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Datei</th>
          <th style="width:<?= $is_admin ? '260px' : '160px' ?>">Aktion</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
          <tr>
            <td><?=htmlspecialchars($d['filename'])?></td>
            <td>
              <a class="btn btn-primary" href="download.php?id=<?=$d['id']?>">Download</a>
              <?php if ($is_admin): ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Datei wirklich löschen? Dies entfernt auch die Zuweisungen.')">
                  <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                  <input type="hidden" name="action" value="delete_document">
                  <input type="hidden" name="id" value="<?=$d['id']?>">
                  <button class="btn btn-danger" type="submit">Löschen</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <h2>Für alle (World Downloads)</h2>
  <?php if (empty($publicDocs)): ?>
    <p><em>Keine öffentlichen Dateien.</em></p>
  <?php else: ?>
    <table>
      <thead>
        <tr><th>Datei</th><th style="width:160px">Aktion</th></tr>
      </thead>
      <tbody>
        <?php foreach ($publicDocs as $d): ?>
          <tr>
            <td><?=htmlspecialchars($d['filename'])?></td>
            <td><a class="btn btn-primary" href="download.php?id=<?=$d['id']?>">Download</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</section>

<section>
  <a href="index.php" class="btn">← Zurück zur Startseite</a>
</section>
<?php render_footer(); ?>
