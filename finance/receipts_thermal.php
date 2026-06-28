<?php
// This is a print template included by receipts.php; it requires $payment/$db.
// Block direct access so it never exposes a fatal error.
if (!isset($payment) || !isset($db)) {
    header('Location: receipts.php');
    exit();
}

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt - <?php echo htmlspecialchars($payment['receipt_number']); ?></title>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            color: #000;
            background: #fff;
            margin: 0;
            padding: 10px;
            width: 80mm;
            box-sizing: border-box;
        }
        .header {
            text-align: center;
            margin-bottom: 8px;
        }
        .title {
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .motto {
            font-style: italic;
            font-size: 10px;
            margin-top: 2px;
        }
        .details {
            font-size: 9px;
            color: #333;
            margin-top: 2px;
            line-height: 1.2;
        }
        .receipt-type {
            font-size: 12px;
            font-weight: bold;
            margin-top: 6px;
            letter-spacing: 1px;
        }
        .separator {
            border-top: 1px dashed #000;
            margin: 6px 0;
        }
        .meta-table, .item-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }
        .meta-table td {
            padding: 2px 0;
            vertical-align: top;
        }
        .item-table th, .item-table td {
            padding: 4px 0;
            text-align: left;
            vertical-align: top;
        }
        .text-right {
            text-align: right;
        }
        .bold {
            font-weight: bold;
        }
        .footer {
            text-align: center;
            margin-top: 12px;
            font-size: 9.5px;
            line-height: 1.3;
        }
        @media print {
            .no-print { display: none !important; }
            body {
                padding: 0;
                margin: 0;
            }
        }
        .no-print-btn {
            background: #111;
            color: #fff;
            border: none;
            padding: 8px 10px;
            cursor: pointer;
            width: 100%;
            margin-bottom: 12px;
            font-family: Arial, sans-serif;
            font-size: 12px;
            font-weight: bold;
            border-radius: 4px;
            transition: background 0.2s;
        }
        .no-print-btn:hover {
            background: #333;
        }
    </style>
</head>
<body>
    <button class="no-print no-print-btn" onclick="window.print()">🖨️ PRINT THERMAL RECEIPT</button>

    <div class="header">
        <div class="title"><?php echo htmlspecialchars($school_name); ?></div>
        <?php if ($school_motto): ?>
        <div class="motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
        <?php endif; ?>
        <div class="details">
            <?php if ($school_postal): ?><?php echo htmlspecialchars($school_postal); ?><br><?php endif; ?>
            <?php echo htmlspecialchars($school_address); ?><br>
            <?php if ($school_phone): ?>Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
            <?php if ($school_email): ?> | <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
        </div>
        <div class="receipt-type">PAYMENT RECEIPT</div>
    </div>
    
    <div class="separator"></div>
    
    <table class="meta-table">
        <tr>
            <td class="bold" style="width: 35%">Receipt No:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
        </tr>
        <tr>
            <td class="bold">Date:</td>
            <td class="text-right"><?php echo date('Y-m-d H:i', strtotime($payment['payment_date'])); ?></td>
        </tr>
        <tr>
            <td class="bold">Student:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['student_name']); ?></td>
        </tr>
        <tr>
            <td class="bold">ID/Reg No:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['student_reg_no']); ?></td>
        </tr>
        <tr>
            <td class="bold">Class:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['class_name'] ?: 'N/A'); ?></td>
        </tr>
        <tr>
            <td class="bold">Period:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['term_name'] . ' • ' . $payment['year_name']); ?></td>
        </tr>
        <tr>
            <td class="bold">Invoice Ref:</td>
            <td class="text-right"><?php echo htmlspecialchars($payment['invoice_number']); ?></td>
        </tr>
    </table>
    
    <div class="separator"></div>
    
    <table class="item-table">
        <thead>
            <tr class="bold">
                <th style="width: 65%">Description</th>
                <th class="text-right" style="width: 35%">Amount</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>
                    School Fee Payment Credit<br>
                    <small style="color: #444; font-size: 8.5px;"><?php echo htmlspecialchars($payment['notes'] ?: 'Term fee installment'); ?></small>
                </td>
                <td class="text-right"><?php echo formatFinanceCurrency($payment['amount'], $db); ?></td>
            </tr>
        </tbody>
    </table>
    
    <div class="separator"></div>
    
    <table class="meta-table">
        <tr>
            <td>Total Invoice:</td>
            <td class="text-right"><?php echo formatFinanceCurrency($payment['total_amount'], $db); ?></td>
        </tr>
        <?php if ($payment['penalty_amount'] > 0): ?>
        <tr>
            <td>Penalties (+):</td>
            <td class="text-right"><?php echo formatFinanceCurrency($payment['penalty_amount'], $db); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($payment['discount_amount'] > 0): ?>
        <tr>
            <td>Discounts (-):</td>
            <td class="text-right"><?php echo formatFinanceCurrency($payment['discount_amount'], $db); ?></td>
        </tr>
        <?php endif; ?>
        
        <?php 
        $grand_total = $payment['total_amount'] + $payment['penalty_amount'] - $payment['discount_amount'];
        $remaining = $grand_total - $payment['total_paid_to_date'];
        ?>
        <tr class="bold">
            <td>Grand Total:</td>
            <td class="text-right"><?php echo formatFinanceCurrency($grand_total, $db); ?></td>
        </tr>
        <tr class="bold" style="background: #eee;">
            <td>Amount Paid:</td>
            <td class="text-right"><?php echo formatFinanceCurrency($payment['amount'], $db); ?></td>
        </tr>
        <tr>
            <td>Paid To Date:</td>
            <td class="text-right"><?php echo formatFinanceCurrency($payment['total_paid_to_date'], $db); ?></td>
        </tr>
        <tr class="bold">
            <td>Balance Due:</td>
            <td class="text-right"><?php echo formatFinanceCurrency($remaining > 0 ? $remaining : 0, $db); ?></td>
        </tr>
    </table>
    
    <div class="separator"></div>
    
    <div class="footer">
        <p>Payment Method: <?php echo strtoupper($payment['payment_method']); ?></p>
        <?php if ($payment['reference_number']): ?>
        <p>Ref Number: <?php echo htmlspecialchars($payment['reference_number']); ?></p>
        <?php endif; ?>
        <p>Recorded By: <?php echo htmlspecialchars($payment['recorded_by_name'] ?: 'Accountant'); ?></p>
        <div style="margin-top: 8px; font-weight: bold;">Thank you for your payment!</div>
        <p style="font-size: 8px; color: #555; margin-top: 4px;">*** Receipt generated on <?php echo date('Y-m-d H:i:s'); ?> ***</p>
    </div>
</body>
</html>
