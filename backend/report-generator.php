<?php

require_once "./SleekDB/SleekDB.php";
require_once "./config.php";

header('Content-Type: application/json');

function generateReport($type = 'all') {
    $speedStore = \SleekDB\SleekDB::store('speedlogs', './', [
        'auto_cache' => false,
        'timeout' => 120
    ]);
    
    $pingStore = \SleekDB\SleekDB::store('pinglogs', './', [
        'auto_cache' => false,
        'timeout' => 120
    ]);
    
    $reportStore = \SleekDB\SleekDB::store('reports', './', [
        'auto_cache' => false,
        'timeout' => 120
    ]);
    
    $speedLogs = $speedStore->orderBy('desc', '_id')->limit(200)->fetch();
    $pingLogs = $pingStore->orderBy('desc', '_id')->limit(200)->fetch();
    
    $report = [
        'report_id' => uniqid('report_', true),
        'created' => date('Y-m-d H:i:s'),
        'type' => $type,
        'summary' => [],
        'speed_tests' => [],
        'ping_tests' => [],
        'statistics' => []
    ];
    
    $totalSpeedTests = count($speedLogs);
    $totalPingTests = count($pingLogs);
    
    $avgDownload = 0;
    $avgUpload = 0;
    $avgPing = 0;
    $avgJitter = 0;
    
    $ispStats = [];
    $regionStats = [];
    
    foreach ($speedLogs as $log) {
        $avgDownload += $log['dspeed'] ?? 0;
        $avgUpload += $log['uspeed'] ?? 0;
        $avgPing += $log['ping'] ?? 0;
        $avgJitter += $log['jitter'] ?? 0;
        
        $isp = $log['isp'] ?? 'Unknown';
        $region = $log['addr'] ?? 'Unknown';
        
        if (!isset($ispStats[$isp])) {
            $ispStats[$isp] = [
                'count' => 0,
                'total_download' => 0,
                'total_upload' => 0,
                'total_ping' => 0
            ];
        }
        $ispStats[$isp]['count']++;
        $ispStats[$isp]['total_download'] += $log['dspeed'] ?? 0;
        $ispStats[$isp]['total_upload'] += $log['uspeed'] ?? 0;
        $ispStats[$isp]['total_ping'] += $log['ping'] ?? 0;
        
        if (!isset($regionStats[$region])) {
            $regionStats[$region] = [
                'count' => 0,
                'total_download' => 0,
                'total_upload' => 0,
                'total_ping' => 0
            ];
        }
        $regionStats[$region]['count']++;
        $regionStats[$region]['total_download'] += $log['dspeed'] ?? 0;
        $regionStats[$region]['total_upload'] += $log['uspeed'] ?? 0;
        $regionStats[$region]['total_ping'] += $log['ping'] ?? 0;
        
        $report['speed_tests'][] = [
            'ip' => $log['ip'],
            'isp' => $log['isp'],
            'addr' => $log['addr'],
            'download' => $log['dspeed'],
            'upload' => $log['uspeed'],
            'ping' => $log['ping'],
            'jitter' => $log['jitter'],
            'created' => $log['created']
        ];
    }
    
    if ($totalSpeedTests > 0) {
        $avgDownload = round($avgDownload / $totalSpeedTests, 2);
        $avgUpload = round($avgUpload / $totalSpeedTests, 2);
        $avgPing = round($avgPing / $totalSpeedTests, 2);
        $avgJitter = round($avgJitter / $totalSpeedTests, 2);
    }
    
    foreach ($ispStats as $isp => &$stats) {
        $stats['avg_download'] = round($stats['total_download'] / $stats['count'], 2);
        $stats['avg_upload'] = round($stats['total_upload'] / $stats['count'], 2);
        $stats['avg_ping'] = round($stats['total_ping'] / $stats['count'], 2);
    }
    
    foreach ($regionStats as $region => &$stats) {
        $stats['avg_download'] = round($stats['total_download'] / $stats['count'], 2);
        $stats['avg_upload'] = round($stats['total_upload'] / $stats['count'], 2);
        $stats['avg_ping'] = round($stats['total_ping'] / $stats['count'], 2);
    }
    
    $globalPingStats = [
        'by_region' => [],
        'by_country' => [],
        'by_server' => []
    ];
    
    foreach ($pingLogs as $log) {
        if (isset($log['results']) && is_array($log['results'])) {
            foreach ($log['results'] as $result) {
                if (!$result['success']) {
                    continue;
                }
                
                $region = $result['region'] ?? 'Unknown';
                $country = $result['country'] ?? 'Unknown';
                $serverName = $result['name'] ?? 'Unknown';
                $latency = $result['latency'] ?? 0;
                
                if (!isset($globalPingStats['by_region'][$region])) {
                    $globalPingStats['by_region'][$region] = ['count' => 0, 'total' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                }
                $globalPingStats['by_region'][$region]['count']++;
                $globalPingStats['by_region'][$region]['total'] += $latency;
                $globalPingStats['by_region'][$region]['min'] = min($globalPingStats['by_region'][$region]['min'], $latency);
                $globalPingStats['by_region'][$region]['max'] = max($globalPingStats['by_region'][$region]['max'], $latency);
                
                if (!isset($globalPingStats['by_country'][$country])) {
                    $globalPingStats['by_country'][$country] = ['count' => 0, 'total' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                }
                $globalPingStats['by_country'][$country]['count']++;
                $globalPingStats['by_country'][$country]['total'] += $latency;
                $globalPingStats['by_country'][$country]['min'] = min($globalPingStats['by_country'][$country]['min'], $latency);
                $globalPingStats['by_country'][$country]['max'] = max($globalPingStats['by_country'][$country]['max'], $latency);
                
                if (!isset($globalPingStats['by_server'][$serverName])) {
                    $globalPingStats['by_server'][$serverName] = ['count' => 0, 'total' => 0, 'min' => PHP_INT_MAX, 'max' => 0];
                }
                $globalPingStats['by_server'][$serverName]['count']++;
                $globalPingStats['by_server'][$serverName]['total'] += $latency;
                $globalPingStats['by_server'][$serverName]['min'] = min($globalPingStats['by_server'][$serverName]['min'], $latency);
                $globalPingStats['by_server'][$serverName]['max'] = max($globalPingStats['by_server'][$serverName]['max'], $latency);
            }
        }
        
        $report['ping_tests'][] = [
            'client_ip' => $log['client_ip'],
            'client_isp' => $log['client_isp'],
            'client_addr' => $log['client_addr'],
            'test_type' => $log['test_type'],
            'results' => $log['results'],
            'created' => $log['created']
        ];
    }
    
    foreach ($globalPingStats['by_region'] as $region => &$stats) {
        $stats['avg'] = round($stats['total'] / $stats['count'], 2);
    }
    foreach ($globalPingStats['by_country'] as $country => &$stats) {
        $stats['avg'] = round($stats['total'] / $stats['count'], 2);
    }
    foreach ($globalPingStats['by_server'] as $server => &$stats) {
        $stats['avg'] = round($stats['total'] / $stats['count'], 2);
    }
    
    $report['summary'] = [
        'total_speed_tests' => $totalSpeedTests,
        'total_ping_tests' => $totalPingTests,
        'avg_download_speed' => $avgDownload,
        'avg_upload_speed' => $avgUpload,
        'avg_ping' => $avgPing,
        'avg_jitter' => $avgJitter
    ];
    
    $report['statistics'] = [
        'by_isp' => $ispStats,
        'by_region' => $regionStats,
        'global_ping' => $globalPingStats
    ];
    
    $reportStore->insert($report);
    
    return $report;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'generate':
        $type = $_GET['type'] ?? 'all';
        $report = generateReport($type);
        echo json_encode([
            'success' => true,
            'report' => $report
        ]);
        break;
        
    case 'list':
        $reportStore = \SleekDB\SleekDB::store('reports', './', [
            'auto_cache' => false,
            'timeout' => 120
        ]);
        
        $limit = filter_var($_GET['limit'] ?? 50, FILTER_SANITIZE_NUMBER_INT);
        $reports = $reportStore->orderBy('desc', '_id')->limit($limit)->fetch();
        
        $reportList = [];
        foreach ($reports as $report) {
            $reportList[] = [
                'report_id' => $report['report_id'],
                'created' => $report['created'],
                'type' => $report['type'],
                'summary' => $report['summary']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'reports' => $reportList,
            'count' => count($reportList)
        ]);
        break;
        
    case 'get':
        $reportId = filter_var($_GET['report_id'] ?? '', FILTER_SANITIZE_STRING);
        
        if (empty($reportId)) {
            echo json_encode(['success' => false, 'error' => 'Report ID is required']);
            exit;
        }
        
        $reportStore = \SleekDB\SleekDB::store('reports', './', [
            'auto_cache' => false,
            'timeout' => 120
        ]);
        
        $report = $reportStore->where('report_id', '=', $reportId)->fetch();
        
        if (empty($report)) {
            echo json_encode(['success' => false, 'error' => 'Report not found']);
        } else {
            echo json_encode([
                'success' => true,
                'report' => $report[0]
            ]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
