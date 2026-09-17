# Slope Hydrologic Monitoring Network

Interactive web map displaying real-time soil moisture data from 24 Zentra Cloud 2.0 monitoring stations across Eastern Kentucky. Click any station marker to view 14-day sensor history, multi-depth charts, and a live NEXRAD radar overlay.

Built by the Kentucky Geological Survey (KGS), University of Kentucky, in support of the CLIMBS project.

## Stack

- **Frontend**: ArcGIS JS SDK 4.x, Chart.js 4.x, custom CSS (dark earthy theme)
- **Backend**: PHP 8.x on IIS (Windows Server)
- **Data source**: Zentra Cloud 2.0 API v5 (`api.zentracloud.io`)
- **Caching**: PHP flat-file JSON in `cache/` subfolder
- **Sensors**: METER TEROS 12 / 21 / 32 and ATMOS 41

---

## File Reference

| File | Purpose |
|------|---------|
| `index.php` | Main map page (HTML shell + ArcGIS init) |
| `config.php` | API token, station registry, cache settings — **not committed** (see `config.example.php`) |
| `config.example.php` | Template for `config.php`; copy and fill in |
| `stations.php` | Stand-alone station information directory (reads config, no cache) |
| `api/zentra_v5.php` | Shared v5 API helper — HTTP, parsing, sensor type detection |
| `api/refresh_cache.php` | Fetches Zentra data, writes per-station + summary JSON cache |
| `api/get_stations.php` | Map endpoint — returns all stations with latest moisture |
| `api/get_station_data.php` | Panel endpoint — returns 14-day history for one station |
| `api/get_stations_geojson.php` | Station metadata as GeoJSON (for ArcGIS Online / hosted layers) |
| `api/download_station.php` | Streams a 14-day per-station data download (CSV or JSON) |
| `api/setup_helper.example.php` | Reference one-time setup utility — copy to `setup_helper.php`, generate a `STATIONS` array from serial numbers, then delete |
| `css/style.css` | Styles |
| `css/splash.css` | Splash / about-panel styles |
| `js/app.js` | ArcGIS map, markers, NEXRAD radar, detail panel, Chart.js charts |
| `web.config` | IIS configuration (PHP handler, MIME types, cache folder blocked) — **not committed** |
| `refresh_cache.bat` | Windows Task Scheduler batch script |

---

## Setup

### 1. Deploy files
Copy everything to your web application root (a UNC share or local path served by IIS).

### 2. Get your API token
- Log in to **app.zentracloud.io**
- Go to **Profile → Integrations**
- Copy your API token
- If you don't see an Integrations page, request access via the form linked in the [Zentra v5 docs](https://docs.zentracloud.com/l/en/article/zjky832943-api-v5)

> **Note:** v5 tokens are separate from v4 tokens. They live at `app.zentracloud.io`, not `zentracloud.com`.

### 3. Create `config.php`
Copy the template and open it:
```
copy config.example.php config.php
```
Paste your token:
```php
define('ZENTRA_API_TOKEN', 'your-token-here');
```
This is the **only** file that needs the token. Do not put it anywhere else. `config.php` is listed in `.gitignore` and must never be committed.

### 4. Create the cache folder
```
mkdir cache
```
Make sure the IIS app pool account has write access to it. (`CACHE_DIR` in `config.php` points here; the default is `<app root>/cache/`.)

### 5. Run the setup helper
The repo ships a reference helper as `api/setup_helper.example.php`. Copy it into place and set a private key:
```
copy api\setup_helper.example.php api\setup_helper.php
```
Edit the `SETUP_KEY` constant near the top of your new `setup_helper.php`, then browse to:
```
/api/setup_helper.php?key=YOUR_SETUP_KEY
```

Enter your device serial numbers, one per line. Find them in `app.zentracloud.io → Devices`. Format: `z6-XXXXX`.

The helper samples recent data for each device, detects its sensor ports and measurement types, and generates a ready-to-paste `STATIONS` array for `config.php`.

**After pasting the generated array into `config.php`**, fill in the following for each station and port — the v5 API does not provide this information:
- `'region'` — descriptive region label, e.g. `'Eastern KY'`
- `'depth_cm'` — sensor installation depth in cm (from your field records)
- `'label'` — human-readable depth label, e.g. `'10 cm'`
- `'vwc_max'` — optional field-saturated VWC per port, used for relative saturation

