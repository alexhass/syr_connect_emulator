<?php
/**
 * SYR Connect Device Emulator - Local JSON API Endpoint
 * 
 * Emulates SYR water treatment devices (Neosoft, Trio) for testing the local JSON API.
 * This emulator supports GET and SET operations via JSON/HTTP requests.
 * 
 * Note: This is for LOCAL JSON API only, not for XML API.
 * 
 * URL Structure:
 *   /{device}/set/ADM/(2)f       - Login (required before /get/all)
 *   /{device}/get/all            - Get all device values (JSON)
 *   /{device}/get/{key}          - Get single device value (returns NSC if not found)
 *   /{device}/set/{key}/{value}  - Set a device value
 * 
 * Device types: neosoft, trio
 */

// Aggressive header suppression to match real device behavior
// Real device only sends: HTTP/1.1 200 OK + content-length
ini_set('default_mimetype', '');
ini_set('expose_php', 'off');

// Remove all default headers before any output
header_remove();

// Prevent Apache from adding headers via PHP
header('X-Remove-Headers: true');


require_once __DIR__ . '/DeviceEmulator.php';

/**
 * Send a JSON response and terminate.
 * Mirrors real SYR device behavior: content-length reflects the JSON body
 * length; the \r\n\r\n terminator is appended but not counted.
 *
 * @param int    $code    HTTP status code
 * @param string $json    Pre-encoded JSON string
 * @param array  $headers Additional headers, e.g. ['X-Emulator-Config: foo']
 */
