/*
 * US Redistricting Tool - Main Program
 * Windows Console Application
 * 
 * A full-featured redistricting software that provides:
 * - State and precinct data management
 * - District assignment (manual and automatic)
 * - Fairness-based automap generation
 * - Comprehensive metrics calculation
 * - Plan save/load functionality
 * 
 * Build with: x86_64-w64-mingw32-gcc -o redistricting.exe main.c ...
 */

#include "../include/maps.h"

/* External UI functions */
extern void show_help(void);
extern void show_precinct_summary(AppState* app);
extern void show_manual_assignment(AppState* app);
extern void get_user_string(const char* prompt, char* buffer, int size);

/* Initialize application state */
static void init_app(AppState* app) {
    memset(app, 0, sizeof(AppState));
    
    /* Set data directory */
#ifdef _WIN32
    /* Try current directory first, then parent's data directory */
    if (file_exists("data" PATH_SEP "states.json")) {
        strcpy(app->dataDir, "data");
    } else if (file_exists(".." PATH_SEP "data" PATH_SEP "states.json")) {
        strcpy(app->dataDir, ".." PATH_SEP "data");
    } else {
        strcpy(app->dataDir, "data");
    }
#else
    if (file_exists("data/states.json")) {
        strcpy(app->dataDir, "data");
    } else if (file_exists("../data/states.json")) {
        strcpy(app->dataDir, "../data");
    } else {
        strcpy(app->dataDir, "data");
    }
#endif
    
    printf("Data directory: %s\n", app->dataDir);
}

/* Handle state menu */
static void handle_state_menu(AppState* app) {
    int choice;
    char input[64];
    
    while (1) {
        show_state_menu(app);
        choice = get_user_choice(0, 3);
        
        switch (choice) {
            case 0:
                return;
                
            case 1:
                print_states_list(app);
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 2:
                print_states_list(app);
                get_user_string("Enter state code (e.g., NC, CA): ", input, sizeof(input));
                if (input[0]) {
                    load_state_data(app, input);
                }
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 3:
                show_precinct_summary(app);
                printf("\nPress Enter to continue...");
                getchar();
                break;
                
            default:
                break;
        }
    }
}

/* Handle plan menu */
static void handle_plan_menu(AppState* app) {
    int choice;
    char input[128];
    
    while (1) {
        show_plan_menu(app);
        choice = get_user_choice(0, 5);
        
        switch (choice) {
            case 0:
                return;
                
            case 1:
                if (!app->currentState) {
                    printf("Please load a state first.\n");
                } else {
                    get_user_string("Enter plan name: ", input, sizeof(input));
                    create_new_plan(app, input[0] ? input : "New Plan");
                }
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 2:
                save_plan(app);
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 3:
                print_plans_list(app);
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 4:
                if (!app->currentState) {
                    printf("Please load a state first.\n");
                } else {
                    print_plans_list(app);
                    if (app->planCount > 0) {
                        get_user_string("Enter plan ID: ", input, sizeof(input));
                        if (input[0]) {
                            load_plan(app, app->currentState->abbr, input);
                        }
                    }
                }
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 5:
                if (app->hasPlan) {
                    get_user_string("Enter new plan name: ", input, sizeof(input));
                    if (input[0]) {
                        strncpy(app->currentPlan.name, input, sizeof(app->currentPlan.name) - 1);
                        printf("Plan renamed to: %s\n", app->currentPlan.name);
                    }
                } else {
                    printf("No plan loaded.\n");
                }
                printf("Press Enter to continue...");
                getchar();
                break;
                
            default:
                break;
        }
    }
}

/* Handle district settings menu */
static void handle_district_settings(AppState* app) {
    int choice;
    char input[32];
    
    while (1) {
        show_district_settings(app);
        choice = get_user_choice(0, 3);
        
        switch (choice) {
            case 0:
                return;
                
            case 1:
                get_user_string("Enter number of districts (1-100): ", input, sizeof(input));
                {
                    int num = atoi(input);
                    if (num >= 1 && num <= 100) {
                        if (app->hasPlan) {
                            app->currentPlan.numDistricts = num;
                        }
                        if (app->currentState) {
                            app->currentState->defaultNumDistricts = num;
                        }
                        printf("Districts set to: %d\n", num);
                    } else {
                        printf("Invalid number. Must be 1-100.\n");
                    }
                }
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 2:
                printf("Clearing all district assignments...\n");
                for (int i = 0; i < app->precinctCount; i++) {
                    app->precincts[i].district = 0;
                }
                printf("All precincts unassigned.\n");
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 3:
                if (app->hasPlan || app->precinctCount > 0) {
                    int numDist = app->hasPlan ? app->currentPlan.numDistricts : 10;
                    printf("\nDistrict Breakdown:\n");
                    printf("%-10s %-12s %-10s %-10s %-8s\n", 
                           "District", "Population", "Dem", "Rep", "Dem%");
                    printf("%-10s %-12s %-10s %-10s %-8s\n", 
                           "--------", "----------", "-------", "-------", "-----");
                    
                    for (int d = 1; d <= numDist; d++) {
                        int pop = 0, dem = 0, rep = 0;
                        for (int i = 0; i < app->precinctCount; i++) {
                            if (app->precincts[i].district == d) {
                                pop += app->precincts[i].population;
                                dem += app->precincts[i].dem;
                                rep += app->precincts[i].rep;
                            }
                        }
                        double demShare = (dem + rep) > 0 ? 100.0 * dem / (dem + rep) : 0;
                        printf("%-10d %-12d %-10d %-10d %-7.1f%%\n", 
                               d, pop, dem, rep, demShare);
                    }
                } else {
                    printf("No data to display.\n");
                }
                printf("\nPress Enter to continue...");
                getchar();
                break;
                
            default:
                break;
        }
    }
}

