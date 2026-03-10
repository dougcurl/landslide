<?php
/**
 * api/zentra_v5.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Shared Zentra Cloud 2.0 API v5 helper functions.
 * Based on confirmed Swagger spec (OAS 3.1 v0.0.1) from api.zentracloud.io.
 *
 * CONFIRMED v5 facts (from Swagger):
 *   Base URL    : https://api.zentracloud.io/
 *   Auth        : Authorization: Bearer {token}
 *   Data        : GET /v5/devices/{device_id}/data
 *   No list     : There is NO list-all-devices endpoint in v5.
 *   Rate limit  : GCRA — burst 5, then 1 req/min steady-state
 *   Pagination  : pagination.next_token; calendar-month windows
 *
 * Confirmed response for GET /v5/devices/{device_id}/data:
 * {
 *   "metadata": {
 *     "device_id":   "z6-12345",
 *     "device_name": "North Device",
 *     "location":    "Location/Field/Zone",
 *     "coordinates": "'46.7515638', '-117.1667332'"   <- string, parse manually
 *   },
 *   "values": [
 *     {
 *       "port_num":    1,
 *       "measurement": "Water Content",
 *       "unit":        "m3/m3",
 *       "sensor_name": "TEROS 12",
 *       "value":       0.312,      <- null on error
 *       "timestamp":   1705485442, <- unix seconds UTC
 *       "datetime":    "2024-01-17 09:57:22-08:00",
 *       "error_code":  0           <- 0 = good reading
 *     },
 *     ...
 *   ],
 *   "pagination": {
 *     "num_readings": 1440,
 *     "next_token":   "_qCnZX4RqGUBAAAAAA==",  <- null when no more pages
 *     "start_datetime": "...",
 *     "end_datetime":   "..."
 *   }
 * }
 *
 * error_code != 0 means bad/unavailable reading. Values include:
 *   128=calibration 129=excitation 130=temp out of range 131=type unsupported
 *   132=comm error  133=sensor error 134=no response 136=outside range
 *   137=invalid     140-143=calculation/limit errors  235=erased flash
 */

if (!defined('ZENTRA_API_TOKEN')) {
    require_once __DIR__ . '/../config.php';
}

// ─── Core HTTP helper ─────────────────────────────────────────────────────────

/**
 * Make an authenticated GET request to the Zentra v5 API.
 * $path should start with 'v5/...' e.g. 'v5/devices/z6-12345/data'
 *
 * Returns decoded JSON array on success.
 * Returns ['_error' => string, '_status' => int] on any failure.
 * Retries once automatically on 429.
 */
function zentra_v5_get(string $path, array $params = [], int $retry = 0): array {
    // ZENTRA_API_BASE = 'https://api.zentracloud.io/' (trailing slash)
    $url = rtrim(ZENTRA_API_BASE, '/') . '/' . ltrim($path, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer " . ZENTRA_API_TOKEN . "\r\n"
                             . "Accept: application/json\r\n",
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);

    $raw    = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('/^HTTP\/[\d.]+ (\d{3})/', $h, $m)) {
            $status = (int)$m[1];
        }
    }

    // ── 429 Rate limit retry ──────────────────────────────────────────────────
    if ($status === 429 && $retry === 0) {
        $wait = 62; // default: steady-state 60s + buffer
        if ($raw) {
            $body = json_decode($raw, true);
            if (!empty($body['next_time_to_call'])) {
                $next = strtotime($body['next_time_to_call']);
                if ($next > time()) $wait = ($next - time()) + 2;
            }
        }
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('/^Retry-After:\s*(\d+)/i', $h, $m)) {
                $wait = max($wait, (int)$m[1] + 1);
            }
        }
        error_log("Zentra v5: 429 rate limit on {$url} — sleeping {$wait}s");
        sleep(min($wait, 180));
        return zentra_v5_get($path, $params, 1);
    }

    if ($raw === false || $status === 0) {
        return ['_error' => 'Connection failed (no HTTP response)', '_status' => 0];
    }

    $decoded = json_decode($raw, true);
    if ($decoded === null) {
        return [
            '_error'  => 'JSON parse error (' . json_last_error_msg() . '): ' . substr($raw, 0, 200),
            '_status' => $status,
        ];
    }

    if ($status >= 400) {
        // v5 validation errors: {"detail": [{"loc": [...], "msg": "...", "type": "..."}]}
        if (isset($decoded['detail']) && is_array($decoded['detail'])) {
            $msgs = array_column($decoded['detail'], 'msg');
            $msg  = implode('; ', array_filter($msgs)) ?: "HTTP $status";
        } else {
            $msg = is_string($decoded['detail'] ?? null)
                 ? $decoded['detail']
                 : ($decoded['message'] ?? $decoded['error'] ?? "HTTP $status");
        }
        return ['_error' => $msg, '_status' => $status];
    }

    return $decoded;
}

// ─── Fetch device data ────────────────────────────────────────────────────────

