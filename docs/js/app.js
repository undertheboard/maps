let currentState = null;
let currentStateMeta = null;
let currentGeojson = null;
let currentPlan = null;
let currentAssignments = {};
let numDistricts = 10;

const stateSelect = document.getElementById('stateSelect');
const loadStateBtn = document.getElementById('loadStateBtn');
const numDistrictsInput = document.getElementById('numDistricts');
const planNameInput = document.getElementById('planName');
const newPlanBtn = document.getElementById('newPlanBtn');
const savePlanBtn = document.getElementById('savePlanBtn');
const existingPlansSelect = document.getElementById('existingPlans');
const loadPlanBtn = document.getElementById('loadPlanBtn');
const metricsPanel = document.getElementById('metricsPanel');
const districtColorLegend = document.getElementById('districtColorLegend');

document.addEventListener('DOMContentLoaded', async () => {
  // Initialize the Leaflet basemap immediately so the map is visible
  initLeafletMap();
  
  await loadStatesList();
  attachEventHandlers();
  // Initialize your existing canvas-based map
  initMapCanvas(handlePrecinctClick, handleHoverPrecinct);
  
  // Auto-load the last selected state if available
  const lastState = localStorage.getItem('lastSelectedState');
  if (lastState && stateSelect.querySelector(`option[value="${lastState}"]`)) {
    stateSelect.value = lastState;
    loadState(lastState);
  }
});

function attachEventHandlers() {
  loadStateBtn.addEventListener('click', () => {
    const value = stateSelect.value;
    if (!value) {
      alert('Select a state first.');
      return;
    }
    loadState(value);
  });

  numDistrictsInput.addEventListener('change', () => {
    numDistricts = parseInt(numDistrictsInput.value, 10) || 1;
    renderDistrictLegend(numDistricts);
    recomputeMetrics();
    redrawMap();
  });

  newPlanBtn.addEventListener('click', () => {
    if (!currentState) {
      alert('Select a state first.');
      return;
    }
    createNewPlan();
  });

  savePlanBtn.addEventListener('click', saveCurrentPlanToLocalStorage);

  loadPlanBtn.addEventListener('click', () => {
    const planId = existingPlansSelect.value;
    if (!planId || !currentState) {
      alert('Select a plan from the dropdown.');
      return;
    }
    loadPlanFromStorage(currentState, planId);
  });
  
  // District selector dropdown
  const districtSelector = document.getElementById('districtSelector');
  if (districtSelector) {
    districtSelector.addEventListener('change', () => {
      const val = parseInt(districtSelector.value, 10);
      if (val >= 1 && val <= numDistricts) {
        setSelectedDistrict(val);
      }
    });
  }
  
  // Keyboard shortcuts
  document.addEventListener('keydown', handleKeyboardShortcut);
}

/**
 * Handle keyboard shortcuts for district selection and mode switching
 */
function handleKeyboardShortcut(e) {
  // Ignore if typing in an input field
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
    return;
  }
  
  const key = e.key.toLowerCase();
  
  // Number keys 1-9 select districts
  if (key >= '1' && key <= '9') {
    const district = parseInt(key, 10);
    if (district <= numDistricts) {
      setSelectedDistrict(district);
      const assignRadio = document.querySelector('input[name="drawMode"][value="assign"]');
      if (assignRadio) assignRadio.checked = true;
    }
    return;
  }
  
  // 0 key selects district 10 (for quick access to first double-digit district)
  if (key === '0') {
    if (numDistricts >= 10) {
      setSelectedDistrict(10);
      const assignRadio = document.querySelector('input[name="drawMode"][value="assign"]');
      if (assignRadio) assignRadio.checked = true;
    }
    return;
  }
  
  // 'E' toggles erase mode
  if (key === 'e') {
    const eraseRadio = document.querySelector('input[name="drawMode"][value="erase"]');
    if (eraseRadio) eraseRadio.checked = true;
    return;
  }
  
  // 'A' toggles assign mode
  if (key === 'a') {
    const assignRadio = document.querySelector('input[name="drawMode"][value="assign"]');
    if (assignRadio) assignRadio.checked = true;
    return;
  }
}

