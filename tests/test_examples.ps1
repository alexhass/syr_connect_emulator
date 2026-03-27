#!/usr/bin/env pwsh
# PowerShell port of tests/test_examples.sh
param(
    [string]$BaseUrl = $env:BASE_URL -or 'http://localhost:5333',
    [string]$Device = $env:DEVICE -or 'neosoft'
)

Set-StrictMode -Version Latest

$LogFile = Join-Path $PSScriptRoot 'examples.log'
$FailDir = Join-Path $PSScriptRoot 'failures'
If (-not (Test-Path $FailDir)) { New-Item -ItemType Directory -Path $FailDir | Out-Null }

Write-Host "Logging to: $LogFile"
Write-Host "Running example API tests against $BaseUrl/$Device"

$tests = @(
    '/set/pa3/true|setPA3|true'
    '/set/pv1/500|setPV1|500'
    '/set/prf/2|getPRF|2'
    '/get/prf|getPRF|2'
    '/set/dtt/04%3A00|setDTT|"04:00"'
    '/set/rmo/4|setRMO4|true'
    '/set/rpd/2|setRPD2|true'
    '/set/rpd/99|setRPD99|"MIMA"'
    '/get/iwh|getIWH|INT'
    '/get/srh|getSRH|DATE'
    '/get/alm|getALM|HEXLIST'
    '/get/xyz|getXYZ|"NSC"'
    '/set/ala/255|setALA255|"OK"'
    '/get/ala|getALA|"ff"'
    '/set/ab/true|setAB|true'
    '/get/vlv|getVLV|VLVSET'
    '/set/slp/7|setSLP7|"OK"'
)

$passed = 0; $failed = 0; $idx = 0
foreach ($t in $tests) {
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
        $body = $resp.Content
        try { $json = $body | ConvertFrom-Json -ErrorAction Stop } catch { $json = $null }
    } catch {
        $status = 0
        $body = ''
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
    # log
    @(
        '---'
        "time: $timestamp"
        "url: $url"
        "http_code: $status"
        "key: $key"
        "expected: $expected"
        "actual: $actualJson"
        'body:'
        $body
    ) | Out-File -FilePath $LogFile -Append -Encoding utf8

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
        $failFile = Join-Path $FailDir "example_fail_$idx.json"
        $body | Out-File -FilePath $failFile -Encoding utf8
        @(
            "FAILURE: $path"
            "Saved response to: $failFile"
        ) | Out-File -FilePath $LogFile -Append -Encoding utf8
        Write-Host "  URL: $url"
        Write-Host "  Response saved: $failFile"
        Write-Host "  Expected: $key -> $expected"
        Write-Host "  Actual:   $key -> $actualJson"
    }
}

Write-Host "`nSummary: Passed=$passed Failed=$failed"
if ($failed -ne 0) { exit 2 } else { exit 0 }
