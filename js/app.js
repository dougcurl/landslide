/**
 * KGS Landslide Monitoring Network — app.js
 * ArcGIS JS SDK 4.x  |  Chart.js  |  NOAA NEXRAD radar overlay
 *
 * Chart.js loaded dynamically after ArcGIS require() to avoid Dojo AMD conflict.
 * HTML markers use ArcGIS GraphicsLayer with SVG-based PictureMarkerSymbol so
 * they render inside the ArcGIS layer stack reliably.
 */

function loadChartJS() {
  // Chart.js UMD conflicts with Dojo's AMD loader — it gets intercepted and
  // window.Chart never gets set. Fix: use a temporary alias trick to shield
  // the global define from Dojo, then restore it after loading.
  if (window._chartReady) return Promise.resolve();

  return new Promise((resolve, reject) => {
    // Temporarily disable AMD define so Chart.js registers as a global
    const savedDefine = window.define;
    window.define = undefined;

    const s1 = document.createElement("script");
    s1.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js";
    s1.onload = () => {
      const s2 = document.createElement("script");
      s2.src = "https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js";
      s2.onload = () => {
        // Restore Dojo's define now that both scripts have loaded
        window.define = savedDefine;
        window._chartReady = true;
        setTimeout(resolve, 20);
      };
      s2.onerror = () => { window.define = savedDefine; reject(new Error("adapter load failed")); };
      document.head.appendChild(s2);
    };
    s1.onerror = () => { window.define = savedDefine; reject(new Error("chart.js load failed")); };
    document.head.appendChild(s1);
  });
}

