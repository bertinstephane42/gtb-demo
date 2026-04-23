<?php
require __DIR__ . '/simulate.php';

$scenariosFile = __DIR__ . '/../data/scenarios.json';
$devicesFile = __DIR__ . '/../data/devices.json';
$eventsFile = __DIR__ . '/../data/events.json';

$scenarios = file_exists($scenariosFile) ? json_decode(file_get_contents($scenariosFile), true) : [];
$devices = json_decode(file_get_contents($devicesFile), true);

$method = $_SERVER['REQUEST_METHOD'];
header('Content-Type: application/json');

function sendResponse($data) {
    echo json_encode($data);
    exit;
}

function findDevice($devices, $id) {
    foreach ($devices as $d) {
        if ($d['id'] === $id) return $d;
    }
    return null;
}

function evaluateCondition($condition, $devices) {
    $device = findDevice($devices, $condition['device']);
    if (!$device) return false;
    
    $value = $device['tag']['value'];
    $target = floatval($condition['value']);
    $op = $condition['operator'];
    
    switch ($op) {
        case '>': return $value > $target;
        case '<': return $value < $target;
        case '>=': return $value >= $target;
        case '<=': return $value <= $target;
        case '==': return abs($value - $target) < 0.01;
        case '!=': return abs($value - $target) >= 0.01;
    }
    return false;
}

function isScheduleActive($schedule) {
    $now = time();
    $currentDay = date('w');
    $currentTime = date('H:i');
    
    $type = $schedule['type'] ?? 'always_on';
    
    if ($type === 'always_on') {
        $days = $schedule['days'] ?? [0,1,2,3,4,5,6];
        return in_array(intval($currentDay), $days);
    }
    
    if ($type === 'weekly') {
        $days = $schedule['days'] ?? [0,1,2,3,4,5,6];
        $start = $schedule['start'] ?? '00:00';
        $end = $schedule['end'] ?? '23:59';
        
        if (!in_array(intval($currentDay), $days)) return false;
        
        if ($start > $end) {
            return $currentTime >= $start || $currentTime <= $end;
        }
        return $currentTime >= $start && $currentTime <= $end;
    }
    
    if ($type === 'hourly') {
        $days = $schedule['days'] ?? [1,2,3,4,5];
        $hours = $schedule['hours'] ?? [];
        $currentHour = intval(date('H'));
        
        if (!in_array(intval($currentDay), $days)) return false;
        return in_array($currentHour, $hours);
    }
    
    if ($type === 'date_range') {
        $startDate = $schedule['start_date'] ?? '';
        $endDate = $schedule['end_date'] ?? '';
        
        if (empty($startDate) || empty($endDate)) return false;
        
        $today = date('Y-m-d');
        return $today >= $startDate && $today <= $endDate;
    }
    
    return true;
}

function runScenario($scenario, &$devices, &$events) {
    if (!$scenario['enabled']) return false;
    if (!isScheduleActive($scenario['schedule'])) return false;
    
    foreach ($scenario['conditions'] as $cond) {
        if (!evaluateCondition($cond, $devices)) return false;
    }
    
    $triggered = false;
    foreach ($scenario['actions'] as $action) {
        foreach ($devices as &$d) {
            if ($d['id'] === $action['device']) {
                if (isset($action['state'])) {
                    $d['state'] = $action['state'];
                }
                if (isset($action['value'])) {
                    $d['tag']['value'] = floatval($action['value']);
                }
                $triggered = true;
            }
        }
    }
    
    if ($triggered) {
        log_event('SCENARIO', "Scenario '{$scenario['name']}' déclenché", 'INFO');
    }
    
    return $triggered;
}

if ($method === 'GET') {
    $activeOnly = isset($_GET['active']) && $_GET['active'] === '1';
    
    $result = $scenarios;
    if ($activeOnly) {
        $result = array_filter($result, function($s) { return $s['enabled']; });
    }
    $result = array_values($result);
    
    sendResponse([
        'scenarios' => $result,
        'count' => count($result),
        'ts' => time()
    ]);
}

if ($method === 'POST' || $method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_GET;
    
    if (isset($_GET['id']) || isset($input['id'])) {
        $id = $_GET['id'] ?? $input['id'];
        
        foreach ($scenarios as &$s) {
            if ($s['id'] === $id) {
                if (isset($input['enabled'])) {
                    $s['enabled'] = (bool)$input['enabled'];
                    log_event('SCENARIO', "Scenario '{$s['name']}' " . ($s['enabled'] ? 'activé' : 'désactivé'), 'INFO');
                }
                if (isset($input['trigger'])) {
                    runScenario($s, $devices, $events);
                }
                break;
            }
        }
        file_put_contents($scenariosFile, json_encode($scenarios, JSON_PRETTY_PRINT));
        file_put_contents($devicesFile, json_encode($devices));
        
        sendResponse(['status' => 'OK', 'id' => $id]);
        exit;
    }
    
    if (isset($input['action']) && $input['action'] === 'run') {
        usort($scenarios, function($a, $b) { return $b['priority'] - $a['priority']; });
        
        $triggeredCount = 0;
        foreach ($scenarios as $s) {
            if (runScenario($s, $devices, $events)) {
                $triggeredCount++;
            }
        }
        
        file_put_contents($scenariosFile, json_encode($scenarios, JSON_PRETTY_PRINT));
        file_put_contents($devicesFile, json_encode($devices));
        
        sendResponse(['status' => 'OK', 'triggered' => $triggeredCount]);
        exit;
    }
    
    if (isset($input['create'])) {
        $newScenario = [
            'id' => 'SCN-' . strtoupper(substr(uniqid(), -4)),
            'name' => $input['name'] ?? 'Nouveau Scénario',
            'description' => $input['description'] ?? '',
            'enabled' => $input['enabled'] ?? false,
            'schedule' => $input['schedule'] ?? [
                'type' => 'always_on',
                'days' => [0,1,2,3,4,5,6],
                'start' => '00:00',
                'end' => '23:59'
            ],
            'conditions' => $input['conditions'] ?? [],
            'actions' => $input['actions'] ?? [],
            'priority' => intval($input['priority'] ?? 5),
            'last_triggered' => null,
            'trigger_count' => 0
        ];
        
        $scenarios[] = $newScenario;
        file_put_contents($scenariosFile, json_encode($scenarios, JSON_PRETTY_PRINT));
        
        sendResponse(['status' => 'OK', 'id' => $newScenario['id']]);
        exit;
    }
    
    sendResponse(['error' => 'Invalid action']);
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    
    if ($id) {
        $scenarios = array_filter($scenarios, function($s) use ($id) { return $s['id'] !== $id; });
        $scenarios = array_values($scenarios);
        file_put_contents($scenariosFile, json_encode($scenarios, JSON_PRETTY_PRINT));
        
        sendResponse(['status' => 'OK', 'id' => $id]);
        exit;
    }
    
    sendResponse(['error' => 'Missing id']);
}

sendResponse(['error' => 'Method not allowed']);