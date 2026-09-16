<?php
/**
 * api/get_stations_geojson.php
 * Returns station metadata (location + site info) as GeoJSON, for
 * consumption as a hosted feature layer / web layer in ArcGIS Online.
 * Static config data only — no live Zentra values, no cache dependency.
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/geo+json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache');

$features = [];

foreach (STATIONS as $s) {
    // Skip anything without real coordinates — can't map it
    if (empty($s['lat']) || empty($s['lng']) || ($s['lat'] == 0.0 && $s['lng'] == 0.0)) {
        continue;
    }

    $info = $s['site_info'] ?? [];

    $features[] = [
        'type'       => 'Feature',
        'geometry'   => [
            'type'        => 'Point',
            'coordinates' => [$s['lng'], $s['lat']], // GeoJSON is [lng, lat]
        ],
        'properties' => [
            'station_id'      => $s['id'],
            'name'            => $s['name'],
            'region'          => $s['region'],
            'geologic_unit'   => $info['geologic_unit']  ?? null,
            'soil_unit'       => $info['soil_unit']      ?? null,
            'elevation_m'     => $info['elevation_m']    ?? null,
            'slope_deg'       => $info['slope_deg']      ?? null,
            'susceptibility'  => $info['susceptibility'] ?? null,
            'sensor_depths'   => $info['sensor_depths']  ?? null,
            'date_installed'  => $info['date_installed'] ?? null,
            'collaborator'    => $info['collaborator']   ?? null,
            'image'           => $info['image']          ?? null,
        ],
    ];
}

echo json_encode([
    'type'     => 'FeatureCollection',
    'features' => $features,
], JSON_UNESCAPED_SLASHES);