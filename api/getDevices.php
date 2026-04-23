<?php
require __DIR__ . '/simulate.php';

simulate_network();

$devicesFile = __DIR__ . '/../data/devices.json';
$scenariosFile = __DIR__ . '/../data/scenarios.json';
$alarmsFile = __DIR__ . '/../data/alarms.json';
$raw = file_get_contents($devicesFile);
$devices = json_decode($raw, true);

$scenarios = file_exists($scenariosFile) ? json_decode(file_get_contents($scenariosFile), true) : [];
$alarms = file_exists($alarmsFile) ? json_decode(file_get_contents($alarmsFile), true) : [];

// Fault generation disabled for stable demo
// $devices = generate_device_fault($devices, 0.001);

apply_control_logic($devices);

$now = time();
foreach ($devices as &$d) {
    $d['tag']['ts'] = $now;
    $d['tag']['quality'] = check_device_quality($d);
    $alarm = check_alarm($d['tag']['value'], $d['tag']['alarms'] ?? []);
    $d['alarm'] = $alarm;
    
    if ($alarm !== 'NORMAL' && $d['state'] === 'AUTO') {
        $exists = false;
        foreach ($alarms as $a) {
            if ($a['device_id'] === $d['id'] && !$a['ack']) {
                $exists = true;
                break;
            }
        }
        
        if (!$exists) {
            $newAlarm = [
                'id' => 'ALM-' . strtoupper(substr(uniqid(), -6)),
                'device_id' => $d['id'],
                'name' => $d['name'] . ' - ' . strtoupper($alarm),
                'priority' => $alarm === 'HH' ? 1 : ($alarm === 'H' ? 2 : ($alarm === 'L' ? 3 : 4)),
                'severity' => $alarm,
                'value' => $d['tag']['value'],
                'threshold' => $d['tag']['alarms'][$alarm] ?? 0,
                'unit' => $d['tag']['unit'],
                'ts' => $now,
                'floor' => $d['floor'] ?? null,
                'category' => $d['category'] ?? null,
                'quality' => $d['tag']['quality'],
                'ack' => false,
                'ack_by' => null,
                'ack_ts' => null,
                'shelved' => false,
                'shelve_until' => null,
                'shelve_duration' => null
            ];
            $alarms[] = $newAlarm;
            log_event('ALARM', "{$d['name']} : {$alarm} ({$d['tag']['value']})", $alarm === 'HH' || $alarm === 'LL' ? 'ALARM' : 'WARNING');
        }
    }
}

foreach ($alarms as $i => $a) {
    if ($a['shelved'] && $a['shelve_until'] && $now > $a['shelve_until']) {
        $alarms[$i]['shelved'] = false;
        $alarms[$i]['shelve_until'] = null;
    }
}
$alarms = array_slice($alarms, -100);
file_put_contents($alarmsFile, json_encode($alarms));

execute_scenarios($devices, $scenarios);

$ts = time();
$needsHistory = false;
foreach ($devices as $d) {
    if (!isset($d['lastHistorize']) || $ts - $d['lastHistorize'] > 10) {
        $needsHistory = true;
        break;
    }
}

if ($needsHistory) {
    historize($devices);
    foreach ($devices as &$d) {
        $d['lastHistorize'] = $ts;
    }
}

file_put_contents($devicesFile, json_encode($devices, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
echo json_encode($devices);

function execute_scenarios(&$devices, $scenarios) {
    $now = time();
    $currentDay = date('w');
    $currentTime = date('H:i');
    
    foreach ($scenarios as &$scenario) {
        if (!$scenario['enabled']) continue;
        
        $schedule = $scenario['schedule'] ?? [];
        $scheduleType = $schedule['type'] ?? 'always_on';
        $scheduleDays = $schedule['days'] ?? [0,1,2,3,4,5,6];
        $scheduleStart = $schedule['start'] ?? '00:00';
        $scheduleEnd = $schedule['end'] ?? '23:59';
        
        $scheduleActive = false;
        
        if ($scheduleType === 'always_on') {
            $scheduleActive = in_array(intval($currentDay), $scheduleDays);
        } elseif ($scheduleType === 'weekly') {
            if (in_array(intval($currentDay), $scheduleDays)) {
                if ($scheduleStart > $scheduleEnd) {
                    $scheduleActive = $currentTime >= $scheduleStart || $currentTime <= $scheduleEnd;
                } else {
                    $scheduleActive = $currentTime >= $scheduleStart && $currentTime <= $scheduleEnd;
                }
            }
        }
        
        if (!$scheduleActive) continue;
        
        $conditionsMet = true;
        foreach ($scenario['conditions'] ?? [] as $condition) {
            $device = array_values(array_filter($devices, function($d) use ($condition) { return $d['id'] === $condition['device']; }));
            if (empty($device)) {
                $conditionsMet = false;
                break;
            }
            $value = $device[0]['tag']['value'];
            $target = floatval($condition['value']);
            $op = $condition['operator'];
            
            $meet = false;
            switch ($op) {
                case '>': $meet = $value > $target; break;
                case '<': $meet = $value < $target; break;
                case '>=': $meet = $value >= $target; break;
                case '<=': $meet = $value <= $target; break;
            }
            if (!$meet) {
                $conditionsMet = false;
                break;
            }
        }
        
        if ($conditionsMet) {
            foreach ($scenario['actions'] ?? [] as $action) {
                foreach ($devices as &$d) {
                    if ($d['id'] === $action['device']) {
                        if (isset($action['state'])) $d['state'] = $action['state'];
                        if (isset($action['value'])) $d['tag']['value'] = floatval($action['value']);
                        
                        $actionValue = isset($action['state']) ? $action['state'] : (isset($action['value']) ? $action['value'] : 'N/A');
                        log_event('SCENARIO', "{$scenario['name']}: {$d['name']} → {$actionValue}", 'INFO');
                    }
                }
            }
            
            $scenario['trigger_count'] = ($scenario['trigger_count'] ?? 0) + 1;
            $scenario['last_triggered'] = $now;
        }
    }
    
    file_put_contents(__DIR__ . '/../data/scenarios.json', json_encode($scenarios, JSON_PRETTY_PRINT));
}