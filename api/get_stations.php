<?php
/**
 * api/get_stations.php
 * Returns ALL stations with latest soil moisture — used to populate map markers.
 * Stations with no cache yet are returned as placeholders with null moisture.
 * Serves from cache; triggers a background refresh if cache is stale.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$summary_path = CACHE_DIR . 'stations_summary.json';
$cache_age    = file_exists($summary_path) ? (time() - filemtime($summary_path)) : PHP_INT_MAX;

// Trigger background refresh if cache is stale
if ($cache_age > CACHE_TTL_SUMMARY) {
    $php_exe        = PHP_BINARY;
    $refresh_script = __DIR__ . '/refresh_cache.php';
    @popen("\"$php_exe\" \"$refresh_script\" > NUL 2>&1", 'r');
}

// Build a lookup of cached station data — prefer summary file, but also
// read individual station cache files so stations appear as soon as
// their cache is written (without waiting for the full run to complete).
$cached = [];

// First load individual station files (always available as they're written)
foreach (STATIONS as $s) {
    $sfile = CACHE_DIR . $s['id'] . '.json';
    if (file_exists($sfile)) {
        $d = json_decode(file_get_contents($sfile), true);
        if ($d && !empty($d['station_id'])) {
            $cached[$d['station_id']] = [
                'station_id'          => $d['station_id'],
                'name'                => $d['name'],
                'lat'                 => $d['lat'],
                'lng'                 => $d['lng'],
                'region'              => $d['region'],
                'location_label'      => $d['location_label'] ?? '',
                'latest_datetime'     => $d['latest_datetime'],
                'latest_moisture_avg' => $d['latest_moisture_avg'],
                'latest_moisture_pct' => $d['latest_moisture_pct'],
                'latest_sensors'      => $d['latest_sensors'] ?? [],
                'rainfall_24h_mm'     => $d['rainfall_24h_mm'] ?? null,
            ];
        }
    }
}

// Then overlay summary file if it exists (may be slightly more recent aggregate)
if (file_exists($summary_path)) {
    $summary = json_decode(file_get_contents($summary_path), true);
    if ($summary && !empty($summary['stations'])) {
        foreach ($summary['stations'] as $s) {
            $cached[$s['station_id']] = $s;
        }
    }
}

// Merge: every station in config gets an entry, cached data wins over placeholder
$stations = [];
foreach (STATIONS as $s) {
    if (isset($cached[$s['id']])) {
        $stations[] = $cached[$s['id']];
    } else {
        // No cache yet — show marker with no data
        $stations[] = [
            'station_id'          => $s['id'],
            'name'                => $s['name'],
            'lat'                 => $s['lat'],
            'lng'                 => $s['lng'],
            'region'              => $s['region'],
            'location_label'      => '',
            'latest_datetime'     => null,
            'latest_moisture_avg' => null,
            'latest_moisture_pct' => null,
            'latest_sensors'      => [],
        ];
    }
}

// Filter out stations with no coordinates (lat/lng = 0) — can't place on map
$stations = array_values(array_filter($stations, fn($s) =>
    !empty($s['lat']) && !empty($s['lng']) &&
    ($s['lat'] != 0.0 || $s['lng'] != 0.0)
));

echo json_encode([
    'cached_at'          => $summary['cached_at'] ?? null,
    'cache_age_seconds'  => $cache_age,
    'refreshing'         => $cache_age > CACHE_TTL_SUMMARY,
    'stations'           => $stations,
    'total_in_config'    => count(STATIONS),
    'total_with_coords'  => count($stations),
]);