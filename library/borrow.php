<?php
session_start();

// Debug: Check session
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php?error=" . urlencode("Please log in to access the library."));
    exit();
}

$allowed_roles = ['student', 'teacher', 'super_admin', 'school_admin', 'principal', 'librarian'];
if (!in_array($_SESSION['role'] ?? '', $allowed_roles)) {
    header("Location: ../dashboard.php?error=" . urlencode("You don't have permission to access the library."));
    exit();
}

try {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    header("Location: ../dashboard.php?error=" . urlencode("Database connection failed. Please try again later."));
    exit();
}

$user_id = $_SESSION['user_id'];
$book_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

// If no book ID is provided, show the book selection page
if (!$book_id) {
    try {
        // Get search parameters
        $search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?? '';
        $category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?? '';

        // Build query for available books
        $where_conditions = ["copies_available > 0"];
        $params = [];

        if (!empty($search)) {
            $where_conditions[] = "(title LIKE :search OR author LIKE :search OR isbn LIKE :search)";
            $params[':search'] = "%$search%";
        }

        if (!empty($category)) {
            $where_conditions[] = "category = :category";
            $params[':category'] = $category;
        }

        $where_clause = implode(" AND ", $where_conditions);

        // Get available books
        $books_query = "SELECT * FROM library_books WHERE $where_clause ORDER BY title ASC";
        $books_stmt = $db->prepare($books_query);
        foreach ($params as $key => $value) {
            $books_stmt->bindValue($key, $value);
        }
        $books_stmt->execute();
        $available_books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get categories for filter
        $categories_query = "SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category != '' ORDER BY category";
        $categories_stmt = $db->query($categories_query);
        $categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

        // Get user's current loan count
        $loan_count_query = "SELECT COUNT(*) as count FROM book_loans WHERE user_id = :user_id AND status = 'borrowed'";
        $loan_count_stmt = $db->prepare($loan_count_query);
        $loan_count_stmt->bindParam(':user_id', $user_id);
        $loan_count_stmt->execute();
        $current_loans = $loan_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        // Get maximum books allowed (default to 3)
        $max_books = 3;

        // Debug: Check if we have the required variables
        if (!isset($available_books)) $available_books = [];
        if (!isset($categories)) $categories = [];
        if (!isset($current_loans)) $current_loans = 0;
        if (!isset($max_books)) $max_books = 3;

        // Show book selection page
        include 'borrow_selection.php';
        exit();
    } catch (Exception $e) {
        // Debug: Log the error
        error_log("Borrow page error: " . $e->getMessage());

        // If there's an error, redirect to library index with error message
        header("Location: books/index.php?error=" . urlencode("Unable to load borrow books page: " . $e->getMessage()));
        exit();
    }
}

// Fetch book details
$book_query = "SELECT * FROM library_books WHERE id = :book_id";
$book_stmt = $db->prepare($book_query);
$book_stmt->bindParam(':book_id', $book_id);
$book_stmt->execute();
$book = $book_stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: books/index.php");
    exit();
}

// Check if book is available
if ($book['copies_available'] <= 0) {
    header("Location: books/index.php?error=Book is not available for borrowing");
    exit();
}

// Check if user already has this book borrowed
$existing_loan_query = "SELECT id FROM book_loans WHERE user_id = :user_id AND book_id = :book_id AND status = 'borrowed'";
$existing_loan_stmt = $db->prepare($existing_loan_query);
$existing_loan_stmt->bindParam(':user_id', $user_id);
$existing_loan_stmt->bindParam(':book_id', $book_id);
$existing_loan_stmt->execute();

if ($existing_loan_stmt->rowCount() > 0) {
    header("Location: books/index.php?error=You already have this book borrowed");
    exit();
}

// Get user's current loan count
$loan_count_query = "SELECT COUNT(*) as count FROM book_loans WHERE user_id = :user_id AND status = 'borrowed'";
$loan_count_stmt = $db->prepare($loan_count_query);
$loan_count_stmt->bindParam(':user_id', $user_id);
$loan_count_stmt->execute();
$current_loans = $loan_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get maximum books allowed (default to 3)
$max_books = 3;

if ($current_loans >= $max_books) {
    header("Location: books/index.php?error=You have reached the maximum number of borrowed books ($max_books)");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loan_duration = filter_input(INPUT_POST, 'loan_duration', FILTER_SANITIZE_NUMBER_INT) ?: 14;
    
    try {
        $db->beginTransaction();
        
        // Create loan record
        $due_date = date('Y-m-d', strtotime("+$loan_duration days"));
        $loan_query = "INSERT INTO book_loans (book_id, user_id, borrowed_date, due_date, status)
                      VALUES (:book_id, :user_id, CURDATE(), :due_date, 'borrowed')";
        $loan_stmt = $db->prepare($loan_query);
        $loan_stmt->bindParam(':book_id', $book_id);
        $loan_stmt->bindParam(':user_id', $user_id);
        $loan_stmt->bindParam(':due_date', $due_date);
        $loan_stmt->execute();
        
        // Update book availability
        $update_book_query = "UPDATE library_books SET copies_available = copies_available - 1 WHERE id = :book_id";
        $update_book_stmt = $db->prepare($update_book_query);
        $update_book_stmt->bindParam(':book_id', $book_id);
        $update_book_stmt->execute();
        
        $db->commit();
        header("Location: loans.php?success=Book borrowed successfully. Due date: " . date('M j, Y', strtotime($due_date)));
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error borrowing book. Please try again.";
    }
}
?>

