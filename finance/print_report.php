<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();
$pdo = $db; // global $pdo for settings helper

require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
require_once 'includes/finance_functions.php';
require_once 'includes/report_functions.php';

$report_type = filter_input(INPUT_GET, 'report_type', FILTER_SANITIZE_STRING) ?: 'pnl';
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-01');
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-t');

// Get school settings
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$school_motto = '';
$school_postal = '';

// Try to get motto and postal from academic_settings
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

$logo_url = getSchoolLogo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $report_type === 'pnl' ? 'P&L Statement' : 'Owing Students Report'; ?> - <?php echo htmlspecialchars($school_name); ?></title>
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
        
        .report-card {
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
        
        .no-print-controls button {
            padding: 8px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .btn-print {
            background: #10b981;
            color: white;
        }
        
        .btn-print:hover {
            background: #059669;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3) !important;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* School Header */
        .school-header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 3px double #1e3a5f;
            margin-bottom: 10px;
        }
        
        .school-logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 6px;
        }
        
        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .school-logo-placeholder {
            width: 60px;
            height: 60px;
            margin: 0 auto 6px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 800;
        }
        
        .school-name {
            font-size: 22px;
            font-weight: 800;
            color: #1e3a5f;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        
        .school-details {
            font-size: 10px;
            color: #555;
            line-height: 1.5;
        }
        
        .school-motto {
            font-style: italic;
            color: #2d5a8e;
            font-size: 11px;
            margin-top: 3px;
            font-weight: 500;
        }
        
        .report-title {
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
        
        .report-meta {
            margin-bottom: 15px;
            font-size: 11px;
            color: #4a5568;
            font-weight: 600;
            text-align: center;
            background: #f7fafc;
            border: 1px solid #e2e8f0;
            padding: 6px;
            border-radius: 4px;
        }
        
        /* Table Styles */
        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e3a5f;
            padding: 5px 10px;
            background: #eef2f7;
            border-left: 4px solid #1e3a5f;
            margin-top: 15px;
            margin-bottom: 8px;
            border-radius: 0 4px 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10.5px;
        }
        
        .data-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 6px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #1a3455;
        }
        
        .data-table thead th:first-child {
            text-align: left;
        }
        
        .data-table tbody td {
            padding: 5px 8px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .data-table tbody td:first-child {
            text-align: left;
            font-weight: 500;
        }
        
        .data-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .data-table tfoot tr {
            font-weight: 700;
            background: #eef2f7;
        }
        
        .data-table tfoot td {
            padding: 6px 8px;
            border: 1px solid #cbd5e0;
            text-align: center;
        }
        
        .data-table tfoot td:first-child {
            text-align: left;
        }
        
        .summary-box {
            margin-top: 20px;
            padding: 12px 18px;
            background: #f8fafc;
            border: 1px solid #cbd5e0;
            border-radius: 6px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .summary-label {
            font-size: 13px;
            font-weight: 700;
            color: #1e3a5f;
        }
        
        .summary-value {
            font-size: 18px;
            font-weight: 900;
        }
        
        .text-emerald { color: #059669; }
        .text-rose { color: #dc2626; }
        
        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 40px;
            padding-top: 10px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 45px;
            padding-top: 4px;
            font-size: 9.5px;
            font-weight: 600;
            color: #333;
        }
        
        .signature-sub {
            font-size: 8px;
            color: #777;
            margin-top: 1px;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .no-print-controls {
                display: none !important;
            }
            
            .report-card {
                width: 100%;
                margin: 0;
                padding: 10mm 12mm;
                box-shadow: none;
                min-height: auto;
            }
            
            @page {
                size: A4;
                margin: 5mm;
            }
        }
    </style>
</head>
<body>
    <!-- Print Controls (hidden in print) -->
    <div class="no-print-controls">
        <button class="btn-back" onclick="closeReceipt()">✕ Close</button>
        <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="report-card">
        <!-- School Header -->
        <div class="school-header">
            <?php if ($logo_url): ?>
            <div class="school-logo">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo">
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

        <?php if ($report_type === 'pnl'): 
            $expense_data = getExpenseReport($start_date, $end_date, $db);
            $pnl = getIncomeVsExpenseReport($start_date, $end_date, $db);
        ?>
            <!-- Report Title -->
            <div class="report-title">Profit & Loss (P&L) Statement</div>
            
            <!-- Meta Date Info -->
            <div class="report-meta">
                Statement Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
            </div>

            <!-- Revenue Section -->
            <div class="section-title">Revenue (Income) Breakdown</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70%">Category</th>
                        <th style="width: 30%; text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Fee Payment Collections</td>
                        <td style="text-align: right; font-weight: 600; color: #059669;"><?php echo formatFinanceCurrency($pnl['fee_income'], $db); ?></td>
                    </tr>
                    <tr>
                        <td>Other Non-Fee Revenue</td>
                        <td style="text-align: right; font-weight: 600; color: #059669;"><?php echo formatFinanceCurrency($pnl['other_income'], $db); ?></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Revenue (A)</td>
                        <td style="text-align: right; color: #059669;"><?php echo formatFinanceCurrency($pnl['total_income'], $db); ?></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Expense Section -->
            <div class="section-title">Operating Expenditures</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 70%">Expense Category</th>
                        <th style="width: 30%; text-align: right;">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_exp_calc = 0;
                    foreach ($expense_data as $exp): 
                        $total_exp_calc += $exp['value'];
                    ?>
                    <tr>
                        <td class="capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $exp['label'])); ?></td>
                        <td style="text-align: right; font-weight: 600; color: #dc2626;"><?php echo formatFinanceCurrency($exp['value'], $db); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($expense_data)): ?>
                    <tr>
                        <td colspan="2" style="text-align: center; padding: 10px; color: #777;">No expenditures recorded in this period.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total Expenditures (B)</td>
                        <td style="text-align: right; color: #dc2626;"><?php echo formatFinanceCurrency($pnl['total_expense'], $db); ?></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Surplus/Deficit Summary Box -->
            <div class="summary-box">
                <span class="summary-label">Net Operating Surplus/Deficit (A - B):</span>
                <span class="summary-value <?php echo $pnl['net_profit'] >= 0 ? 'text-emerald' : 'text-rose'; ?>">
                    <?php echo formatFinanceCurrency($pnl['net_profit'], $db); ?>
                </span>
            </div>

        <?php elseif ($report_type === 'owing'): 
            $students_owing = getStudentReport('owing', $db);
        ?>
            <!-- Report Title -->
            <div class="report-title">Owing Students Directory</div>
            
            <!-- Meta Date Info -->
            <div class="report-meta">
                As of Date: <?php echo date('F d, Y H:i'); ?>
            </div>

            <!-- List of owing students -->
            <div class="section-title">Outstanding Balances List</div>
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 5%">#</th>
                        <th style="width: 30%">Student Name</th>
                        <th style="width: 15%">Reg ID</th>
                        <th style="width: 15%">Class</th>
                        <th style="width: 11%; text-align: right;">Charged</th>
                        <th style="width: 11%; text-align: right;">Paid</th>
                        <th style="width: 13%; text-align: right;">Balance Due</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $cnt = 1;
                    $total_charged = 0;
                    $total_paid = 0;
                    $total_owing = 0;
                    foreach ($students_owing as $s): 
                        $charged = $s['total_amount'] + $s['penalty_amount'] - $s['discount_amount'];
                        $paid = $s['amount_paid'];
                        $owing = $s['outstanding_balance'];
                        
                        $total_charged += $charged;
                        $total_paid += $paid;
                        $total_owing += $owing;
                    ?>
                    <tr>
                        <td><?php echo $cnt++; ?></td>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($s['student_name']); ?></td>
                        <td><?php echo htmlspecialchars($s['student_reg_no']); ?></td>
                        <td><?php echo htmlspecialchars($s['class_name'] ?: 'Unassigned'); ?></td>
                        <td style="text-align: right;"><?php echo formatFinanceCurrency($charged, $db); ?></td>
                        <td style="text-align: right; font-weight: 600; color: #059669;"><?php echo formatFinanceCurrency($paid, $db); ?></td>
                        <td style="text-align: right; font-weight: 700; color: #dc2626;"><?php echo formatFinanceCurrency($owing, $db); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($students_owing)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 15px; color: #666;">No students found with outstanding balances.</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 800;">
                        <td colspan="4" style="text-align: right;">Totals:</td>
                        <td style="text-align: right;"><?php echo formatFinanceCurrency($total_charged, $db); ?></td>
                        <td style="text-align: right; color: #059669;"><?php echo formatFinanceCurrency($total_paid, $db); ?></td>
                        <td style="text-align: right; color: #dc2626;"><?php echo formatFinanceCurrency($total_owing, $db); ?></td>
                    </tr>
                </tfoot>
            </table>

            <!-- Summary statistics -->
            <div class="summary-box">
                <span class="summary-label">Total Outstanding Receivables:</span>
                <span class="summary-value text-rose">
                    <?php echo formatFinanceCurrency($total_owing, $db); ?>
                </span>
            </div>
        <?php endif; ?>

        <!-- Signature Section -->
        <?php echo signatureRow(['School Accountant / Finance Officer', 'School Headmaster/Headmistress']); ?>
    </div>

    <script>
    function closeReceipt() {
        window.close();
        setTimeout(function() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'reports.php';
            }
        }, 300);
    }
    </script>
</body>
</html>
