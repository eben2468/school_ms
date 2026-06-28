<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'librarian'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
$database = new Database();
$db = $database->getConnection();

// School settings for the printable report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();

$today = date('Y-m-d');

// Categories for the filter
$categories = $db->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// ---- Filters ----
$selected_category = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : '';

$range = $db->query("SELECT MIN(borrowed_date) AS mn, MAX(borrowed_date) AS mx FROM book_loans")->fetch(PDO::FETCH_ASSOC);
$data_min = $range['mn'] ?? date('Y-m-d', strtotime('-90 days'));
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $data_min;
$to_date   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

$d1 = DateTime::createFromFormat('Y-m-d', $from_date);
$d2 = DateTime::createFromFormat('Y-m-d', $to_date);
if (!$d1 || $d1->format('Y-m-d') !== $from_date) { $from_date = $data_min; }
if (!$d2 || $d2->format('Y-m-d') !== $to_date) { $to_date = $today; }
if ($from_date > $to_date) { $tmp = $from_date; $from_date = $to_date; $to_date = $tmp; }

// Category clause helper (applied to the book join)
$cat_clause = '';
$cat_param = [];
if ($selected_category !== '') { $cat_clause = " AND b.category = :category "; $cat_param[':category'] = $selected_category; }

// Borrows = loans whose borrowed_date is in range
$borrow_params = array_merge([':from_date' => $from_date, ':to_date' => $to_date], $cat_param);
// Returns = loans whose returned_date is in range
$return_params = array_merge([':from_date' => $from_date, ':to_date' => $to_date], $cat_param);

$base = "FROM book_loans bl JOIN library_books b ON bl.book_id = b.id";

// ---- Summary: borrows ----
$borrow_stmt = $db->prepare("SELECT COUNT(*) AS borrows $base WHERE bl.borrowed_date BETWEEN :from_date AND :to_date $cat_clause");
$borrow_stmt->execute($borrow_params);
$total_borrows = (int)$borrow_stmt->fetchColumn();

