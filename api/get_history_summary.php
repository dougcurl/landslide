<?php
/**
 * api/get_history_summary.php
 * Returns lightweight moisture timeseries for all stations — used by the
 * time slider to animate historical VWC across the map.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$result = [];

foreach (STATIONS as $s) {
    $path = CACHE_DIR . $s['id'] . '.json';
    if (!file_exists($path)) continue;
    $d = json_decode(file_get_contents($path), true);
    if (!$d || empty($d['history'])) continue;

    // Build a compact timeseries: [ [timestamp, moisture_avg], ... ]
    // Sample every 4th row (hourly) to keep payload small
    $series = [];
    $history = $d['history'];
    foreach ($history as $i => $row) {
        if ($i % 4 !== 0) continue;

        // Compute average VWC across all soil_moisture sensors in this row
        $vals = array_column(
            array_filter($row['sensors'], fn($s) => $s['type'] === 'soil_moisture'),
            'value'
        );
        $avg = !empty($vals) ? round(array_sum($vals) / count($vals), 4) : null;
        if ($avg !== null) {
            $series[] = [strtotime($row['datetime']), $avg];
        }
    }

    if (!empty($series)) {
        $result[] = [
            'station_id' => $d['station_id'],
            'series'     => $series,
        ];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);