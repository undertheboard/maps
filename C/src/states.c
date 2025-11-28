/*
 * US Redistricting Tool - State Management
 */

#include "../include/maps.h"
#include "../lib/cJSON.h"

#ifdef _WIN32
#include <io.h>
#include <direct.h>
#else
#include <dirent.h>
#endif

/* External function from utils.c */
extern char* read_file(const char* path);

/* Load list of available states from data directory */
int load_states_list(AppState* app) {
    char statesFile[MAX_PATH_LEN];
    snprintf(statesFile, sizeof(statesFile), "%s" PATH_SEP "states.json", app->dataDir);
    
    /* First, load states metadata from states.json */
    char* jsonStr = read_file(statesFile);
    if (jsonStr) {
        parse_states_json(app, jsonStr);
        free(jsonStr);
    }
    
    /* Check which states have actual precinct data */
    char precinctsDir[MAX_PATH_LEN];
    snprintf(precinctsDir, sizeof(precinctsDir), "%s" PATH_SEP "precincts", app->dataDir);
    
#ifdef _WIN32
    WIN32_FIND_DATA findData;
    char searchPath[MAX_PATH_LEN];
    snprintf(searchPath, sizeof(searchPath), "%s" PATH_SEP "*", precinctsDir);
    
    HANDLE hFind = FindFirstFile(searchPath, &findData);
    if (hFind != INVALID_HANDLE_VALUE) {
        do {
            if (findData.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY) {
                if (strcmp(findData.cFileName, ".") != 0 && strcmp(findData.cFileName, "..") != 0) {
                    /* Check if precincts.geojson exists */
                    char geoPath[MAX_PATH_LEN];
                    snprintf(geoPath, sizeof(geoPath), "%s" PATH_SEP "%s" PATH_SEP "precincts.geojson",
                             precinctsDir, findData.cFileName);
                    
                    if (file_exists(geoPath)) {
                        /* Check if this state is already in our list */
                        int found = 0;
                        for (int i = 0; i < app->stateCount; i++) {
                            if (_stricmp(app->states[i].abbr, findData.cFileName) == 0 ||
                                _stricmp(app->states[i].code, findData.cFileName) == 0) {
                                found = 1;
                                break;
                            }
                        }
                        
                        if (!found && app->stateCount < MAX_STATES) {
                            State* state = &app->states[app->stateCount];
                            strncpy(state->code, findData.cFileName, sizeof(state->code) - 1);
                            strncpy(state->abbr, findData.cFileName, sizeof(state->abbr) - 1);
                            strncpy(state->name, findData.cFileName, sizeof(state->name) - 1);
                            state->defaultNumDistricts = 10;
                            app->stateCount++;
                        }
                    }
                }
            }
        } while (FindNextFile(hFind, &findData));
        FindClose(hFind);
    }
#else
    DIR* dir = opendir(precinctsDir);
    if (dir) {
        struct dirent* entry;
        while ((entry = readdir(dir)) != NULL) {
            if (entry->d_type == DT_DIR) {
                if (strcmp(entry->d_name, ".") != 0 && strcmp(entry->d_name, "..") != 0) {
                    char geoPath[MAX_PATH_LEN];
                    snprintf(geoPath, sizeof(geoPath), "%s" PATH_SEP "%s" PATH_SEP "precincts.geojson",
                             precinctsDir, entry->d_name);
                    
                    if (file_exists(geoPath)) {
                        int found = 0;
                        for (int i = 0; i < app->stateCount; i++) {
                            if (strcasecmp(app->states[i].abbr, entry->d_name) == 0 ||
                                strcasecmp(app->states[i].code, entry->d_name) == 0) {
                                found = 1;
                                break;
                            }
                        }
                        
                        if (!found && app->stateCount < MAX_STATES) {
                            State* state = &app->states[app->stateCount];
                            strncpy(state->code, entry->d_name, sizeof(state->code) - 1);
                            strncpy(state->abbr, entry->d_name, sizeof(state->abbr) - 1);
                            strncpy(state->name, entry->d_name, sizeof(state->name) - 1);
                            state->defaultNumDistricts = 10;
                            app->stateCount++;
                        }
                    }
                }
            }
        }
        closedir(dir);
    }
#endif
    
    return app->stateCount;
}

