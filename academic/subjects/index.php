<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Subjects Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                <h1 class="text-3xl font-semibold text-gray-800">Subjects Management</h1>
                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-plus mr-2"></i> Add New Subject
                </a>
                <?php endif; ?>
            </div>

            <!-- Search and Filters -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <form class="flex flex-col md:flex-row gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" placeholder="Search subjects..." class="w-full px-4 py-2 border rounded-lg">
                        </div>
                        <div class="flex items-center space-x-4">
                            <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white px-6 py-2 rounded-lg">
                                <i class="fas fa-search mr-2"></i> Search
                            </button>
                            <button type="reset" class="text-gray-600 hover:text-gray-800">
                                <i class="fas fa-times"></i> Clear
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Subjects Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subject Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Subject Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Assigned Classes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $search = isset($_GET['search']) ? $_GET['search'] : '';
                            
                            $query = "SELECT s.*, COUNT(ct.id) as class_count 
                                    FROM subjects s 
                                    LEFT JOIN class_teachers ct ON s.id = ct.subject_id 
                                    WHERE s.name LIKE :search OR s.code LIKE :search 
                                    GROUP BY s.id 
                                    ORDER BY s.name";
                            
                            $stmt = $db->prepare($query);
                            $searchTerm = "%$search%";
                            $stmt->bindParam(':search', $searchTerm);
                            $stmt->execute();

                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($row['code']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($row['description']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo $row['class_count']; ?> classes</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>" class="text-red-600 hover:text-red-900" 
                                       onclick="return confirm('Are you sure you want to delete this subject?')">
                                        <i class="fas fa-trash"></i> Delete
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>