<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $author = filter_input(INPUT_POST, 'author', FILTER_SANITIZE_STRING);
    $isbn = filter_input(INPUT_POST, 'isbn', FILTER_SANITIZE_STRING);
    $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
    $copies_available = filter_input(INPUT_POST, 'copies_available', FILTER_SANITIZE_NUMBER_INT);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $publisher = filter_input(INPUT_POST, 'publisher', FILTER_SANITIZE_STRING);
    $publication_year = filter_input(INPUT_POST, 'publication_year', FILTER_SANITIZE_NUMBER_INT);
    $language = filter_input(INPUT_POST, 'language', FILTER_SANITIZE_STRING);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    
    if (empty($title) || empty($author) || $copies_available < 1) {
        $error = "Title, author, and at least 1 copy are required.";
    } else {
        try {
            $query = "INSERT INTO library_books (title, author, isbn, category, copies_available, description, publisher, publication_year, language, location) 
                     VALUES (:title, :author, :isbn, :category, :copies_available, :description, :publisher, :publication_year, :language, :location)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':author', $author);
            $stmt->bindParam(':isbn', $isbn);
            $stmt->bindParam(':category', $category);
            $stmt->bindParam(':copies_available', $copies_available);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':publisher', $publisher);
            $stmt->bindParam(':publication_year', $publication_year);
            $stmt->bindParam(':language', $language);
            $stmt->bindParam(':location', $location);
            
            if ($stmt->execute()) {
                $success = "Book added successfully to the library.";
                // Clear form data
                $title = $author = $isbn = $category = $description = $publisher = $language = $location = '';
                $copies_available = $publication_year = '';
            } else {
                $error = "Failed to add book. Please try again.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "A book with this ISBN already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get book categories for dropdown
$categories = [
    'Fiction', 'Non-Fiction', 'Science', 'Mathematics', 'History', 'Geography', 
    'Literature', 'Biography', 'Reference', 'Textbook', 'Children', 'Young Adult',
    'Technology', 'Arts', 'Philosophy', 'Religion', 'Health', 'Sports', 'Other'
];

$languages = ['English', 'Spanish', 'French', 'German', 'Chinese', 'Arabic', 'Other'];
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Add New Book</h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Books
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

            <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Book Information</h2>
                    <p class="text-gray-600 text-sm mt-1">Enter the details of the new book to add to the library.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <!-- Basic Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Book Title *</label>
                            <input type="text" id="title" name="title" required
                                value="<?php echo htmlspecialchars($title ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter book title">
                        </div>

                        <div>
                            <label for="author" class="block text-sm font-medium text-gray-700 mb-2">Author *</label>
                            <input type="text" id="author" name="author" required
                                value="<?php echo htmlspecialchars($author ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Enter author name">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="isbn" class="block text-sm font-medium text-gray-700 mb-2">ISBN</label>
                            <input type="text" id="isbn" name="isbn"
                                value="<?php echo htmlspecialchars($isbn ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="978-0-123456-78-9">
                        </div>

                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Category</label>
                            <select id="category" name="category"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select category...</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat; ?>" 
                                        <?php echo (isset($category) && $category === $cat) ? 'selected' : ''; ?>>
                                        <?php echo $cat; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="copies_available" class="block text-sm font-medium text-gray-700 mb-2">Copies Available *</label>
                            <input type="number" id="copies_available" name="copies_available" required min="1"
                                value="<?php echo htmlspecialchars($copies_available ?? '1'); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Additional Information -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="publisher" class="block text-sm font-medium text-gray-700 mb-2">Publisher</label>
                            <input type="text" id="publisher" name="publisher"
                                value="<?php echo htmlspecialchars($publisher ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Publisher name">
                        </div>

                        <div>
                            <label for="publication_year" class="block text-sm font-medium text-gray-700 mb-2">Publication Year</label>
                            <input type="number" id="publication_year" name="publication_year" min="1800" max="<?php echo date('Y'); ?>"
                                value="<?php echo htmlspecialchars($publication_year ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="language" class="block text-sm font-medium text-gray-700 mb-2">Language</label>
                            <select id="language" name="language"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select language...</option>
                                <?php foreach ($languages as $lang): ?>
                                    <option value="<?php echo $lang; ?>" 
                                        <?php echo (isset($language) && $language === $lang) ? 'selected' : ''; ?>>
                                        <?php echo $lang; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="location" class="block text-sm font-medium text-gray-700 mb-2">Library Location</label>
                        <input type="text" id="location" name="location"
                            value="<?php echo htmlspecialchars($location ?? ''); ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="e.g., Section A, Shelf 3">
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Brief description of the book (optional)"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-6 border-t border-gray-200">
                        <div class="flex space-x-3">
                            <a href="index.php" 
                               class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                                Cancel
                            </a>
                            <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-plus mr-2"></i>Add Book
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Quick Add Tips -->
            <div class="bg-blue-50 rounded-lg p-6 mt-6">
                <h3 class="text-lg font-semibold text-blue-900 mb-3">
                    <i class="fas fa-lightbulb mr-2"></i>Quick Tips
                </h3>
                <ul class="text-blue-800 space-y-2 text-sm">
                    <li><i class="fas fa-check mr-2"></i>Use the ISBN to automatically populate book details if available</li>
                    <li><i class="fas fa-check mr-2"></i>Specify the exact library location to help students find books easily</li>
                    <li><i class="fas fa-check mr-2"></i>Choose appropriate categories to improve search functionality</li>
                    <li><i class="fas fa-check mr-2"></i>For bulk additions, consider using the bulk import feature</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
