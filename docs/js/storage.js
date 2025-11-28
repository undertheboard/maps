function savePlanToLocalStorage(plan) {
  if (!plan || !plan.state || !plan.planId) return;
  const key = `plan_${plan.state}_${plan.planId}`;
  localStorage.setItem(key, JSON.stringify(plan));
}

function loadPlanFromLocalStorage(state, planId) {
  const key = `plan_${state}_${planId}`;
  const value = localStorage.getItem(key);
  if (!value) return null;
  try {
    return JSON.parse(value);
  } catch {
    return null;
  }
}

window.savePlanToLocalStorage = savePlanToLocalStorage;
window.loadPlanFromLocalStorage = loadPlanFromLocalStorage;