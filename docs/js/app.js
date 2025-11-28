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

async function loadState(stateCode) {
  try {
    const res = await fetch(`data/precincts/${stateCode}/precincts.geojson`);
    if (!res.ok) {
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
  } catch (e) {
    console.error(e);
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
    const targetDistrict = pickCurrentDistrictForUser();
    currentAssignments[precinctId] = targetDistrict;
  }

  recomputeMetrics();
  redrawMap();
}

function pickCurrentDistrictForUser() {
  return 1;
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
    item.className = 'legend-item';
    const swatch = document.createElement('div');
    swatch.className = 'legend-color';
    swatch.style.background = colors[i];
    item.appendChild(swatch);
    const label = document.createElement('span');
    label.textContent = `District ${i}`;
    item.appendChild(label);
    districtColorLegend.appendChild(item);
  }
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

function initLeafletMap() {
  if (leafletMap) return;
  const div = document.getElementById('leafletMap');
  if (!div) return;

  leafletMap = L.map('leafletMap').setView([37.8, -96], 4);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(leafletMap);
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
    style: feature => ({
      color: '#555',
      weight: 0.5,
      fillColor: '#3388ff',
      fillOpacity: 0.4,
    }),
    onEachFeature: (feature, layer) => {
      const p = feature.properties || {};
      const lines = [];
      if (p.id !== undefined) lines.push(`<b>ID:</b> ${p.id}`);
      if (p.population !== undefined) lines.push(`<b>Population:</b> ${p.population}`);
      if (p.dem !== undefined) lines.push(`<b>Dem:</b> ${p.dem}`);
      if (p.rep !== undefined) lines.push(`<b>Rep:</b> ${p.rep}`);
      if (lines.length) {
        layer.bindPopup(lines.join('<br>'));
      }
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
