<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $date_of_birth = filter_input(INPUT_POST, 'date_of_birth', FILTER_SANITIZE_STRING);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
    $parent_name = filter_input(INPUT_POST, 'parent_name', FILTER_SANITIZE_STRING);
    $parent_phone = filter_input(INPUT_POST, 'parent_phone', FILTER_SANITIZE_STRING);
    $parent_email = filter_input(INPUT_POST, 'parent_email', FILTER_SANITIZE_EMAIL);
    $admission_date = filter_input(INPUT_POST, 'admission_date', FILTER_SANITIZE_STRING);

    if ($name && $email && $password && $class_id) {
        try {
            $db->beginTransaction();

            // Generate student ID using the new format STU20254927
            $student_id = $database->generateStudentId();

            // Create user account
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $user_query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, 'student', 'active')";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindParam(':name', $name);
            $user_stmt->bindParam(':email', $email);
            $user_stmt->bindParam(':password', $hashed_password);
            $user_stmt->execute();

            $user_id = $db->lastInsertId();

            // Create student profile
            $profile_query = "INSERT INTO student_profiles (user_id, student_id, date_of_birth, gender, phone, address, parent_name, parent_phone, parent_email, admission_date) 
                             VALUES (:user_id, :student_id, :date_of_birth, :gender, :phone, :address, :parent_name, :parent_phone, :parent_email, :admission_date)";
            $profile_stmt = $db->prepare($profile_query);
            $profile_stmt->bindParam(':user_id', $user_id);
            $profile_stmt->bindParam(':student_id', $student_id);
            $profile_stmt->bindParam(':date_of_birth', $date_of_birth);
            $profile_stmt->bindParam(':gender', $gender);
            $profile_stmt->bindParam(':phone', $phone);
            $profile_stmt->bindParam(':address', $address);
            $profile_stmt->bindParam(':parent_name', $parent_name);
            $profile_stmt->bindParam(':parent_phone', $parent_phone);
            $profile_stmt->bindParam(':parent_email', $parent_email);
            $profile_stmt->bindParam(':admission_date', $admission_date);
            $profile_stmt->execute();

            // Assign to class
            $class_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
            $class_stmt = $db->prepare($class_query);
            $class_stmt->bindParam(':student_id', $user_id);
            $class_stmt->bindParam(':class_id', $class_id);
            $class_stmt->execute();

            $db->commit();
            $success_message = "Student created successfully with ID: $student_id";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = "Error creating student: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get classes for dropdown
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Student";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Create New Student</h1>
                                <p class="text-blue-100 text-lg">Add a new student to the school system</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-user-graduate text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Students</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Create Student</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Student Information</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Fill in the student's personal and academic details</p>
                    </div>

                    <form method="POST" class="p-6 space-y-8">
                        <!-- Personal Information -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Personal Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Full Name -->
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Full Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="name" name="name" required
                                        placeholder="Enter student's full name"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Student ID Info -->
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Student ID
                                    </label>
                                    <div class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-600 text-gray-600 dark:text-gray-300">
                                        Will be auto-generated (Format: STU20254927)
                                    </div>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="email" name="email" required
                                        placeholder="student@example.com"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Password -->
                                <div>
                                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Password <span class="text-red-500">*</span>
                                    </label>
                                    <input type="password" id="password" name="password" required
                                        placeholder="Enter password"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Date of Birth -->
                                <div>
                                    <label for="date_of_birth" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Date of Birth
                                    </label>
                                    <input type="date" id="date_of_birth" name="date_of_birth"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Gender -->
                                <div>
                                    <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Gender
                                    </label>
                                    <select id="gender" name="gender"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select gender</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <!-- Phone -->
                                <div>
                                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Phone Number
                                    </label>
                                    <input type="tel" id="phone" name="phone"
                                        placeholder="Enter phone number"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Class -->
                                <div>
                                    <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Class <span class="text-red-500">*</span>
                                    </label>
                                    <select id="class_id" name="class_id" required
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select class</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            Grade <?php echo $class['grade_level']; ?> - <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <!-- Address -->
                            <div class="mt-6">
                                <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Address
                                </label>
                                <textarea id="address" name="address" rows="3"
                                    placeholder="Enter full address"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                            </div>
                        </div>

                        <!-- Parent/Guardian Information -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Parent/Guardian Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Parent Name -->
                                <div>
                                    <label for="parent_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Parent/Guardian Name
                                    </label>
                                    <input type="text" id="parent_name" name="parent_name"
                                        placeholder="Enter parent/guardian name"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Parent Phone -->
                                <div>
                                    <label for="parent_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Parent/Guardian Phone
                                    </label>
                                    <input type="tel" id="parent_phone" name="parent_phone"
                                        placeholder="Enter parent/guardian phone"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Parent Email -->
                                <div>
                                    <label for="parent_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Parent/Guardian Email
                                    </label>
                                    <input type="email" id="parent_email" name="parent_email"
                                        placeholder="parent@example.com"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Admission Date -->
                                <div>
                                    <label for="admission_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Admission Date
                                    </label>
                                    <input type="date" id="admission_date" name="admission_date"
                                        value="<?php echo date('Y-m-d'); ?>"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" 
                                class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-save mr-2"></i>
                                Create Student
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('name').focus();
});

// Student ID will be auto-generated server-side
</script>
