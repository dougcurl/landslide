<?php
/**
 * config.example.php  —  Slope Hydrologic Monitoring Network
 * ─────────────────────────────────────────────────────────────────────────────
 * TEMPLATE. Copy this file to `config.php` and fill in real values:
 *
 *     copy config.example.php config.php      (Windows)
 *     cp   config.example.php config.php       (bash)
 *
 * `config.php` holds your API token and is intentionally excluded from git
 * (see .gitignore). Never commit it. This example carries only placeholders
 * and is safe to keep in the repo.
 *
 * Most of the STATIONS array can be generated for you by api/setup_helper.php
 * from your device serial numbers — see the README "Setup" section. The fields
 * the v5 API cannot supply (region, depth_cm, label, vwc_max, site_info) must
 * be filled in by hand from your field records.
 */

/* ─── API credentials ──────────────────────────────────────────────────────── */

// Your Zentra Cloud 2.0 (v5) API token, from app.zentracloud.io → Profile →
// Integrations. This is the ONLY place the token belongs. v5 tokens are NOT
// interchangeable with the older v4 tokens from zentracloud.com.
define('ZENTRA_API_TOKEN', 'PASTE-YOUR-V5-TOKEN-HERE');

// v5 API base URL. Trailing slash required.
define('ZENTRA_API_BASE', 'https://api.zentracloud.io/');

/* ─── Data window ──────────────────────────────────────────────────────────── */

// Days of history to fetch and cache per station. 14 days spans at most two
// paginated calendar-month windows per station.
define('HISTORY_DAYS', 14);

/* ─── Site branding (shown in the header and <title>) ──────────────────────── */

define('SITE_NAME', 'Slope Hydrologic Monitoring Network');
define('SITE_ORG',  'Your Organization');

/* ─── Cache ────────────────────────────────────────────────────────────────── */

// Directory for the flat-file JSON cache. Trailing slash required. Must be
// writable by the IIS app pool account AND by the Task Scheduler account that
// runs refresh_cache.php. This folder is blocked from direct web access in
// web.config.
define('CACHE_DIR', __DIR__ . '/cache/');

// Serve the cached station summary for this many seconds before triggering a
// background refresh. ~45 min matches the "provisional data updated
// approximately every 45 minutes" note shown in the header.
define('CACHE_TTL_SUMMARY', 45 * 60);

/* ─── Station registry ─────────────────────────────────────────────────────── */
/*
 * One entry per monitoring station. Two illustrative examples below:
 *   • a soil-only station (TEROS 12 at three depths + a TEROS 21 matric sensor)
 *   • a station that also carries an ATMOS 41 weather sensor
 *
 * STATION FIELDS
 *   id         Device serial, format z6-XXXXX (app.zentracloud.io → Devices).
 *   name       Display name. Leave '' to fall back to the API's device_name.
 *   region     Descriptive label, e.g. 'Eastern KY'.  (Manual — not from API.)
 *   lat / lng  Decimal degrees. Set to 0 to let the API's coordinates fill in
 *              on the first refresh; a non-zero config value always wins.
 *   ports[]    One entry per sensor port/measurement you want charted.
 *   site_info  Optional site metadata for the info panel and GeoJSON export.
 *
 * PORT FIELDS
 *   port       Integer port number on the logger.
 *   type       One of: 'soil_moisture', 'matric_potential', 'soil_temp',
 *              'precipitation', 'air_temp', 'humidity'
 *   depth_cm   Install depth in cm for soil sensors. null for weather sensors.
 *   label      Human-readable label, e.g. '10 cm'.   (Manual — not from API.)
 *   vwc_max    Optional. Field-saturated VWC (m³/m³) for this port, used to
 *              normalize relative soil saturation. Only for 'soil_moisture'.
 */
const STATIONS = [

    // ── Example 1: soil-only station ─────────────────────────────────────────
    [
        'id'     => 'z6-00001',
        'name'   => 'Example Ridge',
        'region' => 'Eastern KY',
        'lat'    => 37.5000,
        'lng'    => -83.2000,
        'ports'  => [
            ['port' => 1, 'type' => 'soil_moisture',    'depth_cm' => 10, 'label' => '10 cm', 'vwc_max' => 0.45],
            ['port' => 2, 'type' => 'soil_moisture',    'depth_cm' => 30, 'label' => '30 cm', 'vwc_max' => 0.47],
            ['port' => 3, 'type' => 'soil_moisture',    'depth_cm' => 60, 'label' => '60 cm', 'vwc_max' => 0.48],
            ['port' => 3, 'type' => 'soil_temp',        'depth_cm' => 60, 'label' => '60 cm'],
            ['port' => 4, 'type' => 'matric_potential', 'depth_cm' => 30, 'label' => '30 cm'],
        ],
        'site_info' => [
            'geologic_unit'  => 'Breathitt Formation',
            'soil_unit'      => 'Shelocta silt loam',
            'elevation_m'    => 380,
            'slope_deg'      => 22,
            'susceptibility' => 'High',        // Very Low | Low | Moderate | High | Very High
            'sensor_depths'  => '10, 30, 60 cm',
            'date_installed' => '2024-05-01',
            'collaborator'   => 'Partner name',
            'image'          => 'img/stations/z6-00001.jpg',
        ],
    ],

    // ── Example 2: soil + weather station (ATMOS 41) ─────────────────────────
    [
        'id'     => 'z6-00002',
        'name'   => 'Example Hollow',
        'region' => 'Eastern KY',
        'lat'    => 0,   // 0 → filled from API metadata on first refresh
        'lng'    => 0,
        'ports'  => [
            ['port' => 1, 'type' => 'soil_moisture', 'depth_cm' => 15,   'label' => '15 cm', 'vwc_max' => 0.44],
            ['port' => 2, 'type' => 'soil_moisture', 'depth_cm' => 45,   'label' => '45 cm', 'vwc_max' => 0.46],
            // ATMOS 41 weather sensor — no install depth
            ['port' => 5, 'type' => 'precipitation', 'depth_cm' => null, 'label' => 'Precip'],
            ['port' => 5, 'type' => 'air_temp',      'depth_cm' => null, 'label' => 'Air Temp'],
            ['port' => 5, 'type' => 'humidity',      'depth_cm' => null, 'label' => 'Humidity'],
        ],
        // site_info is optional — omit the whole block if you don't have it.
    ],

];