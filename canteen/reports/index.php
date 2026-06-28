<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
require_once '../../includes/signature_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Date range filters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Default to start of month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Default to today

// 1. Fetch Summary Stats
$orders_stat_query = "SELECT COUNT(*) as total_orders, SUM(total_price) as order_revenue 
                      FROM canteen_orders 
                      WHERE order_date BETWEEN :start_date AND :end_date AND status = 'served'";
$orders_stat_stmt = $db->prepare($orders_stat_query);
$orders_stat_stmt->bindParam(':start_date', $start_date);
$orders_stat_stmt->bindParam(':end_date', $end_date);
$orders_stat_stmt->execute();
$orders_stats = $orders_stat_stmt->fetch(PDO::FETCH_ASSOC);

$total_orders = $orders_stats['total_orders'] ?? 0;
$order_revenue = $orders_stats['order_revenue'] ?? 0;

$reg_stat_query = "SELECT COUNT(*) as total_regs, SUM(amount_paid) as reg_revenue 
                   FROM canteen_registrations 
                   WHERE DATE(created_at) BETWEEN :start_date AND :end_date AND status = 'active'";
$reg_stat_stmt = $db->prepare($reg_stat_query);
$reg_stat_stmt->bindParam(':start_date', $start_date);
$reg_stat_stmt->bindParam(':end_date', $end_date);
$reg_stat_stmt->execute();
$reg_stats = $reg_stat_stmt->fetch(PDO::FETCH_ASSOC);

$total_registrations = $reg_stats['total_regs'] ?? 0;
$reg_revenue = $reg_stats['reg_revenue'] ?? 0;

$total_revenue = $order_revenue + $reg_revenue;

// 2. Fetch Popular Menu Items
$popular_query = "SELECT cm.item_name, cm.meal_type, COUNT(co.id) as total_sold, SUM(co.total_price) as revenue
                  FROM canteen_orders co
                  JOIN canteen_menu cm ON co.menu_id = cm.id
                  WHERE co.order_date BETWEEN :start_date AND :end_date AND co.status = 'served'
                  GROUP BY cm.id
                  ORDER BY total_sold DESC, revenue DESC
                  LIMIT 5";
$popular_stmt = $db->prepare($popular_query);
$popular_stmt->bindParam(':start_date', $start_date);
$popular_stmt->bindParam(':end_date', $end_date);
$popular_stmt->execute();
$popular_items = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch Daily Sales Summary
$daily_query = "SELECT order_date, COUNT(*) as orders_count, SUM(total_price) as daily_revenue
                FROM canteen_orders
                WHERE order_date BETWEEN :start_date AND :end_date AND status = 'served'
                GROUP BY order_date
                ORDER BY order_date DESC";
$daily_stmt = $db->prepare($daily_query);
$daily_stmt->bindParam(':start_date', $start_date);
$daily_stmt->bindParam(':end_date', $end_date);
$daily_stmt->execute();
$daily_sales = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=canteen_report_' . $start_date . '_to_' . $end_date . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // Title
    fputcsv($output, ['Canteen Sales & Usage Report']);
    fputcsv($output, ['Report Period', $start_date . ' to ' . $end_date]);
    fputcsv($output, []);
    
    // Overview metrics
    fputcsv($output, ['OVERVIEW METRICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Revenue', 'GHS ' . number_format($total_revenue, 2)]);
    fputcsv($output, ['Order Revenue (Served)', 'GHS ' . number_format($order_revenue, 2)]);
    fputcsv($output, ['Registration Revenue', 'GHS ' . number_format($reg_revenue, 2)]);
    fputcsv($output, ['Total Orders Served', $total_orders]);
    fputcsv($output, ['Active Subscriptions Created', $total_registrations]);
    fputcsv($output, []);
    
    // Popular items
    fputcsv($output, ['TOP SELLING ITEMS']);
    fputcsv($output, ['Item Name', 'Meal Type', 'Total Sold', 'Revenue (GHS)']);
    foreach ($popular_items as $item) {
        fputcsv($output, [$item['item_name'], ucfirst($item['meal_type']), $item['total_sold'], $item['revenue']]);
    }
    fputcsv($output, []);
    
    // Daily Sales
    fputcsv($output, ['DAILY SALES SUMMARY']);
    fputcsv($output, ['Date', 'Orders Count', 'Revenue (GHS)']);
    foreach ($daily_sales as $day) {
        fputcsv($output, [$day['order_date'], $day['orders_count'], $day['daily_revenue']]);
    }
    
    fclose($output);
    exit();
}

