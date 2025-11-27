<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$import_type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'students';

// Handle file upload and import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['import_file'])) {
    $file = $_FILES['import_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_extension === 'csv') {
            try {
                $handle = fopen($file['tmp_name'], 'r');
                $header = fgetcsv($handle); // Read header row
                $imported = 0;
                $errors = [];
                
                $db->beginTransaction();
                
                while (($data = fgetcsv($handle)) !== FALSE) {
                    try {
                        if ($import_type === 'students') {
                            // Import students
                            $name = $data[0] ?? '';
                            $email = $data[1] ?? '';
                            $student_id = $data[2] ?? '';
                            $class_id = $data[3] ?? '';
                            $phone = $data[4] ?? '';
                            
                            if (empty($name) || empty($email)) {
                                $errors[] = "Row " . ($imported + 1) . ": Name and email are required";
                                continue;
                            }
                            
                            // Create user account
                            $password = password_hash('password123', PASSWORD_DEFAULT);
                            $user_query = "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')";
                            $user_stmt = $db->prepare($user_query);
                            $user_stmt->execute([$name, $email, $password]);
                            $user_id = $db->lastInsertId();
                            
                            // Create student profile
                            $profile_query = "INSERT INTO student_profiles (user_id, student_id, admission_date, phone) VALUES (?, ?, CURDATE(), ?)";
                            $profile_stmt = $db->prepare($profile_query);
                            $profile_stmt->execute([$user_id, $student_id, $phone]);
                            
                            // Assign to class if provided
                            if (!empty($class_id)) {
                                $class_query = "INSERT INTO student_classes (student_id, class_id) VALUES (?, ?)";
                                $class_stmt = $db->prepare($class_query);
                                $class_stmt->execute([$user_id, $class_id]);
                            }
                            
                        } elseif ($import_type === 'teachers') {
                            // Import teachers
                            $name = $data[0] ?? '';
                            $email = $data[1] ?? '';
                            $employee_id = $data[2] ?? '';
                            $department = $data[3] ?? '';
                            $phone = $data[4] ?? '';
                            
                            if (empty($name) || empty($email)) {
                                $errors[] = "Row " . ($imported + 1) . ": Name and email are required";
                                continue;
                            }
                            
                            // Create user account
                            $password = password_hash('password123', PASSWORD_DEFAULT);
                            $user_query = "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'teacher')";
                            $user_stmt = $db->prepare($user_query);
                            $user_stmt->execute([$name, $email, $password]);
                            $user_id = $db->lastInsertId();
                            
                            // Create teacher profile
                            $profile_query = "INSERT INTO teacher_profiles (user_id, employee_id, department, phone, joining_date) VALUES (?, ?, ?, ?, CURDATE())";
                            $profile_stmt = $db->prepare($profile_query);
                            $profile_stmt->execute([$user_id, $employee_id, $department, $phone]);
                            
                        } elseif ($import_type === 'books') {
                            // Import library books
                            $title = $data[0] ?? '';
                            $author = $data[1] ?? '';
                            $isbn = $data[2] ?? '';
                            $category = $data[3] ?? '';
                            $copies = $data[4] ?? 1;
                            
                            if (empty($title) || empty($author)) {
                                $errors[] = "Row " . ($imported + 1) . ": Title and author are required";
                                continue;
                            }
                            
                            $book_query = "INSERT INTO library_books (title, author, isbn, category, copies_available) VALUES (?, ?, ?, ?, ?)";
                            $book_stmt = $db->prepare($book_query);
                            $book_stmt->execute([$title, $author, $isbn, $category, $copies]);
                        }
                        
                        $imported++;
                        
                    } catch (PDOException $e) {
                        $errors[] = "Row " . ($imported + 1) . ": " . $e->getMessage();
                    }
                }
                
                fclose($handle);
                $db->commit();
                
                $success = "Successfully imported $imported records.";
                if (!empty($errors)) {
                    $success .= " " . count($errors) . " errors occurred.";
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Import failed: " . $e->getMessage();
            }
        } else {
            $error = "Please upload a CSV file.";
        }
    } else {
        $error = "File upload failed.";
    }
}

// Get available classes for student import
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-4xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Bulk Import</h1>
                <a href="dashboard.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                </a>
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

            <?php if (isset($errors) && !empty($errors)): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                <p class="font-semibold">Import Errors:</p>
                <ul class="list-disc list-inside mt-2">
                    <?php foreach (array_slice($errors, 0, 10) as $error_msg): ?>
                    <li class="text-sm"><?php echo htmlspecialchars($error_msg); ?></li>
                    <?php endforeach; ?>
                    <?php if (count($errors) > 10): ?>
                    <li class="text-sm">... and <?php echo count($errors) - 10; ?> more errors</li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- Import Type Selection -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Select Import Type</h3>
                    <div class="flex space-x-4">
                        <a href="?type=students" 
                           class="px-4 py-2 rounded-lg <?php echo $import_type === 'students' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Students
                        </a>
                        <a href="?type=teachers" 
                           class="px-4 py-2 rounded-lg <?php echo $import_type === 'teachers' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Teachers
                        </a>
                        <a href="?type=books" 
                           class="px-4 py-2 rounded-lg <?php echo $import_type === 'books' ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                            Library Books
                        </a>
                    </div>
                </div>
            </div>

            <!-- Import Instructions -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">CSV Format Requirements</h3>
                    
                    <?php if ($import_type === 'students'): ?>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-700 mb-2"><strong>Student Import Format:</strong></p>
                        <p class="text-sm text-gray-600 mb-2">CSV columns: Name, Email, Student ID, Class ID (optional), Phone (optional)</p>
                        <p class="text-sm text-gray-600">Example: John Doe, john@example.com, STU001, 1, +1234567890</p>
                        <p class="text-sm text-gray-500 mt-2">Default password: password123 (users should change on first login)</p>
                    </div>
                    
                    <?php elseif ($import_type === 'teachers'): ?>
                    <div class="bg-green-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-700 mb-2"><strong>Teacher Import Format:</strong></p>
                        <p class="text-sm text-gray-600 mb-2">CSV columns: Name, Email, Employee ID, Department (optional), Phone (optional)</p>
                        <p class="text-sm text-gray-600">Example: Jane Smith, jane@example.com, EMP001, Mathematics, +1234567890</p>
                        <p class="text-sm text-gray-500 mt-2">Default password: password123 (users should change on first login)</p>
                    </div>
                    
                    <?php elseif ($import_type === 'books'): ?>
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <p class="text-sm text-gray-700 mb-2"><strong>Library Books Import Format:</strong></p>
                        <p class="text-sm text-gray-600 mb-2">CSV columns: Title, Author, ISBN (optional), Category (optional), Copies Available</p>
                        <p class="text-sm text-gray-600">Example: "To Kill a Mockingbird", "Harper Lee", "978-0-06-112008-4", "Fiction", 5</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Upload Form -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Upload CSV File</h3>
                    
                    <form action="" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label for="import_file" class="block text-sm font-medium text-gray-700 mb-2">
                                Select CSV File
                            </label>
                            <input type="file" id="import_file" name="import_file" accept=".csv" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div class="flex justify-end">
                            <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-upload mr-2"></i>Import Data
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
