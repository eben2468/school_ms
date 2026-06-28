<?php
// 80mm thermal invoice template, included by invoice_print.php; it relies on the
// variables computed there ($invoice, $money, totals, etc.).
// Block direct access so it never exposes a fatal error.
if (!isset($invoice) || !isset($db)) {
    header('Location: super_admin.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice - <?php echo htmlspecialchars($invoice['invoice_number']); ?></title>
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
        .link-btn {
            display: block; text-align: center; text-decoration: none;
            background: #e2e8f0; color: #111; padding: 7px 10px; width: 100%;
            margin-bottom: 12px; font-family: Arial, sans-serif; font-size: 11px;
            font-weight: bold; border-radius: 4px; box-sizing: border-box;
        }
    </style>
</head>
<body>
    <button class="no-print no-print-btn" onclick="window.print()">🖨️ PRINT THERMAL INVOICE</button>
    <a class="no-print link-btn" href="invoice_print.php?id=<?php echo (int)$invoice['id']; ?>">↩ Switch to A4 Invoice</a>

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
        <div class="receipt-type">SUBSCRIPTION INVOICE</div>
    </div>

    <div class="separator"></div>

    <table class="meta-table">
        <tr>
            <td class="bold" style="width: 40%">Invoice No:</td>
            <td class="text-right"><?php echo htmlspecialchars($invoice['invoice_number']); ?></td>
        </tr>
        <tr>
            <td class="bold">Billed To:</td>
            <td class="text-right"><?php echo htmlspecialchars($invoice['school_name']); ?></td>
        </tr>
        <tr>
            <td class="bold">School Code:</td>
            <td class="text-right"><?php echo htmlspecialchars($invoice['school_code']); ?></td>
        </tr>
        <tr>
            <td class="bold">Date Issued:</td>
            <td class="text-right"><?php echo htmlspecialchars($issueDate); ?></td>
        </tr>
        <tr>
            <td class="bold">Due Date:</td>
            <td class="text-right"><?php echo htmlspecialchars($dueDate); ?></td>
        </tr>
        <tr>
            <td class="bold">Status:</td>
            <td class="text-right" style="text-transform: uppercase;"><?php echo htmlspecialchars($status); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="section">Invoice Details</div>
    <table class="item-table">
        <tr>
            <td>SaaS Multi-School Access Subscription</td>
            <td class="text-right"><?php echo $money($amount); ?></td>
        </tr>
        <tr>
            <td style="font-size:9px; color:#555;">Isolated cloud instance hosting &amp; maintenance (Qty 1)</td>
            <td></td>
        </tr>
    </table>

    <div class="separator"></div>

    <table class="meta-table">
        <tr>
            <td>Subtotal:</td>
            <td class="text-right"><?php echo $money($subtotal); ?></td>
        </tr>
        <tr>
            <td>VAT (0.00%):</td>
            <td class="text-right"><?php echo $money($vat); ?></td>
        </tr>
        <tr class="bold" style="background: #eee;">
            <td>TOTAL DUE:</td>
            <td class="text-right"><?php echo $money($total); ?></td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="footer">
        <p>Payment Method:
            <?php echo $invoice['payment_method'] ? strtoupper(str_replace('_', ' ', $invoice['payment_method'])) : 'N/A'; ?></p>
        <?php if (!empty($invoice['transaction_ref'])): ?>
        <p>Ref Number: <?php echo htmlspecialchars($invoice['transaction_ref']); ?></p>
        <?php endif; ?>
        <?php if ($paidDate): ?>
        <p>Date Paid: <?php echo htmlspecialchars($paidDate); ?></p>
        <?php endif; ?>
        <div style="margin-top: 8px; font-weight: bold;">This is a computer-generated invoice.</div>
        <p style="font-size: 8px; color: #555; margin-top: 4px;">*** Generated on <?php echo date('Y-m-d H:i:s'); ?> ***</p>
    </div>
</body>
</html>
