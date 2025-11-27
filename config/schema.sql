-- Create database
CREATE DATABASE IF NOT EXISTS school_ms;
USE school_ms;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor') NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Remember tokens table
CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Classes table
CREATE TABLE IF NOT EXISTS classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL,
    grade_level VARCHAR(20) NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Subjects table
CREATE TABLE IF NOT EXISTS subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(20) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Class teachers table (mapping teachers to classes and subjects)
CREATE TABLE IF NOT EXISTS class_teachers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- Student classes table (mapping students to classes)
CREATE TABLE IF NOT EXISTS student_classes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    status ENUM('active', 'inactive', 'graduated') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Assignments table
CREATE TABLE IF NOT EXISTS assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    due_date TIMESTAMP NOT NULL,
    status ENUM('active', 'inactive', 'completed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Student assignments table (tracking assignment submissions)
CREATE TABLE IF NOT EXISTS student_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    submission_text TEXT,
    file_path VARCHAR(255),
    grade DECIMAL(5,2),
    feedback TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Attendance table
CREATE TABLE IF NOT EXISTS attendance (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'absent', 'late') NOT NULL,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Library books table
CREATE TABLE IF NOT EXISTS library_books (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(100) NOT NULL,
    isbn VARCHAR(13),
    category VARCHAR(50),
    copies_available INT NOT NULL DEFAULT 0,
    description TEXT,
    publisher VARCHAR(100),
    publication_year INT,
    language VARCHAR(50),
    location VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Book loans table
CREATE TABLE IF NOT EXISTS book_loans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    borrower_id INT NOT NULL,
    borrowed_date DATE NOT NULL,
    loan_date DATE NOT NULL,
    due_date DATE NOT NULL,
    returned_date DATE,
    status ENUM('borrowed', 'returned', 'overdue') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES library_books(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Fees table
CREATE TABLE IF NOT EXISTS fees (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    fee_type ENUM('tuition', 'library', 'laboratory', 'other') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('paid', 'unpaid', 'partial') NOT NULL DEFAULT 'unpaid',
    academic_year VARCHAR(9) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Payments table
CREATE TABLE IF NOT EXISTS payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    fee_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method ENUM('cash', 'bank_transfer', 'card') NOT NULL,
    reference_number VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fee_id) REFERENCES fees(id) ON DELETE CASCADE
);

-- Exams table
CREATE TABLE IF NOT EXISTS exams (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    exam_type ENUM('midterm', 'final', 'quiz', 'assignment', 'project') NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    date DATE NOT NULL,
    exam_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration INT NOT NULL DEFAULT 60,
    total_marks INT NOT NULL DEFAULT 100,
    academic_year VARCHAR(9) NOT NULL,
    academic_term ENUM('first', 'second', 'third') NOT NULL,
    status ENUM('scheduled', 'ongoing', 'completed', 'cancelled') DEFAULT 'scheduled',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
);

-- Exam results table
CREATE TABLE IF NOT EXISTS exam_results (
    id INT PRIMARY KEY AUTO_INCREMENT,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    marks_obtained DECIMAL(5,2) NOT NULL,
    grade VARCHAR(5),
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_exam_student (exam_id, student_id)
);

-- Timetable table
CREATE TABLE IF NOT EXISTS timetable (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    day_of_week ENUM('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    room_number VARCHAR(20),
    academic_year VARCHAR(9) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Class schedule table (alias for timetable for compatibility)
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

-- Student profiles table (extended user information)
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    admission_date DATE NOT NULL,
    date_of_birth DATE,
    gender ENUM('male', 'female', 'other'),
    blood_group VARCHAR(5),
    address TEXT,
    phone VARCHAR(20),
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    parent_id INT,
    guardian_name VARCHAR(100),
    guardian_phone VARCHAR(20),
    guardian_email VARCHAR(100),
    medical_conditions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Teacher profiles table
CREATE TABLE IF NOT EXISTS teacher_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    employee_id VARCHAR(20) UNIQUE NOT NULL,
    date_of_birth DATE,
    gender ENUM('male', 'female', 'other'),
    phone VARCHAR(20),
    address TEXT,
    qualification VARCHAR(255),
    experience_years INT DEFAULT 0,
    joining_date DATE NOT NULL,
    salary DECIMAL(10,2),
    department VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Announcements table
CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    author_id INT NOT NULL,
    target_audience ENUM('all', 'students', 'teachers', 'parents', 'staff') NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
    publish_date DATETIME,
    expiry_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Messages table (internal messaging system)
CREATE TABLE IF NOT EXISTS messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Academic calendar table
CREATE TABLE IF NOT EXISTS academic_calendar (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    event_type ENUM('holiday', 'exam', 'meeting', 'event', 'deadline') NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Fee structures table
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

-- Library fines table
CREATE TABLE IF NOT EXISTS library_fines (
    id INT PRIMARY KEY AUTO_INCREMENT,
    loan_id INT NOT NULL,
    fine_amount DECIMAL(8,2) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    status ENUM('pending', 'paid', 'waived') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    FOREIGN KEY (loan_id) REFERENCES book_loans(id) ON DELETE CASCADE
);

-- Hostel blocks table
CREATE TABLE IF NOT EXISTS hostel_blocks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    total_floors INT NOT NULL DEFAULT 1,
    warden_id INT,
    status ENUM('active', 'inactive', 'maintenance') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warden_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Hostel rooms table
CREATE TABLE IF NOT EXISTS hostel_rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    block_id INT NOT NULL,
    room_number VARCHAR(20) NOT NULL,
    floor_number INT NOT NULL,
    room_type ENUM('single', 'double', 'triple', 'dormitory') NOT NULL,
    capacity INT NOT NULL DEFAULT 1,
    current_occupancy INT DEFAULT 0,
    status ENUM('available', 'occupied', 'maintenance', 'reserved') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_id) REFERENCES hostel_blocks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_room (block_id, room_number)
);

-- Hostel allocations table
CREATE TABLE IF NOT EXISTS hostel_allocations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    room_id INT NOT NULL,
    allocation_date DATE NOT NULL,
    checkout_date DATE,
    status ENUM('active', 'checked_out', 'transferred') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE
);

-- Transport routes table
CREATE TABLE IF NOT EXISTS transport_routes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_name VARCHAR(100) NOT NULL,
    route_code VARCHAR(20) UNIQUE NOT NULL,
    start_point VARCHAR(255) NOT NULL,
    end_point VARCHAR(255) NOT NULL,
    distance_km DECIMAL(6,2),
    estimated_time_minutes INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Transport vehicles table
CREATE TABLE IF NOT EXISTS transport_vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_number VARCHAR(20) UNIQUE NOT NULL,
    vehicle_type ENUM('bus', 'van', 'car') NOT NULL,
    capacity INT NOT NULL,
    driver_name VARCHAR(100),
    driver_phone VARCHAR(20),
    driver_license VARCHAR(50),
    route_id INT,
    status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE SET NULL
);

-- Transport stops table
CREATE TABLE IF NOT EXISTS transport_stops (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_id INT NOT NULL,
    stop_name VARCHAR(255) NOT NULL,
    stop_address TEXT,
    pickup_time TIME,
    drop_time TIME,
    stop_order INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
);

-- Student transport table
CREATE TABLE IF NOT EXISTS student_transport (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    route_id INT NOT NULL,
    stop_id INT NOT NULL,
    academic_year VARCHAR(9) NOT NULL,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE,
    FOREIGN KEY (stop_id) REFERENCES transport_stops(id) ON DELETE CASCADE
);

-- Health records table
CREATE TABLE IF NOT EXISTS health_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    record_type ENUM('checkup', 'vaccination', 'illness', 'injury', 'allergy') NOT NULL,
    record_date DATE NOT NULL,
    description TEXT NOT NULL,
    doctor_name VARCHAR(100),
    medications TEXT,
    follow_up_date DATE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Inventory categories table
CREATE TABLE IF NOT EXISTS inventory_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inventory items table
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    category_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    description TEXT,
    quantity_available INT NOT NULL DEFAULT 0,
    minimum_stock_level INT DEFAULT 0,
    unit_price DECIMAL(10,2),
    location VARCHAR(100),
    status ENUM('available', 'out_of_stock', 'discontinued') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES inventory_categories(id) ON DELETE CASCADE
);

