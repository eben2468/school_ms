<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'librarian'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Handle return book action
if (isset($_POST['return_book']) && isset($_POST['loan_id'])) {
    $loan_id = filter_input(INPUT_POST, 'loan_id', FILTER_SANITIZE_NUMBER_INT);

    // Check if user has permission to return books
    if (!in_array($user_role, ['librarian', 'super_admin', 'school_admin'])) {
        $error = "You don't have permission to return books.";
    } else {

    try {
        $db->beginTransaction();
        
        // Get loan details
        $loan_query = "SELECT book_id FROM book_loans WHERE id = :loan_id AND status = 'borrowed'";
        $loan_stmt = $db->prepare($loan_query);
        $loan_stmt->bindParam(':loan_id', $loan_id);
        $loan_stmt->execute();
        $loan = $loan_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($loan) {
            // Update loan status
            $return_query = "UPDATE book_loans SET status = 'returned', return_date = NOW() WHERE id = :loan_id";
            $return_stmt = $db->prepare($return_query);
            $return_stmt->bindParam(':loan_id', $loan_id);
            $return_stmt->execute();
            
            // Update book availability
            $book_query = "UPDATE library_books SET copies_available = copies_available + 1 WHERE id = :book_id";
            $book_stmt = $db->prepare($book_query);
            $book_stmt->bindParam(':book_id', $loan['book_id']);
            $book_stmt->execute();
            
            $db->commit();
            $success = "Book returned successfully.";
        } else {
            $error = "Invalid loan record.";
        }
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error returning book: " . $e->getMessage();
        error_log("Library book return error: " . $e->getMessage());
    }
    } // End permission check
}

// Get filter parameters
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: 'all';
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build where conditions
$where_conditions = [];
$params = [];

// Role-based filtering
if ($user_role === 'student' || $user_role === 'teacher') {
    $where_conditions[] = "bl.user_id = :user_id";
    $params[':user_id'] = $user_id;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "bl.status = :status";
    $params[':status'] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(lb.title LIKE :search OR u.name LIKE :search OR lb.isbn LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total loans
$count_query = "SELECT COUNT(*) as total
                FROM book_loans bl
                JOIN library_books lb ON bl.book_id = lb.id
                JOIN users u ON bl.user_id = u.id
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_loans = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_loans / $limit);

// Fetch loans
$query = "SELECT bl.*, lb.title, lb.author, lb.isbn, u.name as borrower_name, u.role as borrower_role,
          DATEDIFF(CURDATE(), bl.due_date) as days_overdue,
          bl.borrowed_date as loan_date
          FROM book_loans bl
          JOIN library_books lb ON bl.book_id = lb.id
          JOIN users u ON bl.user_id = u.id
          $where_clause
          ORDER BY bl.borrowed_date DESC
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
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
                COUNT(CASE WHEN status = 'borrowed' AND due_date < CURDATE() THEN 1 END) as overdue_loans
                FROM book_loans bl" . 
                ($user_role === 'student' || $user_role === 'teacher' ? " WHERE bl.user_id = $user_id" : "");
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">
                    <?php echo ($user_role === 'student' || $user_role === 'teacher') ? 'My Book Loans' : 'Book Loans Management'; ?>
                </h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Library
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-blue-100">
                            <i class="fas fa-book text-blue-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Total Loans</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['total_loans']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-green-100">
                            <i class="fas fa-book-open text-green-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Active Loans</p>
                            <p class="text-lg font-semibold text-green-600"><?php echo $stats['active_loans']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-gray-100">
                            <i class="fas fa-check text-gray-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Returned</p>
                            <p class="text-lg font-semibold text-gray-600"><?php echo $stats['returned_loans']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-red-100">
                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Overdue</p>
                            <p class="text-lg font-semibold text-red-600"><?php echo $stats['overdue_loans']; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by book title, borrower name, or ISBN..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="borrowed" <?php echo $status_filter === 'borrowed' ? 'selected' : ''; ?>>Active Loans</option>
                                <option value="returned" <?php echo $status_filter === 'returned' ? 'selected' : ''; ?>>Returned</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Loans List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book Details</th>
                                <?php if (!in_array($user_role, ['student', 'teacher'])): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Borrower</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Loan Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($loans as $loan): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($loan['title']); ?></div>
                                        <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($loan['author']); ?></div>
                                        <div class="text-xs text-gray-400">ISBN: <?php echo htmlspecialchars($loan['isbn']); ?></div>
                                    </div>
                                </td>
                                <?php if (!in_array($user_role, ['student', 'teacher'])): ?>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($loan['borrower_name']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo ucfirst($loan['borrower_role']); ?></div>
                                </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('M j, Y', strtotime($loan['loan_date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="<?php echo $loan['days_overdue'] > 0 && $loan['status'] === 'borrowed' ? 'text-red-600 font-semibold' : 'text-gray-900'; ?>">
                                        <?php echo date('M j, Y', strtotime($loan['due_date'])); ?>
                                    </div>
                                    <?php if ($loan['days_overdue'] > 0 && $loan['status'] === 'borrowed'): ?>
                                    <div class="text-xs text-red-500">
                                        <?php echo $loan['days_overdue']; ?> day(s) overdue
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        if ($loan['status'] === 'borrowed') {
                                            echo $loan['days_overdue'] > 0 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800';
                                        } else {
                                            echo 'bg-green-100 text-green-800';
                                        }
                                        ?>">
                                        <?php 
                                        if ($loan['status'] === 'borrowed') {
                                            echo $loan['days_overdue'] > 0 ? 'Overdue' : 'Borrowed';
                                        } else {
                                            echo 'Returned';
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <?php if ($loan['status'] === 'borrowed' && in_array($user_role, ['librarian', 'super_admin'])): ?>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="loan_id" value="<?php echo $loan['id']; ?>">
                                        <button type="submit" name="return_book" 
                                            class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm"
                                            onclick="return confirm('Mark this book as returned?')">
                                            Return Book
                                        </button>
                                    </form>
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
                <p class="text-gray-500">
                    <?php if ($search || $status_filter !== 'all'): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No book loans have been recorded yet.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $status_filter !== 'all' ? "&status=$status_filter" : ''; ?>" 
                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </nav>
            </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
