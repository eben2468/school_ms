<?php
// Database column fixes - run this once to fix missing columns
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Database Column Fixes</h2>";
    echo "<pre>";
    
    // Fix 1: Add student_id column to users table
    try {
        $db->exec("ALTER TABLE users ADD COLUMN student_id VARCHAR(50)");
        echo "✓ Added student_id column to users table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ student_id column already exists in users table\n";
        } else {
            echo "✗ Error adding student_id column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix 2: Add read_at column to notifications table
    try {
        $db->exec("ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL");
        echo "✓ Added read_at column to notifications table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ read_at column already exists in notifications table\n";
        } else {
            echo "✗ Error adding read_at column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix 3: Add remarks column to attendance table
    try {
        $db->exec("ALTER TABLE attendance ADD COLUMN remarks TEXT");
        echo "✓ Added remarks column to attendance table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ remarks column already exists in attendance table\n";
        } else {
            echo "✗ Error adding remarks column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix 4: Add teacher_id column to exams table
    try {
        $db->exec("ALTER TABLE exams ADD COLUMN teacher_id INT");
        echo "✓ Added teacher_id column to exams table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ teacher_id column already exists in exams table\n";
        } else {
            echo "✗ Error adding teacher_id column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix 5: Update some users with student_id values for testing
    try {
        $db->exec("UPDATE users SET student_id = CONCAT('STU', LPAD(id, 4, '0')) WHERE role = 'student' AND (student_id IS NULL OR student_id = '')");
        echo "✓ Updated student_id values for existing students\n";
    } catch (PDOException $e) {
        echo "✗ Error updating student_id values: " . $e->getMessage() . "\n";
    }
    
    // Fix 6: Add section column to classes table
    try {
        $db->exec("ALTER TABLE classes ADD COLUMN section VARCHAR(10)");
        echo "✓ Added section column to classes table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ section column already exists in classes table\n";
        } else {
            echo "✗ Error adding section column: " . $e->getMessage() . "\n";
        }
    }

    // Fix 7: Create chat tables for live chat feature
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_conversations (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            support_agent_id INT NULL,
            subject VARCHAR(255) NOT NULL,
            status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (support_agent_id) REFERENCES users(id) ON DELETE SET NULL
        )");
        echo "✓ Created chat_conversations table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating chat_conversations table: " . $e->getMessage() . "\n";
    }
    
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_messages (
            id INT PRIMARY KEY AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            sender_id INT NOT NULL,
            message TEXT NOT NULL,
            message_type ENUM('text', 'file', 'system') DEFAULT 'text',
            file_path VARCHAR(500) NULL,
            is_read BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        echo "✓ Created chat_messages table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating chat_messages table: " . $e->getMessage() . "\n";
    }
    
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS chat_typing (
            id INT PRIMARY KEY AUTO_INCREMENT,
            conversation_id INT NOT NULL,
            user_id INT NOT NULL,
            is_typing BOOLEAN DEFAULT TRUE,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_conversation_user (conversation_id, user_id)
        )");
        echo "✓ Created chat_typing table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating chat_typing table: " . $e->getMessage() . "\n";
    }

    // Fix 8: Create assignments table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS assignments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            subject_id INT,
            teacher_id INT,
            class_id INT,
            due_date DATETIME NOT NULL,
            attachment_path VARCHAR(500),
            status ENUM('active', 'inactive', 'draft') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
        )");
        echo "✓ Created assignments table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating assignments table: " . $e->getMessage() . "\n";
    }

    // Fix 9: Create assignment_submissions table
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS assignment_submissions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            assignment_id INT NOT NULL,
            student_id INT NOT NULL,
            submission_text TEXT,
            attachment_path VARCHAR(500),
            status ENUM('submitted', 'late', 'graded') DEFAULT 'submitted',
            grade VARCHAR(10),
            feedback TEXT,
            submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            graded_at TIMESTAMP NULL,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_assignment_student (assignment_id, student_id)
        )");
        echo "✓ Created assignment_submissions table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating assignment_submissions table: " . $e->getMessage() . "\n";
    }

    // Fix 10: Create parent_students table if it doesn't exist
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS parent_students (
            id INT PRIMARY KEY AUTO_INCREMENT,
            parent_id INT NOT NULL,
            student_id INT NOT NULL,
            relationship ENUM('father', 'mother', 'guardian', 'other') DEFAULT 'guardian',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_parent_student (parent_id, student_id)
        )");
        echo "✓ Created parent_students table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating parent_students table: " . $e->getMessage() . "\n";
    }

    // Fix 11: Add class_id column to users table if missing
    try {
        $db->exec("ALTER TABLE users ADD COLUMN class_id INT");
        echo "✓ Added class_id column to users table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ class_id column already exists in users table\n";
        } else {
            echo "✗ Error adding class_id column: " . $e->getMessage() . "\n";
        }
    }

    echo "\n✅ Database fixes completed successfully!\n";
    echo "</pre>";

    echo "<p><a href='help.php'>Go to Help Page</a> | <a href='dashboard.php'>Go to Dashboard</a> | <a href='parent/assignments.php'>Test Assignments Page</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>Database Connection Error</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
