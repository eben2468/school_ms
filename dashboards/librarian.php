<?php
// Librarian Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role IN ('student', 'teacher')) as total_members";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = ['total_members' => 0];
    }
    // Add placeholder values for library features
    $stats['total_books'] = 0;
    $stats['books_borrowed'] = 0;
    $stats['overdue_books'] = 0;
    $stats['loans_today'] = 0;
    $stats['returns_today'] = 0;
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

// Placeholder data for library features (tables may not exist)
$popular_books = [];
$recent_activities = [];
$overdue_items = [];
?>

<!-- Librarian Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Library Management</h1>
                <p class="text-blue-100 text-lg">Fostering knowledge and learning through books</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-book-reader mr-2"></i>
                        Librarian
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>
                <!-- Library Status -->
                <div class="mt-4 p-4 bg-white/10 rounded-lg backdrop-blur-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-blue-100 mb-1">Library Status</p>
                            <p class="text-lg font-semibold text-white">
                                <?php echo $stats['books_borrowed']; ?> books currently borrowed
                            </p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-blue-100">Operating Hours</p>
                            <p class="text-white font-medium">8:00 AM - 6:00 PM</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-book-open text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Librarian Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Books -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Books</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_books']; ?></p>
                <p class="text-sm text-teal-600 dark:text-teal-400 mt-1">
                    <i class="fas fa-book mr-1"></i>
                    Available collection
                </p>
            </div>
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-book text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Books Borrowed -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Books Borrowed</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['books_borrowed']; ?></p>
                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    <i class="fas fa-hand-holding mr-1"></i>
                    Currently out
                </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-hand-holding text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Overdue Books -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Overdue Books</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['overdue_books']; ?></p>
                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Need attention
                </p>
            </div>
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Library Members -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Library Members</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_members']; ?></p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-users mr-1"></i>
                    Active members
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-users text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Library Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- Popular Books -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Popular Books</h3>
            <a href="library/books.php" class="text-sm text-teal-600 dark:text-teal-400 hover:text-teal-800">View all</a>
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
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Overdue Items</h3>
            <a href="library/overdue.php" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800">View all</a>
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
                            <?php echo htmlspecialchars($item['borrower_name']); ?> (<?php echo ucfirst($item['borrower_role']); ?>)
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

<!-- Recent Library Activities -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Activities</h3>
        <a href="library/activities.php" class="text-sm text-teal-600 dark:text-teal-400 hover:text-teal-800">View all</a>
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
                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo ucfirst($activity['borrower_role']); ?></div>
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
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Library Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick actions</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="library/books/add.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-plus text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Book</span>
        </a>
        <a href="library/loans/issue.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-hand-holding text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Issue Book</span>
        </a>
        <a href="library/loans/return.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-undo text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Return Book</span>
        </a>
        <a href="library/search.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-search text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Search Books</span>
        </a>
        <a href="library/members.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
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
