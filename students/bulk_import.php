<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_count = 0;
$error_count = 0;
$errors = [];

// Handle file upload and processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $file_path = $file['tmp_name'];
        
        if (($handle = fopen($file_path, 'r')) !== FALSE) {
            // Skip header row
            $header = fgetcsv($handle);
            
            // Expected columns: name, email, phone, date_of_birth, gender, address, parent_name, parent_phone, parent_email, class_id
            $expected_columns = ['name', 'email', 'phone', 'date_of_birth', 'gender', 'address', 'parent_name', 'parent_phone', 'parent_email', 'class_id'];
            
            $row_number = 1;
            while (($data = fgetcsv($handle)) !== FALSE) {
                $row_number++;
                
                // Validate required fields
                if (empty($data[0]) || empty($data[1])) {
                    $errors[] = "Row $row_number: Name and email are required";
                    $error_count++;
                    continue;
                }

                // Validate class_id if provided
                if (!empty($data[9])) {
                    $class_id_check = $data[9];
                    $class_exists_query = "SELECT id FROM classes WHERE id = :class_id AND status = 'active'";
                    $class_exists_stmt = $db->prepare($class_exists_query);
                    $class_exists_stmt->bindParam(':class_id', $class_id_check);
                    $class_exists_stmt->execute();

                    if (!$class_exists_stmt->fetch()) {
                        $errors[] = "Row $row_number: Class ID $class_id_check does not exist or is inactive";
                        $error_count++;
                        continue;
                    }
                }

                try {
                    // Check if email already exists
                    $check_query = "SELECT id FROM users WHERE email = :email";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':email', $data[1]);
                    $check_stmt->execute();

                    if ($check_stmt->fetch()) {
                        $errors[] = "Row $row_number: Email {$data[1]} already exists";
                        $error_count++;
                        continue;
                    }

                    // Generate student ID using the new format STU20254927
                    $student_id = $database->generateStudentId();

                } catch (PDOException $e) {
                    $errors[] = "Row $row_number: Validation error - " . $e->getMessage();
                    $error_count++;
                    continue;
                }

                // Start transaction for database operations
                $transaction_started = false;
                try {
                    $db->beginTransaction();
                    $transaction_started = true;

                    // Generate default password
                    $default_password = 'student123';
                    $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

                    // Insert user account
                    $user_query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, 'student', 'active')";
                    $user_stmt = $db->prepare($user_query);
                    $user_stmt->bindParam(':name', $data[0]);
                    $user_stmt->bindParam(':email', $data[1]);
                    $user_stmt->bindParam(':password', $hashed_password);
                    $user_stmt->execute();

                    $user_id = $db->lastInsertId();

                    // Prepare variables for binding
                    $date_of_birth = !empty($data[3]) ? $data[3] : null;
                    $gender = !empty($data[4]) ? $data[4] : null;
                    $phone = !empty($data[2]) ? $data[2] : null;
                    $address = !empty($data[5]) ? $data[5] : null;
                    $guardian_name = !empty($data[6]) ? $data[6] : null;
                    $guardian_phone = !empty($data[7]) ? $data[7] : null;
                    $guardian_email = !empty($data[8]) ? $data[8] : null;
                    $admission_date = date('Y-m-d');

                    // Insert student profile
                    $profile_query = "INSERT INTO student_profiles (user_id, student_id, date_of_birth, gender, phone, address, guardian_name, guardian_phone, guardian_email, admission_date)
                                     VALUES (:user_id, :student_id, :date_of_birth, :gender, :phone, :address, :guardian_name, :guardian_phone, :guardian_email, :admission_date)";
                    $profile_stmt = $db->prepare($profile_query);
                    $profile_stmt->bindParam(':user_id', $user_id);
                    $profile_stmt->bindParam(':student_id', $student_id);
                    $profile_stmt->bindParam(':date_of_birth', $date_of_birth);
                    $profile_stmt->bindParam(':gender', $gender);
                    $profile_stmt->bindParam(':phone', $phone);
                    $profile_stmt->bindParam(':address', $address);
                    $profile_stmt->bindParam(':guardian_name', $guardian_name);
                    $profile_stmt->bindParam(':guardian_phone', $guardian_phone);
                    $profile_stmt->bindParam(':guardian_email', $guardian_email);
                    $profile_stmt->bindParam(':admission_date', $admission_date);
                    $profile_stmt->execute();

                    // Assign to class if provided (already validated above)
                    if (!empty($data[9])) {
                        $class_id = $data[9];
                        $class_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
                        $class_stmt = $db->prepare($class_query);
                        $class_stmt->bindParam(':student_id', $user_id);
                        $class_stmt->bindParam(':class_id', $class_id);
                        $class_stmt->execute();
                    }

                    $db->commit();
                    $success_count++;

                } catch (PDOException $e) {
                    if ($transaction_started) {
                        $db->rollBack();
                    }
                    $errors[] = "Row $row_number: Database error - " . $e->getMessage();
                    $error_count++;
                }
            }
            
            fclose($handle);
        } else {
            $error = "Could not read the uploaded file.";
        }
    } else {
        $error = "File upload failed. Please try again.";
    }
}

