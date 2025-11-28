/*
 * US Redistricting Tool - JSON Parsing Utilities
 */

#include "../include/maps.h"
#include "../lib/cJSON.h"

/* Parse states.json and populate states list */
int parse_states_json(AppState* app, const char* jsonStr) {
    cJSON* root = cJSON_Parse(jsonStr);
    if (!root) {
        fprintf(stderr, "Error parsing states.json\n");
        return 0;
    }
    
    if (!cJSON_IsArray(root)) {
        cJSON_Delete(root);
        return 0;
    }
    
    app->stateCount = 0;
    cJSON* stateItem;
    cJSON_ArrayForEach(stateItem, root) {
        if (app->stateCount >= MAX_STATES) break;
        
        State* state = &app->states[app->stateCount];
        
        cJSON* code = cJSON_GetObjectItem(stateItem, "code");
        cJSON* abbr = cJSON_GetObjectItem(stateItem, "abbr");
        cJSON* name = cJSON_GetObjectItem(stateItem, "name");
        cJSON* numDist = cJSON_GetObjectItem(stateItem, "defaultNumDistricts");
        
        if (abbr && cJSON_IsString(abbr)) {
            strncpy(state->abbr, abbr->valuestring, sizeof(state->abbr) - 1);
            strncpy(state->code, abbr->valuestring, sizeof(state->code) - 1);
        } else if (code && cJSON_IsString(code)) {
            strncpy(state->code, code->valuestring, sizeof(state->code) - 1);
            strncpy(state->abbr, code->valuestring, sizeof(state->abbr) - 1);
        }
        
        if (name && cJSON_IsString(name)) {
            strncpy(state->name, name->valuestring, sizeof(state->name) - 1);
        }
        
        if (numDist && cJSON_IsNumber(numDist)) {
            state->defaultNumDistricts = numDist->valueint;
        } else {
            state->defaultNumDistricts = 10;
        }
        
        app->stateCount++;
    }
    
    cJSON_Delete(root);
    return 1;
}

/* Extract centroid from GeoJSON geometry */
static Point get_centroid_from_geometry(cJSON* geometry) {
    Point centroid = {0.0, 0.0};
    
    if (!geometry) return centroid;
    
    cJSON* type = cJSON_GetObjectItem(geometry, "type");
    cJSON* coordinates = cJSON_GetObjectItem(geometry, "coordinates");
    
    if (!type || !cJSON_IsString(type) || !coordinates) return centroid;
    
    cJSON* ring = NULL;
    
    if (strcmp(type->valuestring, "Polygon") == 0) {
        ring = cJSON_GetArrayItem(coordinates, 0);
    } else if (strcmp(type->valuestring, "MultiPolygon") == 0) {
        cJSON* firstPoly = cJSON_GetArrayItem(coordinates, 0);
        if (firstPoly) {
            ring = cJSON_GetArrayItem(firstPoly, 0);
        }
    }
    
    if (!ring || !cJSON_IsArray(ring)) return centroid;
    
    double sumX = 0, sumY = 0;
    int count = 0;
    
    cJSON* coord;
    cJSON_ArrayForEach(coord, ring) {
        if (cJSON_IsArray(coord) && cJSON_GetArraySize(coord) >= 2) {
            cJSON* x = cJSON_GetArrayItem(coord, 0);
            cJSON* y = cJSON_GetArrayItem(coord, 1);
            if (x && y) {
                sumX += x->valuedouble;
                sumY += y->valuedouble;
                count++;
            }
        }
    }
    
    if (count > 0) {
        centroid.x = sumX / count;
        centroid.y = sumY / count;
    }
    
    return centroid;
}

