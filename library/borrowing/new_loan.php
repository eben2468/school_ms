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

// Fetch active users (students, teachers, admins, etc.) who can borrow
$users_query = "SELECT id, name, role, student_id FROM users WHERE status = 'active' ORDER BY name ASC";
$users_stmt = $db->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch books with available copies
$books_query = "SELECT id, title, author, isbn, copies_available FROM library_books WHERE copies_available > 0 ORDER BY title ASC";
$books_stmt = $db->prepare($books_query);
$books_stmt->execute();
$books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $borrower_id = filter_input(INPUT_POST, 'borrower_id', FILTER_SANITIZE_NUMBER_INT);
    $book_id = filter_input(INPUT_POST, 'book_id', FILTER_SANITIZE_NUMBER_INT);
    $loan_duration = filter_input(INPUT_POST, 'loan_duration', FILTER_SANITIZE_NUMBER_INT) ?: 14;

    if (!$borrower_id || !$book_id) {
        $error_message = "Please select both a borrower and a book.";
    } else {
        try {
            // Check if borrower exists and get role
            $user_check_query = "SELECT role FROM users WHERE id = :user_id AND status = 'active'";
            $user_check_stmt = $db->prepare($user_check_query);
            $user_check_stmt->bindParam(':user_id', $borrower_id);
            $user_check_stmt->execute();
            $user_data = $user_check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user_data) {
                $error_message = "Selected borrower does not exist or is inactive.";
            } else {
                $borrower_role = $user_data['role'];

                // Check if book exists and has copies available
                $book_check_query = "SELECT copies_available, title FROM library_books WHERE id = :book_id";
                $book_check_stmt = $db->prepare($book_check_query);
                $book_check_stmt->bindParam(':book_id', $book_id);
                $book_check_stmt->execute();
                $book_data = $book_check_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$book_data) {
                    $error_message = "Selected book does not exist.";
                } elseif ($book_data['copies_available'] <= 0) {
                    $error_message = "No available copies of '{$book_data['title']}' to loan.";
                } else {
                    // Check if user already has this specific book borrowed
                    $existing_query = "SELECT id FROM book_loans WHERE user_id = :user_id AND book_id = :book_id AND status = 'borrowed'";
                    $existing_stmt = $db->prepare($existing_query);
                    $existing_stmt->bindParam(':user_id', $borrower_id);
                    $existing_stmt->bindParam(':book_id', $book_id);
                    $existing_stmt->execute();

                    if ($existing_stmt->rowCount() > 0) {
                        $error_message = "This user has already borrowed a copy of this book and has not returned it yet.";
                    } else {
                        // Check borrower active loan count limit (Students: 3, Others: 5)
                        $limit_query = "SELECT COUNT(*) as active_loans FROM book_loans WHERE user_id = :user_id AND status = 'borrowed'";
                        $limit_stmt = $db->prepare($limit_query);
                        $limit_stmt->bindParam(':user_id', $borrower_id);
                        $limit_stmt->execute();
                        $active_loans = $limit_stmt->fetch(PDO::FETCH_ASSOC)['active_loans'];

                        $max_limit = ($borrower_role === 'student') ? 3 : 5;

                        if ($active_loans >= $max_limit) {
                            $error_message = "Borrower has reached their active loan limit ({$max_limit} books).";
                        } else {
                            // Proceed to issue loan inside a transaction
                            $db->beginTransaction();

                            $due_date = date('Y-m-d', strtotime("+$loan_duration days"));

                            // Insert loan record (populating both user_id, borrower_id, borrowed_date, and loan_date for full schema support)
                            $insert_query = "INSERT INTO book_loans (book_id, user_id, borrower_id, borrowed_date, loan_date, due_date, status) 
                                            VALUES (:book_id, :user_id, :borrower_id, CURDATE(), CURDATE(), :due_date, 'borrowed')";
                            $insert_stmt = $db->prepare($insert_query);
                            $insert_stmt->bindParam(':book_id', $book_id);
                            $insert_stmt->bindParam(':user_id', $borrower_id);
                            $insert_stmt->bindParam(':borrower_id', $borrower_id);
                            $insert_stmt->bindParam(':due_date', $due_date);
                            $insert_stmt->execute();

                            // Decrement available copies
                            $update_query = "UPDATE library_books SET copies_available = copies_available - 1 WHERE id = :book_id";
                            $update_stmt = $db->prepare($update_query);
                            $update_stmt->bindParam(':book_id', $book_id);
                            $update_stmt->execute();

                            $db->commit();
                            $success_message = "Loan registered successfully! Due date is " . date('M j, Y', strtotime($due_date)) . ".";
                            
                            // Re-fetch books to get updated availability
                            $books_stmt->execute();
                            $books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

$title = "Create New Loan";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Create New Book Loan</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Issue a library book to a registered student or teacher</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Borrowing
                    </a>
                </div>

                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2 text-xl"></i>
                        <span><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 shadow-sm">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2 text-xl"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-blue-500 to-indigo-600 text-white">
                        <h3 class="text-lg font-semibold flex items-center">
                            <i class="fas fa-book-reader mr-2"></i>Issue Book Form
                        </h3>
                    </div>
                    
                    <form action="" method="POST" class="p-6 space-y-6">
                        <!-- Borrower Selection -->
                        <div>
                            <label for="borrower_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-user mr-1 text-blue-500"></i>Select Borrower *
                            </label>
                            <select id="borrower_id" name="borrower_id" required
                                class="w-full px-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">-- Select Student or Teacher --</option>
                                <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user['id']; ?>">
                                    <?php echo htmlspecialchars($user['name']); ?> 
                                    (<?php echo htmlspecialchars(formatRoleName($user['role'])); ?>)
                                    <?php echo $user['student_id'] ? " - ID: " . htmlspecialchars($user['student_id']) : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Book Selection -->
                        <div>
                            <label for="book_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-book mr-1 text-green-500"></i>Select Book *
                            </label>
                            <select id="book_id" name="book_id" required
                                class="w-full px-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">-- Select Available Book --</option>
                                <?php foreach ($books as $b): ?>
                                <option value="<?php echo $b['id']; ?>">
                                    <?php echo htmlspecialchars($b['title']); ?> by <?php echo htmlspecialchars($b['author']); ?> 
                                    (ISBN: <?php echo htmlspecialchars($b['isbn'] ?: 'N/A'); ?>) - <?php echo $b['copies_available']; ?> copy/copies left
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Duration Selector -->
                        <div>
                            <label for="loan_duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-calendar-day mr-1 text-purple-500"></i>Loan Duration *
                            </label>
                            <select id="loan_duration" name="loan_duration" required
                                class="w-full px-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="7">1 Week (7 Days)</option>
                                <option value="14" selected>2 Weeks (14 Days)</option>
                                <option value="21">3 Weeks (21 Days)</option>
                                <option value="30">1 Month (30 Days)</option>
                            </select>
                        </div>

                        <!-- Submission Actions -->
                        <div class="flex justify-end pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex space-x-3">
                                <a href="index.php" 
                                   class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-colors">
                                    Cancel
                                </a>
                                <button type="submit" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-all shadow-md flex items-center">
                                    <i class="fas fa-hand-holding mr-2"></i>Issue Book
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
