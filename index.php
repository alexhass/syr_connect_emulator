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

// Parse the request URI
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($scriptName, '', $requestUri);
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');

// Remove query string
$path = explode('?', $path)[0];

// Extract device type from URL prefix
$deviceType = null;
if (preg_match('#^(neosoft|trio|pontos-base)/#', $path, $matches)) {
    $deviceType = $matches[1];
} else {
    http_response_code(400);
    header_remove();
    $response = json_encode([
        'error' => 'Invalid device prefix',
        'path' => $path,
        'message' => 'URL must start with /neosoft/, /trio/ or /pontos-base/'
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
if (in_array($deviceType, ['trio', 'neosoft', 'pontos-base'], true)) {
    $persistFile = $configsDir . '/config_selection_' . $deviceType . '.txt';
    // Accept any existing JSON fixture in the devices folder. Use basename()
    // to avoid directory traversal and require the file to exist.
        if (isset($_GET['config'])) {
            $rawConfig = $_GET['config'];
            $lc = strtolower($rawConfig);
            $savedOk = false;
            $reportedFile = null;

            // Special values to reset to default
            if (in_array($lc, ['default', 'reset', 'none'], true)) {
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
                $reportedFile = 'default';
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

            // Return immediate JSON response about config change and stop processing
            $responseObj = [
                'setFILE' => $reportedFile,
                'setSAVED' => $savedOk === true,
            ];
            $responseJson = json_encode($responseObj, JSON_PRETTY_PRINT);

            // Minimal headers to match emulator behavior
            http_response_code(200);
            header_remove();
            header('X-Emulator-Config: ' . ($reportedFile ?? 'default'), true);
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
$logFile = __DIR__ . '/set_operations.log';

// Parse the request URI
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($scriptName, '', $requestUri);
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');

// Remove query string
$path = explode('?', $path)[0];

// Extract device type from URL prefix
$deviceType = null;
if (preg_match('#^(neosoft|trio|pontos-base)/#', $path, $matches)) {
    $deviceType = $matches[1];
} else {
    http_response_code(400);
    header_remove();
        $response = json_encode([
        'error' => 'Invalid device prefix',
        'path' => $path,
        'message' => 'URL must start with /neosoft/, /trio/ or /pontos-base/'
    ]);
    $bodyWithEnding = $response . "\r\n\r\n";
    header('content-length: ' . strlen($response));
    echo $bodyWithEnding;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// Initialize device emulator, übergebe ggf. configFile
$emulator = new DeviceEmulator($deviceType, $logFile, $configFile);

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
    $value = urldecode($matches[2]);
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