// Get classes for the template
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Bulk Import Students";
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
                    <h1 class="text-3xl font-semibold text-gray-800">Bulk Import Students</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Students
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <?php if ($success_count > 0 || $error_count > 0): ?>
                <div class="mb-6">
                    <?php if ($success_count > 0): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        Successfully imported <?php echo $success_count; ?> students.
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($error_count > 0): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        <p class="font-medium">Failed to import <?php echo $error_count; ?> students:</p>
                        <ul class="mt-2 list-disc list-inside text-sm">
                            <?php foreach (array_slice($errors, 0, 10) as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($errors) > 10): ?>
                            <li>... and <?php echo count($errors) - 10; ?> more errors</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Instructions -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mb-6">
                    <h2 class="text-lg font-semibold text-blue-900 mb-3">Import Instructions</h2>
                    <div class="text-blue-800 space-y-2">
                        <p>1. Download the CSV template below and fill in your student data</p>
                        <p>2. Ensure all required fields (Name, Email) are filled</p>
                        <p>3. Use the correct date format (YYYY-MM-DD) for date of birth</p>
                        <p>4. Use valid class IDs from the list below (leave empty if no class assignment needed)</p>
                        <p>5. Upload the completed CSV file</p>
                        <p><strong>Note:</strong> All imported students will have the default password "student123" and should change it on first login.</p>
                        <p><strong>Important:</strong> Invalid class IDs will cause the import to fail for that row.</p>
                    </div>
                </div>

                <!-- CSV Template Download -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Download Template</h2>
                    <p class="text-gray-600 mb-4">Download the CSV template with the correct column headers:</p>
                    <button onclick="downloadTemplate()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Download CSV Template
                    </button>
                </div>

                <!-- Available Classes -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Available Classes</h2>
                    <p class="text-gray-600 mb-4">Use these Class IDs in your CSV file:</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($classes as $class): ?>
                        <div class="border border-gray-200 rounded-lg p-3">
                            <div class="font-medium text-gray-900">ID: <?php echo $class['id']; ?></div>
                            <div class="text-sm text-gray-600">Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- File Upload Form -->
                <div class="bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Upload CSV File</h2>
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <div>
                            <label for="csv_file" class="block text-sm font-medium text-gray-700 mb-2">
                                Select CSV File
                            </label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <p class="mt-1 text-sm text-gray-500">Only CSV files are allowed. Maximum file size: 5MB</p>
                        </div>
                        
                        <div class="flex items-center">
                            <input type="checkbox" id="confirm" name="confirm" required class="mr-2">
                            <label for="confirm" class="text-sm text-gray-700">
                                I confirm that the data is accurate and I want to proceed with the import
                            </label>
                        </div>
                        
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-upload mr-2"></i>Import Students
                        </button>
                    </form>
                </div>

                <!-- Sample Data Format -->
                <div class="mt-6 bg-gray-50 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Sample Data Format</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-gray-200">
                                    <th class="px-3 py-2 text-left">name</th>
                                    <th class="px-3 py-2 text-left">email</th>
                                    <th class="px-3 py-2 text-left">phone</th>
                                    <th class="px-3 py-2 text-left">date_of_birth</th>
                                    <th class="px-3 py-2 text-left">gender</th>
                                    <th class="px-3 py-2 text-left">address</th>
                                    <th class="px-3 py-2 text-left">guardian_name</th>
                                    <th class="px-3 py-2 text-left">guardian_phone</th>
                                    <th class="px-3 py-2 text-left">guardian_email</th>
                                    <th class="px-3 py-2 text-left">class_id</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="border-b">
                                    <td class="px-3 py-2">John Doe</td>
                                    <td class="px-3 py-2">john.doe@email.com</td>
                                    <td class="px-3 py-2">+233123456789</td>
                                    <td class="px-3 py-2">2010-05-15</td>
                                    <td class="px-3 py-2">Male</td>
                                    <td class="px-3 py-2">123 Main St, Accra</td>
                                    <td class="px-3 py-2">Jane Doe</td>
                                    <td class="px-3 py-2">+233987654321</td>
                                    <td class="px-3 py-2">jane.doe@email.com</td>
                                    <td class="px-3 py-2">1</td>
                                </tr>
                                <tr>
                                    <td class="px-3 py-2">Mary Smith</td>
                                    <td class="px-3 py-2">mary.smith@email.com</td>
                                    <td class="px-3 py-2">+233111222333</td>
                                    <td class="px-3 py-2">2011-08-22</td>
                                    <td class="px-3 py-2">Female</td>
                                    <td class="px-3 py-2">456 Oak Ave, Kumasi</td>
                                    <td class="px-3 py-2">Robert Smith</td>
                                    <td class="px-3 py-2">+233444555666</td>
                                    <td class="px-3 py-2">robert.smith@email.com</td>
                                    <td class="px-3 py-2">2</td>
                                </tr>
                            </tbody>
                        </table>
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

<script>
function downloadTemplate() {
    const headers = ['name', 'email', 'phone', 'date_of_birth', 'gender', 'address', 'guardian_name', 'guardian_phone', 'guardian_email', 'class_id'];
    const sampleData = [
        ['John Doe', 'john.doe@email.com', '+233123456789', '2010-05-15', 'Male', '123 Main St, Accra', 'Jane Doe', '+233987654321', 'jane.doe@email.com', '1'],
        ['Mary Smith', 'mary.smith@email.com', '+233111222333', '2011-08-22', 'Female', '456 Oak Ave, Kumasi', 'Robert Smith', '+233444555666', 'robert.smith@email.com', '2']
    ];
    
    let csvContent = headers.join(',') + '\n';
    sampleData.forEach(row => {
        csvContent += row.map(field => `"${field}"`).join(',') + '\n';
    });
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'student_import_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>
