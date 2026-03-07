@echo off
REM Test script for validation (MIMA response)
echo ========================================
echo Testing SET validation with MIMA response
echo ========================================
echo.

SET BASE_URL=http://localhost:5333

echo Test 1: Valid RPD value (1-3) - should return OK
echo -------------------------------------------------
curl -s "%BASE_URL%/neosoft/set/RPD/1"
echo.
echo.
curl -s "%BASE_URL%/neosoft/set/RPD/2"
echo.
echo.
curl -s "%BASE_URL%/neosoft/set/RPD/3"
echo.
echo.

echo Test 2: Invalid RPD value (outside 1-3) - should return MIMA
echo --------------------------------------------------------------
curl -s "%BASE_URL%/neosoft/set/RPD/0"
echo.
echo.
curl -s "%BASE_URL%/neosoft/set/RPD/4"
echo.
echo.
curl -s "%BASE_URL%/neosoft/set/RPD/99"
echo.
echo.

echo Test 3: Valid value for other parameters - should return OK
echo -----------------------------------------------------------
curl -s "%BASE_URL%/neosoft/set/SIR/0"
echo.
echo.

echo Test 4: Verify response format (setKEYVALUE)
echo -------------------------------------------
echo Should be: {"setSIR0":"OK"}
curl -s "%BASE_URL%/neosoft/set/SIR/0"
echo.
echo.
echo Should be: {"setRPD5":"MIMA"}
curl -s "%BASE_URL%/neosoft/set/RPD/5"
echo.
echo.

echo ========================================
echo Tests complete
echo ========================================
pause
