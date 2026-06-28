<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
require_once 'includes/csrf.php';
require_once 'includes/password_policy.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('profile.php');

    // Fetch existing records for fallback
    $ex_stmt = $db->prepare("SELECT u.name, u.email FROM users u WHERE u.id = :id");
    $ex_stmt->execute([':id' => $user_id]);
    $ex = $ex_stmt->fetch(PDO::FETCH_ASSOC);

    $name = !empty(trim($_POST['name'] ?? '')) ? filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) : $ex['name'];
    $email = !empty(trim($_POST['email'] ?? '')) ? filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) : $ex['email'];
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    try {
        $db->beginTransaction();

        $profile_picture_path = null;

        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/profile_pictures/';
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB

            $file = $_FILES['profile_picture'];
            $file_type = $file['type'];
            $file_size = $file['size'];

            // Validate file type
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception("Invalid file type. Only JPEG, PNG, and GIF are allowed.");
            }

            // Validate file size
            if ($file_size > $max_size) {
                throw new Exception("File size too large. Maximum size is 5MB.");
            }

            // Generate unique filename
            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'profile_' . $user_id . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $filename;

            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                $profile_picture_path = $filename;

                // Delete old profile picture if exists
                $old_picture_query = "SELECT profile_picture FROM users WHERE id = :user_id";
                $old_picture_stmt = $db->prepare($old_picture_query);
                $old_picture_stmt->bindParam(':user_id', $user_id);
                $old_picture_stmt->execute();
                $old_picture = $old_picture_stmt->fetchColumn();

                if ($old_picture && file_exists($upload_dir . $old_picture)) {
                    unlink($upload_dir . $old_picture);
                }
            } else {
                throw new Exception("Failed to upload profile picture.");
            }
        }

        // Update basic info
        if ($profile_picture_path) {
            $query = "UPDATE users SET name = :name, email = :email, profile_picture = :profile_picture WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':profile_picture', $profile_picture_path);
        } else {
            $query = "UPDATE users SET name = :name, email = :email WHERE id = :user_id";
            $stmt = $db->prepare($query);
        }
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        // Update password if provided
        if (!empty($new_password)) {
            // Verify current password
            $verify_query = "SELECT password FROM users WHERE id = :user_id";
            $verify_stmt = $db->prepare($verify_query);
            $verify_stmt->bindParam(':user_id', $user_id);
            $verify_stmt->execute();
            $user_data = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!password_verify($current_password, $user_data['password'])) {
                throw new Exception("Current password is incorrect.");
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception("New passwords do not match.");
            }
            
            $pw_check = validatePasswordStrength($new_password);
            if (!$pw_check['valid']) {
                throw new Exception($pw_check['message']);
            }
            
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $password_query = "UPDATE users SET password = :password WHERE id = :user_id";
            $password_stmt = $db->prepare($password_query);
            $password_stmt->bindParam(':password', $hashed_password);
            $password_stmt->bindParam(':user_id', $user_id);
            $password_stmt->execute();
        }
        
        $db->commit();
        $_SESSION['name'] = $name;
        $_SESSION['user_name'] = $name; // Update both session variables
        $_SESSION['email'] = $email;
        if ($profile_picture_path) {
            $_SESSION['profile_picture'] = $profile_picture_path;
        }
        $success = "Profile updated successfully.";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Get user details
$query = "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.updated_at,
          COALESCE(u.profile_picture, '') as profile_picture,
          CASE
            WHEN u.role = 'student' THEN sp.student_id
            WHEN u.role = 'teacher' THEN tp.employee_id
            ELSE NULL
          END as identifier
          FROM users u
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
          WHERE u.id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: index.php");
    exit();
}

// Ensure profile_picture key exists to prevent undefined key warnings
if (!isset($user['profile_picture'])) {
    $user['profile_picture'] = '';
}
?>

