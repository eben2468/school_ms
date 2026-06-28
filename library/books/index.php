<?php
session_start();
require_once '../../includes/access_control.php';
requireModuleRole('library');

require_once '../../config/database.php';
require_once '../../includes/module_access.php';
requireModule('library'); // block access if the module is disabled for this school
require_once '../../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs missing newer library_books columns (e.g. total_copies).
ensureLibraryBooksColumns($db);

$user_role = $_SESSION['role'];
// Staff can manage the catalogue; students/teachers browse and borrow.
$is_staff = in_array($user_role, ['super_admin', 'school_admin', 'librarian'], true);

// Flash messages carried over from redirects (e.g. books/delete.php).
if (isset($_GET['deleted'])) {
    $success_message = '"' . $_GET['deleted'] . '" was removed from the library.';
}
if (isset($_GET['error'])) {
    $error_message = $_GET['error'];
}

// Handle book deletion (staff only)
if ($is_staff && isset($_POST['delete_book']) && isset($_POST['book_id'])) {
    $book_id = filter_input(INPUT_POST, 'book_id', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Check if book has active loans
        $check_query = "SELECT COUNT(*) as active_loans FROM book_loans WHERE book_id = :book_id AND status = 'borrowed'";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':book_id', $book_id);
        $check_stmt->execute();
        $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check_result['active_loans'] > 0) {
            $error_message = "Cannot delete book with active loans. Please ensure all copies are returned first.";
        } else {
            $query = "DELETE FROM library_books WHERE id = :book_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':book_id', $book_id);
            if ($stmt->execute()) {
                $success_message = "Book deleted successfully!";
            } else {
                $error_message = "Error deleting book.";
            }
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$category_filter = isset($_GET['category']) ? $_GET['category'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

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

if ($status_filter) {
    if ($status_filter === 'available') {
        $where_conditions[] = "copies_available > 0";
    } elseif ($status_filter === 'unavailable') {
        $where_conditions[] = "copies_available = 0";
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch books with borrowing information
$query = "SELECT lb.*, 
          COUNT(CASE WHEN bl.status = 'borrowed' THEN 1 END) as borrowed_count,
          COUNT(bl.id) as total_loans
          FROM library_books lb
          LEFT JOIN book_loans bl ON lb.id = bl.book_id
          $where_clause
          GROUP BY lb.id
          ORDER BY lb.title";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories_query = "SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL ORDER BY category";
$categories_stmt = $db->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get book statistics
$stats_query = "SELECT
    COUNT(*) as total_books,
    SUM(COALESCE(total_copies, 0)) as total_copies,
    SUM(COALESCE(copies_available, 0)) as available_copies,
    COUNT(CASE WHEN copies_available > 0 THEN 1 END) as available_books
    FROM library_books";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Library Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Library Management']
];
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
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-purple-600 via-blue-600 to-indigo-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Library Management</h1>
                                <p class="text-purple-100 text-lg">Browse the catalogue, manage books, loans and resources</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-purple-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-book mr-2"></i>
                                        <?php echo number_format($stats['total_books']); ?> Books
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-copy mr-2"></i>
                                        <?php echo number_format($stats['total_copies']); ?> Copies
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-books text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Toolbar -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <div>
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Book Catalogue</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Search, borrow and manage the library collection</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <a href="../loans.php" class="inline-flex items-center whitespace-nowrap bg-green-500 hover:bg-green-600 text-white px-5 py-2.5 rounded-lg shadow-sm transition-all duration-200">
                            <i class="fas fa-book-reader mr-2"></i>View Loans
                        </a>
                        <?php if ($is_staff): ?>
                        <a href="../reports.php" class="inline-flex items-center whitespace-nowrap bg-purple-500 hover:bg-purple-600 text-white px-5 py-2.5 rounded-lg shadow-sm transition-all duration-200">
                            <i class="fas fa-chart-bar mr-2"></i>Reports
                        </a>
                        <?php $export_availability = $status_filter === 'available' ? 'available' : ($status_filter === 'unavailable' ? 'borrowed' : ''); ?>
                        <a href="export.php?<?php echo http_build_query(array_filter(['search' => $search, 'category' => $category_filter, 'availability' => $export_availability])); ?>" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-5 py-2.5 rounded-lg shadow-sm transition-all duration-200">
                            <i class="fas fa-download mr-2"></i>Export
                        </a>
                        <a href="bulk_import.php" class="inline-flex items-center whitespace-nowrap bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 px-5 py-2.5 rounded-lg shadow-sm transition-all duration-200">
                            <i class="fas fa-file-import mr-2 text-purple-500"></i>Bulk Upload
                        </a>
                        <a href="create.php" class="inline-flex items-center whitespace-nowrap bg-blue-500 hover:bg-blue-600 text-white px-5 py-2.5 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                            <i class="fas fa-plus mr-2"></i>Add Book
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                <div class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-200 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Books -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Books</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_books']); ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-book mr-1"></i>
                                    Unique titles
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-book text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Total Copies -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Copies</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['total_copies']); ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-copy mr-1"></i>
                                    All copies
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-copy text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Available Copies -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Available Copies</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['available_copies']); ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Ready to borrow
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Available Books -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Available Books</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['available_books']); ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-bookmark mr-1"></i>
                                    In stock
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bookmark text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Search & Filter Books</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Find books by title, author, ISBN, category, or status</p>
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
                                    <i class="fas fa-check-circle mr-2 text-purple-500"></i>Status
                                </label>
                                <select name="status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Status</option>
                                    <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="unavailable" <?php echo $status_filter === 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                                </select>
                            </div>
                            <div class="md:col-span-4 flex justify-end space-x-3">
                                <a href="index.php" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                    <i class="fas fa-times mr-2"></i>Clear
                                </a>
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                                    <i class="fas fa-search mr-2"></i>Filter Books
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Books Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <?php foreach ($books as $book): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <!-- Book Header -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex gap-3 flex-grow min-w-0">
                                    <?php if (!empty($book['cover_image'])): ?>
                                    <img src="../../uploads/book_covers/<?php echo htmlspecialchars($book['cover_image']); ?>" alt="<?php echo htmlspecialchars($book['title']); ?> cover" class="w-12 rounded-md shadow flex-shrink-0 object-cover" style="aspect-ratio: 2 / 3;">
                                    <?php endif; ?>
                                    <div class="min-w-0">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2 group-hover:text-blue-600 dark:group-hover:text-blue-400 transition-colors duration-200">
                                            <?php echo htmlspecialchars($book['title']); ?>
                                        </h3>
                                        <p class="text-sm text-blue-600 dark:text-blue-400 font-medium">
                                            <i class="fas fa-user mr-1"></i>
                                            <?php echo htmlspecialchars($book['author']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php $is_available = ($book['copies_available'] ?? 0) > 0; ?>
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                                    <?php echo $is_available ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                    <div class="w-2 h-2 rounded-full mr-2 <?php echo $is_available ? 'bg-green-400' : 'bg-red-400'; ?>"></div>
                                    <?php echo $is_available ? 'Available' : 'Unavailable'; ?>
                                </span>
                            </div>

                            <!-- Book Details -->
                            <div class="space-y-2 mb-4">
                                <?php if ($book['isbn']): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">ISBN:</span>
                                    <span class="font-mono text-gray-900 dark:text-white text-xs"><?php echo htmlspecialchars($book['isbn']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($book['category']): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Category:</span>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        <?php echo htmlspecialchars($book['category']); ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <?php if ($book['publisher']): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Publisher:</span>
                                    <span class="text-gray-900 dark:text-white text-xs"><?php echo htmlspecialchars($book['publisher']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($book['publication_year']): ?>
                                <div class="flex justify-between items-center text-sm">
                                    <span class="text-gray-600 dark:text-gray-400">Year:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($book['publication_year']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Copy Statistics -->
                            <div class="grid grid-cols-3 gap-4 mb-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="text-center">
                                    <div class="text-lg font-bold text-blue-600 dark:text-blue-400"><?php echo $book['copies_available'] ?? 0; ?></div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">Available</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-green-600 dark:text-green-400"><?php echo $book['total_copies'] ?? 1; ?></div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">In Stock</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-lg font-bold text-red-600 dark:text-red-400"><?php echo $book['borrowed_count']; ?></div>
                                    <div class="text-xs text-gray-600 dark:text-gray-400">Borrowed</div>
                                </div>
                            </div>

                            <!-- Loan Statistics -->
                            <div class="mb-4 text-center">
                                <span class="text-sm text-gray-600 dark:text-gray-400">
                                    Total Loans: <span class="font-semibold text-gray-900 dark:text-white"><?php echo $book['total_loans']; ?></span>
                                </span>
                            </div>

                            <!-- Action Buttons -->
                            <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                                <a href="view.php?id=<?php echo $book['id']; ?>"
                                    class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium transition-colors duration-200">
                                    <i class="fas fa-eye mr-1"></i>View Details
                                </a>
                                <div class="flex items-center space-x-2 no-stack">
                                    <?php if (!$is_staff && $is_available && in_array($user_role, ['student', 'teacher'], true)): ?>
                                    <a href="../borrow.php?id=<?php echo $book['id']; ?>"
                                        class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white text-sm px-4 py-2 rounded-lg font-medium transition-colors duration-200">
                                        <i class="fas fa-hand-holding mr-2"></i>Borrow
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($is_staff): ?>
                                    <a href="edit.php?id=<?php echo $book['id']; ?>"
                                        class="inline-flex items-center text-gray-600 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this book? This action cannot be undone.')">
                                        <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                        <button type="submit" name="delete_book"
                                            class="inline-flex items-center text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors duration-200" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
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
                        <?php if ($search || $category_filter || $status_filter): ?>
                            Try adjusting your search criteria or clear filters to see all books.
                        <?php else: ?>
                            Get started by adding your first book to the library collection.
                        <?php endif; ?>
                    </p>
                    <div class="flex justify-center space-x-3">
                        <?php if ($search || $category_filter || $status_filter): ?>
                        <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                            <i class="fas fa-times mr-2"></i>Clear Filters
                        </a>
                        <?php endif; ?>
                        <?php if ($is_staff): ?>
                        <a href="create.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Add First Book
                        </a>
                        <?php endif; ?>
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
