<?php
/**
 * KGS Landslide Monitoring Network
 * index.php — Main map interface
 */
require_once __DIR__ . '/config.php';
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(SITE_NAME) ?></title>
  <meta name="description" content="Interactive landslide soil moisture monitoring network — <?= htmlspecialchars(SITE_ORG) ?>">

  <!-- ArcGIS JS SDK 4.x -->
  <link rel="stylesheet" href="https://js.arcgis.com/4.29/esri/themes/light/main.css">
  <script src="https://js.arcgis.com/4.29/"></script>

  <!-- Chart.js is loaded dynamically inside app.js AFTER ArcGIS require()  -->
  <!-- completes — loading it here conflicts with Dojo's AMD module loader. -->

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <link rel="stylesheet" href="css/style.css">
  <link rel="stylesheet" href="css/splash.css">
</head>
<body>

<div id="app">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <header id="header">
    <a href="https://kgs.uky.edu" target="_blank" rel="noopener" class="kgs-logo-link" title="Kentucky Geological Survey">
      <img src="https://kgs.uky.edu/kygeode/img/UK-KGSlogos/KGS-new/kgs-logo-final.png"
           alt="Kentucky Geological Survey" class="kgs-logo">
    </a>
    <div class="header-divider"></div>
    <div class="title-block">
      <h1><?= htmlspecialchars(SITE_NAME) ?></h1>
      <p><?= htmlspecialchars(SITE_ORG) ?> &nbsp;·&nbsp; <?= count(STATIONS) ?> Monitoring Stations</p>
    </div>
    <div class="header-right">
      <button class="radar-toggle" id="susceptibility-toggle" title="Toggle Landslide Susceptibility Layer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 20 L8 10 L13 15 L17 7 L21 20 Z" stroke-linejoin="round"/>
          <path d="M3 20 h18" stroke-linecap="round"/>
        </svg>
        Susceptibility
      </button>
      <button class="radar-toggle" id="radar-toggle" title="Toggle NEXRAD Weather Radar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M12 2a10 10 0 0 1 10 10"/>
          <path d="M12 6a6 6 0 0 1 6 6"/>
          <path d="M12 10a2 2 0 0 1 2 2"/>
          <line x1="12" y1="12" x2="12" y2="2"/>
        </svg>
        Radar
      </button>
      <span id="last-updated"></span>
      <button class="info-btn" id="splash-reopen" title="About this application" aria-label="About">
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="10" cy="10" r="8"/>
          <line x1="10" y1="9" x2="10" y2="14"/>
          <circle cx="10" cy="6.5" r="0.5" fill="currentColor" stroke="none"/>
        </svg>
      </button>
    </div>
  </header>

  <!-- ── Map ────────────────────────────────────────────────────────────── -->
  <div id="map-container">
    <div id="map"></div>

    <!-- Radar opacity control -->
    <div id="radar-controls">
      <label for="radar-opacity">Radar Opacity</label>
      <input type="range" id="radar-opacity" min="0.1" max="1" step="0.05" value="0.7">
    </div>

    <!-- Soil moisture legend -->
    <div id="legend">
      <span>Dry</span>
      <div class="legend-bar"></div>
      <span>Wet</span>
      <span style="margin-left:8px;font-size:9px;opacity:.5">(m³/m³ VWC)</span>
    </div>

    <!-- ── Station Detail Panel ──────────────────────────────────────── -->
    <div id="detail-panel">
      <div id="panel-header" style="position:relative">
        <div class="panel-title">Select a Station</div>
        <div class="panel-meta"></div>
        <button id="panel-close" title="Close">×</button>
      </div>
      <div id="panel-body"></div>
    </div>

  </div><!-- /map-container -->
</div><!-- /app -->

<script>
  window.STATION_CONFIG = <?= json_encode(array_map(fn($s) => [
    'station_id' => $s['id'],
    'name'       => $s['name'],
    'lat'        => $s['lat'],
    'lng'        => $s['lng'],
    'region'     => $s['region'],
  ], STATIONS)) ?>;
</script>

<script>
  // Splash dismiss
  document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.getElementById('splash-overlay');
    var btn     = document.getElementById('splash-close');
    function dismiss() {
      overlay.classList.add('splash-hiding');
      setTimeout(function() { overlay.style.display = 'none'; }, 400);
    }
    btn.addEventListener('click', dismiss);

    var reopenBtn = document.getElementById('splash-reopen');
    reopenBtn.addEventListener('click', function() {
      overlay.classList.remove('splash-hiding');
      overlay.style.display = 'flex';
    });
    overlay.addEventListener('click', function(e) {
      if (e.target === overlay) dismiss();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') dismiss();
    });
  });
