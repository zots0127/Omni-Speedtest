<?php

require_once "./SleekDB/SleekDB.php";
require_once "./config.php";

header('Content-Type: application/json');

function maskLastSegment($ip) {
    $ipaddr = inet_pton($ip);
    if (strlen($ipaddr) == 4) {
        $ipaddr[3] = chr(0);
    } elseif (strlen($ipaddr) == 16) {
        $ipaddr[14] = chr(0);
        $ipaddr[15] = chr(0);
    } else {
        return "";
    }
    return rtrim(inet_ntop($ipaddr),"0")."*";
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'ping':
        $target = filter_var($_POST['target'] ?? '', FILTER_SANITIZE_STRING);
        $port = filter_var($_POST['port'] ?? 80, FILTER_SANITIZE_NUMBER_INT);
        
        if (empty($target)) {
            echo json_encode(['success' => false, 'error' => 'Target is required']);
            exit;
        }
        
        $startTime = microtime(true);
        $errno = 0;
        $errstr = '';
        $timeout = 5;
        
        $socket = @fsockopen($target, $port, $errno, $errstr, $timeout);
        
        if ($socket) {
            $latency = round((microtime(true) - $startTime) * 1000, 2);
            fclose($socket);
            echo json_encode([
                'success' => true,
                'latency' => $latency,
                'target' => $target,
                'port' => $port,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $errstr,
                'errno' => $errno,
                'target' => $target
            ]);
        }
        break;
        
    case 'ping-multiple':
        $targets = json_decode($_POST['targets'] ?? '[]', true);
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $clientIsp = filter_var($_POST['isp'] ?? '', FILTER_SANITIZE_STRING);
        $clientAddr = filter_var($_POST['addr'] ?? '', FILTER_SANITIZE_STRING);
        
        $results = [];
        
        foreach ($targets as $target) {
            $serverName = $target['name'] ?? '';
            $serverHost = $target['host'] ?? '';
            $serverRegion = $target['region'] ?? '';
            $serverCountry = $target['country'] ?? '';
            $serverIsp = $target['isp'] ?? '';
            $port = $target['port'] ?? 80;
            
            if (empty($serverHost)) {
                continue;
            }
            
            $startTime = microtime(true);
            $errno = 0;
            $errstr = '';
            $timeout = 5;
            
            $socket = @fsockopen($serverHost, $port, $errno, $errstr, $timeout);
            
            if ($socket) {
                $latency = round((microtime(true) - $startTime) * 1000, 2);
                fclose($socket);
                
                $results[] = [
                    'success' => true,
                    'name' => $serverName,
                    'host' => $serverHost,
                    'latency' => $latency,
                    'region' => $serverRegion,
                    'country' => $serverCountry,
                    'server_isp' => $serverIsp
                ];
            } else {
                $results[] = [
                    'success' => false,
                    'name' => $serverName,
                    'host' => $serverHost,
                    'error' => $errstr,
                    'region' => $serverRegion,
                    'country' => $serverCountry,
                    'server_isp' => $serverIsp
                ];
            }
        }
        
        $store = \SleekDB\SleekDB::store('pinglogs', './', [
            'auto_cache' => false,
            'timeout' => 120
        ]);
        
        $pingReport = [
            'client_ip' => maskLastSegment($clientIp),
            'client_isp' => $clientIsp,
            'client_addr' => $clientAddr,
            'results' => $results,
            'created' => date('Y-m-d H:i:s', time()),
            'test_type' => 'global'
        ];
        
        $store->insert($pingReport);
        
        echo json_encode([
            'success' => true,
            'results' => $results,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        break;
        
    case 'localhost':
        $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $clientIsp = filter_var($_POST['isp'] ?? '', FILTER_SANITIZE_STRING);
        $clientAddr = filter_var($_POST['addr'] ?? '', FILTER_SANITIZE_STRING);
        
        $serverHost = $_SERVER['HTTP_HOST'];
        $port = 80;
        
        $results = [];
        
        for ($i = 0; $i < 10; $i++) {
            $startTime = microtime(true);
            $errno = 0;
            $errstr = '';
            $timeout = 2;
            
            $socket = @fsockopen('localhost', $port, $errno, $errstr, $timeout);
            
            if ($socket) {
                $latency = round((microtime(true) - $startTime) * 1000, 2);
                fclose($socket);
                $results[] = $latency;
            }
            
            usleep(100000);
        }
        
        if (count($results) > 0) {
            $avgLatency = round(array_sum($results) / count($results), 2);
            $minLatency = round(min($results), 2);
            $maxLatency = round(max($results), 2);
            $jitter = round($maxLatency - $minLatency, 2);
            
            $store = \SleekDB\SleekDB::store('pinglogs', './', [
                'auto_cache' => false,
                'timeout' => 120
            ]);
            
            $pingReport = [
                'client_ip' => maskLastSegment($clientIp),
                'client_isp' => $clientIsp,
                'client_addr' => $clientAddr,
                'results' => [
                    [
                        'name' => 'Localhost Test',
                        'host' => $serverHost,
                        'latency' => $avgLatency,
                        'min_latency' => $minLatency,
                        'max_latency' => $maxLatency,
                        'jitter' => $jitter,
                        'success' => true
                    ]
                ],
                'created' => date('Y-m-d H:i:s', time()),
                'test_type' => 'localhost'
            ];
            
            $store->insert($pingReport);
            
            echo json_encode([
                'success' => true,
                'avg_latency' => $avgLatency,
                'min_latency' => $minLatency,
                'max_latency' => $maxLatency,
                'jitter' => $jitter,
                'samples' => $results,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => 'Failed to ping localhost'
            ]);
        }
        break;
        
    case 'get-logs':
        $store = \SleekDB\SleekDB::store('pinglogs', './', [
            'auto_cache' => false,
            'timeout' => 120
        ]);
        
        $limit = filter_var($_GET['limit'] ?? 100, FILTER_SANITIZE_NUMBER_INT);
        $testType = filter_var($_GET['type'] ?? 'all', FILTER_SANITIZE_STRING);
        
        if ($testType === 'all') {
            $logs = $store->orderBy('desc', '_id')->limit($limit)->fetch();
        } else {
            $logs = $store->where('test_type', '=', $testType)->orderBy('desc', '_id')->limit($limit)->fetch();
        }
        
        echo json_encode([
            'success' => true,
            'data' => $logs,
            'count' => count($logs)
        ]);
        break;
        
    case 'get-stats':
        $store = \SleekDB\SleekDB::store('pinglogs', './', [
            'auto_cache' => false,
            'timeout' => 120
        ]);
        
        $logs = $store->orderBy('desc', '_id')->limit(200)->fetch();
        
        $statsByRegion = [];
        $statsByIsp = [];
        $statsByCountry = [];
        
        foreach ($logs as $log) {
            if (isset($log['results']) && is_array($log['results'])) {
                $clientIsp = $log['client_isp'] ?? 'Unknown';
                $clientAddr = $log['client_addr'] ?? 'Unknown';
                
                foreach ($log['results'] as $result) {
                    if (!$result['success']) {
                        continue;
                    }
                    
                    $region = $result['region'] ?? 'Unknown';
                    $country = $result['country'] ?? 'Unknown';
                    $serverIsp = $result['server_isp'] ?? 'Unknown';
                    $latency = $result['latency'] ?? 0;
                    
                    if (!isset($statsByRegion[$region])) {
                        $statsByRegion[$region] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                    }
                    $statsByRegion[$region]['total'] += $latency;
                    $statsByRegion[$region]['count']++;
                    $statsByRegion[$region]['min'] = min($statsByRegion[$region]['min'], $latency);
                    $statsByRegion[$region]['max'] = max($statsByRegion[$region]['max'], $latency);
                    
                    if (!isset($statsByCountry[$country])) {
                        $statsByCountry[$country] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                    }
                    $statsByCountry[$country]['total'] += $latency;
                    $statsByCountry[$country]['count']++;
                    $statsByCountry[$country]['min'] = min($statsByCountry[$country]['min'], $latency);
                    $statsByCountry[$country]['max'] = max($statsByCountry[$country]['max'], $latency);
                    
                    $ispKey = $clientIsp . ' -> ' . $serverIsp;
                    if (!isset($statsByIsp[$ispKey])) {
                        $statsByIsp[$ispKey] = ['total' => 0, 'count' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                    }
                    $statsByIsp[$ispKey]['total'] += $latency;
                    $statsByIsp[$ispKey]['count']++;
                    $statsByIsp[$ispKey]['min'] = min($statsByIsp[$ispKey]['min'], $latency);
                    $statsByIsp[$ispKey]['max'] = max($statsByIsp[$ispKey]['max'], $latency);
                }
            }
        }
        
        foreach ($statsByRegion as $key => &$stats) {
            $stats['avg'] = round($stats['total'] / $stats['count'], 2);
        }
        foreach ($statsByCountry as $key => &$stats) {
            $stats['avg'] = round($stats['total'] / $stats['count'], 2);
        }
        foreach ($statsByIsp as $key => &$stats) {
            $stats['avg'] = round($stats['total'] / $stats['count'], 2);
        }
        
        echo json_encode([
            'success' => true,
            'stats' => [
                'by_region' => $statsByRegion,
                'by_country' => $statsByCountry,
                'by_isp' => $statsByIsp
            ]
        ]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
