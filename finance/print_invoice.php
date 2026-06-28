<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'student', 'parent'])) {
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
$accountant_sig = getSchoolSignature('accountant');

$invoice_id = filter_input(INPUT_GET, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
if (!$invoice_id) {
    echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">No invoice ID provided.</p>';
    exit();
}

// Fetch invoice details
$stmt = $db->prepare("SELECT i.*, 
                             u.name as student_name, sp.student_id as student_reg_no, c.name as class_name,
                             ay.year_name, t.term_name
                      FROM finance_invoices i
                      JOIN users u ON i.student_id = u.id
                      JOIN student_profiles sp ON u.id = sp.user_id
                      LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                      LEFT JOIN classes c ON sc.class_id = c.id
                      JOIN academic_years ay ON i.academic_year_id = ay.id
                      JOIN academic_terms t ON i.term_id = t.id
                      WHERE i.id = :invoice_id LIMIT 1");
$stmt->execute([':invoice_id' => $invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">Invoice not found.</p>';
    exit();
}

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Security Check
if ($user_role === 'student' && $invoice['student_id'] != $user_id) {
    echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">Access denied.</p>';
    exit();
} elseif ($user_role === 'parent') {
    $check = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE user_id = :student_id AND parent_id = :parent_id");
    $check->execute([':student_id' => $invoice['student_id'], ':parent_id' => $user_id]);
    if ($check->fetchColumn() == 0) {
        echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">Access denied.</p>';
        exit();
    }
}

// Fetch invoice items
$stmt = $db->prepare("SELECT ii.*, fc.name as category_name
                      FROM finance_invoice_items ii
                      JOIN finance_fee_categories fc ON ii.category_id = fc.id
                      WHERE ii.invoice_id = :invoice_id
                      ORDER BY ii.id ASC");
$stmt->execute([':invoice_id' => $invoice_id]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Invoice - <?php echo htmlspecialchars($invoice['invoice_number']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
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
        
        .invoice-card {
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
        
        .invoice-title {
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
        
        /* Items Table */
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
        
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10.5px;
        }
        
        .items-table thead th {
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
        
        .items-table thead th:first-child,
        .items-table thead th:nth-child(2) {
            text-align: left;
        }
        
        .items-table tbody td {
            padding: 5px 8px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .items-table tbody td:first-child,
        .items-table tbody td:nth-child(2) {
            text-align: left;
        }
        
        .items-table tbody tr:nth-child(even) {
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
            border-bottom: 2px double #1e3a5f !important;
            color: #1e3a5f !important;
            font-size: 11.5px;
        }
        
        .remaining-balance-row {
            background: #fff5f5;
        }
        
        .remaining-balance-row td {
            color: #c53030 !important;
            font-size: 12px;
            font-weight: 800;
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

        .signature-sub {
            font-size: 8px;
            color: #777;
            margin-top: 1px;
        }
        .sig-img { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 44px; max-width: 160px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        .invoice-notes {
            margin-top: 20px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        
        .invoice-notes-title {
            font-weight: 700;
            font-size: 9.5px;
            color: #1e3a5f;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .invoice-notes-text {
            color: #555;
            font-size: 9.5px;
            line-height: 1.4;
        }
        
        /* Status Watermark */
        .status-watermark {
            position: absolute;
            top: 35%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 80px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 5px;
            opacity: 0.08;
            pointer-events: none;
            width: 100%;
            text-align: center;
        }
        
        .watermark-paid { color: #10b981; }
        .watermark-pending { color: #f59e0b; }
        .watermark-partially_paid { color: #3b82f6; }
        .watermark-overdue { color: #ef4444; }
        .watermark-cancelled { color: #6b7280; }
        
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
            
            .invoice-card {
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
        <button class="btn-print" onclick="window.print()">🖨️ Print Invoice</button>
    </div>

    <div class="invoice-card">
        <!-- Status Watermark -->
        <div class="status-watermark watermark-<?php echo $invoice['status']; ?>">
            <?php echo str_replace('_', ' ', $invoice['status']); ?>
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
        
        <!-- Invoice Title -->
        <div class="invoice-title">Official Student Invoice</div>
        
        <!-- Student Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Invoice Number</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['student_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['student_reg_no'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['class_name'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Date Issued</span>
                    <span class="info-value"><?php echo date('M d, Y', strtotime($invoice['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Due Date</span>
                    <span class="info-value" style="font-weight: 600; color: #c53030;"><?php echo date('M d, Y', strtotime($invoice['due_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Academic Term</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['term_name'] . ' • ' . $invoice['year_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Invoice Status</span>
                    <span class="info-value" style="font-weight: 700; text-transform: uppercase;">
                        <?php echo str_replace('_', ' ', $invoice['status']); ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Invoice Items -->
        <div class="section-title">Invoice Breakdown</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 8%">#</th>
                    <th style="width: 32%">Fee Category</th>
                    <th style="width: 45%">Description</th>
                    <th style="width: 15%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $cnt = 1;
                $subtotal = 0;
                foreach ($items as $item): 
                    $subtotal += $item['amount'];
                ?>
                <tr>
                    <td><?php echo $cnt++; ?></td>
                    <td><?php echo htmlspecialchars($item['category_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                    <td style="text-align: right; font-weight: 600;"><?php echo formatFinanceCurrency($item['amount'], $db); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr>
                    <td colspan="4" style="text-align: center; padding: 15px; color: #666;">No items found on this invoice.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Totals & Summary -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Subtotal:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($subtotal, $db); ?></td>
                </tr>
                <?php if ($invoice['penalty_amount'] > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #c53030;">Penalties (+):</td>
                    <td class="totals-value" style="color: #c53030;"><?php echo formatFinanceCurrency($invoice['penalty_amount'], $db); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($invoice['discount_amount'] > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #2f855a;">Discounts (-):</td>
                    <td class="totals-value" style="color: #2f855a;"><?php echo formatFinanceCurrency($invoice['discount_amount'], $db); ?></td>
                </tr>
                <?php endif; ?>
                
                <?php 
                $grand_total = $invoice['total_amount'] + $invoice['penalty_amount'] - $invoice['discount_amount'];
                $remaining_balance = $grand_total - $invoice['amount_paid'];
                ?>
                <tr class="grand-total-row">
                    <td>Grand Total:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($grand_total, $db); ?></td>
                </tr>
                <tr>
                    <td class="totals-label" style="color: #2f855a;">Amount Paid:</td>
                    <td class="totals-value" style="color: #2f855a; font-weight: 750;"><?php echo formatFinanceCurrency($invoice['amount_paid'], $db); ?></td>
                </tr>
                <tr class="remaining-balance-row">
                    <td>Balance Due:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($remaining_balance > 0 ? $remaining_balance : 0, $db); ?></td>
                </tr>
            </table>
        </div>
        
        <?php if ($invoice['notes']): ?>
        <div class="invoice-notes">
            <div class="invoice-notes-title">Notes / Instructions</div>
            <div class="invoice-notes-text"><?php echo nl2br(htmlspecialchars($invoice['notes'])); ?></div>
        </div>
        <?php endif; ?>
        
        <!-- Signature Section -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($accountant_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($accountant_sig['name'] ?: 'Authorized Signature / Stamp'); ?></div>
                <div class="signature-sub">Finance Officer / Accountant</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"></div>
                <div class="signature-line">Student / Guardian Acknowledgment</div>
                <div class="signature-sub">Date Signed</div>
            </div>
        </div>
    </div>

    <script>
    function closeReceipt() {
        window.close();
        setTimeout(function() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'invoices.php';
            }
        }, 300);
    }
    </script>
</body>
</html>
