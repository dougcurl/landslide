<?php
/**
 * api/download_station.php?id=z6-29290&format=csv
 * Streams a 14-day data download from the station cache.
 * Supports format=csv (default) or format=json.
 */
require_once __DIR__ . '/../config.php';

$station_id = trim($_GET['id']     ?? '');
$format     = trim($_GET['format'] ?? 'csv');
$format     = in_array($format, ['csv','json']) ? $format : 'csv';

// ── Validate ──────────────────────────────────────────────────────────────────
if (!$station_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: id']);
    exit;
}

$station_cfg = null;
foreach (STATIONS as $s) {
    if ($s['id'] === $station_id) { $station_cfg = $s; break; }
}

if (!$station_cfg) {
    http_response_code(404);
    echo json_encode(['error' => 'Unknown station: ' . htmlspecialchars($station_id)]);
    exit;
}

$cache_path = CACHE_DIR . $station_id . '.json';
if (!file_exists($cache_path)) {
    http_response_code(503);
    echo json_encode(['error' => 'No cached data available for this station yet.']);
    exit;
}

$data = json_decode(file_get_contents($cache_path), true);
if (!$data || empty($data['history'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Cache read error or empty history.']);
    exit;
}

// ── Build a safe filename ─────────────────────────────────────────────────────
$safe_name = preg_replace('/[^a-z0-9]+/', '_', strtolower($data['name']));
$date_str  = date('Y-m-d');
$filename  = "kgs_landslide_{$safe_name}_{$date_str}.{$format}";

// ── Discover all sensor columns across full history ───────────────────────────
// We scan the history to find every unique port+type combination so the
// column headers are accurate for this specific station's sensor config.
$columns = []; // key => [port, type, label, unit]

foreach ($data['history'] as $row) {
    foreach ($row['sensors'] ?? [] as $s) {
        $key = $s['type'] . '_port' . $s['port'];
        if (!isset($columns[$key])) {
            $columns[$key] = [
                'port'  => $s['port'],
                'type'  => $s['type'],
                'label' => $s['label'] ?? ($s['depth_cm'] ? $s['depth_cm'] . 'cm' : 'port' . $s['port']),
                'unit'  => $s['unit']  ?? '',
            ];
        }
    }
}

// Sort columns: soil_moisture first, then matric_potential, soil_temp, precipitation
$type_order = ['soil_moisture' => 0, 'matric_potential' => 1, 'soil_temp' => 2, 'precipitation' => 3, 'air_temp' => 4, 'humidity' => 5];
uasort($columns, function($a, $b) use ($type_order) {
    $ta = $type_order[$a['type']] ?? 9;
    $tb = $type_order[$b['type']] ?? 9;
    if ($ta !== $tb) return $ta <=> $tb;
    return $a['port'] <=> $b['port'];
});

// ── Human-readable type labels ────────────────────────────────────────────────
function type_label(string $t): string {
    return match($t) {
        'soil_moisture'        => 'VWC_m3m3',
        'matric_potential'     => 'MatricPotential_kPa',
        'soil_temp'            => 'SoilTemp_C',
        'precipitation'        => 'Precip_mm',
        'atmospheric_pressure' => 'AtmPressure_kPa',
        'air_temp'             => 'AirTemp_C',
        'humidity'             => 'RelHumidity_pct',
        default                => $t,
    };
}

// ── JSON output ───────────────────────────────────────────────────────────────
if ($format === 'json') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    // Build clean output — one object per timestamp
    $records = [];
    foreach ($data['history'] as $row) {
        $record = [
            'date'       => substr($row['datetime'], 0, 10),
            'time'       => substr($row['datetime'], 11, 8),
            'station_id' => $data['station_id'],
            'station'    => $data['name'],
            'region'     => $data['region'],
        ];
        // Index sensors by key for easy lookup
        $sensor_map = [];
        foreach ($row['sensors'] ?? [] as $s) {
            $sensor_map[$s['type'] . '_port' . $s['port']] = $s['value'];
        }
        foreach ($columns as $key => $col) {
            $record[type_label($col['type']) . '_' . $col['label']] = $sensor_map[$key] ?? null;
        }
        $records[] = $record;
    }

    echo json_encode([
        'station_id'   => $data['station_id'],
        'station_name' => $data['name'],
        'region'       => $data['region'],
        'cached_at'    => $data['cached_at'],
        'downloaded_at'=> date('c'),
        'record_count' => count($records),
        'columns'      => array_map(fn($k, $c) => [
            'key'   => type_label($c['type']) . '_' . $c['label'],
            'type'  => $c['type'],
            'depth' => $c['label'],
            'unit'  => $c['unit'],
        ], array_keys($columns), $columns),
        'data'         => $records,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSV output ────────────────────────────────────────────────────────────────
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// Metadata comment rows
fputcsv($out, ['# KGS Landslide Monitoring Network — Data Export']);
fputcsv($out, ['# Station',    $data['name']]);
fputcsv($out, ['# Station ID', $data['station_id']]);
fputcsv($out, ['# Region',     $data['region']]);
fputcsv($out, ['# Lat/Lng',    $data['lat'] . ', ' . $data['lng']]);
fputcsv($out, ['# Cached at',  $data['cached_at']]);
fputcsv($out, ['# Downloaded', date('c')]);
fputcsv($out, ['# Records',    count($data['history'])]);
fputcsv($out, ['# Data is provisional — Kentucky Geological Survey']);
fputcsv($out, ['#']);

// Column header row
$headers = ['date', 'time', 'station_id', 'station_name', 'region'];
foreach ($columns as $col) {
    $headers[] = type_label($col['type']) . '_' . $col['label'];
}
fputcsv($out, $headers);

// Unit row
$units = ['', '', '', '', ''];
foreach ($columns as $col) {
    $units[] = $col['unit'];
}
fputcsv($out, $units);

// Data rows — full 15-minute resolution
foreach ($data['history'] as $row) {
    $line = [
        substr($row['datetime'], 0, 10),
        substr($row['datetime'], 11, 8),
        $data['station_id'],
        $data['name'],
        $data['region'],
    ];

    // Index sensors for this row
    $sensor_map = [];
    foreach ($row['sensors'] ?? [] as $s) {
        $sensor_map[$s['type'] . '_port' . $s['port']] = $s['value'];
    }

    foreach ($columns as $key => $col) {
        $line[] = $sensor_map[$key] ?? '';
    }

    fputcsv($out, $line);
}

fclose($out);