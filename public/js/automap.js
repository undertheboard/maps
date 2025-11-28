/**
 * Automap Algorithm for District Generation
 * 
 * Generates district maps based on partisan fairness goals while respecting
 * county borders as much as possible.
 * 
 * Fairness levels:
 * - Very R: Target 60%+ Republican lean
 * - Lean R: Target 52-55% Republican lean  
 * - Fair: Target 48-52% balanced
 * - Lean D: Target 52-55% Democratic lean
 * - Very D: Target 60%+ Democratic lean
 */

// Fairness preset configurations
const FAIRNESS_PRESETS = {
  'very_r': {
    label: 'Very R',
    targetDemShare: 0.40,  // 40% Dem = 60% Rep
    tolerance: 0.05,
    description: 'Strongly Republican-favoring map'
  },
  'lean_r': {
    label: 'Lean R',
    targetDemShare: 0.46,  // 46% Dem = 54% Rep
    tolerance: 0.03,
    description: 'Slightly Republican-favoring map'
  },
  'fair': {
    label: 'Fair',
    targetDemShare: 0.50,  // 50-50
    tolerance: 0.02,
    description: 'Balanced, competitive districts'
  },
  'lean_d': {
    label: 'Lean D',
    targetDemShare: 0.54,  // 54% Dem
    tolerance: 0.03,
    description: 'Slightly Democratic-favoring map'
  },
  'very_d': {
    label: 'Very D',
    targetDemShare: 0.60,  // 60% Dem
    tolerance: 0.05,
    description: 'Strongly Democratic-favoring map'
  }
};

/**
 * Main Automap class
 */
class Automap {
  constructor(geojson, numDistricts, fairnessPreset = 'fair', customTargetDemShare = null) {
    this.geojson = geojson;
    this.numDistricts = numDistricts;
    this.preset = FAIRNESS_PRESETS[fairnessPreset] || FAIRNESS_PRESETS['fair'];
    
    // Allow custom target to override preset
    if (customTargetDemShare !== null) {
      this.targetDemShare = customTargetDemShare;
    } else {
      this.targetDemShare = this.preset.targetDemShare;
    }
    
    this.tolerance = this.preset.tolerance;
    this.assignments = {};
    this.precincts = [];
    this.counties = new Map(); // county -> [precinct indices]
    
    this._prepareData();
  }

  /**
   * Prepare precinct data for algorithm
   */
  _prepareData() {
    if (!this.geojson || !this.geojson.features) return;

    this.geojson.features.forEach((feature, index) => {
      const props = feature.properties || {};
      const id = props.id || props.precinct_id || `p_${index}`;
      
      const precinct = {
        index: index,
        id: id,
        population: Number(props.population || 0),
        dem: Number(props.dem || props.dem_votes || 0),
        rep: Number(props.rep || props.rep_votes || 0),
        county: props.county || props.COUNTY || props.COUNTYFP || props.COUNTYFP20 || 'unknown',
        geometry: feature.geometry,
        centroid: this._getCentroid(feature.geometry),
        neighbors: [], // Will be populated later
        district: null
      };
      
      // Calculate dem share for this precinct
      const totalVotes = precinct.dem + precinct.rep;
      precinct.demShare = totalVotes > 0 ? precinct.dem / totalVotes : 0.5;
      
      this.precincts.push(precinct);
      
      // Group by county
      if (!this.counties.has(precinct.county)) {
        this.counties.set(precinct.county, []);
      }
      this.counties.get(precinct.county).push(index);
    });

    // Build adjacency graph
    this._buildAdjacencyGraph();
  }

  /**
   * Get centroid of a geometry
   */
  _getCentroid(geometry) {
    if (!geometry || !geometry.coordinates) {
      return { x: 0, y: 0 };
    }

    let coords = [];
    if (geometry.type === 'Polygon') {
      coords = geometry.coordinates[0];
    } else if (geometry.type === 'MultiPolygon') {
      coords = geometry.coordinates[0][0];
    }

    if (!coords || coords.length === 0) {
      return { x: 0, y: 0 };
    }

    let sumX = 0, sumY = 0;
    coords.forEach(c => {
      sumX += c[0];
      sumY += c[1];
    });
    
    return {
      x: sumX / coords.length,
      y: sumY / coords.length
    };
  }

  /**
   * Build adjacency graph based on geometry proximity
   * Uses spatial proximity rather than exact topology for efficiency
   */
  _buildAdjacencyGraph() {
    const threshold = 0.01; // Degree threshold for adjacency
    
    for (let i = 0; i < this.precincts.length; i++) {
      for (let j = i + 1; j < this.precincts.length; j++) {
        const p1 = this.precincts[i];
        const p2 = this.precincts[j];
        
        const dx = p1.centroid.x - p2.centroid.x;
        const dy = p1.centroid.y - p2.centroid.y;
        const dist = Math.sqrt(dx * dx + dy * dy);
        
        // Consider adjacent if close enough or same county
        if (dist < threshold || p1.county === p2.county) {
          p1.neighbors.push(j);
          p2.neighbors.push(i);
        }
      }
    }
  }

