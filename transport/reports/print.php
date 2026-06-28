<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
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

// Optional finance currency formatter
$ff = __DIR__ . '/../../finance/includes/finance_functions.php';
if (file_exists($ff)) require_once $ff;
function transport_money($amount, $db) {
    if (function_exists('formatFinanceCurrency')) return formatFinanceCurrency($amount, $db);
    return '₵' . number_format((float)$amount, 2);
}

// ---- Stats ----
$stats = [
    'total_routes' => 0, 'total_vehicles' => 0, 'active_vehicles' => 0,
    'maintenance_vehicles' => 0, 'total_drivers' => 0, 'active_drivers' => 0,
    'total_students' => 0, 'total_maintenance_cost' => 0.00,
];
$stats['total_routes'] = $db->query("SELECT COUNT(*) FROM transport_routes WHERE status = 'active'")->fetchColumn();

$row = $db->query("SELECT COUNT(*), COUNT(CASE WHEN status = 'active' THEN 1 END), COUNT(CASE WHEN status = 'maintenance' THEN 1 END) FROM transport_vehicles")->fetch(PDO::FETCH_NUM);
if ($row) { $stats['total_vehicles'] = $row[0]; $stats['active_vehicles'] = $row[1]; $stats['maintenance_vehicles'] = $row[2]; }

$row = $db->query("SELECT COUNT(*), COUNT(CASE WHEN status = 'active' THEN 1 END) FROM transport_drivers")->fetch(PDO::FETCH_NUM);
if ($row) { $stats['total_drivers'] = $row[0]; $stats['active_drivers'] = $row[1]; }

$stats['total_students'] = $db->query("SELECT COUNT(*) FROM student_transport WHERE status = 'active'")->fetchColumn();
$stats['total_maintenance_cost'] = floatval($db->query("SELECT SUM(cost) FROM transport_maintenance WHERE status = 'completed'")->fetchColumn() ?: 0.00);

