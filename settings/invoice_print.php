<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$invoice_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$invoice_id) {
    header("Location: super_admin.php");
    exit();
}

// Fetch invoice + tenant school details
$stmt = $db->prepare("
    SELECT bi.*, s.name AS school_name, s.code AS school_code,
           s.contact_name, s.contact_email, s.contact_phone, s.address AS school_address_billed
    FROM billing_invoices bi
    JOIN schools s ON bi.school_id = s.id
    WHERE bi.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $invoice_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header("Location: super_admin.php");
    exit();
}

// Platform / issuer settings (header)
require_once '../includes/settings_helper.php';
$school_name = getSchoolSetting('school_name', 'School Management System');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$school_website = getSchoolSetting('school_website', '');
$currency = getSchoolSetting('currency_symbol', '₵');
$currency_code = getSchoolSetting('currency', 'GHS');
$school_logo = getSchoolLogo();

$school_motto = '';
$school_postal = '';
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    if ($r = $motto_stmt->fetch(PDO::FETCH_ASSOC)) $school_motto = $r['setting_value'];
    $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
    $postal_stmt->execute();
    if ($r = $postal_stmt->fetch(PDO::FETCH_ASSOC)) $school_postal = $r['setting_value'];
} catch (PDOException $e) {
    // settings not available
}

// Derived values
$amount = (float)$invoice['amount'];
$subtotal = $amount;
$vat = 0.00;
$total = $subtotal + $vat;
$status = strtolower($invoice['status'] ?? 'pending');
$issueDate = $invoice['created_at'] ? date('M d, Y', strtotime($invoice['created_at'])) : '—';
$dueDate = $invoice['due_date'] ? date('M d, Y', strtotime($invoice['due_date'])) : '—';
$paidDate = $invoice['paid_at'] ? date('M d, Y', strtotime($invoice['paid_at'])) : null;

$money = function ($amt) use ($currency) {
    return htmlspecialchars($currency) . number_format((float)$amt, 2);
};

$title = "Invoice " . $invoice['invoice_number'];

