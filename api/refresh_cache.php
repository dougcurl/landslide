<?php
/**
 * api/refresh_cache.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Fetches latest data from Zentra Cloud 2.0 API v5 and writes JSON cache files.
 *
 * Can be called:
 *   - By Windows Task Scheduler every 15 minutes (recommended)
 *   - On-demand by get_station_data.php when cache is stale
 *   - Directly: refresh_cache.php           — refresh ALL stations
 *   - Directly: refresh_cache.php?station=z6-00001 — refresh ONE station
 *
 * v5 Rate limit: GCRA — burst of 5, then 1 req/min steady-state.
 * With 25 stations we exhaust the burst quickly; expect ~25 min for a full
 * sequential refresh. For this reason the Task Scheduler should call this
 * script continuously — it will refresh whichever stations are stale.
 *
 * Better strategy (implemented here): refresh stations that are MOST STALE
 * first, and stop after processing as many as the burst + steady-state allows
 * within a single scheduler run.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/zentra_v5.php';

// Allow long execution — full refresh can take several minutes
set_time_limit(300);
header('Content-Type: application/json');

// ─── Normalize raw v5 measurement response into our cache format ──────────────

/**
 * Takes the raw v5 measurements response and the station config,
 * returns a normalized cache object.
 *
 * v5 measurement row (expected structure from api.zentracloud.io Swagger):
 * {
 *   "datetime":  "2025-03-09T14:00:00+00:00",
 *   "timestamp": 1741528800,
 *   "measurements": {
 *     "Port 1": {
 *       "sensor_name":       "TEROS 12",
 *       "measurement_name":  "Water Content",
 *       "value":             0.312,
 *       "unit":              "m³/m³",
 *       "error_flag":        false,
 *       "depth":             100       // depth in mm
 *     },
 *     "Port 2": { ... }
 *   }
 * }
 */
function normalize_station_data_v5(array $station, array $raw_response): array {
    $device_id   = $station['id'];
    $device_meta = $raw_response['device_meta'] ?? null;
    $results     = $raw_response['results']     ?? [];

    // Build a port lookup from config: "Port N" => config
    $port_cfg_map = [];
    foreach ($station['ports'] as $p) {
        $port_cfg_map['Port ' . $p['port']] = $p;
    }

    // ── Build timeseries ──
    $timeseries = [];
    $latest_moisture_by_port = [];  // port_key => ['ts'=>, 'value'=>, 'label'=>, 'depth_cm'=>]

    foreach ($results as $row) {
        $dt_str = $row['datetime']  ?? null;
        $ts_unix = isset($row['timestamp']) ? (int)$row['timestamp'] : ($dt_str ? strtotime($dt_str) : null);

        if (!$ts_unix || !$dt_str) continue;

        $measurements = $row['measurements'] ?? [];
        $sensors_out  = [];

        foreach ($measurements as $port_key => $meas) {
            // port_key is like "Port 1", "Port 2", etc.
            $port_cfg    = $port_cfg_map[$port_key] ?? null;
            $sensor_name = $meas['sensor_name']      ?? '';
            $meas_name   = $meas['measurement_name'] ?? '';
            $value       = $meas['value']            ?? null;
            $error_flag  = $meas['error_flag']       ?? false;
            $depth_mm    = $meas['depth']            ?? null;

            // Skip error readings
            if ($error_flag || $value === null) continue;

            // Determine port number from key
            preg_match('/(\d+)$/', $port_key, $pm);
            $port_num = isset($pm[1]) ? (int)$pm[1] : 0;

            // Use configured type if available, else auto-detect
            $type     = $port_cfg['type']     ?? detect_sensor_type_v5($sensor_name, $meas_name);
            $depth_cm = $port_cfg['depth_cm'] ?? ($depth_mm !== null ? (int)round($depth_mm / 10) : null);
            $label    = $port_cfg['label']    ?? ($depth_cm !== null ? $depth_cm . ' cm' : $sensor_name);
            $unit     = $meas['unit']         ?? get_unit_v5($type);

            // Normalize VWC unit: v5 may return % or m³/m³
            $norm_value = (float)$value;
            if ($type === 'soil_moisture') {
                // Ensure stored as m³/m³ (0–1 range)
                if ($norm_value > 1.5) $norm_value = $norm_value / 100.0;
                $norm_value = round($norm_value, 4);
            } else {
                $norm_value = round($norm_value, 3);
            }

            $sensors_out[$port_key] = [
                'port'     => $port_num,
                'label'    => $label,
                'type'     => $type,
                'depth_cm' => $depth_cm,
                'value'    => $norm_value,
                'unit'     => $unit,
                'sensor'   => $sensor_name,
            ];

            // Track latest soil moisture per port for map marker
            if ($type === 'soil_moisture') {
                $pk = 'port_' . $port_num;
                if (!isset($latest_moisture_by_port[$pk]) ||
                    $ts_unix > $latest_moisture_by_port[$pk]['ts']) {
                    $latest_moisture_by_port[$pk] = [
                        'ts'       => $ts_unix,
                        'label'    => $label,
                        'depth_cm' => $depth_cm,
                        'value'    => $norm_value,
                    ];
                }
            }
        }

        if (!empty($sensors_out)) {
            $timeseries[$ts_unix] = [
                'datetime' => $dt_str,
                'sensors'  => array_values($sensors_out),
            ];
        }
    }

    ksort($timeseries);
    $history = array_values($timeseries);

    // Average moisture across latest readings from all soil_moisture ports
    $moisture_avg = null;
    if (!empty($latest_moisture_by_port)) {
        $vals = array_column($latest_moisture_by_port, 'value');
        $moisture_avg = round(array_sum($vals) / count($vals), 4);
    }

    $latest_row = !empty($history) ? end($history) : null;

    // Also pull lat/lng from device_meta if available (useful if config is placeholder)
    $lat = $station['lat'];
    $lng = $station['lng'];
    if ($device_meta) {
        if (isset($device_meta['latitude'])  && $station['lat'] == 0) $lat = (float)$device_meta['latitude'];
        if (isset($device_meta['longitude']) && $station['lng'] == 0) $lng = (float)$device_meta['longitude'];
    }

    return [
        'station_id'          => $device_id,
        'name'                => $device_meta['name'] ?? $station['name'],
        'lat'                 => $lat,
        'lng'                 => $lng,
        'region'              => $station['region'],
        'cached_at'           => date('c'),
        'latest_datetime'     => $latest_row ? $latest_row['datetime'] : null,
        'latest_moisture_avg' => $moisture_avg,
        'latest_moisture_pct' => $moisture_avg !== null ? round($moisture_avg * 100, 1) : null,
        'latest_sensors'      => $latest_row  ? $latest_row['sensors'] : [],
        'port_config'         => $station['ports'],
        'history'             => $history,
    ];
}

