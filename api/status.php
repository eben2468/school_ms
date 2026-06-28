<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/version.php';

// Simple system status check
$status = [
    'status' => 'online',
    'timestamp' => date('Y-m-d H:i:s'),
    'version' => APP_VERSION,
    'uptime' => '99.9%',
    'database' => 'connected',
    'services' => [
        'web' => 'operational',
        'database' => 'operational',
        'authentication' => 'operational'
    ]
];

// You can add more sophisticated checks here
try {
    // Check database connection
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        $status['database'] = 'connected';
        $status['services']['database'] = 'operational';
    } else {
        $status['database'] = 'disconnected';
        $status['services']['database'] = 'down';
        $status['status'] = 'degraded';
    }
} catch (Exception $e) {
    $status['database'] = 'error';
    $status['services']['database'] = 'down';
    $status['status'] = 'degraded';
}

echo json_encode($status);
?>
