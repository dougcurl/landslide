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
  <link rel="stylesheet" href="css/stations.css">
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header id="site-header">
  <div class="logo-stack">
    <a href="https://kgs.uky.edu" target="_blank" rel="noopener" class="kgs-logo-link" title="Kentucky Geological Survey">
      <img src="https://kgs.uky.edu/kygeode/img/UK-KGSlogos/KGS-new/kgs-logo-final.png"
          alt="Kentucky Geological Survey" class="kgs-logo">
    </a>
    <a href="https://kynsfepscor.uky.edu/climbs/" target="_blank" rel="noopener" class="climbs-logo-link" title="CLIMBS">
      <img src="img/CLIMBSLogo.png" alt="CLIMBS" class="climbs-logo">
    </a>
  </div>
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