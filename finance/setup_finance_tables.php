<?php
/**
 * Finance Management Module - Database Setup Script
 * Creates all necessary tables and columns for the school finance system
 */

session_start();

require_once dirname(__DIR__) . '/config/database.php';
$database = new Database();
$db = $database->getConnection();

$results = [];
$errors = [];

try {
    // 1. Create finance_fee_categories table
    $sql = "CREATE TABLE IF NOT EXISTS finance_fee_categories (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_fee_categories";

    // Insert default fee categories if empty
    $checkCategories = $db->query("SELECT COUNT(*) FROM finance_fee_categories")->fetchColumn();
    if ($checkCategories == 0) {
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
            ('Other Charges', 'Miscellaneous / unclassified school charges')
        ");
        $results[] = "✅ Inserted default fee categories";
    }

    // 2. Create finance_fee_structures table
    $sql = "CREATE TABLE IF NOT EXISTS finance_fee_structures (
        id INT PRIMARY KEY AUTO_INCREMENT,
        academic_year_id INT NOT NULL,
        term_id INT NOT NULL,
        class_id INT NOT NULL,
        category_id INT NOT NULL,
        student_type ENUM('all', 'day', 'boarding') DEFAULT 'all',
        amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        is_mandatory TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (category_id) REFERENCES finance_fee_categories(id) ON DELETE CASCADE,
        FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
        FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_fee_structures";

    // 3. Create finance_invoices table
    $sql = "CREATE TABLE IF NOT EXISTS finance_invoices (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_number VARCHAR(50) UNIQUE NOT NULL,
        student_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        term_id INT NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        amount_paid DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        discount_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        penalty_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        due_date DATE NOT NULL,
        status ENUM('pending','partially_paid','paid','overdue','cancelled') DEFAULT 'pending',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
        FOREIGN KEY (term_id) REFERENCES academic_terms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_invoices";

    // 4. Create finance_invoice_items table
    $sql = "CREATE TABLE IF NOT EXISTS finance_invoice_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_id INT NOT NULL,
        category_id INT NOT NULL,
        description VARCHAR(255) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (category_id) REFERENCES finance_fee_categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_invoice_items";

    // 5. Create finance_payments table
    $sql = "CREATE TABLE IF NOT EXISTS finance_payments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        payment_method ENUM('cash','bank_transfer','mobile_money','online','other') NOT NULL DEFAULT 'cash',
        payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reference_number VARCHAR(100) DEFAULT NULL,
        receipt_number VARCHAR(50) UNIQUE NOT NULL,
        recorded_by INT NOT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_payments";

    // 6. Create finance_receipts table
    $sql = "CREATE TABLE IF NOT EXISTS finance_receipts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        receipt_number VARCHAR(50) UNIQUE NOT NULL,
        payment_id INT NOT NULL,
        generated_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (payment_id) REFERENCES finance_payments(id) ON DELETE CASCADE,
        FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_receipts";

    // 7. Create finance_discounts table
    $sql = "CREATE TABLE IF NOT EXISTS finance_discounts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        type ENUM('percentage','fixed_amount') NOT NULL DEFAULT 'percentage',
        value DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_discounts";

    // 8. Create finance_student_discounts table
    $sql = "CREATE TABLE IF NOT EXISTS finance_student_discounts (
        id INT PRIMARY KEY AUTO_INCREMENT,
        student_id INT NOT NULL,
        discount_id INT NOT NULL,
        academic_year_id INT NOT NULL,
        approved_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (discount_id) REFERENCES finance_discounts(id) ON DELETE CASCADE,
        FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_student_discounts";

    // 9. Create finance_penalties table
    $sql = "CREATE TABLE IF NOT EXISTS finance_penalties (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        type ENUM('late_payment','late_registration') NOT NULL DEFAULT 'late_payment',
        calculation_type ENUM('percentage','fixed_amount') NOT NULL DEFAULT 'fixed_amount',
        value DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        grace_period_days INT NOT NULL DEFAULT '0',
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_penalties";

    // 10. Create finance_student_penalties table
    $sql = "CREATE TABLE IF NOT EXISTS finance_student_penalties (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_id INT NOT NULL,
        penalty_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT '0.00',
        applied_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE,
        FOREIGN KEY (penalty_id) REFERENCES finance_penalties(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_student_penalties";

    // 11. Create finance_income table
    $sql = "CREATE TABLE IF NOT EXISTS finance_income (
        id INT PRIMARY KEY AUTO_INCREMENT,
        category VARCHAR(50) NOT NULL, -- donations, fundraising, uniform_sales, canteen, other
        amount DECIMAL(10,2) NOT NULL,
        description TEXT,
        income_date DATE NOT NULL,
        recorded_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_income";

    // 12. Create finance_expenses table
    $sql = "CREATE TABLE IF NOT EXISTS finance_expenses (
        id INT PRIMARY KEY AUTO_INCREMENT,
        category VARCHAR(50) NOT NULL, -- utilities, maintenance, Teaching Materials, Admin, Transport, Salaries, other
        amount DECIMAL(10,2) NOT NULL,
        description TEXT,
        vendor VARCHAR(255) DEFAULT NULL,
        expense_date DATE NOT NULL,
        recorded_by INT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'approved',
        approved_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_expenses";

    // 13. Create finance_audit_log table
    $sql = "CREATE TABLE IF NOT EXISTS finance_audit_log (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        action VARCHAR(100) NOT NULL,
        module VARCHAR(50) NOT NULL,
        record_id INT DEFAULT NULL,
        details TEXT,
        ip_address VARCHAR(45) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_audit_log";

    // 14. Create finance_payment_gateway_log table
    $sql = "CREATE TABLE IF NOT EXISTS finance_payment_gateway_log (
        id INT PRIMARY KEY AUTO_INCREMENT,
        invoice_id INT NOT NULL,
        gateway VARCHAR(50) NOT NULL,
        reference VARCHAR(100) UNIQUE NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status ENUM('initiated','success','failed') DEFAULT 'initiated',
        raw_response TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES finance_invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: finance_payment_gateway_log";

    // 15. Check and alter student_profiles to add student_type ENUM
    $columns = [];
    $stmt = $db->query("SHOW COLUMNS FROM student_profiles");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    if (!in_array('student_type', $columns)) {
        $db->exec("ALTER TABLE student_profiles ADD COLUMN student_type ENUM('day', 'boarding') DEFAULT 'day' AFTER admission_date");
        $results[] = "✅ Added 'student_type' column to student_profiles";
    } else {
        $results[] = "ℹ️ Column 'student_type' already exists in student_profiles";
    }

} catch (PDOException $e) {
    $errors[] = "❌ Database migration error: " . $e->getMessage();
}

$title = "Finance Module - Database Setup";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Module - Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-8">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full p-8">
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-coins text-green-600 text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Finance Module Setup</h1>
            <p class="text-gray-500 mt-2">Database tables and schema setup</p>
        </div>

        <div class="space-y-2 mb-6 max-h-80 overflow-y-auto pr-2">
            <?php foreach ($results as $result): ?>
            <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                <span class="text-sm"><?php echo $result; ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="space-y-2 mb-6">
            <h3 class="font-semibold text-red-600">Errors:</h3>
            <?php foreach ($errors as $error): ?>
            <div class="flex items-center p-3 bg-red-50 rounded-lg">
                <span class="text-sm text-red-700"><?php echo $error; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-8">
            <a href="index.php" class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                <i class="fas fa-arrow-right mr-2"></i> Go to Finance Overview
            </a>
        </div>
    </div>
</body>
</html>
