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
        header("Location: index.php?error=" . urlencode("Unable to load borrow books page: " . $e->getMessage()));
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
    header("Location: index.php");
    exit();
}

// Check if book is available
if ($book['copies_available'] <= 0) {
    header("Location: index.php?error=Book is not available for borrowing");
    exit();
}

// Check if user already has this book borrowed
$existing_loan_query = "SELECT id FROM book_loans WHERE user_id = :user_id AND book_id = :book_id AND status = 'borrowed'";
$existing_loan_stmt = $db->prepare($existing_loan_query);
$existing_loan_stmt->bindParam(':user_id', $user_id);
$existing_loan_stmt->bindParam(':book_id', $book_id);
$existing_loan_stmt->execute();

if ($existing_loan_stmt->rowCount() > 0) {
    header("Location: index.php?error=You already have this book borrowed");
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
    header("Location: index.php?error=You have reached the maximum number of borrowed books ($max_books)");
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
    ['title' => 'Library', 'url' => 'index.php'],
    ['title' => 'Borrow Book']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

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
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Borrow Book</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Library
                    </a>
                </div>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden border border-gray-200 dark:border-gray-700">
                <!-- Book Details -->
                <div class="p-6 border-b border-gray-200">
                    <div class="flex items-start space-x-6">
                        <div class="w-24 h-32 bg-gray-200 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book text-gray-400 text-3xl"></i>
                        </div>
                        <div class="flex-grow">
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($book['title']); ?></h2>
                            <p class="text-lg text-gray-600 dark:text-gray-300 mb-2">by <?php echo htmlspecialchars($book['author']); ?></p>
                            <div class="grid grid-cols-2 gap-4 text-sm text-gray-600">
                                <div>
                                    <span class="font-medium">ISBN:</span> <?php echo htmlspecialchars($book['isbn']); ?>
                                </div>
                                <div>
                                    <span class="font-medium">Category:</span> <?php echo htmlspecialchars($book['category'] ?? 'Uncategorized'); ?>
                                </div>
                                <div>
                                    <span class="font-medium">Publisher:</span> <?php echo htmlspecialchars($book['publisher'] ?? 'N/A'); ?>
                                </div>
                                <div>
                                    <span class="font-medium">Publication Year:</span> <?php echo htmlspecialchars($book['publication_year'] ?? 'N/A'); ?>
                                </div>
                                <div>
                                    <span class="font-medium">Location:</span> <?php echo htmlspecialchars($book['location'] ?? 'Not specified'); ?>
                                </div>
                                <div>
                                    <span class="font-medium">Available Copies:</span> 
                                    <span class="font-semibold text-green-600"><?php echo $book['copies_available']; ?></span>
                                </div>
                            </div>
                            <?php if ($book['description']): ?>
                            <div class="mt-4">
                                <span class="font-medium text-gray-700">Description:</span>
                                <p class="text-gray-600 mt-1"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Borrowing Form -->
                <form action="" method="POST" class="p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Loan Details</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div>
                            <label for="loan_duration" class="block text-sm font-medium text-gray-700 mb-2">Loan Duration</label>
                            <select id="loan_duration" name="loan_duration" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="7">1 Week (7 days)</option>
                                <option value="14" selected>2 Weeks (14 days)</option>
                                <option value="21">3 Weeks (21 days)</option>
                                <option value="30">1 Month (30 days)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
                            <div id="due-date-display" class="w-full px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-gray-700">
                                <!-- Will be updated by JavaScript -->
                            </div>
                        </div>
                    </div>

                    <!-- Current Loans Info -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                        <h4 class="font-medium text-blue-800 mb-2">Your Current Loans</h4>
                        <div class="text-sm text-blue-700">
                            <p>Currently borrowed books: <span class="font-semibold"><?php echo $current_loans; ?></span> of <span class="font-semibold"><?php echo $max_books; ?></span> allowed</p>
                            <p class="mt-1">After borrowing this book, you will have <span class="font-semibold"><?php echo $current_loans + 1; ?></span> borrowed books.</p>
                        </div>
                    </div>

                    <!-- Terms and Conditions -->
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 mb-6">
                        <h4 class="font-medium text-gray-800 mb-2">Borrowing Terms & Conditions</h4>
                        <ul class="text-sm text-gray-600 space-y-1">
                            <li>• You are responsible for the book until it is returned</li>
                            <li>• Late returns may incur fines as per library policy</li>
                            <li>• Lost or damaged books must be replaced or paid for</li>
                            <li>• Books cannot be renewed if there are pending reservations</li>
                            <li>• Maximum of <?php echo $max_books; ?> books can be borrowed at a time</li>
                        </ul>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <a href="index.php" 
                            class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </a>
                        <button type="submit"
                            class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
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
