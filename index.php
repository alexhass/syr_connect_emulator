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
if (preg_match('#^(neosoft|trio)/#', $path, $matches)) {
    $deviceType = $matches[1];
} else {
    http_response_code(400);
    header_remove();
    $response = json_encode([
        'error' => 'Invalid device prefix',
        'path' => $path,
        'message' => 'URL must start with /neosoft/ or /trio/'
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
if ($deviceType === 'trio' || $deviceType === 'neosoft') {
    $persistFile = __DIR__ . '/config_selection_' . $deviceType . '.txt';
    $pattern = $deviceType === 'trio' ? '/^(safetech|trio).*\\.json$/' : '/^neosoft.*\\.json$/';
    if (isset($_GET['config']) && preg_match($pattern, $_GET['config'])) {
        $configFile = $_GET['config'];
        // Schreibe Auswahl persistent
        file_put_contents($persistFile, $configFile);
    } elseif ($persistFile && file_exists($persistFile)) {
        $saved = trim(file_get_contents($persistFile));
        if (preg_match($pattern, $saved)) {
            $configFile = $saved;
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
if (preg_match('#^(neosoft|trio)/#', $path, $matches)) {
    $deviceType = $matches[1];
} else {
    http_response_code(400);
    header_remove();
    $response = json_encode([
        'error' => 'Invalid device prefix',
        'path' => $path,
        'message' => 'URL must start with /neosoft/ or /trio/'
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
    $response = json_encode([
        'error' => 'Not Found',
        'path' => $path,
        'message' => 'Valid endpoints: /{device}/set/ADM/(2)f, /{device}/get/all, /{device}/get/{key}, /{device}/set/{key}/{value}'
    ]);
    $bodyWithEnding = $response . "\r\n\r\n";
    header('content-length: ' . strlen($response));
    echo $bodyWithEnding;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}
