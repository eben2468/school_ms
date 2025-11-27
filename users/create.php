<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $database = new Database();
    $db = $database->getConnection();

    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $role = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING);
    $status = 'active';

    try {
        $query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, :status)";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':status', $status);

        if ($stmt->execute()) {
            header("Location: index.php?success=User created successfully");
            exit();
        }
    } catch (PDOException $e) {
        $error = "Error creating user. Please try again.";
        if ($e->getCode() == 23000) { // Duplicate entry error
            $error = "Email address already exists.";
        }
    }
}
?>

<?php
$title = "Add New User";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'User Management', 'url' => 'index.php'],
    ['title' => 'Add New User']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Add New User</h1>
                                <p class="text-blue-100 text-lg">Create a new user account for the system</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-user-plus mr-2"></i>
                                        User Registration
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-shield-alt mr-2"></i>
                                        Role-based Access
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-user-plus text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between items-center mb-6">
                    <nav class="flex" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3">
                            <li class="inline-flex items-center">
                                <a href="index.php" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                                    <i class="fas fa-users mr-2"></i>
                                    User Management
                                </a>
                            </li>
                            <li>
                                <div class="flex items-center">
                                    <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Add New User</span>
                                </div>
                            </li>
                        </ol>
                    </nav>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- User Creation Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">User Information</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Fill in the details to create a new user account</p>
                    </div>

                    <form action="" method="POST" class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-user mr-2 text-blue-500"></i>Full Name *
                                </label>
                                <input type="text" id="name" name="name" required
                                    value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                                    placeholder="Enter full name"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition-colors duration-200">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-envelope mr-2 text-green-500"></i>Email Address *
                                </label>
                                <input type="email" id="email" name="email" required
                                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                    placeholder="Enter email address"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition-colors duration-200">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-lock mr-2 text-purple-500"></i>Password *
                                </label>
                                <div class="relative">
                                    <input type="password" id="password" name="password" required minlength="6"
                                        placeholder="Enter password (min. 6 characters)"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition-colors duration-200">
                                    <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                        <i id="password-icon" class="fas fa-eye text-gray-400 hover:text-gray-600"></i>
                                    </button>
                                </div>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-info-circle mr-1"></i>Minimum 6 characters required
                                </p>
                            </div>

                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-user-tag mr-2 text-orange-500"></i>User Role *
                                </label>
                                <select id="role" name="role" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition-colors duration-200">
                                    <option value="">Select a role...</option>
                                    <?php
                                    $roles = [
                                        'school_admin' => ['name' => 'School Admin', 'icon' => 'user-shield', 'color' => 'orange'],
                                        'principal' => ['name' => 'Principal', 'icon' => 'user-tie', 'color' => 'purple'],
                                        'teacher' => ['name' => 'Teacher', 'icon' => 'chalkboard-teacher', 'color' => 'blue'],
                                        'student' => ['name' => 'Student', 'icon' => 'user-graduate', 'color' => 'green'],
                                        'parent' => ['name' => 'Parent', 'icon' => 'users', 'color' => 'indigo'],
                                        'librarian' => ['name' => 'Librarian', 'icon' => 'book', 'color' => 'teal'],
                                        'accountant' => ['name' => 'Accountant', 'icon' => 'calculator', 'color' => 'yellow'],
                                        'transport_officer' => ['name' => 'Transport Officer', 'icon' => 'bus', 'color' => 'cyan'],
                                        'hostel_warden' => ['name' => 'Hostel Warden', 'icon' => 'building', 'color' => 'emerald'],
                                        'canteen_manager' => ['name' => 'Canteen Manager', 'icon' => 'utensils', 'color' => 'amber'],
                                        'nurse' => ['name' => 'Nurse', 'icon' => 'user-md', 'color' => 'pink'],
                                        'counselor' => ['name' => 'Counselor', 'icon' => 'heart', 'color' => 'red']
                                    ];

                                    if ($_SESSION['role'] === 'super_admin') {
                                        $roles = ['super_admin' => ['name' => 'Super Admin', 'icon' => 'crown', 'color' => 'red']] + $roles;
                                    }

                                    foreach ($roles as $role_key => $role_info) {
                                        $selected = isset($_POST['role']) && $_POST['role'] === $role_key ? 'selected' : '';
                                        echo "<option value=\"$role_key\" $selected>{$role_info['name']}</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <!-- Role Description -->
                        <div id="role-description" class="hidden p-4 bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <div class="flex items-start">
                                <i id="role-icon" class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                                <div>
                                    <h4 class="text-sm font-medium text-blue-900 dark:text-blue-200">Role Permissions</h4>
                                    <p id="role-text" class="text-sm text-blue-700 dark:text-blue-300 mt-1"></p>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php"
                                class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                <i class="fas fa-times mr-2"></i>Cancel
                            </a>
                            <button type="submit"
                                class="px-6 py-3 border border-transparent rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-plus mr-2"></i>Create User
                            </button>
                        </div>
                    </form>
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
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const passwordIcon = document.getElementById('password-icon');

    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        passwordIcon.classList.remove('fa-eye');
        passwordIcon.classList.add('fa-eye-slash');
    } else {
        passwordInput.type = 'password';
        passwordIcon.classList.remove('fa-eye-slash');
        passwordIcon.classList.add('fa-eye');
    }
}

// Role descriptions
const roleDescriptions = {
    'super_admin': 'Full system access with ability to manage all users, settings, and system configuration.',
    'school_admin': 'Administrative access to manage school operations, users, and academic settings.',
    'principal': 'Access to view reports, approve activities, and manage academic calendar.',
    'teacher': 'Access to mark attendance, upload grades, create assignments, and manage classes.',
    'student': 'Access to view grades, timetable, submit assignments, and view personal information.',
    'parent': 'Access to monitor student progress, receive announcements, and view fee status.',
    'librarian': 'Access to manage library inventory, issue/return books, and track library activities.',
    'accountant': 'Access to manage fee payments, generate financial reports, and handle accounting.',
    'transport_officer': 'Access to manage transport routes, vehicle maintenance, driver schedules, and student transportation.',
    'hostel_warden': 'Access to manage hostel accommodations, room assignments, student check-ins, and hostel facilities.',
    'canteen_manager': 'Access to manage canteen operations, menu planning, food inventory, and meal services.',
    'nurse': 'Access to manage health records, medical information, and health-related activities.',
    'counselor': 'Access to manage counseling sessions, student guidance, and counseling records.'
};

document.getElementById('role').addEventListener('change', function() {
    const selectedRole = this.value;
    const descriptionDiv = document.getElementById('role-description');
    const roleText = document.getElementById('role-text');

    if (selectedRole && roleDescriptions[selectedRole]) {
        roleText.textContent = roleDescriptions[selectedRole];
        descriptionDiv.classList.remove('hidden');
    } else {
        descriptionDiv.classList.add('hidden');
    }
});
</script>