/* Load precinct data for a specific state */
int load_state_data(AppState* app, const char* stateCode) {
    char upperCode[8];
    strncpy(upperCode, stateCode, sizeof(upperCode) - 1);
    upperCode[sizeof(upperCode) - 1] = '\0';
    
    /* Convert to uppercase */
    for (int i = 0; upperCode[i]; i++) {
        if (upperCode[i] >= 'a' && upperCode[i] <= 'z') {
            upperCode[i] -= 32;
        }
    }
    
    /* Find state in list */
    app->currentState = NULL;
    for (int i = 0; i < app->stateCount; i++) {
        if (strcmp(app->states[i].abbr, upperCode) == 0 ||
            strcmp(app->states[i].code, upperCode) == 0) {
            app->currentState = &app->states[i];
            break;
        }
    }
    
    if (!app->currentState) {
        fprintf(stderr, "State '%s' not found in states list.\n", stateCode);
        return 0;
    }
    
    /* Load precincts.geojson */
    char geoPath[MAX_PATH_LEN];
    snprintf(geoPath, sizeof(geoPath), "%s" PATH_SEP "precincts" PATH_SEP "%s" PATH_SEP "precincts.geojson",
             app->dataDir, upperCode);
    
    printf("Loading precinct data from: %s\n", geoPath);
    
    char* jsonStr = read_file(geoPath);
    if (!jsonStr) {
        fprintf(stderr, "Could not read precinct data file.\n");
        fprintf(stderr, "Please ensure precinct data exists at: %s\n", geoPath);
        return 0;
    }
    
    printf("Parsing GeoJSON data...\n");
    int result = parse_geojson(app, jsonStr);
    free(jsonStr);
    
    if (result) {
        printf("Loaded %d precincts for %s (%s)\n", 
               app->precinctCount, 
               app->currentState->name,
               app->currentState->abbr);
        
        /* Calculate total population and votes */
        int totalPop = 0, totalDem = 0, totalRep = 0;
        for (int i = 0; i < app->precinctCount; i++) {
            totalPop += app->precincts[i].population;
            totalDem += app->precincts[i].dem;
            totalRep += app->precincts[i].rep;
        }
        
        printf("Total population: %d\n", totalPop);
        printf("Total Dem votes: %d\n", totalDem);
        printf("Total Rep votes: %d\n", totalRep);
        
        if (totalDem + totalRep > 0) {
            printf("Overall Dem share: %.1f%%\n", 100.0 * totalDem / (totalDem + totalRep));
        }
        
        /* Load plans list for this state */
        load_plans_list(app, upperCode);
    }
    
    return result;
}

/* Print list of available states */
void print_states_list(AppState* app) {
    printf("\n=== Available States ===\n");
    if (app->stateCount == 0) {
        printf("No states with precinct data found.\n");
        printf("Place precinct GeoJSON data in: %s" PATH_SEP "precincts" PATH_SEP "<STATE_CODE>" PATH_SEP "precincts.geojson\n", 
               app->dataDir);
    } else {
        printf("%-4s %-6s %-25s %s\n", "#", "Code", "Name", "Districts");
        printf("%-4s %-6s %-25s %s\n", "---", "----", "-------------------------", "---------");
        for (int i = 0; i < app->stateCount; i++) {
            printf("%-4d %-6s %-25s %d\n", 
                   i + 1,
                   app->states[i].abbr,
                   app->states[i].name,
                   app->states[i].defaultNumDistricts);
        }
    }
    printf("\n");
}
