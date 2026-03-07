<?php
/**
 * Device Emulator Class - Local JSON API
 * 
 * Emulates SYR device behavior for local JSON API by loading fixture data
 * and handling GET/SET operations via JSON endpoints.
 * 
 * This emulator is specifically designed for the local JSON API
 * (Neosoft 2500, Trio DFR/LS) and does not support XML API.
 */
class DeviceEmulator
{
    private string $deviceType;
    private string $logFile;
    private array $deviceData;
    private bool $isLoggedIn = false;

    /**
     * Constructor
     * 
     * @param string $deviceType Device type: 'neosoft' or 'trio'
     * @param string $logFile Path to log file for SET operations
     */
    public function __construct(string $deviceType, string $logFile)
    {
        $this->deviceType = strtolower($deviceType);
        $this->logFile = $logFile;
        $this->loadDeviceData();
    }

    /**
     * Load device data from JSON fixture files
     */
    private function loadDeviceData(): void
    {
        $fixtureMap = [
            'neosoft' => __DIR__ . '/devices/neosoft2500.json',
            'trio' => __DIR__ . '/devices/trio.json',
        ];

        $fixturePath = $fixtureMap[$this->deviceType] ?? $fixtureMap['neosoft'];

        if (!file_exists($fixturePath)) {
            $this->sendError(500, "Device fixture file not found: $fixturePath");
            exit;
        }

        $json = file_get_contents($fixturePath);
        $this->deviceData = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(500, "Failed to parse device data: " . json_last_error_msg());
            exit;
        }
    }

    /**
     * Handle login endpoint
     * 
     * Endpoint: /api/set/ADM/(2)f
     * Some devices require this before returning data from /get/all
     */
    public function handleLogin(): void
    {
        $this->isLoggedIn = true;
        
        // Log the login attempt
        $this->logOperation('LOGIN', 'ADM', '(2)f');
        
        // Return success - match real device response exactly
        $response = json_encode(['setADM(2)f' => 'OK']);
        $this->sendRawResponse($response);
    }

    /**
     * Handle GET all values
     * 
     * Endpoint: /api/get/all
     * Returns all device values as JSON
     */
    public function handleGetAll(): void
    {
        // Some devices require login first
        // For testing, we'll allow it without login but log a warning
        if (!$this->isLoggedIn) {
            error_log("WARNING: /get/all accessed without prior login");
        }

        $response = json_encode($this->deviceData, JSON_PRETTY_PRINT);
        $this->sendRawResponse($response);
    }

    /**
     * Handle SET operation
     * 
     * Endpoint: /api/set/{key}/{value}
     * Updates device value and logs the operation
     * 
     * @param string $key Device parameter key (e.g., 'AB', 'RTM', 'SV1')
     * @param string $value New value
     */
    public function handleSet(string $key, string $value): void
    {
        // Convert set key to get key (e.g., 'AB' -> 'getAB', 'RTM' -> 'getRTM')
        $getKey = 'get' . $key;

        // Check if key exists in device data
        if (!array_key_exists($getKey, $this->deviceData)) {
            $this->logOperation('SET_ERROR', $key, $value, "Key not found: $getKey");
            $this->sendError(400, "Unknown device key: $key");
            return;
        }

        // Get old value
        $oldValue = $this->deviceData[$getKey];

        // Update the value (convert type if needed)
        $newValue = $this->convertValue($value, $oldValue);
        $this->deviceData[$getKey] = $newValue;

        // Log the operation
        $this->logOperation('SET', $key, $value, "Changed $getKey from " . json_encode($oldValue) . " to " . json_encode($newValue));

        // Return success - match real device response format
        $setKey = 'set' . $key;
        $response = json_encode([$setKey => 'OK']);
        $this->sendRawResponse($response);
    }

    /**
     * Convert string value to appropriate type based on old value
     * 
     * @param string $value New value (as string)
     * @param mixed $oldValue Old value (with correct type)
     * @return mixed Converted value
     */
    private function convertValue(string $value, $oldValue)
    {
        // If old value is boolean
        if (is_bool($oldValue)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        // If old value is integer
        if (is_int($oldValue)) {
            return (int) $value;
        }

        // If old value is float
        if (is_float($oldValue)) {
            return (float) $value;
        }

        // If old value is array, try to decode JSON
        if (is_array($oldValue)) {
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : $value;
        }

        // Default: return as string
        return $value;
    }

    /**
     * Log SET operation to file
     * 
     * @param string $operation Operation type (SET, SET_ERROR, LOGIN)
     * @param string $key Device key
     * @param string $value Value
     * @param string $details Additional details (optional)
     */
    private function logOperation(string $operation, string $key, string $value, string $details = ''): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $logEntry = sprintf(
            "[%s] %s | Device: %s | Client: %s | Key: %s | Value: %s | %s | User-Agent: %s\n",
            $timestamp,
            $operation,
            $this->deviceType,
            $clientIp,
            $key,
            $value,
            $details,
            $userAgent
        );

        // Append to log file
        file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Send error response
     * 
     * @param int $code HTTP status code
     * @param string $message Error message
     */
    private function sendError(int $code, string $message): void
    {
        http_response_code($code);
        $response = json_encode([
            'error' => $message,
            'code' => $code,
            'device_type' => $this->deviceType
        ]);
        $this->sendRawResponse($response);
    }

    /**
     * Send raw HTTP response with minimal headers (matching real device)
     * 
     * Real device only sends:
     * - HTTP/1.1 200 OK
     * - content-length: XX (lowercase!)
     * - No Content-Type header
     * - Body ends with \r\n\r\n
     * 
     * @param string $body Response body
     */
    private function sendRawResponse(string $body): void
    {
        // Ensure we're using HTTP/1.1 and 200 OK is already set
        if (http_response_code() === false) {
            http_response_code(200);
        }
        
        // Remove ALL default headers
        header_remove();
        
        // Calculate content length and send ONLY content-length header (lowercase)
        // The body already has \r\n from json_encode, add extra \r\n
        $bodyWithEnding = $body . "\r\n\r\n";
        $contentLength = strlen($body);
        
        // Send exactly what the real device sends - lowercase header name
        header('content-length: ' . $contentLength, true);
        
        // Output body with proper line endings
        echo $bodyWithEnding;
        
        // Flush output immediately to prevent Apache from modifying headers
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }
}
