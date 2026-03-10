<?php
/**
 * api/get_station_data.php?id=z6-00001
 * Returns full 14-day history for one station.
 * Called when a user clicks a map marker to open the detail panel.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$station_id = trim($_GET['id'] ?? '');

if (!$station_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameter: id']);
    exit;
}

// Validate against registry
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
$cache_age  = file_exists($cache_path) ? (time() - filemtime($cache_path)) : PHP_INT_MAX;

if ($cache_age > CACHE_TTL_HISTORY) {
    if (!file_exists($cache_path)) {
        // No cache at all — synchronous refresh for this one station
        $php_exe        = PHP_BINARY;
        $refresh_script = __DIR__ . '/refresh_cache.php';
        exec("\"$php_exe\" \"$refresh_script\" 2>&1");
        $cache_age = file_exists($cache_path) ? (time() - filemtime($cache_path)) : PHP_INT_MAX;
    } else {
        // Stale cache exists — serve it and trigger background refresh
        $php_exe        = PHP_BINARY;
        $refresh_script = __DIR__ . '/refresh_cache.php';
        $cmd = "\"$php_exe\" \"$refresh_script\" ?station=" . escapeshellarg($station_id) . " > NUL 2>&1";
        @popen($cmd, 'r');
    }
}

if (!file_exists($cache_path)) {
    http_response_code(503);
    echo json_encode([
        'error'   => 'Station data not yet cached. Please wait a moment and try again.',
        'station' => $station_id,
    ]);
    exit;
}

$data = json_decode(file_get_contents($cache_path), true);
if (!$data) {
    http_response_code(500);
    echo json_encode(['error' => 'Cache read error for ' . $station_id]);
    exit;
}

$data['cache_age_seconds'] = $cache_age;
echo json_encode($data);