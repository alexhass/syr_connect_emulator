@echo off
rem Tests for example requests (Windows batch)
setlocal enabledelayedexpansion

if "%BASE_URL%"=="" set "BASE_URL=http://localhost:5333"
if "%DEVICE%"=="" set "DEVICE=neosoft"

set "LOGFILE=%~dp0examples.log"
set "FAIL_DIR=%~dp0failures"
if not exist "%FAIL_DIR%" mkdir "%FAIL_DIR%"

echo Logging to: %LOGFILE%
echo Running example API tests against %BASE_URL%/%DEVICE%

rem Create tests list in a temp file
set "TMPTESTS=%TEMP%\syr_examples_tests.txt"
break>"%TMPTESTS%"
echo /set/pa3/true^|setPA3^|true>>"%TMPTESTS%"
echo /set/pv1/500^|setPV1^|500>>"%TMPTESTS%"
echo /set/prf/2^|setPRF^|2>>"%TMPTESTS%"
echo /get/prf^|getPRF^|2>>"%TMPTESTS%"
echo /set/dtt/04%%3A00^|setDTT^|"04:00"^>>"%TMPTESTS%"
echo /set/rmo/4^|setRMO4^|true>>"%TMPTESTS%"
echo /set/rpd/2^|setRPD2^|true>>"%TMPTESTS%"
echo /set/rpd/99^|setRPD99^|"MIMA">>"%TMPTESTS%"
echo /get/iwh^|getIWH^|INT>>"%TMPTESTS%"
echo /get/srh^|getSRH^|DATE>>"%TMPTESTS%"
echo /get/alm^|getALM^|HEXLIST>>"%TMPTESTS%"
echo /get/xyz^|getXYZ^|"NSC">>"%TMPTESTS%"
echo /set/ala/255^|setALA255^|"OK">>"%TMPTESTS%"
echo /get/ala^|getALA^|"ff">>"%TMPTESTS%"
echo /set/ab/true^|setAB^|true>>"%TMPTESTS%"
echo /get/vlv^|getVLV^|VLVSET>>"%TMPTESTS%"
echo /set/slp/7^|setSLP7^|"OK">>"%TMPTESTS%"

set PASSED=0
set FAILED=0
set IDX=0

for /f "usebackq delims=" %%L in ("%TMPTESTS%") do (
    set /a IDX+=1
    for /f "tokens=1,2,3 delims=|" %%A in ("%%L") do (
        set "PATH=%%~A"
        set "KEY=%%~B"
        set "EXPECTED=%%~C"
        set "URL=%BASE_URL%/%DEVICE%!PATH!"
        echo Test: !PATH! ...

        set "BODYFILE=%TEMP%\syr_examples_body_!IDX!.json"
        rem Call PowerShell: save full JSON to BODYFILE and output JSON representation of the key value
        for /f "usebackq delims=" %%R in (`powershell -NoProfile -Command "try { $r = Invoke-RestMethod -Uri '%URL%' -UseBasicParsing -ErrorAction Stop; $val = $r.%KEY%; $r | ConvertTo-Json -Compress | Out-File -Encoding utf8 '%BODYFILE%'; Write-Output ($val | ConvertTo-Json -Compress) } catch { Write-Output '___REQUEST_FAILED___' }"`) do set "ACTUAL_JSON=%%R"

        rem Read body content (single-line compressed JSON)
        set "BODY_CONTENT="
        for /f "usebackq delims=" %%B in ("%BODYFILE%") do (if not defined BODY_CONTENT set "BODY_CONTENT=%%B")

        rem Log entry
        >>"%LOGFILE%" echo ---
        >>"%LOGFILE%" echo time: %DATE% %TIME%
        >>"%LOGFILE%" echo url: %URL%
        >>"%LOGFILE%" echo key: !KEY!
        >>"%LOGFILE%" echo expected: !EXPECTED!
        >>"%LOGFILE%" echo actual: !ACTUAL_JSON!
        >>"%LOGFILE%" echo body: !BODY_CONTENT!

        set "PASS=false"
        rem Remove surrounding quotes for raw value
        set "RAW=!ACTUAL_JSON!"
        if "!RAW:~0,1!"==""" (
            set "RAW_UNQUOTED=!RAW:~1,-1!"
        ) else (
            set "RAW_UNQUOTED=!RAW!"
        )

        rem Validation using PowerShell regex checks for special tokens
        if /I "!EXPECTED!"=="DATE" (
            powershell -NoProfile -Command "if ([regex]::IsMatch('%RAW_UNQUOTED%','^[0-9]{2}\.[0-9]{2}\.[0-9]{4}$')) { exit 0 } else { exit 1 }"
            if !ERRORLEVEL! EQU 0 set "PASS=true"
        ) else if /I "!EXPECTED!"=="INT" (
            powershell -NoProfile -Command "if ([regex]::IsMatch('%RAW_UNQUOTED%','^-?[0-9]+$')) { exit 0 } else { exit 1 }"
            if !ERRORLEVEL! EQU 0 set "PASS=true"
        ) else if /I "!EXPECTED!"=="HEXLIST" (
            powershell -NoProfile -Command "if ([regex]::IsMatch('%RAW_UNQUOTED%','^([0-9a-fA-F]{2})(,[0-9a-fA-F]{2}){0,7}$')) { exit 0 } else { exit 1 }"
            if !ERRORLEVEL! EQU 0 set "PASS=true"
        ) else if /I "!EXPECTED!"=="VLVSET" (
            powershell -NoProfile -Command "if ([regex]::IsMatch('%RAW_UNQUOTED%','^(10|11|20|21)$')) { exit 0 } else { exit 1 }"
            if !ERRORLEVEL! EQU 0 set "PASS=true"
        ) else (
            rem Direct compare of JSON representation
            if "!ACTUAL_JSON!"=="!EXPECTED!" set "PASS=true"
        )

        if "!PASS!"=="true" (
            echo PASS
            set /a PASSED+=1
        ) else (
            echo FAIL
            set /a FAILED+=1
            set "FAILFILE=%FAIL_DIR%\example_fail_!IDX!.json"
            copy /Y "%BODYFILE%" "!FAILFILE!" >nul
            >>"%LOGFILE%" echo FAILURE: !PATH!
            >>"%LOGFILE%" echo Saved response to: !FAILFILE!
            echo   URL: %URL%
            echo   Response saved: !FAILFILE!
            echo   Expected: !KEY! -> !EXPECTED!
            echo   Actual:   !KEY! -> !ACTUAL_JSON!
        )
    )
)

echo.
echo Summary: Passed=%PASSED% Failed=%FAILED%
if %FAILED% NEQ 0 exit /b 2
