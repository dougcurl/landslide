# KGS Landslide Monitoring Network

Interactive web map displaying real-time soil moisture data from ~25 Zentra Cloud 2.0 monitoring stations across Kentucky.

## Stack
- **Frontend**: ArcGIS JS SDK 4.x, Chart.js, custom CSS
- **Backend**: PHP 8.x on IIS (Windows Server)
- **Data Source**: Zentra Cloud 2.0 API **v5** (`api.zentracloud.io`)
- **Caching**: PHP flat-file JSON (in `/cache/`)
- **Deployment**: `\\kgsgarnet\webshare\kygeode\services\landslide\`

---

## Files

| File | Purpose |
|------|---------|
| `index.php` | Main map page |
| `config.php` | API token + station registry |
| `api/zentra_v5.php` | Shared v5 API helper functions |
| `api/refresh_cache.php` | Zentra data fetcher — writes cache JSON files |
| `api/get_stations.php` | Map endpoint: all stations + latest moisture |
| `api/get_station_data.php` | Panel endpoint: 14-day history for one station |
| `api/setup_helper.php` | One-time setup utility — auto-discovers station configs |
| `css/style.css` | Styles |
| `js/app.js` | ArcGIS map, markers, radar, detail panel, charts |
| `web.config` | IIS configuration |
| `refresh_cache.bat` | Windows Task Scheduler batch script |

---

## Setup

### 1. Deploy Files
Place all files in:
```
\\kgsgarnet\webshare\kygeode\services\landslide\
```

### 2. Get Your API Token
- Log in to **app.zentracloud.io**
- Go to Profile → Integrations
- Copy your API token
- Note: v5 tokens require special access — use the request form at the Zentra docs if needed

### 3. Configure `config.php`
Paste your token into `config.php`:
```php
define('ZENTRA_API_TOKEN', 'your-token-here');
```

### 4. Run Setup Helper (auto-populate STATIONS)
Browse to:
```
http://kgs.uky.edu/landslide/api/setup_helper.php?key=kgs-setup-2024
```
Click **Auto-Discover All Stations** — it will find all devices in your Zentra organization,
detect sensor port configs, and generate a ready-to-paste PHP array for `config.php`.
Fill in the `'region'` values, then paste into `config.php`.

### 5. Create Cache Folder
```
mkdir \\kgsgarnet\webshare\kygeode\services\landslide\cache
```
The IIS app pool account already has write access to this share per your setup.

### 6. Initial Cache Population
Run once manually (takes several minutes due to v5 rate limits):
```
C:\php\php.exe \\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php
```

### 7. Task Scheduler
Set up `refresh_cache.bat` to run every 15 minutes (see comments in the bat file for full instructions). The task must run as a **domain account** with access to the `\\kgsgarnet\webshare\` share.

---

## Zentra Cloud 2.0 API v5 Notes

**Base URL**: `https://api.zentracloud.io/v5/`

**Auth**: `Authorization: Bearer {token}` (not "Token" prefix like v4)

**Key endpoints used**:
- `GET /v5/devices/` — list all devices in org (new in v5!)
- `GET /v5/devices/{device_id}/measurements/` — time-series data

**Rate limiting**: GCRA algorithm — burst of 5 requests, then 1 request per minute steady-state. With 25 stations, a full refresh takes ~25 minutes. The refresh script handles this automatically by prioritizing the most-stale stations first.

**Pagination**: Calendar-month cursor-based (`next_token`). 14 days of data = at most 2 pages.

---

## Troubleshooting

**Map shows no markers**: Check `api/get_stations.php` directly in the browser for JSON errors. Ensure cache folder is writable.

**"Data not yet cached"**: Run `refresh_cache.php` manually. With v5 rate limits, initial population takes time.

**429 Rate Limit errors**: The code handles these automatically with retries. If you see them in logs, they'll resolve on the next scheduler run.

**Token errors (401/403)**: Verify token in `config.php`. v5 tokens are different from v4 — get from `app.zentracloud.io → Profile → Integrations` (not zentracloud.com).

**Setup helper shows no devices**: Ensure your account is an Editor or Administrator of the Zentra organization that owns the devices.