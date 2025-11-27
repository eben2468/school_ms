-- Database updates to fix existing schema issues
USE school_ms;

-- Add missing status column to assignments table
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS status ENUM('active', 'inactive', 'completed') DEFAULT 'active';

-- Add missing columns to exams table
ALTER TABLE exams ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE exams ADD COLUMN IF NOT EXISTS exam_date DATE;
ALTER TABLE exams ADD COLUMN IF NOT EXISTS duration INT NOT NULL DEFAULT 60;

-- Update exam_date to match date column for existing records
UPDATE exams SET exam_date = date WHERE exam_date IS NULL;
UPDATE exams SET title = name WHERE title = '';

-- Add missing columns to book_loans table
ALTER TABLE book_loans ADD COLUMN IF NOT EXISTS borrower_id INT;
ALTER TABLE book_loans ADD COLUMN IF NOT EXISTS loan_date DATE;

-- Update borrower_id to match user_id for existing records
UPDATE book_loans SET borrower_id = user_id WHERE borrower_id IS NULL;
UPDATE book_loans SET loan_date = borrowed_date WHERE loan_date IS NULL;

-- Add foreign key constraint for borrower_id if it doesn't exist
SET @constraint_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                         WHERE TABLE_SCHEMA = 'school_ms'
                         AND TABLE_NAME = 'book_loans'
                         AND CONSTRAINT_NAME = 'book_loans_borrower_fk');

SET @sql = IF(@constraint_exists = 0,
    'ALTER TABLE book_loans ADD CONSTRAINT book_loans_borrower_fk FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT "Foreign key constraint already exists"');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create class_schedule table if it doesn't exist
CREATE TABLE IF NOT EXISTS class_schedule (
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
);

-- Fix hostel_blocks table - ensure it has the correct columns
-- The schema shows both 'name' and 'block_name' columns, let's standardize on 'name'
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS name VARCHAR(100);

-- Create hostel_students table (referenced in hostel blocks and rooms pages)
CREATE TABLE IF NOT EXISTS hostel_students (
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
);

-- Add missing visit_date column to health_records table
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS visit_date DATE;
UPDATE health_records SET visit_date = record_date WHERE visit_date IS NULL;

-- Ensure counseling_sessions table exists (it's already in schema but may be missing)
CREATE TABLE IF NOT EXISTS counseling_sessions (
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
);

-- Fix canteen_inventory table - ensure unit_price column exists
ALTER TABLE canteen_inventory ADD COLUMN IF NOT EXISTS unit_price DECIMAL(8,2) DEFAULT 0.00;

-- Create fee_structures table if missing (referenced in finance pages)
CREATE TABLE IF NOT EXISTS fee_structures (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    fee_type ENUM('tuition', 'library', 'laboratory', 'transport', 'hostel', 'examination', 'other') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    academic_term ENUM('first', 'second', 'third', 'annual') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Create support_tickets table for support.php
CREATE TABLE IF NOT EXISTS support_tickets (
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
);

-- Create feedback table for feedback.php
CREATE TABLE IF NOT EXISTS feedback (
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
);

-- Add profile picture column to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) DEFAULT NULL;

-- Add created_by column to attendance table for tracking who recorded attendance
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS created_by INT NULL;

-- Add foreign key constraint for created_by if it doesn't exist
SET @attendance_constraint_exists = (SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
                                   WHERE TABLE_SCHEMA = 'school_ms'
                                   AND TABLE_NAME = 'attendance'
                                   AND CONSTRAINT_NAME = 'attendance_created_by_fk');

SET @attendance_sql = IF(@attendance_constraint_exists = 0,
    'ALTER TABLE attendance ADD CONSTRAINT attendance_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT "Attendance created_by foreign key constraint already exists"');

PREPARE attendance_stmt FROM @attendance_sql;
EXECUTE attendance_stmt;
DEALLOCATE PREPARE attendance_stmt;

-- Fix hostel_blocks table - add missing block_type column
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS block_type ENUM('boys', 'girls', 'mixed', 'staff') DEFAULT 'boys';

-- Fix inventory_items table - add missing item_type column
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS item_type VARCHAR(50) DEFAULT 'general';

-- Create students view for compatibility (many queries expect a 'students' table)
-- This view will show users with role 'student' and their profile information
CREATE OR REPLACE VIEW students AS
SELECT
    u.id,
    u.name,
    u.email,
    u.status,
    u.created_at,
    sp.student_id,
    sp.phone,
    sp.date_of_birth,
    sp.address,
    sp.emergency_contact_name,
    sp.emergency_contact_phone,
    sp.admission_date,
    sp.guardian_name,
    sp.guardian_phone,
    sp.guardian_email
FROM users u
LEFT JOIN student_profiles sp ON u.id = sp.user_id
WHERE u.role = 'student';

-- Fix exam enum values to match database schema
-- Update exam_type values to lowercase
UPDATE exams SET exam_type = 'midterm' WHERE exam_type = 'Midterm';
UPDATE exams SET exam_type = 'final' WHERE exam_type = 'Final';
UPDATE exams SET exam_type = 'quiz' WHERE exam_type = 'Quiz';
UPDATE exams SET exam_type = 'assignment' WHERE exam_type = 'Assignment';
UPDATE exams SET exam_type = 'project' WHERE exam_type = 'Project';

-- Update academic_term values to match database schema
UPDATE exams SET academic_term = 'first' WHERE academic_term = 'Term 1';
UPDATE exams SET academic_term = 'second' WHERE academic_term = 'Term 2';
UPDATE exams SET academic_term = 'third' WHERE academic_term = 'Term 3';

-- Add main_teacher_id to classes table for main class teacher assignment
ALTER TABLE classes ADD COLUMN IF NOT EXISTS main_teacher_id INT NULL;
ALTER TABLE classes ADD CONSTRAINT fk_classes_main_teacher
    FOREIGN KEY (main_teacher_id) REFERENCES users(id) ON DELETE SET NULL;

-- Add file attachment support to assignments table
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS attachment_path VARCHAR(500) NULL;
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS attachment_name VARCHAR(255) NULL;

-- Show completion message
SELECT 'Database schema updates completed successfully!' as message;
