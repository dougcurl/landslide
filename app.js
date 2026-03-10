/**
 * KGS Soil Moisture Monitoring Network — app.js
 * ArcGIS JS SDK 4.x  |  Chart.js  |  NOAA NEXRAD radar overlay
 */

require([
  "esri/Map",
  "esri/views/MapView",
  "esri/layers/WebTileLayer",
  "esri/Graphic",
  "esri/layers/GraphicsLayer",
  "esri/widgets/Home",
  "esri/widgets/ScaleBar",
  "esri/widgets/Legend",
], function (
  Map, MapView, WebTileLayer,
  Graphic, GraphicsLayer,
  Home, ScaleBar
) {

  // ─── State ─────────────────────────────────────────────────────────────────
  let stationsData    = [];
  let activeStationId = null;
  let radarVisible    = false;
  let radarLayer      = null;
  let charts          = {};
  let markerGraphics  = {};  // station_id -> Graphic

  // ─── Map Setup ──────────────────────────────────────────────────────────────
  const map = new Map({
    basemap: "dark-gray-vector"   // ArcGIS dark basemap — clean for data viz
  });

  const view = new MapView({
    container: "map",
    map: map,
    center: [-84.27, 37.8],   // Kentucky center
    zoom: 7,
    ui: { components: ["zoom", "compass"] }
  });

  view.ui.add(new Home({ view }), "top-left");
  view.ui.add(new ScaleBar({ view, unit: "dual" }), "bottom-right");

  const stationLayer = new GraphicsLayer({ id: "stations" });
  map.add(stationLayer);

  // ─── NEXRAD Radar Layer ─────────────────────────────────────────────────────
  // Iowa Environmental Mesonet — NEXRAD composite reflectivity, free, no key
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
      map.add(radarLayer, 0); // insert below station layer
    } else {
      if (radarLayer) {
        map.remove(radarLayer);
        radarLayer = null;
      }
    }
  });

  document.getElementById("radar-opacity").addEventListener("input", function () {
    if (radarLayer) radarLayer.opacity = parseFloat(this.value);
  });

  // Auto-refresh radar every 5 minutes
  setInterval(() => {
    if (radarVisible && radarLayer) {
      const opacity = radarLayer.opacity;
      map.remove(radarLayer);
      radarLayer = buildRadarLayer(opacity);
      map.add(radarLayer, 0);
    }
  }, 5 * 60 * 1000);

  // ─── Moisture → Color ───────────────────────────────────────────────────────
  // m³/m³ range typically 0.05 (very dry) to 0.50 (saturated)
  // We map to brown→green color scale
  function moistureToColor(val) {
    if (val === null || val === undefined) return "#4a5a52"; // gray = no data

    // Clamp to typical soil range
    const min = 0.05, max = 0.50;
    const t   = Math.max(0, Math.min(1, (val - min) / (max - min)));

    // Color stops: dry brown → amber → gold → light green → deep green
    const stops = [
      [0.00, [107, 58,  42]],  // #6b3a2a
      [0.20, [155, 90,  42]],  // #9b5a2a
      [0.40, [196,129,  60]],  // #c4813c
      [0.55, [201,168,  76]],  // #c9a84c
      [0.70, [138,181, 110]],  // #8ab56e
      [0.85, [ 93,186, 125]],  // #5dba7d
      [1.00, [ 42,122,  82]],  // #2a7a52
    ];

    for (let i = 0; i < stops.length - 1; i++) {
      const [t0, c0] = stops[i];
      const [t1, c1] = stops[i + 1];
      if (t >= t0 && t <= t1) {
        const f = (t - t0) / (t1 - t0);
        const r = Math.round(c0[0] + f * (c1[0] - c0[0]));
        const g = Math.round(c0[1] + f * (c1[1] - c0[1]));
        const b = Math.round(c0[2] + f * (c1[2] - c0[2]));
        return `rgb(${r},${g},${b})`;
      }
    }
    return "#5dba7d";
  }

  // ─── Build Marker HTML ──────────────────────────────────────────────────────
  function buildMarkerHTML(station) {
    const pct   = station.latest_moisture_pct;
    const color = moistureToColor(station.latest_moisture_avg);
    const isActive = station.station_id === activeStationId;

    const inner = pct !== null
      ? `<span class="pct">${pct}%</span><span class="pct-label">VWC</span>`
      : `<span class="no-data">N/A</span>`;

    return `
      <div class="station-marker-wrapper${isActive ? ' active' : ''}">
        <div class="station-bubble${isActive ? ' active' : ''}"
             style="background:${color}">
          ${inner}
        </div>
        <div class="station-pin"></div>
        <div class="station-name-label">${station.name.replace(/^Station \d+ — /, '')}</div>
      </div>`;
  }

  // ─── Render Markers ─────────────────────────────────────────────────────────
  function renderMarkers(stations) {
    stationLayer.removeAll();
    markerGraphics = {};

    stations.forEach(station => {
      const el = document.createElement("div");
      el.innerHTML = buildMarkerHTML(station);
      el.firstElementChild.addEventListener("click", () => openPanel(station.station_id));

      const graphic = new Graphic({
        geometry: { type: "point", longitude: station.lng, latitude: station.lat },
        symbol: {
          type: "simple-marker",
          color: [0, 0, 0, 0],  // transparent — we use HTML overlay
          size: 0,
        },
        attributes: { station_id: station.station_id },
        popupTemplate: null,
      });

      // Use MapView.ui or a custom HTMLElement overlay via view.graphics
      // ArcGIS 4.x: use a custom HTMLElement overlay via HTMLElement symbol for the view
      const markerGraphic = {
        geometry: { type: "point", longitude: station.lng, latitude: station.lat },
        symbol: {
          type: "point-3d",
          symbolLayers: [{
            type: "object",
            resource: { primitive: "sphere" },
            material: { color: [0, 0, 0, 0] },
            height: 0,
          }]
        }
      };

      stationLayer.add(graphic);
      markerGraphics[station.station_id] = { graphic, el, station };
    });

    // Use view overlay divs for HTML markers (ArcGIS 4.x pattern)
    renderHTMLMarkers(stations);
  }

  // HTML Marker overlay using ArcGIS MapView's toScreen + absolute positioned divs
  let markerContainer = null;

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
      div.querySelector(".station-marker-wrapper").addEventListener("click", () => {
        openPanel(station.station_id);
      });
      markerContainer.appendChild(div);
    });

    positionMarkers();
  }

  function positionMarkers() {
    if (!markerContainer || !stationsData.length) return;
    stationsData.forEach(station => {
      const el = document.getElementById(`marker-${station.station_id}`);
      if (!el) return;
      const pt = { type: "point", longitude: station.lng, latitude: station.lat };
      const screenPt = view.toScreen(pt);
      if (screenPt) {
        el.style.left = screenPt.x + "px";
        el.style.top  = screenPt.y + "px";
        el.style.display = "";
      } else {
        el.style.display = "none";
      }
    });
  }

  // Reposition markers on pan/zoom
  view.watch("extent", () => positionMarkers());
  view.watch("zoom",   () => positionMarkers());
  view.on("resize",    () => positionMarkers());

  // ─── Load Stations ──────────────────────────────────────────────────────────
  async function loadStations() {
    try {
      const res  = await fetch("api/get_stations.php");
      const json = await res.json();
      stationsData = json.stations || [];
      renderMarkers(stationsData);
      updateLastUpdated(json.cached_at);
    } catch (err) {
      console.error("Failed to load stations:", err);
    }
  }

  function updateLastUpdated(cachedAt) {
    const el = document.getElementById("last-updated");
    if (!cachedAt) { el.textContent = ""; return; }
    const d = new Date(cachedAt);
    el.textContent = "Updated " + d.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" });
  }

  // Refresh map markers every 15 minutes
  setInterval(loadStations, 15 * 60 * 1000);

  // ─── Detail Panel ───────────────────────────────────────────────────────────
  const panel = document.getElementById("detail-panel");

  async function openPanel(stationId) {
    // Update active state
    const prev = activeStationId;
    activeStationId = stationId;

    // Re-render old and new markers to update active style
    [prev, stationId].forEach(id => {
      if (!id) return;
      const s = stationsData.find(x => x.station_id === id);
      if (s) {
        const el = document.getElementById(`marker-${id}`);
        if (el) el.innerHTML = buildMarkerHTML(s);
        el?.querySelector(".station-marker-wrapper")?.addEventListener("click", () => openPanel(id));
      }
    });

    // Open panel with loading state
    panel.classList.add("open");
    showPanelLoading();

    try {
      const res  = await fetch(`api/get_station_data.php?id=${encodeURIComponent(stationId)}`);
      const data = await res.json();
      if (data.error) throw new Error(data.error);
      renderPanelContent(data);
    } catch (err) {
      showPanelError(err.message);
    }
  }

  function closePanel() {
    panel.classList.remove("open");
    activeStationId = null;
    destroyCharts();
    // Re-render all markers to clear active state
    stationsData.forEach(s => {
      const el = document.getElementById(`marker-${s.station_id}`);
      if (el) {
        el.innerHTML = buildMarkerHTML(s);
        el.querySelector(".station-marker-wrapper")?.addEventListener("click", () => openPanel(s.station_id));
      }
    });
  }

  document.getElementById("panel-close").addEventListener("click", closePanel);

  function showPanelLoading() {
    document.getElementById("panel-header").querySelector(".panel-title").textContent = "Loading…";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent  = "";
    document.getElementById("panel-body").innerHTML = `
      <div id="panel-loading">
        <div class="spinner"></div>
        <p>Fetching station data…</p>
      </div>`;
  }

  function showPanelError(msg) {
    document.getElementById("panel-body").innerHTML =
      `<div class="panel-error">⚠ ${msg}</div>`;
  }

  // ─── Render Panel Content ───────────────────────────────────────────────────
  function renderPanelContent(data) {
    destroyCharts();

    // Header
    document.getElementById("panel-header").querySelector(".panel-title").textContent = data.name;
    const dt = data.latest_datetime ? new Date(data.latest_datetime).toLocaleString() : "No data";
    document.getElementById("panel-header").querySelector(".panel-meta").textContent =
      `${data.region}  •  Last reading: ${dt}`;

    // Group latest sensors by type
    const sensors    = data.latest_sensors || [];
    const moistures  = sensors.filter(s => s.type === "soil_moisture");
    const matrics    = sensors.filter(s => s.type === "matric_potential");
    const temps      = sensors.filter(s => s.type === "soil_temp");

    // Also look for precip and atm pressure in the history
    const lastRow   = data.history?.length ? data.history[data.history.length - 1] : null;
    const allSensors = lastRow ? Object.values(lastRow.sensors) : sensors;

    let html = "";

    // ── Latest Values Cards ──
    html += `<div class="section-label">Latest Readings</div>`;
    html += `<div class="latest-grid">`;

    moistures.forEach(s => {
      html += sensorCard(s, "sc-moisture");
    });
    matrics.forEach(s => {
      html += sensorCard(s, "sc-matric");
    });
    temps.forEach(s => {
      html += sensorCard(s, "sc-temp");
    });

    // Precip/atm from any sensor not yet shown
    const shownPorts = new Set([...moistures, ...matrics, ...temps].map(s => s.port));
    allSensors.filter(s => !shownPorts.has(s.port)).forEach(s => {
      html += sensorCard(s, "sc-precip");
    });

    html += `</div>`;  // latest-grid

    // ── Charts ──
    // Build one chart per sensor type with multi-depth lines
    const chartTypes = [
      { key: "soil_moisture",    label: "Soil Moisture (m³/m³)",       id: "chart-moisture" },
      { key: "matric_potential", label: "Matric (Water) Potential (kPa)", id: "chart-matric" },
      { key: "soil_temp",        label: "Soil Temperature (°C)",        id: "chart-temp" },
    ];

    chartTypes.forEach(ct => {
      const hasData = (data.history || []).some(row =>
        Object.values(row.sensors).some(s => s.type === ct.key)
      );
      if (!hasData) return;
      html += `
        <div class="chart-section">
          <div class="section-label">${ct.label} — 14-Day History</div>
          <div class="chart-wrapper">
            <canvas id="${ct.id}"></canvas>
          </div>
        </div>`;
    });

    document.getElementById("panel-body").innerHTML = html;

    // Render charts after DOM insert
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
        <div class="sc-unit">${s.unit}</div>
        <div class="sc-label">${s.label || (s.depth_cm ? s.depth_cm + ' cm' : '')}</div>
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

  // ─── Chart.js Rendering ─────────────────────────────────────────────────────
  const DEPTH_COLORS = [
    "#5dba7d", "#c9a84c", "#4a9ebb", "#d4793a", "#a07dd4"
  ];

  function renderChart(canvasId, sensorType, history, yLabel) {
    // Collect all unique depth labels for this sensor type
    const depthSet = new Map();
    history.forEach(row => {
      Object.values(row.sensors).forEach(s => {
        if (s.type === sensorType) {
          const key = `port_${s.port}`;
          if (!depthSet.has(key)) depthSet.set(key, s.label || `Port ${s.port}`);
        }
      });
    });

    if (depthSet.size === 0) return;

    // Build datasets — one per depth/port
    const depthKeys = [...depthSet.keys()];
    const labels    = [];
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

    // Downsample history to hourly for chart performance (96 pts/day * 14 days = too many)
    // Keep every 4th point (one per hour)
    const sampled = history.filter((_, i) => i % 4 === 0);

    sampled.forEach(row => {
      const dt = new Date(row.datetime);
      labels.push(dt);
      depthKeys.forEach((key, di) => {
        const portNum = parseInt(key.replace("port_", ""));
        const sensor  = Object.values(row.sensors).find(
          s => s.type === sensorType && s.port === portNum
        );
        let val = sensor ? sensor.value : null;
        // Convert moisture to percentage for display
        if (sensorType === "soil_moisture" && val !== null) val = parseFloat((val * 100).toFixed(2));
        datasets[di].data.push({ x: dt, y: val });
      });
    });

    const ctx = document.getElementById(canvasId).getContext("2d");
    const yAxisLabel = sensorType === "soil_moisture" ? "VWC (%)" : yLabel.match(/\(([^)]+)\)/)?.[1] || yLabel;

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
            labels: {
              color: "#9ab5a3",
              font: { family: "DM Mono", size: 10 },
              boxWidth: 12,
            }
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
            grid: { color: "rgba(120,180,140,0.07)" },
            ticks: { color: "#5a7a65", font: { family: "DM Mono", size: 9 }, maxRotation: 0 }
          },
          y: {
            grid: { color: "rgba(120,180,140,0.07)" },
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
