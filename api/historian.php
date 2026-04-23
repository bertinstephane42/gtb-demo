<?php
require __DIR__ . '/simulate.php';

simulate_network();

$historyFile = __DIR__ . '/../data/history.json';

if (!file_exists($historyFile)) {
    header('Content-Type: application/json');
    echo json_encode(["history" => [], "count" => 0]);
    exit;
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$device = isset($_GET['device']) ? $_GET['device'] : null;
$floor = isset($_GET['floor']) ? $_GET['floor'] : null;
$from = isset($_GET['from']) ? intval($_GET['from']) : null;
$to = isset($_GET['to']) ? intval($_GET['to']) : time();
$analyze = isset($_GET['analyze']) ? $_GET['analyze'] : null;

$history = json_decode(file_get_contents($historyFile), true);

if ($device) {
    $history = array_filter($history, function($h) use ($device) { return $h['id'] === $device; });
}

if ($floor) {
    $history = array_filter($history, function($h) use ($floor) { return $h['floor'] === $floor; });
}

if ($from) {
    $history = array_filter($history, function($h) use ($from) { return $h['ts'] >= $from; });
}

if ($to) {
    $history = array_filter($history, function($h) use ($to) { return $h['ts'] <= $to; });
}

$history = array_values($history);
$history = array_slice($history, -$limit);

if ($analyze && count($history) >= 10) {
    $values = array_map(function($h) { return $h['value']; }, $history);
    $count = count($values);
    $mean = array_sum($values) / $count;
    $variance = array_sum(array_map(function($v) use ($mean) { return pow($v - $mean, 2); }, $values)) / $count;
    $stdDev = sqrt($variance);
    
    $recentValues = array_slice($values, -10);
    $recentMean = count($recentValues) > 0 ? array_sum($recentValues) / count($recentValues) : 0;
    $recentStdDev = count($recentValues) > 1 ? sqrt(array_sum(array_map(function($v) use ($recentMean) { return pow($v - $recentMean, 2); }, $recentValues)) / count($recentValues)) : 0;
    
    $trend = 'STABLE';
    if ($recentMean > $mean + $stdDev) $trend = 'RISING';
    elseif ($recentMean < $mean - $stdDev) $trend = 'FALLING';
    
    $anomaly = false;
    $lastValue = end($values);
    if (abs($lastValue - $mean) > 2 * $stdDev) $anomaly = true;
    
    $response = [
        "history" => $history,
        "count" => count($history),
        "analysis" => [
            "mean" => round($mean, 2),
            "stddev" => round($stdDev, 2),
            "min" => min($values),
            "max" => max($values),
            "trend" => $trend,
            "anomaly" => $anomaly,
            "last_value" => $lastValue
        ]
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

header('Content-Type: application/json');
echo json_encode([
    "history" => $history,
    "count" => count($history)
]);