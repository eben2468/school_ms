<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>School Management System - Database Setup</h1>

    <?php
    $host = 'localhost';
    $username = 'root';
    $password = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
        try {
            echo "<div class='info'>Starting database setup...</div>";

            // Create connection
            $conn = new PDO("mysql:host=$host", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            echo "<div class='success'>✅ Database connection successful</div>";

            // Read and execute schema
            if (file_exists('config/schema.sql')) {
                echo "<div class='info'>📖 Reading schema file...</div>";
                $sql = file_get_contents('config/schema.sql');

                echo "<div class='info'>⚙️ Executing SQL commands...</div>";
                $conn->exec($sql);

                echo "<div class='success'>✅ Database setup completed successfully!</div>";
                echo "<div class='info'>🎉 You can now use the student enrollment system.</div>";
                echo "<p><a href='students/enroll.php'>Go to Student Enrollment</a> | <a href='check_database.php'>Check Database Status</a></p>";

            } else {
                echo "<div class='error'>❌ Schema file 'config/schema.sql' not found!</div>";
            }

        } catch(PDOException $e) {
            echo "<div class='error'>❌ Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
            echo "<div class='info'>💡 Make sure XAMPP MySQL is running and try again.</div>";
        }
    } else {
        ?>
        <div class="info">
            <p>This will set up the school management system database with all required tables and sample data.</p>
            <p><strong>Prerequisites:</strong></p>
            <ul>
                <li>XAMPP MySQL server must be running</li>
                <li>MySQL should be accessible with root user (no password)</li>
            </ul>
        </div>

        <form method="POST">
            <button type="submit" name="setup" style="background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer;">
                🚀 Setup Database
            </button>
        </form>

        <p><a href="check_database.php">Check Current Database Status</a></p>
        <?php
    }
    ?>
</body>
</html>