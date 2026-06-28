<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'librarian'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
require_once '../finance/includes/finance_functions.php';
$database = new Database();
$db = $database->getConnection();

// School settings for the printable report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();

// Configurable daily fine rate (used to estimate accrued fines)
$fine_per_day = (float) getSchoolSetting('library_fine_per_day', '0.50');

$today = date('Y-m-d');

// Filter sources
$categories = $db->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$roles = $db->query("SELECT DISTINCT u.role FROM book_loans bl JOIN users u ON bl.user_id = u.id WHERE u.role IS NOT NULL ORDER BY u.role")->fetchAll(PDO::FETCH_COLUMN);

// ---- Filters ----
$selected_category = isset($_GET['category']) && $_GET['category'] !== '' ? $_GET['category'] : '';
$selected_role     = isset($_GET['role']) && $_GET['role'] !== '' ? $_GET['role'] : '';

$where = " WHERE bl.returned_date IS NULL AND bl.due_date < :today ";
$params = [':today' => $today];
if ($selected_category !== '') { $where .= " AND b.category = :category "; $params[':category'] = $selected_category; }
if ($selected_role !== '')     { $where .= " AND u.role = :role "; $params[':role'] = $selected_role; }

$base = "
    FROM book_loans bl
    JOIN library_books b ON bl.book_id = b.id
    JOIN users u ON bl.user_id = u.id
    LEFT JOIN (
        SELECT loan_id, SUM(CASE WHEN status <> 'waived' THEN fine_amount ELSE 0 END) AS recorded_fine
        FROM library_fines GROUP BY loan_id
    ) f ON f.loan_id = bl.id
";

