async function computeMetricsLocally(geojson, assignments, numDistricts) {
  if (!geojson || !geojson.features) {
    return { byDistrict: {} };
  }

  const byDistrict = {};

  function ensureD(d) {
    if (!byDistrict[d]) {
      byDistrict[d] = {
        population: 0,
        demVotes: 0,
        repVotes: 0,
        partisanLean: 0,
        compactness: 0,
        area: 0,
        perimeter: 0
      };
    }
  }

  geojson.features.forEach(f => {
    const props = f.properties || {};
    const id = props.id || props.precinct_id;
    const d = assignments[id];
    if (!d) return;

    ensureD(d);

    const pop = Number(props.population || 0);
    const dem = Number(props.dem || props.dem_votes || 0);
    const rep = Number(props.rep || props.rep_votes || 0);

    byDistrict[d].population += pop;
    byDistrict[d].demVotes += dem;
    byDistrict[d].repVotes += rep;

    const geom = f.geometry;
    const ap = approximateAreaPerimeter(geom);
    byDistrict[d].area += ap.area;
    byDistrict[d].perimeter += ap.perimeter;
  });

  Object.keys(byDistrict).forEach(d => {
    const m = byDistrict[d];
    const totalVotes = m.demVotes + m.repVotes;
    m.partisanLean = totalVotes > 0 ? m.demVotes / totalVotes : 0.5;
    if (m.perimeter > 0) {
      m.compactness = (4 * Math.PI * m.area) / (m.perimeter * m.perimeter);
    } else {
      m.compactness = 0;
    }
  });

  return { byDistrict };
}

function approximateAreaPerimeter(geom) {
  if (!geom) return { area: 0, perimeter: 0 };
  let area = 0;
  let perimeter = 0;
  const polys = geom.type === 'MultiPolygon'
    ? geom.coordinates
    : [geom.coordinates];

  polys.forEach(rings => {
    const ring = rings[0];
    if (!ring || ring.length < 3) return;
    let a = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
      const xi = ring[i][0], yi = ring[i][1];
      const xj = ring[j][0], yj = ring[j][1];
      a += xi * yj - xj * yi;
      const dx = xi - xj, dy = yi - yj;
      perimeter += Math.sqrt(dx * dx + dy * dy);
    }
    area += Math.abs(a) / 2;
  });

  return { area, perimeter };
}

window.computeMetricsLocally = computeMetricsLocally;