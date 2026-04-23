<?php
function simulate_network() {
    $latency = rand(5, 30);
    usleep($latency * 1000);
    
    // Simulation désactivée pour démo stable
    // if (rand(0, 100) < 1) {会导致错误
}

function check_alarm($value, $alarms) {
    if (!is_numeric($value)) return 'NORMAL';
    if ($value >= $alarms['HH']) return 'HH';
    if ($value >= $alarms['H']) return 'H';
    if ($value <= $alarms['LL']) return 'LL';
    if ($value <= $alarms['L']) return 'L';
    return 'NORMAL';
}

function get_quality($device) {
    $ts = $device['tag']['ts'] ?? 0;
    $now = time();
    if ($now - $ts > 30) return 'UNCERTAIN';
    if ($now - $ts > 60) return 'BAD';
    return 'GOOD';
}

function historize($devices) {
    $historyFile = __DIR__ . '/../data/history.json';
    $history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    
    foreach ($devices as $d) {
        $history[] = [
            "id" => $d['id'],
            "name" => $d['name'],
            "floor" => $d['floor'],
            "value" => $d['tag']['value'],
            "unit" => $d['tag']['unit'],
            "ts" => time()
        ];
    }
    
    $history = array_slice($history, -1000);
    file_put_contents($historyFile, json_encode($history));
}

function log_event($type, $message, $severity = 'INFO') {
    $eventsFile = __DIR__ . '/../data/events.json';
    $events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
    
    $events[] = [
        "ts" => time(),
        "type" => $type,
        "message" => $message,
        "severity" => $severity
    ];
    
    $events = array_slice($events, -200);
    file_put_contents($eventsFile, json_encode($events));
}

function apply_control_logic(&$devices) {
    $co2_values = [];
    $vav_values = [];
    $pwr_total = 0;
    $lum_values = [];
    
    foreach ($devices as $i => &$d) {
        $id = $d['id'];
        
        if (strpos($id, 'CO2') === 0) {
            $co2_values[$id] = &$d['tag']['value'];
        }
        if (strpos($id, 'VAV') === 0) {
            $vav_values[$id] = &$d['tag']['value'];
        }
        if (strpos($id, 'PWR') === 0 && strpos($id, 'TOTAL') !== false) {
            $pwr_total = &$d['tag']['value'];
        }
        if (strpos($id, 'LUM') === 0) {
            $lum_values[$id] = &$d['tag']['value'];
        }
    }
    
    $co2_201 = $co2_values['CO2-201'] ?? 0;
    $co2_202 = $co2_values['CO2-202'] ?? 0;
    
    if (!empty($vav_values['VAV-201']) && $co2_201 > 800) {
        $vav_values['VAV-201'] = min(100, $vav_values['VAV-201'] + 2);
    }
    if (!empty($vav_values['VAV-202']) && $co2_202 > 800) {
        $vav_values['VAV-202'] = min(100, $vav_values['VAV-202'] + 2);
    }
    
    if ($pwr_total > 120 && !empty($lum_values)) {
        foreach ($devices as &$d) {
            if (strpos($d['id'], 'LUM') === 0 && $d['state'] === 'AUTO') {
                $d['tag']['value'] = 0;
            }
        }
    }
    
    $noise = rand(-5, 5) / 100;
    foreach ($devices as $i => &$d) {
        // Only add noise to input sensors (AI), not actuator outputs (AO)
        if ($d['tag']['type'] === 'AI') {
            $d['tag']['value'] = $d['tag']['value'] + $noise;
            $d['tag']['value'] = max($d['tag']['min'], min($d['tag']['max'], $d['tag']['value']));
        }
    }
}

