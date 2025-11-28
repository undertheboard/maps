let canvas, ctx;
let geojsonData = null;
let assignmentsRef = null;
let handleClickCallback = null;
let handleHoverCallback = null;

let viewBox = { minX: 0, minY: 0, maxX: 1, maxY: 1 };
let scale = 1;
let offsetX = 0;
let offsetY = 0;

let isPanning = false;
let panStart = { x: 0, y: 0 };
let panOffsetStart = { x: 0, y: 0 };

// Display options
let displayOptions = {
  showCountyBorders: false,
  showPrecinctLines: true,
  colorMode: 'district_set'  // 'district_set', 'district_lean', 'precinct_lean'
};

// Partisan lean color scale
// Equal (within 1%): Yellow
// Within 3%: Slight red/blue
// Within 5%: Lean red/blue
// Within 10%: Likely red/blue
// Within 15%: Deeper red/blue
// Beyond 15%: Dark red/blue
const PARTISAN_COLORS = {
  dem: {
    tossup: '#fef08a',      // Yellow (within 1%)
    slight: '#93c5fd',      // Light blue (1-3%)
    lean: '#60a5fa',        // Blue (3-5%)
    likely: '#3b82f6',      // Medium blue (5-10%)
    strong: '#2563eb',      // Deeper blue (10-15%)
    safe: '#1d4ed8'         // Dark blue (15%+)
  },
  rep: {
    tossup: '#fef08a',      // Yellow (within 1%)
    slight: '#fca5a5',      // Light red (1-3%)
    lean: '#f87171',        // Red (3-5%)
    likely: '#ef4444',      // Medium red (5-10%)
    strong: '#dc2626',      // Deeper red (10-15%)
    safe: '#b91c1c'         // Dark red (15%+)
  }
};

/**
 * Get color based on partisan lean
 * @param {number} demShare - Democratic vote share (0-1)
 * @returns {string} Color hex code
 */
function getPartisanLeanColor(demShare) {
  const margin = (demShare - 0.5) * 100; // Convert to percentage margin
  const absMargin = Math.abs(margin);
  
  if (absMargin <= 1) {
    return PARTISAN_COLORS.dem.tossup; // Yellow for tossup
  }
  
  const party = margin > 0 ? 'dem' : 'rep';
  
  if (absMargin <= 3) {
    return PARTISAN_COLORS[party].slight;
  } else if (absMargin <= 5) {
    return PARTISAN_COLORS[party].lean;
  } else if (absMargin <= 10) {
    return PARTISAN_COLORS[party].likely;
  } else if (absMargin <= 15) {
    return PARTISAN_COLORS[party].strong;
  } else {
    return PARTISAN_COLORS[party].safe;
  }
}

/**
 * Set display options
 */
function setDisplayOptions(options) {
  displayOptions = { ...displayOptions, ...options };
  redrawMap();
}

/**
 * Get current display options
 */
function getDisplayOptions() {
  return { ...displayOptions };
}

function initMapCanvas(onClick, onHover) {
  canvas = document.getElementById('mapCanvas');
  ctx = canvas.getContext('2d');
  handleClickCallback = onClick;
  handleHoverCallback = onHover;

  resizeCanvas();
  window.addEventListener('resize', resizeCanvas);

  canvas.addEventListener('click', onCanvasClick);
  canvas.addEventListener('mousemove', onCanvasMove);
  canvas.addEventListener('mousedown', onMouseDown);
  window.addEventListener('mouseup', onMouseUp);
  canvas.addEventListener('wheel', onWheel, { passive: false });
}

function setGeojson(geojson, assignments) {
  geojsonData = geojson;
  assignmentsRef = assignments;
  computeViewBox();
  resetView();
  redrawMap();
}

function getDistrictColors(num) {
  const palette = {
    1: '#ef4444',
    2: '#3b82f6',
    3: '#10b981',
    4: '#f59e0b',
    5: '#8b5cf6',
    6: '#ec4899',
    7: '#14b8a6',
    8: '#6366f1',
    9: '#a855f7',
    10: '#22c55e'
  };
  const colors = {};
  for (let d = 1; d <= num; d++) {
    colors[d] = palette[d] || `hsl(${(d * 37) % 360}, 65%, 55%)`;
  }
  return colors;
}

function resizeCanvas() {
  if (!canvas) return;
  canvas.width = canvas.clientWidth;
  canvas.height = canvas.clientHeight;
  redrawMap();
}

