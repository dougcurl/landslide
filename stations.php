<?php
/**
 * stations.php
 * Stand-alone station information directory.
 * Reads directly from config.php — no cache needed.
 * Place in the landslide application root alongside index.php.
 */
require_once __DIR__ . '/config.php';

// ── Slug helper ────────────────────────────────────────────────────────────────
function slugify(string $name): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($name)));
}

// ── Susceptibility badge helper ────────────────────────────────────────────────
function susc_class(string $val): string {
    return match(strtolower(trim($val))) {
        'very low'  => 'susc-very-low',
        'low'       => 'susc-low',
        'moderate'  => 'susc-moderate',
        'high'      => 'susc-high',
        'very high' => 'susc-very-high',
        default     => 'susc-unknown',
    };
}

// ── Which station to show? ?id=z6-XXXXX or directory view ─────────────────────
$requested_id = trim($_GET['id'] ?? '');
$active = null;
if ($requested_id) {
    foreach (STATIONS as $s) {
        if ($s['id'] === $requested_id) { $active = $s; break; }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $active ? htmlspecialchars($active['name']) . ' — ' : 'Station Directory — ' ?><?= htmlspecialchars(SITE_NAME) ?></title>
  <meta name="description" content="KGS Landslide Monitoring Network station information">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    /* ── Variables (match dashboard) ───────────────────────────────────────── */
    :root {
      --bg-dark:       #0e1612;
      --bg-panel:      #1a2420;
      --bg-card:       #222e29;
      --bg-card-hover: #2a3830;
      --bg-header:     #0d1f3c;
      --border:        rgba(140,195,160,0.18);
      --border-bright: rgba(140,195,160,0.38);
      --text-primary:  #f0f5f2;
      --text-secondary:#b8d4c0;
      --text-muted:    #7a9e8a;
      --accent-green:  #5ec47f;
      --accent-blue:   #55afd4;
      --accent-amber:  #e0844a;
      --accent-gold:   #d4a840;
      --font-body:     'DM Sans', sans-serif;
      --font-mono:     'DM Mono', monospace;
      --radius:        7px;
      --shadow:        0 4px 24px rgba(0,0,0,0.5);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: var(--font-body);
      background: var(--bg-dark);
      color: var(--text-primary);
      min-height: 100vh;
      font-size: 14px;
      line-height: 1.6;
    }

    a { color: var(--accent-green); text-decoration: none; }
    a:hover { text-decoration: underline; }

    /* ── Header ────────────────────────────────────────────────────────────── */
    #site-header {
      background: var(--bg-header);
      border-bottom: 2px solid rgba(100,150,220,0.35);
      padding: 0 32px;
      height: 72px;
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .kgs-logo {
      height: 36px;
      filter: brightness(0) invert(1);
      opacity: 0.9;
    }

    .header-divider {
      width: 1px;
      height: 28px;
      background: var(--border-bright);
    }

    .header-titles h1 {
      font-size: 16px;
      font-weight: 600;
      color: var(--text-primary);
      line-height: 1.2;
    }

    .header-titles p {
      font-size: 10px;
      font-family: var(--font-mono);
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .header-nav {
      margin-left: auto;
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .nav-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 14px;
      background: var(--bg-card);
      border: 1px solid var(--border-bright);
      border-radius: var(--radius);
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      transition: all 0.15s;
    }

    .nav-btn:hover {
      border-color: var(--accent-green);
      color: var(--accent-green);
      background: rgba(94,196,127,0.08);
      text-decoration: none;
    }

    .nav-btn svg { width: 13px; height: 13px; }

    /* ── Page wrapper ───────────────────────────────────────────────────────── */
    .page-wrap {
      max-width: 1100px;
      margin: 0 auto;
      padding: 36px 24px 60px;
    }

    /* ── Breadcrumb ─────────────────────────────────────────────────────────── */
    .breadcrumb {
      font-size: 11px;
      font-family: var(--font-mono);
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      margin-bottom: 28px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .breadcrumb a { color: var(--text-muted); }
    .breadcrumb a:hover { color: var(--accent-green); text-decoration: none; }
    .breadcrumb-sep { opacity: 0.4; }

    /* ── ══════════════════════════════════════════════════════════════════════
       DIRECTORY VIEW
       ══════════════════════════════════════════════════════════════════════ */
    .dir-heading {
      margin-bottom: 8px;
    }

    .dir-heading h2 {
      font-size: 26px;
      font-weight: 700;
      color: var(--text-primary);
      margin-bottom: 6px;
    }

    .dir-heading p {
      font-size: 13px;
      color: var(--text-secondary);
      margin-bottom: 28px;
    }

    /* Region group label */
    .region-group {
      margin-bottom: 32px;
    }

    .region-label {
      font-family: var(--font-mono);
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      color: var(--text-muted);
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 14px;
    }

    .station-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
      gap: 12px;
    }

    .station-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      transition: border-color 0.2s, transform 0.15s;
      display: flex;
      flex-direction: column;
      text-decoration: none;
      color: inherit;
    }

    .station-card:hover {
      border-color: var(--border-bright);
      transform: translateY(-2px);
      text-decoration: none;
    }

    .card-thumb {
      width: 100%;
      object-position: center top;
      height: 200px;
      object-fit: cover;
      display: block;
      background: var(--bg-panel);
    }

    .card-thumb-placeholder {
      width: 100%;
      height: 200px;
      background: var(--bg-panel);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--text-muted);
      font-size: 10px;
      font-family: var(--font-mono);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      gap: 6px;
    }

    .card-thumb-placeholder svg { width: 16px; height: 16px; opacity: 0.4; }

    .card-body {
      padding: 13px 15px 15px;
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .card-name {
      font-size: 14px;
      font-weight: 600;
      color: var(--text-primary);
      line-height: 1.3;
    }

    .card-region {
      font-size: 11px;
      font-family: var(--font-mono);
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.06em;
    }

    .card-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-top: 2px;
    }

    .card-pill {
      font-size: 10px;
      font-family: var(--font-mono);
      padding: 2px 7px;
      border-radius: 3px;
      background: var(--bg-panel);
      border: 1px solid var(--border);
      color: var(--text-secondary);
    }

    /* ── ══════════════════════════════════════════════════════════════════════
       STATION DETAIL VIEW
       ══════════════════════════════════════════════════════════════════════ */
    .station-detail {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 28px;
      align-items: start;
    }

    @media (max-width: 720px) {
      .station-detail { grid-template-columns: 1fr; }
    }

    /* Left column — photo + map link */
    .detail-photo-col {}

    .detail-photo {
      width: 100%;
      aspect-ratio: 3 / 4;
      object-fit: cover;
      border-radius: var(--radius);
      display: block;
      border: 1px solid var(--border);
    }

    .detail-photo-placeholder {
      width: 100%;
      aspect-ratio: 3 / 4;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      color: var(--text-muted);
      font-family: var(--font-mono);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .detail-photo-placeholder svg { width: 28px; height: 28px; opacity: 0.3; }

    .map-link-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 12px;
      padding: 10px 16px;
      background: var(--bg-card);
      border: 1px solid var(--border-bright);
      border-radius: var(--radius);
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      transition: all 0.15s;
    }

    .map-link-btn:hover {
      border-color: var(--accent-blue);
      color: var(--accent-blue);
      background: rgba(85,175,212,0.08);
      text-decoration: none;
    }

    .map-link-btn svg { width: 14px; height: 14px; }

    /* Right column — info */
    .detail-info-col {}

    .detail-station-name {
      font-size: 28px;
      font-weight: 700;
      color: var(--text-primary);
      line-height: 1.2;
      margin-bottom: 4px;
    }

    .detail-region {
      font-family: var(--font-mono);
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--text-muted);
      margin-bottom: 20px;
    }

    /* Info table */
    .info-table {
      width: 100%;
      border-collapse: collapse;
      margin-bottom: 24px;
    }

    .info-table tr {
      border-bottom: 1px solid var(--border);
    }

    .info-table tr:last-child { border-bottom: none; }

    .info-table td {
      padding: 10px 0;
      vertical-align: top;
      line-height: 1.45;
    }

    .info-table td:first-child {
      font-family: var(--font-mono);
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.09em;
      color: var(--text-muted);
      width: 42%;
      padding-right: 12px;
      padding-top: 12px;
    }

    .info-table td:last-child {
      font-size: 13px;
      font-weight: 500;
      color: var(--text-primary);
    }

    /* Susceptibility badges */
    .susc-badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      font-family: var(--font-mono);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    .susc-very-low  { background: rgba(93,186,125,0.15); color: #5dba7d; border: 1px solid rgba(93,186,125,0.25); }
    .susc-low       { background: rgba(93,186,125,0.10); color: #7dcf9a; border: 1px solid rgba(93,186,125,0.2); }
    .susc-moderate  { background: rgba(201,168,76,0.15);  color: #c9a84c; border: 1px solid rgba(201,168,76,0.25); }
    .susc-high      { background: rgba(224,132,74,0.15);  color: #e0844a; border: 1px solid rgba(224,132,74,0.25); }
    .susc-very-high { background: rgba(224,112,112,0.15); color: #e07070; border: 1px solid rgba(224,112,112,0.25); }
    .susc-unknown   { background: rgba(140,195,160,0.07); color: var(--text-muted); border: 1px solid var(--border); }

    /* Coords */
    .coords-mono {
      font-family: var(--font-mono);
      font-size: 11px;
      color: var(--text-secondary);
    }

    /* Sensor depth chips */
    .depth-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 5px;
    }

    .depth-chip {
      font-family: var(--font-mono);
      font-size: 10px;
      padding: 2px 8px;
      background: var(--bg-panel);
      border: 1px solid var(--border-bright);
      border-radius: 3px;
      color: var(--text-secondary);
    }

    /* Section heading */
    .section-heading {
      font-family: var(--font-mono);
      font-size: 10px;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      color: var(--text-muted);
      padding-bottom: 8px;
      border-bottom: 1px solid var(--border);
      margin-bottom: 14px;
    }

    /* Live data CTA */
    .live-cta {
      background: linear-gradient(135deg, rgba(13,31,60,0.8), rgba(26,36,32,0.8));
      border: 1px solid var(--border-bright);
      border-radius: var(--radius);
      padding: 18px 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      margin-top: 4px;
    }

    .live-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--accent-green);
      flex-shrink: 0;
      box-shadow: 0 0 0 3px rgba(94,196,127,0.2);
      animation: pulse 2s ease-in-out infinite;
    }

    @keyframes pulse {
      0%, 100% { box-shadow: 0 0 0 3px rgba(94,196,127,0.2); }
      50%       { box-shadow: 0 0 0 6px rgba(94,196,127,0.05); }
    }

    .live-cta-text p:first-child {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 2px;
    }

    .live-cta-text p:last-child {
      font-size: 11px;
      color: var(--text-muted);
    }

    .live-cta-btn {
      margin-left: auto;
      padding: 8px 16px;
      background: var(--accent-green);
      color: #0a1a10;
      border-radius: var(--radius);
      font-size: 12px;
      font-weight: 700;
      white-space: nowrap;
      transition: background 0.15s;
      flex-shrink: 0;
    }

    .live-cta-btn:hover {
      background: #6ed48f;
      text-decoration: none;
    }

    /* ── Back nav ───────────────────────────────────────────────────────────── */
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 12px;
      font-family: var(--font-mono);
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--text-muted);
      margin-bottom: 24px;
      transition: color 0.15s;
    }

    .back-link:hover { color: var(--accent-green); text-decoration: none; }
    .back-link svg { width: 12px; height: 12px; }

    /* ── Sort toggle ────────────────────────────────────────────────────────── */
    .sort-active {
      border-color: var(--accent-green);
      color: var(--accent-green);
      background: rgba(94,196,127,0.08);
    }

    /* ── Footer ─────────────────────────────────────────────────────────────── */
    #site-footer {
      border-top: 1px solid var(--border);
      padding: 20px 32px;
      font-size: 11px;
      font-family: var(--font-mono);
      color: var(--text-muted);
      text-align: center;
      text-transform: uppercase;
      letter-spacing: 0.07em;
    }

    /* ── Responsive ─────────────────────────────────────────────────────────── */
    @media (max-width: 600px) {
      #site-header { padding: 0 16px; }
      .page-wrap   { padding: 20px 16px 40px; }
      .detail-station-name { font-size: 22px; }
    }
  </style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header id="site-header">
  <a href="https://kgs.uky.edu" target="_blank" rel="noopener">
    <img src="https://kgs.uky.edu/kygeode/img/UK-KGSlogos/KGS-new/kgs-logo-final.png"
         alt="Kentucky Geological Survey" class="kgs-logo">
  </a>
  <div class="header-divider"></div>
  <div class="header-titles">
    <h1><?= htmlspecialchars(SITE_NAME) ?></h1>
    <p>Station Directory</p>
  </div>
  <nav class="header-nav">
    <a href="index.php" class="nav-btn">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8">
        <rect x="1" y="1" width="14" height="14" rx="2"/>
        <circle cx="5" cy="5" r="1.5" fill="currentColor" stroke="none"/>
        <circle cx="11" cy="5" r="1.5" fill="currentColor" stroke="none"/>
        <circle cx="5" cy="11" r="1.5" fill="currentColor" stroke="none"/>
        <circle cx="11" cy="11" r="1.5" fill="currentColor" stroke="none"/>
      </svg>
      Live Map
    </a>
  </nav>
