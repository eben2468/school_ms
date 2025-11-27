<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Starting database updates...\n";
    
    // Add missing status column to assignments table
    try {
        $db->exec("ALTER TABLE assignments ADD COLUMN status ENUM('active', 'inactive', 'completed') DEFAULT 'active'");
        echo "✓ Added status column to assignments table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Status column already exists in assignments table\n";
        } else {
            echo "✗ Error adding status column to assignments: " . $e->getMessage() . "\n";
        }
    }
    
    // Add missing columns to exams table
    try {
        $db->exec("ALTER TABLE exams ADD COLUMN title VARCHAR(255) NOT NULL DEFAULT ''");
        echo "✓ Added title column to exams table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Title column already exists in exams table\n";
        } else {
            echo "✗ Error adding title column to exams: " . $e->getMessage() . "\n";
        }
    }
    
    try {
        $db->exec("ALTER TABLE exams ADD COLUMN exam_date DATE");
        echo "✓ Added exam_date column to exams table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Exam_date column already exists in exams table\n";
        } else {
            echo "✗ Error adding exam_date column to exams: " . $e->getMessage() . "\n";
        }
    }
    
    try {
        $db->exec("ALTER TABLE exams ADD COLUMN duration INT NOT NULL DEFAULT 60");
        echo "✓ Added duration column to exams table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Duration column already exists in exams table\n";
        } else {
            echo "✗ Error adding duration column to exams: " . $e->getMessage() . "\n";
        }
    }
    
    // Update exam_date to match date column for existing records
    try {
        $db->exec("UPDATE exams SET exam_date = date WHERE exam_date IS NULL");
        $db->exec("UPDATE exams SET title = name WHERE title = ''");
        echo "✓ Updated existing exam records\n";
    } catch (PDOException $e) {
        echo "✗ Error updating exam records: " . $e->getMessage() . "\n";
    }
    
    // Add missing columns to book_loans table
    try {
        $db->exec("ALTER TABLE book_loans ADD COLUMN borrower_id INT");
        echo "✓ Added borrower_id column to book_loans table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Borrower_id column already exists in book_loans table\n";
        } else {
            echo "✗ Error adding borrower_id column to book_loans: " . $e->getMessage() . "\n";
        }
    }
    
    try {
        $db->exec("ALTER TABLE book_loans ADD COLUMN loan_date DATE");
        echo "✓ Added loan_date column to book_loans table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Loan_date column already exists in book_loans table\n";
        } else {
            echo "✗ Error adding loan_date column to book_loans: " . $e->getMessage() . "\n";
        }
    }
    
    // Update borrower_id and loan_date for existing records
    try {
        $db->exec("UPDATE book_loans SET borrower_id = user_id WHERE borrower_id IS NULL");
        $db->exec("UPDATE book_loans SET loan_date = borrowed_date WHERE loan_date IS NULL");
        echo "✓ Updated existing book loan records\n";
    } catch (PDOException $e) {
        echo "✗ Error updating book loan records: " . $e->getMessage() . "\n";
    }
    
    // Create class_schedule table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS class_schedule (
            id INT PRIMARY KEY AUTO_INCREMENT,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            teacher_id INT NOT NULL,
            day VARCHAR(20) NOT NULL,
            time_slot VARCHAR(20) NOT NULL,
            room_number VARCHAR(20),
            academic_year VARCHAR(9) NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $db->exec($sql);
        echo "✓ Created class_schedule table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating class_schedule table: " . $e->getMessage() . "\n";
    }

    // Add missing columns to transport_vehicles table
    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN make_model VARCHAR(100)");
        echo "✓ Added make_model column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Make_model column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding make_model column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN year INT");
        echo "✓ Added year column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Year column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding year column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN insurance_number VARCHAR(50)");
        echo "✓ Added insurance_number column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Insurance_number column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding insurance_number column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN insurance_expiry DATE");
        echo "✓ Added insurance_expiry column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Insurance_expiry column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding insurance_expiry column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN registration_expiry DATE");
        echo "✓ Added registration_expiry column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Registration_expiry column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding registration_expiry column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE transport_vehicles ADD COLUMN notes TEXT");
        echo "✓ Added notes column to transport_vehicles table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Notes column already exists in transport_vehicles table\n";
        } else {
            echo "✗ Error adding notes column to transport_vehicles: " . $e->getMessage() . "\n";
        }
    }

    // Add missing columns to transport_routes table
    try {
        $db->exec("ALTER TABLE transport_routes ADD COLUMN description TEXT");
        echo "✓ Added description column to transport_routes table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Description column already exists in transport_routes table\n";
        } else {
            echo "✗ Error adding description column to transport_routes: " . $e->getMessage() . "\n";
        }
    }

    // Create transport_assignments table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS transport_assignments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            vehicle_id INT NOT NULL,
            route_id INT NOT NULL,
            departure_time TIME NOT NULL,
            return_time TIME,
            effective_date DATE NOT NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES transport_vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
        )";
        $db->exec($sql);
        echo "✓ Created transport_assignments table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating transport_assignments table: " . $e->getMessage() . "\n";
    }

    // Add missing columns to library_books table
    try {
        $db->exec("ALTER TABLE library_books ADD COLUMN description TEXT");
        echo "✓ Added description column to library_books table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Description column already exists in library_books table\n";
        } else {
            echo "✗ Error adding description column to library_books: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE library_books ADD COLUMN publisher VARCHAR(100)");
        echo "✓ Added publisher column to library_books table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Publisher column already exists in library_books table\n";
        } else {
            echo "✗ Error adding publisher column to library_books: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE library_books ADD COLUMN publication_year INT");
        echo "✓ Added publication_year column to library_books table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Publication_year column already exists in library_books table\n";
        } else {
            echo "✗ Error adding publication_year column to library_books: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE library_books ADD COLUMN language VARCHAR(50)");
        echo "✓ Added language column to library_books table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Language column already exists in library_books table\n";
        } else {
            echo "✗ Error adding language column to library_books: " . $e->getMessage() . "\n";
        }
    }

    try {
        $db->exec("ALTER TABLE library_books ADD COLUMN location VARCHAR(100)");
        echo "✓ Added location column to library_books table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Location column already exists in library_books table\n";
        } else {
            echo "✗ Error adding location column to library_books: " . $e->getMessage() . "\n";
        }
    }
    
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
        $db->exec("UPDATE hostel_blocks SET name = block_name WHERE name IS NULL AND block_name IS NOT NULL");
        echo "✓ Updated hostel_blocks name column\n";
    } catch (PDOException $e) {
        echo "✗ Error updating hostel_blocks name: " . $e->getMessage() . "\n";
    }

    // Create hostel_students table
    try {
        $sql = "CREATE TABLE IF NOT EXISTS hostel_students (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            block_id INT NOT NULL,
            room_id INT NOT NULL,
            allocation_date DATE NOT NULL,
            checkout_date DATE,
            status ENUM('active', 'checked_out', 'transferred') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (block_id) REFERENCES hostel_blocks(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE
        )";
        $db->exec($sql);
        echo "✓ Created hostel_students table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating hostel_students table: " . $e->getMessage() . "\n";
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

    // Ensure counseling_sessions table exists
    try {
        $sql = "CREATE TABLE IF NOT EXISTS counseling_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            counselor_id INT NOT NULL,
            session_date DATE NOT NULL,
            session_time TIME NOT NULL,
            duration_minutes INT DEFAULT 60,
            session_type ENUM('academic', 'behavioral', 'personal', 'career', 'other') NOT NULL,
            notes TEXT,
            recommendations TEXT,
            follow_up_required BOOLEAN DEFAULT FALSE,
            follow_up_date DATE,
            status ENUM('scheduled', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $db->exec($sql);
        echo "✓ Created counseling_sessions table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating counseling_sessions table: " . $e->getMessage() . "\n";
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

    // Create support_tickets table for support.php
    try {
        $sql = "CREATE TABLE IF NOT EXISTS support_tickets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
            assigned_to INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        )";
        $db->exec($sql);
        echo "✓ Created support_tickets table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating support_tickets table: " . $e->getMessage() . "\n";
    }

    // Create feedback table for feedback.php
    try {
        $sql = "CREATE TABLE IF NOT EXISTS feedback (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            feedback_type ENUM('suggestion', 'complaint', 'compliment', 'bug_report', 'other') NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            rating INT CHECK (rating >= 1 AND rating <= 5),
            status ENUM('pending', 'reviewed', 'addressed') DEFAULT 'pending',
            admin_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )";
        $db->exec($sql);
        echo "✓ Created feedback table\n";
    } catch (PDOException $e) {
        echo "✗ Error creating feedback table: " . $e->getMessage() . "\n";
    }

    // Add profile picture column to users table
    try {
        $db->exec("ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL");
        echo "✓ Added profile_picture column to users table\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "✓ Profile_picture column already exists in users table\n";
        } else {
            echo "✗ Error adding profile_picture column to users: " . $e->getMessage() . "\n";
        }
    }

    echo "\nDatabase updates completed!\n";

} catch (Exception $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "\n";
}
?>
