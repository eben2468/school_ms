<?php
/**
 * Idempotent schema self-healing helpers.
 * --------------------------------------------------------------------------
 * Tenant databases were provisioned at different times and some are missing
 * columns that newer code writes to. These helpers add any missing columns on
 * demand (safe to call repeatedly) so writes don't fail with "Unknown column".
 * Uses SHOW COLUMNS checks (portable across MySQL/MariaDB) rather than
 * ADD COLUMN IF NOT EXISTS.
 */

if (!function_exists('runDdlSafely')) {
    /**
     * Execute a single DDL statement portably across MySQL and MariaDB.
     *  - Strips MariaDB-only IF [NOT] EXISTS modifiers on ADD / CREATE INDEX /
     *    DROP COLUMN|INDEX|KEY (MySQL rejects these with syntax error 1064).
     *    CREATE TABLE / CREATE DATABASE IF NOT EXISTS are valid on both and kept.
     *  - Tolerates "already exists / doesn't exist" errors so it stays idempotent
     *    (safe to run repeatedly).
     * @return bool true on success or a tolerated no-op, false on a real failure.
     */
    function runDdlSafely($db, $sql) {
        $sql = preg_replace('/\bADD\s+(COLUMN\s+|INDEX\s+|KEY\s+|UNIQUE\s+KEY\s+|UNIQUE\s+INDEX\s+)?IF\s+NOT\s+EXISTS\s+/i', 'ADD ${1}', $sql);
        $sql = preg_replace('/\bCREATE\s+(UNIQUE\s+|FULLTEXT\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+/i', 'CREATE ${1}INDEX ', $sql);
        $sql = preg_replace('/\bDROP\s+(COLUMN\s+|INDEX\s+|KEY\s+)?IF\s+EXISTS\s+/i', 'DROP ${1}', $sql);
        try {
            $db->exec($sql);
            return true;
        } catch (PDOException $e) {
            $tolerated = [1060, 1061, 1062, 1050, 1068, 1091, 1005, 1022];
            $code = (int)($e->errorInfo[1] ?? $e->getCode());
            if (in_array($code, $tolerated, true)) {
                return true; // benign: definition already exists / already absent
            }
            error_log('runDdlSafely failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
            return false;
        }
    }
}

if (!function_exists('ensureColumns')) {
    /**
     * Add any missing columns to a table. $columns maps name => SQL definition.
     * Each column is checked individually so existing ones are left untouched.
     */
    function ensureColumns($db, $table, array $columns) {
        foreach ($columns as $name => $definition) {
            try {
                $chk = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($name));
                if ($chk && $chk->rowCount() === 0) {
                    $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
                }
            } catch (PDOException $e) {
                error_log("ensureColumns({$table}.{$name}) failed: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ensureAttendanceColumns')) {
    function ensureAttendanceColumns($db) {
        ensureColumns($db, 'attendance', [
            'time_in'  => 'TIME NULL',
            'time_out' => 'TIME NULL',
        ]);
    }
}

if (!function_exists('ensureAcademicRecordColumns')) {
    function ensureAcademicRecordColumns($db) {
        ensureColumns($db, 'student_academic_records', [
            'continuous_assessment' => "DECIMAL(5,2) NULL DEFAULT '0.00'",
            'mid_term_exam'         => 'DECIMAL(5,2) NULL',
            'final_exam'            => 'DECIMAL(5,2) NULL',
            'total_score'           => "DECIMAL(5,2) NULL DEFAULT '0.00'",
            'grade'                 => 'VARCHAR(5) NULL',
            'remarks'               => 'TEXT NULL',
        ]);
    }
}

if (!function_exists('ensureAssignmentColumns')) {
    function ensureAssignmentColumns($db) {
        ensureColumns($db, 'assignments', [
            'total_marks' => "INT NULL DEFAULT '100'",
        ]);
    }
}

if (!function_exists('ensureChatTables')) {
    /**
     * Provision the live-chat tables (chat_conversations, chat_messages,
     * chat_typing) in a tenant DB that predates the chat module. Cheap
     * fast-path: returns immediately if chat_conversations already exists.
     * Schema mirrors the central DB.
     */
    function ensureChatTables($db) {
        try {
            $chk = $db->query("SHOW TABLES LIKE 'chat_conversations'");
            if ($chk && $chk->rowCount() > 0) {
                return; // already provisioned
            }
        } catch (PDOException $e) {
            // fall through and attempt creation
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS chat_conversations (
                id INT(11) NOT NULL AUTO_INCREMENT,
                user_id INT(11) NOT NULL,
                support_agent_id INT(11) DEFAULT NULL,
                subject VARCHAR(255) NOT NULL,
                status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
                priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY user_id (user_id), KEY support_agent_id (support_agent_id),
                CONSTRAINT chat_conversations_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT chat_conversations_ibfk_2 FOREIGN KEY (support_agent_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS chat_messages (
                id INT(11) NOT NULL AUTO_INCREMENT,
                conversation_id INT(11) NOT NULL,
                sender_id INT(11) NOT NULL,
                message TEXT NOT NULL,
                message_type ENUM('text','file','system') DEFAULT 'text',
                file_path VARCHAR(500) DEFAULT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY conversation_id (conversation_id), KEY sender_id (sender_id),
                CONSTRAINT chat_messages_ibfk_1 FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
                CONSTRAINT chat_messages_ibfk_2 FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS chat_typing (
                id INT(11) NOT NULL AUTO_INCREMENT,
                conversation_id INT(11) NOT NULL,
                user_id INT(11) NOT NULL,
                is_typing TINYINT(1) DEFAULT 1,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY unique_conversation_user (conversation_id, user_id), KEY user_id (user_id),
                CONSTRAINT chat_typing_ibfk_1 FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
                CONSTRAINT chat_typing_ibfk_2 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];

        foreach ($statements as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                error_log("ensureChatTables failed: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ensureLiveChatTables')) {
    /**
     * Provision the live-chat module tables (live_chat_*) in any DB that predates
     * the module. Mirrors communication/setup_live_chat_db.php. Cheap fast-path:
     * returns immediately when live_chat_rooms already exists. Tables are created
     * in FK-dependency order; each runs independently so one failure doesn't abort
     * the rest. Uses the standardized utf8mb4_general_ci collation (FKs here are on
     * INT columns, so collation differences don't affect them).
     */
    function ensureLiveChatTables($db) {
        // Fast-path only when ALL tables are present — a partial set (e.g. rooms
        // exists but live_chat_user_status is missing) must still be healed.
        try {
            $cnt = (int)$db->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name IN (
                   'live_chat_rooms','live_chat_participants','live_chat_messages',
                   'live_chat_user_status','live_chat_message_reactions',
                   'live_chat_message_reads','live_chat_blocked_users','live_chat_reports')"
            )->fetchColumn();
            if ($cnt >= 8) {
                return; // fully provisioned
            }
        } catch (PDOException $e) {
            // fall through and attempt creation
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS live_chat_rooms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                room_type ENUM('public','private','class','department','admin_only') DEFAULT 'public',
                created_by INT NOT NULL,
                max_participants INT DEFAULT 100,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_room_type (room_type), INDEX idx_is_active (is_active), INDEX idx_created_at (created_at),
                CONSTRAINT live_chat_rooms_ibfk_1 FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('member','moderator','admin') DEFAULT 'member',
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                is_muted BOOLEAN DEFAULT FALSE,
                is_banned BOOLEAN DEFAULT FALSE,
                UNIQUE KEY unique_room_user (room_id, user_id),
                INDEX idx_room_id (room_id), INDEX idx_user_id (user_id), INDEX idx_role (role), INDEX idx_last_seen (last_seen),
                CONSTRAINT live_chat_participants_ibfk_1 FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_participants_ibfk_2 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                message_type ENUM('text','file','image','system','announcement') DEFAULT 'text',
                file_path VARCHAR(500) NULL, file_name VARCHAR(255) NULL, file_size INT NULL,
                reply_to_message_id INT NULL,
                is_edited BOOLEAN DEFAULT FALSE, edited_at TIMESTAMP NULL,
                is_deleted BOOLEAN DEFAULT FALSE, deleted_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_room_created (room_id, created_at), INDEX idx_sender_id (sender_id),
                INDEX idx_message_type (message_type), INDEX idx_reply_to (reply_to_message_id), INDEX idx_is_deleted (is_deleted),
                CONSTRAINT live_chat_messages_ibfk_1 FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_messages_ibfk_2 FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_messages_ibfk_3 FOREIGN KEY (reply_to_message_id) REFERENCES live_chat_messages(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_user_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                room_id INT NULL,
                status ENUM('online','away','busy','offline') DEFAULT 'offline',
                is_typing BOOLEAN DEFAULT FALSE,
                typing_in_room_id INT NULL,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                custom_status VARCHAR(100) NULL,
                UNIQUE KEY unique_user_room (user_id, room_id),
                INDEX idx_user_id (user_id), INDEX idx_status (status), INDEX idx_last_activity (last_activity), INDEX idx_typing (is_typing, typing_in_room_id),
                CONSTRAINT live_chat_user_status_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_user_status_ibfk_2 FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_user_status_ibfk_3 FOREIGN KEY (typing_in_room_id) REFERENCES live_chat_rooms(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_message_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                user_id INT NOT NULL,
                reaction_emoji VARCHAR(10) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_message_user_reaction (message_id, user_id, reaction_emoji),
                INDEX idx_message_id (message_id), INDEX idx_user_id (user_id),
                CONSTRAINT live_chat_reactions_ibfk_1 FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reactions_ibfk_2 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_message_reads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                user_id INT NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_message_user (message_id, user_id),
                INDEX idx_message_id (message_id), INDEX idx_user_id (user_id), INDEX idx_read_at (read_at),
                CONSTRAINT live_chat_reads_ibfk_1 FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reads_ibfk_2 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_blocked_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                blocker_id INT NOT NULL,
                blocked_id INT NOT NULL,
                reason VARCHAR(255) NULL,
                blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_blocker_blocked (blocker_id, blocked_id),
                INDEX idx_blocker_id (blocker_id), INDEX idx_blocked_id (blocked_id),
                CONSTRAINT live_chat_blocked_ibfk_1 FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_blocked_ibfk_2 FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            "CREATE TABLE IF NOT EXISTS live_chat_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reporter_id INT NOT NULL,
                reported_user_id INT NULL,
                message_id INT NULL,
                room_id INT NOT NULL,
                report_type ENUM('spam','harassment','inappropriate_content','other') NOT NULL,
                description TEXT NOT NULL,
                status ENUM('pending','reviewed','resolved','dismissed') DEFAULT 'pending',
                reviewed_by INT NULL, reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_reporter_id (reporter_id), INDEX idx_reported_user_id (reported_user_id), INDEX idx_status (status), INDEX idx_created_at (created_at),
                CONSTRAINT live_chat_reports_ibfk_1 FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reports_ibfk_2 FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reports_ibfk_3 FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reports_ibfk_4 FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                CONSTRAINT live_chat_reports_ibfk_5 FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];

        foreach ($statements as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                error_log("ensureLiveChatTables failed: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ensureNadicsAiTable')) {
    /**
     * Provision the Nadics AI interaction log table. Cheap fast-path: returns
     * immediately when it already exists. Logs are kept per-tenant for quality
     * review. No foreign key (keeps logs even if a user is removed, and avoids
     * collation/engine mismatches across tenant DBs).
     */
    function ensureNadicsAiTable($db) {
        try {
            $chk = $db->query("SHOW TABLES LIKE 'nadics_ai_logs'");
            if ($chk && $chk->rowCount() > 0) {
                return; // already provisioned
            }
        } catch (PDOException $e) {
            // fall through and attempt creation
        }

        $sql = "CREATE TABLE IF NOT EXISTS nadics_ai_logs (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) DEFAULT NULL,
            user_role VARCHAR(50) DEFAULT NULL,
            user_message TEXT NOT NULL,
            ai_reply MEDIUMTEXT NULL,
            source VARCHAR(30) DEFAULT NULL,
            success TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";

        try {
            $db->exec($sql);
        } catch (PDOException $e) {
            error_log("ensureNadicsAiTable failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('ensureFinanceTables')) {
    /**
     * Provision the finance module tables in a tenant DB that predates the
     * finance module. Cheap fast-path: returns immediately when finance_invoices
     * already exists. Mirrors finance/setup_finance_tables.php. Each statement
     * runs independently so one failure doesn't abort the rest.
     */
    function ensureFinanceTables($db) {
        try {
            $chk = $db->query("SHOW TABLES LIKE 'finance_invoices'");
            if ($chk && $chk->rowCount() > 0) {
                return; // already provisioned
            }
        } catch (PDOException $e) {
            // fall through and attempt creation
        }

        $statements = [
            "CREATE TABLE IF NOT EXISTS finance_fee_categories (
                id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL, description TEXT,
                status ENUM('active','inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_fee_structures (
                id INT PRIMARY KEY AUTO_INCREMENT, academic_year_id INT NOT NULL, term_id INT NOT NULL,
                class_id INT NOT NULL, category_id INT NOT NULL,
                student_type ENUM('all','day','boarding') DEFAULT 'all',
                amount DECIMAL(10,2) NOT NULL DEFAULT '0.00', is_mandatory TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (category_id) REFERENCES finance_fee_categories(id) ON DELETE CASCADE,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
                FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_invoices (
                id INT PRIMARY KEY AUTO_INCREMENT, invoice_number VARCHAR(50) UNIQUE NOT NULL,
                student_id INT NOT NULL, academic_year_id INT NOT NULL, term_id INT NOT NULL,
                total_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00', amount_paid DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                discount_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00', penalty_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                due_date DATE NOT NULL,
                status ENUM('pending','partially_paid','paid','overdue','cancelled') DEFAULT 'pending',
                notes TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
                FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_invoice_items (
                id INT PRIMARY KEY AUTO_INCREMENT, invoice_id INT NOT NULL, category_id INT NOT NULL,
                description VARCHAR(255) NOT NULL, amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (category_id) REFERENCES finance_fee_categories(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_payments (
                id INT PRIMARY KEY AUTO_INCREMENT, invoice_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL,
                payment_method ENUM('cash','bank_transfer','mobile_money','online','other') NOT NULL DEFAULT 'cash',
                payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, reference_number VARCHAR(100) DEFAULT NULL,
                receipt_number VARCHAR(50) UNIQUE NOT NULL, recorded_by INT NOT NULL, notes TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_receipts (
                id INT PRIMARY KEY AUTO_INCREMENT, receipt_number VARCHAR(50) UNIQUE NOT NULL,
                payment_id INT NOT NULL, generated_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (payment_id) REFERENCES finance_payments(id) ON DELETE CASCADE,
                FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_discounts (
                id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL,
                type ENUM('percentage','fixed_amount') NOT NULL DEFAULT 'percentage',
                value DECIMAL(10,2) NOT NULL DEFAULT '0.00', status ENUM('active','inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_student_discounts (
                id INT PRIMARY KEY AUTO_INCREMENT, student_id INT NOT NULL, discount_id INT NOT NULL,
                academic_year_id INT NOT NULL, approved_by INT NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (discount_id) REFERENCES finance_discounts(id) ON DELETE CASCADE,
                FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_penalties (
                id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL,
                type ENUM('late_payment','late_registration') NOT NULL DEFAULT 'late_payment',
                calculation_type ENUM('percentage','fixed_amount') NOT NULL DEFAULT 'fixed_amount',
                value DECIMAL(10,2) NOT NULL DEFAULT '0.00', grace_period_days INT NOT NULL DEFAULT '0',
                status ENUM('active','inactive') DEFAULT 'active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_student_penalties (
                id INT PRIMARY KEY AUTO_INCREMENT, invoice_id INT NOT NULL, penalty_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT '0.00', applied_date DATE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (penalty_id) REFERENCES finance_penalties(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_income (
                id INT PRIMARY KEY AUTO_INCREMENT, category VARCHAR(50) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                description TEXT, income_date DATE NOT NULL, recorded_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_expenses (
                id INT PRIMARY KEY AUTO_INCREMENT, category VARCHAR(50) NOT NULL, amount DECIMAL(10,2) NOT NULL,
                description TEXT, vendor VARCHAR(255) DEFAULT NULL, expense_date DATE NOT NULL, recorded_by INT NOT NULL,
                status ENUM('pending','approved','rejected') DEFAULT 'approved', approved_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_audit_log (
                id INT PRIMARY KEY AUTO_INCREMENT, user_id INT NOT NULL, action VARCHAR(100) NOT NULL,
                module VARCHAR(50) NOT NULL, record_id INT DEFAULT NULL, details TEXT, ip_address VARCHAR(45) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS finance_payment_gateway_log (
                id INT PRIMARY KEY AUTO_INCREMENT, invoice_id INT NOT NULL, gateway VARCHAR(50) NOT NULL,
                reference VARCHAR(100) UNIQUE NOT NULL, amount DECIMAL(10,2) NOT NULL,
                status ENUM('initiated','success','failed') DEFAULT 'initiated', raw_response TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];

        foreach ($statements as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                error_log("ensureFinanceTables failed: " . $e->getMessage());
            }
        }

        // Seed default fee categories (only if the table is empty).
        try {
            if ((int)$db->query("SELECT COUNT(*) FROM finance_fee_categories")->fetchColumn() === 0) {
                $db->exec("INSERT INTO finance_fee_categories (name, description) VALUES
                    ('Tuition Fees', 'Standard instructional fees per term'),
                    ('PTA Fees', 'Parent-Teacher Association levies'),
                    ('Examination Fees', 'Termly exam and assessment printing fees'),
                    ('ICT Fees', 'Computer lab access and internet facility levies'),
                    ('Sports Fees', 'Athletic and sporting activities and equipment fees'),
                    ('Library Fees', 'Library access and book maintenance fees'),
                    ('Boarding Fees', 'Hostel accommodation and boarding facility charges'),
                    ('Feeding Fees', 'School meals / canteen feeding plans'),
                    ('Transportation Fees', 'School bus and transit route fees'),
                    ('Other Charges', 'Miscellaneous / unclassified school charges')");
            }
        } catch (PDOException $e) {
            error_log("ensureFinanceTables seed categories failed: " . $e->getMessage());
        }

        // student_type column used by fee structures.
        try {
            $cols = $db->query("SHOW COLUMNS FROM student_profiles LIKE 'student_type'");
            if ($cols && $cols->rowCount() === 0) {
                $db->exec("ALTER TABLE student_profiles ADD COLUMN student_type ENUM('day','boarding') DEFAULT 'day'");
            }
        } catch (PDOException $e) {
            error_log("ensureFinanceTables student_type failed: " . $e->getMessage());
        }
    }
}

if (!function_exists('ensureStudentProfileColumns')) {
    /**
     * Ensure student_profiles has the optional profile columns the edit forms
     * write to. No-op for columns that already exist.
     */
    function ensureStudentProfileColumns($db) {
        $columns = [
            'previous_school'  => 'VARCHAR(255) NULL',
            'nationality'      => 'VARCHAR(100) NULL',
            'religion'         => 'VARCHAR(100) NULL',
            'parent_name'      => 'VARCHAR(100) NULL',
            'parent_phone'     => 'VARCHAR(20) NULL',
            'parent_email'     => 'VARCHAR(100) NULL',
            'admission_number' => 'VARCHAR(50) NULL',
            'class_id'         => 'INT NULL',
            'student_type'     => "ENUM('day','boarding') NULL DEFAULT 'day'",
        ];
        foreach ($columns as $name => $definition) {
            try {
                $chk = $db->query("SHOW COLUMNS FROM student_profiles LIKE " . $db->quote($name));
                if ($chk && $chk->rowCount() === 0) {
                    $db->exec("ALTER TABLE student_profiles ADD COLUMN `$name` $definition");
                }
            } catch (PDOException $e) {
                error_log("ensureStudentProfileColumns({$name}) failed: " . $e->getMessage());
            }
        }
    }
}

if (!function_exists('ensureTeacherProfileColumns')) {
    /**
     * Ensure teacher_profiles has the optional staff columns the staff create /
     * edit forms and the staff bulk importer write to. Older tenant databases
     * only shipped the core columns, so adding/importing staff would otherwise
     * fail with "Unknown column". No-op for columns that already exist.
     */
    function ensureTeacherProfileColumns($db) {
        ensureColumns($db, 'teacher_profiles', [
            'department_id'              => 'INT NULL',
            'position'                   => 'VARCHAR(100) NULL',
            'specialization'             => 'VARCHAR(150) NULL',
            'contract_type'              => "VARCHAR(20) NULL DEFAULT 'full_time'",
            'national_id'                => 'VARCHAR(50) NULL',
            'marital_status'             => 'VARCHAR(20) NULL',
            'nationality'                => 'VARCHAR(100) NULL',
            'city'                       => 'VARCHAR(100) NULL',
            'state_region'               => 'VARCHAR(100) NULL',
            'postal_code'                => 'VARCHAR(20) NULL',
            'emergency_contact_name'     => 'VARCHAR(100) NULL',
            'emergency_contact_phone'    => 'VARCHAR(20) NULL',
            'emergency_contact_relation' => 'VARCHAR(50) NULL',
            'bank_name'                  => 'VARCHAR(100) NULL',
            'bank_account'               => 'VARCHAR(50) NULL',
            'bank_branch'                => 'VARCHAR(100) NULL',
            'bio'                        => 'TEXT NULL',
            'signature_image'            => 'VARCHAR(255) NULL',
            // HR fields edited from users/edit.php (and now staff/edit.php). These
            // existed only on the central DB, so saving staff from a tenant via
            // users/edit.php failed with "Unknown column" until self-healed here.
            'employment_status'          => "ENUM('active','on_leave','suspended','terminated','retired') NULL DEFAULT 'active'",
            'tax_id'                     => 'VARCHAR(50) NULL',
            'contract_end_date'          => 'DATE NULL',
        ]);
    }
}

if (!function_exists('ensureSignatureColumns')) {
    /**
     * Ensure school_settings carries the digital-signature columns used to embed
     * institutional signatures (headmaster, accountant, HR, registrar) on printed
     * documents, plus the master on/off toggle. Cheap fast-path: returns once the
     * sentinel column (signatures_enabled) exists, since all are added together.
     */
    function ensureSignatureColumns($db) {
        try {
            $chk = $db->query("SHOW COLUMNS FROM school_settings LIKE 'signatures_enabled'");
            if ($chk && $chk->rowCount() > 0) {
                return; // already provisioned
            }
        } catch (PDOException $e) {
            return; // table missing; nothing to heal here
        }

        $columns = ['signatures_enabled' => "ENUM('0','1') NOT NULL DEFAULT '0'"];
        foreach (['headmaster', 'accountant', 'hr', 'registrar'] as $slot) {
            $columns["signature_{$slot}"]        = 'VARCHAR(255) NULL';
            $columns["signature_{$slot}_name"]   = 'VARCHAR(150) NULL';
            $columns["signature_{$slot}_title"]  = 'VARCHAR(150) NULL';
        }
        ensureColumns($db, 'school_settings', $columns);
    }
}

if (!function_exists('ensureLibraryBooksColumns')) {
    /**
     * Ensure library_books has the descriptive/inventory columns the library
     * pages read and write (most importantly total_copies, which the books
     * listing aggregates with SUM()). Older tenant databases are missing these,
     * which would 500 the library page and break book create / import.
     */
    function ensureLibraryBooksColumns($db) {
        // total_copies is brand new on most tenants; back-fill it from
        // copies_available once, immediately after it is first added.
        $needsBackfill = false;
        try {
            $chk = $db->query("SHOW COLUMNS FROM library_books LIKE 'total_copies'");
            $needsBackfill = ($chk && $chk->rowCount() === 0);
        } catch (PDOException $e) {
            // table may not exist yet; tenant_provisioning will create it
        }

        ensureColumns($db, 'library_books', [
            'total_copies'     => 'INT NULL DEFAULT 1',
            'description'      => 'TEXT NULL',
            'publisher'        => 'VARCHAR(100) NULL',
            'publication_year' => 'INT NULL',
            'language'         => 'VARCHAR(50) NULL',
            'location'         => 'VARCHAR(100) NULL',
            'cover_image'      => 'VARCHAR(255) NULL',
            'updated_at'       => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        ]);

        if ($needsBackfill) {
            try {
                // Existing rows all defaulted to 1; set total to the on-hand count.
                $db->exec("UPDATE library_books SET total_copies = copies_available WHERE total_copies IS NULL OR total_copies = 1");
            } catch (PDOException $e) {
                error_log("ensureLibraryBooksColumns backfill failed: " . $e->getMessage());
            }
        }
    }
}
