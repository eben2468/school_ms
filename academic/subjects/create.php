<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $code = $_POST['code'];
    $description = $_POST['description'];
    
    // Validate input
    $errors = [];
    if (empty($name)) $errors[] = "Subject name is required.";
    if (empty($code)) $errors[] = "Subject code is required.";
    
    // Check if subject code already exists
    if (!empty($code)) {
        $query = "SELECT COUNT(*) as count FROM subjects WHERE code = :code";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':code', $code);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $errors[] = "Subject code already exists.";
        }
    }
    
    if (empty($errors)) {
        $query = "INSERT INTO subjects (name, code, description) VALUES (:name, :code, :description)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':description', $description);
        
        if ($stmt->execute()) {
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "Error creating subject.";
        }
    }
}

$title = "Create Subject";
include '../../includes/header.php';
include '../../includes/sidebar.php';
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
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Create New Subject</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Subjects
                </a>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Please fix the following errors:</strong>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form method="POST" class="p-6 space-y-6">
                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Subject Code*</label>
                        <input type="text" id="code" name="code" required
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            value="<?php echo isset($_POST['code']) ? htmlspecialchars($_POST['code']) : ''; ?>"
                            placeholder="e.g., MATH101">
                        <p class="mt-1 text-sm text-gray-500">A unique identifier for the subject</p>
                    </div>

                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Subject Name*</label>
                        <input type="text" id="name" name="name" required
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                            placeholder="e.g., Mathematics">
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            placeholder="Enter subject description..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>

                    <div class="flex justify-end space-x-4">
                        <button type="reset" class="px-6 py-2 border rounded-lg hover:bg-gray-100">
                            Reset
                        </button>
                        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            Create Subject
                        </button>
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