</script>
<script src="js/app.js"></script>


<!-- ── Splash Modal ──────────────────────────────────────────────────────── -->
<div id="splash-overlay" role="dialog" aria-modal="true" aria-labelledby="splash-title">
  <div id="splash-modal">

    <div id="splash-header">
      <div class="splash-logo-row">
        <img src="https://kgs.uky.edu/kygeode/img/UK-KGSlogos/KGS-new/kgs-logo-final.png"
             alt="Kentucky Geological Survey" class="splash-logo">
      </div>
      <div class="splash-tag">Real-Time Monitoring Network</div>
      <h1 id="splash-title">Kentucky Landslide<br>Monitoring Network</h1>
      <p class="splash-subtitle">Soil moisture surveillance across Eastern Kentucky's most landslide-prone terrain</p>
    </div>

    <div id="splash-body">

      <div class="splash-grid">
        <div class="splash-card">
          <div class="splash-card-icon">
            <!-- Soil layers icon -->
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <rect x="4" y="6"  width="24" height="6" rx="2" fill="currentColor" opacity="0.9"/>
              <rect x="4" y="14" width="24" height="6" rx="2" fill="currentColor" opacity="0.6"/>
              <rect x="4" y="22" width="24" height="4" rx="2" fill="currentColor" opacity="0.35"/>
            </svg>
          </div>
          <div class="splash-card-text">
            <strong><?= count(STATIONS) ?> Monitoring Stations</strong>
            <span>Dual-depth soil moisture sensors logging volumetric water content at shallow and deep horizons across Eastern Kentucky</span>
          </div>
        </div>

        <div class="splash-card">
          <div class="splash-card-icon">
            <!-- Terrain/layers icon -->
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M4 24 L10 14 L16 19 L21 10 L28 24 Z" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
              <line x1="4" y1="24" x2="28" y2="24" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.5"/>
            </svg>
          </div>
          <div class="splash-card-text">
            <strong>Susceptibility Layer</strong>
            <span>Toggle the Susceptibility button to overlay KGS's landslide susceptibility model — a lidar-derived, machine-learning classification of slope instability across Eastern Kentucky</span>
          </div>
        </div>

        <div class="splash-card">
          <div class="splash-card-icon">
            <!-- Clock/history -->
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <circle cx="16" cy="16" r="11" stroke="currentColor" stroke-width="2.5"/>
              <polyline points="16,9 16,16 21,20" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <div class="splash-card-text">
            <strong>14-Day History</strong>
            <span>Click any station bubble to view sensor readings, matric potential, soil temperature, and precipitation charts</span>
          </div>
        </div>

        <div class="splash-card">
          <div class="splash-card-icon">
            <!-- Radar arcs -->
            <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M16 16 A4 4 0 0 1 20 16"  stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              <path d="M16 16 A8 8 0 0 1 24 16"  stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.7"/>
              <path d="M16 16 A12 12 0 0 1 28 16" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.4"/>
              <line x1="16" y1="4" x2="16" y2="16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>
          <div class="splash-card-text">
            <strong>Live NEXRAD Radar</strong>
            <span>Toggle the Radar button to overlay real-time precipitation from the NWS NEXRAD network</span>
          </div>
        </div>
      </div>

      <div class="splash-how">
        <div class="splash-how-title">Reading the Map</div>
        <div class="splash-how-row">
          <div class="splash-swatch-row">
            <span class="swatch" style="background:#6b3a2a"></span>
            <span class="swatch" style="background:#c4813c"></span>
            <span class="swatch" style="background:#c9a84c"></span>
            <span class="swatch" style="background:#8ab56e"></span>
            <span class="swatch" style="background:#5dba7d"></span>
            <span class="swatch" style="background:#2a7a52"></span>
          </div>
          <div class="splash-swatch-labels">
            <span>Very Dry</span>
            <span style="margin-left:auto">Saturated</span>
          </div>
          <p>Bubble color reflects the average volumetric water content (VWC, m³/m³) across all soil depths at that station. The percentage shown is VWC × 100.</p>
        </div>
      </div>

      <div class="splash-footer-note">
        Data refreshed approximately every 30 minutes via Zentra Cloud 2.0 API. 
        Network operated by the <a href="https://kgs.uky.edu" target="_blank" rel="noopener">Kentucky Geological Survey</a>, University of Kentucky.
      </div>

    </div><!-- /splash-body -->

    <div id="splash-actions">
      <button id="splash-close" autofocus>
        Explore the Network
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 10h12M10 4l6 6-6 6"/>
        </svg>
      </button>
    </div>

  </div><!-- /splash-modal -->
</div><!-- /splash-overlay -->


</body>
</html>