/* Parse GeoJSON FeatureCollection and populate precincts */
int parse_geojson(AppState* app, const char* jsonStr) {
    cJSON* root = cJSON_Parse(jsonStr);
    if (!root) {
        fprintf(stderr, "Error parsing GeoJSON\n");
        return 0;
    }
    
    cJSON* type = cJSON_GetObjectItem(root, "type");
    if (!type || !cJSON_IsString(type) || strcmp(type->valuestring, "FeatureCollection") != 0) {
        fprintf(stderr, "Invalid GeoJSON: not a FeatureCollection\n");
        cJSON_Delete(root);
        return 0;
    }
    
    cJSON* features = cJSON_GetObjectItem(root, "features");
    if (!features || !cJSON_IsArray(features)) {
        fprintf(stderr, "Invalid GeoJSON: no features array\n");
        cJSON_Delete(root);
        return 0;
    }
    
    app->precinctCount = 0;
    int index = 0;
    
    cJSON* feature;
    cJSON_ArrayForEach(feature, features) {
        if (app->precinctCount >= MAX_PRECINCTS) break;
        
        Precinct* p = &app->precincts[app->precinctCount];
        memset(p, 0, sizeof(Precinct));
        
        p->index = index++;
        p->district = 0; /* Unassigned */
        
        cJSON* properties = cJSON_GetObjectItem(feature, "properties");
        cJSON* geometry = cJSON_GetObjectItem(feature, "geometry");
        
        if (properties) {
            /* Get precinct ID */
            cJSON* id = cJSON_GetObjectItem(properties, "id");
            if (!id) id = cJSON_GetObjectItem(properties, "precinct_id");
            if (!id) id = cJSON_GetObjectItem(properties, "GEOID20");
            if (!id) id = cJSON_GetObjectItem(properties, "UNIQUE_ID");
            
            if (id) {
                if (cJSON_IsString(id)) {
                    strncpy(p->id, id->valuestring, sizeof(p->id) - 1);
                } else if (cJSON_IsNumber(id)) {
                    snprintf(p->id, sizeof(p->id), "%d", id->valueint);
                }
            } else {
                snprintf(p->id, sizeof(p->id), "p_%d", p->index);
            }
            
            /* Get population */
            cJSON* pop = cJSON_GetObjectItem(properties, "population");
            if (!pop) pop = cJSON_GetObjectItem(properties, "TOTPOP");
            if (!pop) pop = cJSON_GetObjectItem(properties, "POP100");
            if (pop && cJSON_IsNumber(pop)) {
                p->population = (int)pop->valuedouble;
            }
            
            /* Get dem votes */
            cJSON* dem = cJSON_GetObjectItem(properties, "dem");
            if (!dem) dem = cJSON_GetObjectItem(properties, "dem_votes");
            if (!dem) dem = cJSON_GetObjectItem(properties, "G20PREDBID");
            if (dem && cJSON_IsNumber(dem)) {
                p->dem = (int)dem->valuedouble;
            }
            
            /* Get rep votes */
            cJSON* rep = cJSON_GetObjectItem(properties, "rep");
            if (!rep) rep = cJSON_GetObjectItem(properties, "rep_votes");
            if (!rep) rep = cJSON_GetObjectItem(properties, "G20PRERTRU");
            if (rep && cJSON_IsNumber(rep)) {
                p->rep = (int)rep->valuedouble;
            }
            
            /* Get county */
            cJSON* county = cJSON_GetObjectItem(properties, "county");
            if (!county) county = cJSON_GetObjectItem(properties, "COUNTY");
            if (!county) county = cJSON_GetObjectItem(properties, "COUNTYFP");
            if (!county) county = cJSON_GetObjectItem(properties, "COUNTYFP20");
            
            if (county && cJSON_IsString(county)) {
                strncpy(p->county, county->valuestring, sizeof(p->county) - 1);
            } else {
                strcpy(p->county, "unknown");
            }
        }
        
        /* Calculate dem share */
        int totalVotes = p->dem + p->rep;
        p->demShare = totalVotes > 0 ? (double)p->dem / totalVotes : 0.5;
        
        /* Get centroid */
        p->centroid = get_centroid_from_geometry(geometry);
        
        app->precinctCount++;
    }
    
    cJSON_Delete(root);
    
    /* Build adjacency graph based on proximity */
    double threshold = 0.01;
    for (int i = 0; i < app->precinctCount; i++) {
        app->precincts[i].neighborCount = 0;
        for (int j = 0; j < app->precinctCount; j++) {
            if (i == j) continue;
            if (app->precincts[i].neighborCount >= MAX_NEIGHBORS) break;
            
            double dx = app->precincts[i].centroid.x - app->precincts[j].centroid.x;
            double dy = app->precincts[i].centroid.y - app->precincts[j].centroid.y;
            double dist = sqrt(dx * dx + dy * dy);
            
            /* Consider adjacent if close enough or same county */
            if (dist < threshold || strcmp(app->precincts[i].county, app->precincts[j].county) == 0) {
                app->precincts[i].neighbors[app->precincts[i].neighborCount++] = j;
            }
        }
    }
    
    return 1;
}

