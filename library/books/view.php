<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$book_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$book_id) {
    header("Location: index.php");
    exit();
}

// Fetch book details
$query = "SELECT * FROM library_books WHERE id = :book_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':book_id', $book_id);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: index.php");
    exit();
}

// Get borrowing history
$history_query = "SELECT bl.*, u.name as borrower_name 
                  FROM book_loans bl 
                  JOIN users u ON bl.user_id = u.id 
                  WHERE bl.book_id = :book_id 
                  ORDER BY bl.borrowed_date DESC 
                  LIMIT 10";
$history_stmt = $db->prepare($history_query);
$history_stmt->bindParam(':book_id', $book_id);
$history_stmt->execute();
$borrowing_history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Book Details - " . $book['title'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-6xl mx-auto">
                <div class="mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white mb-4">Book Details</h1>
                    <div class="flex gap-3">
                        <a href="index.php" class="flex-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Library
                        </a>
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])): ?>
                        <a href="edit.php?id=<?php echo $book['id']; ?>" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap">
                            <i class="fas fa-edit mr-2"></i>Edit Book
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Book Information -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div class="p-6">
                                <div class="flex items-start gap-4 sm:gap-6">
                                    <!-- Book Cover - Fixed on left -->
                                    <div class="w-24 sm:w-40 bg-gray-200 dark:bg-gray-700 rounded-lg flex items-center justify-center flex-shrink-0 shadow-md overflow-hidden" style="aspect-ratio: 2 / 3;">
                                        <?php if (!empty($book['cover_image'])): ?>
                                            <img src="../../uploads/book_covers/<?php echo htmlspecialchars($book['cover_image']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?> cover" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="fas fa-book text-gray-400 text-3xl sm:text-5xl"></i>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Book Details - Flows on right -->
                                    <div class="flex-1 min-w-0">
                                        <h2 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-white mb-1 sm:mb-2"><?php echo htmlspecialchars($book['title']); ?></h2>
                                        <p class="text-base sm:text-lg text-gray-600 dark:text-gray-400 mb-3 sm:mb-4">by <?php echo htmlspecialchars($book['author']); ?></p>
                                        
                                        <div class="grid grid-cols-2 sm:grid-cols-1 gap-x-6 gap-y-3 text-xs sm:text-sm mb-4 sm:mb-6">
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
                                                <span class="font-medium text-gray-500 dark:text-gray-400">Language</span>
                                                <span class="text-gray-800 dark:text-gray-200 font-semibold text-right"><?php echo htmlspecialchars($book['language'] ?? 'English'); ?></span>
                                            </div>
                                        </div>
                                        
                                        <?php if ($book['description']): ?>
                                        <div>
                                            <h3 class="text-sm sm:text-base font-semibold text-gray-800 dark:text-white mb-1 sm:mb-2">Description</h3>
                                            <p class="text-xs sm:text-sm text-gray-600 dark:text-gray-400 leading-relaxed"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Availability & Actions -->
                    <div class="space-y-6">
                        <!-- Availability Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Availability</h3>
                                <div class="space-y-3">
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Total Copies:</span>
                                        <span class="font-semibold text-gray-900 dark:text-white"><?php echo $book['total_copies'] ?? 1; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Available:</span>
                                        <span class="font-semibold text-green-600 dark:text-green-400"><?php echo $book['copies_available'] ?? 1; ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-600 dark:text-gray-400">Borrowed:</span>
                                        <span class="font-semibold text-blue-600 dark:text-blue-400"><?php echo ($book['total_copies'] ?? 1) - ($book['copies_available'] ?? 1); ?></span>
                                    </div>
                                </div>

                                <?php if (in_array($_SESSION['role'], ['student', 'teacher']) && ($book['copies_available'] ?? 1) > 0): ?>
                                <div class="mt-4">
                                    <a href="../borrow.php?id=<?php echo $book['id']; ?>" 
                                        class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-center block">
                                        <i class="fas fa-book mr-2"></i>Borrow This Book
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Book Status -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                            <div class="p-6">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Book Status</h3>
                                <div class="space-y-2">
                                    <div class="flex items-center">
                                        <span class="w-3 h-3 bg-green-500 rounded-full mr-2"></span>
                                        <span class="text-sm text-gray-600 dark:text-gray-400">Available for borrowing</span>
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        Added: <?php echo date('M j, Y', strtotime($book['created_at'] ?? 'now')); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Borrowing History -->
                <?php if (!empty($borrowing_history)): ?>
                <div class="mt-8">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Recent Borrowing History</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Borrower</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Borrowed Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Due Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Returned Date</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($borrowing_history as $loan): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($loan['borrower_name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                <?php echo date('M j, Y', strtotime($loan['borrowed_date'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                <?php echo date('M j, Y', strtotime($loan['due_date'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                    <?php echo $loan['status'] === 'returned' ? 'bg-green-100 text-green-800' : 
                                                        ($loan['status'] === 'borrowed' ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'); ?>">
                                                    <?php echo ucfirst($loan['status']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $loan['returned_date'] ? date('M j, Y', strtotime($loan['returned_date'])) : '-'; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
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
