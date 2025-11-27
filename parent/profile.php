<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$parent_id = $_SESSION['user_id'];
$message = '';
$message_type = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        
        if (empty($name) || empty($email)) {
            throw new Exception("Name and email are required.");
        }
        
        // Check if email is already taken by another user
        $email_check = "SELECT id FROM users WHERE email = :email AND id != :user_id";
        $email_stmt = $db->prepare($email_check);
        $email_stmt->bindParam(':email', $email);
        $email_stmt->bindParam(':user_id', $parent_id);
        $email_stmt->execute();
        
        if ($email_stmt->rowCount() > 0) {
            throw new Exception("Email address is already in use by another user.");
        }
        
        // Update user profile
        $update_query = "UPDATE users SET name = :name, email = :email WHERE id = :user_id";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':name', $name);
        $update_stmt->bindParam(':email', $email);
        $update_stmt->bindParam(':user_id', $parent_id);
        $update_stmt->execute();
        
        // Update session name
        $_SESSION['user_name'] = $name;
        
        $message = "Profile updated successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error updating profile: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            throw new Exception("All password fields are required.");
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception("New passwords do not match.");
        }
        
        if (strlen($new_password) < 6) {
            throw new Exception("New password must be at least 6 characters long.");
        }
        
        // Verify current password
        $verify_query = "SELECT password FROM users WHERE id = :user_id";
        $verify_stmt = $db->prepare($verify_query);
        $verify_stmt->bindParam(':user_id', $parent_id);
        $verify_stmt->execute();
        $user_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!password_verify($current_password, $user_data['password'])) {
            throw new Exception("Current password is incorrect.");
        }
        
        // Update password
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $password_query = "UPDATE users SET password = :password WHERE id = :user_id";
        $password_stmt = $db->prepare($password_query);
        $password_stmt->bindParam(':password', $hashed_password);
        $password_stmt->bindParam(':user_id', $parent_id);
        $password_stmt->execute();
        
        $message = "Password changed successfully!";
        $message_type = "success";
        
    } catch (Exception $e) {
        $message = "Error changing password: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get parent information
$parent_query = "SELECT * FROM users WHERE id = :parent_id";
$parent_stmt = $db->prepare($parent_query);
$parent_stmt->bindParam(':parent_id', $parent_id);
$parent_stmt->execute();
$parent_info = $parent_stmt->fetch(PDO::FETCH_ASSOC);

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.email, ps.relationship, ps.is_primary,
           c.name as class_name, c.grade_level
    FROM users u
    JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.status = 'active'
    ORDER BY ps.is_primary DESC, u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $parent_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "My Profile";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-4xl mx-auto">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">My Profile</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage your account information and settings</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Message -->
                <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-lg <?php 
                    echo $message_type === 'success' ? 'bg-green-50 border border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800 dark:text-green-200' : 
                        'bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200'; 
                ?>">
                    <div class="flex items-start">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'times-circle'; ?> mt-1 mr-3"></i>
                        <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Profile Information -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">Profile Information</h3>
                            
                            <form method="POST">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Full Name *</label>
                                        <input type="text" name="name" value="<?php echo htmlspecialchars($parent_info['name']); ?>" required
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address *</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($parent_info['email']); ?>" required
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone Number</label>
                                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($parent_info['phone'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                                        <input type="text" value="<?php echo ucfirst($parent_info['status']); ?>" readonly
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-600 text-gray-500 dark:text-gray-400">
                                    </div>
                                </div>
                                
                                <div class="mt-6">
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                                    <textarea name="address" rows="3"
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($parent_info['address'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="mt-6">
                                    <button type="submit" name="update_profile" 
                                            class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                                        <i class="fas fa-save mr-2"></i>Update Profile
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- Change Password -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">Change Password</h3>
                            
                            <form method="POST">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Current Password</label>
                                        <input type="password" name="current_password" required
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">New Password</label>
                                        <input type="password" name="new_password" required minlength="6"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Confirm New Password</label>
                                        <input type="password" name="confirm_password" required minlength="6"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                                
                                <div class="mt-6">
                                    <button type="submit" name="change_password" 
                                            class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200">
                                        <i class="fas fa-key mr-2"></i>Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sidebar Info -->
                    <div class="space-y-6">
                        <!-- Account Summary -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Account Summary</h3>
                            <div class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Role</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">Parent</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Member Since</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo date('M Y', strtotime($parent_info['created_at'])); ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Children</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white"><?php echo count($children); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- My Children -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">My Children</h3>
                            <?php if (empty($children)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No children linked to your account.</p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($children as $child): ?>
                                <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3">
                                        <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-sm"></i>
                                    </div>
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($child['name']); ?>
                                            <?php if ($child['is_primary']): ?>
                                            <span class="ml-1 text-xs bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 px-1 rounded">Primary</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            <?php echo ucfirst($child['relationship']); ?>
                                            <?php if ($child['class_name']): ?>
                                            • <?php echo htmlspecialchars($child['class_name']); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
