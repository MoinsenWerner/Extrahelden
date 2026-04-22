<?php
// world_downloads.php – Öffentliche Downloads („World Downloads“)
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

$docs = db()->query("
  SELECT id, filename
  FROM documents
  WHERE COALESCE(is_public,0) = 1
  ORDER BY filename ASC
")->fetchAll();

render_header('World Downloads');
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>
<section>
  <h1>World Downloads</h1>
  <?php if (empty($docs)): ?>
    <p><em>Derzeit sind keine öffentlichen Downloads verfügbar.</em></p>
  <?php else: ?>
    <table>
      <thead><tr><th>Datei</th><th style="width:160px">Aktion</th></tr></thead>
      <tbody>
        <?php foreach ($docs as $d): ?>
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