// ---- Overdue loans ----
$ov_stmt = $db->prepare("
    SELECT bl.id, b.title, b.author, COALESCE(b.category, 'Uncategorized') AS category,
           u.name AS borrower_name, u.role AS borrower_role, u.email AS borrower_email,
           bl.borrowed_date, bl.due_date,
           DATEDIFF(:today2, bl.due_date) AS days_overdue,
           COALESCE(f.recorded_fine, 0) AS recorded_fine
    $base
    $where
    ORDER BY days_overdue DESC, b.title ASC
    LIMIT 500
");
$ov_stmt->execute(array_merge($params, [':today2' => $today]));
$overdue = $ov_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Derived metrics + aging buckets ----
$total_overdue = count($overdue);
$borrowers = [];
$total_days = 0;
$max_days = 0;
$est_fines_total = 0.0;
$recorded_fines_total = 0.0;
$aging = ['d7' => 0, 'd30' => 0, 'd90' => 0, 'd90plus' => 0]; // counts
$category_counts = [];

foreach ($overdue as &$row) {
    $d = (int)$row['days_overdue'];
    $row['est_fine'] = round($d * $fine_per_day, 2);
    $est_fines_total += $row['est_fine'];
    $recorded_fines_total += (float)$row['recorded_fine'];
    $total_days += $d;
    if ($d > $max_days) { $max_days = $d; }
    $borrowers[$row['borrower_name']] = true;

    if ($d <= 7) { $aging['d7']++; }
    elseif ($d <= 30) { $aging['d30']++; }
    elseif ($d <= 90) { $aging['d90']++; }
    else { $aging['d90plus']++; }

    $cat = $row['category'];
    $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;
}
unset($row);

$borrowers_affected = count($borrowers);
$avg_days = $total_overdue > 0 ? round($total_days / $total_overdue, 1) : 0;
$fines_outstanding = $recorded_fines_total > 0 ? $recorded_fines_total : $est_fines_total;

arsort($category_counts);

// Aging badge helper
function overdue_badge($days)
{
    if ($days <= 7) return ['text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'];
    if ($days <= 30) return ['text-orange-800 bg-orange-100 dark:bg-orange-900/40 dark:text-orange-300'];
    return ['text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300'];
}

$selected_category_name = $selected_category !== '' ? $selected_category : 'All Categories';
$selected_role_name = $selected_role !== '' ? formatRoleName($selected_role) : 'All Members';

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overdue_books_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Overdue Books Report']);
    fputcsv($out, ['Category', $selected_category_name]);
    fputcsv($out, ['Member Type', $selected_role_name]);
    fputcsv($out, ['As Of', $today]);
    fputcsv($out, ['Fine Rate/Day', number_format($fine_per_day, 2)]);
    fputcsv($out, ['Total Overdue', $total_overdue]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Title', 'Author', 'Category', 'Borrower', 'Role', 'Email', 'Borrowed', 'Due', 'Days Overdue', 'Est. Fine', 'Recorded Fine']);
    foreach ($overdue as $row) {
        fputcsv($out, [
            $row['title'], $row['author'], $row['category'], $row['borrower_name'], $row['borrower_role'],
            $row['borrower_email'], $row['borrowed_date'], $row['due_date'], $row['days_overdue'],
            number_format($row['est_fine'], 2), number_format((float)$row['recorded_fine'], 2),
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'category' => $selected_category, 'role' => $selected_role, 'export' => 'csv',
]);

$title = "Overdue Books Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Overdue Books Report']
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Overdue Books Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Identify books past their due date, aging, and accrued fines for follow-up.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($overdue) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($overdue) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Member Type</label>
                            <select name="role" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Members</option>
                                <?php foreach ($roles as $r): ?>
                                <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $selected_role === $r ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $r))); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_category_name); ?></span> &bull;
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_role_name); ?></span> &bull;
                        As of <?php echo htmlspecialchars($today); ?> &bull; Fine rate <?php echo formatFinanceCurrency($fine_per_day, $db); ?>/day
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Overdue Books</p>
                            <h3 class="text-3xl font-bold text-rose-600 dark:text-rose-400 mt-1"><?php echo number_format($total_overdue); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-rose-100 dark:bg-rose-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-times text-rose-600 dark:text-rose-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Members Affected</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($borrowers_affected); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-clock text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Days Overdue</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $avg_days; ?></h3>
                            <p class="text-xs text-gray-400 mt-1">Max <?php echo $max_days; ?> days</p>
                        </div>
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hourglass-end text-amber-600 dark:text-amber-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Fines Outstanding</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo formatFinanceCurrency($fines_outstanding, $db); ?></h3>
                            <p class="text-xs text-gray-400 mt-1"><?php echo $recorded_fines_total > 0 ? 'Recorded' : 'Estimated'; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-money-bill-wave text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($total_overdue > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Overdue Aging</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Books grouped by how long they are overdue</p>
                        <div class="relative flex items-center justify-center" style="height: 260px;">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Overdue by Category</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Number of overdue books per category</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Overdue Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Overdue Loans</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo $total_overdue; ?> book(s) past due &bull; <?php echo htmlspecialchars($selected_category_name); ?>
                            &bull; <?php echo htmlspecialchars($selected_role_name); ?>
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
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Days Overdue</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Est. Fine</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($overdue as $row):
                                    list($badge) = overdue_badge((int)$row['days_overdue']);
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($row['title']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($row['category']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($row['borrower_name']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($row['borrower_email'] ?? ''); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo date('M j, Y', strtotime($row['borrowed_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo date('M j, Y', strtotime($row['due_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $badge; ?>"><?php echo (int)$row['days_overdue']; ?> days</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-rose-600 dark:text-rose-400"><?php echo formatFinanceCurrency($row['est_fine'], $db); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <td colspan="5" class="px-6 py-3 text-right text-sm font-bold text-gray-700 dark:text-gray-300">Total Estimated Fines</td>
                                    <td class="px-6 py-3 text-right text-sm font-extrabold text-rose-600 dark:text-rose-400"><?php echo formatFinanceCurrency($est_fines_total, $db); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-green-50 dark:bg-green-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Overdue Books</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">Every borrowed book is within its due date for the selected filters. Great work keeping the library current!</p>
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
<?php if (!empty($overdue)): ?>
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

        <div class="print-title-banner"><h2>Overdue Books Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Category:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_category_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">As Of:</span><span class="print-meta-value"><?php echo date('M j, Y'); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Overdue Books:</span><span class="print-meta-value"><?php echo $total_overdue; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Est. Fines:</span><span class="print-meta-value"><?php echo formatFinanceCurrency($est_fines_total, $db); ?></span></div>
        </div>

        <div class="print-section-title">Overdue Loans</div>
        <table class="print-table">
            <thead>
                <tr><th style="text-align:left">Book</th><th style="text-align:left">Borrower</th><th>Borrowed</th><th>Due</th><th>Days Overdue</th><th>Est. Fine</th></tr>
            </thead>
            <tbody>
                <?php foreach ($overdue as $row): ?>
                <tr>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($row['title']); ?></td>
                    <td style="text-align:left"><?php echo htmlspecialchars($row['borrower_name']); ?></td>
                    <td><?php echo date('M j, Y', strtotime($row['borrowed_date'])); ?></td>
                    <td><?php echo date('M j, Y', strtotime($row['due_date'])); ?></td>
                    <td class="pct-cell"><?php echo (int)$row['days_overdue']; ?></td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency($row['est_fine'], $db); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;font-weight:700">Total Estimated Fines</td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency($est_fines_total, $db); ?></td>
                </tr>
            </tfoot>
        </table>

        <div class="print-note">Note: estimated fines are calculated at <?php echo formatFinanceCurrency($fine_per_day, $db); ?> per day overdue and may differ from officially recorded fines.</div>

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
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#9f1239,#e11d48); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #9f1239; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #9f1239; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #fff1f2; border-left: 4px solid #e11d48; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#9f1239,#e11d48); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #881337; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #9f1239; }
    .print-note { font-size: 8.5px; color: #6b7280; font-style: italic; margin-bottom: 14px; }
    .print-signatures { display: grid; grid-template-columns: repeat(3,1fr); gap: 30px; margin-top: 24px; margin-bottom: 16px; }
    .print-signature-block { text-align: center; }
    .print-signature-block .signature-line { border-top: 1.5px solid #374151; margin-top: 36px; padding-top: 4px; }
    .signature-title { font-size: 10px; font-weight: 700; color: #1e3a5f; }
    .print-footer { text-align: center; padding-top: 10px; border-top: 1px solid #e5e7eb; margin-top: 10px; }
    .print-footer p { font-size: 8px; color: #9ca3af; margin: 0; font-style: italic; }
</style>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($total_overdue > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Aging (doughnut)
    const agingCanvas = document.getElementById('agingChart');
    if (agingCanvas) {
        new Chart(agingCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['1-7 days', '8-30 days', '31-90 days', '90+ days'],
                datasets: [{
                    data: [<?php echo $aging['d7']; ?>, <?php echo $aging['d30']; ?>, <?php echo $aging['d90']; ?>, <?php echo $aging['d90plus']; ?>],
                    backgroundColor: ['rgba(245,158,11,0.85)','rgba(249,115,22,0.85)','rgba(239,68,68,0.85)','rgba(159,18,57,0.9)'],
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

    // By category (bar)
    const catCanvas = document.getElementById('categoryChart');
    if (catCanvas) {
        new Chart(catCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_keys($category_counts)); ?>,
                datasets: [{
                    label: 'Overdue',
                    data: <?php echo json_encode(array_values($category_counts)); ?>,
                    backgroundColor: 'rgba(225, 29, 72, 0.85)',
                    borderColor: 'rgb(225, 29, 72)', borderWidth: 1, borderRadius: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: labelColor, font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor, precision: 0 }, grid: { color: gridColor } }
                }
            }
        });
    }
});
<?php endif; ?>
</script>
