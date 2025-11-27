-- Online Learning Tools Database Schema
USE school_ms;

-- Virtual classroom sessions table
CREATE TABLE IF NOT EXISTS virtual_classrooms (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    teacher_id INT NOT NULL,
    class_id INT,
    subject_id INT,
    meeting_url VARCHAR(500),
    meeting_id VARCHAR(100),
    meeting_password VARCHAR(100),
    platform ENUM('zoom', 'google_meet', 'teams', 'other') DEFAULT 'zoom',
    scheduled_date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    duration_minutes INT DEFAULT 60,
    max_participants INT DEFAULT 50,
    status ENUM('scheduled', 'active', 'completed', 'cancelled') DEFAULT 'scheduled',
    recording_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

-- Learning materials table
CREATE TABLE IF NOT EXISTS learning_materials (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    file_size INT NOT NULL,
    material_type ENUM('document', 'video', 'audio', 'presentation', 'link', 'other') NOT NULL,
    uploaded_by INT NOT NULL,
    class_id INT,
    subject_id INT,
    access_level ENUM('public', 'class_only', 'subject_only', 'private') DEFAULT 'class_only',
    download_count INT DEFAULT 0,
    is_featured BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

-- Online quizzes table
CREATE TABLE IF NOT EXISTS online_quizzes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    teacher_id INT NOT NULL,
    class_id INT,
    subject_id INT,
    total_questions INT NOT NULL,
    total_marks INT NOT NULL,
    time_limit_minutes INT DEFAULT 60,
    attempts_allowed INT DEFAULT 1,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    show_results BOOLEAN DEFAULT TRUE,
    randomize_questions BOOLEAN DEFAULT FALSE,
    status ENUM('draft', 'published', 'completed', 'archived') DEFAULT 'draft',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
);

-- Quiz questions table
CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quiz_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice', 'true_false', 'short_answer', 'essay') NOT NULL,
    marks INT DEFAULT 1,
    option_a VARCHAR(500),
    option_b VARCHAR(500),
    option_c VARCHAR(500),
    option_d VARCHAR(500),
    correct_answer TEXT NOT NULL,
    explanation TEXT,
    question_order INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES online_quizzes(id) ON DELETE CASCADE
);

-- Student quiz attempts table
CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    quiz_id INT NOT NULL,
    student_id INT NOT NULL,
    attempt_number INT DEFAULT 1,
    start_time DATETIME NOT NULL,
    end_time DATETIME,
    total_marks_obtained DECIMAL(5,2) DEFAULT 0,
    percentage DECIMAL(5,2) DEFAULT 0,
    status ENUM('in_progress', 'completed', 'submitted', 'timed_out') DEFAULT 'in_progress',
    time_taken_minutes INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (quiz_id) REFERENCES online_quizzes(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Student quiz answers table
CREATE TABLE IF NOT EXISTS quiz_answers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    student_answer TEXT,
    marks_obtained DECIMAL(5,2) DEFAULT 0,
    is_correct BOOLEAN DEFAULT FALSE,
    answered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
);

-- Assignment submissions table (enhanced)
CREATE TABLE IF NOT EXISTS assignment_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    assignment_id INT NOT NULL,
    student_id INT NOT NULL,
    submission_text TEXT,
    file_path VARCHAR(500),
    file_name VARCHAR(255),
    file_size INT,
    submission_type ENUM('text', 'file', 'both') NOT NULL,
    plagiarism_score DECIMAL(5,2),
    plagiarism_report TEXT,
    grade DECIMAL(5,2),
    feedback TEXT,
    status ENUM('draft', 'submitted', 'graded', 'returned') DEFAULT 'draft',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    graded_at TIMESTAMP NULL,
    FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Discussion boards table
CREATE TABLE IF NOT EXISTS discussion_boards (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    class_id INT,
    subject_id INT,
    created_by INT NOT NULL,
    is_pinned BOOLEAN DEFAULT FALSE,
    is_locked BOOLEAN DEFAULT FALSE,
    post_count INT DEFAULT 0,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
);

-- Discussion posts table
CREATE TABLE IF NOT EXISTS discussion_posts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    board_id INT NOT NULL,
    parent_post_id INT,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    attachment_path VARCHAR(500),
    attachment_name VARCHAR(255),
    is_solution BOOLEAN DEFAULT FALSE,
    likes_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (board_id) REFERENCES discussion_boards(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_post_id) REFERENCES discussion_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Discussion post likes table
CREATE TABLE IF NOT EXISTS discussion_post_likes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    post_id INT NOT NULL,
    user_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (post_id) REFERENCES discussion_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_like (post_id, user_id)
);

-- Virtual classroom participants table
CREATE TABLE IF NOT EXISTS virtual_classroom_participants (
    id INT PRIMARY KEY AUTO_INCREMENT,
    classroom_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at TIMESTAMP NULL,
    left_at TIMESTAMP NULL,
    attendance_duration_minutes INT DEFAULT 0,
    participation_score DECIMAL(3,2) DEFAULT 0,
    status ENUM('invited', 'joined', 'left', 'absent') DEFAULT 'invited',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (classroom_id) REFERENCES virtual_classrooms(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Learning material access logs
CREATE TABLE IF NOT EXISTS material_access_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    material_id INT NOT NULL,
    user_id INT NOT NULL,
    access_type ENUM('view', 'download', 'share') NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    accessed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES learning_materials(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Show completion message
SELECT 'Online Learning Tools database schema created successfully!' as message;
