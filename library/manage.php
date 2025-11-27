<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle book actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    $book_id = filter_input(INPUT_POST, 'book_id', FILTER_SANITIZE_NUMBER_INT);
    
    if ($action === 'delete' && $book_id) {
        try {
            // Check if book has active loans
            $check_query = "SELECT COUNT(*) as active_loans FROM book_loans WHERE book_id = :book_id AND status = 'borrowed'";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':book_id', $book_id);
            $check_stmt->execute();
            $check_result = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($check_result['active_loans'] > 0) {
                $error = "Cannot delete book with active loans. Please ensure all copies are returned first.";
            } else {
                $delete_query = "DELETE FROM library_books WHERE id = :book_id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':book_id', $book_id);
                
                if ($delete_stmt->execute()) {
                    $success = "Book deleted successfully.";
                } else {
                    $error = "Failed to delete book.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    } elseif ($action === 'update_copies' && $book_id) {
        $new_copies = filter_input(INPUT_POST, 'copies_available', FILTER_SANITIZE_NUMBER_INT);
        
        if ($new_copies >= 0) {
            try {
                $update_query = "UPDATE library_books SET copies_available = :copies WHERE id = :book_id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':copies', $new_copies);
                $update_stmt->bindParam(':book_id', $book_id);
                
                if ($update_stmt->execute()) {
                    $success = "Book copies updated successfully.";
                } else {
                    $error = "Failed to update book copies.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "Number of copies must be 0 or greater.";
        }
    }
}

// Get filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$category = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?: '';
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(title LIKE :search OR author LIKE :search OR isbn LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category) {
    $where_conditions[] = "category = :category";
    $params[':category'] = $category;
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
          (SELECT COUNT(*) FROM book_loans bl WHERE bl.book_id = lb.id AND bl.status = 'borrowed') as borrowed_count
          FROM library_books lb 
          $where_clause 
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
$categories_query = "SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category != '' ORDER BY category";
$categories_stmt = $db->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);
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
                <h1 class="text-3xl font-semibold text-gray-800">Manage Library Books</h1>
                <div class="flex space-x-3">
                    <a href="books/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add New Book
                    </a>
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

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by title, author, or ISBN..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="category" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>" 
                                        <?php echo $category === $cat ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Books List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Availability</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($books as $book): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($book['title']); ?></div>
                                        <div class="text-sm text-gray-500">by <?php echo htmlspecialchars($book['author']); ?></div>
                                        <?php if ($book['isbn']): ?>
                                        <div class="text-xs text-gray-400">ISBN: <?php echo htmlspecialchars($book['isbn']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($book['category'] ?: 'Uncategorized'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo $book['copies_available'] - $book['borrowed_count']; ?> / <?php echo $book['copies_available']; ?> available
                                    </div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo $book['borrowed_count']; ?> borrowed
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($book['location'] ?: 'Not specified'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <div class="flex justify-end space-x-2">
                                        <!-- Update Copies Modal Trigger -->
                                        <button onclick="openUpdateModal(<?php echo $book['id']; ?>, '<?php echo htmlspecialchars($book['title']); ?>', <?php echo $book['copies_available']; ?>)"
                                            class="text-blue-600 hover:text-blue-900">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <!-- Delete Button -->
                                        <?php if ($book['borrowed_count'] == 0): ?>
                                        <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this book?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="book_id" value="<?php echo $book['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <span class="text-gray-400" title="Cannot delete book with active loans">
                                            <i class="fas fa-trash"></i>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($books)): ?>
            <div class="text-center py-12">
                <i class="fas fa-book text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No books found</h3>
                <p class="text-gray-500">
                    <?php if ($search || $category): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Start by adding some books to the library.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category ? "&category=$category" : ''; ?>" 
                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </nav>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Update Copies Modal -->
<div id="updateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Update Book Copies</h3>
            <form id="updateForm" method="POST">
                <input type="hidden" name="action" value="update_copies">
                <input type="hidden" name="book_id" id="updateBookId">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Book Title</label>
                    <p id="updateBookTitle" class="text-sm text-gray-600"></p>
                </div>
                
                <div class="mb-4">
                    <label for="updateCopies" class="block text-sm font-medium text-gray-700 mb-2">Number of Copies</label>
                    <input type="number" id="updateCopies" name="copies_available" min="0" required
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeUpdateModal()" 
                        class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button type="submit" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Update
                    </button>
                    </div>
                </form>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function openUpdateModal(bookId, bookTitle, currentCopies) {
    document.getElementById('updateBookId').value = bookId;
    document.getElementById('updateBookTitle').textContent = bookTitle;
    document.getElementById('updateCopies').value = currentCopies;
    document.getElementById('updateModal').classList.remove('hidden');
}

function closeUpdateModal() {
    document.getElementById('updateModal').classList.add('hidden');
}
</script>
