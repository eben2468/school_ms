<?php
/**
 * Salary Components API
 * GET  ?user_id=N   -> returns that staff member's earnings/deductions as JSON
 * POST {user_id, earnings:[{name,amount}], deductions:[{name,amount}]}
 *      -> replaces all components for the staff member, then syncs the gross
 *         salary (teacher_profiles.salary = sum of earnings) so payroll and the
 *         finance expense sync stay in step.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Ensure the table exists for this (possibly tenant) database.
$db->exec("
    CREATE TABLE IF NOT EXISTS salary_components (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        type ENUM('earning','deduction') NOT NULL,
        name VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user_type (user_id, type)
    )
");

$staff_roles = ['teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr'];

/** Validate that the id belongs to an active staff member. */
function isStaffMember($db, $user_id, $staff_roles) {
    $in = "'" . implode("','", $staff_roles) . "'";
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role IN ($in)");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $user_id = filter_input(INPUT_GET, 'user_id', FILTER_SANITIZE_NUMBER_INT);
    if (!$user_id || !isStaffMember($db, $user_id, $staff_roles)) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff member.']);
        exit();
    }

    $stmt = $db->prepare("SELECT type, name, amount FROM salary_components WHERE user_id = ? ORDER BY type, sort_order, id");
    $stmt->execute([$user_id]);

    $earnings = [];
    $deductions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $item = ['name' => $row['name'], 'amount' => number_format((float)$row['amount'], 2, '.', '')];
        if ($row['type'] === 'earning') {
            $earnings[] = $item;
        } else {
            $deductions[] = $item;
        }
    }

    // Current base/gross salary for the "Load standard template" helper.
    $salStmt = $db->prepare("SELECT salary FROM teacher_profiles WHERE user_id = ?");
    $salStmt->execute([$user_id]);
    $base = (float)($salStmt->fetchColumn() ?: 0);

    echo json_encode([
        'success' => true,
        'earnings' => $earnings,
        'deductions' => $deductions,
        'base_salary' => $base,
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $user_id = isset($payload['user_id']) ? (int)$payload['user_id'] : 0;
    if (!$user_id || !isStaffMember($db, $user_id, $staff_roles)) {
        echo json_encode(['success' => false, 'message' => 'Invalid staff member.']);
        exit();
    }

    // Normalise the incoming rows: keep only those with a name, clamp amount >= 0.
    $clean = function ($rows) {
        $out = [];
        if (!is_array($rows)) return $out;
        foreach ($rows as $r) {
            $name = trim($r['name'] ?? '');
            if ($name === '') continue;
            $amount = (float)($r['amount'] ?? 0);
            if ($amount < 0) $amount = 0;
            $out[] = ['name' => mb_substr($name, 0, 100), 'amount' => round($amount, 2)];
        }
        return $out;
    };

    $earnings = $clean($payload['earnings'] ?? []);
    $deductions = $clean($payload['deductions'] ?? []);

    try {
        $db->beginTransaction();

        // Replace existing components for this staff member.
        $db->prepare("DELETE FROM salary_components WHERE user_id = ?")->execute([$user_id]);

        $ins = $db->prepare("INSERT INTO salary_components (user_id, type, name, amount, sort_order) VALUES (?, ?, ?, ?, ?)");
        $order = 0;
        $gross = 0.0;
        foreach ($earnings as $e) {
            $ins->execute([$user_id, 'earning', $e['name'], $e['amount'], $order++]);
            $gross += $e['amount'];
        }
        $order = 0;
        $deduct_total = 0.0;
        foreach ($deductions as $d) {
            $ins->execute([$user_id, 'deduction', $d['name'], $d['amount'], $order++]);
            $deduct_total += $d['amount'];
        }

        // Keep the staff member's gross salary in sync with their earnings so the
        // payroll page, its stats, and the finance expense sync reflect changes.
        // Only do this when earnings were actually provided.
        if (!empty($earnings)) {
            $chk = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE user_id = ?");
            $chk->execute([$user_id]);
            if ($chk->fetchColumn() > 0) {
                $db->prepare("UPDATE teacher_profiles SET salary = ? WHERE user_id = ?")->execute([$gross, $user_id]);
            } else {
                $employee_id = 'EMP' . date('Y') . str_pad($user_id, 4, '0', STR_PAD_LEFT);
                $db->prepare("INSERT INTO teacher_profiles (user_id, salary, employee_id, joining_date, contract_type) VALUES (?, ?, ?, CURDATE(), 'full_time')")
                   ->execute([$user_id, $gross, $employee_id]);
            }
        }

        $db->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Salary components saved.',
            'gross' => round($gross, 2),
            'deductions_total' => round($deduct_total, 2),
            'net' => round($gross - $deduct_total, 2),
        ]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['success' => false, 'message' => 'Save failed: ' . $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
