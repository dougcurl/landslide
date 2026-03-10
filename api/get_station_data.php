<?php
/**
 * api/get_station_data.php?id=z6-00001
 * Returns station metadata, latest sensors, and downsampled 14-day history.
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

if (!file_exists($cache_path)) {
    // No cache at all — trigger a single-station synchronous refresh
    $php_exe        = PHP_BINARY;
    $refresh_script = __DIR__ . '/refresh_cache.php';
    exec("\"$php_exe\" \"$refresh_script\" 2>&1");
    $cache_age = file_exists($cache_path) ? (time() - filemtime($cache_path)) : PHP_INT_MAX;
} elseif ($cache_age > CACHE_TTL_HISTORY) {
    // Stale — serve existing cache and trigger background refresh for this station
    $php_exe        = PHP_BINARY;
    $refresh_script = __DIR__ . '/refresh_cache.php';
    @popen("\"$php_exe\" \"$refresh_script\" > NUL 2>&1", 'r');
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

// ── Downsample history for the panel ─────────────────────────────────────────
// Full cache has 15-min readings (~1300 rows). Chart only needs ~hourly resolution
// to show 14-day trends — keep every 4th row (= one per hour).
// This cuts response size from ~3–5 MB down to ~300–500 KB.
$full_history = $data['history'] ?? [];
$sampled = [];
foreach ($full_history as $i => $row) {
    if ($i % 4 === 0) $sampled[] = $row;
}

echo json_encode([
    'station_id'          => $data['station_id'],
    'name'                => $data['name'],
    'lat'                 => $data['lat'],
    'lng'                 => $data['lng'],
    'region'              => $data['region'],
    'location_label'      => $data['location_label'] ?? '',
    'cached_at'           => $data['cached_at'],
    'latest_datetime'     => $data['latest_datetime'],
    'latest_moisture_avg' => $data['latest_moisture_avg'],
    'latest_moisture_pct' => $data['latest_moisture_pct'],
    'latest_sensors'      => $data['latest_sensors'] ?? [],
    'history'             => $sampled,
    'cache_age_seconds'   => $cache_age,
], JSON_UNESCAPED_UNICODE);