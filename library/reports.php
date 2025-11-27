<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$report_type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'overview';

// Get library statistics
$stats = [];

// Total books
$books_query = "SELECT COUNT(*) as total_books, SUM(copies_available) as total_copies FROM library_books";
$books_stmt = $db->query($books_query);
$books_data = $books_stmt->fetch(PDO::FETCH_ASSOC);
$stats['total_books'] = $books_data['total_books'];
$stats['total_copies'] = $books_data['total_copies'];

// Active loans
$loans_query = "SELECT COUNT(*) as active_loans FROM book_loans WHERE status = 'borrowed'";
$loans_stmt = $db->query($loans_query);
$loans_data = $loans_stmt->fetch(PDO::FETCH_ASSOC);
$stats['active_loans'] = $loans_data['active_loans'];

// Overdue books
$overdue_query = "SELECT COUNT(*) as overdue_books FROM book_loans WHERE status = 'borrowed' AND due_date < CURDATE()";
$overdue_stmt = $db->query($overdue_query);
$overdue_data = $overdue_stmt->fetch(PDO::FETCH_ASSOC);
$stats['overdue_books'] = $overdue_data['overdue_books'];

// Books by category
$category_query = "SELECT category, COUNT(*) as count FROM library_books GROUP BY category ORDER BY count DESC";
$category_stmt = $db->query($category_query);
$categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);

// Most borrowed books
$popular_query = "SELECT lb.title, lb.author, COUNT(bl.id) as loan_count 
                 FROM library_books lb 
                 LEFT JOIN book_loans bl ON lb.id = bl.book_id 
                 GROUP BY lb.id 
                 ORDER BY loan_count DESC 
                 LIMIT 10";
$popular_stmt = $db->query($popular_query);
$popular_books = $popular_stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent loans
$recent_query = "SELECT bl.*, lb.title, lb.author, u.name as borrower_name 
                FROM book_loans bl 
                JOIN library_books lb ON bl.book_id = lb.id 
                JOIN users u ON bl.user_id = u.id 
                ORDER BY bl.borrowed_date DESC 
                LIMIT 10";
$recent_stmt = $db->query($recent_query);
$recent_loans = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Overdue loans details
$overdue_details_query = "SELECT bl.*, lb.title, lb.author, u.name as borrower_name,
                         DATEDIFF(CURDATE(), bl.due_date) as days_overdue
                         FROM book_loans bl 
                         JOIN library_books lb ON bl.book_id = lb.id 
                         JOIN users u ON bl.user_id = u.id 
                         WHERE bl.status = 'borrowed' AND bl.due_date < CURDATE()
                         ORDER BY days_overdue DESC";
$overdue_details_stmt = $db->query($overdue_details_query);
$overdue_details = $overdue_details_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Library Reports</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Library
                </a>
            </div>

            <!-- Report Type Selection -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Report Type</h3>
                    <div class="flex space-x-4">
                        <a href="?type=overview" 
                           class="px-4 py-2 rounded-lg <?php echo $report_type === 'overview' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Overview
                        </a>
                        <a href="?type=loans" 
                           class="px-4 py-2 rounded-lg <?php echo $report_type === 'loans' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Loans & Returns
                        </a>
                        <a href="?type=popular" 
                           class="px-4 py-2 rounded-lg <?php echo $report_type === 'popular' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Popular Books
                        </a>
                        <a href="?type=overdue" 
                           class="px-4 py-2 rounded-lg <?php echo $report_type === 'overdue' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Overdue Books
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($report_type === 'overview'): ?>
            <!-- Overview Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100">
                            <i class="fas fa-book text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Books</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['total_books']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100">
                            <i class="fas fa-copy text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Copies</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['total_copies']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100">
                            <i class="fas fa-book-open text-yellow-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Active Loans</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['active_loans']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Overdue Books</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $stats['overdue_books']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Books by Category -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Books by Category</h3>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($categories)): ?>
                        <div class="space-y-4">
                            <?php foreach ($categories as $category): ?>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-700"><?php echo htmlspecialchars($category['category'] ?: 'Uncategorized'); ?></span>
                                <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-sm font-medium">
                                    <?php echo $category['count']; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-center">No books found.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Recent Loans</h3>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_loans)): ?>
                        <div class="space-y-4">
                            <?php foreach (array_slice($recent_loans, 0, 5) as $loan): ?>
                            <div class="flex items-start space-x-3">
                                <div class="w-2 h-2 bg-blue-500 rounded-full mt-2"></div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($loan['title']); ?></p>
                                    <p class="text-xs text-gray-500">
                                        Borrowed by <?php echo htmlspecialchars($loan['borrower_name']); ?> 
                                        on <?php echo date('M j', strtotime($loan['borrowed_date'])); ?>
                                    </p>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-center">No recent loans.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php elseif ($report_type === 'popular'): ?>
            <!-- Popular Books -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Most Popular Books</h3>
                    <p class="text-gray-600 text-sm">Books ranked by number of times borrowed</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Author</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Times Borrowed</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($popular_books as $index => $book): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <?php if ($index < 3): ?>
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-sm font-bold
                                            <?php echo $index === 0 ? 'bg-yellow-500' : ($index === 1 ? 'bg-gray-400' : 'bg-orange-500'); ?>">
                                            <?php echo $index + 1; ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center text-gray-600 text-sm font-bold">
                                            <?php echo $index + 1; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($book['title']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($book['author']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo $book['loan_count']; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($report_type === 'overdue'): ?>
            <!-- Overdue Books -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Overdue Books (<?php echo count($overdue_details); ?>)</h3>
                    <p class="text-gray-600 text-sm">Books that are past their due date</p>
                </div>
                <?php if (!empty($overdue_details)): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Borrower</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Days Overdue</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($overdue_details as $overdue): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($overdue['title']); ?></div>
                                    <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($overdue['author']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($overdue['borrower_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M j, Y', strtotime($overdue['due_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $overdue['days_overdue'] > 7 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                        <?php echo $overdue['days_overdue']; ?> days
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="p-6 text-center">
                    <i class="fas fa-check-circle text-green-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Overdue Books</h3>
                    <p class="text-gray-500">All borrowed books are within their due dates.</p>
                </div>
                <?php endif; ?>
            </div>

            <?php elseif ($report_type === 'loans'): ?>
            <!-- Loans Report -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Recent Loan Activity</h3>
                    <p class="text-gray-600 text-sm">Latest book borrowing and return activity</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Borrower</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Borrowed Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_loans as $loan): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($loan['title']); ?></div>
                                    <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($loan['author']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($loan['borrower_name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M j, Y', strtotime($loan['borrowed_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M j, Y', strtotime($loan['due_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_class = '';
                                    $status_text = '';
                                    if ($loan['status'] === 'returned') {
                                        $status_class = 'bg-green-100 text-green-800';
                                        $status_text = 'Returned';
                                    } elseif (strtotime($loan['due_date']) < time()) {
                                        $status_class = 'bg-red-100 text-red-800';
                                        $status_text = 'Overdue';
                                    } else {
                                        $status_class = 'bg-yellow-100 text-yellow-800';
                                        $status_text = 'Borrowed';
                                    }
                                    ?>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