-- Inventory requests table
CREATE TABLE IF NOT EXISTS inventory_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_id INT NOT NULL,
    requested_by INT NOT NULL,
    quantity_requested INT NOT NULL,
    quantity_approved INT DEFAULT 0,
    purpose TEXT,
    request_date DATE NOT NULL,
    approved_by INT,
    approval_date DATE,
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- School settings table
CREATE TABLE IF NOT EXISTS school_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    setting_type ENUM('text', 'number', 'boolean', 'json') DEFAULT 'text',
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default users
INSERT INTO users (name, email, password, role) VALUES
('System Admin', 'admin@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'super_admin'),
('School Principal', 'principal@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'principal'),
('John Teacher', 'teacher@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher'),
('Jane Student', 'student@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student'),
('Mary Parent', 'parent@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'parent'),
('Library Manager', 'librarian@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'librarian'),
('Finance Manager', 'accountant@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'accountant'),
('Transport Officer', 'transport@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'transport_officer'),
('Hostel Warden', 'warden@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hostel_warden'),
('Canteen Manager', 'canteen@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'canteen_manager'),
('School Nurse', 'nurse@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'nurse'),
('School Counselor', 'counselor@school.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'counselor');
-- Default password is 'password' for all users

-- Insert default classes
INSERT INTO classes (name, grade_level, academic_year) VALUES
('Grade 1A', 'Grade 1', '2024-2025'),
('Grade 1B', 'Grade 1', '2024-2025'),
('Grade 2A', 'Grade 2', '2024-2025'),
('Grade 3A', 'Grade 3', '2024-2025'),
('Grade 4A', 'Grade 4', '2024-2025'),
('Grade 5A', 'Grade 5', '2024-2025');

-- Insert default subjects
INSERT INTO subjects (name, code, description) VALUES
('Mathematics', 'MATH', 'Basic mathematics and arithmetic'),
('English Language', 'ENG', 'English language and literature'),
('Science', 'SCI', 'General science and nature studies'),
('Social Studies', 'SS', 'History, geography and social sciences'),
('Physical Education', 'PE', 'Physical fitness and sports'),
('Art & Craft', 'ART', 'Creative arts and crafts'),
('Computer Studies', 'CS', 'Basic computer literacy');

-- Insert default inventory categories
INSERT INTO inventory_categories (name, description) VALUES
('Office Supplies', 'Stationery and office equipment'),
('Laboratory Equipment', 'Science lab instruments and chemicals'),
('Sports Equipment', 'Physical education and sports items'),
('Furniture', 'Desks, chairs, and classroom furniture'),
('Technology', 'Computers, projectors, and electronic devices'),
('Books & Materials', 'Textbooks and educational materials');

-- Canteen menu table
CREATE TABLE IF NOT EXISTS canteen_menu (
    id INT PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL,
    meal_type ENUM('breakfast', 'lunch', 'dinner', 'snack') NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT,
    price DECIMAL(8,2) NOT NULL,
    available_quantity INT DEFAULT 0,
    status ENUM('available', 'unavailable') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Canteen orders table
CREATE TABLE IF NOT EXISTS canteen_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    staff_id INT NOT NULL,
    menu_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    total_price DECIMAL(8,2) NOT NULL,
    order_date DATE NOT NULL,
    order_time TIME NOT NULL,
    status ENUM('pending', 'confirmed', 'served', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (menu_id) REFERENCES canteen_menu(id) ON DELETE CASCADE
);

-- Canteen inventory table
CREATE TABLE IF NOT EXISTS canteen_inventory (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_name VARCHAR(255) NOT NULL,
    category ENUM('vegetables', 'fruits', 'grains', 'dairy', 'meat', 'spices', 'beverages', 'other') NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 0,
    unit VARCHAR(20) NOT NULL,
    unit_price DECIMAL(8,2) NOT NULL,
    minimum_stock DECIMAL(10,2) DEFAULT 0,
    expiry_date DATE,
    supplier VARCHAR(255),
    last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Canteen registrations table
CREATE TABLE IF NOT EXISTS canteen_registrations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    registration_type ENUM('daily', 'weekly', 'monthly', 'term') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    meal_plan ENUM('breakfast_only', 'lunch_only', 'both', 'all_meals') NOT NULL,
    amount_paid DECIMAL(10,2) NOT NULL,
    status ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    approved_by INT,
    registration_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Parent student relationships table
CREATE TABLE IF NOT EXISTS parent_students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    parent_id INT NOT NULL,
    student_id INT NOT NULL,
    relationship ENUM('father', 'mother', 'guardian', 'other') NOT NULL,
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_parent_student (parent_id, student_id)
);

-- Counseling sessions table
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

-- Document management table
CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size INT NOT NULL,
    uploaded_by INT NOT NULL,
    document_type ENUM('certificate', 'transcript', 'report', 'policy', 'form', 'other') NOT NULL,
    access_level ENUM('public', 'staff', 'students', 'parents', 'admin_only') DEFAULT 'staff',
    related_user_id INT,
    academic_year VARCHAR(9),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Transport routes table
CREATE TABLE IF NOT EXISTS transport_routes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    route_name VARCHAR(255) NOT NULL,
    route_code VARCHAR(50) UNIQUE NOT NULL,
    start_point VARCHAR(255) NOT NULL,
    end_point VARCHAR(255) NOT NULL,
    distance_km DECIMAL(8,2),
    estimated_time_minutes INT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Transport vehicles table
CREATE TABLE IF NOT EXISTS transport_vehicles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    vehicle_number VARCHAR(50) UNIQUE NOT NULL,
    vehicle_type ENUM('bus', 'van', 'car') NOT NULL,
    capacity INT NOT NULL,
    driver_name VARCHAR(255),
    driver_phone VARCHAR(20),
    driver_license VARCHAR(50),
    route_id INT,
    status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE SET NULL
);

-- Student transport assignments table
CREATE TABLE IF NOT EXISTS student_transport (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    route_id INT NOT NULL,
    pickup_point VARCHAR(255) NOT NULL,
    pickup_time TIME,
    drop_point VARCHAR(255) NOT NULL,
    drop_time TIME,
    monthly_fee DECIMAL(8,2) DEFAULT 0,
    status ENUM('active', 'inactive') DEFAULT 'active',
    start_date DATE NOT NULL,
    end_date DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (route_id) REFERENCES transport_routes(id) ON DELETE CASCADE
);

-- Hostel blocks table
CREATE TABLE IF NOT EXISTS hostel_blocks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    block_name VARCHAR(100) NOT NULL,
    block_code VARCHAR(20) UNIQUE NOT NULL,
    gender ENUM('male', 'female', 'mixed') NOT NULL,
    total_floors INT DEFAULT 1,
    warden_id INT,
    status ENUM('active', 'maintenance', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (warden_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Hostel rooms table
CREATE TABLE IF NOT EXISTS hostel_rooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    block_id INT NOT NULL,
    room_number VARCHAR(20) NOT NULL,
    room_type ENUM('single', 'double', 'triple', 'dormitory') NOT NULL,
    capacity INT NOT NULL,
    current_occupancy INT DEFAULT 0,
    monthly_fee DECIMAL(8,2) NOT NULL,
    amenities TEXT,
    status ENUM('available', 'occupied', 'maintenance', 'inactive') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (block_id) REFERENCES hostel_blocks(id) ON DELETE CASCADE,
    UNIQUE KEY unique_room (block_id, room_number)
);

-- Hostel allocations table
CREATE TABLE IF NOT EXISTS hostel_allocations (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    room_id INT NOT NULL,
    allocation_date DATE NOT NULL,
    checkout_date DATE,
    monthly_fee DECIMAL(8,2) NOT NULL,
    security_deposit DECIMAL(8,2) DEFAULT 0,
    status ENUM('active', 'checked_out', 'terminated') DEFAULT 'active',
    allocated_by INT,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (allocated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Health records table
CREATE TABLE IF NOT EXISTS health_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    record_date DATE NOT NULL,
    height_cm DECIMAL(5,2),
    weight_kg DECIMAL(5,2),
    blood_pressure VARCHAR(20),
    temperature_f DECIMAL(4,1),
    pulse_rate INT,
    medical_conditions TEXT,
    allergies TEXT,
    medications TEXT,
    vaccination_status TEXT,
    notes TEXT,
    recorded_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Inventory items table
CREATE TABLE IF NOT EXISTS inventory_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    item_name VARCHAR(255) NOT NULL,
    item_code VARCHAR(50) UNIQUE NOT NULL,
    category ENUM('furniture', 'electronics', 'books', 'sports', 'stationery', 'maintenance', 'other') NOT NULL,
    description TEXT,
    quantity_available INT DEFAULT 0,
    unit_price DECIMAL(10,2),
    location VARCHAR(255),
    condition_status ENUM('new', 'good', 'fair', 'poor', 'damaged') DEFAULT 'new',
    purchase_date DATE,
    warranty_expiry DATE,
    supplier VARCHAR(255),
    status ENUM('active', 'inactive', 'disposed') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Inventory requests table
CREATE TABLE IF NOT EXISTS inventory_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    requested_by INT NOT NULL,
    item_id INT NOT NULL,
    quantity_requested INT NOT NULL,
    purpose TEXT,
    request_date DATE NOT NULL,
    required_date DATE,
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') DEFAULT 'pending',
    approved_by INT,
    approved_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- System logs table
CREATE TABLE IF NOT EXISTS system_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(255) NOT NULL,
    table_name VARCHAR(100),
    record_id INT,
    old_values JSON,
    new_values JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default school settings
INSERT INTO school_settings (setting_key, setting_value, setting_type, description) VALUES
('school_name', 'Greenwood Academy', 'text', 'Name of the school'),
('school_address', '123 Education Street, Learning City', 'text', 'School physical address'),
('school_phone', '+1-234-567-8900', 'text', 'School contact phone number'),
('school_email', 'info@greenwoodacademy.edu', 'text', 'School official email address'),
('academic_year', '2024-2025', 'text', 'Current academic year'),
('current_term', 'first', 'text', 'Current academic term'),
('timezone', 'America/New_York', 'text', 'School timezone'),
('currency', 'GHS', 'text', 'Currency for fee management'),
('late_fee_per_day', '5.00', 'number', 'Late fee charged per day for overdue library books'),
('max_books_per_student', '3', 'number', 'Maximum books a student can borrow'),
('attendance_grace_minutes', '15', 'number', 'Grace period in minutes for late attendance'),
('canteen_enabled', 'true', 'boolean', 'Enable canteen management module'),
('transport_enabled', 'true', 'boolean', 'Enable transport management module'),
('hostel_enabled', 'true', 'boolean', 'Enable hostel management module'),
('health_module_enabled', 'true', 'boolean', 'Enable health and counseling module'),
('inventory_enabled', 'true', 'boolean', 'Enable inventory management module');