<?php include 'includes/header.php'; ?>
<?php include 'includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

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
                            <h1 class="text-3xl font-bold mb-2">My Profile</h1>
                            <p class="text-blue-100 text-lg">Manage your personal information and account settings</p>
                            <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                <div class="flex items-center">
                                    <i class="fas fa-user mr-2"></i>
                                    <?php echo htmlspecialchars($user['name']); ?>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-envelope mr-2"></i>
                                    <?php echo htmlspecialchars($user['email']); ?>
                                </div>
                            </div>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-user-circle text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-between items-center mb-6">
                <div></div>
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Profile Info Card -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-200">
                        <div class="text-center">
                            <div class="w-24 h-24 rounded-full mx-auto mb-4 overflow-hidden border-4 border-white shadow-lg">
                                <?php if (!empty($user['profile_picture'])): ?>
                                    <img src="serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>"
                                         alt="Profile Picture"
                                         class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 flex items-center justify-center">
                                        <i class="fas fa-user text-white text-3xl"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($user['name']); ?></h3>
                            <p class="text-gray-600 capitalize"><?php echo str_replace('_', ' ', $user['role']); ?></p>
                            <?php if ($user['identifier']): ?>
                            <p class="text-sm text-gray-500 mt-2">
                                ID: <?php echo htmlspecialchars($user['identifier']); ?>
                            </p>
                            <?php endif; ?>
                            <div class="mt-4 pt-4 border-t border-gray-200">
                                <div class="flex items-center justify-center text-sm text-gray-600">
                                    <i class="fas fa-calendar mr-2"></i>
                                    Member since <?php echo date('M Y', strtotime($user['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Form -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">Profile Information</h3>
                            <p class="text-gray-600 text-sm">Update your account information and password.</p>
                        </div>
                        
                        <form action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                            <!-- Profile Picture Upload -->
                            <div class="border-b border-gray-200 pb-6">
                                <h4 class="text-md font-medium text-gray-900 mb-4">Profile Picture</h4>
                                <div class="flex items-center space-x-6">
                                    <div class="w-20 h-20 rounded-full overflow-hidden border-2 border-gray-300">
                                        <img id="profilePicturePreview"
                                             src="<?php echo !empty($user['profile_picture']) ? 'serve_image.php?path=profile_pictures/' . htmlspecialchars($user['profile_picture']) : '#'; ?>"
                                             alt="Current Profile Picture"
                                             class="w-full h-full object-cover <?php echo empty($user['profile_picture']) ? 'hidden' : ''; ?>">
                                        <div id="profilePictureIcon" class="w-full h-full bg-gradient-to-r from-blue-500 to-purple-600 flex items-center justify-center <?php echo !empty($user['profile_picture']) ? 'hidden' : ''; ?>">
                                            <i class="fas fa-user text-white text-lg"></i>
                                        </div>
                                    </div>
                                    <div class="flex-1">
                                        <input type="file" id="profile_picture" name="profile_picture" accept="image/*"
                                            data-cropper data-crop-preview="#profilePicturePreview" data-crop-icon="#profilePictureIcon"
                                            class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                        <p class="text-xs text-gray-500 mt-2">Upload a new profile picture (JPEG, PNG, GIF - Max 5MB). You can crop it to frame the face before saving.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Full Name</label>
                                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address</label>
                                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Password Change Section -->
                            <div class="border-t border-gray-200 pt-6">
                                <h4 class="text-md font-medium text-gray-900 mb-4">Change Password</h4>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <div>
                                        <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">Current Password</label>
                                        <div class="relative">
                                            <input type="password" id="current_password" name="current_password"
                                                class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <button type="button" onclick="togglePasswordVisibility('current_password', this)"
                                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 focus:outline-none"
                                                aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                                        <div class="relative">
                                            <input type="password" id="new_password" name="new_password"
                                                class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <button type="button" onclick="togglePasswordVisibility('new_password', this)"
                                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 focus:outline-none"
                                                aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                                        <div class="relative">
                                            <input type="password" id="confirm_password" name="confirm_password"
                                                class="w-full px-3 py-2 pr-10 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                            <button type="button" onclick="togglePasswordVisibility('confirm_password', this)"
                                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 focus:outline-none"
                                                aria-label="Show password">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-500 mt-2">Leave password fields empty if you don't want to change your password.</p>
                            </div>

                            <!-- Submit Button -->
                            <div class="flex justify-end pt-6 border-t border-gray-200">
                                <button type="submit" 
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200">
                                    <i class="fas fa-save mr-2"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(fieldId, btn) {
    const input = document.getElementById(fieldId);
    if (!input) return;
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        if (icon) icon.classList.replace('fa-eye', 'fa-eye-slash');
        btn.setAttribute('aria-label', 'Hide password');
    } else {
        input.type = 'password';
        if (icon) icon.classList.replace('fa-eye-slash', 'fa-eye');
        btn.setAttribute('aria-label', 'Show password');
    }
}
</script>
