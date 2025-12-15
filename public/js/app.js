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

  // Tab switching
  const tabBtns = document.querySelectorAll('.tab-btn');
  tabBtns.forEach(btn => {
    btn.addEventListener('click', () => {
      const tabId = btn.getAttribute('data-tab');
      
      // Update button states
      tabBtns.forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      
      // Update content visibility
      document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
      });
      const targetContent = document.getElementById('tab-' + tabId);
      if (targetContent) {
        targetContent.classList.add('active');
      }
    });
  });

  // RDH Direct Import handlers
  const rdhListBtn = document.getElementById('rdhListBtn');
  const rdhImportBtn = document.getElementById('rdhImportBtn');
  
  if (rdhListBtn) {
    rdhListBtn.addEventListener('click', handleRDHListDatasets);
  }
  
  if (rdhImportBtn) {
    rdhImportBtn.addEventListener('click', handleRDHImport);
  }

  // Collapsible panel toggles
  document.querySelectorAll('.panel.collapsible .panel-toggle').forEach(toggle => {
    toggle.addEventListener('click', () => {
      const panel = toggle.closest('.panel');
      const content = panel.querySelector('.panel-content');
      const icon = toggle.querySelector('.toggle-icon');
      
      if (content.style.display === 'none') {
        content.style.display = 'block';
        icon.textContent = '▼';
      } else {
        content.style.display = 'none';
        icon.textContent = '▶';
      }
    });
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
      // Also switch to assign mode
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

/**
 * Show/hide/update the map loading indicator
 */
function showMapLoadingIndicator(show, progress = 0, message = 'Loading...') {
  let indicator = document.getElementById('mapLoadingIndicator');
  
  if (!indicator) {
    // Create the loading indicator if it doesn't exist
    indicator = document.createElement('div');
    indicator.id = 'mapLoadingIndicator';
    indicator.className = 'map-loading-indicator';
    indicator.innerHTML = `
      <div class="loading-content">
        <div class="loading-spinner"></div>
        <div class="loading-text">Loading...</div>
        <div class="loading-progress-bar">
          <div class="loading-progress-fill"></div>
        </div>
        <div class="loading-progress-text">0%</div>
      </div>
    `;
    const mapPanel = document.querySelector('.map-panel');
    if (mapPanel) {
      mapPanel.appendChild(indicator);
    }
  }
  
  const textEl = indicator.querySelector('.loading-text');
  const progressFill = indicator.querySelector('.loading-progress-fill');
  const progressText = indicator.querySelector('.loading-progress-text');
  
  if (show) {
    indicator.classList.add('active');
    if (textEl) textEl.textContent = message;
    if (progressFill) progressFill.style.width = progress + '%';
    if (progressText) progressText.textContent = Math.round(progress) + '%';
  } else {
    indicator.classList.remove('active');
  }
}

async function loadState(stateCode) {
  // Show loading indicator
  showMapLoadingIndicator(true, 10, `Loading ${stateCode} state data...`);
  
  try {
    showMapLoadingIndicator(true, 30, 'Fetching precinct data...');
    const res = await fetch(`api/load_state.php?state=${encodeURIComponent(stateCode)}`);
    
    showMapLoadingIndicator(true, 50, 'Processing data...');
    const data = await res.json();
    
    if (data.error) {
      showMapLoadingIndicator(false);
      alert(data.error);
      return;
    }
    
    showMapLoadingIndicator(true, 60, 'Setting up state...');
    
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

    showMapLoadingIndicator(true, 70, 'Loading saved plans...');
    await loadStatePlansList(currentState);

    showMapLoadingIndicator(true, 80, 'Rendering map...');
    
    // Canvas engine
    setGeojson(currentGeojson, currentAssignments);
    renderDistrictLegend(numDistricts);
    
    showMapLoadingIndicator(true, 90, 'Computing metrics...');
    recomputeMetrics();

    // Update Leaflet basemap overlay
    showMapLoadingIndicator(true, 95, 'Finalizing map display...');
    updateLeafletOverlay(currentGeojson);
    
    // Update district selector
    updateDistrictSelector();
    
    showMapLoadingIndicator(true, 100, 'Complete!');
    
    // Hide after a brief delay
    setTimeout(() => {
      showMapLoadingIndicator(false);
    }, 500);
    
  } catch (e) {
    console.error(e);
    showMapLoadingIndicator(false);
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
    
    // Refresh Leaflet precinct styles to show loaded assignments
    refreshPrecinctStyles();
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
  
  // Also update the district selector dropdown if it exists
  updateDistrictSelector();
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
      
      // Refresh Leaflet precinct styles
      refreshPrecinctStyles();
      
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
let selectedDistrict = 1;  // Currently selected district for painting

function initLeafletMap() {
  if (leafletMap) return;
  const div = document.getElementById('leafletMap');
  if (!div) return;

  leafletMap = L.map('leafletMap', {
    preferCanvas: true  // Better performance for many features
  }).setView([37.8, -96], 4);
  
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors',
  }).addTo(leafletMap);
}

/**
 * Get the fill color for a district based on CSV data
 * @param {string} districtId - The district ID from the feature
 * @returns {string} - Color hex code or null if not found
 */
function getDistrictColorFromCSV(districtId) {
  if (!window.districtsData || !districtId) return null;
  
  const district = window.districtsData[districtId];
  if (!district) return null;
  
  const dem = district.dem;
  const rep = district.rep;
  
  // Color based on political lean: Dem > Rep ? Blue : Red
  if (dem > rep) {
    return '#0074D9'; // Blue for Democratic
  } else {
    return '#FF4136'; // Red for Republican
  }
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
    
    // Check for district color first, then fall back to precinct color
    const districtId = props.ID || props.id;
    let fillColor = getDistrictColorFromCSV(districtId);
    if (!fillColor) {
      fillColor = getPrecinctFillColor(precinctId);
    }
    
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
      
      // First, try to get color from district CSV data (if feature represents a district)
      const districtId = props.ID || props.id;
      let fillColor = getDistrictColorFromCSV(districtId);
      
      // Fall back to precinct coloring if no district data found
      if (!fillColor) {
        fillColor = getPrecinctFillColor(precinctId);
      }
      
      return {
        color: '#374151',
        weight: 0.5,
        fillColor: fillColor === 'transparent' ? '#3388ff' : fillColor,
        fillOpacity: fillColor === 'transparent' ? 0.15 : 0.6,  // More transparent for unassigned
      };
    },
    onEachFeature: (feature, layer) => {
      const p = feature.properties || {};
      const precinctId = p.id || p.precinct_id;
      
      // Build popup content
      const lines = [];
      
      // Check if this feature has district data from CSV
      const districtId = p.ID || p.id;
      const districtData = window.districtsData && window.districtsData[districtId];
      
      if (districtData) {
        // Show district information from CSV
        lines.push(`<b>District ID:</b> ${districtData.id}`);
        lines.push(`<b>Total Population:</b> ${districtData.total_pop.toLocaleString()}`);
        lines.push(`<b>Dem:</b> ${(districtData.dem * 100).toFixed(2)}%`);
        lines.push(`<b>Rep:</b> ${(districtData.rep * 100).toFixed(2)}%`);
        
        // Calculate margin
        const margin = Math.abs(districtData.dem - districtData.rep) * 100;
        const leader = districtData.dem > districtData.rep ? 'Dem' : 'Rep';
        lines.push(`<b>Margin:</b> ${leader} +${margin.toFixed(2)}%`);
      } else {
        // Show precinct information
        if (precinctId !== undefined) lines.push(`<b>Precinct:</b> ${precinctId}`);
        if (p.population !== undefined) lines.push(`<b>Population:</b> ${Number(p.population).toLocaleString()}`);
        if (p.dem !== undefined) lines.push(`<b>Dem:</b> ${Number(p.dem).toLocaleString()}`);
        if (p.rep !== undefined) lines.push(`<b>Rep:</b> ${Number(p.rep).toLocaleString()}`);
        
        const assignedDistrict = currentAssignments[precinctId];
        lines.push(`<b>District:</b> ${assignedDistrict || 'Unassigned'}`);
      }
      
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
        updateHoverInfo(feature, layer);
      });
      
      layer.on('mouseout', () => {
        // Check for district color first, then fall back to precinct color
        const districtId = p.ID || p.id;
        let fillColor = getDistrictColorFromCSV(districtId);
        if (!fillColor) {
          fillColor = getPrecinctFillColor(precinctId);
        }
        
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

/**
 * Update hover info panel
 */
function updateHoverInfo(feature, layer) {
  const hoverInfo = document.getElementById('hoverInfo');
  if (!hoverInfo) return;
  
  const p = feature.properties || {};
  const precinctId = p.id || p.precinct_id;
  const pop = p.population ?? 'N/A';
  const dem = p.dem ?? 'N/A';
  const rep = p.rep ?? 'N/A';
  const assigned = currentAssignments[precinctId] ?? 'Unassigned';
  
  hoverInfo.innerHTML = `
    <strong>Precinct: ${precinctId}</strong><br>
    District: ${assigned}<br>
    Population: ${typeof pop === 'number' ? pop.toLocaleString() : pop}<br>
    Dem votes: ${typeof dem === 'number' ? dem.toLocaleString() : dem}<br>
    Rep votes: ${typeof rep === 'number' ? rep.toLocaleString() : rep}
  `;
  hoverInfo.style.display = 'block';
  hoverInfo.style.left = '10px';
  hoverInfo.style.top = '50px';
}

// ------------------- RDH Direct Import -------------------

let rdhDatasets = [];

async function handleRDHListDatasets() {
  const rdhStatus = document.getElementById('rdhStatus');
  const rdhDatasetList = document.getElementById('rdhDatasetList');
  const rdhImportBtn = document.getElementById('rdhImportBtn');
  const rdhProgressContainer = document.getElementById('rdhProgressContainer');
  
  const username = document.getElementById('rdhUsername')?.value?.trim();
  const password = document.getElementById('rdhPassword')?.value?.trim();
  const state = document.getElementById('rdhState')?.value?.trim();
  
  if (!username || !password || !state) {
    if (rdhStatus) {
      rdhStatus.textContent = 'Please fill in all fields (username, password, state).';
      rdhStatus.className = 'status-message error';
    }
    return;
  }
  
  if (rdhStatus) {
    rdhStatus.textContent = 'Connecting to RDH API...';
    rdhStatus.className = 'status-message info';
  }
  
  if (rdhProgressContainer) {
    rdhProgressContainer.className = 'upload-progress-container active';
    document.getElementById('rdhProgressFill').style.width = '30%';
    document.getElementById('rdhProgressText').textContent = 'Loading...';
  }
  
  const formData = new FormData();
  formData.append('action', 'list_datasets');
  formData.append('rdh_username', username);
  formData.append('rdh_password', password);
  formData.append('state', state);
  
  try {
    const res = await fetch('api/rdh_import.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await res.json();
    
    if (rdhProgressContainer) {
      document.getElementById('rdhProgressFill').style.width = '100%';
      document.getElementById('rdhProgressText').textContent = '100%';
    }
    
    if (!data.ok) {
      if (rdhStatus) {
        rdhStatus.textContent = 'Error: ' + (data.error || 'Unknown error');
        rdhStatus.className = 'status-message error';
      }
      if (rdhProgressContainer) {
        rdhProgressContainer.className = 'upload-progress-container active error';
      }
      return;
    }
    
    rdhDatasets = data.datasets || [];
    
    // Build dataset list UI
    let html = '';
    
    // Show recommended datasets first
    if (data.recommended && data.recommended.length > 0) {
      html += '<div class="dataset-section"><strong style="font-size:0.75rem;color:#16a34a;">Recommended:</strong>';
      data.recommended.forEach(rec => {
        const ds = rec.dataset;
        html += `
          <div class="dataset-item">
            <input type="checkbox" value="${ds.id}" data-title="${ds.title}" checked>
            <span class="dataset-title">${ds.title}</span>
            <span class="dataset-format">${ds.format}</span>
          </div>
        `;
      });
      html += '</div>';
    }
    
    // Show all datasets
    if (rdhDatasets.length > 0) {
      html += `<div class="dataset-section" style="margin-top:0.5rem;"><strong style="font-size:0.75rem;color:#64748b;">All Datasets (${rdhDatasets.length}):</strong>`;
      rdhDatasets.slice(0, 50).forEach(ds => {
        html += `
          <div class="dataset-item">
            <input type="checkbox" value="${ds.id}" data-title="${ds.title}">
            <span class="dataset-title">${ds.title}</span>
            <span class="dataset-format">${ds.format}</span>
          </div>
        `;
      });
      if (rdhDatasets.length > 50) {
        html += `<p style="font-size:0.75rem;color:#94a3b8;padding:0.5rem;">Showing first 50 of ${rdhDatasets.length} datasets</p>`;
      }
      html += '</div>';
    }
    
    if (rdhDatasetList) {
      rdhDatasetList.innerHTML = html;
      rdhDatasetList.style.display = 'block';
    }
    
    if (rdhImportBtn) {
      rdhImportBtn.style.display = 'inline-block';
    }
    
    if (rdhStatus) {
      rdhStatus.textContent = `Found ${rdhDatasets.length} datasets for ${state}. Select datasets and click Import.`;
      rdhStatus.className = 'status-message success';
    }
    
    if (rdhProgressContainer) {
      rdhProgressContainer.className = 'upload-progress-container active complete';
    }
    
  } catch (e) {
    console.error('RDH list datasets failed:', e);
    if (rdhStatus) {
      rdhStatus.textContent = 'Failed to connect to RDH API. ' + e.message;
      rdhStatus.className = 'status-message error';
    }
    if (rdhProgressContainer) {
      rdhProgressContainer.className = 'upload-progress-container active error';
    }
  }
}

async function handleRDHImport() {
  const rdhStatus = document.getElementById('rdhStatus');
  const rdhDatasetList = document.getElementById('rdhDatasetList');
  const rdhProgressContainer = document.getElementById('rdhProgressContainer');
  
  const username = document.getElementById('rdhUsername')?.value?.trim();
  const password = document.getElementById('rdhPassword')?.value?.trim();
  const state = document.getElementById('rdhState')?.value?.trim();
  
  // Get selected datasets
  const checkboxes = rdhDatasetList?.querySelectorAll('input[type="checkbox"]:checked');
  const selectedIds = Array.from(checkboxes || []).map(cb => cb.value).filter(v => v);
  
  if (selectedIds.length === 0) {
    if (rdhStatus) {
      rdhStatus.textContent = 'Please select at least one dataset to import.';
      rdhStatus.className = 'status-message error';
    }
    return;
  }
  
  if (rdhStatus) {
    rdhStatus.textContent = `Importing ${selectedIds.length} dataset(s)...`;
    rdhStatus.className = 'status-message info';
  }
  
  if (rdhProgressContainer) {
    rdhProgressContainer.className = 'upload-progress-container active';
    document.getElementById('rdhProgressFill').style.width = '10%';
    document.getElementById('rdhProgressText').textContent = 'Starting...';
  }
  
  const formData = new FormData();
  formData.append('action', 'import');
  formData.append('rdh_username', username);
  formData.append('rdh_password', password);
  formData.append('state', state);
  formData.append('dataset_ids', selectedIds.join(','));
  
  try {
    const res = await fetch('api/rdh_import.php', {
      method: 'POST',
      body: formData
    });
    
    const data = await res.json();
    
    if (rdhProgressContainer) {
      document.getElementById('rdhProgressFill').style.width = '100%';
      document.getElementById('rdhProgressText').textContent = '100%';
    }
    
    if (!data.ok) {
      if (rdhStatus) {
        rdhStatus.textContent = 'Error: ' + (data.error || 'Import failed');
        rdhStatus.className = 'status-message error';
      }
      if (rdhProgressContainer) {
        rdhProgressContainer.className = 'upload-progress-container active error';
      }
      return;
    }
    
    if (rdhStatus) {
      rdhStatus.textContent = `Success! Imported ${data.imported}/${data.total} datasets. ${data.featureCount} features loaded.`;
      rdhStatus.className = 'status-message success';
    }
    
    if (rdhProgressContainer) {
      rdhProgressContainer.className = 'upload-progress-container active complete';
    }
    
    // Reload state if it matches
    if (data.stateCode) {
      // Refresh states list and try to load the imported state
      await loadStatesList();
      
      // Try to select and load the state
      const stateSelect = document.getElementById('stateSelect');
      if (stateSelect) {
        const option = stateSelect.querySelector(`option[value="${data.stateCode}"]`);
        if (option) {
          stateSelect.value = data.stateCode;
          loadState(data.stateCode);
        }
      }
    }
    
  } catch (e) {
    console.error('RDH import failed:', e);
    if (rdhStatus) {
      rdhStatus.textContent = 'Import failed: ' + e.message;
      rdhStatus.className = 'status-message error';
    }
    if (rdhProgressContainer) {
      rdhProgressContainer.className = 'upload-progress-container active error';
    }
  }
}