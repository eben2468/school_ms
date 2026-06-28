<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Settings helper expects a global $pdo
$pdo = $db;
require_once '../../includes/settings_helper.php';
require_once '../../includes/signature_helper.php';

// --- 1. Block Occupancy Report ---
$blocks_query = "
    SELECT hb.id, hb.name, hb.block_type, hb.status,
           COUNT(DISTINCT hr.id) as total_rooms,
           COALESCE(SUM(hr.capacity), 0) as total_capacity,
           COUNT(DISTINCT ha.id) as total_residents
    FROM hostel_blocks hb
    LEFT JOIN hostel_rooms hr ON hb.id = hr.block_id AND hr.status != 'maintenance'
    LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
    GROUP BY hb.id
    ORDER BY hb.name
";
$blocks_report = $db->query($blocks_query)->fetchAll(PDO::FETCH_ASSOC);

$total_capacity = 0;
$total_residents = 0;
foreach ($blocks_report as $block) {
    $total_capacity += $block['total_capacity'];
    $total_residents += $block['total_residents'];
}
$overall_occupancy_rate = $total_capacity > 0 ? ($total_residents / $total_capacity) * 100 : 0;

// --- 2. Maintenance Status Summary ---
$maint_summary = $db->query("SELECT status, priority, COUNT(*) as count FROM hostel_maintenance GROUP BY status, priority")->fetchAll(PDO::FETCH_ASSOC);

