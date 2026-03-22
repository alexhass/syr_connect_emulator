<?php
// Worker to apply a single transition after a delay.
// Usage: php transition_worker.php <persisted_path> <key> <delay_seconds> <final_value>
// Note: persisted_path should be an absolute path to the persisted JSON file.
if ($argc < 5) {
    file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: insufficient args\n", date('c')), FILE_APPEND | LOCK_EX);
    exit(1);
}
$persistPath = $argv[1];
$key = $argv[2];
$delay = (int)$argv[3];
$final = $argv[4];
// Sleep for delay
sleep($delay);
// Load persisted state
if (!file_exists($persistPath)) {
    file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: persisted file not found: %s\n", date('c'), $persistPath), FILE_APPEND | LOCK_EX);
    exit(1);
}
$json = @file_get_contents($persistPath);
if ($json === false) {
    file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: failed to read %s\n", date('c'), $persistPath), FILE_APPEND | LOCK_EX);
    exit(1);
}
$data = json_decode($json, true);
if (!is_array($data)) {
    file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: invalid JSON in %s\n", date('c'), $persistPath), FILE_APPEND | LOCK_EX);
    exit(1);
}
// Apply final value and remove transition
$data[$key] = is_numeric($final) ? (int)$final : $final;
if (isset($data['__transitions'][$key])) {
    unset($data['__transitions'][$key]);
}
if (empty($data['__transitions'])) {
    unset($data['__transitions']);
}
$w = @file_put_contents($persistPath, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
if ($w === false) {
    file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: failed to write %s\n", date('c'), $persistPath), FILE_APPEND | LOCK_EX);
    exit(1);
}
file_put_contents(__DIR__ . '/../logs/emulator_internal.log', sprintf("[%s] transition_worker: applied %s=%s in %s\n", date('c'), $key, $final, $persistPath), FILE_APPEND | LOCK_EX);
exit(0);
