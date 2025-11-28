/*
 * US Redistricting Tool - Console User Interface
 */

#include "../include/maps.h"

/* Clear screen (cross-platform) */
void clear_screen(void) {
#ifdef _WIN32
    system("cls");
#else
    system("clear");
#endif
}

/* Get user choice within range */
int get_user_choice(int min, int max) {
    char input[32];
    int choice;
    
    while (1) {
        printf("Enter choice (%d-%d): ", min, max);
        fflush(stdout);
        
        if (fgets(input, sizeof(input), stdin) == NULL) {
            return -1;
        }
        
        choice = atoi(input);
        if (choice >= min && choice <= max) {
            return choice;
        }
        
        printf("Invalid choice. Please try again.\n");
    }
}

/* Get string input from user */
void get_user_string(const char* prompt, char* buffer, int size) {
    printf("%s", prompt);
    fflush(stdout);
    
    if (fgets(buffer, size, stdin) != NULL) {
        /* Remove trailing newline */
        size_t len = strlen(buffer);
        if (len > 0 && buffer[len - 1] == '\n') {
            buffer[len - 1] = '\0';
        }
    }
}

/* Print application header */
static void print_header(void) {
    printf("\n");
    printf("╔══════════════════════════════════════════════════════════════════════════════╗\n");
    printf("║                                                                              ║\n");
    printf("║                    🗺️  US REDISTRICTING TOOL  🗺️                              ║\n");
    printf("║                        Windows Console Edition                               ║\n");
    printf("║                                                                              ║\n");
    printf("╚══════════════════════════════════════════════════════════════════════════════╝\n");
    printf("\n");
}

/* Show main menu */
void show_main_menu(void) {
    print_header();
    printf("MAIN MENU\n");
    printf("═════════════════════════════════════════\n");
    printf("  1. List Available States\n");
    printf("  2. Load State Data\n");
    printf("  3. State Management Menu\n");
    printf("  4. Plan Management Menu\n");
    printf("  5. District Settings\n");
    printf("  6. Auto-Generate Districts\n");
    printf("  7. View Metrics\n");
    printf("  8. Manual Precinct Assignment\n");
    printf("  9. Help / About\n");
    printf("  0. Exit\n");
    printf("═════════════════════════════════════════\n");
}

/* Show state management menu */
void show_state_menu(AppState* app) {
    printf("\n");
    printf("STATE MANAGEMENT\n");
    printf("═════════════════════════════════════════\n");
    
    if (app->currentState) {
        printf("Current State: %s (%s)\n", app->currentState->name, app->currentState->abbr);
        printf("Precincts: %d\n", app->precinctCount);
        
        int totalPop = 0, totalDem = 0, totalRep = 0;
        for (int i = 0; i < app->precinctCount; i++) {
            totalPop += app->precincts[i].population;
            totalDem += app->precincts[i].dem;
            totalRep += app->precincts[i].rep;
        }
        printf("Total Population: %d\n", totalPop);
        printf("Total Dem Votes: %d\n", totalDem);
        printf("Total Rep Votes: %d\n", totalRep);
        if (totalDem + totalRep > 0) {
            printf("Statewide Dem%%: %.1f%%\n", 100.0 * totalDem / (totalDem + totalRep));
        }
    } else {
        printf("No state currently loaded.\n");
    }
    
    printf("\nOptions:\n");
    printf("  1. List all states\n");
    printf("  2. Load different state\n");
    printf("  3. Show precinct summary\n");
    printf("  0. Back to main menu\n");
    printf("═════════════════════════════════════════\n");
}

/* Show plan management menu */
void show_plan_menu(AppState* app) {
    printf("\n");
    printf("PLAN MANAGEMENT\n");
    printf("═════════════════════════════════════════\n");
    
    if (app->hasPlan) {
        printf("Current Plan: %s\n", app->currentPlan.name);
        printf("Plan ID: %s\n", app->currentPlan.planId);
        printf("Districts: %d\n", app->currentPlan.numDistricts);
        
        int assigned = 0;
        for (int i = 0; i < app->precinctCount; i++) {
            if (app->precincts[i].district > 0) assigned++;
        }
        printf("Assigned Precincts: %d / %d\n", assigned, app->precinctCount);
    } else {
        printf("No plan currently loaded.\n");
    }
    
    printf("\nOptions:\n");
    printf("  1. Create new plan\n");
    printf("  2. Save current plan\n");
    printf("  3. List saved plans\n");
    printf("  4. Load existing plan\n");
    printf("  5. Rename current plan\n");
    printf("  0. Back to main menu\n");
    printf("═════════════════════════════════════════\n");
}

