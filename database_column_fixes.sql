-- Database Column Fixes for School Management System
-- This script adds missing columns and fixes column name mismatches

-- Fix hostel_blocks table
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS rooms_per_floor INT DEFAULT 10;
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS capacity_per_room INT DEFAULT 2;
ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS block_type ENUM('boys', 'girls', 'mixed') DEFAULT 'mixed';

-- Fix inventory_items table - add missing columns
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS name VARCHAR(255);
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS category VARCHAR(100);
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS unit VARCHAR(50);
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS cost_per_unit DECIMAL(10,2);
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS current_stock INT DEFAULT 0;
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS minimum_stock INT DEFAULT 0;
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS maximum_stock INT DEFAULT 0;
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS supplier VARCHAR(255);
ALTER TABLE inventory_items ADD COLUMN IF NOT EXISTS barcode VARCHAR(100);

-- Update existing data if item_name exists but name doesn't
UPDATE inventory_items SET name = item_name WHERE name IS NULL AND item_name IS NOT NULL;

-- Fix canteen_inventory table - add missing columns
ALTER TABLE canteen_inventory ADD COLUMN IF NOT EXISTS name VARCHAR(255);
ALTER TABLE canteen_inventory ADD COLUMN IF NOT EXISTS cost_per_unit DECIMAL(8,2);
ALTER TABLE canteen_inventory ADD COLUMN IF NOT EXISTS current_stock DECIMAL(10,2);
ALTER TABLE canteen_inventory ADD COLUMN IF NOT EXISTS description TEXT;

-- Update existing data
UPDATE canteen_inventory SET name = item_name WHERE name IS NULL AND item_name IS NOT NULL;
UPDATE canteen_inventory SET cost_per_unit = unit_price WHERE cost_per_unit IS NULL AND unit_price IS NOT NULL;
UPDATE canteen_inventory SET current_stock = quantity WHERE current_stock IS NULL AND quantity IS NOT NULL;

-- Fix health_records table - ensure all required columns exist
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS height_cm DECIMAL(5,2);
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS weight_kg DECIMAL(5,2);
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS blood_pressure VARCHAR(20);
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS temperature_f DECIMAL(4,1);
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS pulse_rate INT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS medical_conditions TEXT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS allergies TEXT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS medications TEXT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS vaccination_status TEXT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS notes TEXT;
ALTER TABLE health_records ADD COLUMN IF NOT EXISTS recorded_by INT;

-- Create counseling_sessions table if it doesn't exist
CREATE TABLE IF NOT EXISTS counseling_sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    session_date DATE NOT NULL,
    session_type ENUM('individual', 'group', 'crisis', 'academic', 'career', 'behavioral', 'family') NOT NULL,
    reason TEXT,
    concerns TEXT,
    observations TEXT,
    recommendations TEXT,
    follow_up_required ENUM('yes', 'no') DEFAULT 'no',
    follow_up_date DATE,
    confidential_notes TEXT,
    counselor_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Fix library_loans table - add return_date column
ALTER TABLE library_loans ADD COLUMN IF NOT EXISTS return_date DATE;

-- Create library_loans table if it doesn't exist
CREATE TABLE IF NOT EXISTS library_loans (
    id INT PRIMARY KEY AUTO_INCREMENT,
    book_id INT NOT NULL,
    student_id INT NOT NULL,
    loan_date DATE NOT NULL,
    due_date DATE NOT NULL,
    return_date DATE,
    status ENUM('active', 'returned', 'overdue') DEFAULT 'active',
    fine_amount DECIMAL(8,2) DEFAULT 0,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (book_id) REFERENCES library_books(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create hostel_allocations table if missing columns
ALTER TABLE hostel_allocations ADD COLUMN IF NOT EXISTS allocation_date DATE;
ALTER TABLE hostel_allocations ADD COLUMN IF NOT EXISTS checkout_date DATE;

-- Create canteen_orders table if it doesn't exist
CREATE TABLE IF NOT EXISTS canteen_orders (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    order_date DATE NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'confirmed', 'preparing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
    payment_status ENUM('pending', 'paid', 'refunded') DEFAULT 'pending',
    delivery_time TIME,
    special_instructions TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create canteen_order_items table
CREATE TABLE IF NOT EXISTS canteen_order_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    order_id INT NOT NULL,
    menu_item_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(8,2) NOT NULL,
    total_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES canteen_orders(id) ON DELETE CASCADE
);

-- Create fee_structures table if it doesn't exist
CREATE TABLE IF NOT EXISTS fee_structures (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    class_id INT,
    academic_year VARCHAR(9) NOT NULL,
    fee_type ENUM('tuition', 'hostel', 'transport', 'library', 'laboratory', 'sports', 'other') NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE,
    description TEXT,
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL
);

-- Create online_discussions table if it doesn't exist
CREATE TABLE IF NOT EXISTS online_discussions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    class_id INT,
    subject_id INT,
    created_by INT NOT NULL,
    status ENUM('active', 'closed', 'archived') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Create discussion_posts table
CREATE TABLE IF NOT EXISTS discussion_posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    discussion_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    parent_post_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (discussion_id) REFERENCES online_discussions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_post_id) REFERENCES discussion_posts(id) ON DELETE CASCADE
);

-- Add foreign key constraints for health_records if not exists
ALTER TABLE health_records ADD CONSTRAINT IF NOT EXISTS fk_health_records_student 
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE health_records ADD CONSTRAINT IF NOT EXISTS fk_health_records_recorded_by 
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL;
