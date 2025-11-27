<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Live Chat Message Test</h2>";

// Test database connection
try {
    $test_query = "SELECT COUNT(*) as count FROM live_chat_rooms";
    $stmt = $db->prepare($test_query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "<p style='color: green;'>✓ Database connection successful. Found {$result['count']} chat rooms.</p>";
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</p>";
}

// Test table structure
try {
    $columns_query = "DESCRIBE live_chat_messages";
    $stmt = $db->prepare($columns_query);
    $stmt->execute();
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>live_chat_messages table structure:</h3>";
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    
    $has_encryption = false;
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>{$column['Field']}</td>";
        echo "<td>{$column['Type']}</td>";
        echo "<td>{$column['Null']}</td>";
        echo "<td>{$column['Key']}</td>";
        echo "<td>{$column['Default']}</td>";
        echo "</tr>";
        
        if ($column['Field'] === 'is_encrypted') {
            $has_encryption = true;
        }
    }
    echo "</table>";
    
    if ($has_encryption) {
        echo "<p style='color: green;'>✓ is_encrypted column exists</p>";
    } else {
        echo "<p style='color: orange;'>⚠ is_encrypted column missing</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Failed to check table structure: " . $e->getMessage() . "</p>";
}

// Test message insertion
if ($_POST['test_message'] ?? false) {
    try {
        $user_id = $_SESSION['user_id'];
        $room_id = $_POST['room_id'] ?? 1;
        $message = $_POST['message'] ?? 'Test message';
        
        // Try basic insert first
        $query = "
            INSERT INTO live_chat_messages (room_id, sender_id, message, created_at)
            VALUES (:room_id, :sender_id, :message, NOW())
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':room_id', $room_id);
        $stmt->bindParam(':sender_id', $user_id);
        $stmt->bindParam(':message', $message);
        $stmt->execute();
        
        $message_id = $db->lastInsertId();
        echo "<p style='color: green;'>✓ Message inserted successfully! Message ID: {$message_id}</p>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Failed to insert message: " . $e->getMessage() . "</p>";
    }
}

// Get available rooms
try {
    $rooms_query = "SELECT id, name FROM live_chat_rooms WHERE is_active = TRUE";
    $stmt = $db->prepare($rooms_query);
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Available Rooms:</h3>";
    foreach ($rooms as $room) {
        echo "<p>Room ID: {$room['id']} - {$room['name']}</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Failed to get rooms: " . $e->getMessage() . "</p>";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Live Chat Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { margin: 10px 0; }
        th, td { padding: 8px; text-align: left; }
        form { margin: 20px 0; padding: 20px; border: 1px solid #ccc; }
    </style>
</head>
<body>
    <form method="POST">
        <h3>Test Message Sending</h3>
        <label>Room ID: <input type="number" name="room_id" value="1" required></label><br><br>
        <label>Message: <input type="text" name="message" value="Test message from debug script" required></label><br><br>
        <input type="hidden" name="test_message" value="1">
        <button type="submit">Send Test Message</button>
    </form>
    
    <p><a href="live_chat.php">Return to Live Chat</a></p>
</body>
</html>
