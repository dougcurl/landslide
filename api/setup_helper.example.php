<?php
/**
 * setup_helper.example.php  —  Slope Hydrologic Monitoring Network
 * ─────────────────────────────────────────────────────────────────────────────
 * ONE-TIME setup utility. Reference/example version — safe to keep in the repo.
 *
 * Usage:
 *   1. Copy this file to `setup_helper.php`  (it is NOT meant to ship live).
 *   2. Set SETUP_KEY below to a private value.
 *   3. Make sure config.php already has a valid ZENTRA_API_TOKEN.
 *   4. Browse to /api/setup_helper.php?key=YOUR_SETUP_KEY
 *   5. Paste your device serial numbers (one per line), submit, and copy the
 *      generated STATIONS array into config.php.
 *   6. DELETE setup_helper.php when done.
 *
 * What it does: for each serial, it fetches a short recent window from the v5
 * API, discovers which (port, measurement) combinations the device reports,
 * maps each to the app's internal sensor type (same whitelist refresh_cache.php
 * uses), and prints a ready-to-paste STATIONS entry with the device name and
 * coordinates pre-filled.
 *
 * What it CANNOT know (fill in by hand afterward, from your field records):
 *   region, depth_cm, label, vwc_max, site_info
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/zentra_v5.php';

// ── Access gate ───────────────────────────────────────────────────────────────
// Set this to something private before copying to setup_helper.php.
const SETUP_KEY = 'change-me';

if (($_GET['key'] ?? '') !== SETUP_KEY) {
    http_response_code(403);
    exit('Forbidden. Append ?key=YOUR_SETUP_KEY to the URL.');
}

// ── How far back to sample when discovering a device's sensor roster ──────────
// Small window keeps it fast and avoids pagination. Widen if a station reports
// infrequently or has been offline.
const SAMPLE_DAYS = 2;

// ── Measurement → internal type map (mirrors MEAS_WHITELIST in refresh_cache) ──
// Only these measurements become charted ports. Everything else is ignored.
const MEAS_TO_TYPE = [
    'water content'     => 'soil_moisture',
    'matric potential'  => 'matric_potential',
    'soil temperature'  => 'soil_temp',
    'precipitation'     => 'precipitation',
    'air temperature'   => 'air_temp',
    'relative humidity' => 'humidity',
];

// Sort order for ports within a station (soil first, then weather).
const TYPE_ORDER = [
    'soil_moisture'    => 0,
    'matric_potential' => 1,
    'soil_temp'        => 2,
    'precipitation'    => 3,
    'air_temp'         => 4,
    'humidity'         => 5,
];

/**
 * Fetch a short recent window and return [metadata, values] (or throw on error).
 */
function sample_device(string $device_id): array {
    $end   = gmdate('Y-m-d\TH:i:s') . '+00:00';
    $start = gmdate('Y-m-d\TH:i:s', time() - (SAMPLE_DAYS * 86400)) . '+00:00';

    $resp = zentra_v5_get("v5/devices/{$device_id}/data", [
        'start_datetime' => $start,
        'end_datetime'   => $end,
        'direction'      => 'descending', // most recent first; one page is plenty
        'units'          => 'metric',
    ]);

    if (isset($resp['_error'])) {
        throw new RuntimeException($resp['_error'] . ' (HTTP ' . ($resp['_status'] ?? '?') . ')');
    }
    return [$resp['metadata'] ?? [], $resp['values'] ?? []];
}

/**
 * Emit a value as a single-quoted PHP string literal (properly escaped).
 */
function php_str($v): string {
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], (string)$v) . "'";
}

/**
 * Build the STATIONS array source for one device.
 */
