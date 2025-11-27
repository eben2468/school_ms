-- Create base documents table
USE school_ms;

CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size INT NOT NULL,
    uploaded_by INT NOT NULL,
    document_type ENUM('certificate', 'transcript', 'report', 'policy', 'form', 'other') DEFAULT 'other',
    access_level ENUM('public', 'staff', 'students', 'parents', 'admin_only') DEFAULT 'staff',
    related_user_id INT,
    tags VARCHAR(500),
    version VARCHAR(20) DEFAULT '1.0',
    parent_document_id INT,
    is_archived BOOLEAN DEFAULT FALSE,
    download_count INT DEFAULT 0,
    last_accessed TIMESTAMP NULL,
    expiry_date DATE NULL,
    approval_status ENUM('pending', 'approved', 'rejected') DEFAULT 'approved',
    approved_by INT NULL,
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (parent_document_id) REFERENCES documents(id) ON DELETE SET NULL,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Insert sample document categories
INSERT IGNORE INTO document_categories (name, description, icon, color) VALUES
('Academic Records', 'Student transcripts, certificates, and academic documents', 'fas fa-graduation-cap', '#10B981'),
('Administrative', 'School policies, procedures, and administrative documents', 'fas fa-building', '#3B82F6'),
('Student Files', 'Individual student documents and records', 'fas fa-user-graduate', '#8B5CF6'),
('Staff Documents', 'Employee records and staff-related documents', 'fas fa-users', '#F59E0B'),
('Financial Records', 'Fee receipts, financial statements, and payment records', 'fas fa-dollar-sign', '#EF4444'),
('Legal Documents', 'Contracts, agreements, and legal paperwork', 'fas fa-gavel', '#6B7280'),
('Forms & Templates', 'Application forms, templates, and blank documents', 'fas fa-file-alt', '#06B6D4'),
('Reports', 'Academic reports, assessment reports, and analytics', 'fas fa-chart-bar', '#84CC16');

SELECT 'Documents table and categories created successfully!' as message;
