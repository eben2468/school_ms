<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs that predate some optional profile columns.
ensureStudentProfileColumns($db);
ensureTeacherProfileColumns($db);

$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$id) {
    header("Location: index.php");
    exit();
}

// Fetch user data
try {
    $query = "SELECT * FROM users WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header("Location: index.php");
        exit();
    }
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch student or staff profile details
    $student_profile = [];
    $staff_profile = [];
    $staff_roles = ['school_admin', 'principal', 'teacher', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];
    
    if ($user['role'] === 'student') {
        $sp_stmt = $db->prepare("SELECT * FROM student_profiles WHERE user_id = :user_id");
        $sp_stmt->execute([':user_id' => $id]);
        $student_profile = $sp_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } elseif (in_array($user['role'], $staff_roles)) {
        $sp_stmt = $db->prepare("SELECT * FROM teacher_profiles WHERE user_id = :user_id");
        $sp_stmt->execute([':user_id' => $id]);
        $staff_profile = $sp_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    // Fetch active departments for staff dropdown
    try {
        $dept_stmt = $db->query("SELECT id, name FROM staff_departments WHERE status = 'active' ORDER BY name");
        $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $de) {
        $departments = [];
    }
} catch (PDOException $e) {
    header("Location: index.php?error=Error fetching user data");
    exit();
}

$first_name = !empty($user['first_name']) ? $user['first_name'] : '';
$other_names = !empty($user['other_names']) ? $user['other_names'] : '';
$last_name = !empty($user['last_name']) ? $user['last_name'] : '';

