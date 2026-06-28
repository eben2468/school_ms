<?php
/**
 * SMS Notification Triggers Migration
 * Adds SMS notification trigger columns to school_settings table
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>SMS Notifications Migration</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }
        h1 {
            color: #2d3748;
            font-size: 28px;
            margin-bottom: 10px;
            text-align: center;
        }
        .subtitle {
            color: #718096;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .status {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .status.success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        .status.error {
            background: #fed7d7;
            color: #742a2a;
            border: 1px solid #fc8181;
        }
        .status.info {
            background: #bee3f8;
            color: #2c5282;
            border: 1px solid #90cdf4;
        }
        .icon {
            font-size: 20px;
        }
        .btn {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            margin-top: 20px;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .details {
            background: #f7fafc;
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
            font-size: 13px;
            color: #4a5568;
        }
        .details ul {
            list-style: none;
            padding-left: 0;
        }
        .details li {
            padding: 5px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .details li:last-child {
            border-bottom: none;
        }
        .details li:before {
            content: '✓';
            color: #48bb78;
            font-weight: bold;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>🔔 SMS Notifications Migration</h1>";
echo "<p class='subtitle'>Adding SMS notification trigger columns to database</p>";

try {
    $db->beginTransaction();
    
    $columns_added = [];
    $columns_exist = [];
    
    // Check and add SMS notification trigger columns
    $notification_columns = [
        'sms_absence_alerts' => "ENUM('0','1') DEFAULT '0'",
        'sms_payment_reminders' => "ENUM('0','1') DEFAULT '0'",
        'sms_exam_results' => "ENUM('0','1') DEFAULT '0'",
        'sms_event_announcements' => "ENUM('0','1') DEFAULT '0'",
        'sms_emergency_alerts' => "ENUM('0','1') DEFAULT '0'"
    ];
    
    foreach ($notification_columns as $column => $definition) {
        $check = $db->query("SHOW COLUMNS FROM school_settings LIKE '$column'");
        if ($check->rowCount() == 0) {
            $db->exec("ALTER TABLE school_settings ADD COLUMN $column $definition");
            $columns_added[] = $column;
        } else {
            $columns_exist[] = $column;
        }
    }
    
    $db->commit();
    
    if (count($columns_added) > 0) {
        echo "<div class='status success'>";
        echo "<span class='icon'>✅</span>";
        echo "<div><strong>Migration Successful!</strong><br>Added " . count($columns_added) . " new columns to school_settings table.</div>";
        echo "</div>";
        
        echo "<div class='details'>";
        echo "<strong>Columns Added:</strong>";
        echo "<ul>";
        foreach ($columns_added as $col) {
            echo "<li>" . ucwords(str_replace('_', ' ', $col)) . "</li>";
        }
        echo "</ul>";
        echo "</div>";
    }
    
    if (count($columns_exist) > 0) {
        echo "<div class='status info'>";
        echo "<span class='icon'>ℹ️</span>";
        echo "<div><strong>Already Up to Date</strong><br>" . count($columns_exist) . " columns already exist.</div>";
        echo "</div>";
    }
    
    if (count($columns_added) == 0 && count($columns_exist) > 0) {
        echo "<div class='status success'>";
        echo "<span class='icon'>✅</span>";
        echo "<div><strong>Database Already Migrated</strong><br>All SMS notification columns are present.</div>";
        echo "</div>";
    }
    
    echo "<div style='text-align: center;'>";
    echo "<a href='settings/school.php?tab=sms' class='btn'>Go to SMS Settings →</a>";
    echo "</div>";
    
} catch (PDOException $e) {
    $db->rollBack();
    echo "<div class='status error'>";
    echo "<span class='icon'>❌</span>";
    echo "<div><strong>Migration Failed</strong><br>" . htmlspecialchars($e->getMessage()) . "</div>";
    echo "</div>";
    
    echo "<div class='details'>";
    echo "<strong>Troubleshooting:</strong><br>";
    echo "1. Check database connection settings<br>";
    echo "2. Ensure database user has ALTER TABLE privileges<br>";
    echo "3. Verify school_settings table exists<br>";
    echo "4. Check error log for more details";
    echo "</div>";
}

echo "</div>
</body>
</html>";
?>
