<?php
require __DIR__ . '/simulate.php';

simulate_network();

$alarmsFile = __DIR__ . '/../data/alarms.json';
$devicesFile = __DIR__ . '/../data/devices.json';

$alarms = file_exists($alarmsFile) ? json_decode(file_get_contents($alarmsFile), true) : [];
$devices = json_decode(file_get_contents($devicesFile), true);

$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

if ($method === 'GET') {
    $deviceFilter = isset($_GET['device']) ? $_GET['device'] : null;
    $severityFilter = isset($_GET['severity']) ? $_GET['severity'] : null;
    $ackFilter = isset($_GET['ack']) ? ($_GET['ack'] === 'true') : null;
    $floorFilter = isset($_GET['floor']) ? $_GET['floor'] : null;
    
    $result = $alarms;
    
    if ($deviceFilter) {
        $result = array_filter($result, function($a) use ($deviceFilter) { return $a['device_id'] === $deviceFilter; });
    }
    if ($severityFilter) {
        $result = array_filter($result, function($a) use ($severityFilter) { return $a['severity'] === $severityFilter; });
    }
    if ($ackFilter !== null) {
        $result = array_filter($result, function($a) use ($ackFilter) { return $a['ack'] === $ackFilter; });
    }
    if ($floorFilter) {
        $result = array_filter($result, function($a) use ($floorFilter) { return ($a['floor'] ?? '') === $floorFilter; });
    }
    
    $result = array_values($result);
    
    $counts = ['HH' => 0, 'H' => 0, 'L' => 0, 'LL' => 0, 'NORMAL' => 0];
    $floorCounts = [];
    $qualityCounts = ['GOOD' => 0, 'UNCERTAIN' => 0, 'BAD' => 0];
    foreach ($alarms as $a) {
        if (isset($counts[$a['severity']])) {
            $counts[$a['severity']]++;
        }
        $floor = $a['floor'] ?? 'unknown';
        $floorCounts[$floor] = ($floorCounts[$floor] ?? 0) + 1;
        $quality = $a['quality'] ?? 'GOOD';
        $qualityCounts[$quality] = ($qualityCounts[$quality] ?? 0) + 1;
    }
    $unackCount = count(array_filter($alarms, function($a) { return !$a['ack']; }));
    
    echo json_encode([
        'alarms' => $result,
        'count' => count($result),
        'counts' => $counts,
        'floor_counts' => $floorCounts,
        'quality_counts' => $qualityCounts,
        'unack_count' => $unackCount,
        'ts' => time()
    ]);
    exit;
}

if ($method === 'POST' || $method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $id = isset($_GET['id']) ? $_GET['id'] : ($input['id'] ?? null);
    $action = isset($_GET['action']) ? $_GET['action'] : ($input['action'] ?? null);
    
    if ($id && $action === 'ack') {
        $found = false;
        foreach ($alarms as &$a) {
            if ($a['id'] === $id) {
                $a['ack'] = true;
                $a['ack_by'] = $input['user'] ?? 'operator';
                $a['ack_ts'] = time();
                $a['shelved'] = false;
                $a['shelve_until'] = null;
                log_event('ACK', "Alarme {$a['name']} acquittée par {$a['ack_by']}", 'INFO');
                $found = true;
                break;
            }
        }
        file_put_contents($alarmsFile, json_encode($alarms));
        echo json_encode(['status' => 'OK', 'id' => $id, 'action' => 'ack']);
        exit;
    }
    
    if ($id && $action === 'shelve') {
        $duration = isset($_GET['duration']) ? intval($_GET['duration']) : 300;
        foreach ($alarms as &$a) {
            if ($a['id'] === $id) {
                $a['shelved'] = true;
                $a['shelve_until'] = time() + $duration;
                $a['shelve_duration'] = $duration;
                log_event('SHELVE', "Alarme {$a['name']} temporisée {$duration}s", 'INFO');
                break;
            }
        }
        file_put_contents($alarmsFile, json_encode($alarms));
        echo json_encode(['status' => 'OK', 'id' => $id, 'action' => 'shelve', 'duration' => $duration]);
        exit;
    }
    
    echo json_encode(['error' => 'Missing id or action']);
    exit;
}

if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? $_GET['id'] : null;
    
    if ($id === 'all') {
        $alarms = [];
        file_put_contents($alarmsFile, json_encode($alarms));
        echo json_encode(['status' => 'OK', 'action' => 'clear_all']);
        exit;
    }
    
    if ($id) {
        $alarms = array_filter($alarms, function($a) use ($id) { return $a['id'] !== $id; });
        $alarms = array_values($alarms);
        file_put_contents($alarmsFile, json_encode($alarms));
        echo json_encode(['status' => 'OK', 'action' => 'delete', 'id' => $id]);
        exit;
    }
    
    echo json_encode(['error' => 'Missing id']);
    exit;
}

echo json_encode(['error' => 'Method not allowed']);