/*
 * US Redistricting Tool - Automap Algorithm
 * 
 * Generates district maps based on partisan fairness goals while respecting
 * county borders as much as possible.
 * 
 * Algorithm:
 * 1. Group precincts by county
 * 2. Assign whole counties first when possible
 * 3. Split large counties as needed
 * 4. Optimize swaps to improve fairness metrics
 * 
 * Fairness levels:
 * - Very R: Target 60%+ Republican lean (40% Dem)
 * - Lean R: Target 54% Republican lean (46% Dem)
 * - Fair: Target 50-50 balanced
 * - Lean D: Target 54% Democratic lean
 * - Very D: Target 60%+ Democratic lean
 */

#include "../include/maps.h"

/* Fairness preset configurations */
FairnessConfig FAIRNESS_PRESETS[5] = {
    { "Very R",  0.40, 0.05, "Strongly Republican-favoring map" },
    { "Lean R",  0.46, 0.03, "Slightly Republican-favoring map" },
    { "Fair",    0.50, 0.02, "Balanced, competitive districts" },
    { "Lean D",  0.54, 0.03, "Slightly Democratic-favoring map" },
    { "Very D",  0.60, 0.05, "Strongly Democratic-favoring map" }
};

/* County group structure */
typedef struct {
    char name[MAX_NAME_LEN];
    int precinctIndices[MAX_PRECINCTS];
    int count;
    int totalPop;
    int totalDem;
    int totalRep;
    double demShare;
} CountyGroup;

/* Get total population */
static int get_total_population(AppState* app) {
    int total = 0;
    for (int i = 0; i < app->precinctCount; i++) {
        total += app->precincts[i].population;
    }
    return total;
}

/* Get district statistics */
static void get_district_stats_quick(AppState* app, int districtId, 
                                      int* pop, int* dem, int* rep, double* demShare) {
    *pop = 0;
    *dem = 0;
    *rep = 0;
    
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district == districtId) {
            *pop += app->precincts[i].population;
            *dem += app->precincts[i].dem;
            *rep += app->precincts[i].rep;
        }
    }
    
    int total = *dem + *rep;
    *demShare = total > 0 ? (double)*dem / total : 0.5;
}

/* Calculate fairness score for a set of district stats */
static double calculate_fairness_score(AppState* app, int numDistricts, 
                                        int targetPop, double targetDemShare) {
    double score = 0;
    
    for (int d = 1; d <= numDistricts; d++) {
        int pop, dem, rep;
        double demShare;
        get_district_stats_quick(app, d, &pop, &dem, &rep, &demShare);
        
        if (pop == 0) continue;
        
        /* Population balance component */
        double popDeviation = fabs((double)(pop - targetPop) / targetPop);
        double popScore = 1.0 - popDeviation;
        if (popScore < 0) popScore = 0;
        
        /* Partisan target component */
        double partisanDeviation = fabs(demShare - targetDemShare);
        double partisanScore = 1.0 - partisanDeviation * 2;
        if (partisanScore < 0) partisanScore = 0;
        
        score += popScore * 0.5 + partisanScore * 0.5;
    }
    
    return score / numDistricts;
}

/* Build county groups */
static int build_county_groups(AppState* app, CountyGroup* groups, int maxGroups) {
    int groupCount = 0;
    
    for (int i = 0; i < app->precinctCount; i++) {
        Precinct* p = &app->precincts[i];
        
        /* Find existing county group */
        int found = -1;
        for (int g = 0; g < groupCount; g++) {
            if (strcmp(groups[g].name, p->county) == 0) {
                found = g;
                break;
            }
        }
        
        if (found < 0) {
            if (groupCount >= maxGroups) continue;
            found = groupCount++;
            memset(&groups[found], 0, sizeof(CountyGroup));
            strncpy(groups[found].name, p->county, MAX_NAME_LEN - 1);
        }
        
        groups[found].precinctIndices[groups[found].count++] = i;
        groups[found].totalPop += p->population;
        groups[found].totalDem += p->dem;
        groups[found].totalRep += p->rep;
    }
    
    /* Calculate dem share for each county */
    for (int g = 0; g < groupCount; g++) {
        int total = groups[g].totalDem + groups[g].totalRep;
        groups[g].demShare = total > 0 ? (double)groups[g].totalDem / total : 0.5;
    }
    
    return groupCount;
}

