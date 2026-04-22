<?php
// admin_shortlist.php – Übersicht aller 'shortlisted' Bewerbungen mit Aktionen
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

/* Liste laden */
$apps = db()->query("SELECT id, mc_name, mc_uuid, status, discord_name, created_at FROM applications WHERE status='shortlisted' ORDER BY datetime(created_at) DESC")->fetchAll(PDO::FETCH_ASSOC);

render_header('Engere Auswahl – Bewerbungen');
foreach (consume_flashes() as [$t,$m]) {
    echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
}
?>
<style>
  .apps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}
  .app-card{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--card);text-decoration:none;color:inherit}
  .app-head{width:32px;height:32px;border-radius:6px;background:#f0f0f0;object-fit:cover}
  .theme-dark .app-head{background:#2a3348}
  .app-name{font-weight:600}
  .stack-sm{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}
</style>

<section class="row">
  <div class="card" style="flex:1">
    <h2>Engere Auswahl</h2>
    <?php if (empty($apps)): ?>
      <p><em>Aktuell keine Bewerbungen in der engeren Auswahl.</em></p>
    <?php else: ?>
      <div class="apps-grid">
        <?php foreach ($apps as $a):
          $uuid = $a['mc_uuid'] ? strtolower($a['mc_uuid']) : '';
          $avatar = $uuid ? 'https://crafatar.com/avatars/'.htmlspecialchars($uuid).'?size=32&overlay' : '';
        ?>
        <div class="app-card">
          <?php if ($avatar): ?><img class="app-head" src="<?=$avatar?>" alt=""><?php else: ?><div class="app-head"></div><?php endif; ?>
          <div style="flex:1">
            <div class="app-name"><?=htmlspecialchars($a['mc_name'])?></div>
            <div class="stack-sm">
              <a class="btn btn-sm" href="admin_application.php?id=<?=$a['id']?>">Details</a>

              <form method="post" action="admin_application.php?id=<?=$a['id']?>">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="accept_app">
                <button class="btn btn-sm btn-primary" type="submit">Annehmen</button>
              </form>

              <form method="post" action="admin_application.php?id=<?=$a['id']?>" onsubmit="return confirm('Diese Bewerbung ablehnen?')">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="reject_app">
                <button class="btn btn-sm btn-danger" type="submit">Ablehnen</button>
              </form>

              <form method="post" action="admin_application.php?id=<?=$a['id']?>">
                <input type="hidden" name="csrf" value="<?=htmlspecialchars($csrf)?>">
                <input type="hidden" name="action" value="unshortlist_app">
                <button class="btn btn-sm" type="submit">Entfernen</button>
              </form>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>

<section>
  <a href="admin.php" class="btn">← Zurück zur Adminseite</a>
</section>

<?php render_footer(); ?>
