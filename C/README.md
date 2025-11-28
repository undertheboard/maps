# US Redistricting Tool - Windows Console Application

A full-featured redistricting software written in C for Windows. This console application provides comprehensive tools for creating, analyzing, and optimizing congressional and legislative district maps.

## Features

### Core Functionality
- **State Management**: Load precinct data for any US state
- **Plan Management**: Create, save, load, and manage redistricting plans
- **District Assignment**: Manual and automatic precinct-to-district assignment
- **Metrics Calculation**: Population balance, partisan lean, efficiency gap, compactness

### Auto-Generation (Automap)
Generate districts automatically based on fairness goals:
- **Very Republican**: Target 60% Republican / 40% Democratic
- **Lean Republican**: Target 54% Republican / 46% Democratic
- **Fair/Competitive**: Target 50-50 balance
- **Lean Democratic**: Target 54% Democratic / 46% Republican
- **Very Democratic**: Target 60% Democratic / 40% Republican
- **Custom**: Specify your own target percentage

### Metrics & Analysis
- **Population Deviation**: Measures how evenly distributed population is across districts
- **Partisan Lean**: Democratic vote share for each district
- **Efficiency Gap**: Wasted votes analysis (positive favors Republicans)
- **Compactness Score**: Polsby-Popper measure (1.0 = perfect circle)
- **County Splits**: Number of counties divided across districts

## Building

### Prerequisites
- MinGW-w64 (for cross-compiling from Linux)
- Or Visual Studio / MinGW on Windows

### Cross-Compile from Linux
```bash
cd C
make
```

This produces `redistricting.exe` which can be copied to Windows.

### Build on Windows (MinGW)
```bash
cd C
gcc -Wall -O2 -I./include -I./lib -o redistricting.exe src/*.c lib/cJSON.c -lm
```

### Build on Linux (for testing)
```bash
cd C
make linux
./redistricting_linux
```

## Data Format

### Directory Structure
```
data/
├── states.json              # State metadata
├── precincts/
│   ├── NC/
│   │   └── precincts.geojson
│   ├── CA/
│   │   └── precincts.geojson
│   └── ...
└── plans/
    ├── NC/
    │   ├── plan_123456.json
    │   └── ...
    └── ...
```

### states.json Format
```json
[
  {
    "code": "37",
    "abbr": "NC",
    "name": "North Carolina",
    "defaultNumDistricts": 14
  }
]
```

### precincts.geojson Format
Standard GeoJSON FeatureCollection with the following properties:
```json
{
  "type": "FeatureCollection",
  "features": [
    {
      "type": "Feature",
      "properties": {
        "id": "precinct_001",
        "population": 5000,
        "dem": 2500,
        "rep": 2300,
        "county": "Wake"
      },
      "geometry": {
        "type": "Polygon",
        "coordinates": [...]
      }
    }
  ]
}
```

#### Supported Property Names
| Property | Alternatives |
|----------|-------------|
| id | precinct_id, GEOID20, UNIQUE_ID |
| population | TOTPOP, POP100 |
| dem | dem_votes, G20PREDBID |
| rep | rep_votes, G20PRERTRU |
| county | COUNTY, COUNTYFP, COUNTYFP20 |

## Usage

Run the executable:
```
redistricting.exe
```

### Main Menu Options
1. **List Available States** - Show all states with precinct data
2. **Load State Data** - Load precincts for a specific state
3. **State Management Menu** - View state details and statistics
4. **Plan Management Menu** - Create/save/load redistricting plans
5. **District Settings** - Configure number of districts
6. **Auto-Generate Districts** - Use automap algorithm
7. **View Metrics** - Display comprehensive plan metrics
8. **Manual Precinct Assignment** - Assign precincts individually
9. **Help / About** - Show documentation

### Automap Algorithm

The automap algorithm generates districts using a three-phase approach:

1. **Phase 1: County Assignment**
   - Groups precincts by county
   - Assigns whole counties to districts when possible
   - Respects population balance constraints

2. **Phase 2: Precinct Assignment**
   - Assigns remaining precincts to best-fit districts
   - Considers population balance, partisan target, county cohesion
   - Prioritizes adjacency for contiguity

3. **Phase 3: Optimization**
   - Swaps border precincts to improve fairness score
   - Iterates until no improvement possible

## Example Session

```
1. Load state: NC (North Carolina)
2. Create new plan: "My NC Plan"
3. Set districts: 14
4. Run automap with "Fair" preset
5. View metrics
6. Save plan
```

## Technical Notes

### Dependencies
- **cJSON** (included): JSON parsing library by Dave Gamble
- **Standard C Library**: stdio, stdlib, string, math, time

### Memory Limits
- Maximum states: 60
- Maximum precincts: 50,000
- Maximum districts: 100
- Maximum plans: 100

### Platform Support
- **Windows**: Native console application (primary target)
- **Linux**: Can be built for testing purposes

## License

This software is provided for educational and research purposes.

## Credits

- JSON parsing: [cJSON](https://github.com/DaveGamble/cJSON) by Dave Gamble (MIT License)
