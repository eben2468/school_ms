<?php
// This is a print template included by receipts.php; it requires $payment/$db.
// Block direct access so it never exposes a fatal error.
if (!isset($payment) || !isset($db)) {
    header('Location: receipts.php');
    exit();
}

// Get school settings
require_once __DIR__ . '/../includes/signature_helper.php';
$accountant_sig = getSchoolSignature('accountant');
$headmaster_sig = getSchoolSignature('headmaster');
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
    <title>Receipt - <?php echo htmlspecialchars($payment['receipt_number']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
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
        
        .receipt-card {
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
        
        .receipt-title {
            text-align: center;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 6px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        /* Meta Info Grid */
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
            border-left: 4px solid #10b981;
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
            border-bottom: 1px solid #1e3a5f;
            color: #1a1a1a;
        }
        
        .amount-paid-row {
            background: #e6fcf5;
            font-weight: 800;
        }
        
        .amount-paid-row td {
            color: #099268 !important;
            border-bottom: 2px double #099268 !important;
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
        
        .receipt-notes {
            margin-top: 20px;
            padding: 8px 12px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
        }
        
        .receipt-notes-title {
            font-weight: 700;
            font-size: 9.5px;
            color: #1e3a5f;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .receipt-notes-text {
            color: #555;
            font-size: 9.5px;
            line-height: 1.4;
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
            
            .receipt-card {
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
        <button class="btn-print" onclick="window.print()">🖨️ Print Receipt</button>
    </div>

    <div class="receipt-card">
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
        
        <!-- Receipt Title -->
        <div class="receipt-title">Official Payment Receipt</div>
        
        <!-- Meta Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Receipt Number</span>
                    <span class="info-value" style="font-weight: 700; color: #059669;"><?php echo htmlspecialchars($payment['receipt_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['student_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['student_reg_no']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['class_name'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Payment Date</span>
                    <span class="info-value"><?php echo date('M d, Y H:i', strtotime($payment['payment_date'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value" style="text-transform: uppercase; font-weight: 600;"><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Academic Period</span>
                    <span class="info-value"><?php echo htmlspecialchars($payment['term_name'] . ' • ' . $payment['year_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Invoice Reference</span>
                    <span class="info-value" style="font-weight: 600; color: #1e3a5f;"><?php echo htmlspecialchars($payment['invoice_number']); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Ledger Entries Table -->
        <div class="section-title">Transaction Details</div>
        <table class="items-table">
            <thead>
                <tr>
                    <th style="width: 10%">#</th>
                    <th style="width: 50%">Transaction Description</th>
                    <th style="width: 20%">Reference / Method</th>
                    <th style="width: 20%; text-align: right;">Amount Paid</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>1</td>
                    <td>
                        <div style="font-weight: 600;">School Fee Payment Credit</div>
                        <div style="font-size: 9.5px; color: #666; margin-top: 2px;">
                            <?php echo htmlspecialchars($payment['notes'] ?: 'Termly fee installment payment credit.'); ?>
                        </div>
                    </td>
                    <td class="capitalize">
                        <?php echo htmlspecialchars($payment['payment_method']); ?>
                        <?php if ($payment['reference_number']): ?>
                        <div style="font-size: 9px; color: #777; margin-top: 1px;">Ref: <?php echo htmlspecialchars($payment['reference_number']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right; font-weight: 700; color: #099268;">
                        <?php echo formatFinanceCurrency($payment['amount'], $db); ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <!-- Totals & Balances -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Total Invoice Charge:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($payment['total_amount'], $db); ?></td>
                </tr>
                <?php if ($payment['penalty_amount'] > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #c53030;">Penalties (+):</td>
                    <td class="totals-value" style="color: #c53030;"><?php echo formatFinanceCurrency($payment['penalty_amount'], $db); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payment['discount_amount'] > 0): ?>
                <tr>
                    <td class="totals-label" style="color: #2f855a;">Discounts (-):</td>
                    <td class="totals-value" style="color: #2f855a;"><?php echo formatFinanceCurrency($payment['discount_amount'], $db); ?></td>
                </tr>
                <?php endif; ?>
                
                <?php 
                $grand_total = $payment['total_amount'] + $payment['penalty_amount'] - $payment['discount_amount'];
                $remaining = $grand_total - $payment['total_paid_to_date'];
                ?>
                <tr class="grand-total-row">
                    <td>Grand Total:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($grand_total, $db); ?></td>
                </tr>
                <tr class="amount-paid-row">
                    <td>Amount Paid Now:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($payment['amount'], $db); ?></td>
                </tr>
                <tr>
                    <td class="totals-label" style="color: #099268;">Total Paid To Date:</td>
                    <td class="totals-value" style="color: #099268;"><?php echo formatFinanceCurrency($payment['total_paid_to_date'], $db); ?></td>
                </tr>
                <tr class="remaining-balance-row">
                    <td>Remaining Balance:</td>
                    <td class="totals-value"><?php echo formatFinanceCurrency($remaining > 0 ? $remaining : 0, $db); ?></td>
                </tr>
            </table>
        </div>
        
        <!-- Signature Section -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($accountant_sig['url']); ?></div>
                <div class="signature-line">Recorded By: <?php echo htmlspecialchars($accountant_sig['name'] ?: ($payment['recorded_by_name'] ?: 'School Accountant')); ?></div>
                <div class="signature-sub">Accountant Signature / Date</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($headmaster_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($headmaster_sig['name'] ?: 'Authorized Sign / stamp'); ?></div>
                <div class="signature-sub">School Stamp & Authorization</div>
            </div>
        </div>
    </div>

    <script>
    function closeReceipt() {
        // Try window.close() first (works if tab was opened via window.open)
        window.close();
        // If the browser blocked window.close(), the code continues executing.
        // Use a small timeout to let close() take effect if it's going to work.
        setTimeout(function() {
            // If we're still here, window.close() was blocked.
            // Try going back in history, or navigate to the receipts list.
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'receipts.php';
            }
        }, 300);
    }
    </script>
</body>
</html>
