# KGS Slope Hydrologic Monitoring Network

Interactive web map displaying real-time soil moisture data from 24 Zentra Cloud 2.0 monitoring stations across Kentucky. Click any station marker to view 14-day sensor history, multi-depth charts, and live NEXRAD radar overlay.

## Stack

- **Frontend**: ArcGIS JS SDK 4.x, Chart.js 4.x, custom CSS (dark earthy theme)
- **Backend**: PHP 8.x on IIS (Windows Server)
- **Data source**: Zentra Cloud 2.0 API v5 (`api.zentracloud.io`)
- **Caching**: PHP flat-file JSON in `cache/` subfolder
- **Deployment path**: `\\[server-name-and-directory]\slope-monitoring\`

---

## File Reference

| File | Purpose |
|------|---------|
| `index.php` | Main map page (HTML shell + ArcGIS init) |
| `config.php` | API token, station registry, cache settings |
| `api/zentra_v5.php` | Shared v5 API helper — HTTP, parsing, sensor type detection |
| `api/refresh_cache.php` | Fetches Zentra data, writes per-station + summary JSON cache |
| `api/get_stations.php` | Map endpoint — returns all stations with latest moisture |
| `api/get_station_data.php` | Panel endpoint — returns 14-day history for one station |
| `api/setup_helper.php` | One-time setup utility — fetches sensor configs from serial numbers |
| `css/style.css` | Styles |
| `js/app.js` | ArcGIS map, markers, NEXRAD radar, detail panel, Chart.js charts |
| `web.config` | IIS configuration (PHP handler, MIME types, cache folder blocked) |
| `refresh_cache.bat` | Windows Task Scheduler batch script |

---

## Setup

### 1. Deploy files
Copy everything to:
```
\\[server-name-and-directory]\slope-monitoring\
```

### 2. Get your API token
- Log in to **app.zentracloud.io**
- Go to **Profile → Integrations**
- Copy your API token
- If you don't see an Integrations page, request access via the form linked in the [Zentra v5 docs](https://docs.zentracloud.com/l/en/article/zjky832943-api-v5)

> **Note:** v5 tokens are separate from v4 tokens. They live at `app.zentracloud.io`, not `zentracloud.com`.

### 3. Configure `config.php`
Open `config.php` and paste your token:
```php
define('ZENTRA_API_TOKEN', 'your-token-here');
```
This is the **only** file that needs the token. Do not put it anywhere else.

### 4. Create the cache folder
```
mkdir \\[server-name-and-directory]\slope-monitoring\cache
```
Make sure the the IIS app pool account has write access to this share.

### 5. Run the setup helper
Browse to:
```
/api/setup_helper.php?key=kgs-setup-2024
```

Enter your device serial numbers (one per line) in the **Enter Serial Numbers** tab. Find serial numbers in `app.zentracloud.io → Devices`. Format: `z6-XXXXX`.

The helper will call the v5 API for each device, detect sensor ports and measurement types, and generate a ready-to-paste `STATIONS` array for `config.php`.

**After pasting the generated array into `config.php`**, fill in the following for each station and port — the v5 API does not provide this information:
- `'region'` — descriptive region label, e.g. `'Eastern KY'`
- `'depth_cm'` — sensor installation depth in cm (from your field records)
- `'label'` — human-readable depth label, e.g. `'10 cm'`

**Delete `setup_helper.php` when done** — it is a one-time tool.

### 6. Run an initial cache population
With stations configured, run the cache refresh once manually before setting up the scheduler. This takes several minutes due to v5 rate limits (~62 seconds between stations after the first 5):
```
C:\php\php.exe \\[server-name-and-directory]\slope-monitoring\api\refresh_cache.php
```

### 7. Set up Task Scheduler
Schedule `refresh_cache.bat` to run every 15 minutes. See the comments inside that file for full Task Scheduler setup instructions.

> **Important:** The task must run as a **domain account** with access to `\\kgsgarnet\webshare\`. Running as `SYSTEM` or `Local Service` will fail — those accounts cannot reach network shares.

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

**Rate limiting**: GCRA algorithm — burst of 5 requests, then 1 request per minute steady-state. With 25 stations, a full refresh takes approximately 25–30 minutes. The refresh script sorts stations by staleness (oldest cache first) so every 15-minute scheduler run makes useful progress even if it can't complete a full cycle.

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
The helper samples the last 2 hours of data. If the station hasn't reported recently there will be no readings to inspect. Add the port config manually in `config.php` using the sensor model and depth from your field records. Use `detect_sensor_type_v5()` in `zentra_v5.php` as a reference for which `type` value to use.
