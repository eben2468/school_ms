<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Required parameters
$staff_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$month = filter_input(INPUT_GET, 'month', FILTER_SANITIZE_NUMBER_INT) ?: (int)date('m');
$year = filter_input(INPUT_GET, 'year', FILTER_SANITIZE_NUMBER_INT) ?: (int)date('Y');

if (!$staff_id) {
    header("Location: payroll.php");
    exit();
}

// Fetch school settings
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
$accountant_sig = getSchoolSignature('accountant');
$hr_sig = getSchoolSignature('hr');
$school_name = getSchoolSetting('school_name', 'School Management System');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$school_website = getSchoolSetting('school_website', '');
$principal_name = getSchoolSetting('principal_name', '');
$currency = getSchoolSetting('currency_symbol', '₵');
$currency_code = getSchoolSetting('currency', 'GHS');
$school_logo = getSchoolLogo();

// School motto & postal from academic_settings (to match the account statement header)
$school_motto = '';
$school_postal = '';
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    $motto_result = $motto_stmt->fetch(PDO::FETCH_ASSOC);
    if ($motto_result) $school_motto = $motto_result['setting_value'];

    $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
    $postal_stmt->execute();
    $postal_result = $postal_stmt->fetch(PDO::FETCH_ASSOC);
    if ($postal_result) $school_postal = $postal_result['setting_value'];
} catch (PDOException $e) {
    // Settings not available yet
}

// Fetch staff details
$staffStmt = $db->prepare("
    SELECT u.*, tp.employee_id, tp.department, tp.salary, tp.position, tp.contract_type,
           tp.joining_date, tp.bank_name, tp.bank_account, tp.bank_branch,
           tp.qualification
    FROM users u
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE u.id = :id
");
$staffStmt->execute([':id' => $staff_id]);
$staff = $staffStmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header("Location: payroll.php");
    exit();
}

