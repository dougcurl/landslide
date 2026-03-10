<?php
/**
 * api/setup_helper.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE-TIME SETUP UTILITY
 * 
 * Paste your Zentra device serial numbers, click "Fetch from Zentra", and this
 * tool auto-discovers device names, GPS coordinates, and sensor port configs
 * for each station. It then generates a ready-to-paste PHP STATIONS array for
 * your config.php.
 *
 * HOW TO USE:
 *   1. Put your Zentra API token in config.php first
 *   2. Browse to: http://your-server/landslide/api/setup_helper.php
 *   3. Paste serial numbers (one per line, or comma-separated)
 *   4. Click "Fetch from Zentra"
 *   5. Copy the generated PHP array into config.php
 *   6. Delete or move this file when done (it exposes API details)
 *
 * SECURITY: This page is protected by a simple access key. Set it below.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../config.php';

// ─── Simple access protection ─────────────────────────────────────────────────
// Change this to any password you like. Browse to setup_helper.php?key=yourkey
define('SETUP_KEY', 'kgs-setup-2024');

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    http_response_code(403);
    die('Access denied. Add ?key=' . SETUP_KEY . ' to the URL.');
}

// ─── Sensor type detection from Zentra sensor names ──────────────────────────
// Maps known Zentra sensor model names to our internal type labels.
// Zentra returns sensor_name in readings metadata — e.g. "TEROS 12", "MPS-6", "ATMOS 41W"
function detect_sensor_type(string $sensor_name, string $measurement_name = ''): string {
    $sn = strtolower($sensor_name);
    $mn = strtolower($measurement_name);

    // Soil moisture / volumetric water content sensors
    if (preg_match('/teros\s*(1[012]|21|31|54)|5te|5tm|ec-5|gro point/', $sn)) return 'soil_moisture';
    if (str_contains($mn, 'water content') || str_contains($mn, 'volumetric')) return 'soil_moisture';

    // Matric potential / water potential sensors
    if (preg_match('/mps[-\s]?(2|6)|teros\s*21|watermark/', $sn)) return 'matric_potential';
    if (str_contains($mn, 'matric') || str_contains($mn, 'water potential')) return 'matric_potential';

    // Soil temperature (standalone — not the temp channel of a combo sensor)
    if (preg_match('/tsensor|soil.?temp/', $sn)) return 'soil_temp';
    if (str_contains($mn, 'soil temp')) return 'soil_temp';

    // Atmospheric / weather sensors — these are station-level, not depth sensors
    if (preg_match('/atmos|phytos|wxt|davis|ott/', $sn)) return 'atmospheric';
    if (str_contains($mn, 'precipitation') || str_contains($mn, 'pressure') ||
        str_contains($mn, 'wind') || str_contains($mn, 'solar') ||
        str_contains($mn, 'humidity') || str_contains($mn, 'lightning')) return 'atmospheric';

    return 'unknown';
}

// Combo sensors (like TEROS 12) report multiple measurements per port.
// We pick the "primary" measurement for port classification:
// soil_moisture > matric_potential > soil_temp > atmospheric
function primary_type(array $types): string {
    $priority = ['soil_moisture', 'matric_potential', 'soil_temp', 'atmospheric', 'unknown'];
    foreach ($priority as $p) {
        if (in_array($p, $types)) return $p;
    }
    return 'unknown';
}

// ─── Zentra API call ──────────────────────────────────────────────────────────
function zentra_get(string $endpoint, array $params = []): array {
    $url = ZENTRA_API_BASE . ltrim($endpoint, '/');
    if (!empty($params)) $url .= '?' . http_build_query($params);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Token ' . ZENTRA_API_TOKEN . "\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $raw = @file_get_contents($url, false, $ctx);

    // Capture HTTP status code
    $status = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\d\.\d (\d{3})/', $http_response_header[0], $m);
        $status = (int)($m[1] ?? 0);
    }

    if ($raw === false || $status >= 400) {
        return ['_error' => "HTTP $status — " . ($raw ?: 'no response'), '_status' => $status];
    }

    $decoded = json_decode($raw, true);
    return $decoded ?? ['_error' => 'JSON parse failed', '_raw' => substr($raw, 0, 300)];
}

// ─── Fetch device info for one serial number ──────────────────────────────────
// Strategy: pull the most recent 1 reading with device_depth=true and location=true
// This gives us: device name, lat/lng, and all port sensor metadata
function fetch_device_info(string $sn): array {
    $result = [
        'sn'      => $sn,
        'name'    => $sn,
        'lat'     => null,
        'lng'     => null,
        'ports'   => [],
        'errors'  => [],
        'raw_keys'=> [],
    ];

    // Pull just 1 reading (most recent) — we only need the metadata structure
    $data = zentra_get('get_readings/', [
        'device_sn'    => $sn,
        'output_format'=> 'json',
        'per_page'     => 1,
        'page_num'     => 1,
        'sort_by'      => 'desc',
        'device_depth' => 'true',
        'location'     => 'true',
    ]);

    if (isset($data['_error'])) {
        $result['errors'][] = $data['_error'];
        return $result;
    }

    // ── Extract device name ──
    // Zentra v4: data -> {device_sn} -> {measurement_name} -> metadata -> device_name
    $device_data = $data['data'][$sn] ?? [];
    $result['raw_keys'] = array_keys($device_data);

    if (empty($device_data)) {
        $result['errors'][] = "No data returned for $sn — check serial number or subscription status";
        return $result;
    }

    // Device name sits in any port's metadata
    foreach ($device_data as $measurement_name => $port_info) {
        if (isset($port_info['metadata']['device_name'])) {
            $result['name'] = $port_info['metadata']['device_name'];
            break;
        }
    }

    // ── Extract location ──
    // location_history is at the top level alongside 'data'
    $loc_history = $data['location_history'][$sn] ?? [];
    if (!empty($loc_history)) {
        // Most recent location is first (sort_by=desc)
        $latest_loc = $loc_history[0];
        $result['lat'] = round((float)($latest_loc['Latitude']  ?? 0), 6);
        $result['lng'] = round((float)($latest_loc['Longitude'] ?? 0), 6);
    }

    // ── Extract port/sensor configs ──
    // Group measurements by port_number, then determine the primary sensor type per port
    $ports_raw = []; // port_number => ['sensor_name'=>, 'depth_cm'=>, 'measurements'=>[]]

    foreach ($device_data as $measurement_name => $port_info) {
        $meta       = $port_info['metadata'] ?? [];
        $port_num   = (int)($meta['port_number'] ?? 0);
        $sensor_sn  = $meta['sensor_sn']   ?? '';
        $sensor_name= $meta['sensor_name'] ?? '';
        $depth_mm   = $meta['depth']       ?? null;   // Zentra returns depth in mm
        $depth_cm   = $depth_mm !== null ? (int)round($depth_mm / 10) : null;

        if ($port_num === 0) continue;  // skip invalid ports

        if (!isset($ports_raw[$port_num])) {
            $ports_raw[$port_num] = [
                'port'       => $port_num,
                'sensor_sn'  => $sensor_sn,
                'sensor_name'=> $sensor_name,
                'depth_cm'   => $depth_cm,
                'measurements'=> [],
                'types'      => [],
            ];
        }

        // Some sensors return depth per measurement rather than per port
        if ($depth_cm !== null && $ports_raw[$port_num]['depth_cm'] === null) {
            $ports_raw[$port_num]['depth_cm'] = $depth_cm;
        }

        $type = detect_sensor_type($sensor_name, $measurement_name);
        $ports_raw[$port_num]['measurements'][] = $measurement_name;
        $ports_raw[$port_num]['types'][]         = $type;
    }

    // Build the final port array — one entry per port, typed by primary measurement
    ksort($ports_raw);
    foreach ($ports_raw as $port_num => $port) {
        $primary = primary_type($port['types']);

        // Skip pure atmospheric sensors from the ports list —
        // precip/atm pressure are handled automatically during data fetching
        if ($primary === 'atmospheric') continue;
        if ($primary === 'unknown') continue;

        $depth_cm = $port['depth_cm'];
        $label    = $depth_cm !== null ? $depth_cm . ' cm' : $port['sensor_name'];

        $result['ports'][] = [
            'port'     => $port_num,
            'label'    => $label,
            'depth_cm' => $depth_cm,
            'type'     => $primary,
            'sensor'   => $port['sensor_name'],
            'measurements' => $port['measurements'],
        ];
    }

    return $result;
}

// ─── Generate PHP config array output ────────────────────────────────────────
function generate_php_array(array $stations): string {
    $lines = [];
    $lines[] = "define('STATIONS', [";

    foreach ($stations as $s) {
        if (!empty($s['errors'])) continue;  // skip failed stations

        $id     = addslashes($s['sn']);
        $name   = addslashes($s['name']);
        $lat    = $s['lat'] ?? 0;
        $lng    = $s['lng'] ?? 0;
        $region = '';  // user fills in

        $lines[] = "    [";
        $lines[] = "        'id'     => '$id',";
        $lines[] = "        'name'   => '$name',";
        $lines[] = "        'lat'    => $lat,";
        $lines[] = "        'lng'    => $lng,";
        $lines[] = "        'region' => '$region',  // ← fill in region name";
        $lines[] = "        'ports'  => [";

        foreach ($s['ports'] as $p) {
            $port     = (int)$p['port'];
            $label    = addslashes($p['label']);
            $depth    = $p['depth_cm'] !== null ? (int)$p['depth_cm'] : 'null';
            $type     = addslashes($p['type']);
            $sensor   = addslashes($p['sensor']);
            $lines[]  = "            ['port' => $port, 'label' => '$label', 'depth_cm' => $depth, 'type' => '$type'],  // $sensor";
        }

        $lines[] = "        ],";
        $lines[] = "    ],";
    }

    $lines[] = "]);";
    return implode("\n", $lines);
}

// ─── Handle AJAX fetch request ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['serials'])) {
    header('Content-Type: application/json');

    $raw_input = trim($_POST['serials']);
    // Accept newline or comma separated, strip whitespace, filter empties
    $serials = array_filter(
        array_map('trim', preg_split('/[\n,]+/', $raw_input)),
        fn($s) => $s !== ''
    );

    if (empty($serials)) {
        echo json_encode(['error' => 'No serial numbers provided']);
        exit;
    }

    if (count($serials) > 60) {
        echo json_encode(['error' => 'Maximum 60 serial numbers at once (API rate limit)']);
        exit;
    }

    $results = [];
    $i = 0;
    foreach ($serials as $sn) {
        // Normalize serial: lowercase, trim
        $sn = strtolower(trim($sn));
        $results[] = fetch_device_info($sn);
        $i++;
        // Respect Zentra's 1 call/device/min rate limit — sleep 1s between calls
        if ($i < count($serials)) sleep(1);
    }

    $php_output = generate_php_array($results);

    echo json_encode([
        'stations'   => $results,
        'php_output' => $php_output,
        'count'      => count($results),
        'errors'     => array_filter(array_column($results, 'errors')),
    ]);
    exit;
}

// ─── Render the HTML page ─────────────────────────────────────────────────────
$token_set = ZENTRA_API_TOKEN !== 'YOUR_ZENTRA_API_TOKEN_HERE';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KGS Station Setup Helper</title>
<style>
  @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');

  :root {
    --bg:       #0f1614;
    --panel:    #151e1a;
    --card:     #1c2822;
    --border:   rgba(120,180,140,0.2);
    --green:    #5dba7d;
    --amber:    #c9a84c;
    --red:      #d4544a;
    --text:     #e8f0eb;
    --muted:    #9ab5a3;
    --dim:      #5a7a65;
    --mono:     'DM Mono', monospace;
    --sans:     'DM Sans', sans-serif;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: var(--sans);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    padding: 32px 24px;
    font-size: 14px;
    line-height: 1.6;
  }

  .container { max-width: 900px; margin: 0 auto; }

  h1 {
    font-size: 22px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
  }

  .subtitle {
    color: var(--muted);
    font-size: 13px;
    margin-bottom: 28px;
    font-family: var(--mono);
  }

  .warning-box, .info-box {
    border-radius: 6px;
    padding: 12px 16px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.6;
  }

  .warning-box {
    background: rgba(212,84,74,0.1);
    border: 1px solid rgba(212,84,74,0.35);
    color: #e08080;
  }

  .info-box {
    background: rgba(93,186,125,0.08);
    border: 1px solid rgba(93,186,125,0.25);
    color: var(--muted);
  }

  .info-box strong { color: var(--green); }

  .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 24px;
    margin-bottom: 20px;
  }

  .card h2 {
    font-size: 14px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--dim);
    font-family: var(--mono);
    margin-bottom: 14px;
  }

  .steps {
    list-style: none;
    counter-reset: step;
    margin-bottom: 0;
  }

  .steps li {
    counter-increment: step;
    padding: 6px 0 6px 36px;
    position: relative;
    color: var(--muted);
    font-size: 13px;
  }

  .steps li::before {
    content: counter(step);
    position: absolute;
    left: 0;
    top: 6px;
    width: 22px;
    height: 22px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-family: var(--mono);
    color: var(--green);
    line-height: 22px;
    text-align: center;
  }

  .steps li strong { color: var(--text); }

  label {
    display: block;
    font-size: 12px;
    font-family: var(--mono);
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--dim);
    margin-bottom: 8px;
  }

  textarea {
    width: 100%;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--text);
    font-family: var(--mono);
    font-size: 13px;
    padding: 12px;
    resize: vertical;
    min-height: 140px;
    outline: none;
    transition: border-color 0.2s;
    line-height: 1.7;
  }

  textarea:focus { border-color: rgba(93,186,125,0.5); }
  textarea::placeholder { color: var(--dim); }

  .hint {
    font-size: 11px;
    color: var(--dim);
    font-family: var(--mono);
    margin-top: 6px;
  }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 10px 22px;
    border-radius: 6px;
    border: none;
    font-family: var(--sans);
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    margin-top: 14px;
  }

  .btn-primary {
    background: var(--green);
    color: #0f1614;
  }

  .btn-primary:hover { background: #4da96d; }
  .btn-primary:disabled { background: var(--dim); cursor: not-allowed; opacity: 0.6; }

  .btn-copy {
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--muted);
    font-size: 12px;
    padding: 6px 14px;
    margin-top: 0;
  }

  .btn-copy:hover { border-color: var(--green); color: var(--green); }

  #progress {
    display: none;
    margin-top: 16px;
  }

  .progress-label {
    font-size: 12px;
    font-family: var(--mono);
    color: var(--muted);
    margin-bottom: 8px;
  }

  .progress-bar-wrap {
    height: 4px;
    background: var(--card);
    border-radius: 2px;
    overflow: hidden;
  }

  .progress-bar-fill {
    height: 100%;
    background: var(--green);
    border-radius: 2px;
    width: 0%;
    transition: width 0.4s;
    animation: pulse-bar 1.5s ease-in-out infinite;
  }

  @keyframes pulse-bar {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
  }

  /* Results */
  #results { display: none; }

  .station-result {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 14px 16px;
    margin-bottom: 10px;
  }

  .station-result.has-error {
    border-color: rgba(212,84,74,0.4);
    background: rgba(212,84,74,0.05);
  }

  .station-result.has-warning {
    border-color: rgba(201,168,76,0.4);
  }

  .station-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 8px;
  }

  .station-sn {
    font-family: var(--mono);
    font-size: 12px;
    color: var(--dim);
    background: var(--panel);
    padding: 2px 8px;
    border-radius: 4px;
  }

  .station-name {
    font-weight: 600;
    font-size: 14px;
  }

  .badge {
    font-size: 10px;
    font-family: var(--mono);
    padding: 2px 7px;
    border-radius: 10px;
    margin-left: auto;
  }

  .badge-ok      { background: rgba(93,186,125,0.15);  color: var(--green); }
  .badge-warn    { background: rgba(201,168,76,0.15);  color: var(--amber); }
  .badge-error   { background: rgba(212,84,74,0.15);   color: var(--red);   }

  .station-meta {
    font-size: 12px;
    font-family: var(--mono);
    color: var(--muted);
    margin-bottom: 8px;
  }

  .port-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 6px;
  }

  .port-chip {
    font-size: 11px;
    font-family: var(--mono);
    padding: 3px 9px;
    border-radius: 4px;
    border: 1px solid var(--border);
    color: var(--muted);
  }

  .port-chip.soil_moisture    { border-color: rgba(93,186,125,0.4);  color: #5dba7d; }
  .port-chip.matric_potential { border-color: rgba(212,121,58,0.4);  color: #d4793a; }
  .port-chip.soil_temp        { border-color: rgba(74,158,187,0.4);  color: #4a9ebb; }

  .error-msg {
    font-size: 12px;
    color: #e08080;
    font-family: var(--mono);
    margin-top: 6px;
  }

  .php-output-section {
    margin-top: 6px;
  }

  .php-output-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  .php-output-header h2 {
    margin-bottom: 0;
  }

  pre#php-output {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 16px;
    font-family: var(--mono);
    font-size: 12px;
    color: var(--muted);
    overflow-x: auto;
    white-space: pre;
    line-height: 1.7;
    max-height: 500px;
    overflow-y: auto;
  }

  .copy-feedback {
    font-size: 11px;
    font-family: var(--mono);
    color: var(--green);
    margin-left: 10px;
    opacity: 0;
    transition: opacity 0.3s;
  }

  .copy-feedback.show { opacity: 1; }

  .summary-bar {
    display: flex;
    gap: 20px;
    padding: 12px 0;
    border-bottom: 1px solid var(--border);
    margin-bottom: 16px;
  }

  .summary-stat { font-size: 12px; font-family: var(--mono); }
  .summary-stat span { color: var(--green); font-weight: 500; }
</style>
</head>
<body>
<div class="container">

  <h1>🌱 KGS Station Setup Helper</h1>
  <p class="subtitle">One-time utility · Auto-discovers station configs from Zentra Cloud API</p>

  <?php if (!$token_set): ?>
  <div class="warning-box">
    ⚠ <strong>API token not set.</strong> Edit <code>config.php</code> and replace
    <code>YOUR_ZENTRA_API_TOKEN_HERE</code> with your actual Zentra API token before using this tool.
  </div>
  <?php endif; ?>

  <div class="info-box">
    <strong>How to find your serial numbers in Zentra Cloud:</strong><br>
    Log in → click the list/inventory icon in the top menu → all your devices are listed with their
    serial numbers (format: <code>z6-XXXXX</code> or similar). Select all, copy, and paste below.
    <br><br>
    <strong>Note:</strong> Each device takes ~1 second due to Zentra's API rate limit.
    25 stations ≈ 30 seconds total.
  </div>

  <!-- ── Input Card ──────────────────────────────────────────────────────────── -->
  <div class="card">
    <h2>Step 1 — Enter Serial Numbers</h2>
    <label for="serials-input">Device Serial Numbers</label>
    <textarea id="serials-input"
      placeholder="z6-12345&#10;z6-12346&#10;z6-12347&#10;&#10;(one per line, or comma-separated)"
      <?= !$token_set ? 'disabled' : '' ?>
    ></textarea>
    <p class="hint">Format: z6-XXXXX · One per line or comma-separated · Max 60 at once</p>

    <button class="btn btn-primary" id="fetch-btn" <?= !$token_set ? 'disabled' : '' ?>>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="23 4 23 10 17 10"></polyline>
        <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
      </svg>
      Fetch from Zentra Cloud
    </button>

    <div id="progress">
      <p class="progress-label" id="progress-label">Fetching station data…</p>
      <div class="progress-bar-wrap">
        <div class="progress-bar-fill" id="progress-bar"></div>
      </div>
    </div>
  </div>

  <!-- ── Results ─────────────────────────────────────────────────────────────── -->
  <div id="results">

    <div class="card">
      <h2>Step 2 — Review Discovered Stations</h2>
      <div class="summary-bar" id="summary-bar"></div>
      <div id="stations-list"></div>
    </div>

    <div class="card php-output-section">
      <div class="php-output-header">
        <h2>Step 3 — Copy into config.php</h2>
        <div>
          <button class="btn btn-copy" id="copy-btn">Copy to Clipboard</button>
          <span class="copy-feedback" id="copy-feedback">Copied!</span>
        </div>
      </div>
      <p style="font-size:12px;color:var(--muted);margin-bottom:12px;font-family:var(--mono);">
        Replace the existing <code>define('STATIONS', [...])</code> block in <code>config.php</code> with this:
      </p>
      <pre id="php-output"></pre>
      <p style="font-size:11px;color:var(--dim);font-family:var(--mono);margin-top:10px;">
        ← Fill in the <code>'region'</code> values and verify any ports marked with warnings above.
      </p>
    </div>

  </div><!-- /results -->

</div><!-- /container -->

<script>
const fetchBtn   = document.getElementById('fetch-btn');
const progress   = document.getElementById('progress');
const progressBar= document.getElementById('progress-bar');
const progressLbl= document.getElementById('progress-label');
const results    = document.getElementById('results');
const stationsList= document.getElementById('stations-list');
const summaryBar = document.getElementById('summary-bar');
const phpOutput  = document.getElementById('php-output');
const copyBtn    = document.getElementById('copy-btn');
const copyFeedback= document.getElementById('copy-feedback');

fetchBtn.addEventListener('click', async () => {
  const serials = document.getElementById('serials-input').value.trim();
  if (!serials) { alert('Please enter at least one serial number.'); return; }

  // Count serials for progress
  const count = serials.split(/[\n,]+/).filter(s => s.trim()).length;

  // Show progress
  fetchBtn.disabled = true;
  progress.style.display = 'block';
  results.style.display  = 'none';
  progressLbl.textContent = `Fetching ${count} station${count !== 1 ? 's' : ''} from Zentra Cloud… (~${count} seconds)`;
  progressBar.style.width = '5%';

  // Animate progress bar (fake but reassuring — real duration is ~count seconds)
  let pct = 5;
  const progressTimer = setInterval(() => {
    pct = Math.min(pct + (90 / count) * 0.3, 90);
    progressBar.style.width = pct + '%';
  }, 1000);

  try {
    const formData = new FormData();
    formData.append('serials', serials);

    const res  = await fetch(window.location.href, { method: 'POST', body: formData });
    const json = await res.json();

    clearInterval(progressTimer);
    progressBar.style.width = '100%';
    progressBar.style.animation = 'none';
    progressBar.style.background = '#5dba7d';

    if (json.error) {
      alert('Error: ' + json.error);
      return;
    }

    renderResults(json);

  } catch (err) {
    clearInterval(progressTimer);
    alert('Request failed: ' + err.message);
  } finally {
    fetchBtn.disabled = false;
    setTimeout(() => { progress.style.display = 'none'; }, 800);
  }
});

function renderResults(json) {
  const stations = json.stations || [];
  const ok    = stations.filter(s => !s.errors?.length).length;
  const errs  = stations.filter(s =>  s.errors?.length).length;
  const noPorts = stations.filter(s => !s.errors?.length && s.ports?.length === 0).length;

  summaryBar.innerHTML = `
    <div class="summary-stat">Total: <span>${stations.length}</span></div>
    <div class="summary-stat">OK: <span>${ok}</span></div>
    ${errs    ? `<div class="summary-stat" style="color:var(--red)">Errors: <span>${errs}</span></div>` : ''}
    ${noPorts ? `<div class="summary-stat" style="color:var(--amber)">No soil ports detected: <span>${noPorts}</span></div>` : ''}
  `;

  stationsList.innerHTML = '';
  stations.forEach(s => {
    const hasError   = s.errors && s.errors.length > 0;
    const hasWarning = !hasError && (!s.lat || s.ports.length === 0);
    const badgeClass = hasError ? 'badge-error' : hasWarning ? 'badge-warn' : 'badge-ok';
    const badgeText  = hasError ? 'ERROR' : hasWarning ? 'NEEDS REVIEW' : 'OK';

    const portChips = (s.ports || []).map(p =>
      `<div class="port-chip ${p.type}" title="${p.sensor}">
        Port ${p.port} · ${p.label} · ${typeName(p.type)}
      </div>`
    ).join('');

    const errMsgs = (s.errors || []).map(e =>
      `<div class="error-msg">✗ ${e}</div>`
    ).join('');

    const div = document.createElement('div');
    div.className = `station-result${hasError ? ' has-error' : hasWarning ? ' has-warning' : ''}`;
    div.innerHTML = `
      <div class="station-header">
        <span class="station-sn">${s.sn}</span>
        <span class="station-name">${s.name !== s.sn ? s.name : '(name not retrieved)'}</span>
        <span class="badge ${badgeClass}">${badgeText}</span>
      </div>
      ${s.lat ? `<div class="station-meta">📍 ${s.lat}, ${s.lng}</div>` : '<div class="station-meta" style="color:var(--amber)">⚠ No GPS coordinates returned — you\'ll need to enter lat/lng manually</div>'}
      ${s.ports.length > 0
        ? `<div class="port-list">${portChips}</div>`
        : hasError ? '' : '<div style="font-size:12px;color:var(--amber);margin-top:6px;font-family:var(--mono)">⚠ No soil sensor ports detected — verify port configuration in Zentra Cloud</div>'
      }
      ${errMsgs}
    `;
    stationsList.appendChild(div);
  });

  phpOutput.textContent = json.php_output;
  results.style.display = 'block';
  results.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function typeName(t) {
  return { soil_moisture: 'VWC', matric_potential: 'Matric', soil_temp: 'Temp' }[t] || t;
}

copyBtn.addEventListener('click', () => {
  navigator.clipboard.writeText(phpOutput.textContent).then(() => {
    copyFeedback.classList.add('show');
    setTimeout(() => copyFeedback.classList.remove('show'), 2000);
  });
});
</script>
</body>
</html>