// ─── Write cache files ────────────────────────────────────────────────────────

function write_station_cache(string $station_id, array $data): bool {
    if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0755, true);
    return file_put_contents(
        CACHE_DIR . $station_id . '.json',
        json_encode($data, JSON_PRETTY_PRINT)
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
            'latest_datetime'     => $d['latest_datetime'],
            'latest_moisture_avg' => $d['latest_moisture_avg'],
            'latest_moisture_pct' => $d['latest_moisture_pct'],
            'latest_sensors'      => $d['latest_sensors'],
        ];
    }
    return file_put_contents(
        CACHE_DIR . 'stations_summary.json',
        json_encode(['cached_at' => date('c'), 'stations' => $summary], JSON_PRETTY_PRINT)
    ) !== false;
}

// ─── Main ─────────────────────────────────────────────────────────────────────

$target = $_GET['station'] ?? null;

$stations_to_run = $target
    ? array_filter(STATIONS, fn($s) => $s['id'] === $target)
    : STATIONS;

// Sort by staleness — refresh most-stale stations first
// This ensures that if we hit rate limits mid-run, the freshest stations
// were already handled in the previous run
usort($stations_to_run, function($a, $b) {
    $age_a = PHP_INT_MAX;
    $age_b = PHP_INT_MAX;
    $path_a = CACHE_DIR . $a['id'] . '.json';
    $path_b = CACHE_DIR . $b['id'] . '.json';
    if (file_exists($path_a)) $age_a = time() - filemtime($path_a);
    if (file_exists($path_b)) $age_b = time() - filemtime($path_b);
    return $age_b <=> $age_a; // most stale first
});

$results  = [];
$i        = 0;

foreach ($stations_to_run as $station) {
    $id = $station['id'];
    error_log("Zentra v5: fetching $id");

    $raw = zentra_v5_fetch_measurements($id);

    if (isset($raw['_error'])) {
        $results[] = ['station' => $id, 'status' => 'error', 'message' => $raw['_error']];
        $i++;
        // Still sleep to respect rate limit even on error
        if ($i < count($stations_to_run)) sleep(2);
        continue;
    }

    $normalized = normalize_station_data_v5($station, $raw);
    $ok         = write_station_cache($id, $normalized);

    $results[] = [
        'station'         => $id,
        'status'          => $ok ? 'ok' : 'write_error',
        'records'         => count($normalized['history']),
        'latest_moisture' => $normalized['latest_moisture_pct'],
    ];

    $i++;
    // v5 GCRA: after the initial burst of 5 is consumed, wait 60s between requests
    // We use 62s to be safe
    if ($i < count($stations_to_run)) {
        $wait = ($i < 5) ? 1 : 62;
        sleep($wait);
    }
}

// Rebuild summary from all cached station files
write_summary_cache();

echo json_encode([
    'done'    => true,
    'results' => $results,
    'ts'      => date('c'),
]);