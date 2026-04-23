<?php
$historyFile = __DIR__ . '/../data/history.json';
$alarmsFile = __DIR__ . '/../data/alarms.json';
$eventsFile = __DIR__ . '/../data/events.json';
$scenariosFile = __DIR__ . '/../data/scenarios.json';

$format = $_GET['format'] ?? 'json';
$type = $_GET['type'] ?? 'history';
$device = $_GET['device'] ?? null;
$from = isset($_GET['from']) ? intval($_GET['from']) : null;
$to = isset($_GET['to']) ? intval($_GET['to']) : null;

header('Content-Type: application/json');
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="export_' . $type . '_' . date('Y-m-d') . '.csv"');
}

function sendResponse($data, $format, $type) {
    if ($format === 'csv') {
        $output = fopen('php://output', 'w');
        
        if ($type === 'history') {
            fputcsv($output, ['timestamp', 'date', 'device_id', 'name', 'value', 'unit']);
            foreach ($data as $row) {
                fputcsv($output, [$row['ts'], date('Y-m-d H:i:s', $row['ts']), $row['id'], $row['name'], $row['value'], $row['unit']]);
            }
        } elseif ($type === 'alarms') {
            fputcsv($output, ['id', 'device_id', 'name', 'severity', 'value', 'threshold', 'unit', 'ack', 'ack_by', 'ts']);
            foreach ($data as $row) {
                fputcsv($output, [$row['id'], $row['device_id'], $row['name'], $row['severity'], $row['value'], $row['threshold'], $row['unit'], $row['ack'] ? '1' : '0', $row['ack_by'] ?? '', $row['ts']]);
            }
        } elseif ($type === 'events') {
            fputcsv($output, ['timestamp', 'date', 'type', 'message', 'severity']);
            foreach ($data as $row) {
                fputcsv($output, [$row['ts'], date('Y-m-d H:i:s', $row['ts']), $row['type'], $row['message'], $row['severity']]);
            }
        } elseif ($type === 'scenarios') {
            fputcsv($output, ['id', 'name', 'description', 'enabled', 'priority', 'last_triggered', 'trigger_count']);
            foreach ($data as $row) {
                fputcsv($output, [$row['id'], $row['name'], $row['description'] ?? '', $row['enabled'] ? '1' : '0', $row['priority'], $row['last_triggered'] ?? '', $row['trigger_count'] ?? 0]);
            }
        }
        
        fclose($output);
    } else {
        echo json_encode($data, JSON_PRETTY_PRINT);
    }
    exit;
}

if ($type === 'history') {
    $data = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    
    if ($device) {
        $data = array_filter($data, function($d) use ($device) { return $d['id'] === $device; });
    }
    if ($from) {
        $data = array_filter($data, function($d) use ($from) { return $d['ts'] >= $from; });
    }
    if ($to) {
        $data = array_filter($data, function($d) use ($to) { return $d['ts'] <= $to; });
    }
    
    $data = array_values($data);
    usort($data, function($a, $b) { return $a['ts'] - $b['ts']; });
    
    sendResponse($data, $format, $type);
}

if ($type === 'alarms') {
    $data = file_exists($alarmsFile) ? json_decode(file_get_contents($alarmsFile), true) : [];
    
    if ($device) {
        $data = array_filter($data, function($d) use ($device) { return $d['device_id'] === $device; });
    }
    
    $data = array_values($data);
    usort($data, function($a, $b) { return $b['ts'] - $a['ts']; });
    
    sendResponse($data, $format, $type);
}

if ($type === 'events') {
    $data = file_exists($eventsFile) ? json_decode(file_get_contents($eventsFile), true) : [];
    
    if ($from) {
        $data = array_filter($data, function($d) use ($from) { return $d['ts'] >= $from; });
    }
    if ($to) {
        $data = array_filter($data, function($d) use ($to) { return $d['ts'] <= $to; });
    }
    
    $data = array_values($data);
    usort($data, function($a, $b) { return $b['ts'] - $a['ts']; });
    
    sendResponse($data, $format, $type);
}

if ($type === 'scenarios') {
    $data = file_exists($scenariosFile) ? json_decode(file_get_contents($scenariosFile), true) : [];
    sendResponse($data, $format, $type);
}

echo json_encode(['error' => 'Invalid type']);