<?php
/**
 * status.php – Eigenständige Spieler-Statusseite mit responsivem Tabellen-Layout
 */
declare(strict_types=1);
require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

/* ========= 1. API-BACKEND (AJAX-Handler) ========= */
if (isset($_GET['ajax_sync'])) {
    while (ob_get_level()) { ob_end_clean(); }
    ob_start();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $db = db();
        $stmt = $db->prepare("SELECT mc_name, mc_uuid FROM applications WHERE status = 'accepted'");
        $stmt->execute();
        $dbData = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $nameToUuid = [];
        $namesArray = [];
        foreach ($dbData as $row) {
            $nameToUuid[$row['mc_name']] = $row['mc_uuid'];
            $namesArray[] = $row['mc_name'];
        }

        $payload = implode(',', $namesArray);
        $ch = curl_init('http://127.0.0.1:8965/api/get/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) throw new Exception("API-Fehler: $httpCode");

        $apiData = json_decode(trim($response), true);
        $players = [];
        if (is_array($apiData)) {
            foreach ($apiData as $entry) {
                if (!isset($entry['name'])) continue;
                $players[] = [
                    'name'   => $entry['name'],
                    'uuid'   => $nameToUuid[$entry['name']] ?? 'steve',
                    'hearts' => (float)($entry['hearts'] ?? 0),
                    'linked' => (bool)($entry['linkedheart_activated'] ?? false),
                    'banned' => (bool)($entry['banned'] ?? false)
                ];
            }
        }

        ob_end_clean(); 
        echo json_encode(['success' => true, 'players' => $players]);
    } catch (Exception $e) {
        if (ob_get_length()) ob_end_clean();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ========= 2. SEITEN-DARSTELLUNG ========= */
render_header("Spieler Status"); // Nutzt _layout.php
?>

<style>
    /* Grundlegende Status-Styles */
    .bw-filter { filter: grayscale(100%) brightness(0.8); }
    .player-inactive { color: #dc3545 !important; text-decoration: line-through; opacity: 0.7; }
    .heart-icon { width: 18px; margin-right: 2px; vertical-align: middle; }
    .player-head { width: 24px; height: 24px; border-radius: 3px; vertical-align: middle; }

    /* Responsive Tabellen-Logik */
    .table-container {
        width: 100%;
        overflow-x: auto; /* Aktiviert horizontales Scrollen falls nötig */
        -webkit-overflow-scrolling: touch;
    }

    .table-responsive {
        width: 100%;
        border-collapse: collapse;
        table-layout: auto; /* Sorgt dafür, dass Inhalte nicht abgeschnitten werden */
        min-width: 400px; /* Verhindert extremes Quetschen auf Kleinstgeräten */
    }

    .player-name-wrapper {
        white-space: nowrap; /* Verhindert Zeilenumbruch bei Namen */
    }
</style>

<section class="container">
    <div class="card" style="min-width:100%">
        <div class="card-header">
            <h2 class="card-title">Live Spieler-Status</h2>
        </div>
        <div class="card-body" style="padding: 0;"> <div class="table-container">
                <table class="table-responsive">
                    <thead>
                        <tr style="border-bottom: 2px solid var(--border); text-align: left;">
                            <th style="padding: 12px 15px;">Spieler</th>
                            <th style="padding: 12px 15px;">Herzen</th>
                            <th style="padding: 12px 15px; text-align: center;">Linkedheart aktiviert?</th>
                        </tr>
                    </thead>
                    <tbody id="mc-table-body">
                        <tr><td colspan="3" style="padding: 30px; text-align: center;">Initialisiere Live-Daten...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>

<script>
async function syncMinecraft() {
    try {
        const response = await fetch('?ajax_sync=1');
        const text = await response.text();
        const clean = text.trim().replace(/^\uFEFF/, '');
        const data = JSON.parse(clean);
        
        const tbody = document.getElementById('mc-table-body');
        if (data.success && tbody) {
            tbody.innerHTML = data.players.map(p => {
                const currentHearts = Math.round(p.hearts);
                const isEliminated = (currentHearts <= 0 && p.linked === false);
                const isBanned = (p.banned === true);
                const isActive = (!isEliminated && !isBanned);
                
                const nameClass = isActive ? '' : 'class="player-inactive"';
                const imgClass = isActive ? '' : 'class="bw-filter"';

                return `
                <tr style="border-bottom: 1px solid var(--border);">
                    <td style="padding: 12px 15px;">
                        <div class="player-name-wrapper" style="display: flex; align-items: center; gap: 10px;">
                            <img src="https://mc-heads.net/avatar/${p.uuid}/24/nohelm.png" 
                                 ${imgClass} class="player-head" alt="">
                            <span ${nameClass} style="font-weight: 500;">${p.name}</span>
                        </div>
                    </td>
                    <td style="padding: 12px 15px; white-space: nowrap;">
                        ${[1,2,3].map(i => `
                            <img src="${currentHearts >= i ? 'https://www.extrahelden.de/fullheart.png' : 'https://www.extrahelden.de/emptyheart.png'}" 
                                 class="heart-icon" alt="heart">
                        `).join('')}
                    </td>
                    <td style="padding: 12px 15px; text-align: center;">
                        ${isBanned ? '<span title="Banned">☠️</span>' : (p.linked ? '✅' : '<span style="opacity:0.3">❌</span>')}
                    </td>
                </tr>`;
            }).join('');
        }
    } catch (e) {
        console.error("Sync-Fehler:", e);
    } finally {
        setTimeout(syncMinecraft, 3000);
    }
}

document.addEventListener('DOMContentLoaded', syncMinecraft);
</script>

<?php 
render_footer(); // Nutzt _layout.php
?>
