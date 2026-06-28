<?php
// Set global $pdo for settings helper
global $db;
if (!isset($db) || !isset($student)) {
    $student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    if ($student_id) {
        header("Location: student_balances.php?student_id=" . $student_id);
    } else {
        header("Location: student_balances.php");
    }
    exit();
}
if (isset($db)) {
    $pdo = $db;
}
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
$accountant_sig = getSchoolSignature('accountant');
$headmaster_sig = getSchoolSignature('headmaster');

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
    <title>Account Statement - <?php echo htmlspecialchars($student['student_name']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
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
        
        /* Student Info Grid */
        .student-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #d0d0d0;
            margin-bottom: 15px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .info-row {
            display: flex;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10.5px;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
            padding: 5px 10px;
            background: #f5f7fa;
            min-width: 120px;
            border-right: 1px solid #e5e5e5;
        }
        
        .info-value {
            padding: 5px 10px;
            flex: 1;
            color: #1a1a1a;
            font-weight: 500;
        }
        
        .info-col-divider {
            border-right: 1px solid #d0d0d0;
        }
        
        /* Ledger Table */
        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e3a5f;
            padding: 5px 10px;
            background: #eef2f7;
            border-left: 4px solid #1e3a5f;
            margin-bottom: 8px;
            border-radius: 0 4px 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }
        
        .ledger-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 6px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #1a3455;
        }
        
        .ledger-table thead th:first-child,
        .ledger-table thead th:nth-child(2) {
            text-align: left;
        }
        
        .ledger-table tbody td {
            padding: 5px 8px;
            text-align: right;
            border: 1px solid #ddd;
        }
        
        .ledger-table tbody td:first-child,
        .ledger-table tbody td:nth-child(2) {
            text-align: left;
        }
        
        .ledger-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        /* Totals Block */
        .totals-block {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 25px;
        }
        
        .totals-table {
            width: 280px;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        
        .totals-table td {
            padding: 4px 8px;
            border-bottom: 1px solid #e5e5e5;
        }
        
        .totals-table tr:last-child td {
            border-bottom: none;
        }
        
        .totals-label {
            color: #666;
            font-weight: 500;
        }
        
        .totals-value {
            text-align: right;
            font-weight: 700;
            color: #1a1a1a;
        }
        
        .grand-total-row {
            background: #eef2f7;
            font-weight: 800;
        }
        
        .grand-total-row td {
            border-top: 1px solid #1e3a5f;
            border-bottom: 1px solid #1e3a5f;
            color: #1e3a5f !important;
        }
        
        .amount-paid-row {
            background: #e6fcf5;
            font-weight: 800;
        }
        
        .amount-paid-row td {
            color: #099268 !important;
        }
        
        .remaining-balance-row {
            background: <?php echo $net_balance >= 0 ? '#fff5f5' : '#e6fcf5'; ?>;
        }
        
        .remaining-balance-row td {
            color: <?php echo $net_balance >= 0 ? '#c53030' : '#099268'; ?> !important;
            font-size: 12px;
            font-weight: 800;
            border-bottom: 2px double <?php echo $net_balance >= 0 ? '#c53030' : '#099268'; ?> !important;
        }
        
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
            margin-top: 8px;
            padding-top: 4px;
            font-size: 9.5px;
            font-weight: 600;
            color: #333;
        }
        .sig-img { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 44px; max-width: 160px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        .signature-sub {
            font-size: 8px;
            color: #777;
            margin-top: 1px;
        }
        
        /* Watermark */
        .status-watermark {
            position: absolute;
            top: 35%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 5px;
            opacity: 0.06;
            pointer-events: none;
            width: 100%;
            text-align: center;
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
            
            .statement-card {
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
    <?php
    // Send the viewer back to the page that fits their role so parents/students
    // are not dropped onto the finance (admin) ledger page.
    $stmt_role = $_SESSION['role'] ?? '';
    $stmt_sid = isset($view_student_id) ? (int)$view_student_id : (int)filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    if ($stmt_role === 'parent') {
        $back_url = '/parent/fees.php?student_id=' . $stmt_sid;
        $back_label = 'Back to Fees';
    } elseif ($stmt_role === 'student') {
        $back_url = '/dashboard.php';
        $back_label = 'Back to Dashboard';
    } else {
        $back_url = 'student_balances.php';
        $back_label = 'Back to Ledger';
    }
    ?>
    <div class="no-print-controls">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn-back">&larr; <?php echo htmlspecialchars($back_label); ?></a>
        <button class="btn-print" onclick="window.print()">🖨️ Print Statement</button>
    </div>

    <div class="statement-card">
        <?php 
        $net_balance = ($total_charged + $total_penalty - $total_discount) - $total_paid;
        ?>
        <!-- Status Watermark -->
        <div class="status-watermark" style="color: <?php echo $net_balance > 0 ? '#ef4444' : ($net_balance < 0 ? '#10b981' : '#6b7280'); ?>;">
            <?php 
            if ($net_balance > 0) {
                echo 'Balance Due';
            } elseif ($net_balance < 0) {
                echo 'Credit Balance';
            } else {
                echo 'Account Settled';
            }
            ?>
        </div>

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
        
        <!-- Title -->
        <div class="statement-title">Statement of Account</div>
        
        <!-- Student Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Student Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['student_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($student['student_reg_no']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Current Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($student['class_name'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Date Generated</span>
                    <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Enrollment Scope</span>
                    <span class="info-value capitalize"><?php echo htmlspecialchars($student['student_type'] ?: 'day'); ?> Student</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Account Status</span>
                    <span class="info-value" style="font-weight: 700; color: <?php echo $net_balance > 0 ? '#c53030' : '#099268'; ?>;">
                        <?php 
                        if ($net_balance > 0) {
                            echo 'Outstanding Balance';
                        } elseif ($net_balance < 0) {
                            echo 'Credit Balance';
                        } else {
                            echo 'Paid in Full';
                        }
                        ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Timeline Ledger Table -->
        <div class="section-title">Account Transaction History</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 15%">Date / Time</th>
                    <th style="width: 45%">Transaction Details</th>
                    <th style="width: 13%; text-align: right;">Debit (+)</th>
                    <th style="width: 13%; text-align: right;">Credit (-)</th>
                    <th style="width: 14%; text-align: right;">Running Bal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $running_balance = 0.00;
                foreach ($ledger as $entry): 
                    $running_balance += ((float)$entry['debit'] - (float)$entry['credit']);
                ?>
                <tr>
                    <td><?php echo date('Y-m-d H:i', strtotime($entry['date'])); ?></td>
                    <td style="font-weight: 500; text-align: left;"><?php echo htmlspecialchars($entry['desc']); ?></td>
                    <td style="color: #c53030; font-weight: 500;"><?php echo $entry['debit'] > 0 ? formatFinanceCurrency($entry['debit'], $db) : '-'; ?></td>
                    <td style="color: #099268; font-weight: 500;"><?php echo $entry['credit'] > 0 ? formatFinanceCurrency($entry['credit'], $db) : '-'; ?></td>
                    <td style="font-weight: 700; color: #1a1a1a;"><?php echo formatFinanceCurrency($running_balance, $db); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($ledger)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; padding: 15px; color: #666;">No transaction history found for this account.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totals & Balances -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Total Fee Invoiced:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($total_charged, $db); ?></td>
                </tr>
                <?php if ($total_penalty > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #c53030;">Total Penalties Applied:</td>
                    <td class="totals-value" style="color: #c53030;"><?php echo formatFinanceCurrency($total_penalty, $db); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($total_discount > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #2f855a;">Total Discounts Applied:</td>
                    <td class="totals-value" style="color: #2f855a;"><?php echo formatFinanceCurrency($total_discount, $db); ?></td>
                </tr>
                <?php endif; ?>
                
                <?php 
                $net_liability = $total_charged + $total_penalty - $total_discount;
                ?>
                <tr class="grand-total-row">
                    <td>Net Structural Liability:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($net_liability, $db); ?></td>
                </tr>
                <tr class="amount-paid-row">
                    <td>Total Payments Received:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($total_paid, $db); ?></td>
                </tr>
                <tr class="remaining-balance-row">
                    <td><?php echo $net_balance >= 0 ? 'Remaining Balance Due:' : 'Credit / Prepaid Balance:'; ?></td>
                    <td class="totals-value"><?php echo formatFinanceCurrency(abs($net_balance), $db); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Signatures & Contact -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($accountant_sig['url']); ?></div>
                <div class="signature-line"><?php echo $accountant_sig['name'] ? htmlspecialchars($accountant_sig['name']) : 'Prepared By'; ?></div>
                <div class="signature-sub">Accountant / Finance Department</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($headmaster_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($headmaster_sig['name'] ?: 'Authorized Signature / Stamp'); ?></div>
                <div class="signature-sub">Date Signed</div>
            </div>
        </div>
    </div>
</body>
</html>