/* Create JSON string for saving a plan */
char* create_plan_json(AppState* app) {
    cJSON* root = cJSON_CreateObject();
    
    cJSON_AddStringToObject(root, "state", app->currentPlan.state);
    cJSON_AddStringToObject(root, "planId", app->currentPlan.planId);
    cJSON_AddStringToObject(root, "name", app->currentPlan.name);
    cJSON_AddNumberToObject(root, "numDistricts", app->currentPlan.numDistricts);
    
    char timestamp[32];
    get_timestamp(timestamp, sizeof(timestamp));
    cJSON_AddStringToObject(root, "lastUpdated", timestamp);
    
    /* Create assignments object */
    cJSON* assignments = cJSON_CreateObject();
    for (int i = 0; i < app->precinctCount; i++) {
        if (app->precincts[i].district > 0) {
            char value[16];
            snprintf(value, sizeof(value), "%d", app->precincts[i].district);
            cJSON_AddNumberToObject(assignments, app->precincts[i].id, app->precincts[i].district);
        }
    }
    cJSON_AddItemToObject(root, "assignments", assignments);
    
    char* jsonStr = cJSON_Print(root);
    cJSON_Delete(root);
    
    return jsonStr;
}

/* Parse plan JSON and load assignments */
int parse_plan_json(AppState* app, const char* jsonStr) {
    cJSON* root = cJSON_Parse(jsonStr);
    if (!root) {
        fprintf(stderr, "Error parsing plan JSON\n");
        return 0;
    }
    
    cJSON* state = cJSON_GetObjectItem(root, "state");
    cJSON* planId = cJSON_GetObjectItem(root, "planId");
    cJSON* name = cJSON_GetObjectItem(root, "name");
    cJSON* numDist = cJSON_GetObjectItem(root, "numDistricts");
    cJSON* assignments = cJSON_GetObjectItem(root, "assignments");
    
    if (state && cJSON_IsString(state)) {
        strncpy(app->currentPlan.state, state->valuestring, sizeof(app->currentPlan.state) - 1);
    }
    
    if (planId && cJSON_IsString(planId)) {
        strncpy(app->currentPlan.planId, planId->valuestring, sizeof(app->currentPlan.planId) - 1);
    }
    
    if (name && cJSON_IsString(name)) {
        strncpy(app->currentPlan.name, name->valuestring, sizeof(app->currentPlan.name) - 1);
    }
    
    if (numDist && cJSON_IsNumber(numDist)) {
        app->currentPlan.numDistricts = numDist->valueint;
    }
    
    /* Reset all district assignments */
    for (int i = 0; i < app->precinctCount; i++) {
        app->precincts[i].district = 0;
    }
    
    /* Load assignments */
    if (assignments && cJSON_IsObject(assignments)) {
        cJSON* item = NULL;
        cJSON_ArrayForEach(item, assignments) {
            const char* precinctId = item->string;
            int districtId = 0;
            
            if (cJSON_IsNumber(item)) {
                districtId = item->valueint;
            }
            
            /* Find precinct by ID and assign district */
            for (int i = 0; i < app->precinctCount; i++) {
                if (strcmp(app->precincts[i].id, precinctId) == 0) {
                    app->precincts[i].district = districtId;
                    break;
                }
            }
        }
    }
    
    app->hasPlan = 1;
    cJSON_Delete(root);
    return 1;
}