**Delete `setup_helper.php` when done** — it is a one-time tool. (Keep `setup_helper.example.php` in the repo; delete only the working copy from the server.)

### 6. Run an initial cache population
With stations configured, run the cache refresh once manually before setting up the scheduler. This takes several minutes due to v5 rate limits (~62 seconds between stations after the first 5):
```
C:\php\php.exe <app-root>\api\refresh_cache.php
```

### 7. Set up Task Scheduler
Schedule `refresh_cache.bat` to run every 15 minutes. See the comments inside that file for full Task Scheduler setup instructions.

> **Important:** If the app root is a network share, the task must run as a **domain account** with write access to that share. Running as `SYSTEM` or `Local Service` will fail — those accounts cannot reach network shares.

### 8. (Optional) Analytics
`index.php` includes a Google Analytics tag with the KGS measurement IDs. If you fork this, replace or remove those IDs.

---

## Configuration reference

Constants defined in `config.php` (see `config.example.php` for the full annotated template):

| Constant | Purpose |
|----------|---------|
| `ZENTRA_API_TOKEN` | v5 API token (secret) |
| `ZENTRA_API_BASE` | v5 base URL, trailing slash required |
| `HISTORY_DAYS` | Days of history fetched/cached per station |
| `SITE_NAME`, `SITE_ORG` | Header and `<title>` branding |
| `CACHE_DIR` | Flat-file cache directory, trailing slash required |
| `CACHE_TTL_SUMMARY` | Seconds to serve cached summary before a background refresh |
| `STATIONS` | Station + port registry (see template for field docs) |

---

## Zentra Cloud 2.0 API v5 Reference

**Base URL**: `https://api.zentracloud.io/`

**Auth header**: `Authorization: Bearer {token}` — note `Bearer`, not `Token` as used in v4.

**Data endpoint**: `GET /v5/devices/{device_id}/data`

| Parameter | Description |
|-----------|-------------|
| `start_datetime` | ISO 8601 datetime, e.g. `2025-03-01T00:00:00+00:00` |
| `end_datetime` | ISO 8601 datetime |
| `direction` | `ascending` or `descending` |
| `units` | `metric` (default) or `imperial` |
| `next_token` | Pagination cursor returned by previous response |

**Response structure**:
```json
{
  "metadata": {
    "device_id":   "z6-12345",
    "device_name": "Station Name",
    "location":    "Location/Field/Zone",
    "coordinates": "'37.9716', '-84.4747'"
  },
  "values": [
    {
      "port_num":    1,
      "measurement": "Water Content",
      "unit":        "m³/m³",
      "sensor_name": "TEROS 12",
      "value":       0.312,
      "timestamp":   1705485442,
      "datetime":    "2024-01-17 09:57:22-08:00",
      "error_code":  0
    }
  ],
  "pagination": {
    "num_readings": 1440,
    "next_token":   "_qCnZX4RqGUBAAAAAA==",
    "start_datetime": "...",
    "end_datetime":   "..."
  }
}
```

**Important v5 behaviors:**

- `values[]` is a **flat array** — one row per sensor reading per timestamp. Multiple ports and measurement types (Water Content, Matric Potential, Soil Temperature, etc.) all appear in the same list, distinguished by `port_num` and `measurement`. The code groups these by timestamp to build the chart timeseries.
- `error_code != 0` means the reading is bad and should be discarded. The refresh script skips these silently.
- `coordinates` is returned as a string like `"'37.97', '-84.47'"` including literal single quotes. The code parses this automatically.
- **Sensor depth is not returned by the API.** It must be entered manually in `config.php` from your field installation records.
- **There is no list-all-devices endpoint in v5.** Device serial numbers must be known in advance and entered into `config.php` manually (the setup helper assists with this).

**Pagination**: Calendar-month windows aligned to UTC. 14 days of data spans at most 2 pages. When `pagination.next_token` is non-null, pass it as the sole query parameter on the next request — it supersedes all other parameters.

