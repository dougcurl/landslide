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
  <link rel="stylesheet" href="https://js.arcgis.com/4.29/esri/themes/dark/main.css">
  <script src="https://js.arcgis.com/4.29/"></script>

  <!-- Chart.js is loaded dynamically inside app.js AFTER ArcGIS require()  -->
  <!-- completes — loading it here conflicts with Dojo's AMD module loader. -->

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

  <link rel="stylesheet" href="css/style.css">
</head>
<body>

<div id="app">

  <!-- ── Header ─────────────────────────────────────────────────────────── -->
  <header id="header">
    <div class="logo-mark">KGS</div>
    <div class="title-block">
      <h1><?= htmlspecialchars(SITE_NAME) ?></h1>
      <p><?= htmlspecialchars(SITE_ORG) ?> &nbsp;·&nbsp; <?= count(STATIONS) ?> Monitoring Stations</p>
    </div>
    <div class="header-right">
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

<script src="js/app.js"></script>

</body>
</html>