$maint_stats = ['pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'cancelled' => 0, 'total' => 0];
$maint_priority = ['high' => 0, 'medium' => 0, 'low' => 0];
foreach ($maint_summary as $item) {
    if (isset($maint_stats[$item['status']])) $maint_stats[$item['status']] += $item['count'];
    $maint_stats['total'] += $item['count'];
    if (isset($maint_priority[$item['priority']])) $maint_priority[$item['priority']] += $item['count'];
}

// --- 3. Boarding Fees Summary ---
$cat_row = $db->query("SELECT id FROM finance_fee_categories WHERE name LIKE '%Boarding%' OR name LIKE '%Hostel%' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$boarding_cat_id = $cat_row ? $cat_row['id'] : null;

$fees_report = ['invoiced' => 0.00, 'collected' => 0.00, 'outstanding' => 0.00];
if ($boarding_cat_id) {
    $fees_query = "
        SELECT
            SUM(fii.amount) as total_invoiced,
            SUM(CASE WHEN fi.status = 'paid' THEN fii.amount
                     WHEN fi.status = 'partially_paid' THEN fii.amount * (fi.amount_paid / NULLIF(fi.total_amount + fi.penalty_amount - fi.discount_amount, 0))
                     ELSE 0 END) as total_paid
        FROM finance_invoice_items fii
        JOIN finance_invoices fi ON fii.invoice_id = fi.id
        WHERE fii.category_id = :boarding_cat_id AND fi.status != 'cancelled'
    ";
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':boarding_cat_id', $boarding_cat_id);
    $fees_stmt->execute();
    $fees_row = $fees_stmt->fetch(PDO::FETCH_ASSOC);
    if ($fees_row) {
        $fees_report['invoiced'] = floatval($fees_row['total_invoiced']);
        $fees_report['collected'] = floatval($fees_row['total_paid']);
        $fees_report['outstanding'] = $fees_report['invoiced'] - $fees_report['collected'];
    }
}
$coll_rate = $fees_report['invoiced'] > 0 ? ($fees_report['collected'] / $fees_report['invoiced']) * 100 : 0;
$active_repairs = $maint_stats['pending'] + $maint_stats['in_progress'];

// Currency formatter (fall back to a simple cedi format if finance helper absent)
$ff = __DIR__ . '/../../finance/includes/finance_functions.php';
if (file_exists($ff)) require_once $ff;
function hostel_money($amount, $db) {
    if (function_exists('formatFinanceCurrency')) return formatFinanceCurrency($amount, $db);
    return '₵' . number_format((float)$amount, 2);
}

// School settings
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
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
$logo_url = getSchoolLogo();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostel Management Report - <?php echo htmlspecialchars($school_name); ?></title>
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
            min-width: 130px;
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

        /* Section + Tables */
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

        .ledger-table thead th:first-child {
            text-align: left;
        }

        .ledger-table tbody td {
            padding: 5px 8px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .ledger-table tbody td:first-child {
            text-align: left;
            font-weight: 600;
        }

        .ledger-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .status-pill {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .pill-active {
            background: #e6fcf5;
            color: #099268;
        }

        .pill-inactive {
            background: #fff5f5;
            color: #c53030;
        }

        /* Summary blocks side by side */
        .summary-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .totals-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        .totals-table td {
            padding: 5px 8px;
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

        /* Watermark */
        .status-watermark {
            position: absolute;
            top: 35%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 72px;
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
    <div class="no-print-controls">
        <a href="index.php" class="btn-back">&larr; Back to Reports</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="statement-card">
        <!-- Status Watermark -->
        <div class="status-watermark" style="color: <?php echo $overall_occupancy_rate >= 90 ? '#ef4444' : ($overall_occupancy_rate >= 50 ? '#10b981' : '#6b7280'); ?>;">
            <?php echo number_format($overall_occupancy_rate, 0); ?>% Occupied
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
        <div class="statement-title">Hostel Management Report</div>

        <!-- Summary Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Date Generated</span>
                    <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Overall Occupancy</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo number_format($overall_occupancy_rate, 1); ?>% (<?php echo $total_residents; ?> / <?php echo $total_capacity; ?> beds)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Blocks</span>
                    <span class="info-value"><?php echo count($blocks_report); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Active Repairs</span>
                    <span class="info-value" style="font-weight: 700; color: <?php echo $active_repairs > 0 ? '#c53030' : '#099268'; ?>;"><?php echo $active_repairs; ?> open ticket(s)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">High Priority Repairs</span>
                    <span class="info-value"><?php echo $maint_priority['high']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Boarding Collection Rate</span>
                    <span class="info-value" style="font-weight: 700; color: #099268;"><?php echo number_format($coll_rate, 1); ?>%</span>
                </div>
            </div>
        </div>

        <!-- Block Occupancy Table -->
        <div class="section-title">Hostel Block Occupancy</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 26%">Block Name</th>
                    <th style="width: 14%">Type</th>
                    <th style="width: 12%">Rooms</th>
                    <th style="width: 14%">Bed Capacity</th>
                    <th style="width: 12%">Residents</th>
                    <th style="width: 12%">Occupancy</th>
                    <th style="width: 10%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($blocks_report as $block): ?>
                <?php $b_rate = $block['total_capacity'] > 0 ? ($block['total_residents'] / $block['total_capacity']) * 100 : 0; ?>
                <tr>
                    <td><?php echo htmlspecialchars($block['name']); ?></td>
                    <td><?php echo ucfirst($block['block_type']); ?></td>
                    <td><?php echo htmlspecialchars($block['total_rooms']); ?></td>
                    <td><?php echo htmlspecialchars($block['total_capacity']); ?></td>
                    <td><?php echo htmlspecialchars($block['total_residents']); ?></td>
                    <td style="font-weight: 700;"><?php echo number_format($b_rate, 1); ?>%</td>
                    <td>
                        <span class="status-pill <?php echo $block['status'] === 'active' ? 'pill-active' : 'pill-inactive'; ?>">
                            <?php echo ucfirst($block['status']); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($blocks_report)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 15px; color: #666;">No hostel blocks found.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Maintenance & Financial Summaries -->
        <div class="summary-grid">
            <div>
                <div class="section-title">Maintenance Request Stats</div>
                <table class="totals-table">
                    <tr>
                        <td class="totals-label">Pending Approvals</td>
                        <td class="totals-value" style="color: #c53030;"><?php echo $maint_stats['pending']; ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">In Progress Works</td>
                        <td class="totals-value" style="color: #2d5a8e;"><?php echo $maint_stats['in_progress']; ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">Resolved Requests</td>
                        <td class="totals-value" style="color: #099268;"><?php echo $maint_stats['resolved']; ?></td>
                    </tr>
                    <tr class="grand-total-row">
                        <td>Total Logged Tickets</td>
                        <td class="totals-value"><?php echo $maint_stats['total']; ?></td>
                    </tr>
                </table>
            </div>
            <div>
                <div class="section-title">Boarding Fees Summary</div>
                <table class="totals-table">
                    <tr>
                        <td class="totals-label">Total Billed Invoices</td>
                        <td class="totals-value"><?php echo hostel_money($fees_report['invoiced'], $db); ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">Total Collected Payments</td>
                        <td class="totals-value" style="color: #099268;"><?php echo hostel_money($fees_report['collected'], $db); ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">Total Outstanding</td>
                        <td class="totals-value" style="color: #c53030;"><?php echo hostel_money($fees_report['outstanding'], $db); ?></td>
                    </tr>
                    <tr class="grand-total-row">
                        <td>Collection Rate</td>
                        <td class="totals-value"><?php echo number_format($coll_rate, 1); ?>%</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Signatures -->
        <?php echo signatureRow(['Hostel Warden', 'Headmaster / Headmistress']); ?>
    </div>
</body>
</html>
