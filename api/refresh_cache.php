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

set_time_limit(600);
header('Content-Type: application/json');

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

    // ── Group flat values[] by timestamp ─────────────────────────────────────
    //   timeseries[unix_ts] = [
    //     'datetime' => '...',
    //     'sensors'  => [ { port_num, measurement, sensor_name, value, unit, type, label, depth_cm } ]
    //   ]
    $timeseries          = [];
    $latest_by_port_meas = []; // "port_N:measurement" => latest { ts, value, ... }

    foreach ($values as $v) {
        $ts         = (int)($v['timestamp'] ?? 0);
        $dt         = $v['datetime']    ?? null;
        $port_num   = (int)($v['port_num']    ?? 0);
        $meas_name  = $v['measurement'] ?? '';
        $sensor_name= $v['sensor_name'] ?? '';
        $raw_value  = $v['value']       ?? null;
        $error_code = (int)($v['error_code'] ?? 0);
        $unit       = $v['unit']        ?? '';

        if (!$ts || !$dt) continue;
        if ($error_code !== 0 || $raw_value === null) continue; // skip bad readings

        // Determine sensor type
        $port_cfg = $port_cfg_map[$port_num] ?? null;
        $type     = $port_cfg['type']     ?? detect_sensor_type_v5($sensor_name, $meas_name);
        $depth_cm = $port_cfg['depth_cm'] ?? null;
        $label    = $port_cfg['label']    ?? ($depth_cm !== null ? $depth_cm . ' cm' : $sensor_name);

        // Normalize VWC: ensure stored as m³/m³ (0–1 range)
        $norm_value = (float)$raw_value;
        if ($type === 'soil_moisture' && $norm_value > 1.5) {
            $norm_value = $norm_value / 100.0; // was in percent
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
            'unit'     => $unit ?: get_unit_label_v5($type),
            'sensor'   => $sensor_name,
            'meas'     => $meas_name,
        ];

        // Track latest value per port+measurement for map marker / summary
        $pk = "port_{$port_num}:{$meas_name}";
        if (!isset($latest_by_port_meas[$pk]) || $ts > $latest_by_port_meas[$pk]['ts']) {
            $latest_by_port_meas[$pk] = [
                'ts'       => $ts,
                'port'     => $port_num,
                'type'     => $type,
                'label'    => $label,
                'depth_cm' => $depth_cm,
                'value'    => $norm_value,
                'unit'     => $unit ?: get_unit_label_v5($type),
                'sensor'   => $sensor_name,
                'meas'     => $meas_name,
            ];
        }
    }

    ksort($timeseries);
    $history = array_values($timeseries);

    // ── Compute average latest soil moisture (for map marker color) ───────────
    $moisture_vals = [];
    foreach ($latest_by_port_meas as $pk => $entry) {
        if ($entry['type'] === 'soil_moisture') {
            $moisture_vals[] = $entry['value'];
        }
    }
    $moisture_avg = !empty($moisture_vals)
        ? round(array_sum($moisture_vals) / count($moisture_vals), 4)
        : null;

    // Build latest_sensors for summary panel (all types, most recent per port+meas)
    $latest_sensors = array_values($latest_by_port_meas);
    usort($latest_sensors, fn($a, $b) => $a['port'] <=> $b['port']);

    $latest_row = !empty($history) ? end($history) : null;

    return [
        'station_id'          => $device_id,
        'name'                => $device_meta['device_name'] ?? $station['name'],
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

foreach ($stations_to_run as $station) {
    $id = $station['id'];
    error_log("Zentra v5 refresh: [{$i}/{$total}] {$id}");

    $raw = zentra_v5_fetch_data($id);

    if (isset($raw['_error'])) {
        $results[] = [
            'station' => $id,
            'status'  => 'error',
            'message' => $raw['_error'],
            'http'    => $raw['_status'] ?? 0,
        ];
    } else {
        $normalized = normalize_station_data_v5($station, $raw);
        $ok         = write_station_cache($id, $normalized);
        $results[]  = [
            'station'         => $id,
            'status'          => $ok ? 'ok' : 'write_error',
            'records'         => count($normalized['history']),
            'latest_moisture' => $normalized['latest_moisture_pct'],
            'name'            => $normalized['name'],
        ];
    }

    $i++;

    // GCRA rate limit: burst=5 free, then 62s between calls
    if ($i < $total) {
        $wait = ($i < 5) ? 1 : 62;
        sleep($wait);
    }
}

write_summary_cache();

$log_line = date('c') . " refresh complete: {$i}/{$total} stations processed\n";
@file_put_contents(CACHE_DIR . 'refresh.log', $log_line, FILE_APPEND);

echo json_encode([
    'done'       => true,
    'processed'  => $i,
    'total'      => $total,
    'results'    => $results,
    'ts'         => date('c'),
], JSON_PRETTY_PRINT);s