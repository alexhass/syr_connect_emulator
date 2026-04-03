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
if (function_exists('header_remove')) {
    header_remove();
}

// Prevent Apache from adding headers via PHP
header('X-Remove-Headers: true');


require_once __DIR__ . '/DeviceEmulator.php';

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
    http_response_code(400);
    header_remove();
    $response = json_encode([
        'error'   => 'Invalid device prefix',
        'path'    => $path,
        'message' => 'URL must start with /neosoft/, /trio/, /pontos-base/ or /safe-tec/',
    ]);
    $bodyWithEnding = $response . "\r\n\r\n";
    header('content-length: ' . strlen($response));
    echo $bodyWithEnding;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// Persistente Auswahl für JSON-Dateien pro deviceType
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
        'trio' => 'safetechplus.json',
        'safe-tec' => 'safetech_v4_copy.json',
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
                    // Schreibe Auswahl persistent (LOCK_EX), prüfe Ergebnis
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
                http_response_code(401);
                $errorObj = [
                    'error' => 'no_default_mapping',
                    'device' => $deviceType
                ];
                $errorJson = json_encode($errorObj, JSON_PRETTY_PRINT);
                header_remove();
                header('content-length: ' . strlen($errorJson), true);
                echo $errorJson . "\r\n\r\n";
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                exit;
            }

            // Return immediate JSON response about config change and stop processing
            $responseObj = [
                'setFILE' => $effectiveFile,
                'setSAVED' => $savedOk === true,
            ];
            $responseJson = json_encode($responseObj, JSON_PRETTY_PRINT);

            // Minimal headers to match emulator behavior
            http_response_code(200);
            header_remove();
            header('X-Emulator-Config: ' . $effectiveFile, true);
            header('content-length: ' . strlen($responseJson), true);
            echo $responseJson . "\r\n\r\n";
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            exit;
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

// Initialize device emulator, übergebe ggf. configFile
$emulator = new DeviceEmulator($deviceType, $logFile, $configFile);

// Enforce casing rules: the command segment MUST be exactly `set` or `get` (lowercase).
// Any deviation results in an empty 404 response.
$parts = explode('/', $path);
if (isset($parts[1])) {
    $cmdRaw = $parts[1];
    if ($cmdRaw !== 'set' && $cmdRaw !== 'get') {
        http_response_code(404);
        header_remove();
        header('content-length: 0', true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        exit;
    }
    // Enforce that the key segment (third segment) is lowercase, except ADM which must be uppercase
    if (isset($parts[2])) {
        $keyRaw = $parts[2];
        if (strtolower($keyRaw) === 'adm') {
            if ($keyRaw !== 'ADM') {
                http_response_code(404);
                header_remove();
                header('content-length: 0', true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                exit;
            }
        } else {
            if ($keyRaw !== strtolower($keyRaw)) {
                http_response_code(404);
                header_remove();
                header('content-length: 0', true);
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                exit;
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
    // Unknown endpoint
    http_response_code(404);
    header_remove();
    // Return an empty 404 body for unknown commands (no JSON)
    header('content-length: 0', true);
    // send no body
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}
