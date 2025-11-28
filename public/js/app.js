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
const uploadForm = document.getElementById('uploadForm');
const uploadStatus = document.getElementById('uploadStatus');
const metricsPanel = document.getElementById('metricsPanel');
const districtColorLegend = document.getElementById('districtColorLegend');

// Enhanced import form elements
const enhancedImportForm = document.getElementById('enhancedImportForm');
const importTypeSelect = document.getElementById('importType');
const importStatus = document.getElementById('importStatus');
const geojsonImportOptions = document.getElementById('geojsonImportOptions');
const csvImportOptions = document.getElementById('csvImportOptions');
const rdhInfo = document.getElementById('rdhInfo');

// Automap elements
const fairnessPresetSelect = document.getElementById('fairnessPreset');
const useCustomTargetCheckbox = document.getElementById('useCustomTarget');
const customTargetInput = document.getElementById('customTarget');
const automapBtn = document.getElementById('automapBtn');
const automapStatus = document.getElementById('automapStatus');

// Display options elements
const showCountyBordersCheckbox = document.getElementById('showCountyBorders');
const showPrecinctLinesCheckbox = document.getElementById('showPrecinctLines');
const colorModeSelect = document.getElementById('colorMode');
const colorLegendDiv = document.getElementById('colorLegend');