function generate_protocol_log($device, $action = 'READ') {
    $protocol = $device['protocol'];
    $name = $device['name'];
    $value = $device['tag']['value'];
    $addr = $device['addr'] ?? '';
    $instance = $device['instance'] ?? '';
    
    if ($protocol === 'bacnet') {
        $objType = $device['tag']['type'];
        if ($action === 'READ') {
            return "[BACNET] READ {$objType}:{$instance} PV → {$value}";
        } else {
            return "[BACNET] WRITE {$objType}:{$instance} PV = {$value}";
        }
    } elseif ($protocol === 'knx') {
        if ($action === 'READ') {
            return "[KNX] GroupValueRead {$addr}";
        } else {
            return "[KNX] GroupValueWrite {$addr} = {$value}";
        }
    } elseif ($protocol === 'mqtt') {
        $topic = "building/{$device['floor']}/" . strtolower($name);
        if ($action === 'READ') {
            return "[MQTT] SUB {$topic}";
        } else {
            return "[MQTT] PUB {$topic} → {$value}";
        }
    }
    return "";
}

function generate_device_fault(&$devices, $faultProbability = 0.02) {
    foreach ($devices as &$device) {
        if (!isset($device['fault']) || !$device['fault']['active']) {
            if (rand(0, 10000) < $faultProbability * 10000) {
                $faultTypes = ['SENSOR_HS', 'NETWORK_LOSS', 'QUALITY_DEGRADED', 'OUT_OF_RANGE'];
                $faultType = $faultTypes[array_rand($faultTypes)];
                
                $device['fault'] = [
                    'active' => true,
                    'type' => $faultType,
                    'ts' => time(),
                    'description' => match($faultType) {
                        'SENSOR_HS' => 'Capteur hors service',
                        'NETWORK_LOSS' => 'Perte communication',
                        'QUALITY_DEGRADED' => 'Qualité dégradée',
                        'OUT_OF_RANGE' => 'Valeur hors plage',
                        default => 'Panne inconnue'
                    }
                ];
                
                if ($faultType === 'SENSOR_HS') {
                    $device['tag']['value'] = 0;
                    $device['state'] = 'OFF';
                } elseif ($faultType === 'QUALITY_DEGRADED') {
                    $device['tag']['ts'] = time() - rand(45, 120);
                } elseif ($faultType === 'OUT_OF_RANGE') {
                    $device['tag']['value'] = $device['tag']['max'] + 10;
                }
                
                $eventsFile = __DIR__ . '/../data/events.json';
                $events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
                $events[] = [
                    'ts' => time(),
                    'type' => 'FAULT',
                    'message' => "{$device['name']} : {$device['fault']['description']}",
                    'severity' => 'ERROR',
                    'device_id' => $device['id'],
                    'fault_type' => $faultType
                ];
                $events = array_slice($events, -200);
                file_put_contents($eventsFile, json_encode($events));
            }
        } else {
            if (rand(0, 100) < 5) {
                if ($device['fault']['type'] === 'NETWORK_LOSS') {
                    $device['state'] = 'AUTO';
                }
                unset($device['fault']);
                
                $eventsFile = __DIR__ . '/../data/events.json';
                $events = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
                $events[] = [
                    'ts' => time(),
                    'type' => 'RECOVERY',
                    'message' => "{$device['name']} : Panne résolue",
                    'severity' => 'INFO',
                    'device_id' => $device['id']
                ];
                $events = array_slice($events, -200);
                file_put_contents($eventsFile, json_encode($events));
            }
        }
    }
    
    return $devices;
}

function check_device_quality($device) {
    if (isset($device['fault']) && $device['fault']['active']) {
        if ($device['fault']['type'] === 'SENSOR_HS') return 'BAD';
        if ($device['fault']['type'] === 'NETWORK_LOSS') return 'BAD';
        if ($device['fault']['type'] === 'QUALITY_DEGRADED') return 'UNCERTAIN';
        if ($device['fault']['type'] === 'OUT_OF_RANGE') return 'UNCERTAIN';
    }
    
    $ts = $device['tag']['ts'] ?? time();
    $age = time() - $ts;
    
    if ($age > 60) return 'BAD';
    if ($age > 30) return 'UNCERTAIN';
    return 'GOOD';
}