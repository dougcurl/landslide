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

    // Build per-port vwc_max lookup from station config
    $station_cfg = null;
    foreach (STATIONS as $sc) {
        if ($sc['id'] === $d['station_id']) { $station_cfg = $sc; break; }
    }
    $port_maxes = [];
    if ($station_cfg) {
        foreach ($station_cfg['ports'] as $port_num => $pcfg) {
            if (!empty($pcfg['vwc_max']) && $pcfg['vwc_max'] > 0) {
                $port_maxes[$port_num] = $pcfg['vwc_max'];
            }
        }
    }

    // Build compact saturation timeseries: [ [timestamp, sat_avg], ... ]
    // Only include every 4th record to reduce payload size (adjust as needed).
    $series = [];
    $history = $d['history'];
    foreach ($history as $i => $row) {
        if ($i % 4 !== 0) continue;

        $sat_vals = [];
        foreach ($row['sensors'] as $s) {
            if ($s['type'] !== 'soil_moisture') continue;
            $port = $s['port'];
            if (isset($port_maxes[$port]) && $s['value'] !== null) {
                $sat_vals[] = min(1.0, $s['value'] / $port_maxes[$port]);
            }
        }
        $sat_avg = !empty($sat_vals)
            ? round(array_sum($sat_vals) / count($sat_vals), 4)
            : null;

        if ($sat_avg !== null) {
            $series[] = [strtotime($row['datetime']), $sat_avg];
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