// 5. Handle PDF / Print export (printable statement-style document)
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // School branding (mirrors the account statement template)
    $school_name    = getSchoolSetting('school_name', 'Greenwood Academy');
    $school_address = getSchoolSetting('school_address', '');
    $school_phone   = getSchoolSetting('school_phone', '');
    $school_email   = getSchoolSetting('school_email', '');
    $school_motto   = '';
    $school_postal  = '';
    try {
        $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
        $motto_stmt->execute();
        if ($row = $motto_stmt->fetch(PDO::FETCH_ASSOC)) { $school_motto = $row['setting_value']; }

        $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
        $postal_stmt->execute();
        if ($row = $postal_stmt->fetch(PDO::FETCH_ASSOC)) { $school_postal = $row['setting_value']; }
    } catch (PDOException $e) {
        // Settings not available yet
    }
    $logo_url = function_exists('getSchoolLogo') ? getSchoolLogo() : '';
    $avg_order = $total_orders > 0 ? $order_revenue / $total_orders : 0;
    $back_url = 'index.php?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canteen Report - <?php echo htmlspecialchars($start_date . ' to ' . $end_date); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 11px; line-height: 1.4; color: #1a1a1a; background: #f0f0f0; }
        .statement-card { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 12mm 15mm; background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; }
        .no-print-controls { text-align: center; padding: 15px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; position: sticky; top: 0; z-index: 100; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .no-print-controls button, .no-print-controls a { padding: 8px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 13px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
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
        .statement-title { text-align: center; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 20px; font-size: 14px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin: 10px 0; border-radius: 4px; }
        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d0d0d0; margin-bottom: 15px; border-radius: 4px; overflow: hidden; }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #333; padding: 5px 10px; background: #f5f7fa; min-width: 120px; border-right: 1px solid #e5e5e5; }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }
        .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 18px; }
        .summary-box { border: 1px solid #d0d0d0; border-radius: 4px; padding: 8px 10px; background: #f9fafb; }
        .summary-box .label { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 0.3px; }
        .summary-box .value { font-size: 16px; font-weight: 800; color: #1e3a5f; margin-top: 2px; }
        .summary-box .sub { font-size: 8.5px; color: #888; margin-top: 1px; }
        .section-title { font-size: 12px; font-weight: 700; color: #1e3a5f; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #1e3a5f; margin-bottom: 8px; border-radius: 0 4px 4px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 18px; font-size: 10px; }
        .ledger-table thead th { background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #1a3455; }
        .ledger-table thead th:first-child, .ledger-table thead th:nth-child(2) { text-align: left; }
        .ledger-table tbody td { padding: 5px 8px; text-align: right; border: 1px solid #ddd; }
        .ledger-table tbody td:first-child, .ledger-table tbody td:nth-child(2) { text-align: left; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }
        .ledger-table tfoot td { padding: 6px 8px; border: 1px solid #ddd; font-weight: 800; background: #eef2f7; color: #1e3a5f; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 45px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }
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
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn-back">&larr; Back to Reports</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print / Save as PDF</button>
    </div>

    <div class="statement-card">
        <!-- School Header -->
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

        <!-- Title -->
        <div class="statement-title">Canteen Sales &amp; Usage Report</div>

        <!-- Report Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Report Period</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars(date('M d, Y', strtotime($start_date)) . ' — ' . date('M d, Y', strtotime($end_date))); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Orders Served</span>
                    <span class="info-value"><?php echo (int)$total_orders; ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Date Generated</span>
                    <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Active Plans</span>
                    <span class="info-value"><?php echo (int)$total_registrations; ?></span>
                </div>
            </div>
        </div>

        <!-- Overview Metrics -->
        <div class="section-title">Overview Metrics</div>
        <div class="summary-grid">
            <div class="summary-box">
                <div class="label">Total Revenue</div>
                <div class="value">&#8373;<?php echo number_format($total_revenue, 2); ?></div>
                <div class="sub">Combined sales &amp; plans</div>
            </div>
            <div class="summary-box">
                <div class="label">Menu Sales</div>
                <div class="value">&#8373;<?php echo number_format($order_revenue, 2); ?></div>
                <div class="sub"><?php echo (int)$total_orders; ?> served orders</div>
            </div>
            <div class="summary-box">
                <div class="label">Plan Subscriptions</div>
                <div class="value">&#8373;<?php echo number_format($reg_revenue, 2); ?></div>
                <div class="sub"><?php echo (int)$total_registrations; ?> active plans</div>
            </div>
            <div class="summary-box">
                <div class="label">Avg. Order Value</div>
                <div class="value">&#8373;<?php echo number_format($avg_order, 2); ?></div>
                <div class="sub">Per order placed</div>
            </div>
        </div>

        <!-- Top Selling Items -->
        <div class="section-title">Top Selling Items</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 45%">Item Name</th>
                    <th style="width: 20%">Meal Type</th>
                    <th style="width: 17%; text-align: right;">Total Sold</th>
                    <th style="width: 18%; text-align: right;">Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($popular_items)): ?>
                    <?php foreach ($popular_items as $item): ?>
                    <tr>
                        <td style="text-align: left; font-weight: 500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="text-align: left;"><?php echo htmlspecialchars(ucfirst($item['meal_type'])); ?></td>
                        <td><?php echo (int)$item['total_sold']; ?></td>
                        <td style="font-weight: 600;">&#8373;<?php echo number_format($item['revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; padding: 12px; color: #666;">No sales data for this period.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Daily Sales Summary -->
        <div class="section-title">Daily Sales Summary</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 50%">Date</th>
                    <th style="width: 25%; text-align: right;">Orders Served</th>
                    <th style="width: 25%; text-align: right;">Daily Revenue</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($daily_sales)): ?>
                    <?php foreach ($daily_sales as $day): ?>
                    <tr>
                        <td style="text-align: left;"><?php echo date('M d, Y', strtotime($day['order_date'])); ?></td>
                        <td><?php echo (int)$day['orders_count']; ?></td>
                        <td style="font-weight: 600;">&#8373;<?php echo number_format($day['daily_revenue'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" style="text-align: center; padding: 12px; color: #666;">No data found.</td></tr>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($daily_sales)): ?>
            <tfoot>
                <tr>
                    <td style="text-align: left;">Total</td>
                    <td style="text-align: right;"><?php echo (int)array_sum(array_column($daily_sales, 'orders_count')); ?></td>
                    <td style="text-align: right;">&#8373;<?php echo number_format(array_sum(array_column($daily_sales, 'daily_revenue')), 2); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>

        <!-- Signatures -->
        <?php echo signatureRow(['Canteen Manager', 'Headmaster / Headmistress']); ?>
    </div>
</body>
</html>
    <?php
    exit();
}

$title = "Canteen Reports";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Canteen', 'url' => '../index.php'],
    ['title' => 'Reports']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Canteen Reports & Insights</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Monitor revenue, sales patterns, and popular menu items</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Canteen
                        </a>
                        <a href="?export=csv&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>"
                           class="inline-flex items-center whitespace-nowrap bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors shadow">
                            <i class="fas fa-file-csv mr-2"></i>Export to CSV
                        </a>
                        <a href="?export=pdf&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" target="_blank"
                           class="inline-flex items-center whitespace-nowrap bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors shadow">
                            <i class="fas fa-file-pdf mr-2"></i>Download PDF
                        </a>
                    </div>
                </div>

                <!-- Navigation breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Reports</span>
                </div>

                <!-- Filter Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-4">
                        <form method="GET" class="flex flex-col md:flex-row gap-4 md:items-end">
                            <div class="flex-grow">
                                <label for="start_date" class="block text-sm font-medium text-gray-750 dark:text-gray-300 mb-1">Start Date</label>
                                <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" 
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="flex-grow">
                                <label for="end_date" class="block text-sm font-medium text-gray-750 dark:text-gray-300 mb-1">End Date</label>
                                <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" 
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition-colors w-full md:w-auto h-[42px]">
                                Generate Report
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Overview Stats Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Revenue -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Revenue</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400">₵<?php echo number_format($total_revenue, 2); ?></p>
                                <span class="text-xs text-gray-500 mt-1 block">Combined sales & plans</span>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-coins text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Order Revenue -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Menu Sales</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">₵<?php echo number_format($order_revenue, 2); ?></p>
                                <span class="text-xs text-gray-500 mt-1 block"><?php echo $total_orders; ?> served orders</span>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-hamburger text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Plan Revenue -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Plan Subscriptions</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400">₵<?php echo number_format($reg_revenue, 2); ?></p>
                                <span class="text-xs text-gray-500 mt-1 block"><?php echo $total_registrations; ?> active student plans</span>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-id-card text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Average Order Value -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Avg. Order Value</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400">₵<?php echo number_format($total_orders > 0 ? $order_revenue / $total_orders : 0, 2); ?></p>
                                <span class="text-xs text-gray-500 mt-1 block">Per order placed</span>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-shopping-bag text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Top Selling Items -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 lg:col-span-1">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-fire mr-2 text-red-500"></i>Top Selling Items
                        </h3>
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (!empty($popular_items)): ?>
                                <?php foreach ($popular_items as $item): ?>
                                <div class="py-4 first:pt-0 last:pb-0 flex justify-between items-center">
                                    <div>
                                        <h4 class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo ucfirst($item['meal_type']); ?> • Sold: <?php echo $item['total_sold']; ?></p>
                                    </div>
                                    <span class="text-sm font-semibold text-green-600 dark:text-green-400">₵<?php echo number_format($item['revenue'], 2); ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-gray-500 dark:text-gray-400 text-center py-6">No sales data for this period.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Daily Sales Table -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-calendar-alt mr-2 text-blue-500"></i>Daily Sales Summary
                        </h3>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Date</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Orders Served</th>
                                        <th class="px-4 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase">Daily Revenue</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if (!empty($daily_sales)): ?>
                                        <?php foreach ($daily_sales as $day): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                <?php echo date('M d, Y', strtotime($day['order_date'])); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                                <?php echo $day['orders_count']; ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-green-600 dark:text-green-400">
                                                ₵<?php echo number_format($day['daily_revenue'], 2); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="px-4 py-6 text-center text-gray-500 dark:text-gray-400">No data found.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>