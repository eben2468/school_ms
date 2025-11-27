<?php
$title = "Borrow Books";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Library', 'url' => 'index.php'],
    ['title' => 'Borrow Books']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Borrow Books</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Select a book to borrow from our library collection</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Library
                    </a>
                </div>

                <!-- Current Loans Status -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-medium text-blue-800 dark:text-blue-200">Your Current Loans</h3>
                            <p class="text-sm text-blue-700 dark:text-blue-300">
                                You have <span class="font-semibold"><?php echo $current_loans; ?></span> of <span class="font-semibold"><?php echo $max_books; ?></span> books borrowed
                            </p>
                        </div>
                        <?php if ($current_loans >= $max_books): ?>
                        <div class="text-red-600 dark:text-red-400">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            <span class="font-medium">Maximum books reached</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Search and Filter -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <form method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-1">
                            <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search Books</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                placeholder="Search by title, author, or ISBN..."
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        <div class="md:w-48">
                            <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</label>
                            <select id="category" name="category"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md shadow-sm transition-colors duration-200">
                                <i class="fas fa-search mr-2"></i>Search
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Books Grid -->
                <?php if (empty($available_books)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-book text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No books available</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">
                        <?php if ($search || $category): ?>
                            Try adjusting your search criteria.
                        <?php else: ?>
                            There are currently no books available for borrowing.
                        <?php endif; ?>
                    </p>
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])): ?>
                    <a href="books/create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center">
                        <i class="fas fa-plus mr-2"></i>Add First Book
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($available_books as $book): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-md transition-shadow duration-200">
                        <!-- Book Cover -->
                        <div class="h-48 bg-gradient-to-br from-blue-100 to-purple-100 dark:from-blue-900 dark:to-purple-900 flex items-center justify-center">
                            <i class="fas fa-book text-4xl text-blue-600 dark:text-blue-400"></i>
                        </div>
                        
                        <!-- Book Details -->
                        <div class="p-4">
                            <h3 class="font-semibold text-gray-900 dark:text-white mb-2 line-clamp-2">
                                <?php echo htmlspecialchars($book['title']); ?>
                            </h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                by <?php echo htmlspecialchars($book['author']); ?>
                            </p>
                            
                            <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400 mb-3">
                                <?php if ($book['category']): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-tag w-3 mr-2"></i>
                                    <span><?php echo htmlspecialchars($book['category']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex items-center">
                                    <i class="fas fa-copy w-3 mr-2"></i>
                                    <span><?php echo $book['copies_available']; ?> available</span>
                                </div>
                                <?php if ($book['location']): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-map-marker-alt w-3 mr-2"></i>
                                    <span><?php echo htmlspecialchars($book['location']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Action Buttons -->
                            <div class="flex space-x-2">
                                <?php if ($current_loans < $max_books): ?>
                                <a href="borrow.php?id=<?php echo $book['id']; ?>" 
                                   class="flex-1 bg-green-600 hover:bg-green-700 text-white text-sm px-3 py-2 rounded-md text-center transition-colors duration-200">
                                    <i class="fas fa-hand-holding mr-1"></i>Borrow
                                </a>
                                <?php else: ?>
                                <button disabled 
                                        class="flex-1 bg-gray-400 text-white text-sm px-3 py-2 rounded-md text-center cursor-not-allowed">
                                    <i class="fas fa-ban mr-1"></i>Limit Reached
                                </button>
                                <?php endif; ?>
                                <a href="books/view.php?id=<?php echo $book['id']; ?>" 
                                   class="bg-blue-600 hover:bg-blue-700 text-white text-sm px-3 py-2 rounded-md transition-colors duration-200">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<style>
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>