/* Compare counties by size for sorting */
static int compare_counties_by_size(const void* a, const void* b) {
    const CountyGroup* ca = (const CountyGroup*)a;
    const CountyGroup* cb = (const CountyGroup*)b;
    return cb->totalPop - ca->totalPop; /* Descending order */
}

/* Generate districts using automap algorithm */
int generate_automap(AppState* app, int numDistricts, FairnessPreset preset, double customTarget) {
    if (!app->currentState || app->precinctCount == 0) {
        fprintf(stderr, "No state or precinct data loaded.\n");
        return 0;
    }
    
    printf("\n=== Automap District Generation ===\n");
    
    /* Get target parameters */
    double targetDemShare = customTarget > 0 ? customTarget : FAIRNESS_PRESETS[preset].targetDemShare;
    /* tolerance could be used for future refinements */
    (void)FAIRNESS_PRESETS[preset].tolerance;
    
    printf("Fairness preset: %s\n", FAIRNESS_PRESETS[preset].label);
    printf("Target Dem share: %.1f%%\n", targetDemShare * 100);
    printf("Number of districts: %d\n", numDistricts);
    
    int totalPop = get_total_population(app);
    int targetPop = totalPop / numDistricts;
    double maxDeviation = 0.10; /* Allow 10% population deviation */
    
    printf("Total population: %d\n", totalPop);
    printf("Target population per district: %d (±%.0f%%)\n", targetPop, maxDeviation * 100);
    
    /* Reset all assignments */
    for (int i = 0; i < app->precinctCount; i++) {
        app->precincts[i].district = 0;
    }
    
    /* Build county groups */
    CountyGroup* counties = (CountyGroup*)malloc(sizeof(CountyGroup) * 500);
    if (!counties) {
        fprintf(stderr, "Memory allocation failed.\n");
        return 0;
    }
    
    int countyCount = build_county_groups(app, counties, 500);
    printf("Counties found: %d\n", countyCount);
    
    /* Sort counties by size (largest first) */
    qsort(counties, countyCount, sizeof(CountyGroup), compare_counties_by_size);
    
    /* First pass: Assign whole counties */
    printf("\nPhase 1: Assigning whole counties...\n");
    
    int currentDistrict = 1;
    int districtPop[MAX_DISTRICTS] = {0};
    int districtDem[MAX_DISTRICTS] = {0};
    int districtRep[MAX_DISTRICTS] = {0};
    int assignedCounties[500] = {0};
    
    for (int g = 0; g < countyCount && currentDistrict <= numDistricts; g++) {
        CountyGroup* county = &counties[g];
        
        /* Check if adding this county would exceed population limit */
        if (districtPop[currentDistrict - 1] + county->totalPop <= targetPop * (1 + maxDeviation)) {
            /* Assign all precincts in county to current district */
            for (int p = 0; p < county->count; p++) {
                int precinctIdx = county->precinctIndices[p];
                app->precincts[precinctIdx].district = currentDistrict;
            }
            
            districtPop[currentDistrict - 1] += county->totalPop;
            districtDem[currentDistrict - 1] += county->totalDem;
            districtRep[currentDistrict - 1] += county->totalRep;
            assignedCounties[g] = 1;
            
            /* Check if district is full enough */
            if (districtPop[currentDistrict - 1] >= targetPop * (1 - maxDeviation)) {
                currentDistrict++;
            }
        }
    }
    
    int phase1Assigned = 0;
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district > 0) phase1Assigned++;
    }
    printf("Phase 1 complete: %d/%d precincts assigned\n", phase1Assigned, app->precinctCount);
    
    /* Second pass: Assign remaining precincts strategically */
    printf("\nPhase 2: Assigning remaining precincts...\n");
    
    /* Sort unassigned precincts by dem share based on target */
    int* unassigned = (int*)malloc(sizeof(int) * app->precinctCount);
    int unassignedCount = 0;
    
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district == 0) {
            unassigned[unassignedCount++] = i;
        }
    }
    
    /* Simple bubble sort by dem share */
    for (int i = 0; i < unassignedCount - 1; i++) {
        for (int j = 0; j < unassignedCount - i - 1; j++) {
            int swap = 0;
            if (targetDemShare > 0.5) {
                /* Sort descending for Dem-favoring */
                swap = app->precincts[unassigned[j]].demShare < 
                       app->precincts[unassigned[j + 1]].demShare;
            } else if (targetDemShare < 0.5) {
                /* Sort ascending for Rep-favoring */
                swap = app->precincts[unassigned[j]].demShare > 
                       app->precincts[unassigned[j + 1]].demShare;
            }
            if (swap) {
                int temp = unassigned[j];
                unassigned[j] = unassigned[j + 1];
                unassigned[j + 1] = temp;
            }
        }
    }
    
    /* Assign each unassigned precinct to the best district */
    for (int u = 0; u < unassignedCount; u++) {
        int precinctIdx = unassigned[u];
        Precinct* p = &app->precincts[precinctIdx];
        
        int bestDistrict = -1;
        double bestScore = -1e9;
        
        for (int d = 1; d <= numDistricts; d++) {
            /* Recalculate current district stats */
            int dPop = 0, dDem = 0, dRep = 0;
            for (int i = 0; i < app->precinctCount; i++) {
                if (app->precincts[i].district == d) {
                    dPop += app->precincts[i].population;
                    dDem += app->precincts[i].dem;
                    dRep += app->precincts[i].rep;
                }
            }
            
            /* Skip if district is already too full */
            if (dPop >= targetPop * (1 + maxDeviation)) continue;
            
            /* Calculate score for adding this precinct */
            int newPop = dPop + p->population;
            double popScore = 1.0 - fabs((double)(newPop - targetPop) / targetPop);
            
            int newDem = dDem + p->dem;
            int newRep = dRep + p->rep;
            double newDemShare = (newDem + newRep) > 0 ? (double)newDem / (newDem + newRep) : 0.5;
            double partisanScore = 1.0 - fabs(newDemShare - targetDemShare);
            
            /* Bonus for same county */
            double countyBonus = 0;
            for (int i = 0; i < app->precinctCount; i++) {
                if (app->precincts[i].district == d && 
                    strcmp(app->precincts[i].county, p->county) == 0) {
                    countyBonus = 0.2;
                    break;
                }
            }
            
            /* Bonus for adjacency */
            double adjacencyBonus = 0;
            for (int n = 0; n < p->neighborCount; n++) {
                if (app->precincts[p->neighbors[n]].district == d) {
                    adjacencyBonus = 0.1;
                    break;
                }
            }
            
            double score = popScore * 0.4 + partisanScore * 0.3 + countyBonus + adjacencyBonus;
            
            if (score > bestScore) {
                bestScore = score;
                bestDistrict = d;
            }
        }
        
        if (bestDistrict > 0) {
            p->district = bestDistrict;
        } else {
            /* Fallback: assign to least populated district */
            int minPop = 999999999;
            int minDistrict = 1;
            for (int d = 1; d <= numDistricts; d++) {
                int dPop = 0;
                for (int i = 0; i < app->precinctCount; i++) {
                    if (app->precincts[i].district == d) {
                        dPop += app->precincts[i].population;
                    }
                }
                if (dPop < minPop) {
                    minPop = dPop;
                    minDistrict = d;
                }
            }
            p->district = minDistrict;
        }
    }
    
    free(unassigned);
    
    int phase2Assigned = 0;
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district > 0) phase2Assigned++;
    }
    printf("Phase 2 complete: %d/%d precincts assigned\n", phase2Assigned, app->precinctCount);
    
    /* Third pass: Optimization - swap border precincts to improve fairness */
    printf("\nPhase 3: Optimizing district assignments...\n");
    
    int maxIterations = 50;
    int iteration = 0;
    int improved = 1;
    
    while (improved && iteration < maxIterations) {
        improved = 0;
        iteration++;
        
        /* Find border precincts */
        for (int i = 0; i < app->precinctCount; i++) {
            Precinct* p = &app->precincts[i];
            if (p->district == 0) continue;
            
            /* Check if this is a border precinct */
            int isBorder = 0;
            int neighborDistrict = 0;
            
            for (int n = 0; n < p->neighborCount; n++) {
                int ni = p->neighbors[n];
                if (app->precincts[ni].district != 0 && 
                    app->precincts[ni].district != p->district) {
                    isBorder = 1;
                    neighborDistrict = app->precincts[ni].district;
                    break;
                }
            }
            
            if (!isBorder || neighborDistrict == 0) continue;
            
            /* Calculate current fairness score */
            double currentScore = calculate_fairness_score(app, numDistricts, targetPop, targetDemShare);
            
            /* Try swapping to neighbor district */
            int oldDistrict = p->district;
            p->district = neighborDistrict;
            
            double newScore = calculate_fairness_score(app, numDistricts, targetPop, targetDemShare);
            
            if (newScore > currentScore + 0.001) {
                improved = 1;
                /* Keep the swap */
            } else {
                /* Revert */
                p->district = oldDistrict;
            }
        }
    }
    
    printf("Phase 3 complete: %d optimization iterations\n", iteration);
    
    /* Update plan */
    app->currentPlan.numDistricts = numDistricts;
    app->hasPlan = 1;
    
    /* Generate summary */
    print_automap_summary(app);
    
    free(counties);
    return 1;
}