/* Show district settings menu */
void show_district_settings(AppState* app) {
    printf("\n");
    printf("DISTRICT SETTINGS\n");
    printf("═════════════════════════════════════════\n");
    
    if (app->hasPlan) {
        printf("Current number of districts: %d\n", app->currentPlan.numDistricts);
    } else {
        printf("Default districts: %d\n", 
               app->currentState ? app->currentState->defaultNumDistricts : 10);
    }
    
    printf("\nOptions:\n");
    printf("  1. Change number of districts\n");
    printf("  2. Clear all assignments\n");
    printf("  3. View district breakdown\n");
    printf("  0. Back to main menu\n");
    printf("═════════════════════════════════════════\n");
}

/* Show automap menu */
void show_automap_menu(AppState* app) {
    (void)app; /* Currently unused */
    printf("\n");
    printf("AUTO-GENERATE DISTRICTS\n");
    printf("═════════════════════════════════════════\n");
    printf("\nFairness Presets:\n");
    printf("  1. Very Republican (60%% R, 40%% D)\n");
    printf("  2. Lean Republican (54%% R, 46%% D)\n");
    printf("  3. Fair / Competitive (50-50)\n");
    printf("  4. Lean Democratic (54%% D, 46%% R)\n");
    printf("  5. Very Democratic (60%% D, 40%% R)\n");
    printf("  6. Custom target percentage\n");
    printf("  0. Back to main menu\n");
    printf("═════════════════════════════════════════\n");
}

/* Show help / about screen */
void show_help(void) {
    clear_screen();
    print_header();
    
    printf("ABOUT THIS SOFTWARE\n");
    printf("═══════════════════════════════════════════════════════════════════════════════\n");
    printf("\n");
    printf("The US Redistricting Tool is a software application for creating and analyzing\n");
    printf("congressional and legislative district maps. It supports:\n");
    printf("\n");
    printf("FEATURES:\n");
    printf("  • Load precinct-level geographic and demographic data\n");
    printf("  • Assign precincts to districts manually or automatically\n");
    printf("  • Auto-generate districts based on fairness goals\n");
    printf("  • Calculate population balance and partisan metrics\n");
    printf("  • Compute efficiency gap and compactness scores\n");
    printf("  • Save and load redistricting plans\n");
    printf("\n");
    printf("DATA FORMAT:\n");
    printf("  • Precinct data should be in GeoJSON format\n");
    printf("  • Place data in: data\\precincts\\<STATE>\\precincts.geojson\n");
    printf("  • Required properties: id, population, dem (or dem_votes), rep (or rep_votes)\n");
    printf("  • Optional properties: county, name\n");
    printf("\n");
    printf("FAIRNESS METRICS:\n");
    printf("  • Population Deviation: Difference from ideal district population\n");
    printf("  • Partisan Lean: Democratic vote share in each district\n");
    printf("  • Efficiency Gap: Measure of wasted votes favoring one party\n");
    printf("  • Compactness: Polsby-Popper score (1.0 = perfect circle)\n");
    printf("\n");
    printf("AUTOMAP ALGORITHM:\n");
    printf("  The automap feature uses a greedy algorithm that:\n");
    printf("  1. Groups precincts by county\n");
    printf("  2. Assigns whole counties to districts when possible\n");
    printf("  3. Splits large counties to balance population\n");
    printf("  4. Optimizes assignments to achieve target partisan balance\n");
    printf("\n");
    printf("═══════════════════════════════════════════════════════════════════════════════\n");
    printf("\nPress Enter to continue...");
    getchar();
}

