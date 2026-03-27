#!/bin/bash
# Test script for GET single value functionality
echo "========================================"
echo "Testing GET Single Value with NSC response"
echo "========================================"
echo ""

BASE_URL="${BASE_URL:-http://localhost:5333}"

echo "Test 1: Get existing values - should return value"
echo "--------------------------------------------------"
echo "Getting AB (valve state):"
curl -s "$BASE_URL/neosoft/get/ab"
echo ""
echo ""

echo "Getting FLO (flow rate):"
curl -s "$BASE_URL/neosoft/get/flo"
echo ""
echo ""

echo "Getting SV1 (salt amount):"
curl -s "$BASE_URL/neosoft/get/sv1"
echo ""
echo ""

echo "Getting RPD (regeneration period):"
curl -s "$BASE_URL/neosoft/get/rpd"
echo ""
echo ""

echo "Test 2: Get non-existing values - should return NSC"
echo "----------------------------------------------------"
echo "Getting XYZ (invalid key):"
curl -s "$BASE_URL/neosoft/get/xyz"
echo ""
echo ""

echo "Getting INVALID (invalid key):"
curl -s "$BASE_URL/neosoft/get/invalid"
echo ""
echo ""

echo "Getting TEST123 (invalid key):"
curl -s "$BASE_URL/neosoft/get/test123"
echo ""
echo ""

echo "Test 3: Compare with /get/all"
echo "------------------------------"
echo "Getting all values (first 10 keys):"
curl -s "$BASE_URL/neosoft/get/all" | jq -r 'keys[0:10][]' 2>/dev/null || echo "(jq not installed)"
echo ""

echo "========================================"
echo "Tests complete"
echo "========================================"
echo ""
echo "Expected results:"
echo "- Existing keys return: {\"getKEY\": value}"
echo "- Non-existing keys return: {\"getKEY\": \"NSC\"}"
echo ""
