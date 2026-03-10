<?php
/**
 * api/get_stations.php
 * Returns all stations with latest soil moisture — used to populate map markers.
 * Serves from cache; triggers a background refresh if cache is stale.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$summary_path = CACHE_DIR . 'stations_summary.json';

// Check if cache exists and is fresh
$cache_age = file_exists($summary_path) ? (time() - filemtime($summary_path)) : PHP_INT_MAX;

if ($cache_age > CACHE_TTL_SUMMARY) {
    // Cache is stale — trigger a background refresh (non-blocking on IIS/Windows)
    // This uses popen to fire the refresh as a background process
    $php_exe = PHP_BINARY;
    $refresh_script = __DIR__ . '/refresh_cache.php';
    
    // On Windows IIS:
    $cmd = "\"$php_exe\" \"$refresh_script\" > NUL 2>&1";
    popen($cmd, 'r');
    
    // If no cache exists at all, we have to wait for it
    if (!file_exists($summary_path)) {
        // Synchronous fallback: build a minimal response from config
        $minimal = [];
        foreach (STATIONS as $s) {
            $station_cache = CACHE_DIR . $s['id'] . '.json';
            if (file_exists($station_cache)) {
                $d = json_decode(file_get_contents($station_cache), true);
                if ($d) {
                    $minimal[] = [
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
            } else {
                // No cache at all for this station — return placeholder
                $minimal[] = [
                    'station_id'          => $s['id'],
                    'name'                => $s['name'],
                    'lat'                 => $s['lat'],
                    'lng'                 => $s['lng'],
                    'region'              => $s['region'],
                    'latest_datetime'     => null,
                    'latest_moisture_avg' => null,
                    'latest_moisture_pct' => null,
                    'latest_sensors'      => [],
                ];
            }
        }
        echo json_encode([
            'cached_at' => null,
            'stations'  => $minimal,
            'refreshing' => true,
        ]);
        exit;
    }
}

// Serve from cache
$data = json_decode(file_get_contents($summary_path), true);
if (!$data) {
    http_response_code(500);
    echo json_encode(['error' => 'Cache read error']);
    exit;
}

$data['cache_age_seconds'] = $cache_age;
echo json_encode($data);