// Route utilization
$routes_report = $db->query("
    SELECT tr.id, tr.route_name, tr.route_code, tr.start_point, tr.end_point, tr.distance_km,
           COUNT(DISTINCT tv.id) as vehicle_count,
           COALESCE(SUM(tv.capacity), 0) as total_capacity,
           COUNT(DISTINCT st.id) as student_count
    FROM transport_routes tr
    LEFT JOIN transport_vehicles tv ON tr.id = tv.route_id
    LEFT JOIN student_transport st ON tr.id = st.route_id AND st.status = 'active'
    GROUP BY tr.id
    ORDER BY tr.route_name
")->fetchAll(PDO::FETCH_ASSOC);

// Vehicles by type
$vehicle_types = $db->query("SELECT vehicle_type, COUNT(*) as count FROM transport_vehicles GROUP BY vehicle_type")->fetchAll(PDO::FETCH_ASSOC);

// Upcoming maintenance
$upcoming_maintenance = $db->query("
    SELECT tm.*, tv.vehicle_number, tv.make_model
    FROM transport_maintenance tm
    JOIN transport_vehicles tv ON tm.vehicle_id = tv.id
    WHERE tm.status IN ('scheduled', 'in_progress')
    ORDER BY tm.maintenance_date ASC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ---- School settings ----
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
    <title>Transport Report - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 11px; line-height: 1.4; color: #1a1a1a; background: #f0f0f0;
        }

        .statement-card {
            width: 210mm; min-height: 297mm; margin: 20px auto; padding: 12mm 15mm;
            background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative;
        }

        .no-print-controls {
            text-align: center; padding: 15px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white;
            position: sticky; top: 0; z-index: 100;
            display: flex; align-items: center; justify-content: center; gap: 12px;
        }
        .no-print-controls button, .no-print-controls a {
            padding: 8px 24px; border: none; border-radius: 6px; font-weight: 600;
            cursor: pointer; font-family: 'Inter', sans-serif; font-size: 13px;
            transition: all 0.2s; text-decoration: none; display: inline-flex;
            align-items: center; justify-content: center;
        }
        .btn-print { background: #10b981; color: white; }
        .btn-print:hover { background: #059669; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3) !important; }
        .btn-back:hover { background: rgba(255,255,255,0.3); }

        .school-header { text-align: center; padding-bottom: 10px; border-bottom: 3px double #1e3a5f; margin-bottom: 10px; }
        .school-logo { width: 60px; height: 60px; margin: 0 auto 6px; }
        .school-logo img { width: 100%; height: 100%; object-fit: contain; }
        .school-logo-placeholder {
            width: 60px; height: 60px; margin: 0 auto 6px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e); border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 24px; font-weight: 800;
        }
        .school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
        .school-details { font-size: 10px; color: #555; line-height: 1.5; }
        .school-motto { font-style: italic; color: #2d5a8e; font-size: 11px; margin-top: 3px; font-weight: 500; }

        .statement-title {
            text-align: center; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white;
            padding: 6px 20px; font-size: 14px; font-weight: 700; letter-spacing: 2px;
            text-transform: uppercase; margin: 10px 0; border-radius: 4px;
        }

        .student-info {
            display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d0d0d0;
            margin-bottom: 15px; border-radius: 4px; overflow: hidden;
        }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #333; padding: 5px 10px; background: #f5f7fa; min-width: 140px; border-right: 1px solid #e5e5e5; }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }

        .section-title {
            font-size: 12px; font-weight: 700; color: #1e3a5f; padding: 5px 10px; background: #eef2f7;
            border-left: 4px solid #1e3a5f; margin-bottom: 8px; border-radius: 0 4px 4px 0;
            text-transform: uppercase; letter-spacing: 0.5px;
        }

        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px; }
        .ledger-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 8px;
            text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase;
            letter-spacing: 0.3px; border: 1px solid #1a3455;
        }
        .ledger-table thead th:first-child, .ledger-table thead th:nth-child(2) { text-align: left; }
        .ledger-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #ddd; }
        .ledger-table tbody td:first-child, .ledger-table tbody td:nth-child(2) { text-align: left; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }

        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .totals-table { width: 100%; border-collapse: collapse; font-size: 10.5px; }
        .totals-table td { padding: 5px 8px; border-bottom: 1px solid #e5e5e5; }
        .totals-table tr:last-child td { border-bottom: none; }
        .totals-label { color: #666; font-weight: 500; }
        .totals-value { text-align: right; font-weight: 700; color: #1a1a1a; }
        .grand-total-row { background: #eef2f7; font-weight: 800; }
        .grand-total-row td { border-top: 1px solid #1e3a5f; border-bottom: 1px solid #1e3a5f; color: #1e3a5f !important; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 45px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }

        .status-watermark {
            position: absolute; top: 35%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 70px; font-weight: 900; text-transform: uppercase; letter-spacing: 5px;
            opacity: 0.06; pointer-events: none; width: 100%; text-align: center; color: #1e3a5f;
        }

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
        <a href="index.php" class="btn-back">&larr; Back to Reports</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="statement-card">
        <div class="status-watermark">Transport</div>

        <!-- School Header -->
        <div class="school-header">
            <?php if ($logo_url): ?>
            <div class="school-logo"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo"></div>
            <?php else: ?>
            <div class="school-logo-placeholder"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
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
        <div class="statement-title">Transport Management Report</div>

        <!-- Summary Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Date Generated</span>
                    <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Active Routes</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo (int)$stats['total_routes']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Fleet Vehicles</span>
                    <span class="info-value"><?php echo (int)$stats['total_vehicles']; ?> total (<?php echo (int)$stats['active_vehicles']; ?> active, <?php echo (int)$stats['maintenance_vehicles']; ?> in maintenance)</span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Drivers</span>
                    <span class="info-value"><?php echo (int)$stats['total_drivers']; ?> total (<?php echo (int)$stats['active_drivers']; ?> active)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Students on Transport</span>
                    <span class="info-value" style="font-weight: 700;"><?php echo (int)$stats['total_students']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Maintenance Spend</span>
                    <span class="info-value" style="font-weight: 700; color: #c53030;"><?php echo transport_money($stats['total_maintenance_cost'], $db); ?></span>
                </div>
            </div>
        </div>

        <!-- Route Utilization -->
        <div class="section-title">Route Utilization</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 22%">Route</th>
                    <th style="width: 26%">Start &rarr; End</th>
                    <th style="width: 12%">Distance</th>
                    <th style="width: 13%">Vehicles</th>
                    <th style="width: 13%">Capacity</th>
                    <th style="width: 14%">Students</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($routes_report as $r): ?>
                <tr>
                    <td>
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($r['route_name']); ?></span><br>
                        <span style="color: #777; font-size: 9px;"><?php echo htmlspecialchars($r['route_code']); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($r['start_point'] . ' → ' . $r['end_point']); ?></td>
                    <td><?php echo $r['distance_km'] !== null ? htmlspecialchars($r['distance_km']) . ' km' : '—'; ?></td>
                    <td><?php echo (int)$r['vehicle_count']; ?></td>
                    <td><?php echo (int)$r['total_capacity']; ?></td>
                    <td style="font-weight: 700;"><?php echo (int)$r['student_count']; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($routes_report)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 15px; color: #666;">No routes found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Fleet & Maintenance Summaries -->
        <div class="summary-grid">
            <div>
                <div class="section-title">Fleet by Type</div>
                <table class="totals-table">
                    <?php if (empty($vehicle_types)): ?>
                    <tr><td class="totals-label">No vehicles recorded</td><td class="totals-value">0</td></tr>
                    <?php else: foreach ($vehicle_types as $vt): ?>
                    <tr>
                        <td class="totals-label"><?php echo htmlspecialchars(ucfirst($vt['vehicle_type'] ?: 'Unspecified')); ?></td>
                        <td class="totals-value"><?php echo (int)$vt['count']; ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    <tr class="grand-total-row">
                        <td>Total Vehicles</td>
                        <td class="totals-value"><?php echo (int)$stats['total_vehicles']; ?></td>
                    </tr>
                </table>
            </div>
            <div>
                <div class="section-title">Completed Maintenance</div>
                <table class="totals-table">
                    <tr>
                        <td class="totals-label">Vehicles in Maintenance</td>
                        <td class="totals-value" style="color: #c05621;"><?php echo (int)$stats['maintenance_vehicles']; ?></td>
                    </tr>
                    <tr>
                        <td class="totals-label">Upcoming / In Progress</td>
                        <td class="totals-value"><?php echo count($upcoming_maintenance); ?></td>
                    </tr>
                    <tr class="grand-total-row">
                        <td>Total Maintenance Cost</td>
                        <td class="totals-value"><?php echo transport_money($stats['total_maintenance_cost'], $db); ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Upcoming Maintenance -->
        <?php if (!empty($upcoming_maintenance)): ?>
        <div class="section-title">Scheduled / In-Progress Maintenance</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 22%">Vehicle</th>
                    <th style="width: 33%">Details</th>
                    <th style="width: 20%">Date</th>
                    <th style="width: 25%">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming_maintenance as $m): ?>
                <tr>
                    <td>
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($m['vehicle_number']); ?></span><br>
                        <span style="color: #777; font-size: 9px;"><?php echo htmlspecialchars($m['make_model'] ?? ''); ?></span>
                    </td>
                    <td><?php echo htmlspecialchars($m['description'] ?? ($m['maintenance_type'] ?? '—')); ?></td>
                    <td><?php echo !empty($m['maintenance_date']) ? date('M d, Y', strtotime($m['maintenance_date'])) : '—'; ?></td>
                    <td style="text-transform: capitalize;"><?php echo htmlspecialchars(str_replace('_', ' ', $m['status'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Signatures -->
        <?php echo signatureRow(['Transport Officer', 'Headmaster / Headmistress']); ?>
    </div>
</body>
</html>
