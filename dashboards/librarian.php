<?php
// Librarian Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
// An overdue loan is one explicitly marked 'overdue' OR still 'borrowed' past its due date.
$overdue_condition = "(bl.status = 'overdue' OR (bl.status = 'borrowed' AND bl.due_date < CURDATE()))";
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM library_books) AS total_books,
        (SELECT COUNT(*) FROM book_loans WHERE status = 'borrowed') AS books_borrowed,
        (SELECT COUNT(*) FROM book_loans bl WHERE $overdue_condition) AS overdue_books,
        (SELECT COUNT(*) FROM users WHERE role IN ('student', 'teacher')) AS total_members,
        (SELECT COUNT(*) FROM book_loans WHERE borrowed_date = CURDATE()) AS loans_today,
        (SELECT COUNT(*) FROM book_loans WHERE returned_date = CURDATE()) AS returns_today";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        throw new PDOException('No stats row returned');
    }
} catch (PDOException $e) {
    $stats = [
        'total_books' => 0,
        'books_borrowed' => 0,
        'overdue_books' => 0,
        'loans_today' => 0,
        'returns_today' => 0,
        'total_members' => 0
    ];
}

// Most-borrowed books (by total loan count)
$popular_books = [];
$recent_activities = [];
$overdue_items = [];
try {
    $popular_stmt = $db->query("SELECT lb.title, lb.author, COUNT(bl.id) AS loan_count
        FROM library_books lb
        JOIN book_loans bl ON bl.book_id = lb.id
        GROUP BY lb.id, lb.title, lb.author
        ORDER BY loan_count DESC, lb.title ASC
        LIMIT 5");
    $popular_books = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Currently overdue loans, most overdue first
    $overdue_stmt = $db->query("SELECT lb.title AS book_title, u.name AS borrower_name, u.role AS borrower_role,
        GREATEST(DATEDIFF(CURDATE(), bl.due_date), 0) AS days_overdue
        FROM book_loans bl
        JOIN library_books lb ON bl.book_id = lb.id
        JOIN users u ON bl.user_id = u.id
        WHERE $overdue_condition
        ORDER BY bl.due_date ASC
        LIMIT 5");
    $overdue_items = $overdue_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Most recent loan activity
    $recent_stmt = $db->query("SELECT lb.title AS book_title, u.name AS borrower_name, u.role AS borrower_role,
        bl.borrowed_date AS loan_date, bl.status
        FROM book_loans bl
        JOIN library_books lb ON bl.book_id = lb.id
        JOIN users u ON bl.user_id = u.id
        ORDER BY bl.created_at DESC, bl.id DESC
        LIMIT 5");
    $recent_activities = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Librarian dashboard data error: ' . $e->getMessage());
}
?>

<?php
$color_map = [
    'teal'    => ['bg' => 'bg-teal-100 dark:bg-teal-900/40', 'text' => 'text-teal-600 dark:text-teal-400', 'ring' => 'hover:border-teal-300 dark:hover:border-teal-700'],
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'indigo'  => ['bg' => 'bg-indigo-100 dark:bg-indigo-900/40', 'text' => 'text-indigo-600 dark:text-indigo-400', 'ring' => 'hover:border-indigo-300 dark:hover:border-indigo-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
];
?>
<!-- Librarian Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-book-reader mr-1.5"></i> Library Management</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Librarian'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Fostering knowledge and learning through books.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-hand-holding mr-2"></i><?php echo $stats['books_borrowed']; ?> books out</span>
                    <span class="flex items-center"><i class="fas fa-clock mr-2"></i>8:00 AM - 6:00 PM</span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-book-open text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Librarian Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="Library statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Books', 'value' => $stats['total_books'], 'icon' => 'fa-book', 'color' => 'teal', 'hint' => 'Available collection'],
        ['label' => 'Books Borrowed', 'value' => $stats['books_borrowed'], 'icon' => 'fa-hand-holding', 'color' => 'blue', 'hint' => 'Currently out'],
        ['label' => 'Overdue Books', 'value' => $stats['overdue_books'], 'icon' => 'fa-exclamation-triangle', 'color' => 'rose', 'hint' => 'Need attention'],
        ['label' => 'Library Members', 'value' => $stats['total_members'], 'icon' => 'fa-users', 'color' => 'emerald', 'hint' => 'Active members'],
    ];
    foreach ($summary_cards as $card):
        $c = $color_map[$card['color']];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-5 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 <?php echo $c['bg']; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?php echo $card['label']; ?></p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Library Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Popular Books -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Popular Books</h3>
            <a href="library/books/index.php" class="text-sm text-teal-600 dark:text-teal-400 hover:text-teal-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($popular_books)): ?>
                <?php foreach ($popular_books as $book): ?>
                <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="w-10 h-10 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center">
                        <i class="fas fa-book text-teal-600 dark:text-teal-400"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($book['title']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">by <?php echo htmlspecialchars($book['author']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo $book['loan_count']; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">loans</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-book text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No book data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overdue Items -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Overdue Items</h3>
            <a href="library/loans.php?status=overdue" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($overdue_items)): ?>
                <?php foreach ($overdue_items as $item): ?>
                <div class="flex items-center space-x-4 p-4 bg-red-50 dark:bg-red-900/20 rounded-lg">
                    <div class="w-10 h-10 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['book_title']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($item['borrower_name']); ?> (<?php echo htmlspecialchars(formatRoleName($item['borrower_role'])); ?>)
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-red-600 dark:text-red-400"><?php echo $item['days_overdue']; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">days overdue</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-check-circle text-4xl text-green-300 dark:text-green-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No overdue items</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Library Charts & Analytics -->
<?php
$pop_chart = ['labels' => [], 'counts' => []];
foreach ($popular_books as $b) {
    $pop_chart['labels'][] = $b['title'];
    $pop_chart['counts'][] = (int)$b['loan_count'];
}
$lib_on_loan   = max((int)$stats['books_borrowed'] - (int)$stats['overdue_books'], 0);
$lib_available = max((int)$stats['total_books'] - (int)$stats['books_borrowed'], 0);
$lib_overdue   = (int)$stats['overdue_books'];
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Most Borrowed Books -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Most Borrowed Books</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">By total loans</span>
        </div>
        <div class="h-64"><canvas id="libPopularChart"></canvas></div>
    </div>
    <!-- Collection Status -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Collection Status</h3>
        </div>
        <div class="h-64"><canvas id="libStatusChart"></canvas></div>
    </div>
</div>

<!-- Recent Library Activities -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Activities</h3>
        <a href="library/loans.php" class="text-sm text-teal-600 dark:text-teal-400 hover:text-teal-800">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Book</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Borrower</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Loan Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (!empty($recent_activities)): ?>
                    <?php foreach ($recent_activities as $activity): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['book_title']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['borrower_name']); ?></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars(formatRoleName($activity['borrower_role'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($activity['loan_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $status_color = 'gray';
                            if ($activity['status'] === 'borrowed') $status_color = 'blue';
                            elseif ($activity['status'] === 'returned') $status_color = 'green';
                            elseif ($activity['status'] === 'overdue') $status_color = 'red';
                            ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-<?php echo $status_color; ?>-100 text-<?php echo $status_color; ?>-800">
                                <?php echo ucfirst($activity['status']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No recent activities</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Librarian Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Library Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick actions</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="library/books/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-plus text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Book</span>
        </a>
        <a href="library/borrowing/new_loan.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-hand-holding text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Issue Book</span>
        </a>
        <a href="library/loans.php?status=borrowed" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-undo text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Return Book</span>
        </a>
        <a href="library/books/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-search text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Search Books</span>
        </a>
        <a href="library/loans.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-users text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Manage Members</span>
        </a>
        <a href="library/reports.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-chart-bar text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Reports</span>
        </a>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const LIB_POPULAR = <?php echo json_encode($pop_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const LIB_STATUS = <?php echo json_encode([
    'labels' => ['Available', 'On Loan', 'Overdue'],
    'data'   => [$lib_available, $lib_on_loan, $lib_overdue]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let popChart = null, statusChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const pc = document.getElementById('libPopularChart');
        if (pc) {
            const labels = LIB_POPULAR.labels.length ? LIB_POPULAR.labels : ['No data'];
            const counts = LIB_POPULAR.counts.length ? LIB_POPULAR.counts : [0];
            if (popChart) popChart.destroy();
            popChart = new Chart(pc, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Loans', data: counts,
                        backgroundColor: 'rgba(20,184,166,0.7)', hoverBackgroundColor: 'rgba(20,184,166,0.95)',
                        borderRadius: 8, maxBarThickness: 30 }] },
                options: {
                    indexAxis: 'y',
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } },
                        y: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } }
                    }
                }
            });
        }

        const sc = document.getElementById('libStatusChart');
        if (sc) {
            const hasData = LIB_STATUS.data.some(v => v > 0);
            if (statusChart) statusChart.destroy();
            statusChart = new Chart(sc, {
                type: 'doughnut',
                data: { labels: LIB_STATUS.labels, datasets: [{ data: hasData ? LIB_STATUS.data : [1, 0, 0],
                        backgroundColor: ['#10b981', '#3b82f6', '#ef4444'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 10, color: tick, font: { size: 11 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());
</script>
