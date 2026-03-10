# KGS Soil Moisture Monitoring Network

Interactive web map displaying real-time soil moisture data from ~25 Zentra Cloud monitoring stations across Kentucky.

## Stack
- **Frontend**: ArcGIS JS SDK 4.x, Chart.js, custom CSS
- **Backend**: PHP 8.x on IIS (Windows Server)
- **Data Source**: Zentra Cloud API v4
- **Caching**: PHP flat-file JSON (in `/cache/`)

---

## Setup

### 1. Deploy Files
Copy the entire `soilmoisture/` folder contents to:
```
\\kgsgarnet\webshare\kygeode\services\landslide\
```
(or deploy directly into that directory — this IS the deployment directory)

### 2. Configure `config.php`
Edit `config.php` and fill in:
- `ZENTRA_API_TOKEN` — your Zentra Cloud API token
  (Zentra Cloud → API menu → Keys tab → Copy token)
- `STATIONS` array — add all your real station serial numbers, names, lat/lng, and port configs

### 3. Create Cache Folder
The `cache/` subfolder needs to exist and be writable by the IIS app pool account.
Since `\\kgsgarnet\webshare\kygeode\services\landslide\` already has write access
from the web user account, just create the subfolder:
```
mkdir \\kgsgarnet\webshare\kygeode\services\landslide\cache
```
If you run into write errors, verify the IIS app pool identity has access to the share.
You can check/grant this from the web server:
```
icacls \\kgsgarnet\webshare\kygeode\services\landslide\cache /grant "IIS_IUSRS:(OI)(CI)F"
```

### 4. Initial Cache Population
Run this once to populate the cache before the map loads:
```
C:\php\php.exe \\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php
```
This will take ~30 seconds for 25 stations (Zentra rate limit: 1 call/device/min).

### 5. Task Scheduler (Auto-Refresh)
Set up Windows Task Scheduler to run `refresh_cache.bat` every 15 minutes:
1. Open Task Scheduler → Create Basic Task
2. Name: "KGS Soil Moisture Cache Refresh"
3. Trigger: Daily → repeat every 15 minutes, indefinitely
4. Action: Start a program
   - Program: `C:\php\php.exe`
   - Arguments: `"\\kgsgarnet\webshare\kygeode\services\landslide\api\refresh_cache.php"`
5. Run whether user is logged on or not; Run with highest privileges
6. **Important**: The task must run as an account that has access to the `\\kgsgarnet\webshare\` share — typically the same service account used by IIS, or a domain account with share access.

---

## Configuring Stations

In `config.php`, each station entry looks like:
```php
[
    'id'     => 'z6-XXXXX',     // Zentra device serial number
    'name'   => 'Station Name',
    'lat'    => 37.9716,
    'lng'    => -84.4747,
    'region' => 'Central KY',
    'ports'  => [
        ['port' => 1, 'label' => '10 cm',  'depth_cm' => 10,  'type' => 'soil_moisture'],
        ['port' => 2, 'label' => '30 cm',  'depth_cm' => 30,  'type' => 'soil_moisture'],
        ['port' => 3, 'label' => '10 cm',  'depth_cm' => 10,  'type' => 'matric_potential'],
        ['port' => 4, 'label' => 'Soil Temp', 'depth_cm' => 10, 'type' => 'soil_temp'],
    ],
],
```

**Sensor types**: `soil_moisture` | `matric_potential` | `soil_temp`

Ports not listed here (e.g. atmospheric pressure, precip) are auto-detected from the Zentra sensor name.

---

## API Endpoints

| Endpoint | Description |
|---|---|
| `api/get_stations.php` | All stations + latest moisture (map markers) |
| `api/get_station_data.php?id=z6-XXXXX` | Full 14-day history for one station |
| `api/refresh_cache.php` | Trigger manual cache refresh (all stations) |
| `api/refresh_cache.php?station=z6-XXXXX` | Refresh one station |

---

## Troubleshooting

**Map markers not appearing**: Check browser console. Run `api/get_stations.php` directly to see the JSON response.

**"Data not yet available"**: Cache hasn't been populated yet. Run `refresh_cache.php` manually.

**Zentra API errors**: Verify your token in `config.php`. Check Zentra Cloud → API → Keys. Token format should be a long alphanumeric string (no "Token " prefix needed in config).

**IIS write errors**: Make sure `IIS_IUSRS` has write permission on the `cache/` folder.

**Rate limit errors**: Zentra limits 60 calls/min and 1 call/device/min. With 25 stations, full refresh takes ~25+ seconds — this is expected.
