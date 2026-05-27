<?php
/**
 * api/get_history_summary.php
 * Returns lightweight saturation + precipitation timeseries for all stations.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$result = [];

foreach (STATIONS as $station_cfg) {                          // ← renamed $s → $station_cfg
    $path = CACHE_DIR . $station_cfg['id'] . '.json';
    if (!file_exists($path)) continue;
    $d = json_decode(file_get_contents($path), true);
    if (!$d || empty($d['history'])) continue;

    // Build per-port vwc_max lookup
    $port_maxes = [];
    foreach ($station_cfg['ports'] as $port_num => $pcfg) {
        if (!empty($pcfg['vwc_max']) && $pcfg['vwc_max'] > 0) {
            $port_maxes[$port_num] = $pcfg['vwc_max'];
        }
    }

    // Build compact timeseries: [ [timestamp, sat_avg, precip_mm], ... ]
    // Every 4th record (~hourly resolution).
    $series  = [];
    $history = $d['history'];
    $n       = count($history);

    for ($i = 0; $i < $n; $i += 4) {
        // Saturation from the anchor row
        $anchor     = $history[$i];
        $sat_vals   = [];
        foreach ($anchor['sensors'] as $sensor) {
            if ($sensor['type'] === 'soil_moisture') {
                $port = $sensor['port'];
                if (isset($port_maxes[$port]) && $sensor['value'] !== null) {
                    $sat_vals[] = min(1.0, $sensor['value'] / $port_maxes[$port]);
                }
            }
        }
        $sat_avg = !empty($sat_vals)
            ? round(array_sum($sat_vals) / count($sat_vals), 4)
            : null;

        // Precip summed across this window (rows i through i+3)
        $precip_sum = 0.0;
        $has_precip = false;
        for ($j = $i; $j < min($i + 4, $n); $j++) {
            foreach ($history[$j]['sensors'] as $sensor) {
                if ($sensor['type'] === 'precipitation' && $sensor['value'] !== null) {
                    $precip_sum += $sensor['value'];
                    $has_precip  = true;
                }
            }
        }

        if ($sat_avg !== null || $has_precip) {
            $series[] = [
                strtotime($anchor['datetime']),
                $sat_avg,
                $has_precip ? round($precip_sum, 2) : null,
            ];
        }
    }

    if (!empty($series)) {
        $result[] = [
            'station_id' => $d['station_id'],
            'series'     => $series,   // each element: [ts, sat_avg, precip_mm]
        ];
    }
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);