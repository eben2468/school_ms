<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs missing newer teacher_profiles columns before creating staff.
ensureTeacherProfileColumns($db);

// Fetch departments for dropdown
try {
    $dept_stmt = $db->query("SELECT id, name FROM staff_departments WHERE status = 'active' ORDER BY name");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Generate Employee ID
$year = date('Y');
$like_pattern = "EMP" . $year . "%";
try {
    $emp_query = "SELECT employee_id FROM teacher_profiles WHERE employee_id LIKE :pattern ORDER BY employee_id DESC LIMIT 1";
    $emp_stmt = $db->prepare($emp_query);
    $emp_stmt->execute([':pattern' => $like_pattern]);
    $last_emp = $emp_stmt->fetchColumn();

    if ($last_emp && preg_match('/^EMP\d{4}(\d+)$/', $last_emp, $matches)) {
        $next_num = (int)$matches[1] + 1;
    } else {
        $next_num = 1;
    }
    $employee_id = 'EMP' . $year . str_pad($next_num, 4, '0', STR_PAD_LEFT);
} catch (PDOException $e) {
    $employee_id = 'EMP' . $year . '0001';
}

$role_labels = [
    'teacher'           => 'Teacher',
    'librarian'         => 'Librarian',
    'accountant'        => 'Accountant',
    'nurse'             => 'Nurse',
    'counselor'         => 'Counselor',
    'transport_officer' => 'Transport Officer',
    'hostel_warden'     => 'Hostel Warden',
    'canteen_manager'   => 'Canteen Manager',
    'hr'                => 'Human Resource',
];

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honour the User Registration system setting before creating an account.
    require_once '../includes/settings_helper.php';
    if (!isUserRegistrationAllowed()) {
        $errors[] = "New user registration is currently disabled in System Settings.";
    }
    // Enforce the school's subscription plan staff capacity.
    require_once '../includes/plan_limits.php';
    $__cap = checkStaffCapacity($db, $_SESSION['school_id'] ?? 0, 1);
    if (!$__cap['allowed']) {
        $errors[] = planCapacityMessage('staff', $__cap);
    }
    // 1. Sanitize & retrieve inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $other_names = trim($_POST['other_names'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $employee_id_field = trim($_POST['employee_id'] ?? '');
    $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $date_of_birth = $_POST['date_of_birth'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $nationality = trim($_POST['nationality'] ?? '');
    $marital_status = $_POST['marital_status'] ?? '';
    $national_id = trim($_POST['national_id'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state_region = trim($_POST['state_region'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');

    $role = $_POST['role'] ?? '';
    $department_id = $_POST['department_id'] ?? '';
    $position = trim($_POST['position'] ?? '');
    $contract_type = $_POST['contract_type'] ?? '';
    $joining_date = $_POST['joining_date'] ?? '';
    $qualification = trim($_POST['qualification'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $experience_years = $_POST['experience_years'] ?? '';
    $salary = $_POST['salary'] ?? '';
    $bio = trim($_POST['bio'] ?? '');

    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_account = trim($_POST['bank_account'] ?? '');
    $bank_branch = trim($_POST['bank_branch'] ?? '');
    
    $emergency_contact_name = trim($_POST['emergency_contact_name'] ?? '');
    $emergency_contact_phone = trim($_POST['emergency_contact_phone'] ?? '');
    $emergency_contact_relation = trim($_POST['emergency_contact_relation'] ?? '');

    // 2. Validate
    if (empty($first_name)) {
        $errors[] = "First name is required.";
    }
    if (empty($last_name)) {
        $errors[] = "Last name is required.";
    }
    if (empty($employee_id_field)) {
        $errors[] = "Employee ID is required.";
    } else {
        $emp_check_stmt = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE employee_id = :employee_id");
        $emp_check_stmt->execute([':employee_id' => $employee_id_field]);
        if ($emp_check_stmt->fetchColumn() > 0) {
            $errors[] = "The Employee ID is already in use by another staff member.";
        }
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    } else {
        // Email uniqueness check
        $email_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $email_stmt->execute([':email' => $email]);
        if ($email_stmt->fetchColumn() > 0) {
            $errors[] = "The email address is already in use by another user.";
        }
    }

    if (empty($password) || strlen($password) < 8) {
        $errors[] = "Password is required and must be at least 8 characters long.";
    }

    if (empty($role) || !array_key_exists($role, $role_labels)) {
        $errors[] = "Please select a valid staff role.";
    }

    if (empty($joining_date)) {
        $errors[] = "Joining date is required.";
    }

    // 3. Process
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Hash password
            $hashed_password = password_hash($password, PASSWORD_BCRYPT);

            // Handle Profile Picture Upload
            $profile_picture = null;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (in_array($_FILES['profile_picture']['type'], $allowed_types)) {
                    $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('profile_') . '.' . $ext;
                    $upload_dir = '../uploads/profile_pictures/';
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $filename)) {
                        $profile_picture = $filename;
                    }
                }
            }

            // Insert into users
            $user_stmt = $db->prepare("INSERT INTO users (name, first_name, other_names, last_name, email, password, role, status, profile_picture) VALUES (:name, :first_name, :other_names, :last_name, :email, :password, :role, 'active', :profile_picture)");
            $user_stmt->execute([
                ':name' => $name,
                ':first_name' => $first_name,
                ':other_names' => $other_names,
                ':last_name' => $last_name,
                ':email' => $email,
                ':password' => $hashed_password,
                ':role' => $role,
                ':profile_picture' => $profile_picture
            ]);
            $user_id = $db->lastInsertId();

            // Find department name
            $dept_name = null;
            if (!empty($department_id)) {
                $dept_name_stmt = $db->prepare("SELECT name FROM staff_departments WHERE id = :id");
                $dept_name_stmt->execute([':id' => $department_id]);
                $dept_name = $dept_name_stmt->fetchColumn();
            }

            // Re-generate employee ID if empty just in case to avoid race conditions
            if (empty($employee_id_field)) {
                $emp_stmt->execute([':pattern' => $like_pattern]);
                $last_emp = $emp_stmt->fetchColumn();
                if ($last_emp && preg_match('/^EMP\d{4}(\d+)$/', $last_emp, $matches)) {
                    $next_num = (int)$matches[1] + 1;
                } else {
                    $next_num = 1;
                }
                $employee_id_field = 'EMP' . $year . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            }

            // Insert into teacher_profiles
            $profile_stmt = $db->prepare("INSERT INTO teacher_profiles (
                user_id, employee_id, date_of_birth, gender, phone, address,
                qualification, experience_years, joining_date, salary,
                department, department_id, position, national_id, marital_status,
                nationality, city, state_region, postal_code,
                emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                bank_name, bank_account, bank_branch, specialization, contract_type, bio
            ) VALUES (
                :user_id, :employee_id, :date_of_birth, :gender, :phone, :address,
                :qualification, :experience_years, :joining_date, :salary,
                :department, :department_id, :position, :national_id, :marital_status,
                :nationality, :city, :state_region, :postal_code,
                :emergency_contact_name, :emergency_contact_phone, :emergency_contact_relation,
                :bank_name, :bank_account, :bank_branch, :specialization, :contract_type, :bio
            )");

            $profile_stmt->execute([
                ':user_id' => $user_id,
                ':employee_id' => $employee_id_field,
                ':date_of_birth' => !empty($date_of_birth) ? $date_of_birth : null,
                ':gender' => !empty($gender) ? $gender : null,
                ':phone' => !empty($phone) ? $phone : null,
                ':address' => !empty($address) ? $address : null,
                ':qualification' => !empty($qualification) ? $qualification : null,
                ':experience_years' => ($experience_years !== '') ? (int)$experience_years : 0,
                ':joining_date' => $joining_date,
                ':salary' => ($salary !== '') ? $salary : null,
                ':department' => $dept_name,
                ':department_id' => !empty($department_id) ? $department_id : null,
                ':position' => !empty($position) ? $position : null,
                ':national_id' => !empty($national_id) ? $national_id : null,
                ':marital_status' => !empty($marital_status) ? $marital_status : null,
                ':nationality' => !empty($nationality) ? $nationality : null,
                ':city' => !empty($city) ? $city : null,
                ':state_region' => !empty($state_region) ? $state_region : null,
                ':postal_code' => !empty($postal_code) ? $postal_code : null,
                ':emergency_contact_name' => !empty($emergency_contact_name) ? $emergency_contact_name : null,
                ':emergency_contact_phone' => !empty($emergency_contact_phone) ? $emergency_contact_phone : null,
                ':emergency_contact_relation' => !empty($emergency_contact_relation) ? $emergency_contact_relation : null,
                ':bank_name' => !empty($bank_name) ? $bank_name : null,
                ':bank_account' => !empty($bank_account) ? $bank_account : null,
                ':bank_branch' => !empty($bank_branch) ? $bank_branch : null,
                ':specialization' => !empty($specialization) ? $specialization : null,
                ':contract_type' => !empty($contract_type) ? $contract_type : 'full_time',
                ':bio' => !empty($bio) ? $bio : null
            ]);

            $db->commit();

            // Mirror into the central login directory so the staff member can
            // sign in with their email from the main login page.
            require_once '../includes/user_directory.php';
            syncUserToCentralDirectory([
                'school_id'    => $_SESSION['school_id'] ?? null,
                'name'         => $name,
                'email'        => $email,
                'password'     => $hashed_password,
                'role'         => $role,
                'status'       => 'active',
                'employee_id'  => $employee_id_field,
                'joining_date' => $joining_date,
            ]);

            $_SESSION['success'] = "Staff member successfully created with Employee ID: " . $employee_id_field;
            header("Location: index.php");
            exit();

        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$title = "Add New Staff";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-5xl mx-auto">
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Add New Staff</h1>
                                <p class="text-blue-100 text-lg">Create a new user account and staff profile</p>
                                <div class="mt-4">
                                    <a href="index.php" class="inline-flex items-center text-sm text-white hover:text-blue-200 transition">
                                        <i class="fas fa-arrow-left mr-2"></i> Back to Staff Directory
                                    </a>
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

                <!-- Error Messages -->
                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-xl mb-6 shadow" role="alert">
                    <div class="flex items-center mb-1 font-bold">
                        <i class="fas fa-exclamation-circle mr-2 text-lg"></i>
                        <span>Please correct the following errors:</span>
                    </div>
                    <ul class="list-disc list-inside text-sm pl-4">
                        <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Form Container -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 overflow-hidden" 
                     x-data="{ activeTab: 'personal' }">
                    
                    <!-- Tab Navigation Header -->
                    <div class="border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50">
                        <nav class="flex flex-wrap -mb-px" aria-label="Tabs">
                            <!-- Tab 1 Button -->
                            <button type="button" 
                                    @click="activeTab = 'personal'" 
                                    class="w-full sm:w-auto px-6 py-4 font-semibold text-sm border-b-2 transition-all duration-200 flex items-center justify-center"
                                    :class="activeTab === 'personal' ? 'border-blue-600 text-blue-600 dark:text-blue-500' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'">
                                <i class="fas fa-user mr-2 text-base"></i>
                                Personal Information
                            </button>
                            <!-- Tab 2 Button -->
                            <button type="button" 
                                    @click="activeTab = 'employment'" 
                                    class="w-full sm:w-auto px-6 py-4 font-semibold text-sm border-b-2 transition-all duration-200 flex items-center justify-center"
                                    :class="activeTab === 'employment' ? 'border-blue-600 text-blue-600 dark:text-blue-500' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'">
                                <i class="fas fa-briefcase mr-2 text-base"></i>
                                Employment Details
                            </button>
                            <!-- Tab 3 Button -->
                            <button type="button" 
                                    @click="activeTab = 'banking'" 
                                    class="w-full sm:w-auto px-6 py-4 font-semibold text-sm border-b-2 transition-all duration-200 flex items-center justify-center"
                                    :class="activeTab === 'banking' ? 'border-blue-600 text-blue-600 dark:text-blue-500' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'">
                                <i class="fas fa-university mr-2 text-base"></i>
                                Banking &amp; Emergency
                            </button>
                        </nav>
                    </div>

                    <!-- Form -->
                    <form action="" method="POST" enctype="multipart/form-data" class="p-6 md:p-8" 
                          @submit="if (!$el.checkValidity()) { $event.preventDefault(); const invalid = $el.querySelector(':invalid'); if (invalid) { activeTab = invalid.closest('[data-tab]').getAttribute('data-tab'); setTimeout(() => invalid.reportValidity(), 50); } }">
                        
                        <!-- TAB 1: Personal Information -->
                        <div x-show="activeTab === 'personal'" x-transition data-tab="personal" class="space-y-6">
                            <div class="border-b border-gray-100 dark:border-gray-700 pb-3 mb-6">
                                <h3 class="text-xl font-bold text-gray-800 dark:text-white">Personal Information</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Basic contact and identification details</p>
                            </div>
                            
                            <!-- Profile Picture Upload -->
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Profile Picture (Optional)</label>
                                <div class="flex items-center space-x-4">
                                    <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden border border-gray-300 dark:border-gray-600 shadow-sm" id="imagePreviewContainer">
                                        <i class="fas fa-user text-3xl text-gray-400" id="defaultIcon"></i>
                                        <img id="imagePreview" src="#" alt="Preview" class="hidden w-full h-full object-cover">
                                    </div>
                                    <div>
                                        <input type="file" name="profile_picture" accept="image/jpeg, image/png, image/gif"
                                          data-cropper data-crop-preview="#imagePreview" data-crop-icon="#defaultIcon"
                                          class="block w-full text-sm text-gray-500 dark:text-gray-400
                                          file:mr-4 file:py-2 file:px-4
                                          file:rounded-full file:border-0
                                          file:text-sm file:font-semibold
                                          file:bg-blue-50 file:text-blue-700
                                          hover:file:bg-blue-100
                                          dark:file:bg-gray-700 dark:file:text-gray-300
                                          transition-colors cursor-pointer">
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">PNG, JPG, GIF up to 2MB. Crop to frame the face before saving.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
                                <!-- First Name -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" placeholder="First name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Other Name(s) -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Other Name(s)</label>
                                    <input type="text" name="other_names" value="<?php echo htmlspecialchars($_POST['other_names'] ?? ''); ?>" placeholder="Other names (optional)"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Last Name -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" placeholder="Last name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Staff ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Staff ID <span class="text-red-500">*</span></label>
                                    <input type="text" name="employee_id" required value="<?php echo htmlspecialchars($_POST['employee_id'] ?? $employee_id); ?>" placeholder="Staff ID (e.g. EMP20260001)"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Email -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" placeholder="staffname@school.com"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Password -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Login Password <span class="text-red-500">*</span></label>
                                    <input type="password" name="password" required minlength="8" placeholder="At least 8 characters"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Date of Birth -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Date of Birth</label>
                                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($_POST['date_of_birth'] ?? ''); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Gender -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Gender</label>
                                    <select name="gender" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo ($_POST['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($_POST['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo ($_POST['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <!-- Phone -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Phone Number</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="Phone / Mobile"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Nationality -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Nationality</label>
                                    <input type="text" name="nationality" value="<?php echo htmlspecialchars($_POST['nationality'] ?? ''); ?>" placeholder="e.g. Ghanaian"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Marital Status -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Marital Status</label>
                                    <select name="marital_status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Status</option>
                                        <option value="single" <?php echo ($_POST['marital_status'] ?? '') === 'single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="married" <?php echo ($_POST['marital_status'] ?? '') === 'married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="divorced" <?php echo ($_POST['marital_status'] ?? '') === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                        <option value="widowed" <?php echo ($_POST['marital_status'] ?? '') === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                    </select>
                                </div>

                                <!-- National ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">National ID / SSN</label>
                                    <input type="text" name="national_id" value="<?php echo htmlspecialchars($_POST['national_id'] ?? ''); ?>" placeholder="ID Number"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Address -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Residential Address</label>
                                    <textarea name="address" rows="3" placeholder="Full home address..."
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
                                </div>

                                <!-- City -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">City</label>
                                    <input type="text" name="city" value="<?php echo htmlspecialchars($_POST['city'] ?? ''); ?>" placeholder="City"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- State / Region -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">State / Region</label>
                                    <input type="text" name="state_region" value="<?php echo htmlspecialchars($_POST['state_region'] ?? ''); ?>" placeholder="State / Province / Region"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Postal Code -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Postal Code</label>
                                    <input type="text" name="postal_code" value="<?php echo htmlspecialchars($_POST['postal_code'] ?? ''); ?>" placeholder="ZIP / Postal Code"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex justify-end mt-8 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <button type="button" @click="activeTab = 'employment'" 
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-medium flex items-center transition-all duration-200">
                                    Next: Employment Details <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </div>
                        </div>

                        <!-- TAB 2: Employment Details -->
                        <div x-show="activeTab === 'employment'" x-transition data-tab="employment" class="space-y-6">
                            <div class="border-b border-gray-100 dark:border-gray-700 pb-3 mb-6">
                                <h3 class="text-xl font-bold text-gray-800 dark:text-white">Employment &amp; Position</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">School roles, departments, contract details, and salaries</p>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
                                <!-- Employee ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Employee ID (Auto-Generated)</label>
                                    <input type="text" name="employee_id" readonly value="<?php echo $employee_id; ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl bg-gray-100 dark:bg-gray-700 dark:text-white text-gray-500 cursor-not-allowed">
                                </div>

                                <!-- Role -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Staff Role <span class="text-red-500">*</span></label>
                                    <select name="role" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Role</option>
                                        <?php foreach ($role_labels as $val => $lbl): ?>
                                        <option value="<?php echo $val; ?>" <?php echo ($_POST['role'] ?? '') === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Department -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Department</label>
                                    <select name="department_id" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo ($_POST['department_id'] ?? '') == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Position/Title -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Position / Job Title</label>
                                    <input type="text" name="position" value="<?php echo htmlspecialchars($_POST['position'] ?? ''); ?>" placeholder="e.g. Senior Lecturer, Head Cook"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Contract Type -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contract Type</label>
                                    <select name="contract_type" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="full_time" <?php echo ($_POST['contract_type'] ?? '') === 'full_time' ? 'selected' : ''; ?>>Full-Time</option>
                                        <option value="part_time" <?php echo ($_POST['contract_type'] ?? '') === 'part_time' ? 'selected' : ''; ?>>Part-Time</option>
                                        <option value="contract" <?php echo ($_POST['contract_type'] ?? '') === 'contract' ? 'selected' : ''; ?>>Contract</option>
                                        <option value="temporary" <?php echo ($_POST['contract_type'] ?? '') === 'temporary' ? 'selected' : ''; ?>>Temporary</option>
                                    </select>
                                </div>

                                <!-- Joining Date -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Joining Date <span class="text-red-500">*</span></label>
                                    <input type="date" name="joining_date" required value="<?php echo htmlspecialchars($_POST['joining_date'] ?? date('Y-m-d')); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Qualification -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Highest Qualification</label>
                                    <input type="text" name="qualification" value="<?php echo htmlspecialchars($_POST['qualification'] ?? ''); ?>" placeholder="e.g. M.Sc. in Physics"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Specialization -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Area of Specialization</label>
                                    <input type="text" name="specialization" value="<?php echo htmlspecialchars($_POST['specialization'] ?? ''); ?>" placeholder="e.g. Calculus, Nursing Care"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Experience Years -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Experience</label>
                                    <input type="number" name="experience_years" min="0" value="<?php echo htmlspecialchars($_POST['experience_years'] ?? ''); ?>" placeholder="e.g. 5"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Salary -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Monthly Salary</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 dark:text-gray-400 text-sm"><?php echo htmlspecialchars(getSchoolSetting('currency_symbol', '₵')); ?></span>
                                        </div>
                                        <input type="number" step="0.01" min="0" name="salary" value="<?php echo htmlspecialchars($_POST['salary'] ?? ''); ?>" placeholder="0.00"
                                               class="w-full pl-8 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>

                                <!-- Bio / Notes -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bio / Professional Summary / Notes</label>
                                    <textarea name="bio" rows="4" placeholder="Brief biography, career profile, or notes..."
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($_POST['bio'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex justify-between mt-8 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <button type="button" @click="activeTab = 'personal'" 
                                        class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-medium flex items-center transition-all duration-200">
                                    <i class="fas fa-arrow-left mr-2"></i> Previous: Personal Info
                                </button>
                                <button type="button" @click="activeTab = 'banking'" 
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl font-medium flex items-center transition-all duration-200">
                                    Next: Banking &amp; Emergency <i class="fas fa-arrow-right ml-2"></i>
                                </button>
                            </div>
                        </div>

                        <!-- TAB 3: Banking & Emergency -->
                        <div x-show="activeTab === 'banking'" x-transition data-tab="banking" class="space-y-6">
                            <!-- Section: Banking -->
                            <div>
                                <div class="border-b border-gray-100 dark:border-gray-700 pb-3 mb-6">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white">Banking details</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Account details for salary disbursement</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-6 gap-6 mb-8">
                                    <!-- Bank Name -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bank Name</label>
                                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($_POST['bank_name'] ?? ''); ?>" placeholder="e.g. Chase, GCB Bank"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Bank Account Number -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Account Number</label>
                                        <input type="text" name="bank_account" value="<?php echo htmlspecialchars($_POST['bank_account'] ?? ''); ?>" placeholder="Account Number"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Bank Branch -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bank Branch</label>
                                        <input type="text" name="bank_branch" value="<?php echo htmlspecialchars($_POST['bank_branch'] ?? ''); ?>" placeholder="Branch Location / Name"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <!-- Section: Emergency Contact -->
                            <div>
                                <div class="border-b border-gray-100 dark:border-gray-700 pb-3 mb-6">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white">Emergency Contact Info</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Primary point of contact in case of emergency</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
                                    <!-- Emergency Contact Name -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contact Name</label>
                                        <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($_POST['emergency_contact_name'] ?? ''); ?>" placeholder="Full Name"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Emergency Contact Phone -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contact Phone</label>
                                        <input type="text" name="emergency_contact_phone" value="<?php echo htmlspecialchars($_POST['emergency_contact_phone'] ?? ''); ?>" placeholder="Phone Number"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Emergency Contact Relation -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Relationship</label>
                                        <input type="text" name="emergency_contact_relation" value="<?php echo htmlspecialchars($_POST['emergency_contact_relation'] ?? ''); ?>" placeholder="e.g. Spouse, Parent, Sibling"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <!-- Navigation Buttons -->
                            <div class="flex justify-between mt-8 pt-4 border-t border-gray-100 dark:border-gray-700">
                                <button type="button" @click="activeTab = 'employment'" 
                                        class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-medium flex items-center transition-all duration-200">
                                    <i class="fas fa-arrow-left mr-2"></i> Previous: Employment Details
                                </button>
                                <button type="submit" 
                                        class="bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-xl font-semibold flex items-center transition-all duration-200 shadow-lg shadow-blue-500/25">
                                    <i class="fas fa-save mr-2"></i> Create Staff Profile
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
    </div>
</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').src = e.target.result;
            document.getElementById('imagePreview').classList.remove('hidden');
            var defaultIcon = document.getElementById('defaultIcon');
            if (defaultIcon) defaultIcon.classList.add('hidden');
        }
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php
// Since includes/footer.php might contain script tags, this ensures our page structure remains compliant
?>
