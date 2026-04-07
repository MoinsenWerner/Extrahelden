<?php
declare(strict_types=1);

/**
 * under_construction.php
 * Professionelle Platzhalterseite im Baustellen-Design.
 */

ob_start();

require __DIR__ . '/db.php';
require __DIR__ . '/_layout.php';

// Session-Management (Konsistenz zu login.php)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

render_header('Baustelle', /*show_nav=*/true);
?>

<style>
    .construction-container {
        text-align: center;
        padding: 3rem 1rem;
        min-width: 100%;
//        margin: 0 auto;
    }

    .warning-tape {
        height: 20px;
        background: repeating-linear-gradient(
            45deg,
            #f1c40f,
            #f1c40f 10px,
            #2c3e50 10px,
            #2c3e50 20px
        );
//        margin: 20px 0;
        border-radius: 4px;
    }

    .construction-icon {
        font-size: 4rem;
//        margin-bottom: 1rem;
        display: block;
    }

    .card.construction-card {
        border-top: 5px solid #f1c40f;
    }

    .btn-back {
        margin-top: 20px;
        display: inline-block;
        background-color: #2c3e50;
        color: white;
        padding: 10px 20px;
        text-decoration: none;
        border-radius: 4px;
        cursor: pointer;
        border: none;
    }
</style>

<section>
    <div class="card construction-card" style="min-width:100%">
        <div class="construction-container">
            <span class="construction-icon">🚧</span>
            <div class="warning-tape" style="min_width:100%"></div>
            
            <h2>In Bearbeitung</h2>
            <p>Die von Ihnen aufgerufene Seite befindet sich aktuell noch im Aufbau.</p>
            <p>Wir arbeiten mit Hochdruck an der Fertigstellung. Bitte schauen Sie zu einem späteren Zeitpunkt erneut vorbei.</p>
            
            <div class="warning-tape"></div>

            <button onclick="window.history.back();" class="btn btn-back">
                &larr; Zurück zur vorherigen Seite
            </button>
        </div>
    </div>
</section>

<?php
render_footer();
ob_end_flush();