</header>

<div class="page-wrap">

<?php if ($active): ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     STATION DETAIL
     ══════════════════════════════════════════════════════════════════════════ -->

  <a href="stations.php" class="back-link">
    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8">
      <path d="M8 1 L3 6 L8 11" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
    All Stations
  </a>

  <?php $si = $active['site_info'] ?? []; ?>

  <div class="station-detail">

    <!-- Left: photo + map link -->
    <div class="detail-photo-col">
      <?php if (!empty($si['image'])): ?>
        <img src="img/<?= htmlspecialchars($si['image']) ?>"
             alt="Photo of <?= htmlspecialchars($active['name']) ?> monitoring station"
             class="detail-photo"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
        <div class="detail-photo-placeholder" style="display:none">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
          No photo available
        </div>
      <?php else: ?>
        <div class="detail-photo-placeholder">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
            <circle cx="12" cy="13" r="4"/>
          </svg>
          No photo available
        </div>
      <?php endif; ?>

      <a href="index.php#<?= htmlspecialchars($active['id']) ?>" target="_blank" rel="noopener"
         class="map-link-btn">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.7">
          <path d="M10 1 L6 3 L1 1 v12 l5 2 4-2 5 2 V3 L15 1"/>
          <path d="M6 3 v12 M10 1 v12" stroke-opacity="0.6"/>
        </svg>
        View on Live Map
      </a>
    </div>

    <!-- Right: info -->
    <div class="detail-info-col">
      <div class="detail-station-name"><?= htmlspecialchars($active['name']) ?></div>
      <div class="detail-region"><?= htmlspecialchars($active['region']) ?></div>

      <div class="section-heading">Site Information</div>

      <table class="info-table">
        <?php if (!empty($si['geologic_unit'])): ?>
        <tr>
          <td>Geologic Unit</td>
          <td><?= htmlspecialchars($si['geologic_unit']) ?></td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['soil_unit'])): ?>
        <tr>
          <td>Soil Unit</td>
          <td><?= htmlspecialchars($si['soil_unit']) ?></td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['elevation_m'])): ?>
        <tr>
          <td>Elevation</td>
          <td><?= htmlspecialchars($si['elevation_m']) ?> m</td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['slope_deg'])): ?>
        <tr>
          <td>Slope</td>
          <td><?= htmlspecialchars($si['slope_deg']) ?>°</td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['susceptibility'])): ?>
        <tr>
          <td>Landslide Susceptibility</td>
          <td><span class="susc-badge <?= susc_class($si['susceptibility']) ?>">
            <?= htmlspecialchars($si['susceptibility']) ?>
          </span></td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($active['ports'])): ?>
        <tr>
          <td>Sensor Depths</td>
          <td>
            <div class="depth-chips">
              <?php
              $seen = [];
              foreach ($active['ports'] as $p) {
                  if (!$p['depth_cm']) continue;
                  $key = $p['depth_cm'] . $p['type'];
                  if (isset($seen[$key])) continue;
                  $seen[$key] = true;
                  $typeLabel = match($p['type']) {
                      'soil_moisture'    => 'VWC',
                      'matric_potential' => 'Matric',
                      'soil_temp'        => 'Temp',
                      default            => ucfirst($p['type']),
                  };
                  echo '<span class="depth-chip">' . htmlspecialchars($p['depth_cm']) . ' cm — ' . $typeLabel . '</span>';
              }
              ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['date_installed'])): ?>
        <tr>
          <td>Date Installed</td>
          <td><?= htmlspecialchars($si['date_installed']) ?></td>
        </tr>
        <?php endif; ?>

        <?php if (!empty($si['collaborator'])): ?>
        <tr>
          <td>Collaborator</td>
          <td><?= htmlspecialchars($si['collaborator']) ?></td>
        </tr>
        <?php endif; ?>

        <tr>
          <td>Coordinates</td>
          <td><span class="coords-mono"><?= number_format($active['lat'], 5) ?>, <?= number_format($active['lng'], 5) ?></span></td>
        </tr>

        <tr>
          <td>Station ID</td>
          <td><span class="coords-mono"><?= htmlspecialchars($active['id']) ?></span></td>
        </tr>
      </table>

      <div class="live-cta">
        <div class="live-dot"></div>
        <div class="live-cta-text">
          <p>Live Sensor Data</p>
          <p>Soil moisture updated ~every 45 minutes</p>
        </div>
        <a href="index.php#<?= htmlspecialchars($active['id']) ?>" class="live-cta-btn" target="_blank" rel="noopener">Open Map →</a>
      </div>

    </div>
  </div>