// Fetch payment record for this month
$payStmt = $db->prepare("
    SELECT * FROM salary_payments
    WHERE user_id = :uid AND month = :month AND year = :year
    LIMIT 1
");
$payStmt->execute([':uid' => $staff_id, ':month' => $month, ':year' => $year]);
$payment = $payStmt->fetch(PDO::FETCH_ASSOC);

// Calculate salary components
$baseSalary = floatval($staff['salary'] ?? 0);
$paidAmount = $payment ? floatval($payment['amount']) : $baseSalary;

// ---- Salary breakdown ----
// Manually-defined components (set on staff/salaries.php) are the source of
// truth. If a staff member has none yet, fall back to the standard percentage
// template so existing payslips still render.
$earnings = [];
$deductions = [];
try {
    $compStmt = $db->prepare("SELECT type, name, amount FROM salary_components WHERE user_id = :uid ORDER BY type, sort_order, id");
    $compStmt->execute([':uid' => $staff_id]);
    foreach ($compStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ($c['type'] === 'earning') {
            $earnings[] = [$c['name'], (float)$c['amount']];
        } else {
            $deductions[] = [$c['name'], (float)$c['amount']];
        }
    }
} catch (PDOException $e) {
    // salary_components table not available yet — fall through to template
}

if (empty($earnings) && empty($deductions)) {
    $earnings = [
        ['Basic Pay', round($baseSalary * 0.60, 2)],
        ['Housing Allowance', round($baseSalary * 0.15, 2)],
        ['Transport Allowance', round($baseSalary * 0.10, 2)],
        ['Medical Allowance', round($baseSalary * 0.05, 2)],
        ['Responsibility Allowance', round($baseSalary * 0.10, 2)],
    ];
    $deductions = [
        ['Income Tax (PAYE)', round($baseSalary * 0.05, 2)],
        ['Pension Contribution (SSNIT)', round($baseSalary * 0.055, 2)],
        ['Social Security Levy', round($baseSalary * 0.01, 2)],
    ];
}

$totalEarnings = array_sum(array_column($earnings, 1));
$totalDeductions = array_sum(array_column($deductions, 1));
$netPay = $totalEarnings - $totalDeductions;

// Month names
$monthNames = ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$monthName = $monthNames[$month] ?? 'Unknown';

// Generate payslip reference number
$payslipRef = 'PS-' . str_pad($staff_id, 4, '0', STR_PAD_LEFT) . '-' . $year . str_pad($month, 2, '0', STR_PAD_LEFT);

$payStatus = $payment ? ($payment['status'] ?? 'pending') : 'pending';

$money = function ($amount) use ($currency) {
    return htmlspecialchars($currency) . number_format((float)$amount, 2);
};

$title = "Payslip - " . $staff['name'];

// Print format: thermal (80mm) vs A4 (default). Mirrors finance/receipts.php.
$format = isset($_GET['format']) && $_GET['format'] === 'thermal' ? 'thermal' : 'a4';
if ($format === 'thermal') {
    include 'payslip_thermal.php';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1a1a1a;
            background: #f0f0f0;
        }

        .statement-card {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 12mm 15mm;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
        }

        .no-print-controls {
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }

        .no-print-controls button,
        .no-print-controls a {
            padding: 8px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-print { background: #10b981; color: white; }
        .btn-print:hover { background: #059669; }
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3) !important;
        }
        .btn-back:hover { background: rgba(255,255,255,0.3); }

        /* School Header */
        .school-header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 3px double #1e3a5f;
            margin-bottom: 10px;
        }
        .school-logo { width: 60px; height: 60px; margin: 0 auto 6px; }
        .school-logo img { width: 100%; height: 100%; object-fit: contain; }
        .school-logo-placeholder {
            width: 60px; height: 60px; margin: 0 auto 6px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 24px; font-weight: 800;
        }
        .school-name {
            font-size: 22px; font-weight: 800; color: #1e3a5f;
            letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px;
        }
        .school-details { font-size: 10px; color: #555; line-height: 1.5; }
        .school-motto { font-style: italic; color: #2d5a8e; font-size: 11px; margin-top: 3px; font-weight: 500; }

        .statement-title {
            text-align: center;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 6px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 10px 0;
            border-radius: 4px;
        }

        /* Info Grid */
        .student-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #d0d0d0;
            margin-bottom: 15px;
            border-radius: 4px;
            overflow: hidden;
        }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label {
            font-weight: 600; color: #333; padding: 5px 10px;
            background: #f5f7fa; min-width: 120px; border-right: 1px solid #e5e5e5;
        }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }

        /* Section Titles + Tables */
        .section-title {
            font-size: 12px; font-weight: 700; color: #1e3a5f;
            padding: 5px 10px; background: #eef2f7;
            border-left: 4px solid #1e3a5f; margin-bottom: 8px;
            border-radius: 0 4px 4px 0; text-transform: uppercase; letter-spacing: 0.5px;
        }

        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10.5px; }
        .ledger-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white; padding: 6px 10px; text-align: left; font-weight: 600;
            font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #1a3455;
        }
        .ledger-table thead th:last-child { text-align: right; }
        .ledger-table tbody td { padding: 6px 10px; text-align: left; border: 1px solid #ddd; }
        .ledger-table tbody td:last-child { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }
        .ledger-table .icon-cell { width: 30px; text-align: center !important; }
        .ledger-table .icon-cell i {
            width: 22px; height: 22px; line-height: 22px; border-radius: 5px;
            font-size: 10px; text-align: center; display: inline-block;
        }
        .earn-icon { background: #ecfdf5; color: #059669; }
        .deduct-icon { background: #fef2f2; color: #dc2626; }
        .subtotal-row td {
            font-weight: 800; border-top: 2px solid #cbd5e1 !important; background: #f5f7fa;
        }
        .subtotal-row.earn td:last-child { color: #059669; }
        .subtotal-row.deduct td:last-child { color: #dc2626; }

        /* Totals Block */
        .totals-block { display: flex; justify-content: flex-end; margin-bottom: 25px; }
        .totals-table { width: 300px; border-collapse: collapse; font-size: 10.5px; }
        .totals-table td { padding: 5px 8px; border-bottom: 1px solid #e5e5e5; }
        .totals-table tr:last-child td { border-bottom: none; }
        .totals-label { color: #666; font-weight: 500; }
        .totals-value { text-align: right; font-weight: 700; color: #1a1a1a; }
        .grand-total-row { background: #eef2f7; font-weight: 800; }
        .grand-total-row td { border-top: 1px solid #1e3a5f; border-bottom: 1px solid #1e3a5f; color: #1e3a5f !important; }
        .netpay-row { background: #e6fcf5; }
        .netpay-row td {
            color: #099268 !important; font-size: 13px; font-weight: 800;
            border-bottom: 2px double #099268 !important;
        }

        /* Signatures */
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 8px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }
        .sig-img { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 44px; max-width: 160px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .statement-note {
            margin-top: 22px; padding-top: 10px; border-top: 1px dashed #d0d0d0;
            font-size: 9px; color: #888; text-align: center; line-height: 1.5;
        }

        /* Watermark */
        .status-watermark {
            position: absolute; top: 35%; left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px; font-weight: 900; text-transform: uppercase;
            letter-spacing: 5px; opacity: 0.06; pointer-events: none;
            width: 100%; text-align: center;
        }

        /* Print Styles */
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print-controls { display: none !important; }
            .statement-card {
                width: 100%; margin: 0; padding: 10mm 12mm;
                box-shadow: none; min-height: auto;
            }
            @page { size: A4; margin: 5mm; }
        }
    </style>
</head>
<body>
    <!-- Print Controls (hidden in print) -->
    <div class="no-print-controls">
        <a href="payroll.php?month=<?php echo $month; ?>&year=<?php echo $year; ?>" class="btn-back">&larr; Back to Payroll</a>
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Payslip</button>
    </div>

    <div class="statement-card">
        <!-- Status Watermark -->
        <div class="status-watermark" style="color: <?php echo $payStatus === 'paid' ? '#10b981' : '#d97706'; ?>;">
            <?php echo $payStatus === 'paid' ? 'Paid' : 'Pending'; ?>
        </div>

        <!-- School Header -->
        <div class="school-header">
            <?php if ($school_logo): ?>
            <div class="school-logo">
                <img src="<?php echo htmlspecialchars($school_logo); ?>" alt="School Logo">
            </div>
            <?php else: ?>
            <div class="school-logo-placeholder">
                <?php echo strtoupper(substr($school_name, 0, 1)); ?>
            </div>
            <?php endif; ?>
            <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
            <?php if ($school_motto): ?>
            <div class="school-motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
            <?php endif; ?>
            <div class="school-details">
                <?php if ($school_postal): ?><?php echo htmlspecialchars($school_postal); ?> | <?php endif; ?>
                <?php echo htmlspecialchars($school_address); ?>
                <?php if ($school_phone): ?> | Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                <?php if ($school_email): ?> | <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
            </div>
        </div>

        <!-- Title -->
        <div class="statement-title">Salary Payslip &mdash; <?php echo $monthName; ?> <?php echo $year; ?></div>

        <!-- Employee Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Employee Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($staff['name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Employee ID</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($staff['employee_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Department</span>
                    <span class="info-value"><?php echo htmlspecialchars(ucfirst($staff['department'] ?? 'N/A')); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Designation</span>
                    <span class="info-value"><?php echo htmlspecialchars(formatRoleName($staff['role'] ?? 'N/A')); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Payslip Ref</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo $payslipRef; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Pay Period</span>
                    <span class="info-value"><?php echo $monthName; ?> <?php echo $year; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Pay Date</span>
                    <span class="info-value"><?php echo $payment ? date('M d, Y', strtotime($payment['payment_date'])) : 'Not Processed'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Status</span>
                    <span class="info-value" style="font-weight: 700; color: <?php echo $payStatus === 'paid' ? '#099268' : '#d97706'; ?>; text-transform: capitalize;">
                        <?php echo htmlspecialchars($payStatus); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Earnings -->
        <div class="section-title">Earnings</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 34px"></th>
                    <th>Description</th>
                    <th style="width: 30%">Amount (<?php echo htmlspecialchars($currency_code); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($earnings as $row): ?>
                <tr>
                    <td class="icon-cell"><i class="fas fa-plus earn-icon"></i></td>
                    <td><?php echo htmlspecialchars($row[0]); ?></td>
                    <td><?php echo $money($row[1]); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row earn">
                    <td></td>
                    <td>Total Earnings</td>
                    <td><?php echo $money($totalEarnings); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Deductions -->
        <div class="section-title">Deductions</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 34px"></th>
                    <th>Description</th>
                    <th style="width: 30%">Amount (<?php echo htmlspecialchars($currency_code); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($deductions as $row): ?>
                <tr>
                    <td class="icon-cell"><i class="fas fa-minus deduct-icon"></i></td>
                    <td><?php echo htmlspecialchars($row[0]); ?></td>
                    <td><?php echo $money($row[1]); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="subtotal-row deduct">
                    <td></td>
                    <td>Total Deductions</td>
                    <td><?php echo $money($totalDeductions); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Net Pay Summary -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Total Earnings:</td>
                    <td class="totals-value" style="color: #099268;"><?php echo $money($totalEarnings); ?></td>
                </tr>
                <tr>
                    <td class="totals-label" style="color: #c53030;">Total Deductions:</td>
                    <td class="totals-value" style="color: #c53030;">&minus;<?php echo $money($totalDeductions); ?></td>
                </tr>
                <tr class="netpay-row">
                    <td>Net Pay:</td>
                    <td class="totals-value"><?php echo $money($netPay); ?></td>
                </tr>
            </table>
        </div>

        <!-- Bank & Payment Details -->
        <div class="section-title">Bank &amp; Payment Details</div>
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Bank Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($staff['bank_name'] ?: 'Not Specified'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account No.</span>
                    <span class="info-value"><?php echo htmlspecialchars($staff['bank_account'] ? '****' . substr($staff['bank_account'], -4) : 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Branch</span>
                    <span class="info-value"><?php echo htmlspecialchars($staff['bank_branch'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?php echo $payment ? htmlspecialchars(ucwords(str_replace('_', ' ', $payment['payment_method']))) : 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Reference No.</span>
                    <span class="info-value"><?php echo $payment && $payment['reference_number'] ? htmlspecialchars($payment['reference_number']) : 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Join Date</span>
                    <span class="info-value"><?php echo $staff['joining_date'] ? date('M d, Y', strtotime($staff['joining_date'])) : 'N/A'; ?></span>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($accountant_sig['url']); ?></div>
                <div class="signature-line"><?php echo $accountant_sig['name'] ? htmlspecialchars($accountant_sig['name']) : 'Prepared By'; ?></div>
                <div class="signature-sub">Accountant / Finance Department</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($hr_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($hr_sig['name'] ?: ($principal_name ?: 'Authorized Signature / Stamp')); ?></div>
                <div class="signature-sub">Headmaster/Headmistress / HR Director</div>
            </div>
        </div>

        <!-- Note -->
        <div class="statement-note">
            This is a computer-generated payslip and does not require a physical signature.
            For any queries, please contact the Finance/HR department.
            <?php if ($school_website): ?> | <?php echo htmlspecialchars($school_website); ?><?php endif; ?>
            <br>Generated on <?php echo date('M d, Y, h:i A'); ?> by <?php echo htmlspecialchars($_SESSION['name'] ?? 'System'); ?>
        </div>
    </div>
</body>
</html>
