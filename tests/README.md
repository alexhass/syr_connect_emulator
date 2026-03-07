# SYR Connect Emulator - Test Scripts

This directory contains all test scripts for the SYR Connect Device Emulator.

## Test Scripts

### Main Test Suite

**test_api.py** (Python - Recommended)

- Comprehensive test suite using aiohttp
- Tests all endpoints and validation
- Includes automated assertions

**test_api.bat** (Windows)

- Basic API testing with curl
- Tests login, GET, and SET operations

**test_api.sh** (Linux/macOS)

- Same as test_api.bat but for Unix systems

### Specialized Tests

**test_get_single.bat / .sh**

- Tests GET single value endpoint
- Verifies NSC response for invalid keys

**test_validation.bat / .sh**

- Tests SET validation
- Verifies MIMA response for out-of-range values
- Tests RPD validation (1-3 range for neosoft)

## Running Tests

### Python Test Suite (Recommended)

```bash
cd tests/
pip install aiohttp
python test_api.py

# Test different device
python test_api.py http://localhost:5333 trio
```

### Windows

```cmd
cd tests
test_api.bat

REM Test validation
test_validation.bat

REM Test GET single value
test_get_single.bat
```

### Linux/macOS

```bash
cd tests/
chmod +x *.sh
./test_api.sh

# Test validation
./test_validation.sh

# Test GET single value
./test_get_single.sh
```

## What Gets Tested

- ✓ **Login endpoint** (`/set/ADM/(2)f`)
- ✓ **GET all values** (`/get/all`)
- ✓ **GET single values** (`/get/{key}`)
  - Valid keys return value
  - Invalid keys return NSC
- ✓ **SET operations** (`/set/{key}/{value}`)
  - Boolean, string, integer, float values
  - Response format: `{"setKEYVALUE": "OK"}`
- ✓ **Validation**
  - RPD range validation (1-3 for neosoft)
  - MIMA response for invalid ranges
- ✓ **Error handling**
- ✓ **State verification**

## Expected Responses

### Successful SET

```json
{"setSIR0": "OK"}
```

### Validation Error (out of range)

```json
{"setRPD5": "MIMA"}
```

### Invalid Key

```json
{"getXYZ": "NSC"}
```

## Notes

- All tests log operations to `set_operations.log` in the main directory
- Tests require the emulator to be running on port 5333
- Default device is `neosoft`, can be changed via parameters
