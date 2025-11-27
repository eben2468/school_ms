<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$book_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$book_id) {
    header("Location: ../index.php");
    exit();
}

// Fetch book details
$query = "SELECT * FROM library_books WHERE id = :book_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':book_id', $book_id);
$stmt->execute();
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    header("Location: ../index.php");
    exit();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $author = filter_input(INPUT_POST, 'author', FILTER_SANITIZE_STRING);
    $isbn = filter_input(INPUT_POST, 'isbn', FILTER_SANITIZE_STRING);
    $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
    $publisher = filter_input(INPUT_POST, 'publisher', FILTER_SANITIZE_STRING);
    $publication_year = filter_input(INPUT_POST, 'publication_year', FILTER_SANITIZE_NUMBER_INT);
    $language = filter_input(INPUT_POST, 'language', FILTER_SANITIZE_STRING);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $total_copies = filter_input(INPUT_POST, 'total_copies', FILTER_SANITIZE_NUMBER_INT);
    
    // Validation
    if (empty($title)) $errors[] = "Title is required.";
    if (empty($author)) $errors[] = "Author is required.";
    if (empty($isbn)) $errors[] = "ISBN is required.";
    if ($total_copies < 1) $errors[] = "Total copies must be at least 1.";
    
    // Check if ISBN already exists (excluding current book)
    if (!empty($isbn)) {
        $check_query = "SELECT id FROM library_books WHERE isbn = :isbn AND id != :book_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':isbn', $isbn);
        $check_stmt->bindParam(':book_id', $book_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $errors[] = "A book with this ISBN already exists.";
        }
    }
    
    if (empty($errors)) {
        try {
            // Calculate new available copies
            $borrowed_copies = ($book['total_copies'] ?? 1) - ($book['copies_available'] ?? 1);
            $new_available = max(0, $total_copies - $borrowed_copies);
            
            $update_query = "UPDATE library_books SET 
                           title = :title, 
                           author = :author, 
                           isbn = :isbn, 
                           category = :category, 
                           publisher = :publisher, 
                           publication_year = :publication_year, 
                           language = :language, 
                           location = :location, 
                           description = :description, 
                           total_copies = :total_copies, 
                           copies_available = :copies_available,
                           updated_at = NOW()
                           WHERE id = :book_id";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':title', $title);
            $update_stmt->bindParam(':author', $author);
            $update_stmt->bindParam(':isbn', $isbn);
            $update_stmt->bindParam(':category', $category);
            $update_stmt->bindParam(':publisher', $publisher);
            $update_stmt->bindParam(':publication_year', $publication_year);
            $update_stmt->bindParam(':language', $language);
            $update_stmt->bindParam(':location', $location);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':total_copies', $total_copies);
            $update_stmt->bindParam(':copies_available', $new_available);
            $update_stmt->bindParam(':book_id', $book_id);
            $update_stmt->execute();
            
            header("Location: view.php?id=$book_id&success=Book updated successfully");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Error updating book. Please try again.";
        }
    }
}

$title = "Edit Book - " . $book['title'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800">Edit Book</h1>
                    <div class="space-x-3">
                        <a href="view.php?id=<?php echo $book['id']; ?>" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Book Details
                        </a>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <form action="" method="POST" class="p-6 space-y-6">
                        <!-- Basic Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Basic Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Title *</label>
                                    <input type="text" id="title" name="title" required
                                        value="<?php echo htmlspecialchars($book['title']); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="author" class="block text-sm font-medium text-gray-700 mb-2">Author *</label>
                                    <input type="text" id="author" name="author" required
                                        value="<?php echo htmlspecialchars($book['author']); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="isbn" class="block text-sm font-medium text-gray-700 mb-2">ISBN *</label>
                                    <input type="text" id="isbn" name="isbn" required
                                        value="<?php echo htmlspecialchars($book['isbn']); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                                    <input type="text" id="category" name="category"
                                        value="<?php echo htmlspecialchars($book['category'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="publisher" class="block text-sm font-medium text-gray-700 mb-2">Publisher</label>
                                    <input type="text" id="publisher" name="publisher"
                                        value="<?php echo htmlspecialchars($book['publisher'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="publication_year" class="block text-sm font-medium text-gray-700 mb-2">Publication Year</label>
                                    <input type="number" id="publication_year" name="publication_year" min="1000" max="<?php echo date('Y'); ?>"
                                        value="<?php echo htmlspecialchars($book['publication_year'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="language" class="block text-sm font-medium text-gray-700 mb-2">Language</label>
                                    <input type="text" id="language" name="language"
                                        value="<?php echo htmlspecialchars($book['language'] ?? 'English'); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-700 mb-2">Location</label>
                                    <input type="text" id="location" name="location"
                                        value="<?php echo htmlspecialchars($book['location'] ?? ''); ?>"
                                        placeholder="e.g., Shelf A-1, Section 2"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Copies Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Copies Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="total_copies" class="block text-sm font-medium text-gray-700 mb-2">Total Copies *</label>
                                    <input type="number" id="total_copies" name="total_copies" required min="1"
                                        value="<?php echo htmlspecialchars($book['total_copies'] ?? 1); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    <p class="text-sm text-gray-500 mt-1">
                                        Currently borrowed: <?php echo ($book['total_copies'] ?? 1) - ($book['copies_available'] ?? 1); ?> copies
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                            <textarea id="description" name="description" rows="4"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Brief description of the book..."><?php echo htmlspecialchars($book['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="flex justify-end space-x-3 pt-4">
                            <a href="view.php?id=<?php echo $book['id']; ?>" 
                                class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit"
                                class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                Update Book
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
