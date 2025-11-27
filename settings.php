<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Settings";
include 'includes/header.php';
include 'includes/sidebar.php';

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $name = $_POST['name'];
        $email = $_POST['email'];
        
        // Update profile
        $query = "UPDATE users SET name = :name, email = :email WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':id', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            $_SESSION['user_name'] = $name;
            $success_message = "Profile updated successfully!";
        } else {
            $error_message = "Error updating profile.";
        }
    }
    
    if (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Verify current password
        $query = "SELECT password FROM users WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $_SESSION['user_id']);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($current_password, $user['password'])) {
            if ($new_password === $confirm_password) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $query = "UPDATE users SET password = :password WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password', $hashed_password);
                $stmt->bindParam(':id', $_SESSION['user_id']);
                
                if ($stmt->execute()) {
                    $success_message = "Password changed successfully!";
                } else {
                    $error_message = "Error changing password.";
                }
            } else {
                $error_message = "New passwords do not match.";
            }
        } else {
            $error_message = "Current password is incorrect.";
        }
    }
}

// Get user data
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);
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
                                <h1 class="text-3xl font-bold mb-2">Settings</h1>
                                <p class="text-blue-100 text-lg">Manage your account preferences and system settings</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-cog mr-2"></i>
                                        Personal Settings
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-user mr-2"></i>
                                        <?php echo htmlspecialchars($_SESSION['name'] ?? 'User'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-cog text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                </div>
                <?php endif; ?>

                <!-- Profile Settings -->
                <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Profile Settings</h2>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <button type="submit" name="update_profile" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                                Update Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Password Change -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">Change Password</h2>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
                            <input type="password" id="current_password" name="current_password" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
                            <input type="password" id="new_password" name="new_password" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
                            <input type="password" id="confirm_password" name="confirm_password" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <button type="submit" name="change_password" class="bg-indigo-500 hover:bg-indigo-600 text-white px-6 py-2 rounded-lg">
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'])): ?>
            <!-- System Settings -->
            <div class="bg-white rounded-lg shadow overflow-hidden mt-6">
                <div class="p-6">
                    <h2 class="text-xl font-semibold text-gray-800 mb-4">System Settings</h2>
                    <form method="POST" class="space-y-4">
                        <div>
                            <label for="school_name" class="block text-sm font-medium text-gray-700 mb-1">School Name</label>
                            <input type="text" id="school_name" name="school_name" value="School Management System" 
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        </div>
                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 mb-1">Current Academic Year</label>
                            <select id="academic_year" name="academic_year" class="w-full px-4 py-2 border rounded-lg">
                                <?php
                                $current_year = date('Y');
                                for ($i = 0; $i < 3; $i++) {
                                    $year = $current_year - $i;
                                    $academic_year = $year . '-' . ($year + 1);
                                    echo "<option value='$academic_year'>$academic_year</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" name="update_system_settings" class="bg-green-500 hover:bg-green-600 text-white px-6 py-2 rounded-lg">
                                Update System Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>