<?php
require __DIR__ . '/simulate.php';

simulate_network();

$logFile = __DIR__ . '/../data/protocols.log';
if (!file_exists($logFile)) {
    file_put_contents($logFile, "");
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['id'])) {
    http_response_code(400);
    echo json_encode(["error" => "Missing device ID"]);
    exit;
}

$devicesFile = __DIR__ . '/../data/devices.json';
$devices = json_decode(file_get_contents($devicesFile), true);

$found = false;
foreach ($devices as &$d) {
    if ($d['id'] === $input['id']) {
        $found = true;
        $oldValue = $d['tag']['value'];
        
        if (isset($input['value'])) {
            $d['tag']['value'] = floatval($input['value']);
        }
        if (isset($input['state'])) {
            $d['state'] = $input['state'];
        }
        $d['tag']['ts'] = time();
        $d['tag']['quality'] = 'GOOD';
        
        $logMsg = generate_protocol_log($d, 'WRITE');
        if ($logMsg) {
            error_log($logMsg);
            log_event('WRITE', $logMsg, 'INFO');
            // Écrire dans protocols.log
            $timestamp = date('Y-m-d H:i:s');
            $entry = "[{$timestamp}] {$logMsg}\n";
            file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        }
        
        log_event('CONFIG', "{$d['name']} : {$oldValue} → {$d['tag']['value']} {$d['tag']['unit']}", 'INFO');
        
        break;
    }
}

if (!$found) {
    http_response_code(404);
    echo json_encode(["error" => "Device not found"]);
    exit;
}

file_put_contents($devicesFile, json_encode($devices, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
echo json_encode([
    "status" => "OK", 
    "device" => $d,
    "protocolLog" => $logMsg ?? null
]);