/**
 * Update the district selector dropdown
 */
function updateDistrictSelector() {
  const selector = document.getElementById('districtSelector');
  if (!selector) return;
  
  selector.innerHTML = '';
  const colors = getDistrictColors(numDistricts);
  
  for (let i = 1; i <= numDistricts; i++) {
    const opt = document.createElement('option');
    opt.value = i;
    opt.textContent = `District ${i}`;
    opt.selected = (i === selectedDistrict);
    selector.appendChild(opt);
  }
}

async function loadStatesList() {
  try {
    const res = await fetch('data/states.json');
    const data = await res.json();
    if (!data || !Array.isArray(data)) {
      stateSelect.innerHTML = '<option value="">No states available</option>';
      return;
    }

    stateSelect.innerHTML = '<option value="">Select a state</option>';
    data.forEach(st => {
      const opt = document.createElement('option');
      opt.value = st.abbr || st.code;
      opt.textContent = `${st.abbr || st.code} - ${st.name}`;
      stateSelect.appendChild(opt);
    });
  } catch (e) {
    console.error(e);
    stateSelect.innerHTML = '<option value="">Error loading states</option>';
  }
}

/**
 * Show/hide/update the map loading indicator
 */
function showMapLoadingIndicator(show, message = 'Loading...') {
  let indicator = document.getElementById('mapLoadingIndicator');
  
  if (!indicator) {
    indicator = document.createElement('div');
    indicator.id = 'mapLoadingIndicator';
    indicator.className = 'map-loading-indicator';
    indicator.innerHTML = `
      <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading...</div>
      </div>
    `;
    const mapPanel = document.querySelector('.map-panel');
    if (mapPanel) {
      mapPanel.appendChild(indicator);
    }
  }
  
  const textEl = indicator.querySelector('.loading-text');
  
  if (show) {
    indicator.classList.add('active');
    if (textEl) textEl.textContent = message;
  } else {
    indicator.classList.remove('active');
  }
}

async function loadState(stateCode) {
  showMapLoadingIndicator(true, `Loading ${stateCode}...`);
  
  try {
    const res = await fetch(`data/precincts/${stateCode}/precincts.geojson`);
    if (!res.ok) {
      showMapLoadingIndicator(false);
      alert(`No precinct data available for ${stateCode}. Upload data using the PHP version.`);
      return;
    }
    const geo = await res.json();
    
    currentState = stateCode;
    currentStateMeta = { code: stateCode, abbr: stateCode, name: stateCode };
    currentGeojson = geo;
    currentAssignments = {};
    currentPlan = null;

    numDistricts = 10;
    numDistrictsInput.value = numDistricts;

    // Save the selected state to localStorage for auto-loading on next visit
    localStorage.setItem('lastSelectedState', stateCode);

    loadLocalPlansList(currentState);

    // Canvas engine
    setGeojson(currentGeojson, currentAssignments);
    renderDistrictLegend(numDistricts);
    recomputeMetrics();

    // Update Leaflet basemap overlay
    updateLeafletOverlay(currentGeojson);
    
    // Update district selector
    updateDistrictSelector();
    
    showMapLoadingIndicator(false);
  } catch (e) {
    console.error(e);
    showMapLoadingIndicator(false);
    alert('Failed to load state data.');
  }
}

function loadLocalPlansList(stateCode) {
  existingPlansSelect.innerHTML = '';
  const plans = getLocalPlansForState(stateCode);
  if (plans.length === 0) {
    existingPlansSelect.innerHTML = '<option value="">(no saved plans)</option>';
    return;
  }
  existingPlansSelect.innerHTML = '<option value="">Select a plan</option>';
  plans.forEach(p => {
    const opt = document.createElement('option');
    opt.value = p.planId;
    opt.textContent = p.name;
    existingPlansSelect.appendChild(opt);
  });
}

