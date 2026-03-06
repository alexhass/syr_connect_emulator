#!/bin/bash
# SYR Device Emulator - Test Script
# Tests all API endpoints

set -e

# Configuration
BASE_URL="${BASE_URL:-http://localhost:5333}"
DEVICE="${DEVICE:-neosoft}"

echo "=========================================="
echo "SYR Device Emulator - API Test"
echo "=========================================="
echo "Base URL: $BASE_URL"
echo "Device:   $DEVICE"
echo ""

# Color codes
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Test counter
TESTS_PASSED=0
TESTS_FAILED=0

# Function to test endpoint
test_endpoint() {
    local name="$1"
    local url="$2"
    local expected_status="${3:-200}"
    
    echo -n "Testing: $name ... "
    
    response=$(curl -s -w "\n%{http_code}" "$url" 2>/dev/null)
    http_code=$(echo "$response" | tail -n 1)
    body=$(echo "$response" | head -n -1)
    
    if [ "$http_code" == "$expected_status" ]; then
        echo -e "${GREEN}✓ PASS${NC} (HTTP $http_code)"
        TESTS_PASSED=$((TESTS_PASSED + 1))
        
        # Show response (truncated)
        if [ -n "$body" ]; then
            echo "$body" | jq -C '.' 2>/dev/null | head -n 5 || echo "$body" | head -c 200
            echo ""
        fi
    else
        echo -e "${RED}✗ FAIL${NC} (Expected HTTP $expected_status, got $http_code)"
        TESTS_FAILED=$((TESTS_FAILED + 1))
        echo "Response: $body"
    fi
    echo ""
}

echo "=========================================="
echo "1. Testing Login Endpoint"
echo "=========================================="
test_endpoint "Login" "$BASE_URL/$DEVICE/set/ADM/(2)f" 200

echo "=========================================="
echo "2. Testing GET All Values"
echo "=========================================="
test_endpoint "GET all values" "$BASE_URL/$DEVICE/get/all" 200

echo "=========================================="
echo "3. Testing SET Operations"
echo "=========================================="

# Test SET with boolean
test_endpoint "SET AB (valve) to true" "$BASE_URL/$DEVICE/set/AB/true" 200

# Test SET with string
test_endpoint "SET RTM (regen time) to 03:30" "$BASE_URL/$DEVICE/set/RTM/03%3A30" 200

# Test SET with integer
test_endpoint "SET SV1 (salt) to 25" "$BASE_URL/$DEVICE/set/SV1/25" 200

# Test SET with float
test_endpoint "SET RPD (interval) to 3" "$BASE_URL/$DEVICE/set/RPD/3" 200

echo "=========================================="
echo "4. Testing Error Cases"
echo "=========================================="

# Test invalid key
test_endpoint "SET invalid key" "$BASE_URL/$DEVICE/set/INVALID/123" 400

# Test invalid device prefix
test_endpoint "Invalid device prefix" "$BASE_URL/invalid/get/all" 400

echo "=========================================="
echo "5. Verifying State Changes"
echo "=========================================="

# GET again to verify changes
echo "Fetching current values to verify changes..."
curl -s "$BASE_URL/$DEVICE/get/all" | jq '{
    getAB: .getAB,
    getRTM: .getRTM,
    getSV1: .getSV1,
    getRPD: .getRPD
}' 2>/dev/null || echo "jq not installed, skipping verification"
echo ""

echo "=========================================="
echo "Test Summary"
echo "=========================================="
echo -e "${GREEN}Passed: $TESTS_PASSED${NC}"
echo -e "${RED}Failed: $TESTS_FAILED${NC}"
echo ""

if [ $TESTS_FAILED -eq 0 ]; then
    echo -e "${GREEN}All tests passed! ✓${NC}"
    exit 0
else
    echo -e "${RED}Some tests failed! ✗${NC}"
    exit 1
fi
