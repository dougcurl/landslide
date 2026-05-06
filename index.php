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
    <!-- GA4 updated Jan 3, 2023 - Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-GHBYG6LVJQ"></script>
    <script>
      window.dataLayer = window.dataLayer || [];
      function gtag(){dataLayer.push(arguments);}
      gtag('js', new Date());

      gtag('config', 'G-GHBYG6LVJQ');
      gtag('config', 'UA-3514165-12');
    </script>
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
      <p>Provisional data updated approximately every 45 minutes via Zentra Cloud 2.0 API</p>
    </div>
    <div class="header-right">
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
    <div id="map-loading">
      <div class="spinner"></div>
    </div>

    <!-- Mobile map controls toggle -->
    <button id="map-controls-toggle" title="Map Controls">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"/>
        <line x1="3" y1="12" x2="21" y2="12"/>
        <line x1="3" y1="18" x2="21" y2="18"/>
      </svg>
    </button>

    <!-- Radar opacity control -->
  <div id="radar-controls">
    <label for="radar-opacity">Opacity</label>
    <input type="range" id="radar-opacity" min="0.1" max="1" step="0.05" value="0.7">
    <div class="radar-sep"></div>
    <button id="radar-prev" title="Previous frame">
      <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
        <polygon points="13,2 3,8 13,14"/>
      </svg>
    </button>
    <button id="radar-play" title="Play/Pause">
      <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
        <rect x="3" y="2" width="4" height="12"/>
        <rect x="9" y="2" width="4" height="12"/>
      </svg>
    </button>
    <button id="radar-next" title="Next frame">
      <svg viewBox="0 0 16 16" fill="currentColor" width="12" height="12">
        <polygon points="3,2 13,8 3,14"/>
      </svg>
    </button>
    <span id="radar-time" style="font-family:var(--font-mono);font-size:10px;color:var(--text-muted);min-width:70px;text-align:center;"></span>
    <div class="radar-sep"></div>
    <label style="font-size:10px;">Speed</label>
    <select id="radar-speed" style="background:var(--bg-card);border:1px solid var(--border-bright);color:var(--text-secondary);border-radius:3px;font-size:10px;padding:2px 4px;font-family:var(--font-mono);">
      <option value="800">Slow</option>
      <option value="500" selected>Normal</option>
      <option value="250">Fast</option>
    </select>
  </div>

    <!-- Soil moisture legend -->
    <div id="legend">
      <span>Dry</span>
      <div class="legend-bar"></div>
      <span>Wet</span>
      <span style="margin-left:8px;font-size:10px;">(m³/m³ VWC)</span>
    </div>

    <!-- Time slider -->
    <div id="time-slider-bar">
      <span class="ts-label">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">
          <circle cx="8" cy="8" r="6"/>
          <polyline points="8,4 8,8 6,10"/>
        </svg>
        14-Day History (Soil Moisture Only)
      </span>
      <input type="range" id="time-slider-input" min="0" max="100" value="100" aria-label="time slider">
      <span id="time-slider-status">Loading…</span>
      <span class="ts-live">◀ drag left to go back in time</span>
    </div>

    <!-- ── Station Detail Panel ──────────────────────────────────────── -->
    <div id="detail-panel">
      <div id="panel-header" style="position:relative">
        <div class="panel-title">Select a Station</div>
        <div class="panel-meta"></div>
        <button id="panel-close" title="Close">×</button>
      </div>
      <div id="panel-tabs">
        <button class="panel-tab active" data-tab="info">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
            <circle cx="8" cy="5" r="1.5" fill="currentColor" stroke="none"/>
            <path d="M8 8 v5" stroke-linecap="round"/>
            <circle cx="8" cy="8" r="6.5"/>
          </svg>
          Station Info
        </button>
        <button class="panel-tab" data-tab="data">
          <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
            <path d="M2 12 L5 8 L8 10 L11 5 L14 7" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          Sensor Data
        </button>
      </div>
      <div id="panel-body" style="flex:1;overflow-y:auto;">
        <div id="tab-info"  class="tab-pane active"></div>
        <div id="tab-data"  class="tab-pane"></div>
      </div>
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
      <h1 id="splash-title"><?= htmlspecialchars(SITE_NAME) ?></h1>
      <p><?= htmlspecialchars(SITE_TAGLINE) ?></p>
      <br>
      <p class="splash-subtitle">Data displayed on this service is provisional and should be used for informational purposes only. 
        For more information about the network, data, or landslide hazards in Kentucky, please contact the 
        <a href="https://kygs.uky.edu/research/landslides/" target="_blank" rel="noopener">KGS Landslide Hazards and Engineering Team</a>.</p>
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
            <span>Weather station and soil moisture sensors logging volumetric water content and matric potential at two depths. 
              Values on map show the average volumetric water content for the two depths. 
              <a href="stations.php" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">View details for all stations.</a></span>
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
            <strong>Landslide Susceptibility Layer</strong>
            <span>Toggle the Landslide Susceptibility button to overlay KGS's landslide susceptibility model — a lidar-derived, 
              machine-learning classification of slopes prone to landslides. 
              <a href="https://kgs.uky.edu/kgsmap/helpfiles/landslidesusc_help.shtm" target="_blank" rel="noopener" style="color: inherit; text-decoration: underline;">More information</a>.</span>
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
            <span>Click any station bubble to view weather and sensor readings for the most recent two-week period.</span>
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
            <span>Toggle the Radar button to overlay real-time precipitation map from the National Weather Service NEXRAD network.</span>
          </div>
        </div>

      <div class="splash-card">
        <div class="splash-card-icon">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M23 4v6h-6"/>
            <path d="M1 20v-6h6"/>
            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10"/>
            <path d="M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
          </svg>
        </div>
        <div class="splash-card-text">
          <strong>Auto-Refresh</strong>
          <span>Toggle Auto-refresh in the map controls to automatically reload station moisture data every 45 minutes. NEXRAD radar tiles refresh automatically on the same interval whenever the radar overlay is active</span>
        </div>
      </div>

      <div class="splash-card">
        <div class="splash-card-icon">
          <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="16" cy="16" r="11" stroke="currentColor" stroke-width="2.5"/>
            <polyline points="16,8 16,16 11,20" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M22 5 L25 2 M25 2 v4 M25 2 h-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="splash-card-text">
          <strong>Time Slider</strong>
          <span>Step back through up to 14 days of soil moisture history (radar data does not go back in time) using the Time Slider control. Drag left to travel back in time — marker colors update to reflect conditions at that time. Drag fully right to return to live data</span>
        </div>
      </div>

      <div class="splash-card">
        <div class="splash-card-icon">
          <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="8" width="11" height="8" rx="2" fill="currentColor" opacity="0.8"/>
            <rect x="17" y="8" width="11" height="8" rx="2" fill="currentColor" opacity="0.4"/>
            <path d="M4 22 h24" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.5"/>
            <path d="M8 22 v3 M16 22 v3 M24 22 v3" stroke="currentColor" stroke-width="2" stroke-linecap="round" opacity="0.5"/>
          </svg>
        </div>
        <div class="splash-card-text">
          <strong>Basemap Switcher</strong>
          <span>Switch between ESRI Topo and Kentucky Aerial 3-inch imagery using the basemap control at the top of the map. The aerial layer uses a neutral gray base to fill areas outside KY coverage</span>
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
            <span>Dry</span>
            <span style="margin-left:auto">Wet</span>
          </div>
          <p>Bubble color reflects the average volumetric water content (VWC, m³/m³) across all soil depths at that station. The percentage shown is VWC × 100.</p>
        </div>
      </div>

      

      <div class="splash-footer-note">
        Data refreshed approximately every 45 minutes via Zentra Cloud 2.0 API.
        NEXRAD radar shows live precipitation only and cannot be synced to the time slider.
        Network operated by the <a href="https://kygs.uky.edu/research/landslides/" target="_blank" rel="noopener">Kentucky Geological Survey Landslides Hazards and Engineeering Team</a>, University of Kentucky.
      </div>

    </div><!-- /splash-body -->

    <div id="splash-actions">
      <button id="splash-close">
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