<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $code = $_POST['code'];
    $description = $_POST['description'];
    
    // Validate input
    $errors = [];
    if (empty($name)) $errors[] = "Subject name is required.";
    if (empty($code)) $errors[] = "Subject code is required.";
    
    // Check if subject code already exists for other subjects
    if (!empty($code)) {
        $query = "SELECT COUNT(*) as count FROM subjects WHERE code = :code AND id != :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $errors[] = "Subject code already exists.";
        }
    }
    
    if (empty($errors)) {
        $query = "UPDATE subjects SET name = :name, code = :code, description = :description WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':code', $code);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "Error updating subject.";
        }
    }
}

// Get subject data
$query = "SELECT * FROM subjects WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    header("Location: index.php");
    exit();
}

$title = "Edit Subject";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Subject</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Subjects
                    </a>
                </div>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 dark:bg-red-900 dark:border-red-700 dark:text-red-300" role="alert">
                    <strong class="font-bold">Please fix the following errors:</strong>
                    <ul class="mt-2 list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                    <form method="POST" class="p-6 space-y-6">
                        <div>
                            <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject Code*</label>
                            <input type="text" id="code" name="code" required
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                                value="<?php echo htmlspecialchars($subject['code']); ?>"
                                placeholder="e.g., MATH101">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">A unique identifier for the subject</p>
                        </div>

                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject Name*</label>
                            <input type="text" id="name" name="name" required
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                                value="<?php echo htmlspecialchars($subject['name']); ?>"
                                placeholder="e.g., Mathematics">
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
                            <textarea id="description" name="description" rows="4"
                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent dark:bg-gray-700 dark:text-white"
                                placeholder="Enter subject description..."><?php echo htmlspecialchars($subject['description']); ?></textarea>
                        </div>

                    <div class="flex justify-end space-x-4">
                        <a href="index.php" class="px-6 py-2 border rounded-lg hover:bg-gray-100">
                            Cancel
                        </a>
                        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            Update Subject
                        </button>
                    </div>
                </form>
            </div>

            <!-- Class Assignments -->
            <div class="mt-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Class Assignments</h2>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <?php
                        $query = "SELECT c.name as class_name, c.grade_level, u.name as teacher_name 
                                FROM class_teachers ct 
                                JOIN classes c ON ct.class_id = c.id 
                                JOIN users u ON ct.teacher_id = u.id 
                                WHERE ct.subject_id = :subject_id";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':subject_id', $id);
                        $stmt->execute();
                        $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (empty($assignments)): ?>
                        <p class="text-gray-500">This subject is not currently assigned to any classes.</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Grade Level</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Teacher</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($assignments as $assignment): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['grade_level']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['teacher_name']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>