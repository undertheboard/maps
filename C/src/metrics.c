/*
 * US Redistricting Tool - Metrics Calculation
 * 
 * Computes district-level metrics including:
 * - Population per district
 * - Democratic/Republican vote totals
 * - Partisan lean (Democratic vote share)
 * - Compactness (Polsby-Popper score)
 */

#include "../include/maps.h"

/* Calculate compactness using Polsby-Popper formula */
double calculate_compactness(double area, double perimeter) {
    if (perimeter <= 0) return 0;
    return (4.0 * 3.14159265358979 * area) / (perimeter * perimeter);
}

/* Approximate area and perimeter from precinct centroids */
static void approximate_geometry(AppState* app, int districtId, double* area, double* perimeter) {
    *area = 0;
    *perimeter = 0;
    
    /* Collect all precincts in this district */
    int count = 0;
    double minX = 1e9, maxX = -1e9, minY = 1e9, maxY = -1e9;
    
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district == districtId) {
            count++;
            double x = app->precincts[i].centroid.x;
            double y = app->precincts[i].centroid.y;
            
            if (x < minX) minX = x;
            if (x > maxX) maxX = x;
            if (y < minY) minY = y;
            if (y > maxY) maxY = y;
        }
    }
    
    if (count == 0) return;
    
    /* Approximate area as bounding box */
    double width = maxX - minX;
    double height = maxY - minY;
    *area = width * height;
    *perimeter = 2 * (width + height);
}

/* Compute statistics for all districts */
void compute_district_stats(AppState* app, DistrictStats* stats, int numDistricts) {
    /* Initialize stats */
    for (int d = 1; d <= numDistricts; d++) {
        stats[d - 1].districtId = d;
        stats[d - 1].population = 0;
        stats[d - 1].demVotes = 0;
        stats[d - 1].repVotes = 0;
        stats[d - 1].demShare = 0.5;
        stats[d - 1].compactness = 0;
        stats[d - 1].area = 0;
        stats[d - 1].perimeter = 0;
        stats[d - 1].precinctCount = 0;
        stats[d - 1].countyCount = 0;
    }
    
    /* Count unique counties per district */
    char counties[MAX_DISTRICTS][100][MAX_NAME_LEN]; /* district -> list of counties */
    int countyCounts[MAX_DISTRICTS] = {0};
    
    /* Aggregate precinct data */
    for (int i = 0; i < app->precinctCount; i++) {
        Precinct* p = &app->precincts[i];
        int d = p->district;
        
        if (d < 1 || d > numDistricts) continue;
        
        stats[d - 1].population += p->population;
        stats[d - 1].demVotes += p->dem;
        stats[d - 1].repVotes += p->rep;
        stats[d - 1].precinctCount++;
        
        /* Track unique counties */
        int found = 0;
        for (int c = 0; c < countyCounts[d - 1]; c++) {
            if (strcmp(counties[d - 1][c], p->county) == 0) {
                found = 1;
                break;
            }
        }
        if (!found && countyCounts[d - 1] < 100) {
            strncpy(counties[d - 1][countyCounts[d - 1]], p->county, MAX_NAME_LEN - 1);
            countyCounts[d - 1]++;
        }
    }
    
    /* Calculate derived metrics */
    for (int d = 1; d <= numDistricts; d++) {
        int total = stats[d - 1].demVotes + stats[d - 1].repVotes;
        if (total > 0) {
            stats[d - 1].demShare = (double)stats[d - 1].demVotes / total;
        }
        
        stats[d - 1].countyCount = countyCounts[d - 1];
        
        /* Calculate geometry approximation */
        double area, perimeter;
        approximate_geometry(app, d, &area, &perimeter);
        stats[d - 1].area = area;
        stats[d - 1].perimeter = perimeter;
        stats[d - 1].compactness = calculate_compactness(area, perimeter);
    }
}