**Rate limiting**: GCRA algorithm — burst of 5 requests, then 1 request per minute steady-state. With 24 stations, a full refresh takes approximately 25–30 minutes. The refresh script sorts stations by staleness (oldest cache first) so every 15-minute scheduler run makes useful progress even if it can't complete a full cycle.

---

## Data endpoints

Besides the app's own map/panel endpoints, two endpoints are useful for external consumers:

- `api/get_stations_geojson.php` — station metadata (location + `site_info`) as GeoJSON, suitable for publishing as a hosted feature layer in ArcGIS Online. Static config data only; no live values, no cache dependency.
- `api/download_station.php?id=z6-XXXXX&format=csv` — a 14-day per-station export from the cache. `format=csv` (default) or `format=json`.

---

## Troubleshooting

**Map shows no markers / loading spinner doesn't stop**
Browse directly to `api/get_stations.php` — it should return JSON. Common causes: `cache/` folder doesn't exist or isn't writable by the IIS app pool, or `refresh_cache.php` hasn't been run yet.

**"Data not yet cached" when clicking a station**
The initial cache population hasn't completed for that station. Run `refresh_cache.php` from the command line and wait. Progress is logged to `cache/refresh.log`.

**Stations show `null` moisture or appear stale**
Check `cache/refresh.log` for errors. Re-run `refresh_cache.php` manually — the scheduler will also catch up on its next run. Stale data is served while a background refresh is in progress, so the map always loads.

**429 rate limit errors in the log**
Expected behavior during large refreshes. The code retries automatically after the required wait. Persistent 429s usually mean two refresh processes are running simultaneously — ensure the Task Scheduler task doesn't overlap itself (set "If the task is already running, do not start a new instance").

**401 / 403 token errors**
The token in `config.php` is wrong or expired. Get your v5 token from `app.zentracloud.io → Profile → Integrations`. This is a different token from the v4 token at `zentracloud.com`.

**Station shows wrong name or missing GPS coordinates**
The name and coordinates come from the Zentra API (`metadata.device_name` and `metadata.coordinates`). If they're wrong in Zentra, override them directly in the `STATIONS` array in `config.php` — the config values take precedence when the API returns zeros.

**Setup helper shows no ports for a station**
The helper samples recent data. If the station hasn't reported recently there will be no readings to inspect. Add the port config manually in `config.php` using the sensor model and depth from your field records. Use `detect_sensor_type_v5()` in `zentra_v5.php` as a reference for which `type` value to use.

---

## Relative soil saturation

The dashboard's core scientific contribution is **relative soil saturation** normalization: each port's volumetric water content is divided by a field-saturated maximum (`vwc_max`, keyed by station and port in `config.php`) so that stations with different soils can be compared on a common 0–1 scale. Saturation is what the map and panels foreground; raw VWC is available in the charts and downloads.

---

## Data & disclaimer

Data is **provisional** and updated approximately every 45 minutes. It is provided for situational awareness and research and should not be the sole basis for any safety-critical decision.

## License

This repository is dual-licensed:

- **Source code** (the PHP, JavaScript, CSS, and HTML files) is licensed under the [MIT License](LICENSE-MIT).
- **Data and documentation** — the monitoring data and station metadata served or exported by the application, this README and other docs, and any figures — are licensed under the [Creative Commons Attribution 4.0 International License (CC BY 4.0)](https://creativecommons.org/licenses/by/4.0/).

Either way, please credit the Kentucky Geological Survey. See [`LICENSE`](LICENSE) for the summary, and [`LICENSE-MIT`](LICENSE-MIT) / [`LICENSE-CC-BY-4.0`](LICENSE-CC-BY-4.0) for full terms.

### How to cite

Please cite the Kentucky Geological Survey in any use, publication, or derivative that relies on this software or the data it presents:

> Kentucky Geological Survey, University of Kentucky, 2026, Slope Hydrologic Monitoring Network [software and data]: https://github.com/kgsDev/slope-monitoring;.

(Adjust the year and URL as needed once the repository is published.)

## Acknowledgements

Kentucky Geological Survey, University of Kentucky. Landslide susceptibility layer derived from lidar-based machine-learning classification across Eastern Kentucky counties. NEXRAD radar tiles courtesy of the Iowa Environmental Mesonet (Iowa State University). Kentucky APED imagery via kyraster.ky.gov.