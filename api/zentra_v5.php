<?php
/**
 * api/zentra_v5.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Shared Zentra Cloud 2.0 API v5 helper functions.
 * Included by refresh_cache.php and setup_helper.php.
 *
 * v5 Key Differences from v4:
 *   Base URL  : https://api.zentracloud.io/v5/
 *   Auth      : Authorization: Bearer {token}   (not "Token")
 *   Devices   : GET /v5/devices/                 (list all org devices!)
 *   Readings  : GET /v5/devices/{device_id}/measurements/
 *   Pagination: next_token cursor (calendar-month windows)
 *   Rate limit: GCRA — burst of 5, then 1 req/min steady-state
 */

if (!defined('ZENTRA_API_TOKEN')) {
    require_once __DIR__ . '/../config.php';
}

// ─── Core HTTP helper ─────────────────────────────────────────────────────────

/**
 * Make an authenticated GET request to the Zentra v5 API.
 * Returns decoded JSON array, or ['_error' => ..., '_status' => int] on failure.
 * Automatically retries once on 429 (rate limit) after the indicated wait.
 */
function zentra_v5_get(string $path, array $params = [], int $retry = 0): array {
    $url = ZENTRA_API_BASE . ltrim($path, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer " . ZENTRA_API_TOKEN . "\r\n" .
                               "Accept: application/json\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $raw    = @file_get_contents($url, false, $ctx);
    $status = 0;

    if (isset($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $h, $m)) {
                $status = (int)$m[1];
            }
        }
    }

    // Handle rate limit — v5 returns 429 with a Retry-After header
    if ($status === 429 && $retry === 0) {
        $wait = 60; // default 60s
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $h, $m)) {
                $wait = (int)$m[1] + 1;
                break;
            }
            // v5 GCRA also returns X-RateLimit-Reset or next_time_to_call
            if (preg_match('/^X-Next-Call-At:\s*(.+)/i', $h, $m)) {
                $next = strtotime(trim($m[1]));
                if ($next > time()) $wait = $next - time() + 1;
                break;
            }
        }
        // Check body for next_time_to_call
        if ($raw) {
            $body = json_decode($raw, true);
            if (isset($body['next_time_to_call'])) {
                $next = strtotime($body['next_time_to_call']);
                if ($next > time()) $wait = $next - time() + 1;
            }
        }
        error_log("Zentra v5 rate limited — waiting {$wait}s before retry");
        sleep(min($wait, 120)); // cap at 2 min
        return zentra_v5_get($path, $params, 1);
    }

    if ($raw === false || $status === 0) {
        return ['_error' => 'Connection failed', '_status' => 0];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return ['_error' => 'JSON parse error: ' . substr($raw, 0, 200), '_status' => $status];
    }

    if ($status >= 400) {
        $msg = $decoded['detail'] ?? $decoded['message'] ?? $decoded['error'] ?? "HTTP $status";
        return ['_error' => $msg, '_status' => $status];
    }

    return $decoded;
}

// ─── List all devices in the organization ─────────────────────────────────────

/**
 * GET /v5/devices/
 * Returns array of device objects from the authenticated user's organization.
 * v5 makes this possible — v4 didn't have a list-all endpoint.
 *
 * Each device object typically contains:
 *   device_id, name, latitude, longitude, last_seen, status, etc.
 */
function zentra_v5_list_devices(): array {
    $all     = [];
    $params  = ['direction' => 'ascending'];
    $pages   = 0;

    do {
        $data = zentra_v5_get('devices/', $params);
        if (isset($data['_error'])) return ['_error' => $data['_error']];

        $items = $data['results'] ?? $data['devices'] ?? $data['data'] ?? [];
        foreach ($items as $d) {
            $all[] = $d;
        }

        $next_token = $data['next_token'] ?? $data['next'] ?? null;
        if ($next_token) {
            $params['next_token'] = $next_token;
        }
        $pages++;
    } while ($next_token && $pages < 20);

    return $all;
}

// ─── Fetch measurements for one device ───────────────────────────────────────