/* Handle automap menu */
static void handle_automap_menu(AppState* app) {
    int choice;
    char input[32];
    
    if (!app->currentState || app->precinctCount == 0) {
        printf("Please load a state with precinct data first.\n");
        printf("Press Enter to continue...");
        getchar();
        return;
    }
    
    if (!app->hasPlan) {
        create_new_plan(app, "Automap Plan");
    }
    
    show_automap_menu(app);
    choice = get_user_choice(0, 6);
    
    if (choice == 0) return;
    
    int numDistricts = app->currentPlan.numDistricts;
    get_user_string("Number of districts (press Enter for current): ", input, sizeof(input));
    if (input[0]) {
        int num = atoi(input);
        if (num >= 1 && num <= 100) {
            numDistricts = num;
            app->currentPlan.numDistricts = num;
        }
    }
    
    double customTarget = 0;
    FairnessPreset preset;
    
    switch (choice) {
        case 1:
            preset = FAIRNESS_VERY_R;
            break;
        case 2:
            preset = FAIRNESS_LEAN_R;
            break;
        case 3:
            preset = FAIRNESS_FAIR;
            break;
        case 4:
            preset = FAIRNESS_LEAN_D;
            break;
        case 5:
            preset = FAIRNESS_VERY_D;
            break;
        case 6:
            preset = FAIRNESS_FAIR;
            get_user_string("Enter target Democratic %% (0-100): ", input, sizeof(input));
            customTarget = atof(input) / 100.0;
            if (customTarget < 0 || customTarget > 1) {
                printf("Invalid percentage. Using 50%%.\n");
                customTarget = 0.5;
            }
            break;
        default:
            return;
    }
    
    printf("\nGenerating districts...\n");
    generate_automap(app, numDistricts, preset, customTarget);
    
    printf("\nPress Enter to continue...");
    getchar();
}

/* Main program */
int main(int argc, char* argv[]) {
    (void)argc;
    (void)argv;
    AppState app;
    int choice;
    
    /* Initialize */
    init_app(&app);
    
    /* Load states list */
    printf("Loading states list...\n");
    load_states_list(&app);
    printf("Found %d states with data.\n", app.stateCount);
    
    /* Main loop */
    while (1) {
        clear_screen();
        show_main_menu();
        
        /* Show current state if loaded */
        if (app.currentState) {
            printf("\nCurrent: %s (%s) - %d precincts", 
                   app.currentState->name, 
                   app.currentState->abbr,
                   app.precinctCount);
            if (app.hasPlan) {
                printf(" - Plan: %s", app.currentPlan.name);
            }
            printf("\n");
        }
        
        choice = get_user_choice(0, 9);
        
        switch (choice) {
            case 0:
                printf("\nThank you for using the US Redistricting Tool!\n");
                printf("Goodbye.\n");
                return 0;
                
            case 1:
                clear_screen();
                print_states_list(&app);
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 2:
                clear_screen();
                print_states_list(&app);
                {
                    char input[64];
                    get_user_string("Enter state code (e.g., NC, CA): ", input, sizeof(input));
                    if (input[0]) {
                        load_state_data(&app, input);
                    }
                }
                printf("\nPress Enter to continue...");
                getchar();
                break;
                
            case 3:
                clear_screen();
                handle_state_menu(&app);
                break;
                
            case 4:
                clear_screen();
                handle_plan_menu(&app);
                break;
                
            case 5:
                clear_screen();
                handle_district_settings(&app);
                break;
                
            case 6:
                clear_screen();
                handle_automap_menu(&app);
                break;
                
            case 7:
                clear_screen();
                print_metrics(&app);
                printf("Press Enter to continue...");
                getchar();
                break;
                
            case 8:
                clear_screen();
                show_manual_assignment(&app);
                break;
                
            case 9:
                show_help();
                break;
                
            default:
                break;
        }
    }
    
    return 0;
}
