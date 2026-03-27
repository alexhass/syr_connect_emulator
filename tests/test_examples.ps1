#!/usr/bin/env pwsh
# PowerShell port of tests/test_examples.sh
param(
    [string]$BaseUrl = $env:BASE_URL,
    [string]$Device = $env:DEVICE
)

# Ensure defaults when environment variables are not set (avoid using -or which returns boolean)
if (-not $BaseUrl) { $BaseUrl = 'http://localhost:5333' }
if (-not $Device)  { $Device  = 'neosoft' }

Set-StrictMode -Version Latest
$LogFile = Join-Path $PSScriptRoot 'examples.log'

# Device label is used only for log naming; the script is self-contained and does not require
# any external device fixture files. All example requests and expected tokens are embedded below.
Write-Host "Logging to: $LogFile"
Write-Host "Running example API tests against $BaseUrl/$Device"

# Embedded example tests (self-contained)
# Device-specific arrays: edit these lists if you need to add/remove per device
$tests_neosoft = @(
    '/get/ala|getALA|"ff"'
    '/set/ala/255|setALA255|"OK"'
    '/set/ala/ff|setALAff|"OK"'
    '/set/ala/FF|setALAFF|"OK"'
    '/set/dtt/04%3A00|setDTT|"04:00"'
    '/set/rmo/1|setRMO4|true'
    '/set/rmo/4|setRMO4|true'
    '/set/rpd/99|setRPD99|"MIMA"'
    '/set/rpd/2|setRPD2|true'
    '/set/rpd/3|setRPD3|true'
    '/set/slp/7|setSLP7|"OK"'
    '/set/slp/0|setSLP0|"OK"'
)

$tests_trio = @(
    '/set/ab/true|setAB|true'
    '/set/ab/false|setAB|false'
    '/get/alm|getALM|HEXLIST'
    '/get/iwh|getIWH|INT'
    '/set/pa1/true|setPA1|true'
    '/set/pa2/true|setPA2|true'
    '/set/pa3/true|setPA3|true'
    '/set/pa4/false|setPA4|false'
    '/get/prf|getPRF|2'
    '/set/prf/2|getPRF|2'
    '/set/pv1/500|setPV1|500'
    '/get/srh|getSRH|DATE'
    '/get/vlv|getVLV|VLVSET'
    '/get/xyz|getXYZ|"NSC"'
)

# Select tests based on device name (case-insensitive). If unknown, run all tests.
switch ($Device.ToLower()) {
    'neosoft' { $filteredTests = $tests_neosoft }
    'trio'    { $filteredTests = $tests_trio }
    default   { $filteredTests = $tests_neosoft + $tests_trio }
}

$passed = 0; $failed = 0; $idx = 0
foreach ($t in $filteredTests) {
    $idx++
    $parts = $t -split '\|',3
    $path = $parts[0]
    $key = $parts[1]
    $expected = $parts[2]
    $url = "$BaseUrl/$Device$path"
    Write-Host -NoNewline "Test: $path ... "

    try {
        $resp = Invoke-WebRequest -Uri $url -UseBasicParsing -ErrorAction Stop
        $status = $resp.StatusCode
        $bodyRaw = $resp.Content
        if ($bodyRaw -is [byte[]]) { $bodyString = [System.Text.Encoding]::UTF8.GetString($bodyRaw) } else { $bodyString = $bodyRaw }
        try { $json = $bodyString | ConvertFrom-Json -ErrorAction Stop } catch { $json = $null }
    } catch {
        $status = 0
        $bodyString = ''
        $bodyRaw = $null
        $json = $null
    }

    # extract actual value
    if ($json -ne $null -and $json.PSObject.Properties.Name -contains $key) {
        $actual = $json.$key
    } else {
        $actual = $null
    }

    # canonical JSON representation of actual value
    if ($actual -ne $null) {
        try { $actualJson = $actual | ConvertTo-Json -Compress -ErrorAction Stop } catch { $actualJson = 'null' }
    } else { $actualJson = 'null' }

    # raw unquoted for pattern checks
    if ($actual -is [string]) { $rawActual = $actual } elseif ($actual -ne $null) { $rawActual = $actual.ToString() } else { $rawActual = '' }

    $timestamp = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    # build expected_body (if special token, log token; otherwise build JSON with the key)
    if ($expected -in @('DATE','INT','HEXLIST','VLVSET')) {
        $expectedBody = "<$expected>"
    } else {
        $expectedBody = "{`"$key`":$expected}"
    }

    # log
    @(
        '---'
        "time: $timestamp"
        "url: $url"
        "http_code: $status"
        "key: $key"
        "expected: $expected"
        "actual: $actualJson"
        "expected_body: $expectedBody"
        'response_body:'
        $bodyString
    ) | Out-File -FilePath $LogFile -Append -Encoding utf8

    # responses are recorded in the log; no separate response files written

    $pass = $false
    switch ($expected) {
        'DATE' {
            if ($rawActual -match '^[0-9]{2}\.[0-9]{2}\.[0-9]{4}$') { $pass = $true }
        }
        'INT' {
            if ($rawActual -match '^-?[0-9]+$') { $pass = $true }
        }
        'HEXLIST' {
            if ($rawActual -match '^([0-9a-fA-F]{2})(,[0-9a-fA-F]{2}){0,7}$') { $pass = $true }
        }
        'VLVSET' {
            if ($rawActual -match '^(10|11|20|21)$') { $pass = $true }
        }
        default {
            # direct comparison of canonical JSON string
            if ($actualJson -eq $expected) { $pass = $true }
        }
    }

    if ($pass) {
        Write-Host 'PASS'
        $passed++
    } else {
        Write-Host 'FAIL'
        $failed++
        # Response body already logged; no separate failure file created
        @(
            "FAILURE: $path"
        ) | Out-File -FilePath $LogFile -Append -Encoding utf8
        Write-Host "  URL: $url"
        Write-Host "  Expected: $key -> $expected"
        Write-Host "  Actual:   $key -> $actualJson"
    }
}

Write-Host "`nSummary: Passed=$passed Failed=$failed"
if ($failed -ne 0) { exit 2 } else { exit 0 }