/**
 * Fetch HISTORY_DAYS of data for one device via GET /v5/devices/{id}/data.
 * Handles pagination automatically (14 days = at most 2 calendar-month pages).
 *
 * Returns on success:
 *   [
 *     'values'      => [ ...all ValueEntity rows across pages... ],
 *     'device_meta' => [ 'device_id', 'device_name', 'location', 'coordinates' ],
 *   ]
 * Returns on error:
 *   [ '_error' => string, '_status' => int ]
 */
function zentra_v5_fetch_data(string $device_id): array {
    // Force UTC with explicit +00:00 suffix — v5 requires both datetimes
    // to use the same timezone format. date('c') on Windows can produce
    // inconsistent offsets; gmdate gives a clean UTC string.
    $end_dt   = gmdate('Y-m-d\TH:i:s') . '+00:00';
    $start_dt = gmdate('Y-m-d\TH:i:s', time() - (HISTORY_DAYS * 86400)) . '+00:00';

    $all_values  = [];
    $device_meta = null;
    $params = [
        'start_datetime' => $start_dt,
        'end_datetime'   => $end_dt,
        'direction'      => 'ascending',
        'units'          => 'metric',
    ];
    $pages = 0;

    do {
        $data = zentra_v5_get("v5/devices/{$device_id}/data", $params);

        if (isset($data['_error'])) {
            return ['_error' => $data['_error'], '_status' => $data['_status'] ?? 0];
        }

        if ($device_meta === null && isset($data['metadata'])) {
            $device_meta = $data['metadata'];
        }

        foreach (($data['values'] ?? []) as $v) {
            $all_values[] = $v;
        }

        $next_token = $data['pagination']['next_token'] ?? null;
        if ($next_token) {
            $params = ['next_token' => $next_token]; // supersedes all other params
        }

        $pages++;
        if ($next_token) sleep(1); // courtesy pause between pages

    } while ($next_token && $pages < 5);

    return [
        'values'      => $all_values,
        'device_meta' => $device_meta,
    ];
}

// ─── Coordinate string parser ─────────────────────────────────────────────────

/**
 * v5 returns coordinates as a string like: "'46.7515638', '-117.1667332'"
 * Returns [lat, lng] as floats, or [null, null] if unparseable.
 */
function parse_coordinates_v5(?string $s): array {
    if (!$s) return [null, null];
    preg_match_all("/'?(-?\d+\.\d+)'?/", $s, $m);
    if (count($m[1]) >= 2) {
        return [(float)$m[1][0], (float)$m[1][1]];
    }
    return [null, null];
}

// ─── Sensor type detection ────────────────────────────────────────────────────

/**
 * Infer our internal sensor category from v5 sensor_name + measurement fields.
 * Returns: 'soil_moisture' | 'matric_potential' | 'soil_temp'
 *        | 'precipitation' | 'atmospheric_pressure' | 'atmospheric' | 'unknown'
 */
function detect_sensor_type_v5(string $sensor_name, string $measurement): string {
    $sn = strtolower($sensor_name);
    $mn = strtolower($measurement);

    // Soil moisture / VWC
    if (preg_match('/teros\s*(10|11|12|31|54)|5te\b|5tm\b|ec-5|gro.?point/i', $sn))
        return 'soil_moisture';
    if (str_contains($mn, 'water content') || str_contains($mn, 'volumetric') ||
        str_contains($mn, 'vwc') || str_contains($mn, 'soil moisture'))
        return 'soil_moisture';

    // Matric / water potential
    if (preg_match('/mps[-\s]?(2|6)|teros\s*21|watermark/i', $sn))
        return 'matric_potential';
    if (str_contains($mn, 'matric') || str_contains($mn, 'water potential') ||
        str_contains($mn, 'suction') || str_contains($mn, 'pore water'))
        return 'matric_potential';

    // Soil temperature (check AFTER moisture — TEROS sensors report both)
    if (str_contains($mn, 'soil temp') || str_contains($mn, 'soil temperature'))
        return 'soil_temp';
    if (preg_match('/tsensor|soil.?temp/i', $sn))
        return 'soil_temp';

    // Precipitation
    if (str_contains($mn, 'precip') || str_contains($mn, 'rainfall') ||
        str_contains($mn, 'rain '))
        return 'precipitation';

    // Atmospheric pressure
    if (str_contains($mn, 'barometric') || str_contains($mn, 'atm pressure') ||
        str_contains($mn, 'atmospheric pressure'))
        return 'atmospheric_pressure';

    // Generic atmospheric instruments — skip for soil charts
    if (preg_match('/atmos|phytos|wxt|davis|ott|41w/i', $sn))
        return 'atmospheric';

    return 'unknown';
}

function get_unit_label_v5(string $type): string {
    return match($type) {
        'soil_moisture'        => 'm³/m³',
        'matric_potential'     => 'kPa',
        'soil_temp'            => '°C',
        'precipitation'        => 'mm',
        'atmospheric_pressure' => 'kPa',
        default                => '',
    };
}