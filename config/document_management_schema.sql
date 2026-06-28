-- Document & File Management Database Schema
USE school_ms;

-- Enhanced documents table (extending existing)
ALTER TABLE documents ADD COLUMN IF NOT EXISTS tags VARCHAR(500);
ALTER TABLE documents ADD COLUMN IF NOT EXISTS version VARCHAR(20) DEFAULT '1.0';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS parent_document_id INT;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS is_archived BOOLEAN DEFAULT FALSE;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS download_count INT DEFAULT 0;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS last_accessed TIMESTAMP NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS expiry_date DATE NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved';
ALTER TABLE documents ADD COLUMN IF NOT EXISTS approved_by INT NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL;
ALTER TABLE documents ADD COLUMN IF NOT EXISTS academic_year VARCHAR(9) NULL;

-- Add foreign key constraints if they don't exist
ALTER TABLE documents ADD CONSTRAINT fk_documents_parent 
    FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE SET NULL;
ALTER TABLE documents ADD CONSTRAINT fk_documents_approved_by 
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

-- Document categories table
CREATE TABLE IF NOT EXISTS document_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    parent_category_id INT,
    icon VARCHAR(50) DEFAULT 'fas fa-folder',
    color VARCHAR(7) DEFAULT '#3B82F6',
    sort_order INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_category_id) REFERENCES document_categories(id) ON DELETE SET NULL
);

-- Document category assignments
CREATE TABLE IF NOT EXISTS document_category_assignments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    category_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES document_categories(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (document_id, category_id)
);

-- Document sharing table
CREATE TABLE IF NOT EXISTS document_shares (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    shared_by INT NOT NULL,
    shared_with_user_id INT,
    shared_with_role ENUM('super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'),
    shared_with_class_id INT,
    permission_level ENUM('view', 'download', 'edit', 'full') DEFAULT 'view',
    expiry_date DATETIME,
    access_count INT DEFAULT 0,
    last_accessed TIMESTAMP NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_class_id) REFERENCES classes(id) ON DELETE CASCADE
);

-- Document access logs
CREATE TABLE IF NOT EXISTS document_access_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    user_id INT NOT NULL,
    access_type ENUM('view', 'download', 'edit', 'delete', 'share') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Certificate templates table
CREATE TABLE IF NOT EXISTS certificate_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    template_type ENUM('academic', 'achievement', 'participation', 'completion', 'custom') NOT NULL,
    template_file_path VARCHAR(500) NOT NULL,
    background_image VARCHAR(500),
    layout_config JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Generated certificates table
CREATE TABLE IF NOT EXISTS generated_certificates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    student_id INT NOT NULL,
    certificate_number VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    issue_date DATE NOT NULL,
    certificate_data JSON,
    file_path VARCHAR(500) NOT NULL,
    qr_code_path VARCHAR(500),
    verification_code VARCHAR(50) UNIQUE NOT NULL,
    issued_by INT NOT NULL,
    status ENUM('draft', 'issued', 'revoked') DEFAULT 'issued',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE CASCADE
);

-- ID card templates table
CREATE TABLE IF NOT EXISTS id_card_templates (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    card_type ENUM('student', 'staff', 'visitor', 'temporary') NOT NULL,
    template_file_path VARCHAR(500) NOT NULL,
    layout_config JSON,
    is_active BOOLEAN DEFAULT TRUE,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Generated ID cards table
CREATE TABLE IF NOT EXISTS generated_id_cards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    template_id INT NOT NULL,
    user_id INT NOT NULL,
    card_number VARCHAR(100) UNIQUE NOT NULL,
    issue_date DATE NOT NULL,
    expiry_date DATE,
    card_data JSON,
    file_path VARCHAR(500) NOT NULL,
    qr_code_path VARCHAR(500),
    barcode_path VARCHAR(500),
    issued_by INT NOT NULL,
    status ENUM('active', 'expired', 'revoked', 'lost') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (template_id) REFERENCES id_card_templates(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Document approval workflow table
CREATE TABLE IF NOT EXISTS document_approval_workflow (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    approver_id INT NOT NULL,
    approval_level INT NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'skipped') DEFAULT 'pending',
    comments TEXT,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Document comments table
CREATE TABLE IF NOT EXISTS document_comments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    user_id INT NOT NULL,
    comment TEXT NOT NULL,
    parent_comment_id INT,
    is_resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_comment_id) REFERENCES document_comments(id) ON DELETE CASCADE
);

-- Document version history table
CREATE TABLE IF NOT EXISTS document_versions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_id INT NOT NULL,
    version_number VARCHAR(20) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    change_summary TEXT,
    uploaded_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Transcript generation requests table
CREATE TABLE IF NOT EXISTS transcript_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    requested_by INT NOT NULL,
    request_type ENUM('official', 'unofficial', 'partial') DEFAULT 'official',
    academic_years VARCHAR(255),
    purpose VARCHAR(255),
    delivery_method ENUM('pickup', 'email', 'mail') DEFAULT 'pickup',
    delivery_address TEXT,
    status ENUM('pending', 'processing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
    fee_amount DECIMAL(8,2) DEFAULT 0,
    payment_status ENUM('pending', 'paid', 'waived') DEFAULT 'pending',
    generated_file_path VARCHAR(500),
    processed_by INT,
    processed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert default document categories
INSERT IGNORE INTO document_categories (name, description, icon, color) VALUES
('Academic Records', 'Student transcripts, certificates, and academic documents', 'fas fa-graduation-cap', '#10B981'),
('Administrative', 'School policies, procedures, and administrative documents', 'fas fa-building', '#3B82F6'),
('Student Files', 'Individual student documents and records', 'fas fa-user-graduate', '#8B5CF6'),
('Staff Documents', 'Employee records and staff-related documents', 'fas fa-users', '#F59E0B'),
('Financial Records', 'Fee receipts, financial statements, and payment records', 'fas fa-dollar-sign', '#EF4444'),
('Legal Documents', 'Contracts, agreements, and legal paperwork', 'fas fa-gavel', '#6B7280'),
('Forms & Templates', 'Application forms, templates, and blank documents', 'fas fa-file-alt', '#06B6D4'),
('Reports', 'Academic reports, assessment reports, and analytics', 'fas fa-chart-bar', '#84CC16');

-- Show completion message
SELECT 'Document & File Management database schema created successfully!' as message;
