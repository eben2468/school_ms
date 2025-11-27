<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Starting database fixes...\n";

    // Create school_settings table if it doesn't exist
    $sql = "CREATE TABLE IF NOT EXISTS school_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        school_name VARCHAR(255) NOT NULL DEFAULT 'Greenwood Academy',
        school_address TEXT,
        school_phone VARCHAR(20),
        school_email VARCHAR(100),
        school_website VARCHAR(255),
        principal_name VARCHAR(100),
        academic_year_start DATE,
        academic_year_end DATE,
        currency VARCHAR(10) DEFAULT 'GHS',
        timezone VARCHAR(50) DEFAULT 'Africa/Accra',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $db->exec($sql);
    echo "✓ Created school_settings table\n";

    // Fix hostel_blocks table - ensure it has the correct columns
    try {
        $db->exec("ALTER TABLE hostel_blocks ADD COLUMN name VARCHAR(100)");
        echo "✓ Added name column to hostel_blocks table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Name column already exists in hostel_blocks table\n";
        } else {
            echo "✗ Error adding name column to hostel_blocks: " . $e->getMessage() . "\n";
        }
    }

    // Update name column from block_name if it exists
    try {
        $db->exec("UPDATE hostel_blocks SET name = COALESCE(block_name, CONCAT('Block ', id)) WHERE name IS NULL OR name = ''");
        echo "✓ Updated hostel_blocks name column\n";
    } catch (PDOException $e) {
        echo "✗ Error updating hostel_blocks name: " . $e->getMessage() . "\n";
    }

    // Add missing visit_date column to health_records table
    try {
        $db->exec("ALTER TABLE health_records ADD COLUMN visit_date DATE");
        echo "✓ Added visit_date column to health_records table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Visit_date column already exists in health_records table\n";
        } else {
            echo "✗ Error adding visit_date column to health_records: " . $e->getMessage() . "\n";
        }
    }

    // Update visit_date from record_date
    try {
        $db->exec("UPDATE health_records SET visit_date = record_date WHERE visit_date IS NULL");
        echo "✓ Updated health_records visit_date column\n";
    } catch (PDOException $e) {
        echo "✗ Error updating health_records visit_date: " . $e->getMessage() . "\n";
    }

    // Fix canteen_inventory table - ensure unit_price column exists
    try {
        $db->exec("ALTER TABLE canteen_inventory ADD COLUMN unit_price DECIMAL(8,2) DEFAULT 0.00");
        echo "✓ Added unit_price column to canteen_inventory table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Unit_price column already exists in canteen_inventory table\n";
        } else {
            echo "✗ Error adding unit_price column to canteen_inventory: " . $e->getMessage() . "\n";
        }
    }

    echo "\nDatabase fixes completed successfully!\n";
    
} catch (Exception $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "\n";
}
?>
