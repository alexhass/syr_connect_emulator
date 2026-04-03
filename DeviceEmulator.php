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
    private string $fixturePath;
    private bool $isLoggedIn = false;
    private ?string $configFile;

    /**
     * Constructor
     * 
     * @param string $deviceType Device type: 'neosoft', 'trio' or 'safe-tec'
     * @param string $logFile Path to log file for SET operations
     */
    public function __construct(string $deviceType, string $logFile, ?string $configFile = null)
    {
        $this->deviceType = strtolower($deviceType);
        $this->logFile = $logFile;
        $this->configFile = $configFile;
        $this->loadDeviceData();
    }

    /**
     * Load device data from JSON fixture files
     */
    private function loadDeviceData(): void
    {
        // Use latest firmware fixtures by default, but allow override with configFile if valid
        $fixtureMap = [
            'neosoft' => __DIR__ . '/devices/neosoft2500.json',
            'pontos-base' => __DIR__ . '/devices/pontos.json',
            'safe-tec' => __DIR__ . '/devices/safetech_v4_copy.json',
            'trio' => __DIR__ . '/devices/trio.json',
        ];

        // If a config file was provided, accept any existing JSON fixture in devices/
        $fixturePath = $fixtureMap[$this->deviceType] ?? $fixtureMap['neosoft'];
        if ($this->configFile) {
            $customBasename = basename($this->configFile);
            $customPath = __DIR__ . '/devices/' . $customBasename;
            if (file_exists($customPath)) {
                $fixturePath = $customPath;
            }
        }

        if (!file_exists($fixturePath)) {
            $this->sendError(500, "Device fixture file not found: $fixturePath");
            exit;
        }

        // remember which fixture file we loaded (basename used for device-specific behavior)
        $this->fixturePath = $fixturePath;

        $json = file_get_contents($fixturePath);
        $this->deviceData = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendError(500, "Failed to parse device data: " . json_last_error_msg());
            exit;
        }

        // Merge any persisted runtime state (SET operations) so GET returns the updated values.
        $persisted = $this->loadPersistedState();
        if (!empty($persisted) && is_array($persisted)) {
            // Apply any pending transitions first (this may update deviceData and persisted)
            $this->applyPendingTransitions($persisted);

            // Persisted state contains get-keys (e.g., getAB) => value
            // Exclude internal '__transitions' key when merging values
            $merge = $persisted;
            if (isset($merge['__transitions'])) {
                unset($merge['__transitions']);
            }
            $this->deviceData = array_replace($this->deviceData, $merge);
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
        // For most safetech_v4* fixtures the ADM login exists and returns OK.
        // For all other device fixtures the ADM endpoint does not exist and should return 404 File Not Found.
        $fixtureName = basename($this->fixturePath);
        if (!preg_match('/^(safetech_v4|pontos)/i', $fixtureName)) {
            // Emulate device behavior: return an empty 404 for unsupported commands
            http_response_code(404);
            header_remove();
            header('content-length: 0', true);
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            return;
        }

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
            // Only log this warning for pontos devices or safetech_v4* fixtures
            $fixtureName = basename($this->fixturePath ?? '');
            // Log for pontos devices, safetech_v4 fixtures or safe-tec devices. Combine checks into one expression.
            $shouldLog = ($this->deviceType === 'pontos-base' || $this->deviceType === 'safe-tec') || (bool) preg_match('/^(pontos|safetech_v4)/i', $fixtureName);

            if ($shouldLog) {
                $logPath = __DIR__ . '/logs/emulator_internal.log';
                if (!is_dir(dirname($logPath))) {
                    @mkdir(dirname($logPath), 0777, true);
                }
                $msg = sprintf("[%s] WARNING: /get/all accessed without prior login | device=%s | fixture=%s\n", date('c'), $this->deviceType, $fixtureName);
                @file_put_contents($logPath, $msg, FILE_APPEND | LOCK_EX);
            }
        }

        // Ensure any pending transitions are applied now (on GET requests) and persisted.
        $persisted = $this->loadPersistedState();
        if (!is_array($persisted)) {
            $persisted = [];
        }
        $this->applyPendingTransitions($persisted);
        // Merge persisted values (excluding internal transitions) to deviceData
        $merge = $persisted;
        if (isset($merge['__transitions'])) {
            unset($merge['__transitions']);
        }
        if (!empty($merge)) {
            $this->deviceData = array_replace($this->deviceData, $merge);
        }
        // Persist any changes made by applyPendingTransitions
        $this->savePersistedState($persisted);

        $response = json_encode($this->deviceData, JSON_PRETTY_PRINT);
        $this->sendRawResponse($response);
    }

    /**
     * Handle GET single value
     * 
     * Endpoint: /api/get/{key}
     * Returns single device value or NSC (Not a valid command) if key doesn't exist
     * 
     * @param string $key Device parameter key (e.g., 'AB', 'FLO', 'SV1')
     */
    public function handleGetSingle(string $key): void
    {
        // Convert to get key format (e.g., 'AB' -> 'getAB')
        $getKey = 'get' . strtoupper($key);

        // Check if key exists in device data
        if (!array_key_exists($getKey, $this->deviceData)) {
            // Key not found - return NSC (Not a valid command)
            $response = json_encode([$getKey => 'NSC']);
            $this->sendRawResponse($response);
            return;
        }

        // Return the single value
        $value = $this->deviceData[$getKey];
        $response = json_encode([$getKey => $value]);
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
        $getKey = 'get' . strtoupper($key);

        // Check if key exists in device data
        if (!array_key_exists($getKey, $this->deviceData)) {
            // Key not found - emulate real device behavior: return NSC (Not a valid command)
            $this->logOperation('SET_ERROR', $key, $value, "Key not found: $getKey");
            $responseKey = 'set' . strtoupper($key) . $value;
            $response = json_encode([$responseKey => 'NSC']);
            $this->sendRawResponse($response);
            return;
        }

        // Get old value
        $oldValue = $this->deviceData[$getKey];

        // Validate value range
        $validationResult = $this->validateValue($key, $value, $oldValue);
        
        // Generate response key: "set" + key + value (remove slashes)
        // Example: /set/SIR/0 -> setSIR0
        $responseKey = 'set' . strtoupper($key) . $value;
        
        if ($validationResult !== true) {
            // Value is outside valid range
            $this->logOperation('SET_VALIDATION_ERROR', $key, $value, "Value outside valid range: $validationResult");
            $response = json_encode([$responseKey => 'MIMA']);
            $this->sendRawResponse($response);
            return;
        }

        // Update the value (convert type if needed)
        $newValue = $this->convertValue($value, $oldValue);
        // Normalize RTM (regen time) to HH:MM with leading zeros when provided like "2:15"
        $upperKey = strtoupper($key);
        if ($upperKey === 'RTM' && is_string($newValue)) {
            if (preg_match('/^(\d{1,2}):(\d{1,2})$/', $newValue, $m)) {
                $hh = (int)$m[1];
                $mm = (int)$m[2];
                if ($hh >= 0 && $hh <= 23 && $mm >= 0 && $mm <= 59) {
                    $newValue = sprintf('%02d:%02d', $hh, $mm);
                }
            }
        }
        // Special case: for ALA, WRN, NOT a numeric 255 should be stored as hex "FF"
        if (in_array($upperKey, ['ALA', 'WRN', 'NOT'], true)) {
            // If the incoming raw value or converted value represents 255, convert to hex
            if ((is_numeric($value) && (int)$value === 255) || (is_int($newValue) && $newValue === 255) || ($newValue === '255')) {
                $newValue = sprintf('%02x', 255);
            }
        }

        $this->deviceData[$getKey] = $newValue;

        // Log the operation
        $this->logOperation('SET', $key, $value, "Changed $getKey from " . json_encode($oldValue) . " to " . json_encode($newValue));

        // Persist this SET so subsequent GET requests return the updated value
        $persisted = $this->loadPersistedState();
        if (!is_array($persisted)) {
            $persisted = [];
        }
        $persisted[$getKey] = $newValue;
        $saved = $this->savePersistedState($persisted);
        if (!$saved) {
            $this->writeInternalLog("Failed to persist state for $getKey");
        }
        else {
            $keys = array_keys($persisted);
            if(($idx = array_search('__transitions', $keys)) !== false) { unset($keys[$idx]); }
            $this->writeInternalLog(sprintf("Persisted state saved (%s): %s", $this->getStateFilePath(), implode(',', $keys)));
        }

        // If SV1, SV2 or SV3 changed, derive corresponding SSx = SVx * 2 (integers only) and persist
        if (preg_match('/^getSV([1-3])$/', $getKey, $m)) {
            $idx = $m[1];
            $ssKey = 'getSS' . $idx;
            // Use multiplier 2.3 and round to nearest integer
            $ssVal = (int) round((float)$newValue * 2.3);
            $this->deviceData[$ssKey] = $ssVal;
            $persisted[$ssKey] = $ssVal;
            $savedSs = $this->savePersistedState($persisted);
            if ($savedSs) {
                $this->writeInternalLog(sprintf("Derived %s=%s from %s=%s", $ssKey, $ssVal, $getKey, (string)$newValue));
            } else {
                $this->writeInternalLog(sprintf("Failed to persist derived %s for %s", $ssKey, $getKey));
            }
        }

        // If SVx decreased past thresholds, auto-set warnings/alarms
        // When getSVx falls to <=5 -> set getWRN = '02'
        // When getSVx falls to <=2 -> set getALA = '0d'
        if (preg_match('/^getSV([1-3])$/', $getKey)) {
            $intNew = (int)$newValue;
            $intOld = is_numeric($oldValue) ? (int)$oldValue : (int)$oldValue;

            if ($intNew <= 5 && $intOld > 5) {
                $wrnKey = 'getWRN';
                $this->deviceData[$wrnKey] = '02';
                $persisted[$wrnKey] = '02';
                $savedWarn = $this->savePersistedState($persisted);
                if ($savedWarn) {
                    $this->writeInternalLog(sprintf("Auto-set %s=%s due to %s=%s", $wrnKey, '02', $getKey, (string)$newValue));
                } else {
                    $this->writeInternalLog(sprintf("Failed to persist auto-set %s for %s", $wrnKey, $getKey));
                }
            }

            if ($intNew <= 2 && $intOld > 2) {
                $alaKey = 'getALA';
                $this->deviceData[$alaKey] = '0d';
                $persisted[$alaKey] = '0d';
                $savedAla = $this->savePersistedState($persisted);
                if ($savedAla) {
                    $this->writeInternalLog(sprintf("Auto-set %s=%s due to %s=%s", $alaKey, '0d', $getKey, (string)$newValue));
                } else {
                    $this->writeInternalLog(sprintf("Failed to persist auto-set %s for %s", $alaKey, $getKey));
                }
            }
        }

        // Special behavior: changes to AB should modify VLV with a delayed transition
        // If AB changed from false->true: set getVLV = 11 now, schedule ->10 after 30s
        // If AB changed from true->false: set getVLV = 21 now, schedule ->20 after 30s
        if ($getKey === 'getAB') {
            $vlvKey = 'getVLV';
            $now = time();
            $transitions = $persisted['__transitions'] ?? [];

            if ($oldValue === false && $newValue === true) {
                // immediate
                $this->deviceData[$vlvKey] = 11;
                $persisted[$vlvKey] = 11;
                // schedule final value 10
                $transitions[$vlvKey] = ['time' => $now + 30, 'final' => 10];
            } elseif ($oldValue === true && $newValue === false) {
                $this->deviceData[$vlvKey] = 21;
                $persisted[$vlvKey] = 21;
                $transitions[$vlvKey] = ['time' => $now + 30, 'final' => 20];
            }

            if (!empty($transitions)) {
                $persisted['__transitions'] = $transitions;
                $saved2 = $this->savePersistedState($persisted);
                if ($saved2) {
                    $this->writeInternalLog(sprintf("Scheduled transitions saved to %s: %s", $this->getStateFilePath(), implode(',', array_keys($transitions))));
                } else {
                    $this->writeInternalLog(sprintf("Failed to save scheduled transitions to %s", $this->getStateFilePath()));
                }

                // Start background worker to ensure transition is applied after delay
                foreach ($transitions as $tkey => $entry) {
                    if (!isset($entry['time']) || !isset($entry['final'])) {
                        continue;
                    }
                    $delay = max(0, (int)$entry['time'] - time());
                    $started = $this->startTransitionWorker($tkey, $delay, $entry['final']);
                    if (!$started) {
                        $this->writeInternalLog("Failed to start transition worker for $tkey");
                    } else {
                        $this->writeInternalLog("Started transition worker for $tkey with delay $delay");
                    }
                }
            }
        }

        // Return success
        $response = json_encode([$responseKey => 'OK']);
        $this->sendRawResponse($response);
    }

    /**
     * Validate value range for specific device parameters
     * 
     * @param string $key Device parameter key
     * @param string $value New value to validate
     * @param mixed $oldValue Old value (for type checking)
     * @return true|string True if valid, error message if invalid
     */
    private function validateValue(string $key, string $value, $oldValue)
    {
        // Special validation for neosoft device RPD parameter
        // Only values 1-3 are allowed
        if ($this->deviceType === 'neosoft' && $key === 'RPD') {
            $intValue = (int) $value;
            if ($intValue < 1 || $intValue > 3) {
                return "RPD must be between 1 and 3, got: $value";
            }
        }

        // Add more validations here as needed
        // Example:
        // if ($key === 'SV1' && (int)$value < 0) {
        //     return "SV1 cannot be negative";
        // }

        return true;
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
     * Get path to persisted state file for this device type
     */
    private function getStateFilePath(): string
    {
        // Prefer using the actual fixture basename so persisted state is tied to the
        // concrete JSON fixture file (e.g., persisted_neosoft2500.json).
        $base = basename($this->fixturePath ?? ($this->configFile ?? ($this->deviceType . '.json')));
        return __DIR__ . '/configs/persisted_' . $base;
    }

    /**
     * Load persisted state (returns array of getKey => value)
     */
    private function loadPersistedState(): array
    {
        $path = $this->getStateFilePath();
        if (!file_exists($path)) {
            return [];
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            $this->writeInternalLog("Failed to read persisted state: $path");
            return [];
        }
        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            $this->writeInternalLog("Invalid persisted state JSON: $path");
            return [];
        }
        return $data;
    }

    /**
     * Apply any pending transitions stored in persisted state.
     * Transitions are stored under '__transitions' => [ key => ['time'=>timestamp, 'final'=>value], ... ]
     */
    private function applyPendingTransitions(array &$persisted): void
    {
        $changed = false;
        $now = time();
        $transitions = $persisted['__transitions'] ?? [];
        if (!is_array($transitions) || empty($transitions)) {
            return;
        }

        foreach ($transitions as $tkey => $entry) {
            if (!isset($entry['time']) || !isset($entry['final'])) {
                continue;
            }
            if ($entry['time'] <= $now) {
                // apply final value
                $this->deviceData[$tkey] = $entry['final'];
                $persisted[$tkey] = $entry['final'];
                unset($transitions[$tkey]);
                $changed = true;
            } else {
                // not yet: ensure deviceData reflects the current persisted immediate value if present
                if (isset($persisted[$tkey])) {
                    $this->deviceData[$tkey] = $persisted[$tkey];
                }
            }
        }

        if ($changed) {
            // update persisted transitions
            if (!empty($transitions)) {
                $persisted['__transitions'] = $transitions;
            } else {
                unset($persisted['__transitions']);
            }
            $this->savePersistedState($persisted);
        }
    }

    /**
     * Save persisted state (getKey => value) to disk
     */
    private function savePersistedState(array $state): bool
    {
        $path = $this->getStateFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        // Normalize specific hex keys to lowercase before persisting
        $hexKeys = ['getALA', 'getWRN', 'getNOT'];
        foreach ($hexKeys as $hk) {
            if (isset($state[$hk]) && is_string($state[$hk])) {
                $state[$hk] = strtolower($state[$hk]);
            }
        }
        $json = json_encode($state, JSON_PRETTY_PRINT);
        $result = @file_put_contents($path, $json, LOCK_EX);
        if ($result === false) {
            $this->writeInternalLog("Failed to write persisted state: $path");
            return false;
        }
        // log successful write with keys (exclude internal transitions)
        $keys = array_keys($state);
        if(($idx = array_search('__transitions', $keys)) !== false) { unset($keys[$idx]); }
        $this->writeInternalLog(sprintf("Wrote persisted state to %s (keys: %s)", $path, implode(',', $keys)));
        return true;
    }

    /**
     * Write a short internal log message to logs/emulator_internal.log
     */
    private function writeInternalLog(string $message): void
    {
        $logPath = __DIR__ . '/logs/emulator_internal.log';
        if (!is_dir(dirname($logPath))) {
            @mkdir(dirname($logPath), 0777, true);
        }
        $msg = sprintf("[%s] %s\n", date('c'), $message);
        @file_put_contents($logPath, $msg, FILE_APPEND | LOCK_EX);
    }

    /**
     * Start a detached PHP worker to apply a transition after a delay.
     * Returns true if the worker launch was attempted.
     */
    private function startTransitionWorker(string $key, int $delaySeconds, $finalValue): bool
    {
        $persistPath = $this->getStateFilePath();
        $worker = __DIR__ . '/scripts/transition_worker.php';
        if (!file_exists($worker)) {
            $this->writeInternalLog("transition worker missing: $worker");
            return false;
        }

        $php = PHP_BINARY;
        $args = [
            $persistPath,
            $key,
            (string)$delaySeconds,
            (string)$finalValue,
        ];
        $esc = array_map('escapeshellarg', $args);
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($worker) . ' ' . implode(' ', $esc);

        // Launch detached depending on OS
        if (PHP_OS_FAMILY === 'Windows') {
            // start /B php script args
            $cmd = 'start /B ' . $cmd;
            pclose(popen($cmd, 'r'));
            return true;
        } else {
            // POSIX: run in background
            $cmd = $cmd . ' > /dev/null 2>&1 &';
            exec($cmd);
            return true;
        }
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
        
        // Add a custom header that exposes which JSON fixture was used.
        // Prefer the actual loaded fixture path, fall back to the requested config filename,
        // otherwise indicate 'default'. This helps debugging which file was returned.
        $configName = 'default';
        if (!empty($this->fixturePath)) {
            $configName = basename($this->fixturePath);
        } elseif (!empty($this->configFile)) {
            $configName = basename($this->configFile);
        }
        header('X-Emulator-Config: ' . $configName, true);
        
        // Calculate content length and send ONLY content-length header (lowercase)
        // The body already has \r\n from json_encode, add extra \r\n
        $bodyWithEnding = $body . "\r\n\r\n";
        $contentLength = strlen($body);

        // NOTE: According to the JSON and HTTP standard, a Content-Type: application/json header MUST be sent.
        // However, SYR devices do NOT send this header (non-standard, but emulated here for compatibility).
        // If you want to enable the correct header, uncomment the following line:
        // header('Content-Type: application/json');

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
