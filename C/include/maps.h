/*
 * US Redistricting Tool - C Windows Console Application
 * Header file with data structures and function declarations
 */

#ifndef MAPS_H
#define MAPS_H

#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <math.h>
#include <time.h>

#ifdef _WIN32
#include <windows.h>
#include <direct.h>
#define mkdir(path, mode) _mkdir(path)
#define PATH_SEP "\\"
#else
#include <sys/stat.h>
#include <sys/types.h>
#include <dirent.h>
#define PATH_SEP "/"
#endif

/* Maximum limits */
#define MAX_STATES 60
#define MAX_PRECINCTS 50000
#define MAX_DISTRICTS 100
#define MAX_PLANS 100
#define MAX_PATH_LEN 512
#define MAX_NAME_LEN 128
#define MAX_ID_LEN 64
#define MAX_NEIGHBORS 100

/* Fairness presets */
typedef enum {
    FAIRNESS_VERY_R = 0,
    FAIRNESS_LEAN_R,
    FAIRNESS_FAIR,
    FAIRNESS_LEAN_D,
    FAIRNESS_VERY_D
} FairnessPreset;

/* Fairness preset configuration */
typedef struct {
    const char* label;
    double targetDemShare;
    double tolerance;
    const char* description;
} FairnessConfig;

/* Coordinate point */
typedef struct {
    double x;
    double y;
} Point;

/* State metadata */
typedef struct {
    char code[8];
    char abbr[4];
    char name[MAX_NAME_LEN];
    int defaultNumDistricts;
} State;

/* Precinct data */
typedef struct {
    int index;
    char id[MAX_ID_LEN];
    int population;
    int dem;
    int rep;
    char county[MAX_NAME_LEN];
    Point centroid;
    double demShare;
    int district;
    int neighbors[MAX_NEIGHBORS];
    int neighborCount;
} Precinct;

/* District statistics */
typedef struct {
    int districtId;
    int population;
    int demVotes;
    int repVotes;
    double demShare;
    double compactness;
    double area;
    double perimeter;
    int precinctCount;
    int countyCount;
} DistrictStats;

/* Plan data */
typedef struct {
    char state[8];
    char planId[MAX_ID_LEN];
    char name[MAX_NAME_LEN];
    int numDistricts;
    int* assignments;  /* Array: precinctIndex -> districtId */
    int assignmentCount;
    char lastUpdated[32];
} Plan;

/* Application state */
typedef struct {
    char dataDir[MAX_PATH_LEN];
    State states[MAX_STATES];
    int stateCount;
    
    /* Current loaded state */
    State* currentState;
    Precinct precincts[MAX_PRECINCTS];
    int precinctCount;
    
    /* Current plan */
    Plan currentPlan;
    int hasPlan;
    
    /* Plans list */
    char planIds[MAX_PLANS][MAX_ID_LEN];
    char planNames[MAX_PLANS][MAX_NAME_LEN];
    int planCount;
} AppState;

/* Global fairness presets */
extern FairnessConfig FAIRNESS_PRESETS[5];

/* Function declarations - states.c */
int load_states_list(AppState* app);
int load_state_data(AppState* app, const char* stateCode);
void print_states_list(AppState* app);

/* Function declarations - plans.c */
int load_plans_list(AppState* app, const char* stateCode);
int load_plan(AppState* app, const char* stateCode, const char* planId);
int save_plan(AppState* app);
void create_new_plan(AppState* app, const char* name);
void print_plans_list(AppState* app);

/* Function declarations - metrics.c */
void compute_district_stats(AppState* app, DistrictStats* stats, int numDistricts);
void print_metrics(AppState* app);
double calculate_compactness(double area, double perimeter);

/* Function declarations - automap.c */
int generate_automap(AppState* app, int numDistricts, FairnessPreset preset, double customTarget);
void print_automap_summary(AppState* app);

/* Function declarations - utils.c */
int ensure_directory(const char* path);
int file_exists(const char* path);
char* trim_string(char* str);
void get_timestamp(char* buffer, size_t size);
int parse_int(const char* str, int defaultVal);

/* Function declarations - json_utils.c */
int parse_states_json(AppState* app, const char* jsonStr);
int parse_geojson(AppState* app, const char* jsonStr);
char* create_plan_json(AppState* app);
int parse_plan_json(AppState* app, const char* jsonStr);

/* Function declarations - ui.c */
void show_main_menu(void);
void show_state_menu(AppState* app);
void show_district_settings(AppState* app);
void show_automap_menu(AppState* app);
void show_plan_menu(AppState* app);
void clear_screen(void);
int get_user_choice(int min, int max);

#endif /* MAPS_H */
