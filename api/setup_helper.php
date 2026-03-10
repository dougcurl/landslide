<?php
/**
 * api/setup_helper.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE-TIME SETUP UTILITY — Zentra Cloud 2.0 / API v5
 *
 * Two modes:
 *   1. AUTO DISCOVER — fetches all devices in your organization automatically
 *      using the v5 GET /devices/ endpoint (new in v5!). Just click the button.
 *
 *   2. MANUAL — paste specific serial numbers if auto-discovery doesn't return
 *      all the stations you expect (e.g. devices in a different org).
 *
 * After discovery, generates a ready-to-paste PHP STATIONS array for config.php.
 *
 * ACCESS: Browse to setup_helper.php?key=kgs-setup-2024
 * SECURITY: Delete this file when done — it exposes your API token indirectly.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/zentra_v5.php';

define('SETUP_KEY', 'kgs-setup-2024');  // ← change if desired

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    http_response_code(403);
    die('Access denied. Add ?key=' . SETUP_KEY . ' to the URL.');
}

$token_set = ZENTRA_API_TOKEN !== 'YOUR_ZENTRA_V5_API_TOKEN_HERE';

// ─── Auto-discover all org devices via v5 GET /devices/ ──────────────────────

function auto_discover_devices(): array {
    $devices = zentra_v5_list_devices();
    if (isset($devices['_error'])) return $devices;
    return $devices;
}

// ─── Fetch port/sensor config for one device via a single measurement call ────

function fetch_device_port_config(string $device_id): array {
    // Pull just the last 2 hours — we only need sensor metadata, not history
    $end_dt   = date('c');
    $start_dt = date('c', time() - 7200);

    $data = zentra_v5_get("devices/{$device_id}/measurements/", [
        'start_datetime' => $start_dt,
        'end_datetime'   => $end_dt,
        'direction'      => 'descending',
        'units'          => 'metric',
    ]);

    if (isset($data['_error'])) {
        return ['_error' => $data['_error'], 'ports' => []];
    }

    // Collect unique port configs from the most recent data rows
    $ports_seen = [];  // "Port N" => [sensor_name, measurement_name, depth_mm, value_sample]
    $results = $data['results'] ?? $data['data'] ?? [];

    foreach (array_slice($results, 0, 10) as $row) {  // look at up to 10 rows
        foreach (($row['measurements'] ?? []) as $port_key => $meas) {
            if (isset($ports_seen[$port_key])) continue;
            $ports_seen[$port_key] = [
                'sensor_name'      => $meas['sensor_name']      ?? '',
                'measurement_name' => $meas['measurement_name'] ?? '',
                'depth_mm'         => $meas['depth']            ?? null,
                'unit'             => $meas['unit']             ?? '',
                'sample_value'     => $meas['value']            ?? null,
            ];
        }
        if (count($ports_seen) >= 6) break;  // found enough ports
    }

    // Build port config array
    $ports = [];
    foreach ($ports_seen as $port_key => $info) {
        preg_match('/(\d+)$/', $port_key, $pm);
        $port_num = isset($pm[1]) ? (int)$pm[1] : 0;
        if (!$port_num) continue;

        $type     = detect_sensor_type_v5($info['sensor_name'], $info['measurement_name']);
        if (in_array($type, ['atmospheric', 'unknown'])) continue;  // skip non-soil ports

        $depth_cm = $info['depth_mm'] !== null ? (int)round($info['depth_mm'] / 10) : null;
        $label    = $depth_cm !== null ? $depth_cm . ' cm' : $info['sensor_name'];

        $ports[] = [
            'port'     => $port_num,
            'label'    => $label,
            'depth_cm' => $depth_cm,
            'type'     => $type,
            'sensor'   => $info['sensor_name'],
        ];
    }
    usort($ports, fn($a, $b) => $a['port'] <=> $b['port']);

    // Also grab device lat/lng from device metadata
    $device_meta = $data['device'] ?? null;

    return [
        'ports'       => $ports,
        'device_meta' => $device_meta,
        '_raw_ports'  => $ports_seen,
    ];
}

// ─── Generate PHP STATIONS array ─────────────────────────────────────────────

function generate_stations_php(array $stations): string {
    $lines = ["define('STATIONS', ["];
    foreach ($stations as $s) {
        if (!empty($s['_error'])) continue;
        $id    = addslashes($s['device_id'] ?? $s['id'] ?? '');
        $name  = addslashes($s['name'] ?? $id);
        $lat   = is_numeric($s['lat'] ?? '') ? (float)$s['lat'] : 0.0;
        $lng   = is_numeric($s['lng'] ?? '') ? (float)$s['lng'] : 0.0;

        $lines[] = "    [";
        $lines[] = "        'id'     => '$id',";
        $lines[] = "        'name'   => '$name',";
        $lines[] = "        'lat'    => $lat,";
        $lines[] = "        'lng'    => $lng,";
        $lines[] = "        'region' => '',  // ← fill in region name";
        $lines[] = "        'ports'  => [";
        foreach ($s['ports'] as $p) {
            $pn    = (int)$p['port'];
            $lbl   = addslashes($p['label']);
            $dep   = $p['depth_cm'] !== null ? (int)$p['depth_cm'] : 'null';
            $type  = addslashes($p['type']);
            $sensor= addslashes($p['sensor'] ?? '');
            $lines[]= "            ['port' => $pn, 'label' => '$lbl', 'depth_cm' => $dep, 'type' => '$type'],  // $sensor";
        }
        $lines[] = "        ],";
        $lines[] = "    ],";
    }
    $lines[] = "]);";
    return implode("\n", $lines);
}

// ─── Handle AJAX: Auto-discover ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'autodiscover') {
    header('Content-Type: application/json');

    $devices = auto_discover_devices();
    if (isset($devices['_error'])) {
        echo json_encode(['error' => $devices['_error']]);
        exit;
    }

    // Enrich each device with port config
    $enriched = [];
    foreach ($devices as $i => $dev) {
        $device_id = $dev['device_id'] ?? $dev['id'] ?? $dev['serial_number'] ?? null;
        if (!$device_id) continue;

        $port_info = fetch_device_port_config($device_id);

        // Extract lat/lng — v5 may provide at device level or in measurement metadata
        $lat = (float)($dev['latitude']  ?? $dev['lat'] ?? $port_info['device_meta']['latitude']  ?? 0);
        $lng = (float)($dev['longitude'] ?? $dev['lng'] ?? $port_info['device_meta']['longitude'] ?? 0);

        $enriched[] = [
            'device_id' => $device_id,
            'id'        => $device_id,
            'name'      => $dev['name'] ?? $device_id,
            'lat'       => $lat,
            'lng'       => $lng,
            'ports'     => $port_info['ports'],
            '_error'    => $port_info['_error'] ?? null,
            '_raw_ports'=> $port_info['_raw_ports'] ?? [],
        ];

        // Rate limit: burst=5, then 1/min
        if ($i >= 4 && $i < count($devices) - 1) sleep(62);
        elseif ($i < count($devices) - 1) sleep(1);
    }

    echo json_encode([
        'stations'   => $enriched,
        'php_output' => generate_stations_php($enriched),
        'count'      => count($enriched),
    ]);
    exit;
}

// ─── Handle AJAX: Manual serial numbers ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'manual') {
    header('Content-Type: application/json');

    $raw_input = trim($_POST['serials'] ?? '');
    $serials   = array_filter(
        array_map('trim', preg_split('/[\n,]+/', $raw_input)),
        fn($s) => $s !== ''
    );

    if (empty($serials)) { echo json_encode(['error' => 'No serial numbers provided']); exit; }
    if (count($serials) > 60) { echo json_encode(['error' => 'Max 60 at once']); exit; }

    $enriched = [];
    foreach ($serials as $i => $sn) {
        $sn = strtolower(trim($sn));
        $port_info = fetch_device_port_config($sn);

        $lat = (float)($port_info['device_meta']['latitude']  ?? 0);
        $lng = (float)($port_info['device_meta']['longitude'] ?? 0);
        $name= $port_info['device_meta']['name'] ?? $sn;

        $enriched[] = [
            'device_id' => $sn,
            'id'        => $sn,
            'name'      => $name,
            'lat'       => $lat,
            'lng'       => $lng,
            'ports'     => $port_info['ports'],
            '_error'    => $port_info['_error'] ?? null,
        ];

        if ($i >= 4 && $i < count($serials) - 1) sleep(62);
        elseif ($i < count($serials) - 1) sleep(1);
    }

    echo json_encode([
        'stations'   => $enriched,
        'php_output' => generate_stations_php($enriched),
        'count'      => count($enriched),
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>KGS Station Setup Helper — v5</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');
:root {
  --bg:#0f1614;--panel:#151e1a;--card:#1c2822;--border:rgba(120,180,140,.2);
  --green:#5dba7d;--amber:#c9a84c;--red:#d4544a;--blue:#4a9ebb;
  --text:#e8f0eb;--muted:#9ab5a3;--dim:#5a7a65;
  --mono:'DM Mono',monospace;--sans:'DM Sans',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--sans);background:var(--bg);color:var(--text);min-height:100vh;padding:32px 24px;font-size:14px;line-height:1.6}
.container{max-width:920px;margin:0 auto}
h1{font-size:22px;font-weight:600;margin-bottom:4px}
.subtitle{color:var(--muted);font-size:12px;font-family:var(--mono);margin-bottom:28px;text-transform:uppercase;letter-spacing:.06em}
.badge-v5{display:inline-block;background:rgba(74,158,187,.15);border:1px solid rgba(74,158,187,.4);color:var(--blue);font-size:10px;font-family:var(--mono);padding:2px 8px;border-radius:10px;margin-left:8px;vertical-align:middle}
.warn-box,.info-box{border-radius:6px;padding:12px 16px;margin-bottom:18px;font-size:13px;line-height:1.6}
.warn-box{background:rgba(212,84,74,.1);border:1px solid rgba(212,84,74,.35);color:#e08080}
.info-box{background:rgba(93,186,125,.08);border:1px solid rgba(93,186,125,.25);color:var(--muted)}
.info-box strong{color:var(--green)}
code{font-family:var(--mono);background:rgba(255,255,255,.06);padding:1px 5px;border-radius:3px;font-size:.95em}
.card{background:var(--panel);border:1px solid var(--border);border-radius:8px;padding:22px;margin-bottom:18px}
.card h2{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--dim);font-family:var(--mono);margin-bottom:14px}
.tabs{display:flex;gap:0;border-bottom:1px solid var(--border);margin-bottom:18px}
.tab{padding:8px 18px;font-size:13px;cursor:pointer;border-bottom:2px solid transparent;color:var(--muted);transition:all .2s;background:none;border-top:none;border-left:none;border-right:none;font-family:var(--sans)}
.tab.active{color:var(--green);border-bottom-color:var(--green)}
.tab-pane{display:none}.tab-pane.active{display:block}
label{display:block;font-size:11px;font-family:var(--mono);text-transform:uppercase;letter-spacing:.07em;color:var(--dim);margin-bottom:7px}
textarea{width:100%;background:var(--card);border:1px solid var(--border);border-radius:6px;color:var(--text);font-family:var(--mono);font-size:13px;padding:11px;resize:vertical;min-height:120px;outline:none;transition:border-color .2s;line-height:1.7}
textarea:focus{border-color:rgba(93,186,125,.5)}
textarea::placeholder{color:var(--dim)}
.hint{font-size:11px;color:var(--dim);font-family:var(--mono);margin-top:5px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:9px 20px;border-radius:6px;border:none;font-family:var(--sans);font-size:13px;font-weight:500;cursor:pointer;transition:all .2s;margin-top:12px}
.btn-primary{background:var(--green);color:#0f1614}.btn-primary:hover{background:#4da96d}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.btn-secondary{background:var(--card);border:1px solid var(--border);color:var(--muted)}.btn-secondary:hover{border-color:var(--green);color:var(--green)}
.btn-copy{background:var(--card);border:1px solid var(--border);color:var(--muted);font-size:12px;padding:6px 14px;margin-top:0}
.btn-copy:hover{border-color:var(--green);color:var(--green)}
#progress{display:none;margin-top:14px}
.prog-label{font-size:12px;font-family:var(--mono);color:var(--muted);margin-bottom:7px}
.prog-bar-wrap{height:4px;background:var(--card);border-radius:2px;overflow:hidden}
.prog-bar-fill{height:100%;background:var(--green);border-radius:2px;width:0%;transition:width .5s;animation:pulse 1.5s ease-in-out infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}
#results{display:none}
.stn{background:var(--card);border:1px solid var(--border);border-radius:6px;padding:13px 15px;margin-bottom:9px}
.stn.err{border-color:rgba(212,84,74,.4);background:rgba(212,84,74,.04)}
.stn.warn{border-color:rgba(201,168,76,.35)}
.stn-hdr{display:flex;align-items:center;gap:9px;margin-bottom:7px;flex-wrap:wrap}
.stn-sn{font-family:var(--mono);font-size:11px;color:var(--dim);background:var(--panel);padding:2px 7px;border-radius:4px}
.stn-name{font-weight:600;font-size:14px}
.badge{font-size:10px;font-family:var(--mono);padding:2px 7px;border-radius:10px;margin-left:auto}
.badge-ok{background:rgba(93,186,125,.12);color:var(--green)}
.badge-warn{background:rgba(201,168,76,.12);color:var(--amber)}
.badge-err{background:rgba(212,84,74,.12);color:var(--red)}
.meta{font-size:12px;font-family:var(--mono);color:var(--muted);margin-bottom:7px}
.port-list{display:flex;flex-wrap:wrap;gap:5px;margin-top:5px}
.port-chip{font-size:11px;font-family:var(--mono);padding:2px 8px;border-radius:4px;border:1px solid var(--border);color:var(--muted)}
.port-chip.soil_moisture{border-color:rgba(93,186,125,.4);color:#5dba7d}
.port-chip.matric_potential{border-color:rgba(212,121,58,.4);color:#d4793a}
.port-chip.soil_temp{border-color:rgba(74,158,187,.4);color:#4a9ebb}
.err-msg{font-size:12px;color:#e08080;font-family:var(--mono);margin-top:5px}
.summ{display:flex;gap:18px;padding:10px 0;border-bottom:1px solid var(--border);margin-bottom:14px}
.summ-stat{font-size:12px;font-family:var(--mono)}.summ-stat span{color:var(--green);font-weight:500}
.php-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.php-hdr h2{margin-bottom:0}
pre#php-out{background:var(--card);border:1px solid var(--border);border-radius:6px;padding:14px;font-family:var(--mono);font-size:12px;color:var(--muted);overflow:auto;white-space:pre;line-height:1.7;max-height:480px}
.copy-ok{font-size:11px;font-family:var(--mono);color:var(--green);margin-left:9px;opacity:0;transition:opacity .3s}
.copy-ok.show{opacity:1}
</style>
</head>
<body>
<div class="container">

<h1>🌱 KGS Station Setup Helper <span class="badge-v5">Zentra Cloud 2.0 · API v5</span></h1>
<p class="subtitle">One-time utility — auto-discovers station configs from your Zentra organization</p>

<?php if (!$token_set): ?>
<div class="warn-box">
  ⚠ <strong>API token not configured.</strong> Edit <code>config.php</code> and replace
  <code>YOUR_ZENTRA_V5_API_TOKEN_HERE</code> with your token from
  <code>app.zentracloud.io → Profile → Integrations</code>.
</div>
<?php endif; ?>

<div class="info-box">
  <strong>What's new in v5:</strong> The API now has a <code>GET /v5/devices/</code> endpoint that lists
  all devices in your organization — so <strong>Mode 1 can discover all your stations automatically</strong>
  with zero manual input. Just click the button.<br><br>
  <strong>Rate limit:</strong> v5 uses GCRA — burst of 5 requests, then 1 per minute.
  Auto-discovery for 25 stations will take a few minutes. The page will show progress.
</div>

<!-- Input card -->
<div class="card">
  <h2>Step 1 — Choose Discovery Mode</h2>

  <div class="tabs">
    <button class="tab active" data-tab="auto">Mode 1: Auto-Discover (recommended)</button>
    <button class="tab" data-tab="manual">Mode 2: Manual Serial Numbers</button>
  </div>

  <div class="tab-pane active" id="tab-auto">
    <p style="color:var(--muted);font-size:13px;margin-bottom:14px">
      Fetches all devices from your Zentra Cloud 2.0 organization automatically.
      Your account must be an <strong style="color:var(--text)">Editor or Administrator</strong> of the organization.
    </p>
    <button class="btn btn-primary" id="btn-auto" <?= !$token_set ? 'disabled' : '' ?>>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Auto-Discover All Stations
    </button>
  </div>

  <div class="tab-pane" id="tab-manual">
    <label for="serials-input">Device Serial Numbers</label>
    <textarea id="serials-input"
      placeholder="z6-12345&#10;z6-12346&#10;z6-12347&#10;&#10;(one per line or comma-separated)"
      <?= !$token_set ? 'disabled' : '' ?>></textarea>
    <p class="hint">Found in Zentra Cloud 2.0: Devices list · Format: z6-XXXXX</p>
    <button class="btn btn-primary" id="btn-manual" <?= !$token_set ? 'disabled' : '' ?>>
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
      Fetch Selected Stations
    </button>
  </div>

  <div id="progress">
    <p class="prog-label" id="prog-label">Working…</p>
    <div class="prog-bar-wrap"><div class="prog-bar-fill" id="prog-bar"></div></div>
  </div>
</div>

<!-- Results -->
<div id="results">
  <div class="card">
    <h2>Step 2 — Review Discovered Stations</h2>
    <div class="summ" id="summ"></div>
    <div id="stn-list"></div>
  </div>
  <div class="card">
    <div class="php-hdr">
      <h2>Step 3 — Copy into config.php</h2>
      <div>
        <button class="btn btn-copy" id="btn-copy">Copy to Clipboard</button>
        <span class="copy-ok" id="copy-ok">Copied!</span>
      </div>
    </div>
    <p style="font-size:12px;color:var(--muted);font-family:var(--mono);margin-bottom:10px">
      Replace the <code>define('STATIONS', [...])</code> block in <code>config.php</code> with this.
      Fill in the <code>'region'</code> values and verify any warnings.
    </p>
    <pre id="php-out"></pre>
  </div>
</div>

</div><!-- /container -->
<script>
// Tabs
document.querySelectorAll('.tab').forEach(t => {
  t.addEventListener('click', () => {
    document.querySelectorAll('.tab,.tab-pane').forEach(x => x.classList.remove('active'));
    t.classList.add('active');
    document.getElementById('tab-' + t.dataset.tab).classList.add('active');
  });
});

async function runFetch(action, body = {}) {
  const fd = new FormData();
  fd.append('action', action);
  Object.entries(body).forEach(([k, v]) => fd.append(k, v));

  ['btn-auto','btn-manual'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.disabled = true;
  });
  document.getElementById('progress').style.display = 'block';
  document.getElementById('results').style.display  = 'none';

  const lbl = document.getElementById('prog-label');
  const bar = document.getElementById('prog-bar');

  if (action === 'autodiscover') {
    lbl.textContent = 'Discovering all devices in your organization…';
  } else {
    const count = body.serials?.split(/[\n,]+/).filter(s=>s.trim()).length || 1;
    lbl.textContent = `Fetching ${count} station(s) — this may take a few minutes due to API rate limits`;
  }

  // Animate bar
  let pct = 5;
  const timer = setInterval(() => { pct = Math.min(pct + 0.5, 88); bar.style.width = pct + '%'; }, 2000);

  try {
    const res  = await fetch(window.location.href, { method: 'POST', body: fd });
    const json = await res.json();
    clearInterval(timer);
    bar.style.width = '100%';
    bar.style.animation = 'none';

    if (json.error) { alert('Error: ' + json.error); return; }
    renderResults(json);
  } catch(e) {
    clearInterval(timer);
    alert('Request failed: ' + e.message);
  } finally {
    ['btn-auto','btn-manual'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.disabled = false;
    });
    setTimeout(() => document.getElementById('progress').style.display = 'none', 800);
  }
}

document.getElementById('btn-auto').addEventListener('click', () => runFetch('autodiscover'));
document.getElementById('btn-manual').addEventListener('click', () => {
  const s = document.getElementById('serials-input').value.trim();
  if (!s) { alert('Please enter serial numbers.'); return; }
  runFetch('manual', { serials: s });
});

function renderResults(json) {
  const stations = json.stations || [];
  const ok    = stations.filter(s => !s._error && s.ports?.length > 0).length;
  const errs  = stations.filter(s =>  s._error).length;
  const warns = stations.filter(s => !s._error && (!s.lat || !s.lng || s.ports?.length === 0)).length;

  document.getElementById('summ').innerHTML = `
    <div class="summ-stat">Total: <span>${stations.length}</span></div>
    <div class="summ-stat">OK: <span>${ok}</span></div>
    ${errs  ? `<div class="summ-stat" style="color:var(--red)">Errors: <span>${errs}</span></div>` : ''}
    ${warns ? `<div class="summ-stat" style="color:var(--amber)">Needs Review: <span>${warns}</span></div>` : ''}
  `;

  const list = document.getElementById('stn-list');
  list.innerHTML = '';
  stations.forEach(s => {
    const hasErr  = !!s._error;
    const hasWarn = !hasErr && (!s.lat || !s.lng || !s.ports?.length);
    const cls     = hasErr ? 'err' : hasWarn ? 'warn' : '';
    const badge   = hasErr ? '<span class="badge badge-err">ERROR</span>'
                  : hasWarn ? '<span class="badge badge-warn">REVIEW</span>'
                  : '<span class="badge badge-ok">OK</span>';

    const chips = (s.ports||[]).map(p =>
      `<div class="port-chip ${p.type}" title="${p.sensor||''}">Port ${p.port} · ${p.label} · ${typeLabel(p.type)}</div>`
    ).join('');

    const div = document.createElement('div');
    div.className = 'stn ' + cls;
    div.innerHTML = `
      <div class="stn-hdr">
        <span class="stn-sn">${s.device_id || s.id}</span>
        <span class="stn-name">${s.name !== (s.device_id||s.id) ? s.name : '(name not retrieved)'}</span>
        ${badge}
      </div>
      ${s.lat && s.lng
        ? `<div class="meta">📍 ${s.lat.toFixed(5)}, ${s.lng.toFixed(5)}</div>`
        : `<div class="meta" style="color:var(--amber)">⚠ No GPS coordinates — enter lat/lng manually in config.php</div>`}
      ${s.ports?.length ? `<div class="port-list">${chips}</div>` : (!hasErr ? '<div style="font-size:12px;color:var(--amber);font-family:var(--mono);margin-top:5px">⚠ No soil sensor ports detected</div>' : '')}
      ${s._error ? `<div class="err-msg">✗ ${s._error}</div>` : ''}
    `;
    list.appendChild(div);
  });

  document.getElementById('php-out').textContent = json.php_output;
  document.getElementById('results').style.display = 'block';
  document.getElementById('results').scrollIntoView({ behavior: 'smooth' });
}

function typeLabel(t) {
  return {soil_moisture:'VWC',matric_potential:'Matric',soil_temp:'Temp',precipitation:'Precip'}[t]||t;
}

document.getElementById('btn-copy').addEventListener('click', () => {
  navigator.clipboard.writeText(document.getElementById('php-out').textContent).then(() => {
    const el = document.getElementById('copy-ok');
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 2000);
  });
});
</script>
</body>
</html>