function computeViewBox() {
  if (!geojsonData || !geojsonData.features || geojsonData.features.length === 0) {
    viewBox = { minX: 0, minY: 0, maxX: 1, maxY: 1 };
    return;
  }
  let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
  geojsonData.features.forEach(f => {
    const geom = f.geometry;
    if (!geom) return;
    const coords = geom.type === 'MultiPolygon'
      ? geom.coordinates.flat(2)
      : geom.coordinates.flat(1);
    coords.forEach(([x, y]) => {
      if (x < minX) minX = x;
      if (y < minY) minY = y;
      if (x > maxX) maxX = x;
      if (y > maxY) maxY = y;
    });
  });
  viewBox = { minX, minY, maxX, maxY };
}

function resetView() {
  const width = canvas.width;
  const height = canvas.height;
  const dataWidth = viewBox.maxX - viewBox.minX;
  const dataHeight = viewBox.maxY - viewBox.minY;
  if (dataWidth <= 0 || dataHeight <= 0) return;
  const scaleX = width / dataWidth;
  const scaleY = height / dataHeight;
  scale = Math.min(scaleX, scaleY) * 0.95;
  offsetX = width / 2 - ((viewBox.minX + viewBox.maxX) / 2) * scale;
  offsetY = height / 2 + ((viewBox.minY + viewBox.maxY) / 2) * scale;
}

function lonLatToScreen(lon, lat) {
  return {
    x: lon * scale + offsetX,
    y: -lat * scale + offsetY
  };
}

