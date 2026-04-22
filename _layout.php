<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

/* ---- Flash-API ---- */
if (!function_exists('flash')) {
    function flash(string $msg, string $type='info'): void { $_SESSION['flash'][] = [$type, $msg]; }
}
if (!function_exists('consume_flashes')) {
    function consume_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }
}

/* ---- Layout ---- */
if (!function_exists('render_header')) {
    function render_header(string $title, bool $show_nav = true): void {
        $username = $_SESSION['username'] ?? null;
        $is_admin = isset($_SESSION['is_admin']) && (int)$_SESSION['is_admin'] === 1;

        // apply.php Link nur anzeigen, wenn Setting aktiv (db.php muss vorher eingebunden sein)
        $applyEnabled = function_exists('get_setting') ? (get_setting('apply_enabled','0') === '1') : false;
        $applyTitle   = function_exists('get_setting') ? get_setting('apply_title','Projekt-Anmeldung') : 'Projekt-Anmeldung';

        // Theme
        $theme = $_COOKIE['theme'] ?? 'light';
        if (!in_array($theme, ['light','dark'], true)) { $theme = 'light'; }
        $metaThemeColor = ($theme === 'dark') ? '#1c2230' : '#ffffff';
        $reqUri = $_SERVER['REQUEST_URI'] ?? '/';
        ?>
<!doctype html>
<html lang="de" class="theme-<?=htmlspecialchars($theme)?>">
<head>
  <meta charset="utf-8">
  <title><?=htmlspecialchars($title)?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?=$metaThemeColor?>">

  <!-- Favicons -->
  <link rel="icon" href="/favicon.ico">
  <link rel="icon" type="image/svg+xml" href="/assets/icons/favicon.svg">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
  <link rel="mask-icon" href="/assets/icons/safari-pinned-tab.svg" color="#0d6efd">
  <link rel="manifest" href="/assets/icons/site.webmanifest">

  <style>
    /* ====== Design-Variablen (Light als Default) ====== */
    :root{
      --bg:#f6f7fb;
      --text:#0f1220;
      --card:#ffffff;
      --muted:#eef1f6;
      --border:#dce1ea;
      --link:#0b62f6;
      --primary:#0b62f6;
      --primary-contrast:#ffffff;
      --danger:#dc3545;
      --danger-contrast:#ffffff;
      --ok:#1a7f37;
      --warn:#a16300;
      --shadow:0 1px 3px rgba(0,0,0,.06),0 4px 12px rgba(0,0,0,.04);
      --radius:12px;
      --radius-sm:8px;
      --radius-lg:16px;
      --pad:14px;
    }

    /* ====== Dark Mode ====== */
    .theme-dark{
      --bg:#1c2230;
      --text:#e9eef6;
      --card:#232a3b;
      --muted:#202738;
      --border:#35405a;
      --link:#7fb3ff;
      --primary:#3b82f6;
      --primary-contrast:#0e1422;
      --danger:#ef4444;
      --danger-contrast:#0e1422;
      --shadow:0 1px 3px rgba(0,0,0,.35),0 4px 14px rgba(0,0,0,.25);
    }

    /* ====== Grundlayout ====== */
    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      color:var(--text);
      background:var(--bg);
      font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,"Noto Sans","Liberation Sans",sans-serif;
    }
    a{color:var(--link);text-decoration:none}
    a:hover{text-decoration:underline}

        
        .player-dead {
    background-color: rgba(220, 53, 69, 0.2) !important; /* Transparentes Rot */
}
.bw-filter {
    filter: grayscale(100%);
}

        
        
    .container{max-width:1200px;margin:0 auto;padding:18px}
    header.site{
      position:sticky;top:0;z-index:50;
      background:rgba(255,255,255,.85);
      backdrop-filter:blur(8px);
      border-bottom:1px solid var(--border);
    }
    .theme-dark header.site{ background:rgba(28,34,48,.8); }
    .nav{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
    /* Brand */
    .brand{
      display:inline-flex;align-items:center;gap:10px;
      font-weight:700;letter-spacing:.2px;margin-right:8px;color:inherit;text-decoration:none
    }
    .brand-logo{width:24px;height:24px;object-fit:contain;border-radius:6px;display:block}

    .spacer{flex:1}
    main{padding:24px 0}

    /* ====== Layout-Utilities ====== */
    .row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start}
   /* .card{
      background:var(--card);
      border:1px solid var(--border);
      border-radius:var(--radius);
      padding:var(--pad);
      box-shadow:var(--shadow);
      flex:1 1 320px;
      min-width:300px;
    } */
        .card{
            background:var(--card);
            border:1px solid var(--border);
            border-radius:var(--radius);
            padding:var(--pad);
            box-shadow:var(--shadow);
                flex: 0 0 auto;     /* verhindert Strecken */
            width: fit-content; /* passt sich Inhalt an */
            max-width: 100%;
            min-width: unset;   /* entfernt Mindestbreite */
        }

    /* ====== Inputs/Buttons ====== */
    input[type=text],input[type=password],input[type=url],input[type=number],select,textarea,input[type=file]{
      width:100%;padding:9px 10px;border:1px solid var(--border);border-radius:10px;background:#fff;color:#111
    }
    .theme-dark input[type=text],.theme-dark input[type=password],.theme-dark input[type=url],.theme-dark input[type=number],.theme-dark select,.theme-dark textarea,.theme-dark input[type=file]{
      background:#1f2534;color:var(--text);border-color:var(--border)
    }
    textarea{min-height:120px;resize:vertical}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
      padding:8px 12px;border:1px solid var(--border);border-radius:10px;
      background:var(--muted);color:var(--text);cursor:pointer;text-decoration:none;white-space:nowrap
    }
    .btn:hover{filter:brightness(1.02);text-decoration:none}
    .btn:disabled{opacity:.6;cursor:not-allowed}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:var(--primary-contrast)}
    .btn-danger{background:var(--danger);border-color:var(--danger);color:var(--danger-contrast)}
    .btn-ghost{background:transparent}
    .btn-sm{padding:6px 8px;font-size:.9rem;border-radius:8px}
    .is-active{outline:2px solid var(--primary);outline-offset:1px}

    /* ====== Tabellen ====== */
    table{width:100%;border-collapse:separate;border-spacing:0}
    th,td{padding:8px 10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top;background:var(--card)}
    thead th{position:sticky;top:0;background:var(--muted);z-index:1}
    .table-wrap{overflow:auto;max-height:360px;border:1px solid var(--border);border-radius:8px}
    .table-wrap table{min-width:800px}

    /* ====== Badges / Chips ====== */
    .badge{padding:2px 10px;border-radius:999px;font-size:.85rem;display:inline-block;border:1px solid transparent}
    .badge.pending{background:#eef;border-color:#cce;color:#223}
    .badge.accepted{background:#e9f7ef;border-color:#c6e6cf;color:#185e2d}
    .badge.rejected{background:#fdecea;border-color:#f5c6cb;color:#8a1f1f}
    .doc-chip{display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border:1px solid var(--border);border-radius:999px;background:#f7f7f7;margin:2px}
    .theme-dark .doc-chip{background:#263049}

    /* ====== Bewerber-Kacheln ====== */
    .apps-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}
    .app-card{display:flex;align-items:center;gap:10px;padding:10px;border:1px solid var(--border);border-radius:12px;background:var(--card);transition:transform .06s ease, box-shadow .06s ease;text-decoration:none;color:inherit}
    .app-card:hover{transform:translateY(-1px);box-shadow:0 2px 8px rgba(0,0,0,.08)}
    .app-head{width:32px;height:32px;border-radius:6px;background:#f0f0f0;object-fit:cover}
    .theme-dark .app-head{background:#2a3348}
    .app-name{font-weight:600}
    .app-meta{display:flex;flex-direction:column}

    /* ====== Flash ====== */
    .flash{padding:10px 12px;border-radius:10px;border:1px solid var(--border);margin:10px 0}
    .flash.success{background:rgba(16,185,129,.15);border-color:rgba(16,185,129,.35)}
    .flash.error{background:rgba(239,68,68,.15);border-color:rgba(239,68,68,.35)}
    .flash.info{background:rgba(59,130,246,.12);border-color:rgba(59,130,246,.30)}

    /* ===== Menüs (Hamburger) ===== */
    .menu{position:relative}
    .menu .hamburger{display:inline-block;width:18px;height:14px;position:relative}
    .menu .hamburger span,
    .menu .hamburger::before,
    .menu .hamburger::after{
      content:"";display:block;height:2px;background:currentColor;border-radius:2px;position:absolute;left:0;right:0
    }
    .menu .hamburger span{top:6px}
    .menu .hamburger::before{top:0}
    .menu .hamburger::after{bottom:0}
    .dropdown{
      position:absolute; right:0;  top:calc(100% + 6px);
      background:var(--card); border:1px solid var(--border); border-radius:10px; box-shadow:var(--shadow);
      min-width:220px; z-index:60; overflow:hidden
    }
    .dropdown a{
      display:block; padding:10px 12px; color:var(--text); text-decoration:none; border-bottom:1px solid var(--border)
    }
    .dropdown a:last-child{border-bottom:none}
    .dropdown a:hover{background:var(--muted); text-decoration:none}
  </style>
</head>
<body>
  <header class="site">
    <div class="container">
      <div class="nav">
        <!-- Brand: Logo + Wortmarke -->
        <a href="index.php" class="brand btn btn-ghost" style="padding:6px 8px" title="Extrahelden – Startseite">
          <img class="brand-logo"
               src="/logo.png"
               onerror="this.src='/assets/icons/apple-touch-icon.png'; this.onerror=null;"
               alt="Extrahelden Logo">
          <strong>Extrahelden</strong>
        </a>

        <?php if ($show_nav): ?>
          <?php if (!$username): ?>
            <!-- Besucher: Direkt sichtbare Tabs -->
            <!--a class="btn btn-ghost" href="index.php">Start</a-->
            <a class="btn btn-ghost" href="world_downloads.php">World Downloads</a>
            <?php if ($applyEnabled): ?>
              <a class="btn btn-ghost" href="apply.php"><?=htmlspecialchars($applyTitle)?> Bewerbungen</a>
            <?php endif; ?>
            <a class="btn btn-ghost" href="under_construction.php">Duelle</a>
            <a disabled class="btn btn-ghost" href="status.php">Spieler-Status</a>
            <a class="btn btn-ghost" href="bugreport.php">Bug-Report</a>
            <a class="btn btn-ghost" href="https://crafty.extrahelden.de/status">Serverstatus</a>
          <?php else: ?>
            <!-- Mitglieder: Hamburger-Menü -->
            <div class="menu">
              <button id="memberMenuBtn" class="btn" aria-haspopup="true" aria-expanded="false" aria-controls="memberMenu" title="Mitglieder-Menü">
                <span class="hamburger" aria-hidden="true"><span></span></span>
                <span style="margin-left:6px">Menü</span>
              </button>
              <div id="memberMenu" class="dropdown" hidden>
                <!--<a href="index.php">Start</a>-->
                <a href="documents.php">Dokumente</a>
                <a href="https://crafty.extrahelden.de/status">Serverstatus</a>
                <?php if ($applyEnabled): ?>
                  <!--<a href="apply.php"><?=htmlspecialchars($applyTitle)?></a>-->
                <?php endif; ?>
                <a href="support.php">Support</a>
                <a href="account.php">Konto</a>
                <a href="under_construction.php">Duelle</a>
                <a href="status.php">Spieler-Status</a>
                <a href="bugreport.php">Bug-Report</a>
                <!--a href="votes.php">Abstimmungen</a-->
              </div>
            </div>
          <?php endif; ?>

          <!-- Admin-Menü als Hamburger -->
          <?php if ($is_admin): ?>
            <div class="menu">
              <button id="adminMenuBtn" class="btn" aria-haspopup="true" aria-expanded="false" aria-controls="adminMenu" title="Admin-Menü">
                <span class="hamburger" aria-hidden="true"><span></span></span>
                <span style="margin-left:6px">Admin</span>
              </button>
              <div id="adminMenu" class="dropdown" hidden>
                <a href="admin.php">Admin-Dashboard</a>
                <a href="admin_calendar.php">Kalender</a>
                <a href="admin_tickets.php">Tickets</a>
                <!--a href="admin_kingdoms.php">Königreiche</a-->
                <a href="admin_automator.php">DC-Bot Trigger</a>
                <a href="admin_events.php">DC-Bot Events</a>
                <a href="bugs_admin.php">Bugs</a>
              </div>
            </div>
          <?php endif; ?>

          <span class="spacer"></span>

          <!-- Theme Toggle -->
          <?php $r = urlencode($reqUri); ?>
          <a class="btn btn-sm <?= $theme==='light'?'is-active':'' ?>" href="theme.php?t=light&r=<?=$r?>">Light</a>
          <a class="btn btn-sm <?= $theme==='dark'?'is-active':''  ?>" href="theme.php?t=dark&r=<?=$r?>">Dark</a>

          <?php if ($username): ?>
            <span style="margin-left:8px">Angemeldet als <strong><?=htmlspecialchars($username)?></strong></span>
            <a class="btn" href="logout.php">Logout</a>
          <?php else: ?>
            <a class="btn btn-primary" href="login.php">Login</a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($show_nav): ?>
    <script>
      (function(){
        // generische Menu-Toggler
        function attachMenu(btnId, menuId){
          const btn  = document.getElementById(btnId);
          const menu = document.getElementById(menuId);
          if (!btn || !menu) return;

          function openMenu(){ menu.hidden = false; btn.setAttribute('aria-expanded','true'); }
          function closeMenu(){ menu.hidden = true; btn.setAttribute('aria-expanded','false'); }

          btn.addEventListener('click', (e)=>{
            e.preventDefault();
            if (menu.hidden) openMenu(); else closeMenu();
          });

          document.addEventListener('click', (e)=>{
            if (menu.hidden) return;
            if (e.target === btn || btn.contains(e.target)) return;
            if (!menu.contains(e.target)) closeMenu();
          });

          document.addEventListener('keydown', (e)=>{
            if (e.key === 'Escape') closeMenu();
          });
        }

        attachMenu('memberMenuBtn','memberMenu');
        attachMenu('adminMenuBtn','adminMenu');
      })();
    </script>
    <?php endif; ?>
  </header>

  <main>
    <div class="container">
<?php
    }
}

if (!function_exists('render_footer')) {
    /**
     * @param array $extra_links Array aus ['url' => '...', 'label' => '...']
     */
    function render_footer(array $extra_links = []): void { 
        // Standard-Links (Impressum immer dabei)
        $links = array_merge([
            ['url' => 'impressum.html', 'label' => 'Impressum'],
            ['url' => 'https://www.tiktok.com/@extrahelden_6_official', 'label' => 'TikTok'],
            ['url' => 'https://discord.gg/FaMFTsMYeG', 'label' => 'Discord']
        ], $extra_links);

        // Map für Social Media Icons (SVG Pfade)
        $icons = [
            'tiktok'    => 'M26.7009 9.91314V13.2472C26.7009 13.2472 25.1213 13.1853 23.9515 12.8879C22.3188 12.4718 21.2688 11.8337 21.2688 11.8337C21.2688 11.8337 20.5444 11.3787 20.4858 11.3464V20.1364C20.4858 20.6258 20.3518 21.8484 19.9432 22.8672C19.4098 24.2012 18.5867 25.0764 18.4353 25.2553C18.4353 25.2553 17.4337 26.4384 15.668 27.2352C14.0765 27.9539 12.6788 27.9357 12.2604 27.9539C12.2604 27.9539 9.84473 28.0496 7.66995 26.6366L7.6591 26.6288C7.42949 26.4064 7.21336 26.1717 7.01177 25.9257C6.31777 25.0795 5.89237 24.0789 5.78547 23.7934C5.78529 23.7922 5.78529 23.791 5.78547 23.7898C5.61347 23.2937 5.25209 22.1022 5.30147 20.9482C5.38883 18.9122 6.10507 17.6625 6.29444 17.3494C6.79597 16.4957 7.44828 15.7318 8.22233 15.0919C8.90538 14.5396 9.6796 14.1002 10.5132 13.7917C11.4144 13.4295 12.3794 13.2353 13.3565 13.2197V16.5948C13.3565 16.5948 11.5691 16.028 10.1388 17.0317C9.13879 17.7743 8.60812 18.4987 8.45185 19.7926C8.44534 20.7449 8.68897 22.0923 10.0254 22.991C10.1813 23.0898 10.3343 23.1775 10.4845 23.2541C10.7179 23.5576 11.0021 23.8221 11.3255 24.0368C12.631 24.8632 13.7249 24.9209 15.1238 24.3842C16.0565 24.0254 16.7586 23.2167 17.0842 22.3206C17.2888 21.7611 17.2861 21.1978 17.2861 20.6154V4.04639H20.5417C20.6763 4.81139 21.0485 5.90039 22.0328 6.97898C22.4276 7.38624 22.8724 7.7463 23.3573 8.05134C23.5006 8.19955 24.2331 8.93231 25.1734 9.38216C25.6596 9.61469 26.1722 9.79285 26.7009 9.91314Z',
            'instagram' => 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z',
            'youtube'   => 'M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z',
            'discord'   => 'M20.317 4.37a19.791 19.791 0 00-4.885-1.515.074.074 0 00-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 00-5.487 0 12.64 12.64 0 00-.617-1.25.077.077 0 00-.079-.037 19.736 19.736 0 00-4.885 1.515.069.069 0 00-.032.027C.533 9.048-.32 13.58.099 18.057a.082.082 0 00.031.057 19.9 19.9 0 005.993 3.03.078.078 0 00.084-.028 14.09 14.09 0 001.226-1.994.076.076 0 00-.041-.106 13.107 13.107 0 01-1.872-.892.077.077 0 01-.008-.128 10.2 10.2 0 00.372-.292.074.074 0 01.077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 01.078.01c.12.098.246.198.373.292a.077.077 0 01-.006.127 12.299 12.299 0 01-1.873.892.077.077 0 00-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 00.084.028 19.839 19.839 0 006.002-3.03.077.077 0 00.032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 00-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.419 0 1.334-.956 2.419-2.157 2.419zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.419 0 1.334-.946 2.419-2.157 2.419z',
        ];
        ?>
    </div></main>

  <footer style="border-top: 1px solid var(--border); padding: 24px 0; margin-top: 40px; background: var(--card);">
    <div class="container">
      <div style="display: flex; align-items: center; justify-content: center; gap: 20px; flex-wrap: wrap;">
        <?php foreach ($links as $link): 
            $url = $link['url'];
            $label = $link['label'];
            $iconPath = null;

            // Automatische Erkennung des Icons anhand der URL
            foreach ($icons as $key => $path) {
                if (str_contains(strtolower($url), $key)) {
                    $iconPath = $path;
                    break;
                }
            }
        ?>
          <a href="<?= htmlspecialchars($url) ?>" class="btn btn-ghost" style="display: inline-flex; align-items: center; gap: 8px;">
            <?php if ($iconPath): ?>
              <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="<?= $iconPath ?>"></path>
              </svg>
            <?php endif; ?>
            <span><?= htmlspecialchars($label) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div style="text-align: center; margin-top: 16px; font-size: 0.85rem; opacity: 0.6;">
        &copy; <?= date('Y') ?> Extrahelden
      </div>
    </div>
  </footer>
</body>
</html>
<?php }
}
