<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle loan status update
if (isset($_POST['update_status']) && isset($_POST['loan_id']) && isset($_POST['status'])) {
    $loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($status === 'returned') {
        $query = "UPDATE book_loans SET status = :status, returned_date = NOW() WHERE id = :loan_id";
        // Update available copies
        $update_book_query = "UPDATE library_books SET copies_available = copies_available + 1 
                             WHERE id = (SELECT book_id FROM book_loans WHERE id = :loan_id)";
        $update_stmt = $db->prepare($update_book_query);
        $update_stmt->bindParam(':loan_id', $loan_id);
        $update_stmt->execute();
    } else {
        $query = "UPDATE book_loans SET status = :status WHERE id = :loan_id";
    }
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':loan_id', $loan_id);
    
    if ($stmt->execute()) {
        $success_message = "Loan status updated successfully!";
    } else {
        $error_message = "Error updating loan status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$overdue_filter = isset($_GET['overdue']) ? $_GET['overdue'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(lb.title LIKE :search OR u.name LIKE :search OR u.student_id LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "bl.status = :status";
    $params[':status'] = $status_filter;
}

if ($overdue_filter === 'yes') {
    $where_conditions[] = "bl.due_date < CURDATE() AND bl.status = 'borrowed'";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch loans with book and user information
$query = "SELECT bl.*, lb.title, lb.author, lb.isbn, u.name as borrower_name, u.student_id,
          DATEDIFF(CURDATE(), bl.due_date) as days_overdue
          FROM book_loans bl
          JOIN library_books lb ON bl.book_id = lb.id
          JOIN users u ON bl.user_id = u.id
          $where_clause
          ORDER BY bl.borrowed_date DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$loans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get loan statistics
$stats_query = "SELECT 
    COUNT(*) as total_loans,
    COUNT(CASE WHEN status = 'borrowed' THEN 1 END) as active_loans,
    COUNT(CASE WHEN status = 'returned' THEN 1 END) as returned_loans,
    COUNT(CASE WHEN due_date < CURDATE() AND status = 'borrowed' THEN 1 END) as overdue_loans
    FROM book_loans";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Borrowing Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Borrowing Management</h1>
                <div class="flex space-x-3">
                    <a href="../books/index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Library
                    </a>
                    <a href="new_loan.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>New Loan
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Loans</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_loans']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-book-reader text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Loans</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_loans']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-hand-holding text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Returned</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['returned_loans']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Overdue</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['overdue_loans']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by book title, borrower name, or student ID..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="borrowed" <?php echo $status_filter === 'borrowed' ? 'selected' : ''; ?>>Borrowed</option>
                                <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                                <option value="lost" <?php echo $status_filter === 'lost' ? 'selected' : ''; ?>>Lost</option>
                            </select>
                        </div>
                        <div class="w-48">
                            <select name="overdue" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Loans</option>
                                <option value="yes" <?php echo $overdue_filter === 'yes' ? 'selected' : ''; ?>>Overdue Only</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Loans Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Borrower</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dates</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($loans as $loan): ?>
                            <tr class="hover:bg-gray-50 <?php echo $loan['days_overdue'] > 0 && $loan['status'] === 'borrowed' ? 'bg-red-50' : ''; ?>">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($loan['title']); ?></div>
                                    <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($loan['author']); ?></div>
                                    <?php if ($loan['isbn']): ?>
                                    <div class="text-xs text-gray-400">ISBN: <?php echo htmlspecialchars($loan['isbn']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($loan['borrower_name']); ?></div>
                                    <?php if ($loan['student_id']): ?>
                                    <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($loan['student_id']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <div>Borrowed: <?php echo date('M j, Y', strtotime($loan['borrowed_date'])); ?></div>
                                        <div>Due: <?php echo date('M j, Y', strtotime($loan['due_date'])); ?></div>
                                        <?php if ($loan['returned_date']): ?>
                                        <div>Returned: <?php echo date('M j, Y', strtotime($loan['returned_date'])); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_classes = [
                                        'borrowed' => 'bg-blue-100 text-blue-800',
                                        'returned' => 'bg-green-100 text-green-800',
                                        'lost' => 'bg-red-100 text-red-800'
                                    ];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$loan['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($loan['status']); ?>
                                    </span>
                                    <?php if ($loan['days_overdue'] > 0 && $loan['status'] === 'borrowed'): ?>
                                    <div class="text-xs text-red-600 mt-1">
                                        <?php echo $loan['days_overdue']; ?> day(s) overdue
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if ($loan['status'] === 'borrowed'): ?>
                                    <form action="" method="POST" class="inline mr-2">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                        <input type="hidden" name="status" value="returned">
                                        <button type="submit" name="update_status" class="text-green-600 hover:text-green-900">Mark Returned</button>
                                    </form>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                        <input type="hidden" name="status" value="lost">
                                        <button type="submit" name="update_status" class="text-red-600 hover:text-red-900" 
                                                onclick="return confirm('Are you sure this book is lost?')">Mark Lost</button>
                                    </form>
                                    <?php else: ?>
                                    <span class="text-gray-400">No actions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($loans)): ?>
            <div class="text-center py-12">
                <i class="fas fa-book-reader text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No loans found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $overdue_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No books have been borrowed yet.
                    <?php endif; ?>
                </p>
                <a href="new_loan.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Loan
                </a>
            </div>
            <?php endif; ?>
                </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

