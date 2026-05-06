<?php
/**
 * api/get_station_data.php?id=z6-00001
 * Returns station metadata, latest sensors, downsampled 14-day history,
 * site_info from config, and 24-hour rainfall total.
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
$full_history = $data['history'] ?? [];
$sampled = [];
foreach ($full_history as $i => $row) {
    if ($i % 4 === 0) $sampled[] = $row;
}

// ── 24-hour rainfall total ────────────────────────────────────────────────────
// Sum all precipitation readings in the last 86400 seconds.
// Each reading in the cache represents one 15-min interval value in mm.
// We simply sum them — do NOT divide (they're already interval totals, not rates).
$cutoff_ts      = time() - 86400;
$rainfall_24h   = null;
$rainfall_count = 0;

foreach ($full_history as $row) {
    $row_ts = strtotime($row['datetime'] ?? '');
    if (!$row_ts || $row_ts < $cutoff_ts) continue;

    foreach ($row['sensors'] as $s) {
        if ($s['type'] === 'precipitation' && $s['value'] !== null) {
            $rainfall_24h = ($rainfall_24h ?? 0) + $s['value'];
            $rainfall_count++;
        }
    }
}

if ($rainfall_24h !== null) {
    $rainfall_24h = round($rainfall_24h, 2);
}

// ── site_info from config ─────────────────────────────────────────────────────
// Sourced directly from config.php so it's always current without a cache rebuild.
$site_info = $station_cfg['site_info'] ?? null;

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
    'rainfall_24h_mm'     => $rainfall_24h,    // null if no precip sensor
    'site_info'           => $site_info,        // null if not set in config
], JSON_UNESCAPED_UNICODE);