// Print format: thermal (80mm) vs A4 (default), mirroring staff/payslip.php
$format = isset($_GET['format']) && $_GET['format'] === 'thermal' ? 'thermal' : 'a4';
if ($format === 'thermal') {
    include 'invoice_print_thermal.php';
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
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 11px; line-height: 1.4; color: #1a1a1a; background: #f0f0f0; }

        .statement-card { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 12mm 15mm; background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; }

        .no-print-controls { text-align: center; padding: 15px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; position: sticky; top: 0; z-index: 100; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .no-print-controls button, .no-print-controls a { padding: 8px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 13px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-print { background: #10b981; color: white; }
        .btn-print:hover { background: #059669; }
        .btn-thermal { background: #334155; color: white; }
        .btn-thermal:hover { background: #1e293b; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3) !important; }
        .btn-back:hover { background: rgba(255,255,255,0.3); }

        .school-header { text-align: center; padding-bottom: 10px; border-bottom: 3px double #1e3a5f; margin-bottom: 10px; }
        .school-logo { width: 60px; height: 60px; margin: 0 auto 6px; }
        .school-logo img { width: 100%; height: 100%; object-fit: contain; }
        .school-logo-placeholder { width: 60px; height: 60px; margin: 0 auto 6px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: 800; }
        .school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
        .school-details { font-size: 10px; color: #555; line-height: 1.5; }
        .school-motto { font-style: italic; color: #2d5a8e; font-size: 11px; margin-top: 3px; font-weight: 500; }

        .statement-title { text-align: center; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 20px; font-size: 14px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin: 10px 0; border-radius: 4px; }

        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d0d0d0; margin-bottom: 15px; border-radius: 4px; overflow: hidden; }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #333; padding: 5px 10px; background: #f5f7fa; min-width: 120px; border-right: 1px solid #e5e5e5; }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }

        .section-title { font-size: 12px; font-weight: 700; color: #1e3a5f; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #1e3a5f; margin-bottom: 8px; border-radius: 0 4px 4px 0; text-transform: uppercase; letter-spacing: 0.5px; }

        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10.5px; }
        .ledger-table thead th { background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 10px; text-align: left; font-weight: 600; font-size: 9.5px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #1a3455; }
        .ledger-table thead th:last-child { text-align: right; }
        .ledger-table tbody td { padding: 6px 10px; text-align: left; border: 1px solid #ddd; }
        .ledger-table tbody td.num { text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
        .ledger-table tbody td.center { text-align: center; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }

        .totals-block { display: flex; justify-content: flex-end; margin-bottom: 25px; }
        .totals-table { width: 300px; border-collapse: collapse; font-size: 10.5px; }
        .totals-table td { padding: 5px 8px; border-bottom: 1px solid #e5e5e5; }
        .totals-table tr:last-child td { border-bottom: none; }
        .totals-label { color: #666; font-weight: 500; }
        .totals-value { text-align: right; font-weight: 700; color: #1a1a1a; }
        .grand-total-row { background: #e6fcf5; }
        .grand-total-row td { color: #099268 !important; font-size: 13px; font-weight: 800; border-bottom: 2px double #099268 !important; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 45px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }

        .statement-note { margin-top: 22px; padding-top: 10px; border-top: 1px dashed #d0d0d0; font-size: 9px; color: #888; text-align: center; line-height: 1.5; }

        .status-watermark { position: absolute; top: 35%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 80px; font-weight: 900; text-transform: uppercase; letter-spacing: 5px; opacity: 0.06; pointer-events: none; width: 100%; text-align: center; }

        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print-controls { display: none !important; }
            .statement-card { width: 100%; margin: 0; padding: 10mm 12mm; box-shadow: none; min-height: auto; }
            @page { size: A4; margin: 5mm; }
        }
    </style>
</head>
<body>
    <div class="no-print-controls">
        <a href="super_admin.php?tab=billing" class="btn-back">&larr; Back to Billing</a>
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Invoice</button>
        <a href="invoice_print.php?id=<?php echo (int)$invoice_id; ?>&format=thermal" class="btn-thermal"><i class="fas fa-receipt"></i> Thermal Version</a>
    </div>

    <div class="statement-card">
        <div class="status-watermark" style="color: <?php echo $status === 'paid' ? '#10b981' : '#d97706'; ?>;">
            <?php echo $status === 'paid' ? 'Paid' : 'Due'; ?>
        </div>

        <!-- Header -->
        <div class="school-header">
            <?php if ($school_logo): ?>
            <div class="school-logo"><img src="<?php echo htmlspecialchars($school_logo); ?>" alt="Logo"></div>
            <?php else: ?>
            <div class="school-logo-placeholder"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
            <?php endif; ?>
            <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
            <?php if ($school_motto): ?><div class="school-motto">"<?php echo htmlspecialchars($school_motto); ?>"</div><?php endif; ?>
            <div class="school-details">
                <?php if ($school_postal): ?><?php echo htmlspecialchars($school_postal); ?> | <?php endif; ?>
                <?php echo htmlspecialchars($school_address); ?>
                <?php if ($school_phone): ?> | Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                <?php if ($school_email): ?> | <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
            </div>
        </div>

        <!-- Title -->
        <div class="statement-title">Subscription Invoice</div>

        <!-- Invoice meta -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Billed To</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($invoice['school_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">School Code</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['school_code']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Contact</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['contact_name'] ?: 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Email</span>
                    <span class="info-value"><?php echo htmlspecialchars($invoice['contact_email'] ?: 'N/A'); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Invoice No.</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($invoice['invoice_number']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date Issued</span>
                    <span class="info-value"><?php echo htmlspecialchars($issueDate); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Due Date</span>
                    <span class="info-value" style="color: <?php echo $status === 'paid' ? '#099268' : '#c53030'; ?>; font-weight: 600;"><?php echo htmlspecialchars($dueDate); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value" style="font-weight: 700; text-transform: capitalize; color: <?php echo $status === 'paid' ? '#099268' : '#d97706'; ?>;"><?php echo htmlspecialchars($status); ?></span>
                </div>
            </div>
        </div>

        <!-- Line items -->
        <div class="section-title">Invoice Details</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="width: 18%; text-align:center;">Unit Price</th>
                    <th style="width: 10%; text-align:center;">Qty</th>
                    <th style="width: 20%">Amount (<?php echo htmlspecialchars($currency_code); ?>)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>SaaS Multi-School Access Subscription</strong><br>
                        <span style="color:#777; font-size:9.5px;">Isolated database-per-tenant cloud instance hosting &amp; platform maintenance</span>
                    </td>
                    <td class="center"><?php echo $money($amount); ?></td>
                    <td class="center">1</td>
                    <td class="num"><?php echo $money($amount); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Subtotal:</td>
                    <td class="totals-value"><?php echo $money($subtotal); ?></td>
                </tr>
                <tr>
                    <td class="totals-label">VAT (0.00%):</td>
                    <td class="totals-value"><?php echo $money($vat); ?></td>
                </tr>
                <tr class="grand-total-row">
                    <td>Total Due:</td>
                    <td class="totals-value"><?php echo $money($total); ?></td>
                </tr>
            </table>
        </div>

        <!-- Payment details -->
        <div class="section-title">Payment Details</div>
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?php echo $invoice['payment_method'] ? htmlspecialchars(ucwords(str_replace('_', ' ', $invoice['payment_method']))) : 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Transaction Ref</span>
                    <span class="info-value"><?php echo $invoice['transaction_ref'] ? htmlspecialchars($invoice['transaction_ref']) : 'N/A'; ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Date Paid</span>
                    <span class="info-value"><?php echo $paidDate ? htmlspecialchars($paidDate) : 'Not Paid'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Amount Settled</span>
                    <span class="info-value" style="font-weight: 700; color: <?php echo $status === 'paid' ? '#099268' : '#d97706'; ?>;"><?php echo $status === 'paid' ? $money($total) : $money(0); ?></span>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-box">
                <div class="signature-line">Prepared By</div>
                <div class="signature-sub">Central Billing Department</div>
            </div>
            <div class="signature-box">
                <div class="signature-line">Authorized Signature / Stamp</div>
                <div class="signature-sub">Finance Director</div>
            </div>
        </div>

        <div class="statement-note">
            This is a computer-generated invoice and does not require a physical signature.
            For billing queries, please contact the central finance department.
            <?php if ($school_website): ?> | <?php echo htmlspecialchars($school_website); ?><?php endif; ?>
            <br>Generated on <?php echo date('M d, Y, h:i A'); ?> by <?php echo htmlspecialchars($_SESSION['name'] ?? 'System'); ?>
        </div>
    </div>
</body>
</html>
