@echo off
REM SYR Device Emulator - Test Script (Windows)
REM Tests all API endpoints using curl

setlocal enabledelayedexpansion

REM Configuration
if "%BASE_URL%"=="" set BASE_URL=http://localhost:5333
if "%DEVICE%"=="" set DEVICE=neosoft
if "%CONFIG%"=="" set CONFIG=

echo ==========================================
echo SYR Device Emulator - API Test
echo ==========================================
echo Base URL: %BASE_URL%
echo Device:   %DEVICE%
echo.

set TESTS_PASSED=0
set TESTS_FAILED=0

echo ==========================================
echo 1. Testing Login Endpoint
echo ==========================================
echo Testing: Login ...
set ADM_EXPECT=404
echo DEVICE=%DEVICE% CONFIG=%CONFIG%
if /I "%DEVICE%"=="pontos-base" (
	set ADM_EXPECT=200
) else (
	echo %CONFIG% | findstr /R /C:"safetech_v4" >nul && (
		if /I "%DEVICE:~0,4%"=="trio" set ADM_EXPECT=200
	)
)
curl -s -w "%%{http_code}" "%BASE_URL%/%DEVICE%/set/ADM/(2)f" > test_response.tmp
type test_response.tmp
echo Expected ADM HTTP: %ADM_EXPECT%
echo.

echo ==========================================
echo 2. Testing GET All Values
echo ==========================================
echo Testing: GET all values ...
curl -s "%BASE_URL%/%DEVICE%/get/all" > test_response.tmp
type test_response.tmp
echo.

echo ==========================================
echo 3. Testing SET Operations
echo ==========================================

echo Testing: SET AB (valve) to true ...
curl -s "%BASE_URL%/%DEVICE%/set/AB/true" > test_response.tmp
type test_response.tmp
echo.
echo.

echo Testing: SET RTM (regen time) to 03:30 ...
curl -s "%BASE_URL%/%DEVICE%/set/RTM/03%%3A30" > test_response.tmp
type test_response.tmp
echo.
echo.

echo Testing: SET SV1 (salt) to 25 ...
curl -s "%BASE_URL%/%DEVICE%/set/SV1/25" > test_response.tmp
type test_response.tmp
echo.
echo.

echo Testing: SET RPD (interval) to 3 ...
curl -s "%BASE_URL%/%DEVICE%/set/RPD/3" > test_response.tmp
type test_response.tmp
echo.
echo.

echo ==========================================
echo 4. Testing Error Cases
echo ==========================================

echo Testing: SET invalid key ...
curl -s "%BASE_URL%/%DEVICE%/set/INVALID/123" > test_response.tmp
type test_response.tmp
echo.
echo.

echo Testing: Invalid device prefix ...
curl -s "%BASE_URL%/invalid/get/all" > test_response.tmp
type test_response.tmp
echo.
echo.

echo ==========================================
echo 5. Verifying State Changes
echo ==========================================
echo Fetching current values to verify changes...
curl -s "%BASE_URL%/%DEVICE%/get/all" > test_response.tmp
type test_response.tmp
echo.
echo.

REM Cleanup
del test_response.tmp 2>nul

echo ==========================================
echo Tests completed!
echo ==========================================
echo Check set_operations.log for detailed logs
echo.

pause
