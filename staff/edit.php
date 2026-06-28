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

// Heal older tenant DBs missing newer teacher_profiles columns before editing.
ensureTeacherProfileColumns($db);

$staff_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$staff_id) {
    $_SESSION['error'] = "Invalid staff ID.";
    header("Location: index.php");
    exit();
}

// Fetch departments for dropdown
try {
    $dept_stmt = $db->query("SELECT id, name FROM staff_departments WHERE status = 'active' ORDER BY name");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
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

// Fetch current staff details
try {
    $query = "SELECT u.id, u.name, u.first_name, u.other_names, u.last_name, u.email, u.role, u.status,
                     tp.employee_id, tp.date_of_birth, tp.gender, tp.phone, tp.address,
                     tp.qualification, tp.experience_years, tp.joining_date, tp.salary,
                     tp.department, tp.department_id, tp.position, tp.national_id,
                     tp.marital_status, tp.nationality, tp.city, tp.state_region, tp.postal_code,
                     tp.emergency_contact_name, tp.emergency_contact_phone, tp.emergency_contact_relation,
                     tp.bank_name, tp.bank_account, tp.bank_branch, tp.specialization, tp.contract_type, tp.bio,
                     tp.employment_status, tp.tax_id, tp.contract_end_date
              FROM users u
              LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
              WHERE u.id = :id AND u.role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr')";
    $stmt = $db->prepare($query);
    $stmt->execute([':id' => $staff_id]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$staff) {
        $_SESSION['error'] = "Staff member not found.";
        header("Location: index.php");
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: index.php");
    exit();
}

// Compute initials for the avatar box
$initials = '';
$name_parts = explode(' ', $staff['name'] ?? '');
foreach ($name_parts as $np) {
    if ($np) $initials .= strtoupper($np[0]);
}
$initials = substr($initials, 0, 2);
if (empty($initials)) {
    $initials = 'ST';
}

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Sanitize & retrieve inputs
    // Fetch existing values to fallback if empty
    $ex_stmt = $db->prepare("SELECT u.name, u.first_name, u.other_names, u.last_name, u.email, u.role, tp.joining_date, tp.employee_id FROM users u LEFT JOIN teacher_profiles tp ON u.id = tp.user_id WHERE u.id = :id");
    $ex_stmt->execute([':id' => $staff_id]);
    $ex = $ex_stmt->fetch(PDO::FETCH_ASSOC);

    $first_name = !empty(trim($_POST['first_name'] ?? '')) ? trim($_POST['first_name']) : $ex['first_name'];
    $other_names = trim($_POST['other_names'] ?? '');
    $last_name = !empty(trim($_POST['last_name'] ?? '')) ? trim($_POST['last_name']) : $ex['last_name'];
    $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));
    $employee_id_field = !empty(trim($_POST['employee_id'] ?? '')) ? trim($_POST['employee_id']) : $ex['employee_id'];
    $email = !empty(trim($_POST['email'] ?? '')) ? trim($_POST['email']) : $ex['email'];
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

    $role = !empty($_POST['role']) ? $_POST['role'] : $ex['role'];
    $department_id = $_POST['department_id'] ?? '';
    $position = trim($_POST['position'] ?? '');
    $contract_type = $_POST['contract_type'] ?? '';
    $joining_date = !empty($_POST['joining_date']) ? $_POST['joining_date'] : $ex['joining_date'];
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

    // HR / account fields (kept consistent with users/edit.php).
    $employment_status = $_POST['employment_status'] ?? '';
    $tax_id = trim($_POST['tax_id'] ?? '');
    $contract_end_date = $_POST['contract_end_date'] ?? '';
    $status = (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active';

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
        $emp_check_stmt = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE employee_id = :employee_id AND user_id != :user_id");
        $emp_check_stmt->execute([':employee_id' => $employee_id_field, ':user_id' => $staff_id]);
        if ($emp_check_stmt->fetchColumn() > 0) {
            $errors[] = "The Employee ID is already in use by another staff member.";
        }
    }
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "A valid email address is required.";
    } elseif (!empty($email)) {
        // Email uniqueness check
        $email_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email AND id != :id");
        $email_stmt->execute([':email' => $email, ':id' => $staff_id]);
        if ($email_stmt->fetchColumn() > 0) {
            $errors[] = "The email address is already in use by another user.";
        }
    }

    if (!empty($password) && strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }

    if (!empty($role) && !array_key_exists($role, $role_labels)) {
        $errors[] = "Please select a valid staff role.";
    }

    if (empty($joining_date)) {
        // joining date is no longer required
    }

    // 3. Process
    if (empty($errors)) {
        try {
            $db->beginTransaction();

            // Handle Profile Picture Upload
            $profile_picture = null;
            $update_picture = false;
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
                if (in_array($_FILES['profile_picture']['type'], $allowed_types)) {
                    $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                    $filename = uniqid('profile_') . '.' . $ext;
                    $upload_dir = '../uploads/profile_pictures/';
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $filename)) {
                        $profile_picture = $filename;
                        $update_picture = true;
                    }
                }
            }

            // Update users table
            if (!empty($password)) {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $query = "UPDATE users SET name = :name, first_name = :first_name, other_names = :other_names, last_name = :last_name, email = :email, password = :password, role = :role, status = :status";
                if ($update_picture) $query .= ", profile_picture = :profile_picture";
                $query .= " WHERE id = :id";
                $user_stmt = $db->prepare($query);

                $params = [
                    ':name' => $name,
                    ':first_name' => $first_name,
                    ':other_names' => $other_names,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':password' => $hashed_password,
                    ':role' => $role,
                    ':status' => $status,
                    ':id' => $staff_id
                ];
                if ($update_picture) $params[':profile_picture'] = $profile_picture;
                $user_stmt->execute($params);
            } else {
                $query = "UPDATE users SET name = :name, first_name = :first_name, other_names = :other_names, last_name = :last_name, email = :email, role = :role, status = :status";
                if ($update_picture) $query .= ", profile_picture = :profile_picture";
                $query .= " WHERE id = :id";
                $user_stmt = $db->prepare($query);

                $params = [
                    ':name' => $name,
                    ':first_name' => $first_name,
                    ':other_names' => $other_names,
                    ':last_name' => $last_name,
                    ':email' => $email,
                    ':role' => $role,
                    ':status' => $status,
                    ':id' => $staff_id
                ];
                if ($update_picture) $params[':profile_picture'] = $profile_picture;
                $user_stmt->execute($params);
            }

            if ($update_picture && $staff_id == $_SESSION['user_id']) {
                $_SESSION['profile_picture'] = $profile_picture;
            }

            // Find department name
            $dept_name = null;
            if (!empty($department_id)) {
                $dept_name_stmt = $db->prepare("SELECT name FROM staff_departments WHERE id = :id");
                $dept_name_stmt->execute([':id' => $department_id]);
                $dept_name = $dept_name_stmt->fetchColumn();
            }

            // Check if profile exists
            $check_stmt = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE user_id = :user_id");
            $check_stmt->execute([':user_id' => $staff_id]);
            $has_profile = $check_stmt->fetchColumn() > 0;

            if ($has_profile) {
                // Update profile
                $profile_stmt = $db->prepare("UPDATE teacher_profiles SET
                    employee_id = :employee_id,
                    date_of_birth = :date_of_birth,
                    gender = :gender,
                    phone = :phone,
                    address = :address,
                    qualification = :qualification,
                    experience_years = :experience_years,
                    joining_date = :joining_date,
                    salary = :salary,
                    department = :department,
                    department_id = :department_id,
                    position = :position,
                    national_id = :national_id,
                    marital_status = :marital_status,
                    nationality = :nationality,
                    city = :city,
                    state_region = :state_region,
                    postal_code = :postal_code,
                    emergency_contact_name = :emergency_contact_name,
                    emergency_contact_phone = :emergency_contact_phone,
                    emergency_contact_relation = :emergency_contact_relation,
                    bank_name = :bank_name,
                    bank_account = :bank_account,
                    bank_branch = :bank_branch,
                    specialization = :specialization,
                    contract_type = :contract_type,
                    bio = :bio,
                    employment_status = :employment_status,
                    tax_id = :tax_id,
                    contract_end_date = :contract_end_date
                    WHERE user_id = :user_id");
            } else {
                // Insert profile (fallback)
                $employee_id = 'EMP' . date('Y') . str_pad($staff_id, 4, '0', STR_PAD_LEFT);
                $profile_stmt = $db->prepare("INSERT INTO teacher_profiles (
                    user_id, employee_id, date_of_birth, gender, phone, address,
                    qualification, experience_years, joining_date, salary,
                    department, department_id, position, national_id, marital_status,
                    nationality, city, state_region, postal_code,
                    emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                    bank_name, bank_account, bank_branch, specialization, contract_type, bio,
                    employment_status, tax_id, contract_end_date
                ) VALUES (
                    :user_id, :employee_id, :date_of_birth, :gender, :phone, :address,
                    :qualification, :experience_years, :joining_date, :salary,
                    :department, :department_id, :position, :national_id, :marital_status,
                    :nationality, :city, :state_region, :postal_code,
                    :emergency_contact_name, :emergency_contact_phone, :emergency_contact_relation,
                    :bank_name, :bank_account, :bank_branch, :specialization, :contract_type, :bio,
                    :employment_status, :tax_id, :contract_end_date
                )");
            }

            $params = [
                ':user_id' => $staff_id,
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
                ':bio' => !empty($bio) ? $bio : null,
                ':employment_status' => !empty($employment_status) ? $employment_status : 'active',
                ':tax_id' => !empty($tax_id) ? $tax_id : null,
                ':contract_end_date' => !empty($contract_end_date) ? $contract_end_date : null
            ];

            if (!$has_profile) {
                $params[':employee_id'] = $employee_id;
            }

            $profile_stmt->execute($params);

            // Digital signature upload (used on report cards, payslips, etc.)
            if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
                $sig_ext = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
                if (in_array($sig_ext, ['png', 'jpg', 'jpeg', 'gif']) && $_FILES['signature_image']['size'] <= 1024 * 1024) {
                    require_once '../includes/signature_helper.php';
                    $sig_dir = '../uploads/signatures/';
                    if (!file_exists($sig_dir)) { @mkdir($sig_dir, 0777, true); }
                    $sig_base = 'staffsig_' . $staff_id . '_' . time();
                    $raw_name = $sig_base . '.' . $sig_ext;
                    if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $sig_dir . $raw_name)) {
                        // Normalize so the signature prints at a consistent size.
                        $sig_name = $sig_base . '.png';
                        if (normalizeSignatureImage($sig_dir . $raw_name, $sig_dir . $sig_name)) {
                            if ($raw_name !== $sig_name) { @unlink($sig_dir . $raw_name); }
                        } else {
                            $sig_name = $raw_name; // keep original if normalization fails
                        }
                        // Remove the previous signature file, if any.
                        $old = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
                        $old->execute([':id' => $staff_id]);
                        $old_file = $old->fetchColumn();
                        if ($old_file && $old_file !== $sig_name && file_exists($sig_dir . $old_file)) { @unlink($sig_dir . $old_file); }
                        $db->prepare("UPDATE teacher_profiles SET signature_image = :s WHERE user_id = :id")
                           ->execute([':s' => $sig_name, ':id' => $staff_id]);
                    }
                }
            } elseif (!empty($_POST['remove_signature'])) {
                $rm = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
                $rm->execute([':id' => $staff_id]);
                $rm_file = $rm->fetchColumn();
                if ($rm_file && file_exists('../uploads/signatures/' . $rm_file)) { @unlink('../uploads/signatures/' . $rm_file); }
                $db->prepare("UPDATE teacher_profiles SET signature_image = NULL WHERE user_id = :id")
                   ->execute([':id' => $staff_id]);
            }

            $db->commit();

            $_SESSION['success'] = "Staff profile successfully updated.";
            header("Location: index.php");
            exit();

        } catch (PDOException $e) {
            $db->rollBack();
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

// Prepare values for display (form inputs keep posted data on validation failure, otherwise load database values)
$first_name = !empty($staff['first_name']) ? $staff['first_name'] : '';
$other_names = !empty($staff['other_names']) ? $staff['other_names'] : '';
$last_name = !empty($staff['last_name']) ? $staff['last_name'] : '';

// Fallback to name split if first/last are empty
if (empty($first_name) && empty($last_name) && !empty($staff['name'])) {
    $fullName = trim($staff['name']);
    $parts = preg_split('/\s+/', $fullName);
    $num_parts = count($parts);
    if ($num_parts === 1) {
        $first_name = $parts[0];
    } elseif ($num_parts === 2) {
        $first_name = $parts[0];
        $last_name = $parts[1];
    } else {
        $first_name = $parts[0];
        $last_name = $parts[$num_parts - 1];
        $other_names = implode(' ', array_slice($parts, 1, $num_parts - 2));
    }
}

$first_name_val = $_POST['first_name'] ?? $first_name;
$other_names_val = $_POST['other_names'] ?? $other_names;
$last_name_val = $_POST['last_name'] ?? $last_name;

$email = $_POST['email'] ?? $staff['email'];
$date_of_birth = $_POST['date_of_birth'] ?? $staff['date_of_birth'];
$gender = $_POST['gender'] ?? $staff['gender'];
$phone = $_POST['phone'] ?? $staff['phone'];
$nationality = $_POST['nationality'] ?? $staff['nationality'];
$marital_status = $_POST['marital_status'] ?? $staff['marital_status'];
$national_id = $_POST['national_id'] ?? $staff['national_id'];
$address = $_POST['address'] ?? $staff['address'];
$city = $_POST['city'] ?? $staff['city'];
$state_region = $_POST['state_region'] ?? $staff['state_region'];
$postal_code = $_POST['postal_code'] ?? $staff['postal_code'];

$employee_id = $staff['employee_id'] ?? '';
$role = $_POST['role'] ?? $staff['role'];
$department_id = $_POST['department_id'] ?? $staff['department_id'];
$position = $_POST['position'] ?? $staff['position'];
$contract_type = $_POST['contract_type'] ?? $staff['contract_type'];
$joining_date = $_POST['joining_date'] ?? $staff['joining_date'];
$qualification = $_POST['qualification'] ?? $staff['qualification'];
$specialization = $_POST['specialization'] ?? $staff['specialization'];
$experience_years = $_POST['experience_years'] ?? $staff['experience_years'];
$salary = $_POST['salary'] ?? $staff['salary'];
$bio = $_POST['bio'] ?? $staff['bio'];

$bank_name = $_POST['bank_name'] ?? $staff['bank_name'];
$bank_account = $_POST['bank_account'] ?? $staff['bank_account'];
$bank_branch = $_POST['bank_branch'] ?? $staff['bank_branch'];

$emergency_contact_name = $_POST['emergency_contact_name'] ?? $staff['emergency_contact_name'];
$emergency_contact_phone = $_POST['emergency_contact_phone'] ?? $staff['emergency_contact_phone'];
$emergency_contact_relation = $_POST['emergency_contact_relation'] ?? $staff['emergency_contact_relation'];

$employment_status = $_POST['employment_status'] ?? ($staff['employment_status'] ?? 'active');
$tax_id = $_POST['tax_id'] ?? ($staff['tax_id'] ?? '');
$contract_end_date = $_POST['contract_end_date'] ?? ($staff['contract_end_date'] ?? '');
$status = $_POST['status'] ?? ($staff['status'] ?? 'active');

$title = "Edit Staff Profile";
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
                
                <!-- Profile Summary Header Card -->
                <div class="bg-gradient-to-r from-blue-700 to-indigo-800 dark:from-slate-800 dark:to-indigo-950 rounded-2xl p-6 text-white shadow-xl mb-8 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex flex-col md:flex-row items-center space-y-4 md:space-y-0 md:space-x-6 text-center md:text-left">
                        <!-- Initials Avatar -->
                        <div class="w-24 h-24 bg-white/20 rounded-full flex items-center justify-center border-4 border-white/30 backdrop-blur-sm shadow-inner flex-shrink-0">
                            <span class="text-white font-bold text-3xl"><?php echo $initials; ?></span>
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-2xl font-bold mb-1 truncate"><?php echo htmlspecialchars($staff['name']); ?></h2>
                            <p class="text-blue-100 font-medium mb-2"><?php echo htmlspecialchars($staff['employee_id'] ?? 'No Employee ID'); ?></p>
                            <div class="flex flex-wrap gap-2 justify-center md:justify-start">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-white/25 text-white flex items-center">
                                    <i class="fas fa-id-badge mr-1.5"></i> <?php echo htmlspecialchars($role_labels[$staff['role']] ?? formatRoleName($staff['role'])); ?>
                                </span>
                                <?php if (!empty($staff['department'])): ?>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold bg-white/25 text-white flex items-center">
                                    <i class="fas fa-building mr-1.5"></i> <?php echo htmlspecialchars($staff['department']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="flex flex-wrap gap-3 justify-center">
                        <a href="index.php" class="inline-flex items-center bg-white/10 hover:bg-white/20 text-white border border-white/20 px-4 py-2.5 rounded-xl text-sm font-semibold transition backdrop-blur-sm">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Directory
                        </a>
                        <a href="view.php?id=<?php echo $staff['id']; ?>" class="inline-flex items-center bg-white text-blue-800 hover:bg-blue-50 px-4 py-2.5 rounded-xl text-sm font-bold transition shadow-lg">
                            <i class="fas fa-eye mr-2"></i> View Profile
                        </a>
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
                                        <?php 
                                        // fetch profile picture again to ensure it exists
                                        $pic_stmt = $db->prepare("SELECT profile_picture FROM users WHERE id = :id");
                                        $pic_stmt->execute([':id' => $staff_id]);
                                        $pic = $pic_stmt->fetchColumn();
                                        ?>
                                        <?php if(!empty($pic)): ?>
                                            <img id="imagePreview" src="/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($pic); ?>" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <i class="fas fa-user text-3xl text-gray-400" id="defaultIcon"></i>
                                            <img id="imagePreview" src="#" alt="Preview" class="hidden w-full h-full object-cover">
                                        <?php endif; ?>
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

                            <!-- Digital Signature Upload -->
                            <?php
                            $sig_stmt = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
                            $sig_stmt->execute([':id' => $staff_id]);
                            $staff_sig = $sig_stmt->fetchColumn();
                            ?>
                            <div class="mb-6">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Digital Signature (Optional)</label>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Embedded on documents this person signs (e.g. report cards as class teacher), when signatures are enabled in Settings.</p>
                                <div class="flex items-center space-x-4">
                                    <div class="w-40 h-20 rounded-lg bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center overflow-hidden">
                                        <?php if(!empty($staff_sig) && file_exists('../uploads/signatures/' . $staff_sig)): ?>
                                            <img src="/serve_image.php?path=signatures/<?php echo rawurlencode($staff_sig); ?>" alt="Signature" class="max-h-full max-w-full object-contain">
                                        <?php else: ?>
                                            <span class="text-xs text-gray-400"><i class="fas fa-signature mr-1"></i>No signature</span>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <input type="file" name="signature_image" accept="image/png, image/jpeg, image/gif"
                                          class="block w-full text-sm text-gray-500 dark:text-gray-400
                                          file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0
                                          file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700
                                          hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-300
                                          transition-colors cursor-pointer">
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Transparent PNG recommended, max 1MB.</p>
                                        <?php if(!empty($staff_sig)): ?>
                                        <label class="inline-flex items-center mt-2 text-xs text-red-600 cursor-pointer">
                                            <input type="checkbox" name="remove_signature" value="1" class="mr-1.5">Remove current signature
                                        </label>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-6 gap-6">
                                <!-- First Name -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="first_name" required value="<?php echo htmlspecialchars($first_name_val); ?>" placeholder="First name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Other Name(s) -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Other Name(s)</label>
                                    <input type="text" name="other_names" value="<?php echo htmlspecialchars($other_names_val); ?>" placeholder="Other names (optional)"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Last Name -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" name="last_name" required value="<?php echo htmlspecialchars($last_name_val); ?>" placeholder="Last name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Staff ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Staff ID <span class="text-red-500">*</span></label>
                                    <input type="text" name="employee_id" required value="<?php echo htmlspecialchars($employee_id); ?>" placeholder="Staff ID (e.g. EMP20260001)"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Email -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" placeholder="staffname@school.com"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Password -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Login Password</label>
                                    <input type="password" name="password" minlength="8" placeholder="Leave blank to keep current"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Date of Birth -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Date of Birth</label>
                                    <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($date_of_birth); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Gender -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Gender</label>
                                    <select name="gender" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo $gender === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $gender === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo $gender === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <!-- Phone -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Phone Number</label>
                                    <input type="text" name="phone" value="<?php echo htmlspecialchars($phone); ?>" placeholder="Phone / Mobile"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Nationality -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Nationality</label>
                                    <input type="text" name="nationality" value="<?php echo htmlspecialchars($nationality); ?>" placeholder="e.g. Ghanaian"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Marital Status -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Marital Status</label>
                                    <select name="marital_status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Status</option>
                                        <option value="single" <?php echo $marital_status === 'single' ? 'selected' : ''; ?>>Single</option>
                                        <option value="married" <?php echo $marital_status === 'married' ? 'selected' : ''; ?>>Married</option>
                                        <option value="divorced" <?php echo $marital_status === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                        <option value="widowed" <?php echo $marital_status === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                    </select>
                                </div>

                                <!-- National ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">National ID / SSN</label>
                                    <input type="text" name="national_id" value="<?php echo htmlspecialchars($national_id); ?>" placeholder="ID Number"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Address -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Residential Address</label>
                                    <textarea name="address" rows="3" placeholder="Full home address..."
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($address); ?></textarea>
                                </div>

                                <!-- City -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">City</label>
                                    <input type="text" name="city" value="<?php echo htmlspecialchars($city); ?>" placeholder="City"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- State / Region -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">State / Region</label>
                                    <input type="text" name="state_region" value="<?php echo htmlspecialchars($state_region); ?>" placeholder="State / Province / Region"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Postal Code -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Postal Code</label>
                                    <input type="text" name="postal_code" value="<?php echo htmlspecialchars($postal_code); ?>" placeholder="ZIP / Postal Code"
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
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Employee ID (Readonly)</label>
                                    <input type="text" name="employee_id" readonly value="<?php echo htmlspecialchars($employee_id); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl bg-gray-100 dark:bg-gray-700 dark:text-white text-gray-500 cursor-not-allowed">
                                </div>

                                <!-- Role -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Staff Role <span class="text-red-500">*</span></label>
                                    <select name="role" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select a role</option>
                                        <?php foreach ($role_labels as $val => $lbl): ?>
                                        <option value="<?php echo $val; ?>" <?php echo $role === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Department -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Department</label>
                                    <select name="department_id" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Department</option>
                                        <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_id == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Position/Title -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Position / Job Title</label>
                                    <input type="text" name="position" value="<?php echo htmlspecialchars($position); ?>" placeholder="e.g. Senior Lecturer, Head Cook"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Contract Type -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contract Type</label>
                                    <select name="contract_type" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="full_time" <?php echo $contract_type === 'full_time' ? 'selected' : ''; ?>>Full-Time</option>
                                        <option value="part_time" <?php echo $contract_type === 'part_time' ? 'selected' : ''; ?>>Part-Time</option>
                                        <option value="contract" <?php echo $contract_type === 'contract' ? 'selected' : ''; ?>>Contract</option>
                                        <option value="temporary" <?php echo $contract_type === 'temporary' ? 'selected' : ''; ?>>Temporary</option>
                                    </select>
                                </div>

                                <!-- Joining Date -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Joining Date <span class="text-red-500">*</span></label>
                                    <input type="date" name="joining_date" value="<?php echo htmlspecialchars($joining_date); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Qualification -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Highest Qualification</label>
                                    <input type="text" name="qualification" value="<?php echo htmlspecialchars($qualification); ?>" placeholder="e.g. M.Sc. in Physics"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Specialization -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Area of Specialization</label>
                                    <input type="text" name="specialization" value="<?php echo htmlspecialchars($specialization); ?>" placeholder="e.g. Calculus, Nursing Care"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Experience Years -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Years of Experience</label>
                                    <input type="number" name="experience_years" min="0" value="<?php echo htmlspecialchars($experience_years); ?>" placeholder="e.g. 5"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Employment Status -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Employment Status</label>
                                    <select name="employment_status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <?php foreach (['active' => 'Active', 'on_leave' => 'On Leave', 'suspended' => 'Suspended', 'terminated' => 'Terminated', 'retired' => 'Retired'] as $val => $lbl): ?>
                                        <option value="<?php echo $val; ?>" <?php echo $employment_status === $val ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Contract End Date -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contract End Date</label>
                                    <input type="date" name="contract_end_date" value="<?php echo htmlspecialchars($contract_end_date); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Tax ID -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Tax ID / TIN</label>
                                    <input type="text" name="tax_id" value="<?php echo htmlspecialchars($tax_id); ?>" placeholder="e.g. P0001234567"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Account Status -->
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Account Status</label>
                                    <select name="status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active (can log in)</option>
                                        <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive (login disabled)</option>
                                    </select>
                                </div>

                                <!-- Salary -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Monthly Salary</label>
                                    <div class="relative">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 dark:text-gray-400 text-sm"><?php echo htmlspecialchars(getSchoolSetting('currency_symbol', '₵')); ?></span>
                                        </div>
                                        <input type="number" step="0.01" min="0" name="salary" value="<?php echo htmlspecialchars($salary); ?>" placeholder="0.00"
                                               class="w-full pl-8 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>

                                <!-- Bio / Notes -->
                                <div class="md:col-span-6">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bio / Professional Summary / Notes</label>
                                    <textarea name="bio" rows="4" placeholder="Brief biography, career profile, or notes..."
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($bio); ?></textarea>
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
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white">Banking Details</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Account details for salary disbursement</p>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-6 gap-6 mb-8">
                                    <!-- Bank Name -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bank Name</label>
                                        <input type="text" name="bank_name" value="<?php echo htmlspecialchars($bank_name); ?>" placeholder="e.g. Chase, GCB Bank"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Bank Account Number -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Account Number</label>
                                        <input type="text" name="bank_account" value="<?php echo htmlspecialchars($bank_account); ?>" placeholder="Account Number"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Bank Branch -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Bank Branch</label>
                                        <input type="text" name="bank_branch" value="<?php echo htmlspecialchars($bank_branch); ?>" placeholder="Branch Location / Name"
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
                                        <input type="text" name="emergency_contact_name" value="<?php echo htmlspecialchars($emergency_contact_name); ?>" placeholder="Full Name"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Emergency Contact Phone -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Contact Phone</label>
                                        <input type="text" name="emergency_contact_phone" value="<?php echo htmlspecialchars($emergency_contact_phone); ?>" placeholder="Phone Number"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <!-- Emergency Contact Relation -->
                                    <div class="md:col-span-2">
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1.5">Relationship</label>
                                        <input type="text" name="emergency_contact_relation" value="<?php echo htmlspecialchars($emergency_contact_relation); ?>" placeholder="e.g. Spouse, Parent, Sibling"
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
                                    <i class="fas fa-save mr-2"></i> Update Staff Profile
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
<?php
// Since includes/footer.php might contain script tags, this ensures our page structure remains compliant
?>
