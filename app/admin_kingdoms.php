<?php
// admin_kingdoms.php — Königreiche & Mitgliedschaften verwalten (Admin only, ohne E-Mail)
// Rollen pro Mitgliedschaft; max. 2 Königreiche pro Nutzer
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
require_admin(); // muss in deinem Projekt vorhanden sein

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf'];

function post($k,$d=''){ return $_POST[$k] ?? $d; }
function getv($k,$d=''){ return $_GET[$k] ?? $d; }

$pdo = db();

// Rollenliste (beide Schreibweisen erlaubt)
$ROLES = ['König','Ritter','Spion','Bote','Bauer','Minenarbeiter','Mienenarbeiter'];

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Ungültiges CSRF-Token.');
        }

        if ($action === 'create_kingdom') {
            $name = trim((string)post('name'));
            $desc = trim((string)post('description'));
            if ($name === '') { throw new RuntimeException('Name ist erforderlich.'); }
            $st = $pdo->prepare("INSERT INTO kingdoms(name, description, created_by) VALUES(?,?,?)");
            $st->execute([$name, $desc, (int)current_user()['id']]);
            flash('Königreich erstellt.','success');
            header('Location: admin_kingdoms.php?k='.(int)$pdo->lastInsertId()); exit;
        }

        if ($action === 'delete_kingdom') {
            $kid = (int)post('kingdom_id');
            $pdo->prepare("DELETE FROM kingdoms WHERE id=?")->execute([$kid]);
            flash('Königreich gelöscht.','success');
            header('Location: admin_kingdoms.php'); exit;
        }

        if ($action === 'add_member') {
            $kid  = (int)post('kingdom_id');
            $uid  = (int)post('user_id');
            $role = trim((string)post('role'));
            if (!in_array($role, $ROLES, true)) { throw new RuntimeException('Rolle ungültig.'); }

            // Max 2 Königreiche pro Nutzer (Serverside-Check zusätzlich zum DB-Trigger)
            $st = $pdo->prepare("SELECT COUNT(*) FROM kingdom_memberships WHERE user_id=?");
            $st->execute([$uid]);
            $count = (int)$st->fetchColumn();

            $st2 = $pdo->prepare("SELECT 1 FROM kingdom_memberships WHERE user_id=? AND kingdom_id=?");
            $st2->execute([$uid,$kid]);
            $already = (bool)$st2->fetchColumn();

            if (!$already && $count >= 2) {
                throw new RuntimeException('Dieser Nutzer ist bereits in 2 Königreichen.');
            }

            $st = $pdo->prepare("INSERT OR REPLACE INTO kingdom_memberships(kingdom_id,user_id,role) VALUES(?,?,?)");
            $st->execute([$kid,$uid,$role]);
            flash('Mitglied hinzugefügt/aktualisiert.','success');
            header('Location: admin_kingdoms.php?k='.$kid); exit;
        }

        if ($action === 'change_role') {
            $mid  = (int)post('membership_id');
            $role = trim((string)post('role'));
            if (!in_array($role, $ROLES, true)) { throw new RuntimeException('Rolle ungültig.'); }
            $pdo->prepare("UPDATE kingdom_memberships SET role=? WHERE id=?")->execute([$role,$mid]);
            $kid = (int)$pdo->query("SELECT kingdom_id FROM kingdom_memberships WHERE id=".$mid)->fetchColumn();
            flash('Rolle aktualisiert.','success');
            header('Location: admin_kingdoms.php?k='.$kid); exit;
        }

        if ($action === 'remove_member') {
            $mid = (int)post('membership_id');
            $kid = (int)$pdo->query("SELECT kingdom_id FROM kingdom_memberships WHERE id=".$mid)->fetchColumn();
            $pdo->prepare("DELETE FROM kingdom_memberships WHERE id=?")->execute([$mid]);
            flash('Mitglied entfernt.','success');
            header('Location: admin_kingdoms.php?k='.$kid); exit;
        }
    }

    $current_k = (int)getv('k', 0);

    render_header('Admin · Königreiche', true);
    foreach (consume_flashes() as [$t,$m]) {
        echo '<div class="flash '.htmlspecialchars($t).'">'.htmlspecialchars($m).'</div>';
    }

    echo '<section class="row">';

    // Linke Spalte: Liste + neues Königreich (Bewerberlisten-Stil)
    echo '<div class="card" style="min-width:340px;max-width:420px">';
    echo '<h2>Königreiche</h2>';

    $rs = $pdo->query("
        SELECT k.id,
               k.name,
               strftime('%d.%m.%Y %H:%M', k.created_at) AS created_at,
               (SELECT COUNT(*) FROM kingdom_memberships km WHERE km.kingdom_id = k.id) AS members
        FROM kingdoms k
        ORDER BY k.name ASC
    ");

    echo '<div class="list list-compact">';
    while ($r = $rs->fetch(PDO::FETCH_ASSOC)) {
        $active = ($current_k === (int)$r['id']) ? ' active' : '';
        $mCount = (int)$r['members'];
        echo '<a class="list-item'.$active.'" href="admin_kingdoms.php?k='.$r['id'].'">';
        echo   '<div class="list-main">';
        echo     '<div class="list-title">'.htmlspecialchars($r['name']).'</div>';
        echo     '<div class="list-sub">erstellt am '.htmlspecialchars($r['created_at']).'</div>';
        echo   '</div>';
        echo   '<div class="list-meta">';
        echo     '<span class="badge">'.$mCount.' Mitglied'.($mCount===1?'':'er').'</span>';
        echo   '</div>';
        echo '</a>';
    }
    echo '</div>';

    echo '<hr><h3>Neues Königreich</h3>';
    echo '<form method="post">';
    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
    echo '<input type="hidden" name="action" value="create_kingdom">';
    echo '<label>Name<br><input type="text" name="name" required></label><br><br>';
    echo '<label>Beschreibung (optional)<br><textarea name="description" rows="3"></textarea></label><br><br>';
    echo '<button class="btn btn-primary" type="submit">Erstellen</button>';
    echo '</form>';
    echo '</div>';

    // Rechte Spalte: Details
    if ($current_k > 0) {
        $st = $pdo->prepare("SELECT * FROM kingdoms WHERE id=?");
        $st->execute([$current_k]);
        if ($kg = $st->fetch(PDO::FETCH_ASSOC)) {
            echo '<div class="card" style="flex:1">';
            echo    '<div class="list-meta">';
            echo        '<span class="badge"><a href="/admin_kingdoms.php">X</a></span>';
            echo    '</div>';
            echo '<h2>'.htmlspecialchars($kg['name']).'</h2>';
            if (!empty($kg['description'])) echo '<p>'.nl2br(htmlspecialchars($kg['description'])).'</p>';

            // Mitglieder – ohne E-Mail
            $st = $pdo->prepare("
                SELECT km.id AS membership_id, u.id AS user_id, u.username, km.role, km.created_at
                FROM kingdom_memberships km
                JOIN users u ON u.id = km.user_id
                WHERE km.kingdom_id = ?
                ORDER BY u.username ASC
            ");
            $st->execute([$current_k]);
            $members = $st->fetchAll(PDO::FETCH_ASSOC);

            echo '<h3>Mitglieder</h3>';
            if (!$members) {
                echo '<p><i>Noch keine Mitglieder.</i></p>';
            } else {
                echo '<div class="table-responsive"><table class="table">';
                echo '<thead><tr><th>#</th><th>User</th><th>Rolle</th><th>Seit</th><th>Aktionen</th></tr></thead><tbody>';
                foreach ($members as $i=>$m) {
                    echo '<tr>';
                    echo '<td>'.($i+1).'</td>';
                    echo '<td>'.htmlspecialchars($m['username']).'</td>';
                    echo '<td>';
                    echo '<form method="post" style="display:inline-flex;gap:6px;align-items:center">';
                    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
                    echo '<input type="hidden" name="action" value="change_role">';
                    echo '<input type="hidden" name="membership_id" value="'.$m['membership_id'].'">';
                    echo '<select name="role">';
                    foreach ($ROLES as $r) {
                        $sel = ($r === $m['role']) ? ' selected' : '';
                        echo '<option value="'.htmlspecialchars($r).'"'.$sel.'>'.htmlspecialchars($r).'</option>';
                    }
                    echo '</select>';
                    echo '<button class="btn btn-sm" type="submit">Speichern</button>';
                    echo '</form>';
                    echo '</td>';
                    echo '<td>'.htmlspecialchars(date('d.m.Y H:i', strtotime($m['created_at']))).'</td>';
                    echo '<td>';
                    echo '<form method="post" onsubmit="return confirm(\'Mitglied entfernen?\')" style="display:inline">';
                    echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
                    echo '<input type="hidden" name="action" value="remove_member">';
                    echo '<input type="hidden" name="membership_id" value="'.$m['membership_id'].'">';
                    echo '<button class="btn btn-danger btn-sm" type="submit">Entfernen</button>';
                    echo '</form>';
                    echo '</td>';
                    echo '</tr>';
                }
                echo '</tbody></table></div>';
            }

            // Mitglied hinzufügen
            echo '<hr><h3>Mitglied hinzufügen</h3>';
            $users = $pdo->query("
                SELECT u.id, u.username,
                       (SELECT COUNT(*) FROM kingdom_memberships km WHERE km.user_id=u.id) AS k_count,
                       EXISTS(SELECT 1 FROM kingdom_memberships km2 WHERE km2.user_id=u.id AND km2.kingdom_id=".$current_k.") AS in_this
                FROM users u
                ORDER BY u.username ASC
            ")->fetchAll(PDO::FETCH_ASSOC);

            echo '<form method="post" class="row" style="gap:12px;align-items:end">';
            echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
            echo '<input type="hidden" name="action" value="add_member">';
            echo '<input type="hidden" name="kingdom_id" value="'.$current_k.'">';
            echo '<label style="min-width:260px">Nutzer<br><select name="user_id" required>';
            foreach ($users as $u) {
                $disabled = ($u['k_count'] >= 2 && !$u['in_this']) ? ' disabled' : '';
                $tag = $u['in_this'] ? ' (bereits Mitglied)' : (($u['k_count'] >= 2) ? ' (max erreicht)' : '');
                echo '<option value="'.$u['id'].'"'.$disabled.'>'.htmlspecialchars($u['username'].$tag).'</option>';
            }
            echo '</select></label>';

            echo '<label>Rolle<br><select name="role" required>';
            foreach ($ROLES as $r) echo '<option>'.htmlspecialchars($r).'</option>';
            echo '</select></label>';

            echo '<button class="btn btn-primary" type="submit">Hinzufügen</button>';
            echo '</form>';

            // Königreich löschen
            echo '<hr>';
            echo '<form method="post" onsubmit="return confirm(\'Königreich wirklich löschen? Alle Mitgliedschaften werden entfernt.\')">';
            echo '<input type="hidden" name="csrf" value="'.htmlspecialchars($csrf).'">';
            echo '<input type="hidden" name="action" value="delete_kingdom">';
            echo '<input type="hidden" name="kingdom_id" value="'.$current_k.'">';
            echo '<button class="btn btn-danger">Königreich löschen</button>';
            echo '</form>';

            echo '</div>';
        }
    }

    echo '</section>';

    // Tabellen-Styles + Listen-Styles (Bewerber-Look)
    echo '<style>
      .table { width:100%; border-collapse:collapse; }
      .table th, .table td { padding:8px 10px; border-bottom:1px solid #2a2f3d; }
      .btn-sm { padding:4px 8px; font-size:.9rem; }
      .btn-danger { background:#8b1d1d; color:#fff; }
      .table-responsive{ width:100%; overflow:auto; }

      /* Bewerber-Listenstil für Königreiche */
      .list{display:flex;flex-direction:column;gap:8px;margin:6px 0 10px 0}
      .list-item{display:flex;align-items:center;justify-content:space-between;gap:12px;
        padding:10px 12px;border:1px solid #2a2f3d;border-radius:10px;background:rgba(0,0,0,0.08);
        text-decoration:none;transition:background .15s,border-color .15s,transform .03s}
      .list-item:hover{background:rgba(255,255,255,0.06);border-color:#3a4154}
      .list-item.active{border-color:#7d5cff;box-shadow:0 0 0 1px rgba(125,92,255,.35) inset}
      .list-main{display:flex;flex-direction:column;min-width:0}
      .list-title{font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
      .list-sub{opacity:.75;font-size:.9rem}
      .list-meta{display:flex;align-items:center;gap:8px;flex-shrink:0}
      .badge{display:inline-block;padding:2px 8px;border-radius:999px;background:rgba(125,92,255,.18);
        border:1px solid rgba(125,92,255,.5);font-size:.85rem}
    </style>';

    render_footer();

} catch (Throwable $e) {
    error_log('admin_kingdoms error: '.$e->getMessage());
    render_header('Fehler');
    echo '<p>Interner Fehler: '.htmlspecialchars($e->getMessage()).'</p>';
    render_footer();
}