/* Show precinct summary */
void show_precinct_summary(AppState* app) {
    if (app->precinctCount == 0) {
        printf("No precincts loaded.\n");
        return;
    }
    
    printf("\nPRECINCT SUMMARY\n");
    printf("═════════════════════════════════════════\n");
    printf("Total precincts: %d\n\n", app->precinctCount);
    
    /* Count by district */
    int unassigned = 0;
    int districtCounts[MAX_DISTRICTS + 1] = {0};
    
    for (int i = 0; i < app->precinctCount; i++) {
        int d = app->precincts[i].district;
        if (d <= 0) {
            unassigned++;
        } else if (d <= MAX_DISTRICTS) {
            districtCounts[d]++;
        }
    }
    
    printf("Unassigned: %d\n", unassigned);
    
    if (app->hasPlan) {
        printf("\nBy District:\n");
        for (int d = 1; d <= app->currentPlan.numDistricts; d++) {
            if (districtCounts[d] > 0) {
                printf("  District %2d: %d precincts\n", d, districtCounts[d]);
            }
        }
    }
    
    /* Count by county */
    printf("\nBy County:\n");
    
    typedef struct { char name[MAX_NAME_LEN]; int count; } CountyCount;
    CountyCount counties[200];
    int countyCount = 0;
    
    for (int i = 0; i < app->precinctCount; i++) {
        int found = 0;
        for (int c = 0; c < countyCount; c++) {
            if (strcmp(counties[c].name, app->precincts[i].county) == 0) {
                counties[c].count++;
                found = 1;
                break;
            }
        }
        if (!found && countyCount < 200) {
            strncpy(counties[countyCount].name, app->precincts[i].county, MAX_NAME_LEN - 1);
            counties[countyCount].count = 1;
            countyCount++;
        }
    }
    
    /* Show top 10 counties */
    for (int i = 0; i < countyCount - 1; i++) {
        for (int j = 0; j < countyCount - i - 1; j++) {
            if (counties[j].count < counties[j + 1].count) {
                CountyCount temp = counties[j];
                counties[j] = counties[j + 1];
                counties[j + 1] = temp;
            }
        }
    }
    
    int showCount = countyCount < 10 ? countyCount : 10;
    for (int i = 0; i < showCount; i++) {
        printf("  %-20s %d precincts\n", counties[i].name, counties[i].count);
    }
    if (countyCount > 10) {
        printf("  ... and %d more counties\n", countyCount - 10);
    }
}

/* Manual precinct assignment interface */
void show_manual_assignment(AppState* app) {
    if (!app->currentState || app->precinctCount == 0) {
        printf("No state loaded. Please load a state first.\n");
        return;
    }
    
    if (!app->hasPlan) {
        printf("No plan active. Creating new plan...\n");
        create_new_plan(app, "Manual Plan");
    }
    
    printf("\n");
    printf("MANUAL PRECINCT ASSIGNMENT\n");
    printf("═════════════════════════════════════════\n");
    printf("Enter precinct ID and district number to assign.\n");
    printf("Enter 'list' to show precincts, 'quit' to exit.\n");
    printf("\n");
    
    char input[128];
    while (1) {
        printf("Command (precinct_id district | list | search <term> | quit): ");
        fflush(stdout);
        
        if (fgets(input, sizeof(input), stdin) == NULL) break;
        
        /* Remove newline */
        size_t len = strlen(input);
        if (len > 0 && input[len - 1] == '\n') input[len - 1] = '\0';
        
        if (strcmp(input, "quit") == 0 || strcmp(input, "q") == 0) {
            break;
        }
        
        if (strcmp(input, "list") == 0) {
            printf("\nFirst 20 precincts:\n");
            printf("%-20s %-10s %-8s %-10s\n", "ID", "Pop", "Dem%", "District");
            int shown = 0;
            for (int i = 0; i < app->precinctCount && shown < 20; i++) {
                Precinct* p = &app->precincts[i];
                printf("%-20s %-10d %-7.1f%% %d\n", 
                       p->id, p->population, p->demShare * 100, p->district);
                shown++;
            }
            printf("\n");
            continue;
        }
        
        if (strncmp(input, "search ", 7) == 0) {
            char* term = input + 7;
            printf("\nSearch results for '%s':\n", term);
            printf("%-20s %-10s %-8s %-10s\n", "ID", "Pop", "Dem%", "District");
            int found = 0;
            for (int i = 0; i < app->precinctCount; i++) {
                Precinct* p = &app->precincts[i];
                if (strstr(p->id, term) != NULL || strstr(p->county, term) != NULL) {
                    printf("%-20s %-10d %-7.1f%% %d\n", 
                           p->id, p->population, p->demShare * 100, p->district);
                    found++;
                    if (found >= 20) {
                        printf("... (showing first 20 matches)\n");
                        break;
                    }
                }
            }
            if (found == 0) {
                printf("No precincts found matching '%s'\n", term);
            }
            printf("\n");
            continue;
        }
        
        /* Parse precinct_id and district */
        char precinctId[MAX_ID_LEN] = {0};
        int district = 0;
        
        if (sscanf(input, "%s %d", precinctId, &district) == 2) {
            /* Find precinct */
            int found = 0;
            for (int i = 0; i < app->precinctCount; i++) {
                if (strcmp(app->precincts[i].id, precinctId) == 0) {
                    if (district >= 0 && district <= app->currentPlan.numDistricts) {
                        app->precincts[i].district = district;
                        printf("Assigned precinct %s to district %d\n", precinctId, district);
                    } else {
                        printf("Invalid district number. Use 0-%d\n", app->currentPlan.numDistricts);
                    }
                    found = 1;
                    break;
                }
            }
            if (!found) {
                printf("Precinct '%s' not found.\n", precinctId);
            }
        } else {
            printf("Usage: <precinct_id> <district_number>\n");
        }
    }
}
