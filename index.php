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
 *   /{device}/set/{key}/{value}  - Set a device value
 * 
 * Device types: neosoft, trio
 */

require_once __DIR__ . '/DeviceEmulator.php';

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
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Invalid device prefix',
        'path' => $path,
        'message' => 'URL must start with /neosoft/ or /trio/'
    ]);
    exit;
}

// Initialize device emulator
$emulator = new DeviceEmulator($deviceType, $logFile);

// Route the request
if (preg_match('#^[^/]+/set/ADM/\(2\)f$#', $path)) {
    // Login endpoint
    $emulator->handleLogin();
} elseif (preg_match('#^[^/]+/get/all$#', $path)) {
    // Get all values
    $emulator->handleGetAll();
} elseif (preg_match('#^[^/]+/set/([^/]+)/(.+)$#', $path, $matches)) {
    // Set operation
    $key = $matches[1];
    $value = urldecode($matches[2]);
    $emulator->handleSet($key, $value);
} else {
    // Unknown endpoint
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Not Found',
        'path' => $path,
        'message' => 'Valid endpoints: /{device}/set/ADM/(2)f, /{device}/get/all, /{device}/set/{key}/{value}'
    ]);
}