/* Print detailed metrics for current plan */
void print_metrics(AppState* app) {
    if (!app->hasPlan || !app->currentState) {
        printf("No plan loaded. Load a state and create/load a plan first.\n");
        return;
    }
    
    int numDistricts = app->currentPlan.numDistricts;
    if (numDistricts < 1 || numDistricts > MAX_DISTRICTS) {
        numDistricts = 10;
    }
    
    DistrictStats stats[MAX_DISTRICTS];
    compute_district_stats(app, stats, numDistricts);
    
    /* Calculate totals and targets */
    int totalPop = 0, totalDem = 0, totalRep = 0;
    int assignedPrecincts = 0;
    
    for (int i = 0; i < app->precinctCount; i++) {
        totalPop += app->precincts[i].population;
        totalDem += app->precincts[i].dem;
        totalRep += app->precincts[i].rep;
        if (app->precincts[i].district > 0) {
            assignedPrecincts++;
        }
    }
    
    int targetPop = numDistricts > 0 ? totalPop / numDistricts : 0;
    
    printf("\n");
    printf("╔══════════════════════════════════════════════════════════════════════════════╗\n");
    printf("║                         REDISTRICTING PLAN METRICS                           ║\n");
    printf("╠══════════════════════════════════════════════════════════════════════════════╣\n");
    printf("║ Plan: %-30s  State: %-8s                    ║\n", 
           app->currentPlan.name, app->currentState->abbr);
    printf("║ Districts: %-3d    Target Pop/District: %-10d                           ║\n",
           numDistricts, targetPop);
    printf("║ Precincts: %d/%d assigned                                                   ║\n",
           assignedPrecincts, app->precinctCount);
    printf("╠══════════════════════════════════════════════════════════════════════════════╣\n");
    printf("║  Dist │ Population │    Dev   │    Dem    │    Rep    │ Dem%% │ Compact │ Cnty ║\n");
    printf("╠═══════╪════════════╪══════════╪═══════════╪═══════════╪══════╪═════════╪══════╣\n");
    
    int demSeats = 0, repSeats = 0, tossupSeats = 0;
    double avgDemShare = 0;
    int districtsWithData = 0;
    
    for (int d = 1; d <= numDistricts; d++) {
        DistrictStats* s = &stats[d - 1];
        
        if (s->precinctCount == 0) {
            printf("║  %3d  │     ---    │    ---   │    ---    │    ---    │  --- │   ---   │  --- ║\n", d);
            continue;
        }
        
        double deviation = targetPop > 0 ? 100.0 * (s->population - targetPop) / targetPop : 0;
        char devSign = deviation >= 0 ? '+' : '-';
        deviation = fabs(deviation);
        
        /* Determine seat lean */
        char leanChar = ' ';
        if (s->demShare > 0.52) { leanChar = 'D'; demSeats++; }
        else if (s->demShare < 0.48) { leanChar = 'R'; repSeats++; }
        else { leanChar = 'T'; tossupSeats++; }
        
        avgDemShare += s->demShare;
        districtsWithData++;
        
        printf("║  %3d  │ %10d │ %c%6.2f%% │ %9d │ %9d │%5.1f%c │ %7.3f │  %3d ║\n",
               d,
               s->population,
               devSign, deviation,
               s->demVotes,
               s->repVotes,
               s->demShare * 100, leanChar,
               s->compactness,
               s->countyCount);
    }
    
    printf("╠══════════════════════════════════════════════════════════════════════════════╣\n");
    
    /* Summary statistics */
    if (districtsWithData > 0) {
        avgDemShare /= districtsWithData;
    }
    
    printf("║ SUMMARY:                                                                     ║\n");
    printf("║   Democratic seats: %-3d    Republican seats: %-3d    Tossup: %-3d              ║\n",
           demSeats, repSeats, tossupSeats);
    printf("║   Average Dem share: %5.1f%%                                                  ║\n", 
           avgDemShare * 100);
    printf("║   Statewide Dem share: %5.1f%%                                                ║\n", 
           (totalDem + totalRep) > 0 ? 100.0 * totalDem / (totalDem + totalRep) : 50.0);
    
    /* Efficiency gap calculation */
    int wastedDem = 0, wastedRep = 0;
    for (int d = 1; d <= numDistricts; d++) {
        DistrictStats* s = &stats[d - 1];
        if (s->precinctCount == 0) continue;
        
        int totalVotes = s->demVotes + s->repVotes;
        int threshold = totalVotes / 2 + 1;
        
        if (s->demVotes > s->repVotes) {
            /* Dem won - wasted Dem votes above threshold, all Rep votes */
            wastedDem += s->demVotes - threshold;
            wastedRep += s->repVotes;
        } else {
            /* Rep won */
            wastedRep += s->repVotes - threshold;
            wastedDem += s->demVotes;
        }
    }
    
    int totalVotes = totalDem + totalRep;
    double efficiencyGap = totalVotes > 0 ? 100.0 * (wastedDem - wastedRep) / totalVotes : 0;
    
    printf("║   Efficiency Gap: %+6.2f%% (positive favors R, negative favors D)            ║\n", efficiencyGap);
    printf("╚══════════════════════════════════════════════════════════════════════════════╝\n");
    printf("\n");
    printf("Legend: D=Democratic seat, R=Republican seat, T=Tossup (<4%% margin)\n");
    printf("        Compact=Polsby-Popper score (1.0 is perfect circle)\n");
    printf("        Cnty=Number of counties split in district\n");
    printf("\n");
}
