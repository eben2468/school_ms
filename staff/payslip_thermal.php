<?php
// 80mm thermal payslip template, included by payslip.php; it relies on the
// variables computed there ($staff, $payment, $earnings, $deductions, etc.).
// Block direct access so it never exposes a fatal error.
if (!isset($staff) || !isset($db)) {
    header('Location: payroll.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?php echo htmlspecialchars($payslipRef); ?></title>
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
        .header { text-align: center; margin-bottom: 8px; }
        .title { font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .motto { font-style: italic; font-size: 10px; margin-top: 2px; }
        .details { font-size: 9px; color: #333; margin-top: 2px; line-height: 1.2; }
        .receipt-type { font-size: 12px; font-weight: bold; margin-top: 6px; letter-spacing: 1px; }
        .separator { border-top: 1px dashed #000; margin: 6px 0; }
        .meta-table, .item-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .meta-table td { padding: 2px 0; vertical-align: top; }
        .item-table th, .item-table td { padding: 3px 0; text-align: left; vertical-align: top; }
        .text-right { text-align: right; }
        .bold { font-weight: bold; }
        .section { font-size: 11px; font-weight: bold; margin: 4px 0 2px; text-transform: uppercase; }
        .footer { text-align: center; margin-top: 12px; font-size: 9.5px; line-height: 1.3; }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
        }
        .no-print-btn {
            background: #111; color: #fff; border: none; padding: 8px 10px;
            cursor: pointer; width: 100%; margin-bottom: 12px;
            font-family: Arial, sans-serif; font-size: 12px; font-weight: bold;
            border-radius: 4px; transition: background 0.2s;
        }
        .no-print-btn:hover { background: #333; }
    </style>
</head>
<body>
    <button class="no-print no-print-btn" onclick="window.print()">🖨️ PRINT THERMAL PAYSLIP</button>

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
        <div class="receipt-type">SALARY PAYSLIP</div>
    </div>

    <div class="separator"></div>

    <table class="meta-table">
        <tr>
            <td class="bold" style="width: 38%">Payslip Ref:</td>
            <td class="text-right"><?php echo htmlspecialchars($payslipRef); ?></td>
        </tr>
        <tr>
            <td class="bold">Pay Period:</td>
            <td class="text-right"><?php echo htmlspecialchars($monthName . ' ' . $year); ?></td>
        </tr>
        <tr>
            <td class="bold">Employee:</td>
            <td class="text-right"><?php echo htmlspecialchars($staff['name']); ?></td>
        </tr>
        <tr>
            <td class="bold">Employee ID:</td>
            <td class="text-right"><?php echo htmlspecialchars($staff['employee_id'] ?? 'N/A'); ?></td>
        </tr>
        <tr>
            <td class="bold">Department:</td>
            <td class="text-right"><?php echo htmlspecialchars(ucfirst($staff['department'] ?? 'N/A')); ?></td>
        </tr>
        <tr>
            <td class="bold">Designation:</td>
            <td class="text-right"><?php echo htmlspecialchars(formatRoleName($staff['role'] ?? 'N/A')); ?></td>
        </tr>
        <tr>
            <td class="bold">Pay Date:</td>
            <td class="text-right"><?php echo $payment ? date('Y-m-d', strtotime($payment['payment_date'])) : 'Not Processed'; ?></td>
        </tr>
        <tr>
            <td class="bold">Status:</td>
            <td class="text-right" style="text-transform: uppercase;"><?php echo htmlspecialchars($payStatus); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="section">Earnings</div>
    <table class="item-table">
        <?php foreach ($earnings as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row[0]); ?></td>
            <td class="text-right"><?php echo $money($row[1]); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="bold">
            <td>Total Earnings</td>
            <td class="text-right"><?php echo $money($totalEarnings); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="section">Deductions</div>
    <table class="item-table">
        <?php foreach ($deductions as $row): ?>
        <tr>
            <td><?php echo htmlspecialchars($row[0]); ?></td>
            <td class="text-right">-<?php echo $money($row[1]); ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="bold">
            <td>Total Deductions</td>
            <td class="text-right">-<?php echo $money($totalDeductions); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <table class="meta-table">
        <tr>
            <td>Total Earnings:</td>
            <td class="text-right"><?php echo $money($totalEarnings); ?></td>
        </tr>
        <tr>
            <td>Total Deductions:</td>
            <td class="text-right">-<?php echo $money($totalDeductions); ?></td>
        </tr>
        <tr class="bold" style="background: #eee;">
            <td>NET PAY:</td>
            <td class="text-right"><?php echo $money($netPay); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="footer">
        <p>Payment Method:
            <?php echo $payment ? strtoupper(str_replace('_', ' ', $payment['payment_method'])) : 'N/A'; ?></p>
        <?php if ($payment && $payment['reference_number']): ?>
        <p>Ref Number: <?php echo htmlspecialchars($payment['reference_number']); ?></p>
        <?php endif; ?>
        <?php if (!empty($staff['bank_name'])): ?>
        <p>Bank: <?php echo htmlspecialchars($staff['bank_name']); ?>
            <?php echo $staff['bank_account'] ? '(****' . htmlspecialchars(substr($staff['bank_account'], -4)) . ')' : ''; ?></p>
        <?php endif; ?>
        <div style="margin-top: 8px; font-weight: bold;">This is a computer-generated payslip.</div>
        <p style="font-size: 8px; color: #555; margin-top: 4px;">*** Generated on <?php echo date('Y-m-d H:i:s'); ?> ***</p>
    </div>
</body>
</html>
