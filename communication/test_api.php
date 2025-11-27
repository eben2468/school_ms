<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Live Chat API Test</h2>";

// Test basic API call
if ($_POST['test_api'] ?? false) {
    $action = $_POST['action'] ?? 'send_message';
    $room_id = $_POST['room_id'] ?? 1;
    $message = $_POST['message'] ?? 'Test message';
    
    echo "<h3>Testing API Call...</h3>";
    
    // Simulate the API call
    $_POST['action'] = $action;
    $_POST['room_id'] = $room_id;
    $_POST['message'] = $message;
    
    // Capture output
    ob_start();
    
    try {
        include 'live_chat_api.php';
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    $output = ob_get_clean();
    
    echo "<h4>API Response:</h4>";
    echo "<pre style='background: #f5f5f5; padding: 10px; border: 1px solid #ccc;'>";
    echo htmlspecialchars($output);
    echo "</pre>";
    
    // Try to parse as JSON
    $json_data = json_decode($output, true);
    if ($json_data) {
        echo "<h4>Parsed JSON:</h4>";
        echo "<pre style='background: #e8f5e8; padding: 10px; border: 1px solid #4CAF50;'>";
        print_r($json_data);
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>⚠ Response is not valid JSON</p>";
    }
}

// Check user session
echo "<h3>Session Information:</h3>";
echo "<p>User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "</p>";
echo "<p>User Role: " . ($_SESSION['role'] ?? 'Not set') . "</p>";
echo "<p>User Name: " . ($_SESSION['name'] ?? 'Not set') . "</p>";

// Check available rooms
try {
    $rooms_query = "SELECT id, name, room_type FROM live_chat_rooms WHERE is_active = TRUE";
    $stmt = $db->prepare($rooms_query);
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Available Rooms:</h3>";
    if (empty($rooms)) {
        echo "<p style='color: orange;'>No active rooms found</p>";
    } else {
        foreach ($rooms as $room) {
            echo "<p>Room {$room['id']}: {$room['name']} ({$room['room_type']})</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error getting rooms: " . $e->getMessage() . "</p>";
}

// Check user access to room 1
if (!empty($rooms)) {
    $test_room_id = $rooms[0]['id'];
    echo "<h3>Testing Access to Room {$test_room_id}:</h3>";
    
    try {
        // Test hasRoomAccess function
        $access_query = "
            SELECT r.room_type, p.user_id as is_participant
            FROM live_chat_rooms r
            LEFT JOIN live_chat_participants p ON r.id = p.room_id AND p.user_id = :user_id AND p.is_banned = FALSE
            WHERE r.id = :room_id AND r.is_active = TRUE
        ";
        
        $stmt = $db->prepare($access_query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':room_id', $test_room_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            echo "<p>Room Type: {$result['room_type']}</p>";
            echo "<p>Is Participant: " . ($result['is_participant'] ? 'Yes' : 'No') . "</p>";
            
            $has_access = false;
            if ($result['room_type'] === 'public') {
                $has_access = true;
                echo "<p style='color: green;'>✓ Access granted (public room)</p>";
            } elseif ($result['room_type'] === 'admin_only') {
                $has_access = in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal']);
                echo "<p style='color: " . ($has_access ? 'green' : 'red') . ";'>" . ($has_access ? '✓' : '✗') . " Admin access " . ($has_access ? 'granted' : 'denied') . "</p>";
            } else {
                $has_access = $result['is_participant'] !== null;
                echo "<p style='color: " . ($has_access ? 'green' : 'red') . ";'>" . ($has_access ? '✓' : '✗') . " Participant access " . ($has_access ? 'granted' : 'denied') . "</p>";
            }
        } else {
            echo "<p style='color: red;'>✗ Room not found or inactive</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>Error checking access: " . $e->getMessage() . "</p>";
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Live Chat API Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        form { margin: 20px 0; padding: 20px; border: 1px solid #ccc; background: #f9f9f9; }
        input, select, button { margin: 5px; padding: 8px; }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <form method="POST">
        <h3>Test API Call</h3>
        <label>Action: 
            <select name="action">
                <option value="send_message">Send Message</option>
                <option value="get_messages">Get Messages</option>
                <option value="debug_user_access">Debug User Access</option>
            </select>
        </label><br>
        
        <label>Room ID: <input type="number" name="room_id" value="<?php echo $rooms[0]['id'] ?? 1; ?>" required></label><br>
        
        <label>Message: <input type="text" name="message" value="Test message from API test" required></label><br>
        
        <input type="hidden" name="test_api" value="1">
        <button type="submit">Test API</button>
    </form>
    
    <p><a href="live_chat.php">Return to Live Chat</a></p>
</body>
</html>