function getLocalPlansForState(stateCode) {
  const plans = [];
  for (let i = 0; i < localStorage.length; i++) {
    const key = localStorage.key(i);
    if (key.startsWith(`plan_${stateCode}_`)) {
      try {
        const plan = JSON.parse(localStorage.getItem(key));
        if (plan && plan.planId) {
          plans.push({ planId: plan.planId, name: plan.name || 'Untitled' });
        }
      } catch (e) {
        // skip invalid entries
      }
    }
  }
  return plans;
}

function createNewPlan() {
  currentPlan = {
    state: currentState,
    planId: 'plan_' + Date.now(),
    name: planNameInput.value || 'Untitled Plan',
    numDistricts: numDistricts,
    assignments: {},
    metrics: {},
  };
  currentAssignments = currentPlan.assignments;
  recomputeMetrics();
  redrawMap();
}

function loadPlanFromStorage(stateCode, planId) {
  const plan = loadPlanFromLocalStorage(stateCode, planId);
  if (!plan) {
    alert('Could not load plan from local storage.');
    return;
  }
  currentPlan = plan;
  currentAssignments = currentPlan.assignments || {};
  numDistricts = currentPlan.numDistricts || numDistricts;
  numDistrictsInput.value = numDistricts;

  planNameInput.value = currentPlan.name || '';
  renderDistrictLegend(numDistricts);
  recomputeMetrics();
  redrawMap();
  
  // Refresh Leaflet precinct styles to show loaded assignments
  refreshPrecinctStyles();
}

function handlePrecinctClick(precinctId, buttonMode) {
  if (!currentState) return;
  if (!currentPlan) {
    createNewPlan();
  }

  const drawMode = document.querySelector('input[name="drawMode"]:checked')?.value || 'assign';

  if (drawMode === 'erase') {
    delete currentAssignments[precinctId];
  } else {
    currentAssignments[precinctId] = selectedDistrict;
  }

  // Refresh styles on Leaflet layer
  refreshPrecinctStyles();
  
  recomputeMetrics();
  redrawMap();
}

function pickCurrentDistrictForUser() {
  return selectedDistrict;
}

function handleHoverPrecinct(precinctFeature, screenX, screenY) {
  const hoverInfo = document.getElementById('hoverInfo');
  if (!precinctFeature) {
    hoverInfo.style.display = 'none';
    return;
  }
  const props = precinctFeature.properties || {};
  const pop = props.population ?? 'N/A';
  const dem = props.dem ?? 'N/A';
  const rep = props.rep ?? 'N/A';
  const id = props.id || props.precinct_id || '(no id)';
  const assigned = currentAssignments[id] ?? 'Unassigned';

  hoverInfo.innerHTML = `
    <strong>Precinct: ${id}</strong><br>
    District: ${assigned}<br>
    Population: ${pop}<br>
    Dem votes: ${dem}<br>
    Rep votes: ${rep}
  `;
  hoverInfo.style.left = (screenX + 10) + 'px';
  hoverInfo.style.top = (screenY + 10) + 'px';
  hoverInfo.style.display = 'block';
}

function renderDistrictLegend(n) {
  districtColorLegend.innerHTML = '';
  const colors = getDistrictColors(n);
  for (let i = 1; i <= n; i++) {
    const item = document.createElement('div');
    item.className = 'legend-item' + (i === selectedDistrict ? ' selected' : '');
    item.dataset.district = i;
    
    const swatch = document.createElement('div');
    swatch.className = 'legend-color';
    swatch.style.background = colors[i];
    item.appendChild(swatch);
    
    const label = document.createElement('span');
    label.textContent = `District ${i}`;
    item.appendChild(label);
    
    // Make clickable to select district
    item.addEventListener('click', () => {
      setSelectedDistrict(i);
    });
    
    districtColorLegend.appendChild(item);
  }
  
  // Also update the district selector dropdown
  updateDistrictSelector();
}

async function saveCurrentPlanToLocalStorage() {
  if (!currentState) {
    alert('Select a state first.');
    return;
  }
  if (!currentPlan) {
    createNewPlan();
  }
  currentPlan.name = planNameInput.value || currentPlan.name;
  currentPlan.numDistricts = numDistricts;
  currentPlan.assignments = currentAssignments;
  currentPlan.metrics = await computeMetricsLocally(currentGeojson, currentAssignments, numDistricts);

  savePlanToLocalStorage(currentPlan);
  alert('Plan saved to local storage.');
  loadLocalPlansList(currentState);
}