function send_json_response(int $code, string $json, array $headers = []): never
{
    http_response_code($code);
    header_remove();
    foreach ($headers as $h) {
        header($h, true);
    }
    header('content-length: ' . strlen($json));
    echo $json . "\r\n\r\n";
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

/** Send an empty (no-body) response and terminate. */
function send_empty_response(int $code): never
{
    http_response_code($code);
    header_remove();
    header('content-length: 0', true);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// Prefer the _dp query parameter injected by mod_rewrite in .htaccess.
// Apache URL-encodes special characters in query-string values (e.g. ':' in
// time values like '2:00' becomes '%3A00'), so Windows path normalization never
// gets a chance to truncate the value at the colon (NTFS ADS syntax).
// PHP's $_GET decodes percent-encoding automatically.
if (isset($_GET['_dp']) && $_GET['_dp'] !== '') {
    $path = trim($_GET['_dp'], '/');
} else {
    // Fallback for requests that bypass mod_rewrite (e.g. direct CLI execution).
    $rawUri  = $_SERVER['REQUEST_URI'] ?? '/';
    $qPos    = strpos($rawUri, '?');
    $uriPath = ($qPos !== false) ? substr($rawUri, 0, $qPos) : $rawUri;
    // Strip subdirectory prefix when the emulator is deployed in a subfolder.
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($scriptDir !== '' && $scriptDir !== '.') {
        if (strncmp($uriPath, $scriptDir, strlen($scriptDir)) === 0) {
            $uriPath = substr($uriPath, strlen($scriptDir));
        }
    }
    $path = trim($uriPath, '/');
}

// Extract device type from URL prefix
$deviceType = null;
if (preg_match('#^(neosoft|trio|pontos-base|safe-tec)/#', $path, $matches)) {
    $deviceType = $matches[1];
} else {
    send_json_response(400, json_encode([
        'error'   => 'Invalid device prefix',
        'path'    => $path,
        'message' => 'URL must start with /neosoft/, /trio/, /pontos-base/ or /safe-tec/',
    ]));
}

// Persistent fixture file selection per device type
$configFile = null;
$persistFile = null;
// Ensure runtime folders exist for persisted configs and logs
$logsDir = __DIR__ . '/logs';
$configsDir = __DIR__ . '/configs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0777, true);
}
if (!is_dir($configsDir)) {
    @mkdir($configsDir, 0777, true);
}
if (in_array($deviceType, ['trio', 'neosoft', 'pontos-base', 'safe-tec'], true)) {
    $persistFile = $configsDir . '/config_selection_' . $deviceType . '.txt';
    // Default fixture filenames (kept in sync with DeviceEmulator::$fixtureMap)
    $defaultFixtureMap = [
        'neosoft' => 'neosoft2500.json',
        'pontos-base' => 'pontos.json',
        'safe-tec' => 'safetech_v4_copy.json',
        'trio' => 'safetechplus.json',
    ];

    // Accept any existing JSON fixture in the devices folder. Use basename()
    // to avoid directory traversal and require the file to exist.
    if (isset($_GET['config'])) {
        $rawConfig = trim((string)($_GET['config'] ?? ''));
        $lc = strtolower($rawConfig);
        $savedOk = false;
        $reportedFile = null;

        // Special values to reset to default
        // Treat empty value as a request to reset to default
        if ($rawConfig === '' || in_array($lc, ['default', 'reset', 'none'], true)) {
            if (file_exists($persistFile)) {
                $savedOk = @unlink($persistFile);
                if ($savedOk === false) {
                    $logPath = $logsDir . '/emulator_internal.log';
                    $msg = sprintf("[%s] Failed to remove persist file: %s\n", date('c'), $persistFile);
                    @file_put_contents($logPath, $msg, FILE_APPEND | LOCK_EX);
                }
            } else {
                // nothing to remove, treat as success
                $savedOk = true;
            }
            $configFile = null; // force default fixture
            $reportedFile = $defaultFixtureMap[$deviceType] ?? null;
        } else {
            $candidate = basename($rawConfig);
            $customPath = __DIR__ . '/devices/' . $candidate;
            if (file_exists($customPath)) {
                $configFile = $candidate;
                // Write selection persistently (LOCK_EX), check result
                $writeResult = @file_put_contents($persistFile, $configFile, LOCK_EX);
                if ($writeResult === false) {
                    $dir = dirname($persistFile);
                    $writable = is_writable($dir) ? 'writable' : 'not writable';
                    $logPath = $logsDir . '/emulator_internal.log';
                    $msg = sprintf("[%s] Failed to write persist file: %s (dir %s is %s)\n", date('c'), $persistFile, $dir, $writable);
                    @file_put_contents($logPath, $msg, FILE_APPEND | LOCK_EX);
                    $savedOk = false;
                } else {
                    $savedOk = true;
                }
                $reportedFile = $candidate;
            } else {
                // requested config not found
                $savedOk = false;
                $reportedFile = $candidate;
            }
        }

        // Determine effective filename to report (respect device default map)
        if ($reportedFile !== null) {
            $effectiveFile = $reportedFile;
        } elseif (isset($defaultFixtureMap[$deviceType])) {
            $effectiveFile = $defaultFixtureMap[$deviceType];
        } else {
            // No default mapping for this device -> return 401 with JSON body
            send_json_response(401, json_encode([
                'error'  => 'no_default_mapping',
                'device' => $deviceType,
            ], JSON_PRETTY_PRINT));
        }

        // Return immediate JSON response about config change and stop processing
        $responseJson = json_encode(['setFILE' => $effectiveFile, 'setSAVED' => $savedOk === true], JSON_PRETTY_PRINT);
        send_json_response(200, $responseJson, ['X-Emulator-Config: ' . $effectiveFile]);
    } elseif ($persistFile && file_exists($persistFile)) {
        $saved = trim(file_get_contents($persistFile));
        $savedBasename = basename($saved);
        $customPath = __DIR__ . '/devices/' . $savedBasename;
        if (file_exists($customPath)) {
            $configFile = $savedBasename;
        }
    }
}

// Configuration
// Ensure main SET operations logfile is inside logs/
$logFile = $logsDir . '/set_operations.log';

// Initialize device emulator, passing configFile if set
$emulator = new DeviceEmulator($deviceType, $logFile, $configFile);

// Enforce casing rules: the command segment MUST be exactly `set` or `get` (lowercase).
// Any deviation results in an empty 404 response.
$parts = explode('/', $path);
if (isset($parts[1])) {
    $cmdRaw = $parts[1];
    if ($cmdRaw !== 'set' && $cmdRaw !== 'get') {
        send_empty_response(404);
    }
    // Enforce that the key segment (third segment) is lowercase, except ADM which must be uppercase
    if (isset($parts[2])) {
        $keyRaw = $parts[2];
        if (strtolower($keyRaw) === 'adm') {
            if ($keyRaw !== 'ADM') {
                send_empty_response(404);
            }
        } else {
            if ($keyRaw !== strtolower($keyRaw)) {
                send_empty_response(404);
            }
        }
    }
}

// Route the request
if (preg_match('#^[^/]+/set/ADM/\(2\)f$#', $path)) {
    // Login endpoint
    $emulator->handleLogin();
} elseif (preg_match('#^[^/]+/get/all$#', $path)) {
    // Get all values
    $emulator->handleGetAll();
} elseif (preg_match('#^[^/]+/get/([^/]+)$#', $path, $matches)) {
    // Get single value
    $key = $matches[1];
    $emulator->handleGetSingle($key);
} elseif (preg_match('#^[^/]+/set/([^/]+)/(.+)$#', $path, $matches)) {
    // Set operation
    $key = $matches[1];
    $value = $matches[2];
    $emulator->handleSet($key, $value);
} else {
    // Unknown endpoint — return empty 404
    send_empty_response(404);
}
