<?php
require __DIR__ . '/simulate.php';

$logFile = __DIR__ . '/../data/protocols.log';
$devicesFile = __DIR__ . '/../data/devices.json';

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

function sendResponse($data) {
    echo json_encode($data);
    exit;
}

function getLogLines($logFile, $limit = 50) {
    if (!file_exists($logFile)) return [];
    
    $lines = file($logFile);
    $lines = array_slice($lines, -$limit);
    return array_map('trim', $lines);
}

function addToProtocolLog($logFile, $message) {
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[{$timestamp}] {$message}\n";
    file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
}

simulate_network();

if ($method === 'GET') {
    $protocolFilter = isset($_GET['protocol']) ? $_GET['protocol'] : null;
    $deviceFilter = isset($_GET['device']) ? $_GET['device'] : null;
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
    $search = isset($_GET['search']) ? $_GET['search'] : null;
    
    $lines = getLogLines($logFile, 200);
    
    if ($protocolFilter) {
        $lines = array_filter($lines, function($l) use ($protocolFilter) {
            return stripos($l, "[{$protocolFilter}]") !== false;
        });
    }
    
    if ($deviceFilter) {
        $lines = array_filter($lines, function($l) use ($deviceFilter) {
            return stripos($l, $deviceFilter) !== false;
        });
    }
    
    if ($search) {
        $lines = array_filter($lines, function($l) use ($search) {
            return stripos($l, $search) !== false;
        });
    }
    
    $lines = array_values($lines);
    $lines = array_slice($lines, -$limit);
    
    $counts = ['BACNET' => 0, 'KNX' => 0, 'MQTT' => 0];
    foreach ($lines as $l) {
        foreach (array_keys($counts) as $p) {
            if (stripos($l, "[{$p}]") !== false) {
                $counts[$p]++;
            }
        }
    }
    
    sendResponse([
        'logs' => $lines,
        'count' => count($lines),
        'counts' => $counts,
        'ts' => time()
    ]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    if (isset($_GET['clear']) || isset($input['clear'])) {
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        sendResponse(['status' => 'OK', 'action' => 'clear']);
        exit;
    }
    
    if (isset($input['message'])) {
        addToProtocolLog($logFile, $input['message']);
        sendResponse(['status' => 'OK']);
        exit;
    }
    
    if (isset($_GET['simulate']) || isset($input['simulate'])) {
        $devices = json_decode(file_get_contents($devicesFile), true);
        $count = isset($_GET['count']) ? intval($_GET['count']) : 3;
        
        $devices = array_slice($devices, 0, $count);
        foreach ($devices as $d) {
            $log = generate_protocol_log($d, 'READ');
            addToProtocolLog($logFile, $log);
        }
        
        sendResponse(['status' => 'OK', 'count' => $count]);
        exit;
    }
    
    sendResponse(['error' => 'Invalid action']);
}

sendResponse(['error' => 'Method not allowed']);