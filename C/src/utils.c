/*
 * US Redistricting Tool - Utility Functions
 */

#include "../include/maps.h"

#ifdef _WIN32
#include <io.h>
#define F_OK 0
#define access _access
#else
#include <unistd.h>
#endif

int ensure_directory(const char* path) {
    if (file_exists(path)) {
        return 1;
    }
#ifdef _WIN32
    return _mkdir(path) == 0;
#else
    return mkdir(path, 0775) == 0;
#endif
}

int file_exists(const char* path) {
    return access(path, F_OK) == 0;
}

char* trim_string(char* str) {
    char* end;
    
    /* Trim leading space */
    while (*str == ' ' || *str == '\t' || *str == '\n' || *str == '\r') {
        str++;
    }
    
    if (*str == 0) {
        return str;
    }
    
    /* Trim trailing space */
    end = str + strlen(str) - 1;
    while (end > str && (*end == ' ' || *end == '\t' || *end == '\n' || *end == '\r')) {
        end--;
    }
    
    end[1] = '\0';
    return str;
}

void get_timestamp(char* buffer, size_t size) {
    time_t now = time(NULL);
    struct tm* tm_info = localtime(&now);
    strftime(buffer, size, "%Y-%m-%dT%H:%M:%S", tm_info);
}

int parse_int(const char* str, int defaultVal) {
    if (str == NULL || *str == '\0') {
        return defaultVal;
    }
    char* endptr;
    long val = strtol(str, &endptr, 10);
    if (endptr == str) {
        return defaultVal;
    }
    return (int)val;
}

/* Read entire file into string */
char* read_file(const char* path) {
    FILE* file = fopen(path, "rb");
    if (!file) {
        return NULL;
    }
    
    fseek(file, 0, SEEK_END);
    long length = ftell(file);
    fseek(file, 0, SEEK_SET);
    
    char* buffer = (char*)malloc(length + 1);
    if (!buffer) {
        fclose(file);
        return NULL;
    }
    
    size_t read = fread(buffer, 1, length, file);
    buffer[read] = '\0';
    
    fclose(file);
    return buffer;
}

/* Write string to file */
int write_file(const char* path, const char* content) {
    FILE* file = fopen(path, "wb");
    if (!file) {
        return 0;
    }
    
    size_t len = strlen(content);
    size_t written = fwrite(content, 1, len, file);
    
    fclose(file);
    return written == len;
}
