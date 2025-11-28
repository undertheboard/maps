/*
 * US Redistricting Tool - Plan Management
 */

#include "../include/maps.h"
#include "../lib/cJSON.h"

#ifdef _WIN32
#include <io.h>
#include <direct.h>
#else
#include <dirent.h>
#endif

/* External functions */
extern char* read_file(const char* path);
extern int write_file(const char* path, const char* content);

/* Load list of saved plans for a state */
int load_plans_list(AppState* app, const char* stateCode) {
    char plansDir[MAX_PATH_LEN];
    snprintf(plansDir, sizeof(plansDir), "%s" PATH_SEP "plans" PATH_SEP "%s",
             app->dataDir, stateCode);
    
    app->planCount = 0;
    
    if (!file_exists(plansDir)) {
        return 0;
    }
    
#ifdef _WIN32
    WIN32_FIND_DATA findData;
    char searchPath[MAX_PATH_LEN];
    snprintf(searchPath, sizeof(searchPath), "%s" PATH_SEP "*.json", plansDir);
    
    HANDLE hFind = FindFirstFile(searchPath, &findData);
    if (hFind != INVALID_HANDLE_VALUE) {
        do {
            if (!(findData.dwFileAttributes & FILE_ATTRIBUTE_DIRECTORY)) {
                if (app->planCount >= MAX_PLANS) break;
                
                char planPath[MAX_PATH_LEN];
                snprintf(planPath, sizeof(planPath), "%s" PATH_SEP "%s", plansDir, findData.cFileName);
                
                char* jsonStr = read_file(planPath);
                if (jsonStr) {
                    cJSON* root = cJSON_Parse(jsonStr);
                    if (root) {
                        cJSON* planId = cJSON_GetObjectItem(root, "planId");
                        cJSON* name = cJSON_GetObjectItem(root, "name");
                        
                        if (planId && cJSON_IsString(planId)) {
                            strncpy(app->planIds[app->planCount], planId->valuestring, MAX_ID_LEN - 1);
                        } else {
                            /* Use filename without extension */
                            char* dot = strrchr(findData.cFileName, '.');
                            if (dot) *dot = '\0';
                            strncpy(app->planIds[app->planCount], findData.cFileName, MAX_ID_LEN - 1);
                        }
                        
                        if (name && cJSON_IsString(name)) {
                            strncpy(app->planNames[app->planCount], name->valuestring, MAX_NAME_LEN - 1);
                        } else {
                            strncpy(app->planNames[app->planCount], "(untitled)", MAX_NAME_LEN - 1);
                        }
                        
                        app->planCount++;
                        cJSON_Delete(root);
                    }
                    free(jsonStr);
                }
            }
        } while (FindNextFile(hFind, &findData));
        FindClose(hFind);
    }
#else
    DIR* dir = opendir(plansDir);
    if (dir) {
        struct dirent* entry;
        while ((entry = readdir(dir)) != NULL) {
            if (app->planCount >= MAX_PLANS) break;
            
            char* ext = strrchr(entry->d_name, '.');
            if (ext && strcmp(ext, ".json") == 0) {
                char planPath[MAX_PATH_LEN];
                snprintf(planPath, sizeof(planPath), "%s" PATH_SEP "%s", plansDir, entry->d_name);
                
                char* jsonStr = read_file(planPath);
                if (jsonStr) {
                    cJSON* root = cJSON_Parse(jsonStr);
                    if (root) {
                        cJSON* planId = cJSON_GetObjectItem(root, "planId");
                        cJSON* name = cJSON_GetObjectItem(root, "name");
                        
                        if (planId && cJSON_IsString(planId)) {
                            strncpy(app->planIds[app->planCount], planId->valuestring, MAX_ID_LEN - 1);
                        } else {
                            char filename[MAX_ID_LEN];
                            strncpy(filename, entry->d_name, MAX_ID_LEN - 1);
                            char* dot = strrchr(filename, '.');
                            if (dot) *dot = '\0';
                            strncpy(app->planIds[app->planCount], filename, MAX_ID_LEN - 1);
                        }
                        
                        if (name && cJSON_IsString(name)) {
                            strncpy(app->planNames[app->planCount], name->valuestring, MAX_NAME_LEN - 1);
                        } else {
                            strncpy(app->planNames[app->planCount], "(untitled)", MAX_NAME_LEN - 1);
                        }
                        
                        app->planCount++;
                        cJSON_Delete(root);
                    }
                    free(jsonStr);
                }
            }
        }
        closedir(dir);
    }
#endif
    
    return app->planCount;
}

