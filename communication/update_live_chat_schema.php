<?php
/*
 * Database Schema Update for Enhanced Live Chat Features
 * This script adds missing columns and indexes for the new chat features
 */

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Updating Live Chat Database Schema...</h2>";
    
    // Add is_encrypted column to live_chat_messages table
    $alterQueries = [
        "ALTER TABLE live_chat_messages ADD COLUMN IF NOT EXISTS is_encrypted BOOLEAN DEFAULT FALSE AFTER deleted_at",
        "ALTER TABLE live_chat_messages ADD INDEX IF NOT EXISTS idx_is_encrypted (is_encrypted)",
        
        // Add indexes for better performance
        "ALTER TABLE live_chat_messages ADD INDEX IF NOT EXISTS idx_room_sender (room_id, sender_id)",
        "ALTER TABLE live_chat_messages ADD INDEX IF NOT EXISTS idx_created_deleted (created_at, is_deleted)",
        
        // Ensure file_size column exists for enhanced file sharing
        "ALTER TABLE live_chat_messages MODIFY COLUMN file_size BIGINT NULL",
        
        // Add indexes for message reactions
        "ALTER TABLE live_chat_message_reactions ADD INDEX IF NOT EXISTS idx_message_emoji (message_id, reaction_emoji)",
        
        // Add indexes for user status
        "ALTER TABLE live_chat_user_status ADD INDEX IF NOT EXISTS idx_status_activity (status, last_activity)",
        
        // Add indexes for message reads
        "ALTER TABLE live_chat_message_reads ADD INDEX IF NOT EXISTS idx_user_read (user_id, read_at)",
        
        // Add indexes for reports
        "ALTER TABLE live_chat_reports ADD INDEX IF NOT EXISTS idx_status_created (status, created_at)",
        "ALTER TABLE live_chat_reports ADD INDEX IF NOT EXISTS idx_room_type (room_id, report_type)"
    ];
    
    foreach ($alterQueries as $query) {
        try {
            runDdlSafely($db, $query);
            echo "<p style='color: green;'>✓ " . htmlspecialchars($query) . "</p>";
        } catch (PDOException $e) {
            // Check if it's just a "column already exists" or "index already exists" error
            if (strpos($e->getMessage(), 'Duplicate column') !== false || 
                strpos($e->getMessage(), 'Duplicate key') !== false ||
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "<p style='color: orange;'>⚠ " . htmlspecialchars($query) . " (already exists)</p>";
            } else {
                echo "<p style='color: red;'>✗ " . htmlspecialchars($query) . " - Error: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
    }
    
    // Update existing messages to have encryption flag
    try {
        $updateQuery = "UPDATE live_chat_messages SET is_encrypted = FALSE WHERE is_encrypted IS NULL";
        $db->exec($updateQuery);
        echo "<p style='color: green;'>✓ Updated existing messages with encryption flag</p>";
    } catch (PDOException $e) {
        echo "<p style='color: red;'>✗ Failed to update existing messages: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
    
    echo "<h3 style='color: green;'>Database schema update completed!</h3>";
    echo "<p><a href='live_chat.php'>Return to Live Chat</a></p>";
    
} catch (Exception $e) {
    echo "<h3 style='color: red;'>Error updating database schema:</h3>";
    echo "<p style='color: red;'>" . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Chat Schema Update</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h2 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        p {
            margin: 5px 0;
            padding: 5px;
            border-radius: 3px;
        }
        a {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 5px;
        }
        a:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- PHP output will be displayed here -->
    </div>
</body>
</html>
