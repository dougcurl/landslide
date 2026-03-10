<?php
/**
 * api/refresh_cache.php
 * Fetches latest data from Zentra Cloud v4 API and writes JSON cache files.
 * 
 * Can be called:
 *   - By Windows Task Scheduler every 15 minutes (recommended)
 *   - On-demand by get_station_data.php when cache is stale
 *   - Directly in browser with ?station=z6-00001 to refresh one station
 *   - Directly in browser with no params to refresh all stations
 *
 * Rate limit: Zentra allows 60 calls/min, 1 call/device/min.
 * With 25 stations, a full refresh takes ~25 seconds minimum.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Make authenticated GET request to Zentra API v4
 */
function zentra_get(string $endpoint, array $params = []): ?array {
    $url = ZENTRA_API_BASE . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Token ' . ZENTRA_API_TOKEN . "\r\n" .
                         'Content-Type: application/json' . "\r\n",
            'timeout' => 30,
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $response = @file_get_contents($url, false, $ctx);
    if ($response === false) {
        error_log("Zentra API error for $url");
        return null;
    }
    return json_decode($response, true);
}

/**
 * Fetch all readings for a device over the past HISTORY_DAYS days.
 * Handles pagination (Zentra returns max 2000 records per page).
 */
function fetch_device_readings(string $device_sn): ?array {
    $end_time   = time();
    $start_time = $end_time - (HISTORY_DAYS * 86400);

    $all_readings = [];
    $page = 1;

    do {
        $data = zentra_get('get_readings/', [
            'device_sn'     => $device_sn,
            'start_date'    => date('c', $start_time),
            'end_date'      => date('c', $end_time),
            'output_format' => 'json',
            'per_page'      => 2000,
            'page_num'      => $page,
            'sort_by'       => 'asc',
        ]);

        if ($data === null || isset($data['error'])) {
            return null;
        }

        // Zentra v4 response structure: data -> [device_sn] -> [port_name] -> [readings]
        if ($page === 1) {
            $all_readings = $data;
        } else {
            // Merge paginated port readings
            if (isset($data['data'])) {
                foreach ($data['data'] as $sn => $ports) {
                    foreach ($ports as $port_name => $port_data) {
                        if (isset($all_readings['data'][$sn][$port_name]['readings'])) {
                            $all_readings['data'][$sn][$port_name]['readings'] = array_merge(
                                $all_readings['data'][$sn][$port_name]['readings'],
                                $port_data['readings'] ?? []
                            );
                        }
                    }
                }
            }
        }

        $total_pages = $data['pagination']['total_pages'] ?? 1;
        $page++;
        
        // Respect rate limit if paginating
        if ($page <= $total_pages) {
            sleep(1);
        }

    } while ($page <= $total_pages);

    return $all_readings;
}

/**
 * Parse raw Zentra readings into our normalized cache format.
 * 
 * Zentra v4 response data structure:
 * data -> {device_sn} -> {port_name (e.g. "Port 1")} -> {
 *   sensor_sn, sensor_name, readings: [{datetime_utc, value, error}]
 * }
 */