function recomputeMetrics() {
  if (!currentGeojson) return;
  computeMetricsLocally(currentGeojson, currentAssignments, numDistricts)
    .then(metrics => {
      if (currentPlan) {
        currentPlan.metrics = metrics;
      }
      renderMetrics(metrics);
    })
    .catch(err => console.error(err));
}

function renderMetrics(metrics) {
  if (!metrics || !metrics.byDistrict || Object.keys(metrics.byDistrict).length === 0) {
    metricsPanel.innerHTML = '<p>No metrics yet. Start assigning precincts.</p>';
    return;
  }
  const rows = Object.keys(metrics.byDistrict)
    .sort((a, b) => Number(a) - Number(b))
    .map(d => {
      const m = metrics.byDistrict[d];
      return `
        <tr>
          <td style="text-align:left;">${d}</td>
          <td>${m.population.toLocaleString()}</td>
          <td>${(m.demVotes ?? 0).toLocaleString()}</td>
          <td>${(m.repVotes ?? 0).toLocaleString()}</td>
          <td>${(m.partisanLean * 100).toFixed(1)}%</td>
          <td>${m.compactness.toFixed(3)}</td>
        </tr>
      `;
    })
    .join('');

  metricsPanel.innerHTML = `
    <table>
      <thead>
        <tr>
          <th style="text-align:left;">District</th>
          <th>Population</th>
          <th>Dem votes</th>
          <th>Rep votes</th>
          <th>Dem share</th>
          <th>Compactness</th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
    </table>
  `;
}

// ------------------- Leaflet basemap + overlay -------------------

let leafletMap = null;
let leafletPrecinctLayer = null;
let selectedDistrict = 1;  // Currently selected district for painting

function initLeafletMap() {
  if (leafletMap) return;
  const div = document.getElementById('leafletMap');
  if (!div) return;

  leafletMap = L.map('leafletMap', {
    preferCanvas: true
  }).setView([37.8, -96], 4);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(leafletMap);
}

/**
 * Get the fill color for a precinct based on its district assignment
 */
function getPrecinctFillColor(precinctId) {
  if (!currentAssignments || !precinctId) return 'transparent';
  const district = currentAssignments[precinctId];
  if (!district) return 'transparent';
  const colors = getDistrictColors(numDistricts);
  return colors[district] || 'transparent';
}

/**
 * Get the current selected district
 */
function getSelectedDistrict() {
  return selectedDistrict;
}

/**
 * Set the current selected district
 */
function setSelectedDistrict(district) {
  // Validate district is within valid bounds
  if (district < 1 || district > numDistricts) {
    console.warn(`Invalid district ${district}, must be between 1 and ${numDistricts}`);
    return;
  }
  selectedDistrict = district;
  // Update legend selection state
  const legendItems = districtColorLegend.querySelectorAll('.legend-item');
  legendItems.forEach(item => {
    const d = parseInt(item.dataset.district, 10);
    if (d === selectedDistrict) {
      item.classList.add('selected');
    } else {
      item.classList.remove('selected');
    }
  });
  // Update dropdown if it exists
  updateDistrictSelector();
}

/**
 * Update the Leaflet precinct layer styles based on current assignments
 */
function refreshPrecinctStyles() {
  if (!leafletPrecinctLayer) return;
  
  leafletPrecinctLayer.eachLayer(layer => {
    const feature = layer.feature;
    if (!feature) return;
    const props = feature.properties || {};
    const precinctId = props.id || props.precinct_id;
    const fillColor = getPrecinctFillColor(precinctId);
    
    layer.setStyle({
      fillColor: fillColor === 'transparent' ? '#3388ff' : fillColor,
      fillOpacity: fillColor === 'transparent' ? 0.15 : 0.6,
      color: '#374151',
      weight: 0.5
    });
  });
}

