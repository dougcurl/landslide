<?php
/**
 * api/refresh_cache.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Fetches data from Zentra Cloud 2.0 API v5 and writes JSON cache files.
 *
 * Invocation:
 *   CLI (Task Scheduler): php refresh_cache.php
 *   Web — all stations:   refresh_cache.php
 *   Web — one station:    refresh_cache.php?station=z6-00001
 *
 * v5 endpoint: GET /v5/devices/{device_id}/data
 *
 * v5 rate limit: GCRA — burst of 5, then 1 req/min.
 * With 25 stations we exhaust burst on requests 1-5, then each subsequent
 * station costs ~62s wait. Strategy: sort most-stale stations first so every
 * 15-min Task Scheduler run makes useful progress.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/zentra_v5.php';

// CLI needs unlimited time — 24 stations x 62s = ~25 min minimum
$is_cli = (php_sapi_name() === 'cli');
set_time_limit(0);
ini_set('memory_limit', '256M'); // whitelist filtering keeps per-station usage low

if (!$is_cli) {
    header('Content-Type: application/json');
}

// Verbose output — prints timestamped progress to stdout on CLI
function log_progress(string $msg): void {
    global $is_cli;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    if ($is_cli) {
        echo $line;
        flush();
    }
    //error_log(trim($msg));
}

// ─── Normalize v5 response into cache format ──────────────────────────────────
//
// The v5 /data endpoint returns a FLAT array of ValueEntity rows.
// Each row is one sensor reading at one timestamp:
//   { port_num, measurement, unit, sensor_name, value, timestamp, datetime, error_code }
//
// We group these by timestamp, then build a timeseries usable by Chart.js.
// We also compute per-port latest values for the map marker.

function normalize_station_data_v5(array $station, array $raw_response): array {
    $device_id   = $station['id'];
    $device_meta = $raw_response['device_meta'] ?? null;
    $values      = $raw_response['values']      ?? [];

    // ── Parse coordinates from metadata ──────────────────────────────────────
    $lat = $station['lat'];
    $lng = $station['lng'];
    if ($device_meta && isset($device_meta['coordinates'])) {
        [$api_lat, $api_lng] = parse_coordinates_v5($device_meta['coordinates']);
        // Only overwrite config coords if they're placeholder zeros
        if ($lat == 0 && $api_lat !== null) $lat = $api_lat;
        if ($lng == 0 && $api_lng !== null) $lng = $api_lng;
    }

    // Build a port lookup: port_num => config entry
    $port_cfg_map = [];
    foreach ($station['ports'] as $p) {
        $port_cfg_map[(int)$p['port']] = $p;
    }

    // ── Measurement whitelist ─────────────────────────────────────────────────
    // The TEROS 12 reports many measurements per port (Raw VWC ~25.5, Water Content
    // ~0.34, Pore Water EC, Bulk EC, Soil Temp etc). We only keep the specific
    // measurements we need for charts and markers to avoid both wrong values and
    // excess memory use. Each whitelisted measurement maps to a canonical type.
    // Key = lowercase measurement name, value = [internal_type, unit]
    $MEAS_WHITELIST = [
        'water content'          => ['soil_moisture',    'm³/m³'],
        'matric potential'       => ['matric_potential', 'kPa'],
        'soil temperature'       => ['soil_temp',        '°C'],
        'precipitation'          => ['precipitation',    'mm'],
        'atmospheric pressure'   => ['atmospheric_pressure', 'kPa'],
    ];

    // ── Group flat values[] by timestamp ─────────────────────────────────────
    $timeseries        = [];
    $latest_vwc_by_port = []; // port_num => latest { ts, value } — VWC only, for marker

    foreach ($values as $v) {
        $ts          = (int)($v['timestamp'] ?? 0);
        $dt          = $v['datetime']    ?? null;
        $port_num    = (int)($v['port_num']   ?? 0);
        $meas_name   = $v['measurement'] ?? '';
        $sensor_name = $v['sensor_name'] ?? '';
        $raw_value   = $v['value']       ?? null;
        $error_code  = (int)($v['error_code'] ?? 0);
        $unit        = $v['unit']        ?? '';

        if (!$ts || !$dt) continue;
        if ($error_code !== 0 || $raw_value === null) continue;

        // Only keep whitelisted measurements
        $meas_lower = strtolower(trim($meas_name));
        if (!isset($MEAS_WHITELIST[$meas_lower])) continue;

        [$type, $canonical_unit] = $MEAS_WHITELIST[$meas_lower];

        $port_cfg = $port_cfg_map[$port_num] ?? null;
        $depth_cm = $port_cfg['depth_cm'] ?? null;
        $label    = $port_cfg['label']    ?? ($depth_cm !== null ? $depth_cm . ' cm' : $sensor_name);

        // VWC: store as m³/m³ (0–1). Water Content from TEROS 12 is already m³/m³.
        // Guard against any Raw VWC slipping through (>1.5 = raw count, divide by 100).
        $norm_value = (float)$raw_value;
        if ($type === 'soil_moisture' && $norm_value > 1.5) {
            $norm_value = $norm_value / 100.0;
        }
        $norm_value = round($norm_value, 4);

        // Group by timestamp
        if (!isset($timeseries[$ts])) {
            $timeseries[$ts] = ['datetime' => $dt, 'sensors' => []];
        }
        $timeseries[$ts]['sensors'][] = [
            'port'     => $port_num,
            'label'    => $label,
            'type'     => $type,
            'depth_cm' => $depth_cm,
            'value'    => $norm_value,
            'unit'     => $canonical_unit,
            'sensor'   => $sensor_name,
            'meas'     => $meas_name,
        ];

        // Track latest VWC per port for map marker color
        if ($type === 'soil_moisture') {
            if (!isset($latest_vwc_by_port[$port_num]) ||
                $ts > $latest_vwc_by_port[$port_num]['ts']) {
                $latest_vwc_by_port[$port_num] = [
                    'ts'       => $ts,
                    'port'     => $port_num,
                    'type'     => $type,
                    'label'    => $label,
                    'depth_cm' => $depth_cm,
                    'value'    => $norm_value,
                    'unit'     => $canonical_unit,
                    'sensor'   => $sensor_name,
                    'meas'     => $meas_name,
                ];
            }
        }
    }

    ksort($timeseries);
    $history = array_values($timeseries);

    // ── Compute average latest VWC across ports (for map marker color) ────────
    $moisture_vals = array_column($latest_vwc_by_port, 'value');
    $moisture_avg  = !empty($moisture_vals)
        ? round(array_sum($moisture_vals) / count($moisture_vals), 4)
        : null;

    // latest_sensors: one entry per port per whitelisted measurement, most recent ts
    // Build by rescanning history in reverse to find latest per port+type combo
    $latest_by_key  = [];
    foreach (array_reverse($history) as $row) {
        foreach ($row['sensors'] as $s) {
            $k = $s['port'] . ':' . $s['type'];
            if (!isset($latest_by_key[$k])) {
                $latest_by_key[$k] = array_merge($s, ['ts' => 0]);
            }
        }
        if (count($latest_by_key) > 20) break; // got enough
    }
    $latest_sensors = array_values($latest_by_key);
    usort($latest_sensors, fn($a, $b) => $a['port'] <=> $b['port']);

    $latest_row = !empty($history) ? end($history) : null;

    return [
        'station_id'          => $device_id,
        'name'                => $station['name'] ?: ($device_meta['device_name'] ?? ''),
        'lat'                 => $lat,
        'lng'                 => $lng,
        'region'              => $station['region'],
        'location_label'      => $device_meta['location']    ?? '',
        'cached_at'           => date('c'),
        'latest_datetime'     => $latest_row ? $latest_row['datetime'] : null,
        'latest_moisture_avg' => $moisture_avg,
        'latest_moisture_pct' => $moisture_avg !== null ? round($moisture_avg * 100, 1) : null,
        'latest_sensors'      => $latest_sensors,
        'port_config'         => $station['ports'],
        'history'             => $history,
    ];
}

// ─── Cache I/O ────────────────────────────────────────────────────────────────

function write_station_cache(string $station_id, array $data): bool {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    return file_put_contents(
        CACHE_DIR . $station_id . '.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ) !== false;
}

function write_summary_cache(): bool {
    $summary = [];
    foreach (STATIONS as $s) {
        $path = CACHE_DIR . $s['id'] . '.json';
        if (!file_exists($path)) continue;
        $d = json_decode(file_get_contents($path), true);
        if (!$d) continue;
        $summary[] = [
            'station_id'          => $d['station_id'],
            'name'                => $d['name'],
            'lat'                 => $d['lat'],
            'lng'                 => $d['lng'],
            'region'              => $d['region'],
            'location_label'      => $d['location_label'] ?? '',
            'latest_datetime'     => $d['latest_datetime'],
            'latest_moisture_avg' => $d['latest_moisture_avg'],
            'latest_moisture_pct' => $d['latest_moisture_pct'],
            'latest_sensors'      => $d['latest_sensors'],
        ];
    }
    return file_put_contents(
        CACHE_DIR . 'stations_summary.json',
        json_encode(
            ['cached_at' => date('c'), 'stations' => $summary],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        )
    ) !== false;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

$target            = $_GET['station'] ?? null;
$stations_to_run   = $target
    ? array_filter(STATIONS, fn($s) => $s['id'] === $target)
    : STATIONS;

// Sort most-stale first so partial runs always make useful progress
usort($stations_to_run, function($a, $b) {
    $pa = CACHE_DIR . $a['id'] . '.json';
    $pb = CACHE_DIR . $b['id'] . '.json';
    $aa = file_exists($pa) ? filemtime($pa) : 0;
    $ab = file_exists($pb) ? filemtime($pb) : 0;
    return $aa <=> $ab; // oldest mtime first
});

$results = [];
$i       = 0;
$total   = count($stations_to_run);

log_progress("Starting refresh: {$total} stations to process");

foreach ($stations_to_run as $station) {
    $id = $station['id'];
    log_progress("[{$i}/{$total}] Fetching {$id} ({$station['name']})...");

    $raw = zentra_v5_fetch_data($id);

    if (isset($raw['_error'])) {
        log_progress("[{$i}/{$total}] ERROR {$id}: {$raw['_error']} (HTTP {$raw['_status']})");
        $results[] = [
            'station' => $id,
            'status'  => 'error',
            'message' => $raw['_error'],
            'http'    => $raw['_status'] ?? 0,
        ];
    } else {
        $normalized = normalize_station_data_v5($station, $raw);
        $ok         = write_station_cache($id, $normalized);
        $moisture   = $normalized['latest_moisture_pct'];
        $records    = count($normalized['history']);
        log_progress("[{$i}/{$total}] OK {$id}: {$records} records, moisture=" . ($moisture ?? 'null') . "%");
        $results[]  = [
            'station'         => $id,
            'status'          => $ok ? 'ok' : 'write_error',
            'records'         => $records,
            'latest_moisture' => $moisture,
            'name'            => $normalized['name'],
        ];
    }

    $i++;

    // GCRA rate limit: burst=5 free, then 62s between calls
    if ($i < $total) {
        $wait = ($i < 5) ? 1 : 62;
        log_progress("  Waiting {$wait}s (rate limit)...");
        sleep($wait);
    }
}

write_summary_cache();
log_progress("Done. {$i}/{$total} stations processed. Summary cache written.");

$log_line = date('c') . " refresh complete: {$i}/{$total} stations processed" . PHP_EOL;
@file_put_contents(CACHE_DIR . 'refresh.log', $log_line, FILE_APPEND);

$output = json_encode([
    'done'       => true,
    'processed'  => $i,
    'total'      => $total,
    'results'    => $results,
    'ts'         => date('c'),
], JSON_PRETTY_PRINT);

if ($is_cli) {
    echo $output . PHP_EOL;
} else {
    echo $output;
}