/* Print automap generation summary */
void print_automap_summary(AppState* app) {
    int numDistricts = app->currentPlan.numDistricts;
    
    printf("\n=== Automap Summary ===\n");
    
    int demSeats = 0, repSeats = 0, tossupSeats = 0;
    double avgDemShare = 0;
    int districtsWithData = 0;
    
    printf("\nDistrict Results:\n");
    printf("%-8s %-12s %-10s %-10s %-8s %s\n", 
           "District", "Population", "Dem Votes", "Rep Votes", "Dem%", "Result");
    printf("%-8s %-12s %-10s %-10s %-8s %s\n", 
           "--------", "------------", "----------", "----------", "--------", "------");
    
    for (int d = 1; d <= numDistricts; d++) {
        int pop = 0, dem = 0, rep = 0;
        double demShare;
        get_district_stats_quick(app, d, &pop, &dem, &rep, &demShare);
        
        if (pop == 0) {
            printf("%-8d %-12s %-10s %-10s %-8s %s\n", d, "---", "---", "---", "---", "---");
            continue;
        }
        
        const char* result;
        if (demShare > 0.52) { result = "DEM"; demSeats++; }
        else if (demShare < 0.48) { result = "REP"; repSeats++; }
        else { result = "TOSSUP"; tossupSeats++; }
        
        avgDemShare += demShare;
        districtsWithData++;
        
        printf("%-8d %-12d %-10d %-10d %-7.1f%% %s\n",
               d, pop, dem, rep, demShare * 100, result);
    }
    
    if (districtsWithData > 0) {
        avgDemShare /= districtsWithData;
    }
    
    printf("\n");
    printf("═══════════════════════════════════════\n");
    printf("  Democratic seats: %d\n", demSeats);
    printf("  Republican seats: %d\n", repSeats);
    printf("  Tossup seats:     %d\n", tossupSeats);
    printf("  Average Dem%%:     %.1f%%\n", avgDemShare * 100);
    printf("═══════════════════════════════════════\n");
}
