-- Academic Term and Year Management System Tables
-- Run this script to create tables for managing academic years, terms, and student promotions

USE school_ms;

-- Academic Years table
CREATE TABLE IF NOT EXISTS academic_years (
    id INT PRIMARY KEY AUTO_INCREMENT,
    year_name VARCHAR(9) NOT NULL UNIQUE, -- e.g., '2024-2025'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'completed', 'upcoming') DEFAULT 'upcoming',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Academic Terms table
CREATE TABLE IF NOT EXISTS academic_terms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    academic_year_id INT NOT NULL,
    term_number ENUM('1', '2', '3') NOT NULL,
    term_name VARCHAR(50) NOT NULL, -- e.g., 'First Term', 'Second Term', 'Third Term'
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    status ENUM('active', 'completed', 'upcoming') DEFAULT 'upcoming',
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    UNIQUE KEY unique_term_per_year (academic_year_id, term_number)
);

-- Student Promotions table (tracks student class changes between academic years)
CREATE TABLE IF NOT EXISTS student_promotions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    from_academic_year_id INT NOT NULL,
    to_academic_year_id INT NOT NULL,
    from_class_id INT NOT NULL,
    to_class_id INT NOT NULL,
    promotion_status ENUM('promoted', 'repeated', 'transferred', 'graduated') NOT NULL,
    promotion_date DATE NOT NULL,
    remarks TEXT,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (from_academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (to_academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (from_class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (to_class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Student Academic Records table (maintains historical academic performance)
CREATE TABLE IF NOT EXISTS student_academic_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    academic_term_id INT NOT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    continuous_assessment DECIMAL(5,2) DEFAULT 0.00,
    exam_score DECIMAL(5,2) DEFAULT 0.00,
    total_score DECIMAL(5,2) DEFAULT 0.00,
    grade VARCHAR(5),
    remarks TEXT,
    teacher_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_student_subject_term (student_id, academic_year_id, academic_term_id, subject_id)
);

-- Term Reports table (stores generated report cards)
CREATE TABLE IF NOT EXISTS term_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_year_id INT NOT NULL,
    academic_term_id INT NOT NULL,
    class_id INT NOT NULL,
    total_subjects INT DEFAULT 0,
    total_score DECIMAL(8,2) DEFAULT 0.00,
    average_score DECIMAL(5,2) DEFAULT 0.00,
    position_in_class INT,
    class_size INT,
    attendance_days INT DEFAULT 0,
    attendance_present INT DEFAULT 0,
    conduct_grade VARCHAR(5),
    teacher_remarks TEXT,
    principal_remarks TEXT,
    next_term_begins DATE,
    report_generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_student_term_report (student_id, academic_year_id, academic_term_id)
);

-- System Settings for Academic Management
CREATE TABLE IF NOT EXISTS academic_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    description TEXT,
    updated_by INT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default academic settings
INSERT INTO academic_settings (setting_key, setting_value, description) VALUES
('current_academic_year_id', NULL, 'ID of the currently active academic year'),
('current_academic_term_id', NULL, 'ID of the currently active academic term'),
('auto_promotion_enabled', 'false', 'Whether to enable automatic student promotion'),
('minimum_pass_score', '50', 'Minimum score required to pass a subject'),
('grading_system', 'percentage', 'Grading system: percentage, letter, or points')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Add indexes for better performance
CREATE INDEX idx_academic_records_student_year_term ON student_academic_records(student_id, academic_year_id, academic_term_id);
CREATE INDEX idx_promotions_student_year ON student_promotions(student_id, from_academic_year_id);
CREATE INDEX idx_terms_year_status ON academic_terms(academic_year_id, status);
CREATE INDEX idx_years_status ON academic_years(status);