function redrawMap() {
  if (!canvas || !ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  if (!geojsonData || !geojsonData.features) return;

  const districtColors = getDistrictColors(50);
  
  // Calculate district stats for district lean coloring
  let districtStats = {};
  if (displayOptions.colorMode === 'district_lean') {
    districtStats = calculateDistrictStats();
  }

  // Group precincts by county for county border drawing
  const countyPrecincts = new Map();

  geojsonData.features.forEach(f => {
    const geom = f.geometry;
    if (!geom) return;
    const props = f.properties || {};
    const precinctId = props.id || props.precinct_id;
    const district = assignmentsRef && precinctId ? assignmentsRef[precinctId] : null;
    const county = props.county || props.COUNTY || props.COUNTYFP || props.COUNTYFP20 || 'unknown';
    
    // Track counties for border drawing
    if (!countyPrecincts.has(county)) {
      countyPrecincts.set(county, []);
    }
    countyPrecincts.set(county, [...countyPrecincts.get(county), f]);

    // Determine fill color based on color mode
    let fillStyle = '#ffffff';
    
    switch (displayOptions.colorMode) {
      case 'precinct_lean':
        // Color by precinct's own partisan lean
        const dem = Number(props.dem || props.dem_votes || 0);
        const rep = Number(props.rep || props.rep_votes || 0);
        const total = dem + rep;
        if (total > 0) {
          const demShare = dem / total;
          fillStyle = getPartisanLeanColor(demShare);
        } else {
          fillStyle = '#e5e7eb'; // Gray for no data
        }
        break;
        
      case 'district_lean':
        // Color by district's partisan lean
        if (district && districtStats[district]) {
          fillStyle = getPartisanLeanColor(districtStats[district].demShare);
        } else if (district && districtColors[district]) {
          fillStyle = districtColors[district];
        }
        break;
        
      case 'district_set':
      default:
        // Original behavior: color by district set color
        if (district && districtColors[district]) {
          fillStyle = districtColors[district];
        }
        break;
    }

    ctx.beginPath();
    if (geom.type === 'Polygon') {
      drawPolygon(geom.coordinates);
    } else if (geom.type === 'MultiPolygon') {
      geom.coordinates.forEach(poly => drawPolygon(poly));
    }
    ctx.fillStyle = fillStyle;
    
    // Draw precinct lines if enabled
    if (displayOptions.showPrecinctLines) {
      ctx.strokeStyle = '#374151';
      ctx.lineWidth = 0.5;
      ctx.stroke();
    }
    
    ctx.fill();
  });

  // Draw county borders if enabled
  if (displayOptions.showCountyBorders) {
    drawCountyBorders(countyPrecincts);
  }
}

/**
 * Calculate partisan stats for each district
 */
function calculateDistrictStats() {
  const stats = {};
  
  if (!geojsonData || !geojsonData.features) return stats;
  
  geojsonData.features.forEach(f => {
    const props = f.properties || {};
    const precinctId = props.id || props.precinct_id;
    const district = assignmentsRef && precinctId ? assignmentsRef[precinctId] : null;
    
    if (!district) return;
    
    if (!stats[district]) {
      stats[district] = { dem: 0, rep: 0, demShare: 0.5 };
    }
    
    stats[district].dem += Number(props.dem || props.dem_votes || 0);
    stats[district].rep += Number(props.rep || props.rep_votes || 0);
  });
  
  // Calculate dem share for each district
  Object.keys(stats).forEach(d => {
    const total = stats[d].dem + stats[d].rep;
    stats[d].demShare = total > 0 ? stats[d].dem / total : 0.5;
  });
  
  return stats;
}

/**
 * Draw county borders with thicker lines
 */
function drawCountyBorders(countyPrecincts) {
  ctx.strokeStyle = '#1f2937';
  ctx.lineWidth = 2;
  
  countyPrecincts.forEach((precincts, county) => {
    // Draw each precinct's outer boundary that's on a county edge
    // For simplicity, we draw all precinct boundaries with thick lines for same county
    precincts.forEach(f => {
      const geom = f.geometry;
      if (!geom) return;
      
      ctx.beginPath();
      if (geom.type === 'Polygon') {
        drawPolygon(geom.coordinates);
      } else if (geom.type === 'MultiPolygon') {
        geom.coordinates.forEach(poly => drawPolygon(poly));
      }
      ctx.stroke();
    });
  });
}

function drawPolygon(rings) {
  rings.forEach((ring) => {
    ring.forEach(([lon, lat], i) => {
      const { x, y } = lonLatToScreen(lon, lat);
      if (i === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
  });
}

function onCanvasClick(e) {
  if (!geojsonData) return;
  const rect = canvas.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;
  const lonLat = screenToLonLat(x, y);

  const feature = findFeatureAtPoint(lonLat.lon, lonLat.lat);
  if (!feature) return;
  const props = feature.properties || {};
  const precinctId = props.id || props.precinct_id;
  if (!precinctId) return;

  const buttonMode = 'single';
  handleClickCallback && handleClickCallback(precinctId, buttonMode);
}

function onCanvasMove(e) {
  if (isPanning) {
    const dx = e.clientX - panStart.x;
    const dy = e.clientY - panStart.y;
    offsetX = panOffsetStart.x + dx;
    offsetY = panOffsetStart.y + dy;
    redrawMap();
    return;
  }

  if (!geojsonData) return;
  const rect = canvas.getBoundingClientRect();
  const x = e.clientX - rect.left;
  const y = e.clientY - rect.top;
  const lonLat = screenToLonLat(x, y);
  const feature = findFeatureAtPoint(lonLat.lon, lonLat.lat);
  handleHoverCallback && handleHoverCallback(feature, x, y);
}

function onMouseDown(e) {
  // Shift+left button or middle mouse to pan
  if (e.button === 1 || (e.button === 0 && e.shiftKey)) {
    isPanning = true;
    panStart = { x: e.clientX, y: e.clientY };
    panOffsetStart = { x: offsetX, y: offsetY };
  }
}

function onMouseUp() {
  isPanning = false;
}

function onWheel(e) {
  e.preventDefault();
  const delta = e.deltaY;
  const zoomFactor = Math.exp(-delta * 0.001);
  const rect = canvas.getBoundingClientRect();
  const mouseX = e.clientX - rect.left;
  const mouseY = e.clientY - rect.top;
  const lonLatBefore = screenToLonLat(mouseX, mouseY);

  scale *= zoomFactor;

  const screenAfter = lonLatToScreen(lonLatBefore.lon, lonLatBefore.lat);
  offsetX += mouseX - screenAfter.x;
  offsetY += mouseY - screenAfter.y;

  redrawMap();
}

function screenToLonLat(x, y) {
  const lon = (x - offsetX) / scale;
  const lat = -(y - offsetY) / scale;
  return { lon, lat };
}

function findFeatureAtPoint(lon, lat) {
  if (!geojsonData) return null;
  for (let i = 0; i < geojsonData.features.length; i++) {
    const f = geojsonData.features[i];
    const geom = f.geometry;
    if (!geom) continue;
    if (geom.type === 'Polygon') {
      if (polygonContains(geom.coordinates, lon, lat)) return f;
    } else if (geom.type === 'MultiPolygon') {
      if (geom.coordinates.some(poly => polygonContains(poly, lon, lat))) return f;
    }
  }
  return null;
}

function polygonContains(rings, x, y) {
  const ring = rings[0];
  let inside = false;
  for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
    const xi = ring[i][0], yi = ring[i][1];
    const xj = ring[j][0], yj = ring[j][1];
    const intersect = (yi > y) !== (yj > y) &&
      x < ((xj - xi) * (y - yi)) / ((yj - yi) || 1e-9) + xi;
    if (intersect) inside = !inside;
  }
  return inside;
}

window.initMapCanvas = initMapCanvas;
window.setGeojson = setGeojson;
window.redrawMap = redrawMap;
window.getDistrictColors = getDistrictColors;
window.setDisplayOptions = setDisplayOptions;
window.getDisplayOptions = getDisplayOptions;
window.getPartisanLeanColor = getPartisanLeanColor;
window.PARTISAN_COLORS = PARTISAN_COLORS;