function normalize_station_data(array $station, array $raw): array {
    $device_sn = $station['id'];
    $port_map  = [];
    foreach ($station['ports'] as $p) {
        $port_map['Port ' . $p['port']] = $p;
    }

    $device_data = $raw['data'][$device_sn] ?? [];

    // Build timeseries: indexed by UTC timestamp
    $timeseries = [];
    $latest_moisture_values = []; // for summary: collect last soil_moisture readings

    foreach ($device_data as $port_name => $port_info) {
        $port_cfg = $port_map[$port_name] ?? null;
        if (!$port_cfg) continue;

        $sensor_label = $port_cfg['label'];
        $sensor_type  = $port_cfg['type'];
        $depth_cm     = $port_cfg['depth_cm'];

        foreach (($port_info['readings'] ?? []) as $reading) {
            $ts  = $reading['datetime_utc'] ?? $reading['timestamp_utc'] ?? null;
            $val = $reading['value'] ?? null;

            if ($ts === null || $val === null || isset($reading['error'])) continue;

            // Normalize timestamp to ISO string
            if (is_numeric($ts)) {
                $ts_iso = date('c', (int)$ts);
            } else {
                $ts_iso = $ts;
                $ts     = strtotime($ts);
            }

            if (!isset($timeseries[$ts])) {
                $timeseries[$ts] = ['datetime' => $ts_iso, 'sensors' => []];
            }

            $timeseries[$ts]['sensors'][$port_name] = [
                'port'       => $port_cfg['port'],
                'label'      => $sensor_label,
                'type'       => $sensor_type,
                'depth_cm'   => $depth_cm,
                'value'      => round((float)$val, 4),
                'unit'       => get_unit($sensor_type),
            ];

            // Track latest moisture reading per depth for map marker display
            if ($sensor_type === 'soil_moisture') {
                $key = 'port_' . $port_cfg['port'];
                if (!isset($latest_moisture_values[$key]) ||
                    $ts > $latest_moisture_values[$key]['ts']) {
                    $latest_moisture_values[$key] = [
                        'ts'       => $ts,
                        'label'    => $sensor_label,
                        'depth_cm' => $depth_cm,
                        'value'    => round((float)$val, 4),
                    ];
                }
            }
        }
    }

    // Also parse precip and atm pressure from any ATM/weather ports
    // These are identified by sensor_name containing "Precip" or "Pressure"
    foreach ($device_data as $port_name => $port_info) {
        $sensor_name = strtolower($port_info['sensor_name'] ?? '');
        if (strpos($sensor_name, 'precip') !== false ||
            strpos($sensor_name, 'pressure') !== false ||
            strpos($sensor_name, 'atmos') !== false) {
            // Already in timeseries if port is configured; skip if not in port_map
            // (atmospheric sensors may be auto-detected separately)
        }
    }

    // Sort timeseries chronologically
    ksort($timeseries);
    $history = array_values($timeseries);

    // Compute average soil moisture across all depths (last reading each port)
    $moisture_avg = null;
    if (!empty($latest_moisture_values)) {
        $vals = array_column($latest_moisture_values, 'value');
        $moisture_avg = round(array_sum($vals) / count($vals), 4);
    }

    // Latest reading timestamp
    $latest_ts  = !empty($history) ? end($history)['datetime'] : null;
    $latest_row = !empty($history) ? end($history)['sensors']  : [];

    return [
        'station_id'          => $station['id'],
        'name'                => $station['name'],
        'lat'                 => $station['lat'],
        'lng'                 => $station['lng'],
        'region'              => $station['region'],
        'cached_at'           => date('c'),
        'latest_datetime'     => $latest_ts,
        'latest_moisture_avg' => $moisture_avg,  // m³/m³, used for map marker color
        'latest_moisture_pct' => $moisture_avg !== null ? round($moisture_avg * 100, 1) : null,
        'latest_sensors'      => array_values($latest_row),
        'port_config'         => $station['ports'],
        'history'             => $history,
    ];
}

function get_unit(string $type): string {
    return match($type) {
        'soil_moisture'    => 'm³/m³',
        'matric_potential' => 'kPa',
        'soil_temp'        => '°C',
        default            => '',
    };
}

/**
 * Write a station cache file
 */
function write_cache(string $station_id, array $data): bool {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    $path = CACHE_DIR . $station_id . '.json';
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Write the stations summary cache (used for fast map load)
 */
function write_summary_cache(array $all_stations): bool {
    $summary = array_map(function($s) {
        return [
            'station_id'          => $s['station_id'],
            'name'                => $s['name'],
            'lat'                 => $s['lat'],
            'lng'                 => $s['lng'],
            'region'              => $s['region'],
            'latest_datetime'     => $s['latest_datetime'],
            'latest_moisture_avg' => $s['latest_moisture_avg'],
            'latest_moisture_pct' => $s['latest_moisture_pct'],
            'latest_sensors'      => $s['latest_sensors'],
        ];
    }, $all_stations);

    return file_put_contents(
        CACHE_DIR . 'stations_summary.json',
        json_encode(['cached_at' => date('c'), 'stations' => $summary], JSON_PRETTY_PRINT)
    ) !== false;
}

// ─── Main Execution ───────────────────────────────────────────────────────────

$target_station = $_GET['station'] ?? null;
$results = [];

$stations_to_refresh = $target_station
    ? array_filter(STATIONS, fn($s) => $s['id'] === $target_station)
    : STATIONS;

foreach ($stations_to_refresh as $station) {
    $sn = $station['id'];
    echo json_encode(['status' => 'fetching', 'station' => $sn]) . "\n";
    flush();

    $raw = fetch_device_readings($sn);
    if ($raw === null) {
        $results[] = ['station' => $sn, 'status' => 'error', 'message' => 'API call failed'];
        continue;
    }

    $normalized = normalize_station_data($station, $raw);
    $ok = write_cache($sn, $normalized);

    $results[] = [
        'station'          => $sn,
        'status'           => $ok ? 'ok' : 'write_error',
        'records'          => count($normalized['history']),
        'latest_moisture'  => $normalized['latest_moisture_pct'],
    ];

    // Respect per-device rate limit
    if (count($stations_to_refresh) > 1) {
        sleep(1);
    }
}

// Rebuild summary cache from all existing station caches
if (!$target_station) {
    $all = [];
    foreach (STATIONS as $s) {
        $path = CACHE_DIR . $s['id'] . '.json';
        if (file_exists($path)) {
            $d = json_decode(file_get_contents($path), true);
            if ($d) $all[] = $d;
        }
    }
    write_summary_cache($all);
}

echo json_encode(['done' => true, 'results' => $results]);
