<?php
/**
 * Staff Payroll - Database Migration Script
 * Fixes inconsistent column types in salary_payments table
 * and prepares finance_expenses for salary sync.
 * 
 * Run once: C:\xampp\php\php.exe staff/migrate_payroll_db.php
 */

session_start();

require_once dirname(__DIR__) . '/config/database.php';
$database = new Database();
$db = $database->getConnection();

$results = [];
$errors = [];

try {
    $db->beginTransaction();

    // ─── Step 1: Convert text month names to integers ───
    $monthMap = [
        'January' => 1, 'February' => 2, 'March' => 3, 'April' => 4,
        'May' => 5, 'June' => 6, 'July' => 7, 'August' => 8,
        'September' => 9, 'October' => 10, 'November' => 11, 'December' => 12
    ];

    $rows = $db->query("SELECT id, month FROM salary_payments")->fetchAll(PDO::FETCH_ASSOC);
    $converted = 0;
    foreach ($rows as $row) {
        $val = trim($row['month']);
        // If it's already numeric, skip
        if (is_numeric($val) && (int)$val >= 1 && (int)$val <= 12) {
            continue;
        }
        // Try text lookup
        if (isset($monthMap[$val])) {
            $stmt = $db->prepare("UPDATE salary_payments SET month = :m WHERE id = :id");
            $stmt->execute([':m' => (string)$monthMap[$val], ':id' => $row['id']]);
            $converted++;
        } else {
            $errors[] = "⚠️ Row #{$row['id']}: Unknown month value '{$val}' — skipped";
        }
    }
    $results[] = "✅ Converted $converted text month values to integers";

    // ─── Step 2: Alter month column to TINYINT ───
    try {
        $db->exec("ALTER TABLE salary_payments MODIFY COLUMN month TINYINT NOT NULL");
        $results[] = "✅ Changed salary_payments.month → TINYINT NOT NULL";
    } catch (PDOException $e) {
        $errors[] = "⚠️ Could not alter month column: " . $e->getMessage();
    }

    // ─── Step 3: Alter payment_method to VARCHAR(50) ───
    try {
        $db->exec("ALTER TABLE salary_payments MODIFY COLUMN payment_method VARCHAR(50) DEFAULT NULL");
        $results[] = "✅ Changed salary_payments.payment_method → VARCHAR(50)";
    } catch (PDOException $e) {
        $errors[] = "⚠️ Could not alter payment_method column: " . $e->getMessage();
    }

    // ─── Step 4: Alter status to include 'partial' ───
    try {
        $db->exec("ALTER TABLE salary_payments MODIFY COLUMN status ENUM('pending','partial','paid','cancelled','failed') DEFAULT 'pending'");
        $results[] = "✅ Changed salary_payments.status → ENUM('pending','partial','paid','cancelled','failed')";
    } catch (PDOException $e) {
        $errors[] = "⚠️ Could not alter status column: " . $e->getMessage();
    }

    // ─── Step 5: Verify user_id FK exists ───
    $fkCheck = $db->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'salary_payments'
          AND COLUMN_NAME = 'user_id'
          AND REFERENCED_TABLE_NAME = 'users'
    ")->fetch(PDO::FETCH_ASSOC);

    if ($fkCheck) {
        $results[] = "✅ FK salary_payments.user_id → users(id) already exists ({$fkCheck['CONSTRAINT_NAME']})";
    } else {
        try {
            $db->exec("ALTER TABLE salary_payments ADD CONSTRAINT fk_salary_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
            $results[] = "✅ Added FK: salary_payments.user_id → users(id)";
        } catch (PDOException $e) {
            $errors[] = "⚠️ Could not add FK for user_id: " . $e->getMessage();
        }
    }

    // ─── Step 6: Add salary_payment_id column to finance_expenses ───
    $expCols = [];
    $colStmt = $db->query("SHOW COLUMNS FROM finance_expenses");
    while ($col = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $expCols[] = $col['Field'];
    }

    if (!in_array('salary_payment_id', $expCols)) {
        try {
            $db->exec("ALTER TABLE finance_expenses ADD COLUMN salary_payment_id INT DEFAULT NULL");
            $results[] = "✅ Added column: finance_expenses.salary_payment_id";
        } catch (PDOException $e) {
            $errors[] = "⚠️ Could not add salary_payment_id column: " . $e->getMessage();
        }

        // Add FK
        try {
            $db->exec("ALTER TABLE finance_expenses ADD CONSTRAINT fk_expense_salary FOREIGN KEY (salary_payment_id) REFERENCES salary_payments(id) ON DELETE SET NULL");
            $results[] = "✅ Added FK: finance_expenses.salary_payment_id → salary_payments(id)";
        } catch (PDOException $e) {
            $errors[] = "⚠️ Could not add FK for salary_payment_id: " . $e->getMessage();
        }
    } else {
        $results[] = "ℹ️ Column finance_expenses.salary_payment_id already exists";
    }

    if ($db->inTransaction()) {
        $db->commit();
        $results[] = "✅ Migration committed successfully";
    } else {
        $results[] = "✅ Migration completed successfully (DDL auto-committed)";
    }

    // ─── Verification: Show final table structure ───
    $results[] = "";
    $results[] = "── Final salary_payments structure ──";
    $finalCols = $db->query("DESCRIBE salary_payments")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($finalCols as $c) {
        $results[] = "   {$c['Field']} ({$c['Type']})";
    }

    $results[] = "";
    $results[] = "── Existing data verification ──";
    $count = $db->query("SELECT COUNT(*) FROM salary_payments")->fetchColumn();
    $results[] = "   Total rows: $count";
    if ($count > 0) {
        $sample = $db->query("SELECT id, user_id, month, year, status, payment_method FROM salary_payments LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sample as $s) {
            $results[] = "   Row #{$s['id']}: user={$s['user_id']}, month={$s['month']}, year={$s['year']}, status={$s['status']}, method={$s['payment_method']}";
        }
    }

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $errors[] = "❌ Migration failed: " . $e->getMessage();
}

// ─── Output ───
echo "=== Staff Payroll DB Migration ===\n\n";

foreach ($results as $r) {
    echo "$r\n";
}

if (!empty($errors)) {
    echo "\n--- Warnings/Errors ---\n";
    foreach ($errors as $e) {
        echo "$e\n";
    }
}

echo "\nDone.\n";
