# US Redistricting Tool - Makefile for Windows
# Cross-compile from Linux using MinGW-w64

# Compiler settings
CC = x86_64-w64-mingw32-gcc
CFLAGS = -Wall -Wextra -O2 -I./include -I./lib
LDFLAGS = -static -lm

# Source files
SRC_DIR = src
LIB_DIR = lib
INCLUDE_DIR = include

SOURCES = $(SRC_DIR)/main.c \
          $(SRC_DIR)/utils.c \
          $(SRC_DIR)/json_utils.c \
          $(SRC_DIR)/states.c \
          $(SRC_DIR)/plans.c \
          $(SRC_DIR)/metrics.c \
          $(SRC_DIR)/automap.c \
          $(SRC_DIR)/ui.c \
          $(LIB_DIR)/cJSON.c

# Output
TARGET = redistricting.exe

# Default target
all: $(TARGET)

# Build executable
$(TARGET): $(SOURCES)
	$(CC) $(CFLAGS) -o $(TARGET) $(SOURCES) $(LDFLAGS)
	@echo "Build complete: $(TARGET)"
	@echo "This is a Windows executable. Copy to Windows to run."

# Clean build files
clean:
	rm -f $(TARGET)

# Build for Linux (for testing)
linux:
	gcc -Wall -Wextra -O2 -I./include -I./lib -o redistricting_linux \
		$(SOURCES) -lm
	@echo "Linux build complete: redistricting_linux"

# Build with debug symbols
debug:
	$(CC) $(CFLAGS) -g -DDEBUG -o $(TARGET) $(SOURCES) $(LDFLAGS)
	@echo "Debug build complete: $(TARGET)"

# Show help
help:
	@echo "US Redistricting Tool - Build System"
	@echo ""
	@echo "Targets:"
	@echo "  all     - Build Windows executable (default)"
	@echo "  linux   - Build Linux executable for testing"
	@echo "  debug   - Build with debug symbols"
	@echo "  clean   - Remove build artifacts"
	@echo "  help    - Show this help message"
	@echo ""
	@echo "Requirements:"
	@echo "  - MinGW-w64 (for Windows cross-compilation)"
	@echo "  - GCC (for Linux build)"

.PHONY: all clean linux debug help