function build_station_block(string $device_id, array $meta, array $values): string {
    // ── Discover unique (port, type) combos ───────────────────────────────────
    $ports = []; // "port:type" => ['port'=>int, 'type'=>string]
    foreach ($values as $v) {
        $port = (int)($v['port_num'] ?? 0);
        $meas = strtolower(trim($v['measurement'] ?? ''));
        if (!$port || !isset(MEAS_TO_TYPE[$meas])) continue;
        $type = MEAS_TO_TYPE[$meas];
        $ports["{$port}:{$type}"] = ['port' => $port, 'type' => $type];
    }

    // Sort by type order, then port number
    usort($ports, function ($a, $b) {
        $ta = TYPE_ORDER[$a['type']] ?? 9;
        $tb = TYPE_ORDER[$b['type']] ?? 9;
        return $ta !== $tb ? $ta <=> $tb : $a['port'] <=> $b['port'];
    });

    // ── Pre-fill name + coordinates from metadata ─────────────────────────────
    $name = $meta['device_name'] ?? '';
    [$lat, $lng] = parse_coordinates_v5($meta['coordinates'] ?? null);
    $lat = $lat ?? 0;
    $lng = $lng ?? 0;

    // ── Emit the block ────────────────────────────────────────────────────────
    $lines = [];
    $lines[] = "    [";
    $lines[] = "        'id'     => " . php_str($device_id) . ",";
    $lines[] = "        'name'   => " . php_str($name) . ",";
    $lines[] = "        'region' => '',                 // TODO: e.g. 'Eastern KY'";
    $lines[] = "        'lat'    => {$lat},";
    $lines[] = "        'lng'    => {$lng},";
    $lines[] = "        'ports'  => [";

    if (empty($ports)) {
        $lines[] = "            // No charted measurements found in the last " . SAMPLE_DAYS . " days.";
        $lines[] = "            // Station may be offline — add ports manually from field records.";
    } else {
        foreach ($ports as $p) {
            if ($p['type'] === 'soil_moisture') {
                $lines[] = sprintf(
                    "            ['port' => %d, 'type' => 'soil_moisture', 'depth_cm' => null, 'label' => '', 'vwc_max' => null], // TODO depth/label/vwc_max",
                    $p['port']
                );
            } elseif (in_array($p['type'], ['precipitation', 'air_temp', 'humidity'], true)) {
                $lines[] = sprintf(
                    "            ['port' => %d, 'type' => '%s', 'depth_cm' => null, 'label' => ''],",
                    $p['port'], $p['type']
                );
            } else { // matric_potential, soil_temp
                $lines[] = sprintf(
                    "            ['port' => %d, 'type' => '%s', 'depth_cm' => null, 'label' => ''], // TODO depth/label",
                    $p['port'], $p['type']
                );
            }
        }
    }

    $lines[] = "        ],";
    $lines[] = "        // 'site_info' => [ /* optional — see config.example.php */ ],";
    $lines[] = "    ],";

    return implode("\n", $lines);
}

// ── Handle submission ─────────────────────────────────────────────────────────
$generated = null;
$errors    = [];
$raw       = trim($_POST['serials'] ?? '');

if ($raw !== '') {
    $serials = array_values(array_filter(array_map('trim', preg_split('/\s+/', $raw))));
    $blocks  = [];

    foreach ($serials as $i => $sn) {
        // Courtesy pause after the v5 burst allowance (5) to avoid rate limiting.
        if ($i >= 5) sleep(2);
        try {
            [$meta, $values] = sample_device($sn);
            $blocks[] = build_station_block($sn, $meta, $values);
        } catch (Throwable $e) {
            $errors[] = "{$sn}: " . $e->getMessage();
        }
    }

    if ($blocks) {
        $generated = "const STATIONS = [\n\n" . implode("\n\n", $blocks) . "\n\n];";
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Setup Helper — <?= htmlspecialchars(SITE_NAME) ?></title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 820px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; }
    h1 { font-size: 1.3rem; }
    .warn { background: #fff3cd; border: 1px solid #e0c34a; padding: .75rem 1rem; border-radius: 6px; }
    textarea { width: 100%; height: 140px; font-family: ui-monospace, monospace; font-size: .9rem; padding: .5rem; box-sizing: border-box; }
    button { margin-top: .75rem; padding: .5rem 1.25rem; font-size: 1rem; cursor: pointer; }
    pre { background: #0d1117; color: #e6edf3; padding: 1rem; border-radius: 6px; overflow-x: auto; font-size: .82rem; }
    .err { color: #b00020; }
    code { background: #f0f0f0; padding: .1rem .3rem; border-radius: 3px; }
  </style>
</head>
<body>
  <h1>Setup Helper</h1>
  <p class="warn"><strong>One-time tool.</strong> Delete <code>setup_helper.php</code> from the server when you're done.</p>

  <p>Enter device serial numbers, one per line (format <code>z6-XXXXX</code>). The helper samples the last
     <?= SAMPLE_DAYS ?> day(s) of data to detect each device's ports, then generates a
     <code>STATIONS</code> array to paste into <code>config.php</code>.</p>

  <form method="post">
    <textarea name="serials" placeholder="z6-00001&#10;z6-00002&#10;z6-00003"><?= htmlspecialchars($raw) ?></textarea>
    <div><button type="submit">Generate STATIONS array</button></div>
  </form>

  <?php if ($errors): ?>
    <h2 class="err">Errors</h2>
    <ul class="err">
      <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <?php if ($generated !== null): ?>
    <h2>Generated <code>STATIONS</code> array</h2>
    <p>Paste this into <code>config.php</code>, then fill in the <code>TODO</code> fields
       (<code>region</code>, <code>depth_cm</code>, <code>label</code>, <code>vwc_max</code>)
       from your field records.</p>
    <pre><?= htmlspecialchars($generated) ?></pre>
  <?php endif; ?>
</body>
</html>