<?php
$title = "Borrow Book";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Library', 'url' => 'books/index.php'],
    ['title' => 'Borrow Book']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Borrow Book</h1>
                    <a href="books/index.php" class="w-full sm:w-auto bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap transition-colors shadow-sm">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Library
                    </a>
                </div>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 dark:bg-red-900/30 dark:text-red-300 dark:border-red-800 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden border border-gray-200 dark:border-gray-700">
                <!-- Book Details -->
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <div class="flex flex-col sm:flex-row items-center sm:items-start gap-6">
                        <!-- Book Cover - Center on mobile, left on desktop -->
                        <div class="w-24 sm:w-32 h-32 sm:h-44 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center flex-shrink-0 shadow-md">
                            <i class="fas fa-book text-gray-400 dark:text-gray-500 text-3xl sm:text-4xl"></i>
                        </div>
                        
                        <!-- Book Information -->
                        <div class="flex-grow w-full">
                            <h2 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white mb-2 text-center sm:text-left"><?php echo htmlspecialchars($book['title']); ?></h2>
                            <p class="text-base sm:text-lg text-gray-600 dark:text-gray-300 mb-4 text-center sm:text-left">by <?php echo htmlspecialchars($book['author']); ?></p>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-xs sm:text-sm text-gray-600 dark:text-gray-400">
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">ISBN</span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['isbn']); ?></span>
                                </div>
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">Category</span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['category'] ?? 'Uncategorized'); ?></span>
                                </div>
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">Publisher</span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['publisher'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">Pub. Year</span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['publication_year'] ?? 'N/A'); ?></span>
                                </div>
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">Location</span>
                                    <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['location'] ?? 'Not specified'); ?></span>
                                </div>
                                <div class="flex justify-between border-b border-gray-100 dark:border-gray-700 pb-1.5">
                                    <span class="font-medium text-gray-500 dark:text-gray-400">Available Copies</span>
                                    <span class="text-green-600 dark:text-green-400 font-semibold text-right"><?php echo $book['copies_available']; ?></span>
                                </div>
                            </div>
                            
                            <?php if ($book['description']): ?>
                            <div class="mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <span class="font-semibold text-gray-700 dark:text-gray-300 block mb-2">Description</span>
                                <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Borrowing Form -->
                <form action="" method="POST" class="p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Loan Details</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="loan_duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Loan Duration</label>
                            <select id="loan_duration" name="loan_duration" 
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="7">1 Week (7 days)</option>
                                <option value="14" selected>2 Weeks (14 days)</option>
                                <option value="21">3 Weeks (21 days)</option>
                                <option value="30">1 Month (30 days)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Due Date</label>
                            <div id="due-date-display" class="w-full px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 font-medium">
                                <!-- Will be updated by JavaScript -->
                            </div>
                        </div>
                    </div>

                    <!-- Current Loans Info -->
                    <div class="bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900/50 rounded-lg p-4 mb-6">
                        <h4 class="font-semibold text-blue-800 dark:text-blue-300 mb-2">Your Current Loans</h4>
                        <div class="text-sm text-blue-700 dark:text-blue-400 leading-relaxed">
                            <p>Currently borrowed books: <span class="font-bold"><?php echo $current_loans; ?></span> of <span class="font-bold"><?php echo $max_books; ?></span> allowed</p>
                            <p class="mt-1.5">After borrowing this book, you will have <span class="font-bold"><?php echo $current_loans + 1; ?></span> borrowed books.</p>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="bg-gray-50 dark:bg-gray-700/30 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-6">
                        <h4 class="font-semibold text-gray-800 dark:text-gray-200 mb-2">Borrowing Terms & Conditions</h4>
                        <ul class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
                            <li>• You are responsible for the book until it is returned</li>
                            <li>• Late returns may incur fines as per library policy</li>
                            <li>• Lost or damaged books must be replaced or paid for</li>
                            <li>• Books cannot be renewed if there are pending reservations</li>
                            <li>• Maximum of <?php echo $max_books; ?> books can be borrowed at a time</li>
                        </ul>
                    </div>

                    <div class="flex flex-col sm:flex-row justify-end gap-3 pt-2">
                        <a href="books/index.php" 
                            class="w-full sm:w-auto px-6 py-2.5 text-center border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Cancel
                        </a>
                        <button type="submit"
                            class="w-full sm:w-auto px-6 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg shadow-sm text-sm font-medium transition-colors flex items-center justify-center">
                            <i class="fas fa-book mr-2"></i>Borrow Book
                        </button>
                    </div>
                </form>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function updateDueDate() {
    const loanDuration = document.getElementById('loan_duration').value;
    const dueDate = new Date();
    dueDate.setDate(dueDate.getDate() + parseInt(loanDuration));
    
    const options = { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    };
    
    document.getElementById('due-date-display').textContent = dueDate.toLocaleDateString('en-US', options);
}

// Update due date on page load and when duration changes
document.addEventListener('DOMContentLoaded', updateDueDate);
document.getElementById('loan_duration').addEventListener('change', updateDueDate);
</script>