// Fallback split name if empty
if (empty($first_name) && empty($last_name) && !empty($user['name'])) {
    $fullName = trim($user['name']);
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = !empty($_POST['first_name']) ? filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING) : $first_name;
    $other_names = filter_input(INPUT_POST, 'other_names', FILTER_SANITIZE_STRING);
    $last_name = !empty($_POST['last_name']) ? filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING) : $last_name;
    $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));
    $email = !empty($_POST['email']) ? filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) : $user['email'];
    $role = !empty($_POST['role']) ? filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING) : $user['role'];
    $status = (($_POST['status'] ?? '') === 'inactive') ? 'inactive' : 'active';
    $password = !empty($_POST['password']) ? password_hash($_POST['password'], PASSWORD_DEFAULT) : null;

    // Handle Profile Picture Upload
    $profile_picture = null;
    $update_picture = false;
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        if (in_array($_FILES['profile_picture']['type'], $allowed_types)) {
            $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
            $filename = uniqid('profile_') . '.' . $ext;
            $upload_dir = '../uploads/profile_pictures/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $filename)) {
                $profile_picture = $filename;
                $update_picture = true;
            }
        }
    }

    try {
        $db->beginTransaction();

        $student_id_val = null;
        $staff_roles = ['school_admin', 'principal', 'teacher', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];
        if ($role === 'student') {
            $student_id_val = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_STRING);
            if (empty($student_id_val)) {
                throw new Exception("Student ID is required.");
            }
            $sid_check = "SELECT user_id FROM student_profiles WHERE student_id = :student_id AND user_id != :user_id";
            $sid_stmt = $db->prepare($sid_check);
            $sid_stmt->execute([':student_id' => $student_id_val, ':user_id' => $id]);
            if ($sid_stmt->rowCount() > 0) {
                throw new Exception("Student ID already exists for another student.");
            }
        } elseif (in_array($role, $staff_roles)) {
            $employee_id_val = filter_input(INPUT_POST, 'employee_id', FILTER_SANITIZE_STRING);
            if (empty($employee_id_val)) {
                throw new Exception("Employee ID is required.");
            }
            $emp_check = "SELECT user_id FROM teacher_profiles WHERE employee_id = :employee_id AND user_id != :user_id";
            $emp_stmt = $db->prepare($emp_check);
            $emp_stmt->execute([':employee_id' => $employee_id_val, ':user_id' => $id]);
            if ($emp_stmt->rowCount() > 0) {
                throw new Exception("Employee ID already exists for another staff member.");
            }
        }

        $query = "UPDATE users SET name = :name, first_name = :first_name, other_names = :other_names, last_name = :last_name, email = :email, role = :role, status = :status";
        if ($role === 'student') {
            $query .= ", student_id = :student_id";
        } else {
            $query .= ", student_id = NULL";
        }
        if ($password) $query .= ", password = :password";
        if ($update_picture) $query .= ", profile_picture = :profile_picture";
        $query .= " WHERE id = :id";

        $update_stmt = $db->prepare($query);
        $update_stmt->bindParam(':name', $name);
        $update_stmt->bindParam(':first_name', $first_name);
        $update_stmt->bindParam(':other_names', $other_names);
        $update_stmt->bindParam(':last_name', $last_name);
        $update_stmt->bindParam(':email', $email);
        $update_stmt->bindParam(':role', $role);
        $update_stmt->bindParam(':status', $status);
        $update_stmt->bindParam(':id', $id);
        
        if ($role === 'student') {
            $update_stmt->bindParam(':student_id', $student_id_val);
        }
        if ($password) {
            $update_stmt->bindParam(':password', $password);
        }
        if ($update_picture) {
            $update_stmt->bindParam(':profile_picture', $profile_picture);
            if ($id == $_SESSION['user_id']) {
                $_SESSION['profile_picture'] = $profile_picture;
            }
        }

        $update_stmt->execute();

        if ($role === 'student') {
            $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
            $date_of_birth = $_POST['date_of_birth'] ?? '';
            $gender = $_POST['gender'] ?? '';
            $blood_group = filter_input(INPUT_POST, 'blood_group', FILTER_SANITIZE_STRING);
            $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
            $guardian_name = filter_input(INPUT_POST, 'guardian_name', FILTER_SANITIZE_STRING);
            $guardian_phone = filter_input(INPUT_POST, 'guardian_phone', FILTER_SANITIZE_STRING);
            $guardian_email = filter_input(INPUT_POST, 'guardian_email', FILTER_SANITIZE_EMAIL);
            $emergency_contact_name = filter_input(INPUT_POST, 'emergency_contact_name', FILTER_SANITIZE_STRING);
            $emergency_contact_phone = filter_input(INPUT_POST, 'emergency_contact_phone', FILTER_SANITIZE_STRING);
            $medical_conditions = filter_input(INPUT_POST, 'medical_conditions', FILTER_SANITIZE_STRING);
            $admission_date = $_POST['admission_date'] ?? '';
            $previous_school = filter_input(INPUT_POST, 'previous_school', FILTER_SANITIZE_STRING);

            $check_stmt = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE user_id = :user_id");
            $check_stmt->execute([':user_id' => $id]);
            $profile_exists = $check_stmt->fetchColumn() > 0;

            if ($profile_exists) {
                $profile_query = "UPDATE student_profiles SET
                                 student_id = :student_id,
                                 phone = :phone,
                                 date_of_birth = :date_of_birth,
                                 gender = :gender,
                                 blood_group = :blood_group,
                                 address = :address,
                                 guardian_name = :guardian_name,
                                 guardian_phone = :guardian_phone,
                                 guardian_email = :guardian_email,
                                 emergency_contact_name = :emergency_contact_name,
                                 emergency_contact_phone = :emergency_contact_phone,
                                 medical_conditions = :medical_conditions,
                                 admission_date = :admission_date,
                                 previous_school = :previous_school
                                 WHERE user_id = :user_id";
                $profile_stmt = $db->prepare($profile_query);
            } else {
                $profile_query = "INSERT INTO student_profiles (
                                    user_id, student_id, phone, date_of_birth, gender, blood_group,
                                    address, guardian_name, guardian_phone, guardian_email,
                                    emergency_contact_name, emergency_contact_phone, medical_conditions,
                                    admission_date, previous_school
                                 ) VALUES (
                                    :user_id, :student_id, :phone, :date_of_birth, :gender, :blood_group,
                                    :address, :guardian_name, :guardian_phone, :guardian_email,
                                    :emergency_contact_name, :emergency_contact_phone, :medical_conditions,
                                    :admission_date, :previous_school
                                 )";
                $profile_stmt = $db->prepare($profile_query);
            }

            $admission_date_val = !empty($admission_date) ? $admission_date : date('Y-m-d');

            $profile_stmt->bindParam(':student_id', $student_id_val);
            $profile_stmt->bindParam(':phone', $phone);
            $profile_stmt->bindParam(':date_of_birth', $date_of_birth);
            $profile_stmt->bindParam(':gender', $gender);
            $profile_stmt->bindParam(':blood_group', $blood_group);
            $profile_stmt->bindParam(':address', $address);
            $profile_stmt->bindParam(':guardian_name', $guardian_name);
            $profile_stmt->bindParam(':guardian_phone', $guardian_phone);
            $profile_stmt->bindParam(':guardian_email', $guardian_email);
            $profile_stmt->bindParam(':emergency_contact_name', $emergency_contact_name);
            $profile_stmt->bindParam(':emergency_contact_phone', $emergency_contact_phone);
            $profile_stmt->bindParam(':medical_conditions', $medical_conditions);
            $profile_stmt->bindParam(':admission_date', $admission_date_val);
            $profile_stmt->bindParam(':previous_school', $previous_school);
            $profile_stmt->bindParam(':user_id', $id);
            $profile_stmt->execute();
        } elseif (in_array($role, $staff_roles)) {
            $phone = filter_input(INPUT_POST, 'staff_phone', FILTER_SANITIZE_STRING);
            $date_of_birth = $_POST['staff_date_of_birth'] ?? '';
            $gender = $_POST['staff_gender'] ?? '';
            $nationality = filter_input(INPUT_POST, 'nationality', FILTER_SANITIZE_STRING);
            $marital_status = $_POST['marital_status'] ?? '';
            $national_id = filter_input(INPUT_POST, 'national_id', FILTER_SANITIZE_STRING);
            $address = filter_input(INPUT_POST, 'staff_address', FILTER_SANITIZE_STRING);
            $city = filter_input(INPUT_POST, 'city', FILTER_SANITIZE_STRING);
            $state_region = filter_input(INPUT_POST, 'state_region', FILTER_SANITIZE_STRING);
            $postal_code = filter_input(INPUT_POST, 'postal_code', FILTER_SANITIZE_STRING);
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_SANITIZE_NUMBER_INT);
            $position = filter_input(INPUT_POST, 'position', FILTER_SANITIZE_STRING);
            $contract_type = $_POST['contract_type'] ?? 'full_time';
            $joining_date = $_POST['joining_date'] ?? '';
            $qualification = filter_input(INPUT_POST, 'qualification', FILTER_SANITIZE_STRING);
            $specialization = filter_input(INPUT_POST, 'specialization', FILTER_SANITIZE_STRING);
            $experience_years = filter_input(INPUT_POST, 'experience_years', FILTER_SANITIZE_NUMBER_INT);
            $salary = filter_input(INPUT_POST, 'salary', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $bio = filter_input(INPUT_POST, 'bio', FILTER_SANITIZE_STRING);
            
            $bank_name = filter_input(INPUT_POST, 'bank_name', FILTER_SANITIZE_STRING);
            $bank_account = filter_input(INPUT_POST, 'bank_account', FILTER_SANITIZE_STRING);
            $bank_branch = filter_input(INPUT_POST, 'bank_branch', FILTER_SANITIZE_STRING);
            
            $emergency_contact_name = filter_input(INPUT_POST, 'staff_emergency_contact_name', FILTER_SANITIZE_STRING);
            $emergency_contact_phone = filter_input(INPUT_POST, 'staff_emergency_contact_phone', FILTER_SANITIZE_STRING);
            $emergency_contact_relation = filter_input(INPUT_POST, 'staff_emergency_contact_relation', FILTER_SANITIZE_STRING);

            $employment_status = $_POST['employment_status'] ?? 'active';
            $tax_id = filter_input(INPUT_POST, 'tax_id', FILTER_SANITIZE_STRING);
            $contract_end_date = $_POST['contract_end_date'] ?? '';

            // Fetch department name
            $dept_name = null;
            if (!empty($department_id)) {
                $dept_name_stmt = $db->prepare("SELECT name FROM staff_departments WHERE id = :id");
                $dept_name_stmt->execute([':id' => $department_id]);
                $dept_name = $dept_name_stmt->fetchColumn() ?: null;
            }

            $check_stmt = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE user_id = :user_id");
            $check_stmt->execute([':user_id' => $id]);
            $profile_exists = $check_stmt->fetchColumn() > 0;

            if ($profile_exists) {
                $profile_query = "UPDATE teacher_profiles SET
                                 employee_id = :employee_id,
                                 phone = :phone,
                                 date_of_birth = :date_of_birth,
                                 gender = :gender,
                                 nationality = :nationality,
                                 marital_status = :marital_status,
                                 national_id = :national_id,
                                 address = :address,
                                 city = :city,
                                 state_region = :state_region,
                                 postal_code = :postal_code,
                                 department_id = :department_id,
                                 department = :department,
                                 position = :position,
                                 contract_type = :contract_type,
                                 joining_date = :joining_date,
                                 qualification = :qualification,
                                 specialization = :specialization,
                                 experience_years = :experience_years,
                                 salary = :salary,
                                 bio = :bio,
                                 bank_name = :bank_name,
                                 bank_account = :bank_account,
                                 bank_branch = :bank_branch,
                                 emergency_contact_name = :emergency_contact_name,
                                 emergency_contact_phone = :emergency_contact_phone,
                                 emergency_contact_relation = :emergency_contact_relation,
                                 employment_status = :employment_status,
                                 tax_id = :tax_id,
                                 contract_end_date = :contract_end_date
                                 WHERE user_id = :user_id";
                $profile_stmt = $db->prepare($profile_query);
            } else {
                $profile_query = "INSERT INTO teacher_profiles (
                                    user_id, employee_id, phone, date_of_birth, gender, nationality, marital_status,
                                    national_id, address, city, state_region, postal_code, department_id, department,
                                    position, contract_type, joining_date, qualification, specialization,
                                    experience_years, salary, bio, bank_name, bank_account, bank_branch,
                                    emergency_contact_name, emergency_contact_phone, emergency_contact_relation,
                                    employment_status, tax_id, contract_end_date
                                 ) VALUES (
                                    :user_id, :employee_id, :phone, :date_of_birth, :gender, :nationality, :marital_status,
                                    :national_id, :address, :city, :state_region, :postal_code, :department_id, :department,
                                    :position, :contract_type, :joining_date, :qualification, :specialization,
                                    :experience_years, :salary, :bio, :bank_name, :bank_account, :bank_branch,
                                    :emergency_contact_name, :emergency_contact_phone, :emergency_contact_relation,
                                    :employment_status, :tax_id, :contract_end_date
                                 )";
                $profile_stmt = $db->prepare($profile_query);
            }

            $joining_date_val = !empty($joining_date) ? $joining_date : date('Y-m-d');

            $profile_stmt->bindValue(':employee_id', $employee_id_val);
            $profile_stmt->bindValue(':phone', !empty($phone) ? $phone : null);
            $profile_stmt->bindValue(':date_of_birth', !empty($date_of_birth) ? $date_of_birth : null);
            $profile_stmt->bindValue(':gender', !empty($gender) ? $gender : null);
            $profile_stmt->bindValue(':nationality', !empty($nationality) ? $nationality : null);
            $profile_stmt->bindValue(':marital_status', !empty($marital_status) ? $marital_status : null);
            $profile_stmt->bindValue(':national_id', !empty($national_id) ? $national_id : null);
            $profile_stmt->bindValue(':address', !empty($address) ? $address : null);
            $profile_stmt->bindValue(':city', !empty($city) ? $city : null);
            $profile_stmt->bindValue(':state_region', !empty($state_region) ? $state_region : null);
            $profile_stmt->bindValue(':postal_code', !empty($postal_code) ? $postal_code : null);
            $profile_stmt->bindValue(':department_id', !empty($department_id) ? $department_id : null);
            $profile_stmt->bindValue(':department', $dept_name);
            $profile_stmt->bindValue(':position', !empty($position) ? $position : null);
            $profile_stmt->bindValue(':contract_type', $contract_type);
            $profile_stmt->bindValue(':joining_date', $joining_date_val);
            $profile_stmt->bindValue(':qualification', !empty($qualification) ? $qualification : null);
            $profile_stmt->bindValue(':specialization', !empty($specialization) ? $specialization : null);
            $profile_stmt->bindValue(':experience_years', ($experience_years !== null && $experience_years !== '') ? (int)$experience_years : 0);
            $profile_stmt->bindValue(':salary', ($salary !== null && $salary !== '') ? $salary : null);
            $profile_stmt->bindValue(':bio', !empty($bio) ? $bio : null);
            $profile_stmt->bindValue(':bank_name', !empty($bank_name) ? $bank_name : null);
            $profile_stmt->bindValue(':bank_account', !empty($bank_account) ? $bank_account : null);
            $profile_stmt->bindValue(':bank_branch', !empty($bank_branch) ? $bank_branch : null);
            $profile_stmt->bindValue(':emergency_contact_name', !empty($emergency_contact_name) ? $emergency_contact_name : null);
            $profile_stmt->bindValue(':emergency_contact_phone', !empty($emergency_contact_phone) ? $emergency_contact_phone : null);
            $profile_stmt->bindValue(':emergency_contact_relation', !empty($emergency_contact_relation) ? $emergency_contact_relation : null);
            $profile_stmt->bindValue(':employment_status', $employment_status);
            $profile_stmt->bindValue(':tax_id', !empty($tax_id) ? $tax_id : null);
            $profile_stmt->bindValue(':contract_end_date', !empty($contract_end_date) ? $contract_end_date : null);
            $profile_stmt->bindValue(':user_id', $id);
            $profile_stmt->execute();

            // Digital signature upload (kept consistent with staff/edit.php).
            if (isset($_FILES['signature_image']) && $_FILES['signature_image']['error'] === UPLOAD_ERR_OK) {
                $sig_ext = strtolower(pathinfo($_FILES['signature_image']['name'], PATHINFO_EXTENSION));
                if (in_array($sig_ext, ['png', 'jpg', 'jpeg', 'gif']) && $_FILES['signature_image']['size'] <= 1024 * 1024) {
                    require_once '../includes/signature_helper.php';
                    $sig_dir = '../uploads/signatures/';
                    if (!file_exists($sig_dir)) { @mkdir($sig_dir, 0777, true); }
                    $sig_base = 'staffsig_' . $id . '_' . time();
                    $raw_name = $sig_base . '.' . $sig_ext;
                    if (move_uploaded_file($_FILES['signature_image']['tmp_name'], $sig_dir . $raw_name)) {
                        // Normalize so the signature prints at a consistent size.
                        $sig_name = $sig_base . '.png';
                        if (normalizeSignatureImage($sig_dir . $raw_name, $sig_dir . $sig_name)) {
                            if ($raw_name !== $sig_name) { @unlink($sig_dir . $raw_name); }
                        } else {
                            $sig_name = $raw_name; // keep original if normalization fails
                        }
                        $old = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
                        $old->execute([':id' => $id]);
                        $old_file = $old->fetchColumn();
                        if ($old_file && $old_file !== $sig_name && file_exists($sig_dir . $old_file)) { @unlink($sig_dir . $old_file); }
                        $db->prepare("UPDATE teacher_profiles SET signature_image = :s WHERE user_id = :id")
                           ->execute([':s' => $sig_name, ':id' => $id]);
                    }
                }
            } elseif (!empty($_POST['remove_signature'])) {
                $rm = $db->prepare("SELECT signature_image FROM teacher_profiles WHERE user_id = :id");
                $rm->execute([':id' => $id]);
                $rm_file = $rm->fetchColumn();
                if ($rm_file && file_exists('../uploads/signatures/' . $rm_file)) { @unlink('../uploads/signatures/' . $rm_file); }
                $db->prepare("UPDATE teacher_profiles SET signature_image = NULL WHERE user_id = :id")
                   ->execute([':id' => $id]);
            }
        }

        $db->commit();
        header("Location: index.php?success=User updated successfully");
        exit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Error updating user: " . $e->getMessage();
        if ($e->getCode() == 23000) {
            $error = "Email address or Student ID already exists.";
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit User</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 dark:bg-red-900 dark:border-red-700 dark:text-red-300">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                <form action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                    <!-- Profile Picture -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Profile Picture</label>
                        <div class="flex items-center space-x-4">
                            <div class="w-20 h-20 rounded-full bg-gray-100 flex items-center justify-center overflow-hidden border border-gray-300" id="imagePreviewContainer">
                                <?php if(!empty($user['profile_picture'])): ?>
                                    <img id="imagePreview" src="/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <i class="fas fa-user text-3xl text-gray-400" id="defaultIcon"></i>
                                    <img id="imagePreview" src="#" alt="Preview" class="hidden w-full h-full object-cover">
                                <?php endif; ?>
                            </div>
                            <div>
                                <input type="file" name="profile_picture" accept="image/jpeg, image/png, image/gif"
                                    data-cropper data-crop-preview="#imagePreview" data-crop-icon="#defaultIcon"
                                    class="block w-full text-sm">
                                <p class="mt-1 text-xs text-gray-500">PNG, JPG, GIF up to 2MB. Crop to frame the face before saving.</p>
                            </div>
                        </div>
                    </div>

                    <?php
                    $first_name_val = $_POST['first_name'] ?? $first_name;
                    $other_names_val = $_POST['other_names'] ?? $other_names;
                    $last_name_val = $_POST['last_name'] ?? $last_name;

                    $student_id_val = $_POST['student_id'] ?? ($student_profile['student_id'] ?? '');
                    $phone_val = $_POST['phone'] ?? ($student_profile['phone'] ?? '');
                    $dob_val = $_POST['date_of_birth'] ?? ($student_profile['date_of_birth'] ?? '');
                    $gender_val = $_POST['gender'] ?? ($student_profile['gender'] ?? '');
                    $blood_group_val = $_POST['blood_group'] ?? ($student_profile['blood_group'] ?? '');
                    $admission_date_val = $_POST['admission_date'] ?? ($student_profile['admission_date'] ?? '');
                    $previous_school_val = $_POST['previous_school'] ?? ($student_profile['previous_school'] ?? '');
                    $medical_conditions_val = $_POST['medical_conditions'] ?? ($student_profile['medical_conditions'] ?? '');
                    $address_val = $_POST['address'] ?? ($student_profile['address'] ?? '');
                    $guardian_name_val = $_POST['guardian_name'] ?? ($student_profile['guardian_name'] ?? '');
                    $guardian_phone_val = $_POST['guardian_phone'] ?? ($student_profile['guardian_phone'] ?? '');
                    $guardian_email_val = $_POST['guardian_email'] ?? ($student_profile['guardian_email'] ?? '');
                    $emergency_contact_name_val = $_POST['emergency_contact_name'] ?? ($student_profile['emergency_contact_name'] ?? '');
                    $emergency_contact_phone_val = $_POST['emergency_contact_phone'] ?? ($student_profile['emergency_contact_phone'] ?? '');

                    $employee_id_val = $_POST['employee_id'] ?? ($staff_profile['employee_id'] ?? '');
                    $staff_phone_val = $_POST['staff_phone'] ?? ($staff_profile['phone'] ?? '');
                    $staff_dob_val = $_POST['staff_date_of_birth'] ?? ($staff_profile['date_of_birth'] ?? '');
                    $staff_gender_val = $_POST['staff_gender'] ?? ($staff_profile['gender'] ?? '');
                    $nationality_val = $_POST['nationality'] ?? ($staff_profile['nationality'] ?? '');
                    $marital_status_val = $_POST['marital_status'] ?? ($staff_profile['marital_status'] ?? '');
                    $national_id_val = $_POST['national_id'] ?? ($staff_profile['national_id'] ?? '');
                    $staff_address_val = $_POST['staff_address'] ?? ($staff_profile['address'] ?? '');
                    $staff_city_val = $_POST['city'] ?? ($staff_profile['city'] ?? '');
                    $staff_state_val = $_POST['state_region'] ?? ($staff_profile['state_region'] ?? '');
                    $staff_postal_val = $_POST['postal_code'] ?? ($staff_profile['postal_code'] ?? '');
                    $department_id_val = $_POST['department_id'] ?? ($staff_profile['department_id'] ?? '');
                    $position_val = $_POST['position'] ?? ($staff_profile['position'] ?? '');
                    $contract_type_val = $_POST['contract_type'] ?? ($staff_profile['contract_type'] ?? '');
                    $joining_date_val = $_POST['joining_date'] ?? ($staff_profile['joining_date'] ?? '');
                    $qualification_val = $_POST['qualification'] ?? ($staff_profile['qualification'] ?? '');
                    $specialization_val = $_POST['specialization'] ?? ($staff_profile['specialization'] ?? '');
                    $experience_years_val = $_POST['experience_years'] ?? ($staff_profile['experience_years'] ?? '');
                    $salary_val = $_POST['salary'] ?? ($staff_profile['salary'] ?? '');
                    $bio_val = $_POST['bio'] ?? ($staff_profile['bio'] ?? '');
                    $bank_name_val = $_POST['bank_name'] ?? ($staff_profile['bank_name'] ?? '');
                    $bank_account_val = $_POST['bank_account'] ?? ($staff_profile['bank_account'] ?? '');
                    $bank_branch_val = $_POST['bank_branch'] ?? ($staff_profile['bank_branch'] ?? '');
                    $staff_emergency_name_val = $_POST['staff_emergency_contact_name'] ?? ($staff_profile['emergency_contact_name'] ?? '');
                    $staff_emergency_phone_val = $_POST['staff_emergency_contact_phone'] ?? ($staff_profile['emergency_contact_phone'] ?? '');
                    $emergency_contact_relation_val = $_POST['staff_emergency_contact_relation'] ?? ($staff_profile['emergency_contact_relation'] ?? '');
                    $employment_status_val = $_POST['employment_status'] ?? ($staff_profile['employment_status'] ?? 'active');
                    $tax_id_val = $_POST['tax_id'] ?? ($staff_profile['tax_id'] ?? '');
                    $contract_end_date_val = $_POST['contract_end_date'] ?? ($staff_profile['contract_end_date'] ?? '');
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name</label>
                            <input type="text" id="first_name" name="first_name" required
                                value="<?php echo htmlspecialchars($first_name_val); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="other_names" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Other Name(s)</label>
                            <input type="text" id="other_names" name="other_names"
                                value="<?php echo htmlspecialchars($other_names_val); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required
                                value="<?php echo htmlspecialchars($last_name_val); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address</label>
                        <input type="email" id="email" name="email"
                            value="<?php echo htmlspecialchars($user['email']); ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                        <input type="password" id="password" name="password" minlength="6"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <p class="mt-1 text-sm text-gray-500">Leave blank to keep current password</p>
                    </div>

                    <div>
                        <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Role</label>
                        <select id="role" name="role"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <?php
                            $roles = ['school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];
                            if ($_SESSION['role'] === 'super_admin') {
                                array_unshift($roles, 'super_admin');
                            }
                            foreach ($roles as $role) {
                                $selected = $user['role'] === $role ? 'selected' : '';
                                echo "<option value=\"$role\" $selected>" . formatRoleName($role) . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Status</label>
                        <select id="status" name="status"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="active" <?php echo (($user['status'] ?? 'active') === 'active') ? 'selected' : ''; ?>>Active (can log in)</option>
                            <option value="inactive" <?php echo (($user['status'] ?? '') === 'inactive') ? 'selected' : ''; ?>>Inactive (login disabled)</option>
                        </select>
                    </div>

                    <!-- Student specific fields container -->
                    <div id="student-fields-container" class="space-y-6 pt-6 border-t border-gray-200 dark:border-gray-700 hidden">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">Student Profile Information</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student ID *</label>
                                <input type="text" id="student_id" name="student_id" value="<?php echo htmlspecialchars($student_id_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone Number</label>
                                <input type="text" id="phone" name="phone" value="<?php echo htmlspecialchars($phone_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="date_of_birth" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date of Birth</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars($dob_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gender</label>
                                <select id="gender" name="gender"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo $gender_val === 'Male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo $gender_val === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo $gender_val === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="blood_group" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Blood Group</label>
                                <input type="text" id="blood_group" name="blood_group" placeholder="e.g. O+, A-" value="<?php echo htmlspecialchars($blood_group_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="admission_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Admission Date</label>
                                <input type="date" id="admission_date" name="admission_date" value="<?php echo htmlspecialchars($admission_date_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="previous_school" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Previous School</label>
                                <input type="text" id="previous_school" name="previous_school" value="<?php echo htmlspecialchars($previous_school_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="medical_conditions" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Medical Conditions</label>
                                <input type="text" id="medical_conditions" name="medical_conditions" placeholder="e.g. Asthma, none" value="<?php echo htmlspecialchars($medical_conditions_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div>
                            <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                            <textarea id="address" name="address" rows="2"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($address_val); ?></textarea>
                        </div>

                        <h3 class="text-lg font-medium text-gray-900 dark:text-white pt-4 border-t border-gray-200 dark:border-gray-700">Guardian & Emergency Information</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="guardian_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Name</label>
                                <input type="text" id="guardian_name" name="guardian_name" value="<?php echo htmlspecialchars($guardian_name_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="guardian_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Phone</label>
                                <input type="text" id="guardian_phone" name="guardian_phone" value="<?php echo htmlspecialchars($guardian_phone_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="guardian_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Email</label>
                                <input type="email" id="guardian_email" name="guardian_email" value="<?php echo htmlspecialchars($guardian_email_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Emergency Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name" value="<?php echo htmlspecialchars($emergency_contact_name_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Emergency Contact Phone</label>
                                <input type="text" id="emergency_contact_phone" name="emergency_contact_phone" value="<?php echo htmlspecialchars($emergency_contact_phone_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>

                    <!-- Staff specific fields container -->
                    <div id="staff-fields-container" class="space-y-6 pt-6 border-t border-gray-200 dark:border-gray-700 hidden">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white">Staff Profile Information</h3>

                        <!-- Digital Signature -->
                        <?php $staff_sig = $staff_profile['signature_image'] ?? ''; ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Digital Signature (Optional)</label>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-2">Embedded on documents this person signs (e.g. report cards as class teacher), when signatures are enabled in Settings.</p>
                            <div class="flex items-center space-x-4">
                                <div class="w-40 h-20 rounded-lg bg-white dark:bg-gray-700 border border-dashed border-gray-300 dark:border-gray-600 flex items-center justify-center overflow-hidden">
                                    <?php if (!empty($staff_sig) && file_exists('../uploads/signatures/' . $staff_sig)): ?>
                                        <img src="/serve_image.php?path=signatures/<?php echo rawurlencode($staff_sig); ?>" alt="Signature" class="max-h-full max-w-full object-contain">
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400"><i class="fas fa-signature mr-1"></i>No signature</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <input type="file" name="signature_image" accept="image/png, image/jpeg, image/gif"
                                        class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 dark:file:bg-gray-700 dark:file:text-gray-300 transition-colors cursor-pointer">
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Transparent PNG recommended, max 1MB.</p>
                                    <?php if (!empty($staff_sig)): ?>
                                    <label class="inline-flex items-center mt-2 text-xs text-red-600 cursor-pointer">
                                        <input type="checkbox" name="remove_signature" value="1" class="mr-1.5">Remove current signature
                                    </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="employee_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Staff / Employee ID *</label>
                                <input type="text" id="employee_id" name="employee_id" value="<?php echo htmlspecialchars($employee_id_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone Number</label>
                                <input type="text" id="staff_phone" name="staff_phone" value="<?php echo htmlspecialchars($staff_phone_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_date_of_birth" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date of Birth</label>
                                <input type="date" id="staff_date_of_birth" name="staff_date_of_birth" value="<?php echo htmlspecialchars($staff_dob_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="staff_gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gender</label>
                                <select id="staff_gender" name="staff_gender"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo $staff_gender_val === 'male' ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo $staff_gender_val === 'female' ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo $staff_gender_val === 'other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="nationality" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nationality</label>
                                <input type="text" id="nationality" name="nationality" value="<?php echo htmlspecialchars($nationality_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="marital_status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Marital Status</label>
                                <select id="marital_status" name="marital_status"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Status</option>
                                    <option value="single" <?php echo $marital_status_val === 'single' ? 'selected' : ''; ?>>Single</option>
                                    <option value="married" <?php echo $marital_status_val === 'married' ? 'selected' : ''; ?>>Married</option>
                                    <option value="divorced" <?php echo $marital_status_val === 'divorced' ? 'selected' : ''; ?>>Divorced</option>
                                    <option value="widowed" <?php echo $marital_status_val === 'widowed' ? 'selected' : ''; ?>>Widowed</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="national_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">National ID / SSN</label>
                                <input type="text" id="national_id" name="national_id" value="<?php echo htmlspecialchars($national_id_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="department_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Department</label>
                                <select id="department_id" name="department_id"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo $department_id_val == $dept['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="position" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Position / Title</label>
                                <input type="text" id="position" name="position" value="<?php echo htmlspecialchars($position_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="contract_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contract Type</label>
                                <select id="contract_type" name="contract_type"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="full_time" <?php echo $contract_type_val === 'full_time' ? 'selected' : ''; ?>>Full-Time</option>
                                    <option value="part_time" <?php echo $contract_type_val === 'part_time' ? 'selected' : ''; ?>>Part-Time</option>
                                    <option value="contract" <?php echo $contract_type_val === 'contract' ? 'selected' : ''; ?>>Contract</option>
                                    <option value="temporary" <?php echo $contract_type_val === 'temporary' ? 'selected' : ''; ?>>Temporary</option>
                                </select>
                            </div>
                            <div>
                                <label for="joining_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Joining Date</label>
                                <input type="date" id="joining_date" name="joining_date" value="<?php echo htmlspecialchars($joining_date_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="salary" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Basic Salary</label>
                                <input type="number" step="0.01" id="salary" name="salary" value="<?php echo htmlspecialchars($salary_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="employment_status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Employment Status</label>
                                <select id="employment_status" name="employment_status"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="active" <?php echo $employment_status_val === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="on_leave" <?php echo $employment_status_val === 'on_leave' ? 'selected' : ''; ?>>On Leave</option>
                                    <option value="suspended" <?php echo $employment_status_val === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="terminated" <?php echo $employment_status_val === 'terminated' ? 'selected' : ''; ?>>Terminated</option>
                                    <option value="retired" <?php echo $employment_status_val === 'retired' ? 'selected' : ''; ?>>Retired</option>
                                </select>
                            </div>
                            <div>
                                <label for="contract_end_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contract End Date</label>
                                <input type="date" id="contract_end_date" name="contract_end_date" value="<?php echo htmlspecialchars($contract_end_date_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="tax_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tax ID / TIN</label>
                                <input type="text" id="tax_id" name="tax_id" value="<?php echo htmlspecialchars($tax_id_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="md:col-span-2">
                                <label for="qualification" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Highest Qualification</label>
                                <input type="text" id="qualification" name="qualification" value="<?php echo htmlspecialchars($qualification_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="experience_years" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Experience Years</label>
                                <input type="number" id="experience_years" name="experience_years" value="<?php echo htmlspecialchars($experience_years_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="specialization" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Specialization</label>
                                <input type="text" id="specialization" name="specialization" value="<?php echo htmlspecialchars($specialization_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Address</label>
                                <input type="text" id="staff_address" name="staff_address" value="<?php echo htmlspecialchars($staff_address_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="staff_city" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">City</label>
                                <input type="text" id="staff_city" name="city" value="<?php echo htmlspecialchars($staff_city_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_state_region" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">State / Region</label>
                                <input type="text" id="staff_state_region" name="state_region" value="<?php echo htmlspecialchars($staff_state_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_postal_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Postal Code</label>
                                <input type="text" id="staff_postal_code" name="postal_code" value="<?php echo htmlspecialchars($staff_postal_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div>
                            <label for="bio" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bio / Notes</label>
                            <textarea id="bio" name="bio" rows="2"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($bio_val); ?></textarea>
                        </div>

                        <h3 class="text-lg font-medium text-gray-900 dark:text-white pt-4 border-t border-gray-200 dark:border-gray-700">Banking Information</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="bank_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bank Name</label>
                                <input type="text" id="bank_name" name="bank_name" value="<?php echo htmlspecialchars($bank_name_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="bank_account" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Number</label>
                                <input type="text" id="bank_account" name="bank_account" value="<?php echo htmlspecialchars($bank_account_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="bank_branch" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bank Branch</label>
                                <input type="text" id="bank_branch" name="bank_branch" value="<?php echo htmlspecialchars($bank_branch_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <h3 class="text-lg font-medium text-gray-900 dark:text-white pt-4 border-t border-gray-200 dark:border-gray-700">Emergency &amp; Next of Kin</h3>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="staff_emergency_contact_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contact Name</label>
                                <input type="text" id="staff_emergency_contact_name" name="staff_emergency_contact_name" value="<?php echo htmlspecialchars($staff_emergency_name_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="staff_emergency_contact_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Contact Phone</label>
                                <input type="text" id="staff_emergency_contact_phone" name="staff_emergency_contact_phone" value="<?php echo htmlspecialchars($staff_emergency_phone_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="emergency_contact_relation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Relationship</label>
                                <input type="text" id="emergency_contact_relation" name="staff_emergency_contact_relation" value="<?php echo htmlspecialchars($emergency_contact_relation_val); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>

                    <div class="pt-4">
                        <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Update User
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
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('imagePreview').src = e.target.result;
            document.getElementById('imagePreview').classList.remove('hidden');
            var defaultIcon = document.getElementById('defaultIcon');
            if(defaultIcon) defaultIcon.classList.add('hidden');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const studentFieldsContainer = document.getElementById('student-fields-container');
    const studentIdInput = document.getElementById('student_id');
    const staffFieldsContainer = document.getElementById('staff-fields-container');
    const employeeIdInput = document.getElementById('employee_id');
    const staffRoles = ['school_admin', 'principal', 'teacher', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];

    function toggleRoleFields() {
        const role = roleSelect.value;
        if (role === 'student') {
            studentFieldsContainer.classList.remove('hidden');
            studentIdInput.setAttribute('required', 'required');
            
            if (staffFieldsContainer) staffFieldsContainer.classList.add('hidden');
            if (employeeIdInput) employeeIdInput.removeAttribute('required');
        } else if (staffRoles.includes(role)) {
            if (staffFieldsContainer) staffFieldsContainer.classList.remove('hidden');
            if (employeeIdInput) employeeIdInput.setAttribute('required', 'required');
            
            studentFieldsContainer.classList.add('hidden');
            studentIdInput.removeAttribute('required');
        } else {
            studentFieldsContainer.classList.add('hidden');
            studentIdInput.removeAttribute('required');
            
            if (staffFieldsContainer) staffFieldsContainer.classList.add('hidden');
            if (employeeIdInput) employeeIdInput.removeAttribute('required');
        }
    }

    if (roleSelect) {
        roleSelect.addEventListener('change', toggleRoleFields);
        toggleRoleFields();
    }
});
</script>