  /**
   * Calculate total population
   */
  _getTotalPopulation() {
    return this.precincts.reduce((sum, p) => sum + p.population, 0);
  }

  /**
   * Calculate target population per district
   */
  _getTargetPopPerDistrict() {
    return this._getTotalPopulation() / this.numDistricts;
  }

  /**
   * Get district statistics
   */
  _getDistrictStats(districtId) {
    const precincts = this.precincts.filter(p => p.district === districtId);
    
    const stats = {
      population: 0,
      dem: 0,
      rep: 0,
      demShare: 0,
      precinctCount: precincts.length,
      counties: new Set()
    };
    
    precincts.forEach(p => {
      stats.population += p.population;
      stats.dem += p.dem;
      stats.rep += p.rep;
      stats.counties.add(p.county);
    });
    
    const totalVotes = stats.dem + stats.rep;
    stats.demShare = totalVotes > 0 ? stats.dem / totalVotes : 0.5;
    stats.countyCount = stats.counties.size;
    
    return stats;
  }

  /**
   * Generate districts using a greedy algorithm that:
   * 1. Respects county borders when possible
   * 2. Balances population
   * 3. Targets the specified partisan lean
   */
  generate() {
    const targetPop = this._getTargetPopPerDistrict();
    const maxDeviation = 0.10; // Allow 10% population deviation
    
    // Reset assignments
    this.precincts.forEach(p => p.district = null);
    
    // Sort counties by size (larger first for more options)
    const countiesBySize = Array.from(this.counties.entries())
      .sort((a, b) => b[1].length - a[1].length);
    
    // Strategy: Assign whole counties first, then split as needed
    let currentDistrict = 1;
    let districtPop = 0;
    let districtDem = 0;
    let districtRep = 0;
    
    // First pass: Try to assign whole counties
    const assignedCounties = new Set();
    const unassignedPrecincts = [];
    
    for (const [county, precinctIndices] of countiesBySize) {
      const countyPop = precinctIndices.reduce((sum, i) => sum + this.precincts[i].population, 0);
      const countyDem = precinctIndices.reduce((sum, i) => sum + this.precincts[i].dem, 0);
      const countyRep = precinctIndices.reduce((sum, i) => sum + this.precincts[i].rep, 0);
      
      // Check if adding this county would exceed population limit
      if (districtPop + countyPop <= targetPop * (1 + maxDeviation)) {
        // Check if this county helps achieve partisan target
        const newTotalVotes = districtDem + countyDem + districtRep + countyRep;
        const newDemShare = newTotalVotes > 0 ? (districtDem + countyDem) / newTotalVotes : 0.5;
        
        // Assign county to current district
        precinctIndices.forEach(i => {
          this.precincts[i].district = currentDistrict;
        });
        
        districtPop += countyPop;
        districtDem += countyDem;
        districtRep += countyRep;
        assignedCounties.add(county);
        
        // Check if district is full enough
        if (districtPop >= targetPop * (1 - maxDeviation)) {
          currentDistrict++;
          if (currentDistrict > this.numDistricts) break;
          districtPop = 0;
          districtDem = 0;
          districtRep = 0;
        }
      } else {
        // County is too large, need to split it later
        precinctIndices.forEach(i => unassignedPrecincts.push(i));
      }
    }
    
    // Second pass: Assign remaining precincts strategically
    // Sort by dem share to help achieve partisan target
    unassignedPrecincts.sort((a, b) => {
      const shareA = this.precincts[a].demShare;
      const shareB = this.precincts[b].demShare;
      
      // If targeting Dem, prioritize Dem precincts for incomplete districts
      // If targeting Rep, prioritize Rep precincts
      if (this.targetDemShare > 0.5) {
        return shareB - shareA; // Higher dem share first
      } else if (this.targetDemShare < 0.5) {
        return shareA - shareB; // Lower dem share first (higher rep)
      }
      return 0; // Fair - no preference
    });
    
    // Assign remaining precincts
    for (const precinctIndex of unassignedPrecincts) {
      if (this.precincts[precinctIndex].district !== null) continue;
      
      // Find the best district to add this precinct to
      let bestDistrict = null;
      let bestScore = -Infinity;
      
      for (let d = 1; d <= this.numDistricts; d++) {
        const stats = this._getDistrictStats(d);
        const precinct = this.precincts[precinctIndex];
        
        // Skip if district is already too full
        if (stats.population >= targetPop * (1 + maxDeviation)) continue;
        
        // Calculate score based on:
        // 1. Population balance
        // 2. Partisan target achievement
        // 3. County cohesion
        
        const newPop = stats.population + precinct.population;
        const popScore = 1 - Math.abs(newPop - targetPop) / targetPop;
        
        const newDem = stats.dem + precinct.dem;
        const newRep = stats.rep + precinct.rep;
        const newDemShare = (newDem + newRep) > 0 ? newDem / (newDem + newRep) : 0.5;
        const partisanScore = 1 - Math.abs(newDemShare - this.targetDemShare);
        
        // Bonus for same county
        const countyBonus = stats.counties.has(precinct.county) ? 0.2 : 0;
        
        // Bonus for adjacency (check if any precinct in district is a neighbor)
        let adjacencyBonus = 0;
        for (const neighborIdx of precinct.neighbors) {
          if (this.precincts[neighborIdx].district === d) {
            adjacencyBonus = 0.1;
            break;
          }
        }
        
        const score = popScore * 0.4 + partisanScore * 0.3 + countyBonus + adjacencyBonus;
        
        if (score > bestScore) {
          bestScore = score;
          bestDistrict = d;
        }
      }
      
      if (bestDistrict !== null) {
        this.precincts[precinctIndex].district = bestDistrict;
      } else {
        // Fallback: assign to least populated district
        let minPop = Infinity;
        let minDistrict = 1;
        for (let d = 1; d <= this.numDistricts; d++) {
          const stats = this._getDistrictStats(d);
          if (stats.population < minPop) {
            minPop = stats.population;
            minDistrict = d;
          }
        }
        this.precincts[precinctIndex].district = minDistrict;
      }
    }
    
    // Third pass: Optimization - swap precincts to improve fairness
    this._optimizeForFairness();
    
    // Build assignments map
    this.precincts.forEach(p => {
      if (p.district !== null) {
        this.assignments[p.id] = p.district;
      }
    });
    
    return this.assignments;
  }

