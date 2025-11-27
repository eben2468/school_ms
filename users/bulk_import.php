<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['csv_file']['tmp_name'];
        $handle = fopen($file, 'r');
        
        if ($handle !== FALSE) {
            $header = fgetcsv($handle); // Skip header row
            $imported = 0;
            $failed = 0;
            
            while (($data = fgetcsv($handle)) !== FALSE) {
                try {
                    $name = trim($data[0]);
                    $email = trim($data[1]);
                    $role = trim($data[2]);
                    $password = trim($data[3]) ?: 'password123';

                    // Validate required fields
                    if (empty($name) || empty($email) || empty($role)) {
                        $failed++;
                        continue;
                    }

                    // Validate email format
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $failed++;
                        continue;
                    }

                    // Validate role
                    $valid_roles = ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor'];
                    if (!in_array($role, $valid_roles)) {
                        $failed++;
                        continue;
                    }
                    
                    // Check if user already exists
                    $check_query = "SELECT id FROM users WHERE email = :email";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':email', $email);
                    $check_stmt->execute();
                    
                    if ($check_stmt->rowCount() > 0) {
                        $failed++;
                        continue;
                    }
                    
                    // Insert user
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, 'active')";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':password', $hashed_password);
                    $stmt->bindParam(':role', $role);
                    $stmt->execute();
                    
                    $imported++;
                } catch (Exception $e) {
                    $failed++;
                }
            }
            
            fclose($handle);
            $success = "Import completed. $imported users imported successfully, $failed failed.";
        } else {
            $errors[] = "Could not read the uploaded file.";
        }
    } else {
        $errors[] = "Please select a valid CSV file.";
    }
}

$title = "Bulk Import Users";
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
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800">Bulk Import Users</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
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

                <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>

                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Upload CSV File</h3>
                        
                        <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
                            <div>
                                <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">CSV File</label>
                                <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <p class="text-sm text-gray-500 mt-1">Select a CSV file with user data</p>
                            </div>

                            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <h4 class="font-medium text-blue-800">CSV Format Requirements</h4>
                                    <a href="sample_users.csv" download class="text-sm bg-blue-600 text-white px-3 py-1 rounded hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-download mr-1"></i>Download Sample
                                    </a>
                                </div>
                                <p class="text-sm text-blue-700 mb-2">Your CSV file should have the following columns in order:</p>
                                <ol class="text-sm text-blue-700 list-decimal list-inside space-y-1">
                                    <li><strong>Name</strong> - Full name of the user</li>
                                    <li><strong>Email</strong> - Valid email address (must be unique)</li>
                                    <li><strong>Role</strong> - One of: student, teacher, parent, super_admin, school_admin, principal, librarian, accountant, transport_officer, hostel_warden, canteen_manager, nurse, counselor</li>
                                    <li><strong>Password</strong> - Optional (defaults to 'password123' if empty)</li>
                                </ol>
                                <p class="text-sm text-blue-700 mt-2">
                                    <strong>Example:</strong><br>
                                    John Doe,john@example.com,student,mypassword<br>
                                    Jane Smith,jane@example.com,teacher,<br>
                                    Lisa Davis,lisa.davis@school.com,transport_officer,transport123
                                </p>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" 
                                    class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Cancel
                                </a>
                                <button type="submit"
                                    class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                                    Import Users
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