/* Load a specific plan */
int load_plan(AppState* app, const char* stateCode, const char* planId) {
    char planPath[MAX_PATH_LEN];
    snprintf(planPath, sizeof(planPath), "%s" PATH_SEP "plans" PATH_SEP "%s" PATH_SEP "%s.json",
             app->dataDir, stateCode, planId);
    
    printf("Loading plan from: %s\n", planPath);
    
    char* jsonStr = read_file(planPath);
    if (!jsonStr) {
        fprintf(stderr, "Could not read plan file.\n");
        return 0;
    }
    
    int result = parse_plan_json(app, jsonStr);
    free(jsonStr);
    
    if (result) {
        printf("Loaded plan: %s\n", app->currentPlan.name);
        printf("Districts: %d\n", app->currentPlan.numDistricts);
        
        /* Count assigned precincts */
        int assigned = 0;
        for (int i = 0; i < app->precinctCount; i++) {
            if (app->precincts[i].district > 0) {
                assigned++;
            }
        }
        printf("Assigned precincts: %d / %d\n", assigned, app->precinctCount);
    }
    
    return result;
}

/* Save current plan to file */
int save_plan(AppState* app) {
    if (!app->currentState) {
        fprintf(stderr, "No state loaded.\n");
        return 0;
    }
    
    if (!app->hasPlan) {
        fprintf(stderr, "No plan to save.\n");
        return 0;
    }
    
    /* Ensure plans directory exists */
    char plansDir[MAX_PATH_LEN];
    snprintf(plansDir, sizeof(plansDir), "%s" PATH_SEP "plans", app->dataDir);
    ensure_directory(plansDir);
    
    char statePlansDir[MAX_PATH_LEN];
    snprintf(statePlansDir, sizeof(statePlansDir), "%s" PATH_SEP "%s", 
             plansDir, app->currentPlan.state);
    ensure_directory(statePlansDir);
    
    /* Generate plan ID if not set */
    if (app->currentPlan.planId[0] == '\0') {
        snprintf(app->currentPlan.planId, sizeof(app->currentPlan.planId), 
                 "plan_%ld", (long)time(NULL));
    }
    
    /* Create JSON */
    char* jsonStr = create_plan_json(app);
    if (!jsonStr) {
        fprintf(stderr, "Failed to create plan JSON.\n");
        return 0;
    }
    
    /* Write to file */
    char planPath[MAX_PATH_LEN];
    snprintf(planPath, sizeof(planPath), "%s" PATH_SEP "%s.json", 
             statePlansDir, app->currentPlan.planId);
    
    printf("Saving plan to: %s\n", planPath);
    
    int result = write_file(planPath, jsonStr);
    free(jsonStr);
    
    if (result) {
        printf("Plan saved successfully!\n");
        /* Reload plans list */
        load_plans_list(app, app->currentPlan.state);
    } else {
        fprintf(stderr, "Failed to save plan.\n");
    }
    
    return result;
}

/* Create a new empty plan */
void create_new_plan(AppState* app, const char* name) {
    if (!app->currentState) {
        fprintf(stderr, "No state loaded.\n");
        return;
    }
    
    memset(&app->currentPlan, 0, sizeof(Plan));
    
    strncpy(app->currentPlan.state, app->currentState->abbr, sizeof(app->currentPlan.state) - 1);
    snprintf(app->currentPlan.planId, sizeof(app->currentPlan.planId), "plan_%ld", (long)time(NULL));
    
    if (name && name[0]) {
        strncpy(app->currentPlan.name, name, sizeof(app->currentPlan.name) - 1);
    } else {
        strcpy(app->currentPlan.name, "Untitled Plan");
    }
    
    app->currentPlan.numDistricts = app->currentState->defaultNumDistricts;
    
    /* Reset all district assignments */
    for (int i = 0; i < app->precinctCount; i++) {
        app->precincts[i].district = 0;
    }
    
    app->hasPlan = 1;
    
    printf("Created new plan: %s\n", app->currentPlan.name);
    printf("Districts: %d\n", app->currentPlan.numDistricts);
}

/* Print list of saved plans */
void print_plans_list(AppState* app) {
    printf("\n=== Saved Plans ===\n");
    if (app->planCount == 0) {
        printf("No saved plans for this state.\n");
    } else {
        printf("%-4s %-20s %s\n", "#", "Plan ID", "Name");
        printf("%-4s %-20s %s\n", "---", "--------------------", "--------------------");
        for (int i = 0; i < app->planCount; i++) {
            printf("%-4d %-20s %s\n", 
                   i + 1,
                   app->planIds[i],
                   app->planNames[i]);
        }
    }
    printf("\n");
}
