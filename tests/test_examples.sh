#!/usr/bin/env bash
# Tests for example requests extracted from https://iotsyrpublicapi.z1.web.core.windows.net/
# Each test issues the documented GET and checks the JSON response matches the documented example.

set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:5333}"
DEVICE="${DEVICE:-neosoft}"

# Logging
LOGFILE="${LOGFILE:-$(dirname "$0")/examples.log}"
FAIL_DIR="$(dirname "$0")/failures"
mkdir -p "$(dirname "$LOGFILE")" "$FAIL_DIR"

echo "Logging to: $LOGFILE"

echo "Running example API tests against $BASE_URL/$DEVICE"

declare -a TESTS
# Format: PATH|JSON_KEY|EXPECTED_JSON_VALUE
TESTS+=( "/set/pa3/true|setPA3|true" )
TESTS+=( "/set/pv1/500|setPV1|500" )
TESTS+=( "/set/prf/2|setPRF|2" )
TESTS+=( "/get/prf|getPRF|2" )
TESTS+=( "/set/dtt/04%3A00|setDTT|\"04:00\"" )
TESTS+=( "/set/rmo/4|setRMO4|true" )
TESTS+=( "/set/rpd/2|setRPD2|true" )
TESTS+=( "/set/rpd/99|setRPD99|\"MIMA\"" )
TESTS+=( "/get/iwh|getIWH|INT" )
TESTS+=( "/get/srh|getSRH|DATE" )
TESTS+=( "/get/alm|getALM|HEXLIST" )
TESTS+=( "/get/xyz|getXYZ|\"NSC\"" )
TESTS+=( "/set/ala/255|setALA255|\"OK\"" )
TESTS+=( "/set/ala/ff|setALAff|\"OK\"" )
TESTS+=( "/set/ab/true|setAB|true" )
TESTS+=( "/get/vlv|getVLV|VLVSET" )
TESTS+=( "/set/slp/7|setSLP7|\"OK\"" )

PASSED=0
FAILED=0

test_idx=0
for t in "${TESTS[@]}"; do
    test_idx=$((test_idx+1))
    IFS='|' read -r path key expected <<< "$t"
    url="$BASE_URL/$DEVICE$path"
    echo -n "Test: $path ... "

    # Perform request, capture body and HTTP status
    resp=$(curl -s -w "\n%{http_code}" "$url" || echo -e "\n000")
    http_code=$(printf '%s' "$resp" | tail -n1)
    body=$(printf '%s' "$resp" | sed '$d')

    timestamp=$(date -u +"%Y-%m-%dT%H:%M:%SZ")
    # Normalize actual JSON value (compact)
    actual_json=$(printf '%s' "$body" | jq -c --arg k "$key" '.[$k]' 2>/dev/null || echo null)
    # Also get raw (unquoted) value for special checks (dates etc.)
    raw_actual=$(printf '%s' "$body" | jq -r --arg k "$key" '.[$k]' 2>/dev/null || echo "")

    # Log the request and response
    {
        echo "---"
        echo "time: $timestamp"
        echo "url: $url"
        echo "http_code: $http_code"
        echo "key: $key"
        echo "expected: $expected"
        echo "actual: $actual_json"
        echo "body:"
        echo "$body"
    } >> "$LOGFILE"

    pass=false
    if [ "$expected" = "DATE" ]; then
        # accept any date in format DD.MM.YYYY
        if [[ "$raw_actual" =~ ^[0-9]{2}\.[0-9]{2}\.[0-9]{4}$ ]]; then
            pass=true
        fi
    elif [ "$expected" = "INT" ]; then
        # accept any integer (positive/negative)
        if [[ "$raw_actual" =~ ^-?[0-9]+$ ]]; then
            pass=true
        fi
    elif [ "$expected" = "HEXLIST" ]; then
        # accept 1-8 comma-separated hex byte values (e.g. ff,ab,01)
        if [[ "$raw_actual" =~ ^([0-9a-fA-F]{2})(,[0-9a-fA-F]{2}){0,7}$ ]]; then
            pass=true
        fi
    elif [ "$expected" = "VLVSET" ]; then
        # accept only 10,11,20,21
        if [[ "$raw_actual" =~ ^(10|11|20|21)$ ]]; then
            pass=true
        fi
    else
        if [ "$actual_json" = "$expected" ]; then
            pass=true
        fi
    fi

    if [ "$pass" = true ]; then
        echo "PASS"
        PASSED=$((PASSED+1))
    else
        echo "FAIL"
        FAILED=$((FAILED+1))
        # write failing response to separate file for easier inspection
        failfile="$FAIL_DIR/example_fail_${test_idx}.json"
        printf '%s' "$body" > "$failfile"
        {
            echo "FAILURE: $path"
            echo "Saved response to: $failfile"
        } >> "$LOGFILE"
        echo "  URL: $url"
        echo "  Response saved: $failfile"
        echo "  Expected: $key -> $expected"
        echo "  Actual:   $key -> $actual_json"
    fi
done

echo "\nSummary: Passed=$PASSED Failed=$FAILED"
if [ $FAILED -ne 0 ]; then
    exit 2
fi