/**
 * GET /v5/devices/{device_id}/measurements/
 *
 * Fetches HISTORY_DAYS worth of measurements, handling pagination.
 * v5 paginates by calendar month — 14 days spans at most 2 pages.
 *
 * Returns normalized array of timestamped sensor rows, or ['_error' => ...].
 *
 * v5 response structure (expected):
 * {
 *   "results": [
 *     {
 *       "datetime": "2025-03-01T12:00:00Z",
 *       "timestamp": 1740830400,
 *       "measurements": {
 *         "Port 1": { "sensor_name": "TEROS 12", "measurement_name": "Water Content",
 *                     "value": 0.31, "unit": "m³/m³", "error_flag": false },
 *         "Port 2": { ... },
 *         ...
 *       }
 *     }
 *   ],
 *   "next_token": "...",
 *   "device": { "device_id": "z6-12345", "name": "My Station", "latitude": 37.9, "longitude": -84.4 }
 * }
 *
 * NOTE: The exact field names are confirmed from the Swagger spec at api.zentracloud.io.
 * If Zentra changes the response shape, adjust the field mappings below.
 */
function zentra_v5_fetch_measurements(string $device_id): array {
    $end_dt   = date('c');                               // now
    $start_dt = date('c', time() - (HISTORY_DAYS * 86400));

    $all_results  = [];
    $device_meta  = null;
    $params = [
        'start_datetime' => $start_dt,
        'end_datetime'   => $end_dt,
        'direction'      => 'ascending',
        'units'          => 'metric',
    ];
    $pages = 0;

    do {
        $data = zentra_v5_get("devices/{$device_id}/measurements/", $params);

        if (isset($data['_error'])) {
            return ['_error' => $data['_error'], '_status' => $data['_status'] ?? 0];
        }

        // Capture device metadata from first page
        if ($device_meta === null) {
            $device_meta = $data['device'] ?? $data['device_info'] ?? null;
        }

        $results = $data['results'] ?? $data['data'] ?? [];
        foreach ($results as $row) {
            $all_results[] = $row;
        }

        $next_token = $data['next_token'] ?? null;
        if ($next_token) {
            $params = ['next_token' => $next_token]; // next_token supersedes other params
        }

        $pages++;
        // v5 GCRA: after burst of 5, must wait 60s between calls.
        // 14 days = at most 2 pages (2 calendar months) — usually fine in burst.
        // Sleep 1s between pages as courtesy.
        if ($next_token) sleep(1);

    } while ($next_token && $pages < 20); // 14 days = max 2 pages

    return [
        'results'     => $all_results,
        'device_meta' => $device_meta,
    ];
}

// ─── Sensor type detection ────────────────────────────────────────────────────

/**
 * Infer our internal sensor type from Zentra's sensor_name / measurement_name.
 * Returns: 'soil_moisture' | 'matric_potential' | 'soil_temp' | 'precipitation'
 *          | 'atmospheric_pressure' | 'atmospheric' | 'unknown'
 */
function detect_sensor_type_v5(string $sensor_name, string $measurement_name): string {
    $sn = strtolower($sensor_name);
    $mn = strtolower($measurement_name);

    // Soil moisture / VWC
    if (preg_match('/teros\s*(1[012]|21|31|54)|5te|5tm|ec-5|gro.?point/', $sn)) return 'soil_moisture';
    if (str_contains($mn, 'water content') || str_contains($mn, 'volumetric') ||
        str_contains($mn, 'vwc'))  return 'soil_moisture';

    // Matric / water potential
    if (preg_match('/mps[-\s]?(2|6)|teros\s*21|watermark/', $sn)) return 'matric_potential';
    if (str_contains($mn, 'matric') || str_contains($mn, 'water potential') ||
        str_contains($mn, 'suction')) return 'matric_potential';

    // Soil temperature
    if (str_contains($mn, 'soil temp') || preg_match('/tsensor|soil.?temp/i', $sn))
        return 'soil_temp';

    // Precipitation
    if (str_contains($mn, 'precip') || str_contains($mn, 'rainfall'))
        return 'precipitation';

    // Atmospheric pressure
    if (str_contains($mn, 'pressure') || str_contains($mn, 'barometric'))
        return 'atmospheric_pressure';

    // Generic atmospheric (wind, humidity, solar, etc.)
    if (preg_match('/atmos|phytos|wxt|davis|ott|41w/i', $sn)) return 'atmospheric';

    return 'unknown';
}

/**
 * Return the display unit string for a sensor type.
 */
function get_unit_v5(string $type): string {
    return match($type) {
        'soil_moisture'        => 'm³/m³',
        'matric_potential'     => 'kPa',
        'soil_temp'            => '°C',
        'precipitation'        => 'mm',
        'atmospheric_pressure' => 'kPa',
        default                => '',
    };
}