function updateLeafletOverlay(geojson) {
  if (!geojson) return;
  initLeafletMap();
  if (!leafletMap) return;

  if (leafletPrecinctLayer) {
    leafletMap.removeLayer(leafletPrecinctLayer);
    leafletPrecinctLayer = null;
  }

  leafletPrecinctLayer = L.geoJSON(geojson, {
    style: feature => {
      const props = feature.properties || {};
      const precinctId = props.id || props.precinct_id;
      const fillColor = getPrecinctFillColor(precinctId);
      
      return {
        color: '#374151',
        weight: 0.5,
        fillColor: fillColor === 'transparent' ? '#3388ff' : fillColor,
        fillOpacity: fillColor === 'transparent' ? 0.15 : 0.6,
      };
    },
    onEachFeature: (feature, layer) => {
      const p = feature.properties || {};
      const precinctId = p.id || p.precinct_id;
      
      const lines = [];
      if (precinctId !== undefined) lines.push(`<b>Precinct:</b> ${precinctId}`);
      if (p.population !== undefined) lines.push(`<b>Population:</b> ${Number(p.population).toLocaleString()}`);
      if (p.dem !== undefined) lines.push(`<b>Dem:</b> ${Number(p.dem).toLocaleString()}`);
      if (p.rep !== undefined) lines.push(`<b>Rep:</b> ${Number(p.rep).toLocaleString()}`);
      
      const assignedDistrict = currentAssignments[precinctId];
      lines.push(`<b>District:</b> ${assignedDistrict || 'Unassigned'}`);
      
      if (lines.length) {
        layer.bindPopup(lines.join('<br>'));
      }
      
      // Add click handler for painting/erasing districts
      layer.on('click', (e) => {
        L.DomEvent.stopPropagation(e);
        handleLeafletPrecinctClick(precinctId, layer);
      });
      
      // Add hover effect
      layer.on('mouseover', () => {
        layer.setStyle({ weight: 2, color: '#1f2937' });
        layer.bringToFront();
      });
      
      layer.on('mouseout', () => {
        const fillColor = getPrecinctFillColor(precinctId);
        layer.setStyle({ 
          weight: 0.5, 
          color: '#374151',
          fillColor: fillColor === 'transparent' ? '#3388ff' : fillColor,
          fillOpacity: fillColor === 'transparent' ? 0.15 : 0.6
        });
      });
    },
  }).addTo(leafletMap);

  try {
    const bounds = leafletPrecinctLayer.getBounds();
    if (bounds.isValid()) {
      leafletMap.fitBounds(bounds, { padding: [20, 20] });
    }
  } catch (e) {
    console.warn('Could not fit Leaflet bounds:', e);
  }
}

/**
 * Handle click on a Leaflet precinct layer
 */
function handleLeafletPrecinctClick(precinctId, layer) {
  if (!precinctId) return;
  
  // Create plan if needed
  if (!currentPlan) {
    createNewPlan();
  }
  
  const drawMode = document.querySelector('input[name="drawMode"]:checked')?.value || 'assign';
  
  if (drawMode === 'erase') {
    delete currentAssignments[precinctId];
  } else {
    currentAssignments[precinctId] = selectedDistrict;
  }
  
  // Update layer style immediately
  const fillColor = getPrecinctFillColor(precinctId);
  layer.setStyle({
    fillColor: fillColor === 'transparent' ? '#3388ff' : fillColor,
    fillOpacity: fillColor === 'transparent' ? 0.15 : 0.6
  });
  
  // Update popup content
  const feature = layer.feature;
  if (feature) {
    const p = feature.properties || {};
    const lines = [];
    if (precinctId !== undefined) lines.push(`<b>Precinct:</b> ${precinctId}`);
    if (p.population !== undefined) lines.push(`<b>Population:</b> ${Number(p.population).toLocaleString()}`);
    if (p.dem !== undefined) lines.push(`<b>Dem:</b> ${Number(p.dem).toLocaleString()}`);
    if (p.rep !== undefined) lines.push(`<b>Rep:</b> ${Number(p.rep).toLocaleString()}`);
    lines.push(`<b>District:</b> ${currentAssignments[precinctId] || 'Unassigned'}`);
    layer.setPopupContent(lines.join('<br>'));
  }
  
  recomputeMetrics();
}
