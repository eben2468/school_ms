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

// Get filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$category_filter = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?: '';
$availability_filter = filter_input(INPUT_GET, 'availability', FILTER_SANITIZE_STRING) ?: '';
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(title LIKE :search OR author LIKE :search OR isbn LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_filter) {
    $where_conditions[] = "category = :category";
    $params[':category'] = $category_filter;
}

if ($availability_filter === 'available') {
    $where_conditions[] = "copies_available > 0";
} elseif ($availability_filter === 'borrowed') {
    $where_conditions[] = "copies_available = 0";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total books
$count_query = "SELECT COUNT(*) as total FROM library_books $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_books = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_books / $limit);

// Fetch books
$query = "SELECT lb.*,
          COUNT(bl.id) as total_loans,
          COUNT(CASE WHEN bl.status = 'borrowed' THEN 1 END) as active_loans
          FROM library_books lb
          LEFT JOIN book_loans bl ON lb.id = bl.book_id
          $where_clause
          GROUP BY lb.id
          ORDER BY lb.title
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL ORDER BY category";
$categories_stmt = $db->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

$title = "Library Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Library Management']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Library Management</h1>
                                <p class="text-blue-100 text-lg">Manage books, loans, and library resources</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-book mr-2"></i>
                                        <?php echo $total_books; ?> Books
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-alt mr-2"></i>
                                        <?php echo date('F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-book text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex space-x-3">
                        <?php if (in_array($user_role, ['librarian', 'super_admin'])): ?>
                        <a href="books/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-plus mr-2"></i>Add Book
                        </a>
                        <?php endif; ?>
                        <a href="loans.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-book-reader mr-2"></i>View Loans
                        </a>
                        <?php if (in_array($user_role, ['librarian', 'super_admin'])): ?>
                        <a href="reports.php" class="bg-purple-500 hover:bg-purple-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-chart-bar mr-2"></i>Reports
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportBooks()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Search and Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Search & Filter Books</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Find books by title, author, ISBN, or category</p>
                    </div>
                    <div class="p-6">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-search mr-2 text-blue-500"></i>Search Books
                                </label>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Search by title, author, or ISBN..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-tags mr-2 text-green-500"></i>Category
                                </label>
                                <select name="category" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Categories</option>
                                    <?php foreach ($categories as $category): ?>
                                    <option value="<?php echo htmlspecialchars($category); ?>" <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($category)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-check-circle mr-2 text-purple-500"></i>Availability
                                </label>
                                <select name="availability" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Books</option>
                                    <option value="available" <?php echo $availability_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="borrowed" <?php echo $availability_filter === 'borrowed' ? 'selected' : ''; ?>>Out of Stock</option>
                                </select>
                            </div>
                            <div class="md:col-span-4 flex justify-end">
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                                    <i class="fas fa-search mr-2"></i>Search Books
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Books Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">
                    <?php foreach ($books as $book): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-all duration-300 border border-gray-200 dark:border-gray-700 group">
                        <div class="p-6">
                            <!-- Book Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-grow">
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2 line-clamp-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors duration-200">
                                        <?php echo htmlspecialchars($book['title']); ?>
                                    </h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">
                                        <i class="fas fa-user mr-1"></i>
                                        <?php echo htmlspecialchars($book['author']); ?>
                                    </p>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        <i class="fas fa-tag mr-1"></i>
                                        <?php echo htmlspecialchars($book['category'] ?? 'Uncategorized'); ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Book Details -->
                            <div class="space-y-3 mb-4">
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">ISBN:</span>
                                    <span class="font-mono text-gray-900 dark:text-white text-xs"><?php echo htmlspecialchars($book['isbn']); ?></span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Total Copies:</span>
                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo $book['copies_total'] ?? 1; ?></span>
                                </div>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Available:</span>
                                    <span class="font-bold <?php echo $book['copies_available'] > 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                                        <?php echo $book['copies_available']; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Availability Status -->
                            <div class="mb-4">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                                    <?php echo $book['copies_available'] > 0 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                    <div class="w-2 h-2 rounded-full mr-2 <?php echo $book['copies_available'] > 0 ? 'bg-green-400' : 'bg-red-400'; ?>"></div>
                                    <?php echo $book['copies_available'] > 0 ? 'Available' : 'Out of Stock'; ?>
                                </span>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                                <a href="books/view.php?id=<?php echo $book['id']; ?>"
                                    class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium transition-colors duration-200">
                                    <i class="fas fa-eye mr-1"></i>View Details
                                </a>
                                <div class="flex space-x-2">
                                    <?php if ($book['copies_available'] > 0 && in_array($user_role, ['student', 'teacher'])): ?>
                                    <a href="borrow.php?id=<?php echo $book['id']; ?>"
                                        class="inline-flex items-center bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-sm font-medium transition-colors duration-200">
                                        <i class="fas fa-book mr-1"></i>Borrow
                                    </a>
                                    <?php endif; ?>
                                    <?php if (in_array($user_role, ['librarian', 'super_admin'])): ?>
                                    <a href="books/edit.php?id=<?php echo $book['id']; ?>"
                                        class="inline-flex items-center text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($books)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-book text-gray-400 text-4xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No books found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">
                        <?php if ($search || $category_filter || $availability_filter): ?>
                            Try adjusting your search criteria or clear filters to see all books.
                        <?php else: ?>
                            Get started by adding your first book to the library collection.
                        <?php endif; ?>
                    </p>
                    <div class="flex justify-center space-x-3">
                        <?php if ($search || $category_filter || $availability_filter): ?>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                            <i class="fas fa-times mr-2"></i>Clear Filters
                        </a>
                        <?php endif; ?>
                        <?php if (in_array($user_role, ['librarian', 'super_admin'])): ?>
                        <a href="books/create.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Add First Book
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-8 flex justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $availability_filter ? "&availability=$availability_filter" : ''; ?>"
                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200
                            <?php echo $i === $page ? 'z-10 bg-blue-50 dark:bg-blue-900 border-blue-500 text-blue-600 dark:text-blue-200' : ''; ?>">
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

<script>
function exportBooks() {
    ExportUtils.showExportModal({
        title: 'Export Library Books',
        csvCallback: () => {
            // Prepare data for export
            const data = [
                <?php foreach ($books as $book): ?>
                {
                    'Title': '<?php echo addslashes($book['title']); ?>',
                    'Author': '<?php echo addslashes($book['author']); ?>',
                    'ISBN': '<?php echo addslashes($book['isbn']); ?>',
                    'Category': '<?php echo addslashes($book['category']); ?>',
                    'Total Copies': '<?php echo $book['copies_total'] ?? 1; ?>',
                    'Available': '<?php echo $book['copies_available']; ?>',
                    'Status': '<?php echo ucfirst($book['status']); ?>'
                },
                <?php endforeach; ?>
            ];

            ExportUtils.exportArrayToCSV(
                data,
                ExportUtils.generateFilename('library_books'),
                ['Title', 'Author', 'ISBN', 'Category', 'Total Copies', 'Available', 'Status']
            );
            ExportUtils.showSuccessMessage('Library books exported successfully!');
        },
        pdfCallback: () => {
            ExportUtils.exportToPDF('Library Books Report', 'main');
        }
    });
}
</script>