<?php else: ?>
<!-- ══════════════════════════════════════════════════════════════════════════
     DIRECTORY VIEW
     ══════════════════════════════════════════════════════════════════════════ -->

  <div class="dir-heading">
    <h2>Monitoring Station Directory</h2>
    <p><?= count(STATIONS) ?> stations across Eastern Kentucky — click any station to view site details.</p>

    <div style="display:flex;gap:6px;margin-bottom:28px;margin-top:16px;">
      <a href="stations.php?sort=region"
         class="nav-btn <?= $sort === 'region' ? 'sort-active' : '' ?>">
        By Region
      </a>
      <a href="stations.php?sort=name"
         class="nav-btn <?= $sort === 'name' ? 'sort-active' : '' ?>">
        By Name
      </a>
    </div>
  </div>

  <?php
  // ── Sort control ───────────────────────────────────────────────────────────
  $sort = $_GET['sort'] ?? 'region';
  $sort = in_array($sort, ['region', 'name']) ? $sort : 'region';

  $by_region = [];
  foreach (STATIONS as $s) {
      $by_region[$s['region']][] = $s;
  }
  ksort($by_region);

  if ($sort === 'name') {
      $all = array_merge(...array_values($by_region));
      usort($all, fn($a, $b) => strcmp($a['name'], $b['name']));
      $by_region = ['All Stations' => $all];
  }
  ?>

  <?php foreach ($by_region as $region => $group): ?>
  <div class="region-group">
    <div class="region-label"><?= htmlspecialchars($region) ?></div>
    <div class="station-grid">
      <?php foreach ($group as $s):
        $si = $s['site_info'] ?? [];
      ?>
      <a href="stations.php?id=<?= urlencode($s['id']) ?>" class="station-card">

        <?php if (!empty($si['image'])): ?>
          <img src="img/<?= htmlspecialchars($si['image']) ?>"
               alt="<?= htmlspecialchars($s['name']) ?>"
               class="card-thumb"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
          <div class="card-thumb-placeholder" style="display:none">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            No photo
          </div>
        <?php else: ?>
          <div class="card-thumb-placeholder">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
              <circle cx="12" cy="13" r="4"/>
            </svg>
            No photo
          </div>
        <?php endif; ?>

        <div class="card-body">
          <div class="card-name"><?= htmlspecialchars($s['name']) ?></div>
          <div class="card-region"><?= htmlspecialchars($s['region']) ?></div>
          <div class="card-meta">
            <?php if (!empty($si['susceptibility'])): ?>
              <span class="card-pill susc-badge <?= susc_class($si['susceptibility']) ?>">
                <?= htmlspecialchars($si['susceptibility']) ?>
              </span>
            <?php endif; ?>
            <?php if (!empty($si['elevation_m'])): ?>
              <span class="card-pill"><?= htmlspecialchars($si['elevation_m']) ?> m</span>
            <?php endif; ?>
            <?php if (!empty($si['slope_deg'])): ?>
              <span class="card-pill"><?= htmlspecialchars($si['slope_deg']) ?>° slope</span>
            <?php endif; ?>
          </div>
        </div>

      </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

<?php endif; ?>

</div><!-- /page-wrap -->

<footer id="site-footer">
  <?= htmlspecialchars(SITE_ORG) ?> &nbsp;·&nbsp; <?= htmlspecialchars(SITE_NAME) ?>
  &nbsp;·&nbsp; <a href="index.php">Live Map</a>
</footer>

</body>
</html>