require([
  "esri/Map",
  "esri/views/MapView",
  "esri/layers/WebTileLayer",
  "esri/layers/MapImageLayer",
  "esri/layers/ImageryLayer",
  "esri/Graphic",
  "esri/layers/GraphicsLayer",
  "esri/widgets/Home",
  "esri/widgets/ScaleBar",
], function (Map, MapView, WebTileLayer, MapImageLayer, ImageryLayer, Graphic, GraphicsLayer, Home, ScaleBar) {

  // ─── State ───────────────────────────────────────────────────────────────────
  let stationsData    = [];
  let activeStationId = null;
  let radarVisible    = false;
  let radarLayer      = null;
  let charts          = {};
  let autoRefreshTimer = null;
  const AUTO_REFRESH_INTERVAL = 45 * 60 * 1000; // 45 minutes
  let timeSliderActive  = false;
  let historyData       = null;   // loaded once on first activation
  let timeSliderIndex   = null;   // null = live mode

  // ─── Map Setup ───────────────────────────────────────────────────────────────
  const map  = new Map({ basemap: "topo-vector" });
  const view = new MapView({
    container: "map",
    map,
    center: [-83.2, 37.4],
    zoom: 8,
    ui: { components: ["zoom", "compass"] },
    popup: { autoOpenEnabled: false }
  });

  view.ui.add(new Home({ view }), "top-left");
  view.ui.add(new ScaleBar({ view, unit: "dual" }), "bottom-right");

  const stationLayer = new GraphicsLayer({ id: "stations" });
  map.add(stationLayer);

// ─── Basemap Selector ────────────────────────────────────────────────────────
  let kyImageryLayer = null;

  function switchBasemap(key) {
    const opts = {
      "topo-vector": { esriId: "topo-vector", kyImagery: false }, // default ESRI topo
      "ky-imagery":  { esriId: "gray-vector", kyImagery: true  }, // ESRI gray + KY APED imagery
    };
    const opt = opts[key];
    if (!opt) return;

    // Swap ESRI basemap
    map.basemap = opt.esriId;

    // Toggle KY APED imagery overlay
    if (opt.kyImagery) {
      if (!kyImageryLayer) {
        kyImageryLayer = new ImageryLayer({
          url: "https://kyraster.ky.gov/arcgis/rest/services/ImageServices/Ky_KYAPED_Phase3_3IN_WGS84WM/ImageServer",
          id: "ky-imagery",
          opacity: 1,
        });
        map.add(kyImageryLayer, 1); // above basemap, below stations
      } else {
        kyImageryLayer.visible = true;
      }
    } else {
      if (kyImageryLayer) kyImageryLayer.visible = false;
    }

    // Update button states
    document.querySelectorAll(".basemap-btn").forEach(btn => {
      btn.classList.toggle("active", btn.dataset.basemap === key);
    });
  }

  document.querySelectorAll(".basemap-btn").forEach(btn => {
    btn.addEventListener("click", () => switchBasemap(btn.dataset.basemap));
  });

  // ─── Landslide Susceptibility Layer ────────────────────────────────────────────
  const susceptibilityLayer = new MapImageLayer({
    url: "https://kgs.uky.edu/arcgis/rest/services/Hazards/LandslideSusceptibility/MapServer",
    opacity: 0.55,
    visible: false,
    title: "Landslide Susceptibility"
  });
  map.add(susceptibilityLayer, 0); // below station markers, above basemap

  document.getElementById("susceptibility-toggle").addEventListener("click", function () {
    var isOn = !susceptibilityLayer.visible;
    susceptibilityLayer.visible = isOn;
    this.classList.toggle("active", isOn);
  });

  // ─── NEXRAD Radar ────────────────────────────────────────────────────────────
  function buildRadarLayer(opacity) {
    return new WebTileLayer({
      urlTemplate: "https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q-900913/{level}/{col}/{row}.png",
      opacity,
      id: "radar",
    });
  }

  document.getElementById("radar-toggle").addEventListener("click", function () {
    radarVisible = !radarVisible;
    this.classList.toggle("active", radarVisible);
    document.getElementById("radar-controls").classList.toggle("visible", radarVisible);
    if (radarVisible) {
      radarLayer = buildRadarLayer(parseFloat(document.getElementById("radar-opacity").value));
      map.add(radarLayer, 0);
    } else {
      if (radarLayer) { map.remove(radarLayer); radarLayer = null; }
    }
  });

  document.getElementById("radar-opacity").addEventListener("input", function () {
    if (radarLayer) radarLayer.opacity = parseFloat(this.value);
  });

  setInterval(() => {
    if (radarVisible && radarLayer) {
      const opacity = radarLayer.opacity;
      map.remove(radarLayer);
      radarLayer = buildRadarLayer(opacity);
      map.add(radarLayer, 0);
    }
  }, 5 * 60 * 1000);

  // ─── Moisture → Color ────────────────────────────────────────────────────────
  function moistureToColor(val) {
    if (val === null || val === undefined) return [74, 90, 82];
    const t = Math.max(0, Math.min(1, (val - 0.05) / 0.45));
    const stops = [
      [0.00, [107, 58,  42]],
      [0.20, [155, 90,  42]],
      [0.40, [196,129,  60]],
      [0.55, [201,168,  76]],
      [0.70, [138,181, 110]],
      [0.85, [ 93,186, 125]],
      [1.00, [ 42,122,  82]],
    ];
    for (let i = 0; i < stops.length - 1; i++) {
      const [t0, c0] = stops[i], [t1, c1] = stops[i + 1];
      if (t >= t0 && t <= t1) {
        const f = (t - t0) / (t1 - t0);
        return [
          Math.round(c0[0] + f * (c1[0] - c0[0])),
          Math.round(c0[1] + f * (c1[1] - c0[1])),
          Math.round(c0[2] + f * (c1[2] - c0[2])),
        ];
      }
    }
    return [93, 186, 125];
  }

  function colorToHex([r, g, b]) {
    return "#" + [r,g,b].map(v => v.toString(16).padStart(2,"0")).join("");
  }

  // ─── Build SVG marker (used as PictureMarkerSymbol via data URI) ─────────────
  function buildMarkerSVG(station) {
    const pct    = station.latest_moisture_pct;
    const rgb    = moistureToColor(station.latest_moisture_avg);
    const fill   = colorToHex(rgb);
    const isActive = station.station_id === activeStationId;
    const stroke  = isActive ? "#ffffff" : "rgba(0,0,0,0.45)";
    const strokeW = isActive ? 3 : 1.5;
    const label   = pct !== null ? `${pct}%` : "N/A";
    const name    = station.name.replace(/^Station \d+ — /, "");

    // ── Marker geometry — change ONLY r to resize everything ──────────────
    const r      = 38;            // bubble radius — the one knob to turn
    const pad    = 6;             // space above bubble top (for drop shadow)
    const pinH   = 14;            // pin triangle height below bubble
    const pillH  = 20;            // name pill height
    const pillGap = 4;            // gap between pin tip and pill

    // Derived positions — all flow from r
    const pillW   = Math.max(Math.min(name.length, 28) * 7.6 + 20, 70);
    const svgW   = Math.max((r + pad) * 2, pillW + 8);
    const cx     = svgW / 2;
    const cy     = r + pad;
    const pinY   = cy + r;        // bottom of bubble = tip of pin base
    const pillY  = pinY + pinH + pillGap + pillH / 2;
    const svgH   = pillY + pillH / 2 + 2;

    // Font scales with bubble
    const fontSize    = Math.round(r * 0.58);
    const subFontSize = Math.round(r * 0.22);

    // Pill is horizontally centered on the pin, which is centered on the bubble, which is centered in the SVG — so pillX depends on svgW which depends on pillW
    const pillX   = cx - pillW / 2;

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${svgW}" height="${svgH}" viewBox="0 0 ${svgW} ${svgH}">
      <defs>
        <filter id="sh" x="-40%" y="-40%" width="180%" height="180%">
          <feDropShadow dx="0" dy="1.5" stdDeviation="2.5" flood-color="rgba(0,0,0,0.6)"/>
        </filter>
        <filter id="lsh" x="-10%" y="-30%" width="120%" height="160%">
          <feDropShadow dx="0" dy="1" stdDeviation="1.5" flood-color="rgba(0,0,0,0.7)"/>
        </filter>
      </defs>
      <!-- Bubble -->
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="${fill}" stroke="${stroke}" stroke-width="${strokeW}" filter="url(#sh)"/>
      <!-- Pin -->
      <polygon points="${cx},${pinY + pinH} ${cx - 8},${pinY} ${cx + 8},${pinY}"
               fill="${fill}" stroke="${stroke}" stroke-width="${strokeW - 0.5}"/>
      <!-- VWC value -->
      <text x="${cx}" y="${cy + fontSize * 0.35}" text-anchor="middle" font-family="'Courier New',monospace"
            font-size="${fontSize}" font-weight="bold" fill="white"
            paint-order="stroke" stroke="rgba(0,0,0,0.25)" stroke-width="1">${label}</text>
      <text x="${cx}" y="${cy + fontSize * 0.72}" text-anchor="middle" font-family="'Courier New',monospace"
            font-size="${subFontSize}" fill="rgba(255,255,255,0.8)" letter-spacing="1">VWC</text>
      <!-- Name pill -->
      <rect x="${pillX}" y="${pillY - pillH/2}" width="${pillW}" height="${pillH}" rx="4"
            fill="rgba(0,0,0,0.72)" filter="url(#lsh)"/>
      <text x="${cx}" y="${pillY + 4}" text-anchor="middle" font-family="Arial,sans-serif"
            font-size="11" font-weight="600" fill="white" letter-spacing="0.3">${name.substring(0,28)}</text>
    </svg>`;
  }

  function svgToDataURI(svg) {
    return "data:image/svg+xml;base64," + btoa(unescape(encodeURIComponent(svg)));
  }

  // ─── Render Markers via GraphicsLayer ────────────────────────────────────────
  function renderMarkers(stations) {
    stationLayer.removeAll();
    stations.forEach(station => {
      const svg = buildMarkerSVG(station);
      const graphic = new Graphic({
        geometry: {
          type: "point",
          longitude: station.lng,
          latitude:  station.lat,
        },
        symbol: {
          type: "picture-marker",
          url:    svgToDataURI(svg),
          // Size must match SVG canvas: r=38 → svgW=(38+6)*2=88, svgH≈120
          // Change these if you change r in buildMarkerSVG
          width:  `${Math.max(88, Math.min(station.name.replace(/^Station \d+ — /, "").length, 28) * 7.6 + 28)}px`,
          height: "120px",
          yoffset: "46px",  // = svgH - (pinY + pinH) = distance from bottom to pin tip
        },
        attributes: { station_id: station.station_id },
      });
      stationLayer.add(graphic);
    });
  }

  // ─── Click handler on the graphics layer ─────────────────────────────────────
  view.on("click", function (event) {
    view.hitTest(event).then(function (response) {
      const hit = response.results.find(r =>
        r.graphic && r.graphic.layer === stationLayer
      );
      if (hit) {
        openPanel(hit.graphic.attributes.station_id);
      }
    });
  });

  // Change cursor on hover
  view.on("pointer-move", function (event) {
    view.hitTest(event).then(function (response) {
      const hit = response.results.find(r =>
        r.graphic && r.graphic.layer === stationLayer
      );
      view.container.style.cursor = hit ? "pointer" : "default";
    });
  });

  // ─── Load Stations ───────────────────────────────────────────────────────────
  function loadStations() {
    document.getElementById("last-updated").textContent = "Loading…";
    fetch("api/get_stations.php")
      .then(r => r.json())
      .then(data => {
        document.getElementById("map-loading").classList.add("hidden");
        stationsData = data.stations || [];
        renderMarkers(stationsData);
        if (data.cached_at) {
          document.getElementById("last-updated").textContent =
            "Soil Data Updated " + new Date(data.cached_at).toLocaleTimeString();
        }
      })
      .catch(err => console.error("Failed to load stations:", err));
  }

  function setAutoRefresh(enabled) {
    clearInterval(autoRefreshTimer);
    autoRefreshTimer = null;
    const btn = document.getElementById("autorefresh-toggle");
    btn.classList.toggle("active", enabled);
    if (enabled) {
      autoRefreshTimer = setInterval(() => {
        loadStations();
      }, AUTO_REFRESH_INTERVAL);
    }
  }

  document.getElementById("autorefresh-toggle").addEventListener("click", function () {
    setAutoRefresh(!this.classList.contains("active"));
  });

// ─── Time Slider ─────────────────────────────────────────────────────────────
  function activateTimeSlider() {
    timeSliderActive = true;
    document.getElementById("time-slider-bar").classList.add("visible");
    document.getElementById("timeslider-toggle").classList.add("active");

    if (historyData) {
      initSlider();
      return;
    }

    document.getElementById("time-slider-status").textContent = "Loading history…";
    fetch("api/get_history_summary.php")
      .then(r => r.json())
      .then(data => {
        historyData = data;
        initSlider();
      })
      .catch(err => {
        document.getElementById("time-slider-status").textContent = "Failed to load history";
        console.error("History load error:", err);
      });
  }

  function deactivateTimeSlider() {
    timeSliderActive = false;
    timeSliderIndex  = null;
    document.getElementById("time-slider-bar").classList.remove("visible");
    document.getElementById("timeslider-toggle").classList.remove("active");
    // Restore live data
    renderMarkers(stationsData);
  }

  function initSlider() {
    if (!historyData || !historyData.length) return;

    // Collect all unique timestamps across all stations, sorted ascending
    const tsSet = new Set();
    historyData.forEach(s => s.series.forEach(([ts]) => tsSet.add(ts)));
    const timestamps = Array.from(tsSet).sort((a, b) => a - b);

    const slider = document.getElementById("time-slider-input");
    slider.min   = 0;
    slider.max   = timestamps.length - 1;
    slider.value = timestamps.length - 1; // start at most recent
    window._tsTimestamps = timestamps;

    renderAtIndex(timestamps.length - 1);
  }

  function renderAtIndex(idx) {
    if (!historyData || !window._tsTimestamps) return;
    const ts = window._tsTimestamps[idx];
    const dt = new Date(ts * 1000);

    document.getElementById("time-slider-status").textContent =
      dt.toLocaleDateString(undefined, { month: "short", day: "numeric" }) + " " +
      dt.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" });

    // Build a lookup of station_id => moisture at this timestamp (nearest match)
    const moistureAt = {};
    historyData.forEach(s => {
      // Find closest timestamp entry
      let best = null, bestDiff = Infinity;
      s.series.forEach(([t, v]) => {
        const diff = Math.abs(t - ts);
        if (diff < bestDiff) { bestDiff = diff; best = v; }
      });
      if (best !== null) moistureAt[s.station_id] = best;
    });

    // Re-render markers with historical moisture values
    const historicalStations = stationsData.map(st => ({
      ...st,
      latest_moisture_avg: moistureAt[st.station_id] ?? null,
      latest_moisture_pct: moistureAt[st.station_id] != null
        ? Math.round(moistureAt[st.station_id] * 1000) / 10
        : null,
    }));
    renderMarkers(historicalStations);
  }

  document.getElementById("timeslider-toggle").addEventListener("click", function () {
    timeSliderActive ? deactivateTimeSlider() : activateTimeSlider();
  });

  document.getElementById("time-slider-input").addEventListener("input", function () {
    renderAtIndex(parseInt(this.value));
  });

  // Snap back to live when dragged fully right
  document.getElementById("time-slider-input").addEventListener("change", function () {
    if (parseInt(this.value) === parseInt(this.max)) {
      renderMarkers(stationsData);
      document.getElementById("time-slider-status").textContent = "Live";
    }
  });

  // ─── Panel ───────────────────────────────────────────────────────────────────
  function openPanel(stationId) {
    activeStationId = stationId;
    document.getElementById("detail-panel").classList.add("open");
    // Re-render markers so active one gets white ring
    renderMarkers(stationsData);
    showPanelLoading();

    loadChartJS()
      .then(() => fetch(`api/get_station_data.php?id=${encodeURIComponent(stationId)}`))
      .then(r => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      })
      .then(data => {
        if (data.error) { showPanelError(data.error); return; }
        renderPanelContent(data);
      })
      .catch(err => {
        console.error("Panel load error:", err);
        showPanelError(`Failed to load station data. (${err.message})`);
      });
  }

  function closePanel() {
    activeStationId = null;
    document.getElementById("detail-panel").classList.remove("open");
    destroyCharts();
    renderMarkers(stationsData); // re-render to remove active ring
  }

  document.getElementById("panel-close").addEventListener("click", closePanel);

  document.querySelectorAll('.panel-tab').forEach(btn => {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.panel-tab').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      this.classList.add('active');
      document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
  });

  function showPanelLoading() {
    document.getElementById("panel-header").querySelector(".panel-title").textContent = "Loading…";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent  = "";
  
    const loadingHTML = `<div id="panel-loading"><div class="spinner"></div><p>Fetching station data…</p></div>`;
    document.getElementById("tab-info").innerHTML = loadingHTML;
    document.getElementById("tab-data").innerHTML = "";
  
    // Switch to info tab while loading
    document.querySelectorAll('.panel-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelector('.panel-tab[data-tab="info"]').classList.add('active');
    document.getElementById('tab-info').classList.add('active');
  }

  function showPanelError(msg) {
    document.getElementById("panel-body").innerHTML = `<div class="panel-error">⚠ ${msg}</div>`;
  }

  // ─── Panel Content ───────────────────────────────────────────────────────────
  function renderPanelContent(data) {
    destroyCharts();
  
    // ── Header ────────────────────────────────────────────────────────────────
    document.getElementById("panel-header").querySelector(".panel-title").textContent = data.name;
    const dt = data.latest_datetime ? new Date(data.latest_datetime).toLocaleString() : "No data yet";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent =
      `${data.region}  •  Last reading: ${dt}`;
  
    // Switch to info tab first (it's the default)
    document.querySelectorAll('.panel-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelector('.panel-tab[data-tab="info"]').classList.add('active');
    document.getElementById('tab-info').classList.add('active');
  
    // ── Station Info Tab ──────────────────────────────────────────────────────
    renderInfoTab(data);
  
    // ── Sensor Data Tab ───────────────────────────────────────────────────────
    renderDataTab(data);
  }
  
  // ────────────────────────────────────────────────────────────────────────────
  // STATION INFO TAB
  // ────────────────────────────────────────────────────────────────────────────
  
  function renderInfoTab(data) {
    const si = data.site_info;
    const infoEl = document.getElementById('tab-info');
  
    // Photo or placeholder
    let photoHTML = '';
    if (si && si.image) {
      photoHTML = `<img src="img/${escHtml(si.image)}" alt="Photo of ${escHtml(data.name)}"
                        class="station-photo"
                        onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                  <div class="station-photo-placeholder" style="display:none">
                    ${cameraIcon()} No photo available
                  </div>`;
    } else {
      photoHTML = `<div class="station-photo-placeholder">
                    ${cameraIcon()} No photo available
                  </div>`;
    }
  
    // Build info rows
    let rows = '';
  
    if (si) {
      rows += infoRow('Geologic Unit',   si.geologic_unit  || '—');
      rows += infoRow('Soil Unit',       si.soil_unit      || '—');
      rows += infoRow('Elevation',       si.elevation_m != null ? si.elevation_m + ' m' : '—');
      rows += infoRow('Slope',           si.slope_deg  != null ? si.slope_deg + '°' : '—');
      rows += infoRow('Landslide Susceptibility',  susceptibilityBadge(si.susceptibility));
      rows += infoRow('Sensor Depths',   si.sensor_depths  || '—');
      rows += infoRow('Installed',       si.date_installed || '—');
      rows += infoRow('Collaborator',    si.collaborator   || '—');
    }
  
    // Coordinates always shown (from API/config)
    if (data.lat && data.lng) {
      rows += infoRow('Coordinates',
        `<span class="coords-text">${data.lat.toFixed(5)}, ${data.lng.toFixed(5)}</span>`);
    }
  
    // Station ID
    rows += infoRow('Station ID', `<span class="coords-text">${escHtml(data.station_id)}</span>`);
  
    if (!si && !data.lat) {
      rows = `<tr><td colspan="2" style="color:var(--text-muted);padding:16px;font-size:12px;">
                No site information available for this station.
              </td></tr>`;
    }
  
    infoEl.innerHTML = `
      ${photoHTML}
      <table class="site-info-table">
        <tbody>${rows}</tbody>
      </table>`;
  }
  
  function infoRow(label, value) {
    return `<tr><td>${label}</td><td>${value}</td></tr>`;
  }
  
  function susceptibilityBadge(val) {
    if (!val) return '<span class="susc-badge susc-unknown">—</span>';
    const v = val.trim().toLowerCase();
    if (v === 'data currently unavailable' || v === 'data unavailable') {
      return '<span class="susc-badge susc-unknown">Data unavailable</span>';
    }
    const cls = {
      'very low':  'susc-very-low',
      'low':       'susc-low',
      'moderate':  'susc-moderate',
      'high':      'susc-high',
      'very high': 'susc-very-high',
    }[v] || 'susc-unknown';
    return `<span class="susc-badge ${cls}">${escHtml(val)}</span>`;
  }
  
  function cameraIcon() {
    return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
      <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/>
      <circle cx="12" cy="13" r="4"/>
    </svg>`;
  }
  
  function escHtml(s) {
    if (!s) return '';
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }
  
  // ────────────────────────────────────────────────────────────────────────────
  // SENSOR DATA TAB
  // ────────────────────────────────────────────────────────────────────────────
  
  function renderDataTab(data) {
    const sensors   = data.latest_sensors || [];
    const moistures = sensors.filter(s => s.type === "soil_moisture");
    const matrics   = sensors.filter(s => s.type === "matric_potential");
    const temps     = sensors.filter(s => s.type === "soil_temp");
    const precips   = sensors.filter(s => s.type === "precipitation");
  
    let html = `
      <div style="padding:18px 20px;">
        <div class="section-label">Latest Readings</div>
        <div class="section-label" style="margin-top:-6px;margin-bottom:12px;font-size:10px;">
          Provisional data updated approximately every 45 minutes via Zentra Cloud 2.0 API
        </div>
        <div class="latest-grid">`;
  
    if (!sensors.length) {
      html += `<p style="color:var(--text-muted);font-size:12px;padding:8px 0;grid-column:1/-1;">
                No data cached yet — check back after the first refresh cycle.
              </p>`;
    }
  
    moistures.forEach(s => html += sensorCard(s, "sc-moisture"));
    matrics.forEach(s   => html += sensorCard(s, "sc-matric"));
    temps.forEach(s     => html += sensorCard(s, "sc-temp"));
  
    // Precipitation: show 24-hr total instead of latest interval reading
    if (precips.length > 0) {
      const rain24 = data.rainfall_24h_mm;
      if (rain24 !== null && rain24 !== undefined) {
        html += `
          <div class="sensor-card sc-precip">
            <div class="sc-type">Precipitation (24h)</div>
            <div class="sc-value">${rain24.toFixed(1)}</div>
            <div class="sc-unit">mm</div>
            <div class="sc-label">Last 24 hours total</div>
          </div>`;
      } else {
        // Fall back to latest reading if 24h can't be computed
        precips.forEach(s => html += sensorCard(s, "sc-precip"));
      }
    }
  
    html += `</div>`;  // close latest-grid
  
    // Charts
    const chartTypes = [
      { key: "soil_moisture",    label: "Soil Moisture (m³/m³)",          id: "chart-moisture" },
      { key: "matric_potential", label: "Matric (Water) Potential (kPa)", id: "chart-matric"   },
      { key: "soil_temp",        label: "Soil Temperature (°C)",           id: "chart-temp"     },
      { key: "precipitation",    label: "Precipitation (mm)",              id: "chart-precip"   },
    ];
  
    chartTypes.forEach(ct => {
      const hasData = (data.history || []).some(row =>
        (row.sensors || []).some(s => s.type === ct.key)
      );
      if (!hasData) return;
      html += `
        <div class="chart-section">
          <div class="section-label">${ct.label} — 14-Day History</div>
          <div class="chart-wrapper"><canvas id="${ct.id}"></canvas></div>
        </div>`;
    });
  
    html += `</div>`; // close padding wrapper
  
    document.getElementById("tab-data").innerHTML = html;
  
    chartTypes.forEach(ct => {
      if (document.getElementById(ct.id)) {
        renderChart(ct.id, ct.key, data.history || [], ct.label);
      }
    });
  }

  function sensorCard(s, cls) {
    return `
      <div class="sensor-card ${cls}">
        <div class="sc-type">${typeLabel(s.type)}</div>
        <div class="sc-value">${formatVal(s.value, s.type)}</div>
        <div class="sc-unit">${s.unit || ""}</div>
        <div class="sc-label">${s.label || (s.depth_cm ? s.depth_cm + " cm" : "")}</div>
      </div>`;
  }

  function typeLabel(t) {
    return {
      soil_moisture: "Vol. Water Content", matric_potential: "Matric Potential",
      soil_temp: "Soil Temperature", precipitation: "Precipitation",
      atmospheric_pressure: "Atm. Pressure"
    }[t] || t;
  }

  function formatVal(v, type) {
    if (v === null || v === undefined) return "—";
    if (type === "soil_moisture") return (v * 100).toFixed(1);
    return parseFloat(v).toFixed(1);
  }

  // ─── Charts ──────────────────────────────────────────────────────────────────
  const CHART_COLORS = {
    soil_moisture:    ["#55afd4", "#4a8fc4", "#3a6fa4", "#2a4f84"],  // blues
    matric_potential: ["#a07dd4", "#8a5fc4", "#7a4fb4", "#6a3fa4"],  // purples
    soil_temp:        ["#e0844a", "#c4703a", "#a85c2a", "#8c481a"],  // ambers
    precipitation:    ["#5ec47f", "#4aaa6a", "#369055", "#227640"],  // greens
  };
  const DEPTH_COLORS = ["#55afd4","#a07dd4","#e0844a","#5ec47f","#d4793a"]; // fallback

  function renderChart(canvasId, sensorType, history, yLabel) {
    // Use plain object instead of Map for broadest compatibility
    const depthLabels = {}; // key -> label
    history.forEach(row => {
      (row.sensors || []).forEach(s => {
        if (s.type === sensorType) {
          const key = "port_" + s.port;
          if (!Object.prototype.hasOwnProperty.call(depthLabels, key)) {
            depthLabels[key] = s.label || ("Port " + s.port);
          }
        }
      });
    });
    const depthKeys = Object.keys(depthLabels);
    if (!depthKeys.length) return;

    const palette = CHART_COLORS[sensorType] || DEPTH_COLORS;
    const datasets  = depthKeys.map((key, i) => ({
      label:           depthLabels[key],
      data:            [],
      borderColor:     palette[i % palette.length],
      backgroundColor: palette[i % palette.length] + "22",
      borderWidth: 1.5, pointRadius: 0, tension: 0.3, fill: false,
    }));

    history.forEach(row => {
      const dt = new Date(row.datetime);
      depthKeys.forEach((key, di) => {
        const portNum = parseInt(key.replace("port_", ""));
        const sensor  = (row.sensors || []).find(s => s.type === sensorType && s.port === portNum);
        let val = sensor ? sensor.value : null;
        if (sensorType === "soil_moisture" && val !== null) val = parseFloat((val * 100).toFixed(2));
        datasets[di].data.push({ x: dt, y: val });
      });
    });

    const ctx = document.getElementById(canvasId)?.getContext("2d");
    if (!ctx) return;

    const yAxisLabel = sensorType === "soil_moisture"
      ? "VWC (%)"
      : (yLabel.match(/\(([^)]+)\)/)?.[1] || yLabel);

    try {
    charts[canvasId] = new Chart(ctx, {
      type: "line",
      data: { datasets },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        interaction: { mode: "index", intersect: false },
        plugins: {
          legend: {
            display: depthKeys.length > 1,
            labels: { color: "#9ab5a3", font: { family: "DM Mono", size: 10 }, boxWidth: 12 }
          },
          tooltip: {
            backgroundColor: "#151e1a", borderColor: "rgba(120,180,140,0.3)", borderWidth: 1,
            titleColor: "#e8f0eb", bodyColor: "#9ab5a3",
            titleFont: { family: "DM Mono", size: 11 },
            bodyFont:  { family: "DM Mono", size: 11 },
          }
        },
        scales: {
          x: {
            type: "time",
            time: { unit: "day", displayFormats: { day: "MMM d" } },
            grid:  { color: "rgba(120,180,140,0.07)" },
            ticks: { color: "#b8d4c0", font: { family: "DM Mono", size: 9 }, maxRotation: 0 }
          },
          y: {
            grid:  { color: "rgba(120,180,140,0.07)" },
            ticks: { color: "#b8d4c0", font: { family: "DM Mono", size: 9 } },
            title: { display: true, text: yAxisLabel, color: "#b8d4c0",
                     font: { family: "DM Mono", size: 9 } }
          }
        }
      }
    });
    } catch(chartErr) {
      console.error("Chart.js error on", canvasId, chartErr);
    }
  }

  function destroyCharts() {
    Object.values(charts).forEach(c => c.destroy());
    charts = {};
  }

  // ─── Init ────────────────────────────────────────────────────────────────────
  view.when(() => {
    loadStations();

    // If a station ID was passed in the URL hash, open it once markers load
    const hashId = window.location.hash.replace('#', '');
    if (hashId) {
      // Wait for stations to load then zoom + open panel
      const pollForStation = setInterval(() => {
        const match = stationsData.find(s => s.station_id === hashId);
        if (!match) return;
        clearInterval(pollForStation);

        view.goTo({ target: [match.lng, match.lat], zoom: 14 }, { duration: 1200 });
        openPanel(hashId);
      }, 200);

      // Give up after 15 seconds if station never loads
      setTimeout(() => clearInterval(pollForStation), 15000);
    }
  });

}); // end require