// ---- Summary: returns, duration, punctuality (loans returned in range) ----
$return_stmt = $db->prepare("
    SELECT COUNT(*) AS returns_count,
           AVG(DATEDIFF(bl.returned_date, bl.borrowed_date)) AS avg_days,
           SUM(CASE WHEN bl.returned_date <= bl.due_date THEN 1 ELSE 0 END) AS ontime_count
    $base
    WHERE bl.returned_date IS NOT NULL AND bl.returned_date BETWEEN :from_date AND :to_date $cat_clause
");
$return_stmt->execute($return_params);
$ret = $return_stmt->fetch(PDO::FETCH_ASSOC);
$total_returns = (int)$ret['returns_count'];
$avg_days = $ret['avg_days'] !== null ? round((float)$ret['avg_days'], 1) : 0;
$ontime_count = (int)$ret['ontime_count'];
$late_count = max(0, $total_returns - $ontime_count);
$ontime_rate = $total_returns > 0 ? ($ontime_count / $total_returns) * 100 : 0;

// ---- Currently out (snapshot, category-aware) ----
$out_stmt = $db->prepare("SELECT COUNT(*) $base WHERE bl.returned_date IS NULL $cat_clause");
$out_stmt->execute($cat_param);
$currently_out = (int)$out_stmt->fetchColumn();

// ---- Borrows trend (by month) ----
$bt_stmt = $db->prepare("SELECT DATE_FORMAT(bl.borrowed_date, '%Y-%m') AS ym, COUNT(*) AS cnt
    $base WHERE bl.borrowed_date BETWEEN :from_date AND :to_date $cat_clause GROUP BY ym");
$bt_stmt->execute($borrow_params);
$borrow_trend = [];
foreach ($bt_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $borrow_trend[$r['ym']] = (int)$r['cnt']; }

// ---- Returns trend (by month) ----
$rt_stmt = $db->prepare("SELECT DATE_FORMAT(bl.returned_date, '%Y-%m') AS ym, COUNT(*) AS cnt
    $base WHERE bl.returned_date IS NOT NULL AND bl.returned_date BETWEEN :from_date AND :to_date $cat_clause GROUP BY ym");
$rt_stmt->execute($return_params);
$return_trend = [];
foreach ($rt_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $return_trend[$r['ym']] = (int)$r['cnt']; }

// Merge months for the activity chart
$months = array_unique(array_merge(array_keys($borrow_trend), array_keys($return_trend)));
sort($months);
$trend_labels = [];
$trend_borrows = [];
$trend_returns = [];
foreach ($months as $m) {
    $trend_labels[] = date('M Y', strtotime($m . '-01'));
    $trend_borrows[] = $borrow_trend[$m] ?? 0;
    $trend_returns[] = $return_trend[$m] ?? 0;
}

// ---- Per-book circulation summary (borrows in range) ----
$book_stmt = $db->prepare("
    SELECT b.title, b.author, COALESCE(b.category, 'Uncategorized') AS category,
           b.total_copies, b.copies_available,
           COUNT(bl.id) AS borrows,
           SUM(CASE WHEN bl.returned_date IS NOT NULL THEN 1 ELSE 0 END) AS returns_count,
           SUM(CASE WHEN bl.returned_date IS NULL THEN 1 ELSE 0 END) AS out_now
    $base
    WHERE bl.borrowed_date BETWEEN :from_date AND :to_date $cat_clause
    GROUP BY b.id, b.title, b.author, b.category, b.total_copies, b.copies_available
    ORDER BY borrows DESC, b.title ASC
    LIMIT 100
");
$book_stmt->execute($borrow_params);
$book_data = $book_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Top borrowers ----
$borrower_stmt = $db->prepare("
    SELECT u.name AS borrower_name, u.role AS borrower_role,
           COUNT(bl.id) AS loans,
           SUM(CASE WHEN bl.returned_date IS NOT NULL THEN 1 ELSE 0 END) AS returns_count,
           SUM(CASE WHEN bl.returned_date IS NULL THEN 1 ELSE 0 END) AS out_now
    FROM book_loans bl
    JOIN library_books b ON bl.book_id = b.id
    JOIN users u ON bl.user_id = u.id
    WHERE bl.borrowed_date BETWEEN :from_date AND :to_date $cat_clause
    GROUP BY u.id, u.name, u.role
    ORDER BY loans DESC, u.name ASC
    LIMIT 10
");
$borrower_stmt->execute($borrow_params);
$borrower_data = $borrower_stmt->fetchAll(PDO::FETCH_ASSOC);

$selected_category_name = $selected_category !== '' ? $selected_category : 'All Categories';

// ---- CSV export (per-book circulation) ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="book_circulation_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Book Circulation Report']);
    fputcsv($out, ['Category', $selected_category_name]);
    fputcsv($out, ['Period', $from_date . ' to ' . $to_date]);
    fputcsv($out, ['Total Borrows', $total_borrows]);
    fputcsv($out, ['Total Returns', $total_returns]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Title', 'Author', 'Category', 'Copies', 'Borrows', 'Returns', 'Currently Out', 'Turnover Ratio']);
    foreach ($book_data as $bk) {
        $turnover = $bk['total_copies'] > 0 ? round($bk['borrows'] / $bk['total_copies'], 2) : $bk['borrows'];
        fputcsv($out, [
            $bk['title'], $bk['author'], $bk['category'], $bk['total_copies'],
            $bk['borrows'], $bk['returns_count'], $bk['out_now'], $turnover,
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'category' => $selected_category, 'from_date' => $from_date, 'to_date' => $to_date, 'export' => 'csv',
]);

$title = "Book Circulation Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Book Circulation Report']
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div id="web-layout" class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Book Circulation Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Monitor borrowing and return activity, turnover, and return punctuality.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($book_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($book_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category</label>
                            <select name="category" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selected_category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">From Date</label>
                            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">To Date</label>
                            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_category_name); ?></span> &bull;
                        <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        <span class="ml-2">&bull; <?php echo $currently_out; ?> currently out</span>
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Borrows</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($total_borrows); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-arrow-up-from-bracket text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Returns</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($total_returns); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-rotate-left text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">On-Time Return Rate</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($ontime_rate, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-emerald-600 dark:text-emerald-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Loan Duration</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $avg_days; ?> <span class="text-lg font-medium text-gray-400">days</span></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hourglass-half text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($total_borrows > 0 || $total_returns > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Circulation Activity</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Borrows vs. returns per month</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="activityChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Return Punctuality</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">On-time vs. late returns in the period</p>
                        <div class="relative flex items-center justify-center" style="height: 260px;">
                            <canvas id="punctualityChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Book Circulation Summary -->
                <?php if (!empty($book_data)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Book Circulation Summary</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo count($book_data); ?> title(s) circulated &bull; <?php echo htmlspecialchars($selected_category_name); ?>
                            &bull; <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Category</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Copies</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Borrows</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Returns</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Out Now</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Turnover</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($book_data as $bk):
                                    $turnover = $bk['total_copies'] > 0 ? $bk['borrows'] / $bk['total_copies'] : $bk['borrows'];
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($bk['title']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($bk['author']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($bk['category']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$bk['total_copies']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-bold text-blue-600 dark:text-blue-400"><?php echo (int)$bk['borrows']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-green-600 dark:text-green-400"><?php echo (int)$bk['returns_count']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-amber-600 dark:text-amber-400"><?php echo (int)$bk['out_now']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-gray-900 dark:text-white"><?php echo number_format($turnover, 2); ?>&times;</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Borrowers -->
                <?php if (!empty($borrower_data)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Most Active Borrowers</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">Members with the most loans in the period</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Borrower</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Loans</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Returned</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Out Now</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php $rank = 1; foreach ($borrower_data as $bw): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">#<?php echo $rank; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($bw['borrower_name']); ?>
                                        <div class="text-xs text-gray-400 capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $bw['borrower_role'] ?? '')); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-bold text-indigo-600 dark:text-indigo-400"><?php echo (int)$bw['loans']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-green-600 dark:text-green-400"><?php echo (int)$bw['returns_count']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-amber-600 dark:text-amber-400"><?php echo (int)$bw['out_now']; ?></td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-exchange-alt text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Circulation Activity</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No books were borrowed in the selected period. Try widening the date range or clearing the category filter.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- PRINT REPORT TEMPLATE                                        -->
<!-- ============================================================ -->
<?php if (!empty($book_data)): ?>
<div id="print-report" class="print-report-container">
    <div class="print-page">
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="print-logo-fallback" style="display:none"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
                </div>
                <div class="print-school-info">
                    <h1 class="print-school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <div class="print-title-banner"><h2>Book Circulation Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Category:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_category_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Period:</span><span class="print-meta-value"><?php echo htmlspecialchars($from_date . ' – ' . $to_date); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Borrows / Returns:</span><span class="print-meta-value"><?php echo $total_borrows; ?> / <?php echo $total_returns; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">On-Time Rate:</span><span class="print-meta-value"><?php echo number_format($ontime_rate, 1); ?>%</span></div>
        </div>

        <div class="print-section-title">Book Circulation Summary</div>
        <table class="print-table">
            <thead>
                <tr><th style="text-align:left">Title</th><th>Category</th><th>Copies</th><th>Borrows</th><th>Returns</th><th>Out</th><th>Turnover</th></tr>
            </thead>
            <tbody>
                <?php foreach ($book_data as $bk):
                    $turnover = $bk['total_copies'] > 0 ? $bk['borrows'] / $bk['total_copies'] : $bk['borrows'];
                ?>
                <tr>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($bk['title']); ?></td>
                    <td><?php echo htmlspecialchars($bk['category']); ?></td>
                    <td><?php echo (int)$bk['total_copies']; ?></td>
                    <td class="pct-cell"><?php echo (int)$bk['borrows']; ?></td>
                    <td><?php echo (int)$bk['returns_count']; ?></td>
                    <td><?php echo (int)$bk['out_now']; ?></td>
                    <td><?php echo number_format($turnover, 2); ?>x</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if (!empty($borrower_data)): ?>
        <div class="print-section-title">Most Active Borrowers</div>
        <table class="print-table">
            <thead><tr><th style="width:40px">#</th><th style="text-align:left">Borrower</th><th>Role</th><th>Loans</th><th>Returned</th><th>Out</th></tr></thead>
            <tbody>
                <?php $pr = 1; foreach ($borrower_data as $bw): ?>
                <tr>
                    <td><?php echo $pr; ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($bw['borrower_name']); ?></td>
                    <td><?php echo htmlspecialchars(formatRoleName($bw['borrower_role'] ?? '')); ?></td>
                    <td class="pct-cell"><?php echo (int)$bw['loans']; ?></td>
                    <td><?php echo (int)$bw['returns_count']; ?></td>
                    <td><?php echo (int)$bw['out_now']; ?></td>
                </tr>
                <?php $pr++; endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php echo signatureRow(['Librarian', 'Administrator', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>This is a computer-generated document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .print-report-container { display: none; }
    @media print {
        header, #sidebar, #web-layout, .search-overlay { display: none !important; }
        .print-report-container { display: block !important; }
        body, main {
            display: block !important; margin: 0 !important; padding: 0 !important;
            background: white !important; min-height: auto !important; height: auto !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        }
        @page { size: A4 portrait; margin: 10mm; }
    }
    .print-page { font-family: 'Inter','Segoe UI',sans-serif; font-size: 10.5px; line-height: 1.45; color: #1a1a2e; max-width: 210mm; margin: 0 auto; }
    .print-header-inner { display: flex; align-items: center; gap: 16px; padding-bottom: 10px; }
    .print-logo img, .print-logo-fallback { width: 60px; height: 60px; object-fit: contain; }
    .print-logo-fallback { background: linear-gradient(135deg,#1e3a5f,#2563eb); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:26px; font-weight:800; }
    .print-school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1.2px; text-transform: uppercase; margin: 0 0 2px 0; }
    .print-contact-line { font-size: 9px; color: #6b7280; margin: 0; }
    .print-header-divider { height: 3px; background: linear-gradient(to right,#1e3a5f,#2563eb,#7c3aed); border-radius: 3px; margin-bottom: 12px; }
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#1e3a5f,#4f46e5); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #1e3a5f; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #1e3a5f; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #4f46e5; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#1e3a5f,#4f46e5); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #1a3455; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #1e3a5f; }
    .print-signatures { display: grid; grid-template-columns: repeat(3,1fr); gap: 30px; margin-top: 28px; margin-bottom: 16px; }
    .print-signature-block { text-align: center; }
    .print-signature-block .signature-line { border-top: 1.5px solid #374151; margin-top: 36px; padding-top: 4px; }
    .signature-title { font-size: 10px; font-weight: 700; color: #1e3a5f; }
    .print-footer { text-align: center; padding-top: 10px; border-top: 1px solid #e5e7eb; margin-top: 10px; }
    .print-footer p { font-size: 8px; color: #9ca3af; margin: 0; font-style: italic; }
</style>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($total_borrows > 0 || $total_returns > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Circulation activity (borrows vs returns)
    const actCanvas = document.getElementById('activityChart');
    if (actCanvas) {
        new Chart(actCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode($trend_labels); ?>,
                datasets: [
                    {
                        label: 'Borrows',
                        data: <?php echo json_encode($trend_borrows); ?>,
                        borderColor: 'rgb(59, 130, 246)', backgroundColor: 'rgba(59,130,246,0.12)',
                        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 3
                    },
                    {
                        label: 'Returns',
                        data: <?php echo json_encode($trend_returns); ?>,
                        borderColor: 'rgb(34, 197, 94)', backgroundColor: 'rgba(34,197,94,0.12)',
                        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 3
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, font: { size: 11 } } } },
                scales: {
                    x: { ticks: { color: labelColor }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor, precision: 0 }, grid: { color: gridColor } }
                }
            }
        });
    }

    // Return punctuality (doughnut)
    const punctCanvas = document.getElementById('punctualityChart');
    if (punctCanvas) {
        new Chart(punctCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['On Time', 'Late'],
                datasets: [{
                    data: [<?php echo $ontime_count; ?>, <?php echo $late_count; ?>],
                    backgroundColor: ['rgba(16,185,129,0.85)','rgba(239,68,68,0.85)'],
                    borderColor: isDark ? '#1f2937' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, padding: 14, font: { size: 11 } } } }
            }
        });
    }
});
<?php endif; ?>
</script>
