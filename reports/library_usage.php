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

// ---- Catalog inventory (global) ----
$catalog = $db->query("SELECT COUNT(*) AS titles, COALESCE(SUM(total_copies),0) AS total_copies,
    COALESCE(SUM(copies_available),0) AS available FROM library_books")->fetch(PDO::FETCH_ASSOC);
$catalog_titles = (int)$catalog['titles'];
$catalog_copies = (int)$catalog['total_copies'];
$catalog_available = (int)$catalog['available'];
$catalog_on_loan = max(0, $catalog_copies - $catalog_available);

$categories = $db->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

// ---- Filters ----
$selected_category = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : '';
$selected_status   = isset($_GET['status']) && in_array($_GET['status'], ['borrowed', 'returned', 'overdue']) ? $_GET['status'] : '';

// Loan date range for sensible defaults
$range = $db->query("SELECT MIN(borrowed_date) AS mn, MAX(borrowed_date) AS mx FROM book_loans")->fetch(PDO::FETCH_ASSOC);
$data_min = $range['mn'] ?? date('Y-m-d', strtotime('-90 days'));
$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $data_min;
$to_date   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $today;

// Validate dates
$d1 = DateTime::createFromFormat('Y-m-d', $from_date);
$d2 = DateTime::createFromFormat('Y-m-d', $to_date);
if (!$d1 || $d1->format('Y-m-d') !== $from_date) { $from_date = $data_min; }
if (!$d2 || $d2->format('Y-m-d') !== $to_date) { $to_date = $today; }
if ($from_date > $to_date) { $tmp = $from_date; $from_date = $to_date; $to_date = $tmp; }

// Shared FROM/WHERE
$from_join = "
    FROM book_loans bl
    JOIN library_books b ON bl.book_id = b.id
    JOIN users u ON bl.user_id = u.id
";
$where = " WHERE bl.borrowed_date BETWEEN :from_date AND :to_date ";
$params = [':from_date' => $from_date, ':to_date' => $to_date];
if ($selected_category !== '') { $where .= " AND b.category = :category "; $params[':category'] = $selected_category; }
if ($selected_status === 'returned') {
    $where .= " AND bl.returned_date IS NOT NULL ";
} elseif ($selected_status === 'overdue') {
    $where .= " AND bl.returned_date IS NULL AND bl.due_date < :today_o ";
    $params[':today_o'] = $today;
} elseif ($selected_status === 'borrowed') {
    $where .= " AND bl.returned_date IS NULL ";
}

// ---- Summary (within filtered set) ----
$sum_stmt = $db->prepare("
    SELECT COUNT(*) AS total_loans,
           COUNT(DISTINCT bl.user_id) AS borrowers,
           SUM(CASE WHEN bl.returned_date IS NULL THEN 1 ELSE 0 END) AS currently_borrowed,
           SUM(CASE WHEN bl.returned_date IS NULL AND bl.due_date < :today_s THEN 1 ELSE 0 END) AS overdue_loans
    $from_join
    $where
");
$sum_stmt->execute(array_merge($params, [':today_s' => $today]));
$summary = $sum_stmt->fetch(PDO::FETCH_ASSOC);
$total_loans = (int)$summary['total_loans'];
$borrowers = (int)$summary['borrowers'];
$currently_borrowed = (int)$summary['currently_borrowed'];
$overdue_loans = (int)$summary['overdue_loans'];

// ---- Loans trend (by month) ----
$trend_stmt = $db->prepare("
    SELECT DATE_FORMAT(bl.borrowed_date, '%Y-%m') AS ym, COUNT(*) AS cnt
    $from_join
    $where
    GROUP BY ym ORDER BY ym ASC
");
$trend_stmt->execute($params);
$trend_data = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Loans by category ----
$cat_stmt = $db->prepare("
    SELECT COALESCE(b.category, 'Uncategorized') AS category, COUNT(*) AS cnt
    $from_join
    $where
    GROUP BY b.category ORDER BY cnt DESC
");
$cat_stmt->execute($params);
$category_data = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Most borrowed books ----
$top_stmt = $db->prepare("
    SELECT b.title, b.author, COALESCE(b.category, 'Uncategorized') AS category,
           b.total_copies, b.copies_available, COUNT(bl.id) AS loan_count
    $from_join
    $where
    GROUP BY b.id, b.title, b.author, b.category, b.total_copies, b.copies_available
    ORDER BY loan_count DESC, b.title ASC
    LIMIT 10
");
$top_stmt->execute($params);
$top_books = $top_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Loan transactions ----
$txn_stmt = $db->prepare("
    SELECT b.title, b.author, COALESCE(b.category, 'Uncategorized') AS category,
           u.name AS borrower_name, u.role AS borrower_role,
           bl.borrowed_date, bl.due_date, bl.returned_date, bl.status
    $from_join
    $where
    ORDER BY bl.borrowed_date DESC, bl.id DESC
    LIMIT 500
");
$txn_stmt->execute($params);
$transactions = $txn_stmt->fetchAll(PDO::FETCH_ASSOC);

// Display status helper (returned / overdue / borrowed)
function loan_display_status($row, $today)
{
    if (!empty($row['returned_date'])) {
        return ['Returned', 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300'];
    }
    if ($row['due_date'] < $today) {
        return ['Overdue', 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300'];
    }
    return ['Borrowed', 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'];
}

$selected_category_name = $selected_category !== '' ? $selected_category : 'All Categories';
$selected_status_name = $selected_status !== '' ? ucfirst($selected_status) : 'All Statuses';

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="library_usage_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Library Usage Report']);
    fputcsv($out, ['Category', $selected_category_name]);
    fputcsv($out, ['Status', $selected_status_name]);
    fputcsv($out, ['Period', $from_date . ' to ' . $to_date]);
    fputcsv($out, ['Total Loans', $total_loans]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Book Title', 'Author', 'Category', 'Borrower', 'Role', 'Borrowed', 'Due', 'Returned', 'Status']);
    foreach ($transactions as $t) {
        list($st) = loan_display_status($t, $today);
        fputcsv($out, [
            $t['title'], $t['author'], $t['category'], $t['borrower_name'], $t['borrower_role'],
            $t['borrowed_date'], $t['due_date'], $t['returned_date'] ?? '', $st,
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'category' => $selected_category, 'status' => $selected_status,
    'from_date' => $from_date, 'to_date' => $to_date, 'export' => 'csv',
]);

$title = "Library Usage Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Library Usage Report']
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Library Usage Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track book circulation, popular titles, and catalog utilization.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($transactions) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($transactions) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Catalog Inventory Strip -->
                <div class="bg-gradient-to-r from-indigo-50 to-blue-50 dark:from-gray-800 dark:to-gray-800 rounded-xl border border-gray-150 dark:border-gray-700 p-4 mb-6 grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="text-center">
                        <p class="text-2xl font-bold text-white"><?php echo number_format($catalog_titles); ?></p>
                        <p class="text-xs font-semibold text-blue-100 uppercase tracking-wide">Catalog Titles</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-white"><?php echo number_format($catalog_copies); ?></p>
                        <p class="text-xs font-semibold text-blue-100 uppercase tracking-wide">Total Copies</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-green-300"><?php echo number_format($catalog_available); ?></p>
                        <p class="text-xs font-semibold text-blue-100 uppercase tracking-wide">Available</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-white"><?php echo number_format($catalog_on_loan); ?></p>
                        <p class="text-xs font-semibold text-blue-100 uppercase tracking-wide">On Loan</p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Statuses</option>
                                <option value="borrowed" <?php echo $selected_status === 'borrowed' ? 'selected' : ''; ?>>Currently Borrowed</option>
                                <option value="overdue" <?php echo $selected_status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="returned" <?php echo $selected_status === 'returned' ? 'selected' : ''; ?>>Returned</option>
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
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_status_name); ?></span> &bull;
                        <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Loans</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($total_loans); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book-reader text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Currently Borrowed</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($currently_borrowed); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Overdue Loans</p>
                            <h3 class="text-3xl font-bold text-rose-600 dark:text-rose-400 mt-1"><?php echo number_format($overdue_loans); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-rose-100 dark:bg-rose-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-circle text-rose-600 dark:text-rose-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Unique Borrowers</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($borrowers); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($total_loans > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Loans Over Time</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Books borrowed per month</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Loans by Category</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Circulation share by book category</p>
                        <div class="relative flex items-center justify-center" style="height: 260px;">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Most Borrowed Books -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Most Borrowed Books</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">Top titles by loan count in the selected period</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Author</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Category</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Times Borrowed</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Availability</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php $rank = 1; foreach ($top_books as $bk): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">#<?php echo $rank; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($bk['title']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($bk['author']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($bk['category']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-bold text-indigo-600 dark:text-indigo-400"><?php echo (int)$bk['loan_count']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$bk['copies_available']; ?> / <?php echo (int)$bk['total_copies']; ?></td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Loan Transactions -->
                <?php if (!empty($transactions)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Loan Transactions</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo count($transactions); ?> loan(s) &bull; <?php echo htmlspecialchars($selected_category_name); ?>
                            &bull; <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Book</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Borrower</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Borrowed</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Returned</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($transactions as $t): list($st_label, $st_class) = loan_display_status($t, $today); ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($t['title']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($t['author']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($t['borrower_name']); ?>
                                        <div class="text-xs text-gray-400 capitalize"><?php echo htmlspecialchars(str_replace('_', ' ', $t['borrower_role'] ?? '')); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo date('M j, Y', strtotime($t['borrowed_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo date('M j, Y', strtotime($t['due_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $t['returned_date'] ? date('M j, Y', strtotime($t['returned_date'])) : '—'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $st_class; ?>"><?php echo $st_label; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-book-open text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Loan Activity</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No book loans match the selected filters. Try widening the date range or clearing the category/status filters.</p>
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
<?php if (!empty($transactions)): ?>
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

        <div class="print-title-banner"><h2>Library Usage Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Category:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_category_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Period:</span><span class="print-meta-value"><?php echo htmlspecialchars($from_date . ' – ' . $to_date); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Total Loans:</span><span class="print-meta-value"><?php echo $total_loans; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Overdue:</span><span class="print-meta-value"><?php echo $overdue_loans; ?></span></div>
        </div>

        <?php if (!empty($top_books)): ?>
        <div class="print-section-title">Most Borrowed Books</div>
        <table class="print-table">
            <thead><tr><th style="width:40px">#</th><th style="text-align:left">Title</th><th style="text-align:left">Author</th><th>Category</th><th>Times Borrowed</th></tr></thead>
            <tbody>
                <?php $pr = 1; foreach ($top_books as $bk): ?>
                <tr>
                    <td><?php echo $pr; ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($bk['title']); ?></td>
                    <td style="text-align:left"><?php echo htmlspecialchars($bk['author']); ?></td>
                    <td><?php echo htmlspecialchars($bk['category']); ?></td>
                    <td class="pct-cell"><?php echo (int)$bk['loan_count']; ?></td>
                </tr>
                <?php $pr++; endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <div class="print-section-title">Loan Transactions</div>
        <table class="print-table">
            <thead>
                <tr><th style="text-align:left">Book</th><th style="text-align:left">Borrower</th><th>Borrowed</th><th>Due</th><th>Returned</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): list($st_label) = loan_display_status($t, $today); ?>
                <tr>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($t['title']); ?></td>
                    <td style="text-align:left"><?php echo htmlspecialchars($t['borrower_name']); ?></td>
                    <td><?php echo date('M j, Y', strtotime($t['borrowed_date'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime($t['due_date'])); ?></td>
                    <td><?php echo $t['returned_date'] ? date('M j, Y', strtotime($t['returned_date'])) : '-'; ?></td>
                    <td><?php echo $st_label; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

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
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#3730a3,#4f46e5); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #3730a3; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #3730a3; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #eef2ff; border-left: 4px solid #4f46e5; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#3730a3,#4f46e5); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #312e81; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #3730a3; }
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
<?php if ($total_loans > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Loans over time (line)
    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => date('M Y', strtotime($r['ym'] . '-01')), $trend_data)); ?>,
                datasets: [{
                    label: 'Loans',
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['cnt'], $trend_data)); ?>,
                    borderColor: 'rgb(79, 70, 229)',
                    backgroundColor: 'rgba(79, 70, 229, 0.12)',
                    borderWidth: 2, tension: 0.35, fill: true, pointRadius: 4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: labelColor }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor, precision: 0 }, grid: { color: gridColor } }
                }
            }
        });
    }

    // Loans by category (doughnut)
    const catCanvas = document.getElementById('categoryChart');
    if (catCanvas) {
        new Chart(catCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['category'], $category_data)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['cnt'], $category_data)); ?>,
                    backgroundColor: ['rgba(79,70,229,0.85)','rgba(59,130,246,0.85)','rgba(34,197,94,0.85)','rgba(245,158,11,0.85)','rgba(239,68,68,0.85)','rgba(168,85,247,0.85)'],
                    borderColor: isDark ? '#1f2937' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, padding: 14, font: { size: 11 } } } }
            }
        });
    }
});
<?php endif; ?>
</script>
