-- Database fixes for School Management System
-- Run this script in your MySQL database to fix all schema issues

USE school_ms;

-- Fix attendance table - add missing remarks column
ALTER TABLE attendance ADD COLUMN IF NOT EXISTS remarks TEXT;

-- Fix exams table - add missing teacher_id column
ALTER TABLE exams ADD COLUMN IF NOT EXISTS teacher_id INT;
ALTER TABLE exams ADD CONSTRAINT IF NOT EXISTS fk_exams_teacher
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL;

-- Fix hostel_blocks table - ensure it has the correct columns
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS name VARCHAR(100);

-- Update name column with default values if empty (since block_name column doesn't exist)
UPDATE hostel_blocks
SET name = CONCAT('Block ', id)
WHERE name IS NULL OR name = '';

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

-- Update visit_date from record_date where visit_date is null
UPDATE health_records 
SET visit_date = record_date 
WHERE visit_date IS NULL AND record_date IS NOT NULL;

-- Ensure counseling_sessions table exists
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

-- Drop school_settings table if it exists with wrong structure, then recreate
DROP TABLE IF EXISTS school_settings;

-- Create school_settings table for settings/school.php
CREATE TABLE school_settings (
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
);

-- Insert default school settings
INSERT INTO school_settings (
    school_name,
    academic_year_start,
    academic_year_end,
    currency,
    timezone
) VALUES (
    'Greenwood Academy',
    CONCAT(YEAR(CURDATE()), '-09-01'),
    CONCAT(YEAR(CURDATE()) + 1, '-06-30'),
    'GHS',
    'Africa/Accra'
);

-- Create inventory_requests table if missing (referenced in inventory pages)
CREATE TABLE IF NOT EXISTS inventory_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    requested_by INT NOT NULL,
    quantity_requested INT NOT NULL,
    quantity_approved INT DEFAULT 0,
    purpose TEXT,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    request_date DATE NOT NULL,
    required_date DATE,
    notes TEXT,
    approved_by INT,
    approval_date DATE,
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Create inventory_movements table if missing
CREATE TABLE IF NOT EXISTS inventory_movements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    movement_type ENUM('in', 'out') NOT NULL,
    quantity INT NOT NULL,
    reference_id INT,
    reference_type VARCHAR(50),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create communication_announcements table if missing
CREATE TABLE IF NOT EXISTS communication_announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    author_id INT NOT NULL,
    target_audience ENUM('all', 'students', 'teachers', 'parents', 'staff') DEFAULT 'all',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    publish_date DATETIME,
    expiry_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create communication_messages table if missing
CREATE TABLE IF NOT EXISTS communication_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    recipient_id INT,
    recipient_type ENUM('user', 'class', 'role', 'all') DEFAULT 'user',
    recipient_value VARCHAR(100),
    subject VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('draft', 'sent', 'delivered', 'read') DEFAULT 'draft',
    sent_at TIMESTAMP NULL,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add missing status column to assignments table if it doesn't exist
ALTER TABLE assignments ADD COLUMN IF NOT EXISTS status ENUM('active', 'inactive', 'completed') DEFAULT 'active';

-- Add missing columns to exams table if they don't exist
ALTER TABLE exams ADD COLUMN IF NOT EXISTS title VARCHAR(255) NOT NULL DEFAULT '';
ALTER TABLE exams ADD COLUMN IF NOT EXISTS exam_date DATE;
ALTER TABLE exams ADD COLUMN IF NOT EXISTS duration INT NOT NULL DEFAULT 60;

-- Update exam_date to match date column for existing records where exam_date is null
UPDATE exams SET exam_date = date WHERE exam_date IS NULL AND date IS NOT NULL;
UPDATE exams SET title = name WHERE title = '' AND name IS NOT NULL;

-- Add missing columns to book_loans table if they don't exist
ALTER TABLE book_loans ADD COLUMN IF NOT EXISTS borrower_id INT;
ALTER TABLE book_loans ADD COLUMN IF NOT EXISTS loan_date DATE;

-- Update borrower_id to match user_id for existing records where borrower_id is null
UPDATE book_loans SET borrower_id = user_id WHERE borrower_id IS NULL AND user_id IS NOT NULL;
UPDATE book_loans SET loan_date = borrowed_date WHERE loan_date IS NULL AND borrowed_date IS NOT NULL;

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

-- Create notifications table for the notifications system
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('academic', 'finance', 'system', 'announcement', 'general') DEFAULT 'general',
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    is_read BOOLEAN DEFAULT FALSE,
    action_url VARCHAR(500),
    action_text VARCHAR(100),
    icon VARCHAR(50) DEFAULT 'fas fa-bell',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_created_at (created_at)
);

-- Create document_uploads table for document management
CREATE TABLE IF NOT EXISTS document_uploads (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size BIGINT NOT NULL,
    document_type ENUM('academic', 'administrative', 'certificate', 'transcript', 'id_card', 'other') DEFAULT 'other',
    uploaded_by INT NOT NULL,
    access_level ENUM('public', 'staff', 'students', 'parents', 'private') DEFAULT 'private',
    is_shared BOOLEAN DEFAULT FALSE,
    download_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_document_type (document_type),
    INDEX idx_access_level (access_level),
    INDEX idx_uploaded_by (uploaded_by)
);

-- Create shared_documents table for file sharing
CREATE TABLE IF NOT EXISTS shared_documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    shared_by INT NOT NULL,
    shared_with INT,
    shared_with_role ENUM('student', 'teacher', 'parent', 'admin', 'all'),
    access_type ENUM('view', 'download', 'edit') DEFAULT 'view',
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES document_uploads(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_shared_with (shared_with),
    INDEX idx_shared_with_role (shared_with_role)
);

-- Create transcript_requests table
CREATE TABLE IF NOT EXISTS transcript_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    requested_by INT NOT NULL,
    request_type ENUM('official', 'unofficial', 'electronic') DEFAULT 'official',
    purpose VARCHAR(255),
    delivery_method ENUM('pickup', 'mail', 'email') DEFAULT 'pickup',
    delivery_address TEXT,
    status ENUM('pending', 'processing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
    fee_amount DECIMAL(10,2) DEFAULT 0.00,
    fee_paid BOOLEAN DEFAULT FALSE,
    processed_by INT,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student_id (student_id),
    INDEX idx_status (status)
);

-- Insert sample notifications for testing
INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
(NULL, 'New Student Enrollment', 'John Doe has been successfully enrolled in Grade 5A. Please review the enrollment details and assign necessary resources.', 'academic', 'medium', 'fas fa-user-plus'),
(NULL, 'Assignment Submissions', '15 students have submitted their Math homework for Chapter 5. Review submissions are now available.', 'academic', 'low', 'fas fa-check'),
(NULL, 'Fee Payment Reminder', 'Monthly fees for December are due. Please ensure timely payment to avoid late charges.', 'finance', 'high', 'fas fa-money-bill-wave'),
(NULL, 'System Maintenance', 'Scheduled system maintenance will occur this weekend. Some services may be temporarily unavailable.', 'system', 'medium', 'fas fa-tools'),
(NULL, 'Parent-Teacher Meeting', 'Parent-teacher meetings are scheduled for next week. Please check your calendar for appointment details.', 'announcement', 'high', 'fas fa-calendar-alt');

-- Show completion message
SELECT 'Database schema fixes completed successfully!' as message;
