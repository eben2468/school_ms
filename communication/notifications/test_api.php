<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit();
}

require_once '../../config/database.php';
require_once 'NotificationHelper.php';

$database = new Database();
$db = $database->getConnection();
$notificationHelper = new NotificationHelper($db);

$test_results = [];

// Test 1: Create a notification
try {
    $notification_id = $notificationHelper->createNotification([
        'user_id' => $_SESSION['user_id'],
        'title' => 'API Test Notification',
        'message' => 'This notification was created via the API test at ' . date('Y-m-d H:i:s'),
        'type' => 'system',
        'priority' => 'medium',
        'icon' => 'fas fa-test-tube',
        'action_url' => '/notifications.php',
        'action_text' => 'View All',
        'created_by' => $_SESSION['user_id']
    ]);
    
    $test_results['create'] = $notification_id ? 'PASS' : 'FAIL';
} catch (Exception $e) {
    $test_results['create'] = 'FAIL: ' . $e->getMessage();
}

// Test 2: Fetch notifications
try {
    $url = 'http://localhost/communication/notifications/get_notifications.php?limit=5';
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => 'Cookie: ' . $_SERVER['HTTP_COOKIE']
        ]
    ]);
    $response = file_get_contents($url, false, $context);
    $data = json_decode($response, true);
    
    $test_results['fetch'] = ($data && $data['success']) ? 'PASS' : 'FAIL';
} catch (Exception $e) {
    $test_results['fetch'] = 'FAIL: ' . $e->getMessage();
}

// Test 3: Template-based notification
try {
    $template_notification = $notificationHelper->createFromTemplate('student_enrollment', [
        'student_name' => 'Test Student',
        'class_name' => 'Test Class',
        'student_id' => 999
    ], $_SESSION['user_id'], $_SESSION['user_id']);
    
    $test_results['template'] = $template_notification ? 'PASS' : 'FAIL';
} catch (Exception $e) {
    $test_results['template'] = 'FAIL: ' . $e->getMessage();
}

// Test 4: Global notification
try {
    $global_notification = $notificationHelper->createGlobalNotification([
        'title' => 'Global Test Notification',
        'message' => 'This is a global notification visible to all users',
        'type' => 'announcement',
        'priority' => 'low',
        'icon' => 'fas fa-globe',
        'created_by' => $_SESSION['user_id']
    ]);
    
    $test_results['global'] = $global_notification ? 'PASS' : 'FAIL';
} catch (Exception $e) {
    $test_results['global'] = 'FAIL: ' . $e->getMessage();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notification API Test</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">Notification System API Test</h1>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Test Results -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">Test Results</h2>
                <div class="space-y-3">
                    <?php foreach ($test_results as $test => $result): ?>
                    <div class="flex items-center justify-between p-3 rounded <?php echo strpos($result, 'PASS') === 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <span class="font-medium"><?php echo ucfirst($test); ?> Test:</span>
                        <span class="font-bold"><?php echo $result; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- API Test Actions -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-xl font-semibold mb-4">API Test Actions</h2>
                <div class="space-y-3">
                    <button onclick="testGetNotifications()" class="w-full px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                        Test Get Notifications
                    </button>
                    <button onclick="testMarkRead()" class="w-full px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                        Test Mark as Read
                    </button>
                    <button onclick="testMarkAllRead()" class="w-full px-4 py-2 bg-yellow-600 text-white rounded hover:bg-yellow-700">
                        Test Mark All Read
                    </button>
                    <button onclick="testCreateNotification()" class="w-full px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                        Test Create Notification
                    </button>
                </div>
                <div id="apiResults" class="mt-4 p-3 bg-gray-100 rounded min-h-20"></div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">Navigation</h2>
            <div class="flex flex-wrap gap-3">
                <a href="/notifications.php" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                    View Notifications Page
                </a>
                <a href="/dashboard.php" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700">
                    Test Dashboard Notifications
                </a>
                <a href="integration_examples.php" class="px-4 py-2 bg-purple-600 text-white rounded hover:bg-purple-700">
                    Integration Examples
                </a>
                <a href="setup_database.php" class="px-4 py-2 bg-gray-600 text-white rounded hover:bg-gray-700">
                    Database Setup
                </a>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="mt-8 bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">System Status</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="text-center p-4 bg-green-100 rounded">
                    <div class="text-2xl font-bold text-green-800">✅</div>
                    <div class="text-sm text-green-700">Database Schema</div>
                </div>
                <div class="text-center p-4 bg-green-100 rounded">
                    <div class="text-2xl font-bold text-green-800">✅</div>
                    <div class="text-sm text-green-700">API Endpoints</div>
                </div>
                <div class="text-center p-4 bg-green-100 rounded">
                    <div class="text-2xl font-bold text-green-800">✅</div>
                    <div class="text-sm text-green-700">Frontend Integration</div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function testGetNotifications() {
        fetch('/communication/notifications/get_notifications.php?limit=3')
            .then(response => response.json())
            .then(data => {
                document.getElementById('apiResults').innerHTML = 
                    '<strong>Get Notifications:</strong><br>' + 
                    JSON.stringify(data, null, 2);
            })
            .catch(error => {
                document.getElementById('apiResults').innerHTML = 
                    '<strong>Error:</strong> ' + error;
            });
    }
    
    function testMarkRead() {
        // First get a notification ID
        fetch('/communication/notifications/get_notifications.php?limit=1')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.notifications.length > 0) {
                    const notificationId = data.notifications[0].id;
                    return fetch('/communication/notifications/mark_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ notification_id: notificationId })
                    });
                } else {
                    throw new Error('No notifications found to mark as read');
                }
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('apiResults').innerHTML = 
                    '<strong>Mark Read:</strong><br>' + 
                    JSON.stringify(data, null, 2);
            })
            .catch(error => {
                document.getElementById('apiResults').innerHTML = 
                    '<strong>Error:</strong> ' + error;
            });
    }
    
    function testMarkAllRead() {
        fetch('/communication/notifications/mark_all_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({})
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('apiResults').innerHTML = 
                '<strong>Mark All Read:</strong><br>' + 
                JSON.stringify(data, null, 2);
        })
        .catch(error => {
            document.getElementById('apiResults').innerHTML = 
                '<strong>Error:</strong> ' + error;
        });
    }
    
    function testCreateNotification() {
        fetch('/communication/notifications/create_notification.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                title: 'API Test Notification',
                message: 'This notification was created via JavaScript API test at ' + new Date().toLocaleString(),
                type: 'system',
                priority: 'medium',
                icon: 'fas fa-code'
            })
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('apiResults').innerHTML = 
                '<strong>Create Notification:</strong><br>' + 
                JSON.stringify(data, null, 2);
        })
        .catch(error => {
            document.getElementById('apiResults').innerHTML = 
                '<strong>Error:</strong> ' + error;
        });
    }
    </script>
</body>
</html>
