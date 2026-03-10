/**
 * KGS Landslide Monitoring Network — app.js
 * ArcGIS JS SDK 4.x  |  Chart.js  |  NOAA NEXRAD radar overlay
 *
 * Chart.js is loaded dynamically via loadChartJS() AFTER ArcGIS require()
 * completes. Loading Chart.js (UMD bundle) in <head> alongside the ArcGIS
 * SDK causes a Dojo AMD "multipleDefine" conflict.
 */

// ── Dynamic Chart.js loader ─────────────────────────────────────────────────
// Injects Chart.js + date-fns adapter as <script> tags and resolves a Promise
// once both are loaded. Safe to call multiple times — skips if already loaded.
function loadChartJS() {
  if (window.Chart) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const s1 = document.createElement("script");
    s1.src = "https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js";
    s1.onload = () => {
      const s2 = document.createElement("script");
      s2.src = "https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns@3.0.0/dist/chartjs-adapter-date-fns.bundle.min.js";
      s2.onload = resolve;
      s2.onerror = reject;
      document.head.appendChild(s2);
    };
    s1.onerror = reject;
    document.head.appendChild(s1);
  });
}

require([
  "esri/Map",
  "esri/views/MapView",
  "esri/layers/WebTileLayer",
  "esri/Graphic",
  "esri/layers/GraphicsLayer",
  "esri/widgets/Home",
  "esri/widgets/ScaleBar",
], function (
  Map, MapView, WebTileLayer,
  Graphic, GraphicsLayer,
  Home, ScaleBar
) {

  // ─── State ───────────────────────────────────────────────────────────────────
  let stationsData    = [];
  let activeStationId = null;
  let radarVisible    = false;
  let radarLayer      = null;
  let charts          = {};
  let markerContainer = null;

  // ─── Map Setup ───────────────────────────────────────────────────────────────
  const map = new Map({ basemap: "topo" });

  const view = new MapView({
    container: "map",
    map: map,
    center: [-84.27, 37.8],
    zoom: 7,
    ui: { components: ["zoom", "compass"] }
  });

  view.ui.add(new Home({ view }), "top-left");
  view.ui.add(new ScaleBar({ view, unit: "dual" }), "bottom-right");

  const stationLayer = new GraphicsLayer({ id: "stations" });
  map.add(stationLayer);

  // ─── NEXRAD Radar ────────────────────────────────────────────────────────────
  function buildRadarLayer(opacity) {
    return new WebTileLayer({
      urlTemplate: "https://mesonet.agron.iastate.edu/cache/tile.py/1.0.0/nexrad-n0q-900913/{level}/{col}/{row}.png",
      opacity: opacity,
      id: "radar",
      title: "NEXRAD Radar",
    });
  }

  document.getElementById("radar-toggle").addEventListener("click", function () {
    radarVisible = !radarVisible;
    this.classList.toggle("active", radarVisible);
    document.getElementById("radar-controls").classList.toggle("visible", radarVisible);
    if (radarVisible) {
      const opacity = parseFloat(document.getElementById("radar-opacity").value);
      radarLayer = buildRadarLayer(opacity);
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
    if (val === null || val === undefined) return "#4a5a52";
    const min = 0.05, max = 0.50;
    const t   = Math.max(0, Math.min(1, (val - min) / (max - min)));
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
        return `rgb(${Math.round(c0[0]+f*(c1[0]-c0[0]))},${Math.round(c0[1]+f*(c1[1]-c0[1]))},${Math.round(c0[2]+f*(c1[2]-c0[2]))})`;
      }
    }
    return "#5dba7d";
  }

  // ─── Markers ─────────────────────────────────────────────────────────────────
  function buildMarkerHTML(station) {
    const pct   = station.latest_moisture_pct;
    const color = moistureToColor(station.latest_moisture_avg);
    const isActive = station.station_id === activeStationId;
    const inner = pct !== null
      ? `<span class="pct">${pct}%</span><span class="pct-label">VWC</span>`
      : `<span class="no-data">N/A</span>`;
    return `
      <div class="station-marker-wrapper${isActive ? ' active' : ''}">
        <div class="station-bubble${isActive ? ' active' : ''}" style="background:${color}">${inner}</div>
        <div class="station-pin"></div>
        <div class="station-name-label">${station.name.replace(/^Station \d+ — /, '')}</div>
      </div>`;
  }

  function renderHTMLMarkers(stations) {
    if (!markerContainer) {
      markerContainer = document.createElement("div");
      markerContainer.id = "marker-overlay";
      markerContainer.style.cssText = "position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10;";
      document.getElementById("map").appendChild(markerContainer);
    }
    markerContainer.innerHTML = "";
    stations.forEach(station => {
      const div = document.createElement("div");
      div.id = `marker-${station.station_id}`;
      div.style.cssText = "position:absolute;transform:translate(-50%,-100%);pointer-events:all;";
      div.innerHTML = buildMarkerHTML(station);
      div.querySelector(".station-marker-wrapper").addEventListener("click", () => openPanel(station.station_id));
      markerContainer.appendChild(div);
    });
    positionMarkers();
  }

  function positionMarkers() {
    if (!markerContainer || !stationsData.length) return;
    stationsData.forEach(station => {
      const el = document.getElementById(`marker-${station.station_id}`);
      if (!el) return;
      const screenPt = view.toScreen({ type: "point", longitude: station.lng, latitude: station.lat });
      if (screenPt) {
        el.style.left = screenPt.x + "px";
        el.style.top  = screenPt.y + "px";
        el.style.display = "";
      } else {
        el.style.display = "none";
      }
    });
  }

  view.watch("extent", positionMarkers);
  view.watch("zoom",   positionMarkers);

  // ─── Load Stations ───────────────────────────────────────────────────────────
  function loadStations() {
    fetch("api/get_stations.php")
      .then(r => r.json())
      .then(data => {
        stationsData = data.stations || [];
        renderHTMLMarkers(stationsData);
        if (data.cached_at) {
          document.getElementById("last-updated").textContent =
            "Updated " + new Date(data.cached_at).toLocaleTimeString();
        }
      })
      .catch(err => console.error("Failed to load stations:", err));
  }

  // ─── Panel ───────────────────────────────────────────────────────────────────
  function openPanel(stationId) {
    activeStationId = stationId;
    document.getElementById("detail-panel").classList.add("open");
    refreshMarkerActive();
    showPanelLoading();

    // Load Chart.js lazily then fetch station data
    loadChartJS()
      .then(() => fetch(`api/get_station_data.php?station=${encodeURIComponent(stationId)}`))
      .then(r => r.json())
      .then(data => {
        if (data.error) { showPanelError(data.error); return; }
        renderPanelContent(data);
      })
      .catch(err => showPanelError("Failed to load station data."));
  }

  function closePanel() {
    activeStationId = null;
    document.getElementById("detail-panel").classList.remove("open");
    destroyCharts();
    refreshMarkerActive();
  }

  function refreshMarkerActive() {
    stationsData.forEach(s => {
      const el = document.getElementById(`marker-${s.station_id}`);
      if (!el) return;
      el.innerHTML = buildMarkerHTML(s);
      el.querySelector(".station-marker-wrapper").addEventListener("click", () => openPanel(s.station_id));
    });
  }

  document.getElementById("panel-close").addEventListener("click", closePanel);

  function showPanelLoading() {
    document.getElementById("panel-header").querySelector(".panel-title").textContent = "Loading…";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent  = "";
    document.getElementById("panel-body").innerHTML = `
      <div id="panel-loading"><div class="spinner"></div><p>Fetching station data…</p></div>`;
  }

  function showPanelError(msg) {
    document.getElementById("panel-body").innerHTML = `<div class="panel-error">⚠ ${msg}</div>`;
  }

  // ─── Panel Content ───────────────────────────────────────────────────────────
  function renderPanelContent(data) {
    destroyCharts();

    document.getElementById("panel-header").querySelector(".panel-title").textContent = data.name;
    const dt = data.latest_datetime ? new Date(data.latest_datetime).toLocaleString() : "No data";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent =
      `${data.region}  •  Last reading: ${dt}`;

    const sensors   = data.latest_sensors || [];
    const moistures = sensors.filter(s => s.type === "soil_moisture");
    const matrics   = sensors.filter(s => s.type === "matric_potential");
    const temps     = sensors.filter(s => s.type === "soil_temp");
    const others    = sensors.filter(s => !["soil_moisture","matric_potential","soil_temp"].includes(s.type));

    let html = `<div class="section-label">Latest Readings</div><div class="latest-grid">`;
    moistures.forEach(s => html += sensorCard(s, "sc-moisture"));
    matrics.forEach(s   => html += sensorCard(s, "sc-matric"));
    temps.forEach(s     => html += sensorCard(s, "sc-temp"));
    others.forEach(s    => html += sensorCard(s, "sc-precip"));
    html += `</div>`;

    const chartTypes = [
      { key: "soil_moisture",    label: "Soil Moisture (m³/m³)",          id: "chart-moisture" },
      { key: "matric_potential", label: "Matric (Water) Potential (kPa)", id: "chart-matric"   },
      { key: "soil_temp",        label: "Soil Temperature (°C)",           id: "chart-temp"     },
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

    document.getElementById("panel-body").innerHTML = html;

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
    return { soil_moisture: "Vol. Water Content", matric_potential: "Matric Potential",
             soil_temp: "Soil Temperature", precipitation: "Precipitation",
             atmospheric_pressure: "Atm. Pressure" }[t] || t;
  }

  function formatVal(v, type) {
    if (v === null || v === undefined) return "—";
    if (type === "soil_moisture") return (v * 100).toFixed(1);
    return parseFloat(v).toFixed(1);
  }

  // ─── Charts ──────────────────────────────────────────────────────────────────
  const DEPTH_COLORS = ["#5dba7d","#c9a84c","#4a9ebb","#d4793a","#a07dd4"];

  function renderChart(canvasId, sensorType, history, yLabel) {
    const depthSet = new Map();
    history.forEach(row => {
      (row.sensors || []).forEach(s => {
        if (s.type === sensorType) {
          const key = `port_${s.port}`;
          if (!depthSet.has(key)) depthSet.set(key, s.label || `Port ${s.port}`);
        }
      });
    });
    if (depthSet.size === 0) return;

    const depthKeys = [...depthSet.keys()];
    const datasets  = depthKeys.map((key, i) => ({
      label:           depthSet.get(key),
      data:            [],
      borderColor:     DEPTH_COLORS[i % DEPTH_COLORS.length],
      backgroundColor: DEPTH_COLORS[i % DEPTH_COLORS.length] + "22",
      borderWidth:     1.5,
      pointRadius:     0,
      tension:         0.3,
      fill:            false,
    }));

    // Downsample to ~hourly
    const sampled = history.filter((_, i) => i % 4 === 0);

    sampled.forEach(row => {
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

    const yAxisLabel = sensorType === "soil_moisture" ? "VWC (%)" : (yLabel.match(/\(([^)]+)\)/)?.[1] || yLabel);

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
            backgroundColor: "#151e1a",
            borderColor: "rgba(120,180,140,0.3)",
            borderWidth: 1,
            titleColor: "#e8f0eb",
            bodyColor: "#9ab5a3",
            titleFont: { family: "DM Mono", size: 11 },
            bodyFont:  { family: "DM Mono", size: 11 },
          }
        },
        scales: {
          x: {
            type: "time",
            time: { unit: "day", displayFormats: { day: "MMM d" } },
            grid:  { color: "rgba(120,180,140,0.07)" },
            ticks: { color: "#5a7a65", font: { family: "DM Mono", size: 9 }, maxRotation: 0 }
          },
          y: {
            grid:  { color: "rgba(120,180,140,0.07)" },
            ticks: { color: "#5a7a65", font: { family: "DM Mono", size: 9 } },
            title: { display: true, text: yAxisLabel, color: "#5a7a65",
                     font: { family: "DM Mono", size: 9 } }
          }
        }
      }
    });
  }

  function destroyCharts() {
    Object.values(charts).forEach(c => c.destroy());
    charts = {};
  }

  // ─── Init ────────────────────────────────────────────────────────────────────
  view.when(() => {
    loadStations();
  });

}); // end require