  /**
   * Optimization pass: Try swapping border precincts to improve partisan balance
   */
  _optimizeForFairness() {
    const maxIterations = 100;
    let improved = true;
    let iteration = 0;
    
    while (improved && iteration < maxIterations) {
      improved = false;
      iteration++;
      
      // Find border precincts (precincts with neighbors in different districts)
      const borderPrecincts = this.precincts.filter(p => {
        if (p.district === null) return false;
        return p.neighbors.some(ni => {
          const neighbor = this.precincts[ni];
          return neighbor.district !== null && neighbor.district !== p.district;
        });
      });
      
      for (const precinct of borderPrecincts) {
        const currentDistrict = precinct.district;
        const currentStats = this._getDistrictStats(currentDistrict);
        
        // Try swapping to each neighboring district
        for (const neighborIdx of precinct.neighbors) {
          const neighbor = this.precincts[neighborIdx];
          if (neighbor.district === null || neighbor.district === currentDistrict) continue;
          
          const targetDistrict = neighbor.district;
          const targetStats = this._getDistrictStats(targetDistrict);
          
          // Calculate current fairness score
          const currentScore = this._calculateFairnessScore([currentStats, targetStats]);
          
          // Simulate swap
          precinct.district = targetDistrict;
          const newCurrentStats = this._getDistrictStats(currentDistrict);
          const newTargetStats = this._getDistrictStats(targetDistrict);
          const newScore = this._calculateFairnessScore([newCurrentStats, newTargetStats]);
          
          // Keep swap if it improves fairness
          if (newScore > currentScore) {
            improved = true;
            break; // Move to next precinct
          } else {
            // Revert swap
            precinct.district = currentDistrict;
          }
        }
      }
    }
  }

  /**
   * Calculate overall fairness score for given district stats
   */
  _calculateFairnessScore(districtStatsList) {
    let score = 0;
    const targetPop = this._getTargetPopPerDistrict();
    
    for (const stats of districtStatsList) {
      // Population balance component
      const popDeviation = Math.abs(stats.population - targetPop) / targetPop;
      const popScore = Math.max(0, 1 - popDeviation);
      
      // Partisan target component
      const partisanDeviation = Math.abs(stats.demShare - this.targetDemShare);
      const partisanScore = Math.max(0, 1 - partisanDeviation * 2);
      
      // County cohesion component (fewer counties is better)
      const countyScore = 1 / (stats.countyCount || 1);
      
      score += popScore * 0.4 + partisanScore * 0.4 + countyScore * 0.2;
    }
    
    return score / districtStatsList.length;
  }

  /**
   * Get summary statistics for the generated map
   */
  getSummary() {
    const districts = [];
    
    for (let d = 1; d <= this.numDistricts; d++) {
      const stats = this._getDistrictStats(d);
      districts.push({
        district: d,
        population: stats.population,
        demShare: stats.demShare,
        repShare: 1 - stats.demShare,
        counties: stats.countyCount,
        precincts: stats.precinctCount
      });
    }
    
    // Calculate overall metrics
    const avgDemShare = districts.reduce((sum, d) => sum + d.demShare, 0) / districts.length;
    const demSeats = districts.filter(d => d.demShare > 0.5).length;
    const repSeats = districts.filter(d => d.demShare < 0.5).length;
    const tossupSeats = districts.filter(d => Math.abs(d.demShare - 0.5) < 0.02).length;
    
    return {
      targetDemShare: this.targetDemShare,
      districts: districts,
      summary: {
        averageDemShare: avgDemShare,
        demSeats: demSeats,
        repSeats: repSeats,
        tossupSeats: tossupSeats,
        totalDistricts: this.numDistricts
      }
    };
  }
}

// Export for use in app.js
window.Automap = Automap;
window.FAIRNESS_PRESETS = FAIRNESS_PRESETS;