document.addEventListener('DOMContentLoaded', async () => {
  // Initialize the Leaflet basemap immediately so the map is visible
  initLeafletMap();
  
  await loadStatesList();
  attachEventHandlers();
  // Initialize your existing canvas-based map
  initMapCanvas(handlePrecinctClick, handleHoverPrecinct);
  
  // Initialize color legend
  updateColorLegend();
  
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

  savePlanBtn.addEventListener('click', saveCurrentPlanToServer);

  loadPlanBtn.addEventListener('click', () => {
    const planId = existingPlansSelect.value;
    if (!planId || !currentState) {
      alert('Select a plan from the dropdown.');
      return;
    }
    loadPlan(currentState, planId);
  });

  uploadForm.addEventListener('submit', handleUpload);

  // Enhanced import form handlers
  if (importTypeSelect) {
    importTypeSelect.addEventListener('change', () => {
      const type = importTypeSelect.value;
      const isGeoJSON = type === 'geojson' || type === 'rdh_geojson';
      const isCSV = type === 'csv_merge' || type === 'rdh_csv';
      const isRDH = type === 'rdh_geojson' || type === 'rdh_csv';
      
      if (geojsonImportOptions) {
        geojsonImportOptions.style.display = isGeoJSON ? 'block' : 'none';
      }
      if (csvImportOptions) {
        csvImportOptions.style.display = isCSV ? 'block' : 'none';
      }
      if (rdhInfo) {
        rdhInfo.style.display = isRDH ? 'block' : 'none';
      }
    });
  }

  if (enhancedImportForm) {
    enhancedImportForm.addEventListener('submit', handleEnhancedImport);
  }

  // Automap event handlers
  if (useCustomTargetCheckbox && customTargetInput) {
    useCustomTargetCheckbox.addEventListener('change', () => {
      customTargetInput.disabled = !useCustomTargetCheckbox.checked;
    });
  }

  if (automapBtn) {
    automapBtn.addEventListener('click', handleAutomap);
  }

  // Display options event handlers
  if (showCountyBordersCheckbox) {
    showCountyBordersCheckbox.addEventListener('change', () => {
      setDisplayOptions({ showCountyBorders: showCountyBordersCheckbox.checked });
    });
  }

  if (showPrecinctLinesCheckbox) {
    showPrecinctLinesCheckbox.addEventListener('change', () => {
      setDisplayOptions({ showPrecinctLines: showPrecinctLinesCheckbox.checked });
    });
  }

  if (colorModeSelect) {
    colorModeSelect.addEventListener('change', () => {
      setDisplayOptions({ colorMode: colorModeSelect.value });
      updateColorLegend();
    });
  }
}

/**
 * Update color legend based on current color mode
 */
function updateColorLegend() {
  if (!colorLegendDiv) return;
  
  const colorMode = colorModeSelect ? colorModeSelect.value : 'district_set';
  
  if (colorMode === 'district_set') {
    colorLegendDiv.innerHTML = '<p style="font-size:0.75rem;color:#6b7280;">Colors assigned by district number.</p>';
    return;
  }
  
  // Show partisan lean legend
  const legendItems = [
    { label: 'Safe D (15%+)', color: '#1d4ed8' },
    { label: 'Strong D (10-15%)', color: '#2563eb' },
    { label: 'Likely D (5-10%)', color: '#3b82f6' },
    { label: 'Lean D (3-5%)', color: '#60a5fa' },
    { label: 'Slight D (1-3%)', color: '#93c5fd' },
    { label: 'Tossup (±1%)', color: '#fef08a' },
    { label: 'Slight R (1-3%)', color: '#fca5a5' },
    { label: 'Lean R (3-5%)', color: '#f87171' },
    { label: 'Likely R (5-10%)', color: '#ef4444' },
    { label: 'Strong R (10-15%)', color: '#dc2626' },
    { label: 'Safe R (15%+)', color: '#b91c1c' }
  ];
  
  let html = '<div class="partisan-legend">';
  legendItems.forEach(item => {
    html += `<div class="legend-row">
      <span class="legend-swatch" style="background:${item.color};"></span>
      <span class="legend-label">${item.label}</span>
    </div>`;
  });
  html += '</div>';
  
  colorLegendDiv.innerHTML = html;
}

async function loadStatesList() {
  try {
    const res = await fetch('api/list_states.php');
    const data = await res.json();
    if (data.error) {
      console.error(data.error);
    }
    if (!data || !Array.isArray(data.states)) return;

    stateSelect.innerHTML = '<option value="">Select a state</option>';
    data.states.forEach(st => {
      const opt = document.createElement('option');
      opt.value = st.code || st.abbr; // two-letter code preferred
      opt.textContent = `${st.abbr || st.code} - ${st.name}`;
      stateSelect.appendChild(opt);
    });
  } catch (e) {
    console.error(e);
    alert('Could not load states list. Check api/list_states.php and states.json.');
  }
}

async function loadState(stateCode) {
  try {
    const res = await fetch(`api/load_state.php?state=${encodeURIComponent(stateCode)}`);
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    // data.state.code should match e.g. "NC"
    currentState = data.state.code;
    currentStateMeta = data.state;
    currentGeojson = data.precincts;
    currentAssignments = {};
    currentPlan = null;

    numDistricts = data.defaultNumDistricts || 10;
    numDistrictsInput.value = numDistricts;

    // Save the selected state to localStorage for auto-loading on next visit
    localStorage.setItem('lastSelectedState', stateCode);

    await loadStatePlansList(currentState);

    // Canvas engine
    setGeojson(currentGeojson, currentAssignments);
    renderDistrictLegend(numDistricts);
    recomputeMetrics();

    // NEW: also update Leaflet basemap overlay
    updateLeafletOverlay(currentGeojson);
  } catch (e) {
    console.error(e);
    alert('Failed to load state data. Check api/load_state.php.');
  }
}

async function loadStatePlansList(stateCode) {
  existingPlansSelect.innerHTML = '';
  try {
    const res = await fetch(`api/load_plan.php?state=${encodeURIComponent(stateCode)}&list=1`);
    const data = await res.json();
    if (data.error) {
      console.warn(data.error);
    }
    if (!data.plans) {
      existingPlansSelect.innerHTML = '<option value="">(no saved plans)</option>';
      return;
    }
    existingPlansSelect.innerHTML = '<option value="">Select a plan</option>';
    data.plans.forEach(p => {
      const opt = document.createElement('option');
      opt.value = p.planId;
      opt.textContent = p.name;
      existingPlansSelect.appendChild(opt);
    });
  } catch (e) {
    console.error(e);
  }
}

function createNewPlan() {
  currentPlan = {
    state: currentState,
    planId: null,
    name: planNameInput.value || 'Untitled Plan',
    numDistricts: numDistricts,
    assignments: {},
    metrics: {},
  };
  currentAssignments = currentPlan.assignments;
  recomputeMetrics();
  redrawMap();
}

async function loadPlan(stateCode, planId) {
  try {
    const res = await fetch(`api/load_plan.php?state=${encodeURIComponent(stateCode)}&planId=${encodeURIComponent(planId)}`);
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    currentPlan = data.plan;
    currentAssignments = currentPlan.assignments || {};
    numDistricts = currentPlan.numDistricts || numDistricts;
    numDistrictsInput.value = numDistricts;

    planNameInput.value = currentPlan.name || '';
    renderDistrictLegend(numDistricts);
    recomputeMetrics();
    redrawMap();
  } catch (e) {
    console.error(e);
    alert('Could not load plan. Check api/load_plan.php and file permissions.');
  }
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
  // For now, always district 1.
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

async function saveCurrentPlanToServer() {
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

  try {
    const res = await fetch('api/save_plan.php', {
      method: 'POST',
      body: JSON.stringify(currentPlan),
      headers: { 'Content-Type': 'application/json' }
    });
    const data = await res.json();
    if (data.error) {
      alert(data.error);
      return;
    }
    currentPlan.planId = data.planId;
    alert('Plan saved.');
    await loadStatePlansList(currentState);
  } catch (e) {
    console.error(e);
    alert('Could not save plan. Check api/save_plan.php and file permissions.');
  }
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

async function handleUpload(e) {
  e.preventDefault();
  
  const progressContainer = document.getElementById('uploadProgressContainer');
  const progressFill = document.getElementById('uploadProgressFill');
  const progressText = document.getElementById('uploadProgressText');
  
  uploadStatus.textContent = '';
  progressContainer.className = 'upload-progress-container active';
  progressFill.style.width = '0%';
  progressText.textContent = '0%';
  
  const formData = new FormData(uploadForm);
  
  const xhr = new XMLHttpRequest();
  
  xhr.upload.addEventListener('progress', (event) => {
    if (event.lengthComputable) {
      const percentComplete = Math.round((event.loaded / event.total) * 100);
      progressFill.style.width = percentComplete + '%';
      progressText.textContent = percentComplete + '%';
    }
  });
  
  xhr.addEventListener('load', () => {
    progressContainer.className = 'upload-progress-container active complete';
    progressFill.style.width = '100%';
    progressText.textContent = '100%';
    
    const text = xhr.responseText;
    console.log('upload_precincts.php raw response:', text);
    
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      console.error('Upload response was not valid JSON:', parseErr);
      progressContainer.className = 'upload-progress-container active error';
      uploadStatus.textContent = 'Server error: response is not JSON. See console.';
      return;
    }
    
    if (data.error) {
      progressContainer.className = 'upload-progress-container active error';
      uploadStatus.textContent = 'Error: ' + data.error;
      console.error('Upload error object:', data);
      return;
    }
    
    uploadStatus.textContent =
      `Uploaded and converted successfully. ${data.featureCount || 0} features.`;
    if (data.stateCode === currentState) {
      loadState(currentState);
    }
  });
  
  xhr.addEventListener('error', () => {
    progressContainer.className = 'upload-progress-container active error';
    console.error('Upload failed: network error');
    uploadStatus.textContent = 'Upload failed (network error). See console.';
  });
  
  xhr.open('POST', 'api/upload_precincts.php');
  xhr.send(formData);
}

async function handleEnhancedImport(e) {
  e.preventDefault();
  if (!importStatus) return;
  
  const progressContainer = document.getElementById('importProgressContainer');
  const progressFill = document.getElementById('importProgressFill');
  const progressText = document.getElementById('importProgressText');
  
  importStatus.textContent = '';
  progressContainer.className = 'upload-progress-container active';
  progressFill.style.width = '0%';
  progressText.textContent = '0%';
  
  const formData = new FormData(enhancedImportForm);
  
  const xhr = new XMLHttpRequest();
  
  xhr.upload.addEventListener('progress', (event) => {
    if (event.lengthComputable) {
      const percentComplete = Math.round((event.loaded / event.total) * 100);
      progressFill.style.width = percentComplete + '%';
      progressText.textContent = percentComplete + '%';
    }
  });
  
  xhr.addEventListener('load', () => {
    progressContainer.className = 'upload-progress-container active complete';
    progressFill.style.width = '100%';
    progressText.textContent = '100%';
    
    const text = xhr.responseText;
    console.log('import_data.php raw response:', text);
    
    let data;
    try {
      data = JSON.parse(text);
    } catch (parseErr) {
      console.error('Import response was not valid JSON:', parseErr);
      progressContainer.className = 'upload-progress-container active error';
      importStatus.textContent = 'Server error: response is not JSON. See console.';
      return;
    }
    
    if (!data.ok) {
      progressContainer.className = 'upload-progress-container active error';
      importStatus.textContent = 'Error: ' + (data.error || 'Unknown error');
      console.error('Import error object:', data);
      return;
    }
    
    // Build success message based on import type
    let successMsg = 'Import successful. ';
    if (data.importType === 'geojson') {
      successMsg += `${data.featureCount || 0} features imported.`;
      if (data.warnings && data.warnings.length > 0) {
        successMsg += ` Warnings: ${data.warnings.length}`;
        console.warn('Import warnings:', data.warnings);
      }
    } else if (data.importType === 'csv_merge') {
      successMsg += `${data.matchCount || 0} features matched. ${data.unmatchedCount || 0} unmatched.`;
    }
    
    importStatus.textContent = successMsg;
    
    // Reload state data if we just imported data for the current state
    if (data.stateCode === currentState) {
      loadState(currentState);
    }
    
    // Refresh states list in case a new state was added
    loadStatesList();
  });
  
  xhr.addEventListener('error', () => {
    progressContainer.className = 'upload-progress-container active error';
    console.error('Import failed: network error');
    importStatus.textContent = 'Import failed (network error). See console.';
  });
  
  xhr.open('POST', 'api/import_data.php');
  xhr.send(formData);
}

// ------------------- Automap Generator -------------------

function handleAutomap() {
  if (!currentState || !currentGeojson) {
    alert('Please load a state first before generating an automap.');
    return;
  }

  if (!window.Automap) {
    alert('Automap module not loaded. Please refresh the page.');
    return;
  }

  if (automapStatus) {
    automapStatus.textContent = 'Generating map...';
    automapStatus.style.color = '#0369a1';
  }

  // Get settings
  const fairnessPreset = fairnessPresetSelect ? fairnessPresetSelect.value : 'fair';
  let customTargetDemShare = null;
  
  if (useCustomTargetCheckbox && useCustomTargetCheckbox.checked && customTargetInput) {
    customTargetDemShare = parseFloat(customTargetInput.value) / 100;
    if (isNaN(customTargetDemShare) || customTargetDemShare < 0 || customTargetDemShare > 1) {
      customTargetDemShare = null;
    }
  }

  // Use setTimeout to allow UI to update before blocking algorithm runs
  setTimeout(() => {
    try {
      const startTime = performance.now();
      
      // Create automap instance and generate
      const automap = new Automap(
        currentGeojson,
        numDistricts,
        fairnessPreset,
        customTargetDemShare
      );
      
      const newAssignments = automap.generate();
      const summary = automap.getSummary();
      
      const endTime = performance.now();
      const duration = ((endTime - startTime) / 1000).toFixed(2);
      
      // Apply the generated assignments
      currentAssignments = newAssignments;
      
      // Create or update current plan
      if (!currentPlan) {
        createNewPlan();
      }
      currentPlan.assignments = currentAssignments;
      const presetLabel = window.FAIRNESS_PRESETS && window.FAIRNESS_PRESETS[fairnessPreset] 
        ? window.FAIRNESS_PRESETS[fairnessPreset].label 
        : 'Custom';
      currentPlan.name = `Automap - ${presetLabel}`;
      planNameInput.value = currentPlan.name;
      
      // Redraw and update metrics
      recomputeMetrics();
      redrawMap();
      
      // Show success message with summary
      const statusMsg = `✓ Generated in ${duration}s. ` +
        `Dem seats: ${summary.summary.demSeats}, ` +
        `Rep seats: ${summary.summary.repSeats}, ` +
        `Tossup: ${summary.summary.tossupSeats}. ` +
        `Avg Dem share: ${(summary.summary.averageDemShare * 100).toFixed(1)}%`;
      
      if (automapStatus) {
        automapStatus.textContent = statusMsg;
        automapStatus.style.color = '#16a34a';
      }
      
      console.log('Automap summary:', summary);
      
    } catch (error) {
      console.error('Automap generation failed:', error);
      if (automapStatus) {
        automapStatus.textContent = 'Error: ' + error.message;
        automapStatus.style.color = '#dc2626';
      }
    }
  }, 50);
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