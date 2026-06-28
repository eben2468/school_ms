<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'])) {
    // If it's a report generation (iframe), we might want to return HTML instead of JSON
    if (isset($_GET['action']) && $_GET['action'] === 'generate_report') {
        header('Content-Type: text/html');
        echo "Unauthorized access.";
    } else {
        echo json_encode(['error' => 'Unauthorized access']);
    }
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? '';
$staff_roles = ['teacher','librarian','accountant','nurse','counselor','transport_officer','hostel_warden','canteen_manager','hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

if ($action === 'get_schedule') {
    $staff_id = filter_input(INPUT_GET, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
    if (!$staff_id) {
        echo json_encode(['error' => 'Staff ID required']);
        exit;
    }
    
    // Fetch only active schedules (effective_from <= today and (effective_to is null or >= today))
    $stmt = $db->prepare("
        SELECT * FROM staff_schedules 
        WHERE staff_id = :staff_id 
        AND CURDATE() >= effective_from 
        AND (effective_to IS NULL OR CURDATE() <= effective_to)
    ");
    $stmt->execute([':staff_id' => $staff_id]);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $schedules]);
    exit();
}

if ($action === 'generate_report') {
    $type = $_GET['type'] ?? '';
    header('Content-Type: text/html');

    $currency = getSchoolSetting('currency_symbol', '₵');

    // ---- School branding ----
    $school_name    = getSchoolSetting('school_name', 'Greenwood Academy');
    $school_address = getSchoolSetting('school_address', '');
    $school_phone   = getSchoolSetting('school_phone', '');
    $school_email   = getSchoolSetting('school_email', '');
    $school_motto   = '';
    $school_postal  = '';
    try {
        $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
        $motto_stmt->execute();
        if ($r = $motto_stmt->fetch(PDO::FETCH_ASSOC)) $school_motto = $r['setting_value'];
        $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
        $postal_stmt->execute();
        if ($r = $postal_stmt->fetch(PDO::FETCH_ASSOC)) $school_postal = $r['setting_value'];
    } catch (PDOException $e) { /* settings not available */ }
    $logo_url = function_exists('getSchoolLogo') ? getSchoolLogo() : '';

    // Report metadata by type
    $report_meta = [
        'attendance'     => ['title' => 'Staff Attendance Report',        'subtitle' => 'Monthly attendance, tardiness & absence summary'],
        'performance'    => ['title' => 'Performance Evaluation Report',   'subtitle' => 'Staff appraisal ratings & top performers'],
        'payroll'        => ['title' => 'Payroll & Compensation Report',   'subtitle' => 'Salary distribution & disbursement audit trail'],
        'leaves'         => ['title' => 'Staff Leave Report',              'subtitle' => 'Leave requests, balances & seasonal trends'],
        'qualifications' => ['title' => 'Qualifications & Certifications Report', 'subtitle' => 'Active credentials & upcoming expiries'],
    ];
    $meta = $report_meta[$type] ?? ['title' => ucfirst($type) . ' Report', 'subtitle' => ''];
    $generated_by = $_SESSION['name'] ?? formatRoleName($_SESSION['role'] ?? '');

    // ---------- Document shell (head + header) ----------
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($meta['title']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
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
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3) !important; }
        .btn-back:hover { background: rgba(255,255,255,0.3); }

        .school-header { text-align: center; padding-bottom: 10px; border-bottom: 3px double #1e3a5f; margin-bottom: 10px; }
        .school-logo { width: 60px; height: 60px; margin: 0 auto 6px; }
        .school-logo img { width: 100%; height: 100%; object-fit: contain; }
        .school-logo-placeholder { width: 60px; height: 60px; margin: 0 auto 6px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: 800; }
        .school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
        .school-details { font-size: 10px; color: #555; line-height: 1.5; }
        .school-motto { font-style: italic; color: #2d5a8e; font-size: 11px; margin-top: 3px; font-weight: 500; }

        .statement-title { text-align: center; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 20px; font-size: 14px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin: 10px 0 4px; border-radius: 4px; }
        .statement-subtitle { text-align: center; font-size: 10px; color: #666; margin-bottom: 12px; }

        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d0d0d0; margin-bottom: 15px; border-radius: 4px; overflow: hidden; }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #333; padding: 5px 10px; background: #f5f7fa; min-width: 120px; border-right: 1px solid #e5e5e5; }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }

        .section-title { font-size: 12px; font-weight: 700; color: #1e3a5f; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #1e3a5f; margin: 16px 0 8px; border-radius: 0 4px 4px 0; text-transform: uppercase; letter-spacing: 0.5px; }

        .stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 6px; }
        .stat-grid.cols-3 { grid-template-columns: repeat(3, 1fr); }
        .stat-tile { border: 1px solid #e5e5e5; border-radius: 6px; padding: 8px 10px; background: #f9fafb; }
        .stat-tile .label { font-size: 8.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4px; color: #64748b; }
        .stat-tile .value { font-size: 16px; font-weight: 800; color: #1e3a5f; margin-top: 2px; }
        .stat-tile.green .value { color: #099268; }
        .stat-tile.red .value { color: #c53030; }
        .stat-tile.amber .value { color: #b45309; }

        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px; }
        .ledger-table thead th { background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #1a3455; }
        .ledger-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #ddd; }
        .ledger-table tbody td.left { text-align: left; }
        .ledger-table tbody td.right { text-align: right; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }
        .ledger-table .empty-row td { text-align: center; padding: 15px; color: #666; }
        .ledger-table tfoot td { padding: 6px 8px; background: #eef2f7; font-weight: 800; color: #1e3a5f; border: 1px solid #d0d0d0; }

        .badge { display: inline-block; padding: 1.5px 8px; border-radius: 999px; font-size: 8.5px; font-weight: 700; border: 1px solid transparent; }
        .badge.green { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .badge.amber { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .badge.red { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .badge.blue { background: #dbeafe; color: #1e40af; border-color: #bfdbfe; }
        .badge.gray { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 45px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }

        .empty-state { text-align: center; padding: 30px; color: #94a3b8; }
        .empty-state i { font-size: 36px; margin-bottom: 8px; opacity: 0.5; }

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
        <a href="reports.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back to Reports</a>
        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print / Save PDF</button>
    </div>

    <div class="statement-card">
        <div class="school-header">
            <?php if ($logo_url): ?>
            <div class="school-logo"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo"></div>
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

        <div class="statement-title"><?php echo htmlspecialchars($meta['title']); ?></div>
        <?php if ($meta['subtitle']): ?><div class="statement-subtitle"><?php echo htmlspecialchars($meta['subtitle']); ?></div><?php endif; ?>

        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row"><span class="info-label">Report Type</span><span class="info-value"><?php echo htmlspecialchars(ucfirst($type)); ?></span></div>
                <div class="info-row"><span class="info-label">Generated By</span><span class="info-value"><?php echo htmlspecialchars($generated_by); ?></span></div>
            </div>
            <div>
                <div class="info-row"><span class="info-label">Date Generated</span><span class="info-value"><?php echo date('M d, Y H:i'); ?></span></div>
                <div class="info-row"><span class="info-label">Reporting Period</span><span class="info-value"><?php
                    $sm = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
                    $sy = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
                    if (in_array($type, ['payroll', 'attendance'])) {
                        echo htmlspecialchars(date('F', mktime(0,0,0,$sm,1)) . ' ' . $sy);
                    } else {
                        echo 'As at ' . date('M d, Y');
                    }
                ?></span></div>
            </div>
        </div>

<?php
    // ============================ ATTENDANCE ============================
    if ($type === 'attendance') {
        $selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $selected_year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        $stmt = $db->prepare("
            SELECT u.name, u.role,
            SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as p,
            SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as a,
            SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as l
            FROM users u
            LEFT JOIN staff_attendance sa ON u.id = sa.staff_id AND MONTH(sa.date) = ? AND YEAR(sa.date) = ?
            WHERE u.role IN ($staff_roles_in) AND u.status = 'active'
            GROUP BY u.id ORDER BY u.name ASC
        ");
        $stmt->execute([$selected_month, $selected_year]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $sumP = $sumA = $sumL = 0;
        foreach ($data as $row) { $sumP += $row['p']; $sumA += $row['a']; $sumL += $row['l']; }
        $totalMarks = $sumP + $sumA + $sumL;
        $overallRate = $totalMarks > 0 ? round(($sumP / $totalMarks) * 100, 1) : 0;
        ?>
        <div class="section-title">Summary &mdash; <?php echo htmlspecialchars(date('F', mktime(0,0,0,$selected_month,1)) . ' ' . $selected_year); ?></div>
        <div class="stat-grid">
            <div class="stat-tile"><div class="label">Active Staff</div><div class="value"><?php echo count($data); ?></div></div>
            <div class="stat-tile green"><div class="label">Total Present</div><div class="value"><?php echo $sumP; ?></div></div>
            <div class="stat-tile red"><div class="label">Total Absent</div><div class="value"><?php echo $sumA; ?></div></div>
            <div class="stat-tile amber"><div class="label">Total Late</div><div class="value"><?php echo $sumL; ?></div></div>
        </div>

        <div class="section-title">Attendance Breakdown</div>
        <table class="ledger-table">
            <thead><tr>
                <th style="text-align:left;">Staff Member</th><th>Role</th>
                <th>Present</th><th>Absent</th><th>Late</th><th>Attendance Rate</th>
            </tr></thead>
            <tbody>
            <?php if (empty($data)): ?>
                <tr class="empty-row"><td colspan="6">No active staff records found.</td></tr>
            <?php else: foreach ($data as $row):
                $marks = $row['p'] + $row['a'] + $row['l'];
                $rate = $marks > 0 ? round(($row['p'] / $marks) * 100, 1) : 0;
                $rateClass = $rate >= 90 ? 'green' : ($rate >= 75 ? 'amber' : 'red');
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars(formatRoleName($row['role'])); ?></td>
                    <td style="color:#099268;font-weight:600;"><?php echo $row['p']; ?></td>
                    <td style="color:#c53030;font-weight:600;"><?php echo $row['a']; ?></td>
                    <td style="color:#b45309;font-weight:600;"><?php echo $row['l']; ?></td>
                    <td><span class="badge <?php echo $rateClass; ?>"><?php echo $marks > 0 ? $rate . '%' : 'N/A'; ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($data)): ?>
            <tfoot><tr>
                <td colspan="2" style="text-align:right;">Totals</td>
                <td><?php echo $sumP; ?></td><td><?php echo $sumA; ?></td><td><?php echo $sumL; ?></td>
                <td><?php echo $overallRate; ?>%</td>
            </tr></tfoot>
            <?php endif; ?>
        </table>
        <?php

    // ============================ PERFORMANCE ============================
    } elseif ($type === 'performance') {
        $stats = $db->query("
            SELECT COUNT(*) total_evals, AVG(overall_rating) avg_rating,
                   AVG(teaching_quality) avg_teaching, AVG(punctuality) avg_punctuality,
                   AVG(communication) avg_communication, AVG(professionalism) avg_professionalism,
                   AVG(teamwork) avg_teamwork, AVG(innovation) avg_innovation
            FROM staff_evaluations WHERE status = 'submitted'
        ")->fetch(PDO::FETCH_ASSOC);

        $top = $db->query("
            SELECT u.name, e.overall_rating, e.evaluation_period
            FROM staff_evaluations e JOIN users u ON e.staff_id = u.id
            WHERE e.status = 'submitted' ORDER BY e.overall_rating DESC LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $history = $db->query("
            SELECT e.overall_rating, e.evaluation_period, e.evaluated_at, e.status,
                   u.name staff_name, ev.name evaluator_name
            FROM staff_evaluations e
            JOIN users u ON e.staff_id = u.id
            JOIN users ev ON e.evaluator_id = ev.id
            ORDER BY e.evaluated_at DESC, e.id DESC LIMIT 100
        ")->fetchAll(PDO::FETCH_ASSOC);

        $r = fn($v) => $v !== null ? number_format((float)$v, 1) : '0.0';
        ?>
        <div class="section-title">Overall Performance Indicators</div>
        <div class="stat-grid">
            <div class="stat-tile"><div class="label">Total Evaluations</div><div class="value"><?php echo (int)$stats['total_evals']; ?></div></div>
            <div class="stat-tile green"><div class="label">Avg Overall Rating</div><div class="value"><?php echo $r($stats['avg_rating']); ?>/5</div></div>
            <div class="stat-tile"><div class="label">Avg Teaching</div><div class="value"><?php echo $r($stats['avg_teaching']); ?></div></div>
            <div class="stat-tile"><div class="label">Avg Punctuality</div><div class="value"><?php echo $r($stats['avg_punctuality']); ?></div></div>
        </div>
        <div class="stat-grid">
            <div class="stat-tile"><div class="label">Avg Communication</div><div class="value"><?php echo $r($stats['avg_communication']); ?></div></div>
            <div class="stat-tile"><div class="label">Avg Professionalism</div><div class="value"><?php echo $r($stats['avg_professionalism']); ?></div></div>
            <div class="stat-tile"><div class="label">Avg Teamwork</div><div class="value"><?php echo $r($stats['avg_teamwork']); ?></div></div>
            <div class="stat-tile"><div class="label">Avg Innovation</div><div class="value"><?php echo $r($stats['avg_innovation']); ?></div></div>
        </div>

        <div class="section-title">Top Performers</div>
        <table class="ledger-table">
            <thead><tr><th style="text-align:left;">Rank</th><th style="text-align:left;">Staff Member</th><th>Evaluation Period</th><th>Overall Rating</th></tr></thead>
            <tbody>
            <?php if (empty($top)): ?>
                <tr class="empty-row"><td colspan="4">No submitted evaluations yet.</td></tr>
            <?php else: $rank = 1; foreach ($top as $t): ?>
                <tr>
                    <td class="left" style="font-weight:700;color:#1e3a5f;">#<?php echo $rank++; ?></td>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($t['name']); ?></td>
                    <td><?php echo htmlspecialchars($t['evaluation_period'] ?: '-'); ?></td>
                    <td><span class="badge green"><?php echo $r($t['overall_rating']); ?> / 5</span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <div class="section-title">Evaluation History</div>
        <table class="ledger-table">
            <thead><tr><th style="text-align:left;">Staff Member</th><th style="text-align:left;">Evaluator</th><th>Period</th><th>Rating</th><th>Status</th><th>Date</th></tr></thead>
            <tbody>
            <?php if (empty($history)): ?>
                <tr class="empty-row"><td colspan="6">No evaluation history recorded.</td></tr>
            <?php else: foreach ($history as $h):
                $st = $h['status'] ?? 'draft';
                $stClass = $st === 'submitted' ? 'green' : 'gray';
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($h['staff_name']); ?></td>
                    <td class="left"><?php echo htmlspecialchars($h['evaluator_name']); ?></td>
                    <td><?php echo htmlspecialchars($h['evaluation_period'] ?: '-'); ?></td>
                    <td style="font-weight:700;"><?php echo $r($h['overall_rating']); ?></td>
                    <td><span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                    <td><?php echo $h['evaluated_at'] ? date('M d, Y', strtotime($h['evaluated_at'])) : '-'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php

    // ============================ PAYROLL ============================
    } elseif ($type === 'payroll') {
        $selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
        $selected_year  = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $monthName = date('F', mktime(0, 0, 0, $selected_month, 1));

        $totalPayroll = floatval($db->query("
            SELECT SUM(tp.salary) total FROM teacher_profiles tp JOIN users u ON tp.user_id = u.id
            WHERE u.status = 'active' AND u.role IN ($staff_roles_in)
        ")->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $ds = $db->prepare("SELECT SUM(amount) total FROM salary_payments WHERE month = ? AND year = ? AND status = 'paid'");
        $ds->execute([$selected_month, $selected_year]);
        $totalDisbursed = floatval($ds->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $ps = $db->prepare("SELECT SUM(amount) total FROM salary_payments WHERE month = ? AND year = ? AND status = 'partial'");
        $ps->execute([$selected_month, $selected_year]);
        $totalPartial = floatval($ps->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);

        $totalDisbursedActual = $totalDisbursed + $totalPartial;
        $totalPending = max(0, $totalPayroll - $totalDisbursedActual);
        $cur = fn($n) => htmlspecialchars($currency) . number_format((float)$n, 2);
        ?>
        <div class="section-title">Payroll Overview &mdash; <?php echo htmlspecialchars($monthName . ' ' . $selected_year); ?></div>
        <div class="stat-grid cols-3">
            <div class="stat-tile"><div class="label">Monthly Payroll</div><div class="value" style="font-size:13px;"><?php echo $cur($totalPayroll); ?></div></div>
            <div class="stat-tile green"><div class="label">Total Disbursed</div><div class="value" style="font-size:13px;"><?php echo $cur($totalDisbursedActual); ?></div></div>
            <div class="stat-tile amber"><div class="label">Total Pending</div><div class="value" style="font-size:13px;"><?php echo $cur($totalPending); ?></div></div>
        </div>

        <?php
        $dept = $db->query("
            SELECT tp.department, COUNT(u.id) staff_count, SUM(tp.salary) total_salary
            FROM users u JOIN teacher_profiles tp ON u.id = tp.user_id
            WHERE u.role IN ($staff_roles_in) AND u.status = 'active' GROUP BY tp.department
        ")->fetchAll(PDO::FETCH_ASSOC);
        $deptTotal = 0;
        ?>
        <div class="section-title">Summary by Department</div>
        <table class="ledger-table">
            <thead><tr><th style="text-align:left;">Department</th><th>Staff Count</th><th>Total Monthly Salary</th></tr></thead>
            <tbody>
            <?php if (empty($dept)): ?>
                <tr class="empty-row"><td colspan="3">No payroll data available.</td></tr>
            <?php else: foreach ($dept as $d): $deptTotal += $d['total_salary']; ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($d['department'] ?: 'Unassigned'); ?></td>
                    <td><?php echo (int)$d['staff_count']; ?></td>
                    <td class="right"><?php echo $cur($d['total_salary']); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if (!empty($dept)): ?>
            <tfoot><tr><td colspan="2" style="text-align:right;">Grand Total</td><td style="text-align:right;"><?php echo $cur($deptTotal); ?></td></tr></tfoot>
            <?php endif; ?>
        </table>

        <?php
        $trail = $db->prepare("
            SELECT u.name, u.role, tp.salary,
                   sp.amount paid_amount, sp.status, sp.payment_date, sp.payment_method, sp.reference_number
            FROM users u JOIN teacher_profiles tp ON u.id = tp.user_id
            LEFT JOIN salary_payments sp ON u.id = sp.user_id AND sp.month = ? AND sp.year = ?
            WHERE u.status = 'active' AND u.role IN ($staff_roles_in) ORDER BY u.name ASC
        ");
        $trail->execute([$selected_month, $selected_year]);
        $trailData = $trail->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="section-title">Payment Audit Trail</div>
        <table class="ledger-table">
            <thead><tr>
                <th style="text-align:left;">Staff Member</th><th style="text-align:left;">Role</th>
                <th>Base Salary</th><th>Paid Amount</th><th>Status</th><th>Date</th><th>Method</th><th style="text-align:left;">Reference</th>
            </tr></thead>
            <tbody>
            <?php if (empty($trailData)): ?>
                <tr class="empty-row"><td colspan="8">No active staff found.</td></tr>
            <?php else: foreach ($trailData as $tr):
                $st = $tr['status'] ?? 'pending';
                $stClass = $st === 'paid' ? 'green' : ($st === 'partial' ? 'blue' : 'amber');
                $paidDisplay = ($st !== 'pending' && $st !== null) ? $cur($tr['paid_amount']) : '-';
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($tr['name']); ?></td>
                    <td class="left"><?php echo htmlspecialchars(formatRoleName($tr['role'])); ?></td>
                    <td class="right"><?php echo $cur($tr['salary']); ?></td>
                    <td class="right" style="font-weight:600;"><?php echo $paidDisplay; ?></td>
                    <td><span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                    <td><?php echo $tr['payment_date'] ? date('M d, Y', strtotime($tr['payment_date'])) : '-'; ?></td>
                    <td><?php echo $tr['payment_method'] ? htmlspecialchars(ucfirst(str_replace('_', ' ', $tr['payment_method']))) : '-'; ?></td>
                    <td class="left" style="font-family:monospace;font-size:9px;"><?php echo $tr['reference_number'] ? htmlspecialchars($tr['reference_number']) : '-'; ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php

    // ============================ LEAVES ============================
    } elseif ($type === 'leaves') {
        $stats = $db->query("
            SELECT COUNT(*) total,
                SUM(CASE WHEN lr.status='pending' THEN 1 ELSE 0 END) pending,
                SUM(CASE WHEN lr.status='approved' THEN 1 ELSE 0 END) approved,
                SUM(CASE WHEN lr.status='rejected' THEN 1 ELSE 0 END) rejected,
                SUM(CASE WHEN lr.status='approved' AND lr.start_date<=CURDATE() AND lr.end_date>=CURDATE() THEN 1 ELSE 0 END) active_now
            FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE u.role IN ($staff_roles_in)
        ")->fetch(PDO::FETCH_ASSOC);

        $status_filter = (isset($_GET['status']) && in_array($_GET['status'], ['pending','approved','rejected'])) ? $_GET['status'] : 'all';
        $where = $status_filter !== 'all' ? "AND lr.status = :st" : "";
        $q = $db->prepare("
            SELECT lr.*, u.name staff_name, u.role, tp.department,
                   (DATEDIFF(lr.end_date, lr.start_date) + 1) AS days
            FROM leave_requests lr JOIN users u ON lr.user_id = u.id
            LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
            WHERE u.role IN ($staff_roles_in) $where ORDER BY lr.created_at DESC LIMIT 200
        ");
        if ($status_filter !== 'all') $q->bindValue(':st', $status_filter);
        $q->execute();
        $leaves = $q->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="section-title">Leave Summary</div>
        <div class="stat-grid">
            <div class="stat-tile"><div class="label">Total Requests</div><div class="value"><?php echo (int)$stats['total']; ?></div></div>
            <div class="stat-tile amber"><div class="label">Pending</div><div class="value"><?php echo (int)$stats['pending']; ?></div></div>
            <div class="stat-tile green"><div class="label">Approved</div><div class="value"><?php echo (int)$stats['approved']; ?></div></div>
            <div class="stat-tile red"><div class="label">Rejected</div><div class="value"><?php echo (int)$stats['rejected']; ?></div></div>
        </div>

        <div class="section-title">Leave Requests<?php echo $status_filter !== 'all' ? ' &mdash; ' . htmlspecialchars(ucfirst($status_filter)) : ''; ?></div>
        <table class="ledger-table">
            <thead><tr>
                <th style="text-align:left;">Staff Member</th><th style="text-align:left;">Department</th>
                <th style="text-align:left;">Leave Type</th><th>Start</th><th>End</th><th>Days</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php if (empty($leaves)): ?>
                <tr class="empty-row"><td colspan="7">No leave requests found.</td></tr>
            <?php else: foreach ($leaves as $lv):
                $st = $lv['status'] ?? 'pending';
                $stClass = $st === 'approved' ? 'green' : ($st === 'rejected' ? 'red' : 'amber');
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($lv['staff_name']); ?></td>
                    <td class="left"><?php echo htmlspecialchars($lv['department'] ?: formatRoleName($lv['role'])); ?></td>
                    <td class="left"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $lv['leave_type'] ?? '-'))); ?></td>
                    <td><?php echo $lv['start_date'] ? date('M d, Y', strtotime($lv['start_date'])) : '-'; ?></td>
                    <td><?php echo $lv['end_date'] ? date('M d, Y', strtotime($lv['end_date'])) : '-'; ?></td>
                    <td style="font-weight:600;"><?php echo (int)$lv['days']; ?></td>
                    <td><span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php

    // ============================ QUALIFICATIONS ============================
    } elseif ($type === 'qualifications') {
        $db->exec("UPDATE staff_qualifications SET status = 'expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status != 'expired'");

        $stats = $db->query("
            SELECT COUNT(*) total,
                SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active,
                SUM(CASE WHEN status='expired' THEN 1 ELSE 0 END) expired
            FROM staff_qualifications
        ")->fetch(PDO::FETCH_ASSOC);

        $expiring = $db->query("
            SELECT q.title, q.expiry_date, u.name staff_name, DATEDIFF(q.expiry_date, CURDATE()) days_left
            FROM staff_qualifications q JOIN users u ON q.staff_id = u.id
            WHERE q.expiry_date IS NOT NULL AND q.status='active'
            AND q.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY q.expiry_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $quals = $db->query("
            SELECT q.*, u.name staff_name, u.role, tp.employee_id
            FROM staff_qualifications q JOIN users u ON q.staff_id = u.id
            LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
            ORDER BY q.date_obtained DESC LIMIT 200
        ")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="section-title">Credentials Summary</div>
        <div class="stat-grid cols-3">
            <div class="stat-tile"><div class="label">Total Records</div><div class="value"><?php echo (int)$stats['total']; ?></div></div>
            <div class="stat-tile green"><div class="label">Active</div><div class="value"><?php echo (int)$stats['active']; ?></div></div>
            <div class="stat-tile red"><div class="label">Expired</div><div class="value"><?php echo (int)$stats['expired']; ?></div></div>
        </div>

        <?php if (!empty($expiring)): ?>
        <div class="section-title">Expiring Within 90 Days</div>
        <table class="ledger-table">
            <thead><tr><th style="text-align:left;">Staff Member</th><th style="text-align:left;">Qualification</th><th>Expiry Date</th><th>Days Left</th></tr></thead>
            <tbody>
            <?php foreach ($expiring as $ex):
                $dl = (int)$ex['days_left'];
                $dlClass = $dl <= 30 ? 'red' : 'amber';
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($ex['staff_name']); ?></td>
                    <td class="left"><?php echo htmlspecialchars($ex['title']); ?></td>
                    <td><?php echo date('M d, Y', strtotime($ex['expiry_date'])); ?></td>
                    <td><span class="badge <?php echo $dlClass; ?>"><?php echo $dl; ?> days</span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="section-title">All Qualifications &amp; Certifications</div>
        <table class="ledger-table">
            <thead><tr>
                <th style="text-align:left;">Staff Member</th><th style="text-align:left;">Title</th>
                <th style="text-align:left;">Type</th><th style="text-align:left;">Institution</th>
                <th>Obtained</th><th>Expiry</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php if (empty($quals)): ?>
                <tr class="empty-row"><td colspan="7">No qualifications recorded.</td></tr>
            <?php else: foreach ($quals as $ql):
                $st = $ql['status'] ?? 'active';
                $stClass = $st === 'active' ? 'green' : ($st === 'expired' ? 'red' : 'gray');
            ?>
                <tr>
                    <td class="left" style="font-weight:600;"><?php echo htmlspecialchars($ql['staff_name']); ?></td>
                    <td class="left"><?php echo htmlspecialchars($ql['title']); ?></td>
                    <td class="left"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $ql['type'] ?? '-'))); ?></td>
                    <td class="left"><?php echo htmlspecialchars($ql['institution'] ?: '-'); ?></td>
                    <td><?php echo $ql['date_obtained'] ? date('M d, Y', strtotime($ql['date_obtained'])) : '-'; ?></td>
                    <td><?php echo $ql['expiry_date'] ? date('M d, Y', strtotime($ql['expiry_date'])) : 'No Expiry'; ?></td>
                    <td><span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        <?php

    } else {
        echo '<div class="empty-state"><i class="fas fa-folder-open"></i><p>This report module (' . htmlspecialchars($type) . ') is not available.</p></div>';
    }
    ?>

        <?php echo signatureRow([($generated_by !== '' ? $generated_by . ' (Prepared By)' : 'Prepared By'), 'Headmaster/Headmistress']); ?>
    </div>
</body>
</html>
<?php
    exit();
}

echo json_encode(['error' => 'Invalid action']);
