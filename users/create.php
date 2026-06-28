<?php
session_start();
require_once '../includes/access_control.php';
// Creating users from the User Management section is open to super admins and
// school admins (e.g. so a school admin can set up the headmaster/headmistress
// account). Which roles each may assign is constrained by $creatable_roles below.
requireRole(['super_admin', 'school_admin']);

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs that predate some optional profile columns so the
// student/staff profile inserts below never fail on a missing column.
ensureStudentProfileColumns($db);
ensureTeacherProfileColumns($db);

$staff_roles = ['school_admin', 'principal', 'teacher', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];
$all_roles   = array_merge(['super_admin'], $staff_roles, ['student', 'parent']);

// Roles the *current* user is allowed to assign. School admins may create every
// role except super_admin (no privilege escalation above their own tier);
// super admins may assign anything.
$creatable_roles = ($_SESSION['role'] === 'super_admin')
    ? $all_roles
    : array_values(array_diff($all_roles, ['super_admin']));

$errors = [];
// Preserve submitted values so the form can be re-rendered on error.
$first_name = $other_names = $last_name = $email = '';
$role = 'student';
$status = 'active';
$student_id_val = $employee_id_val = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name     = trim(filter_input(INPUT_POST, 'first_name', FILTER_SANITIZE_STRING) ?? '');
    $other_names    = trim(filter_input(INPUT_POST, 'other_names', FILTER_SANITIZE_STRING) ?? '');
    $last_name      = trim(filter_input(INPUT_POST, 'last_name', FILTER_SANITIZE_STRING) ?? '');
    $email          = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL) ?? '');
    $role           = filter_input(INPUT_POST, 'role', FILTER_SANITIZE_STRING) ?? '';
    $status         = (($_POST['status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
    $password       = $_POST['password'] ?? '';
    $password2      = $_POST['password_confirm'] ?? '';
    $student_id_val = trim(filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_STRING) ?? '');
    $employee_id_val = trim(filter_input(INPUT_POST, 'employee_id', FILTER_SANITIZE_STRING) ?? '');

    // ---- Validation ----
    if ($first_name === '' || $last_name === '') {
        $errors[] = 'First name and last name are required.';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (!in_array($role, $creatable_roles, true)) {
        $errors[] = 'Please choose a valid role you are permitted to assign.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    } elseif ($password !== $password2) {
        $errors[] = 'Password and confirmation do not match.';
    }

    // Role-specific identifiers.
    if ($role === 'student') {
        if ($student_id_val === '') {
            $student_id_val = $database->generateStudentId();
        }
    } elseif (in_array($role, $staff_roles, true)) {
        if ($employee_id_val === '') {
            $errors[] = 'Employee / Staff ID is required for staff roles.';
        }
    }

    // Uniqueness checks (only worth running once the basics are valid).
    if (empty($errors)) {
        $dup = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
        $dup->execute([':email' => $email]);
        if ($dup->fetchColumn() > 0) {
            $errors[] = 'A user with that email already exists.';
        }

        if ($role === 'student' && $student_id_val !== '') {
            $sid = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE student_id = :sid");
            $sid->execute([':sid' => $student_id_val]);
            if ($sid->fetchColumn() > 0) {
                $errors[] = 'That Student ID is already in use.';
            }
        } elseif (in_array($role, $staff_roles, true) && $employee_id_val !== '') {
            $eid = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE employee_id = :eid");
            $eid->execute([':eid' => $employee_id_val]);
            if ($eid->fetchColumn() > 0) {
                $errors[] = 'That Employee / Staff ID is already in use.';
            }
        }
    }

    if (empty($errors)) {
        $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));
        $name = preg_replace('/\s+/', ' ', $name);
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Optional profile picture.
        $profile_picture = null;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (in_array($_FILES['profile_picture']['type'], $allowed_types, true)) {
                $ext = pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION);
                $filename = uniqid('profile_') . '.' . $ext;
                $upload_dir = '../uploads/profile_pictures/';
                if (!file_exists($upload_dir)) {
                    @mkdir($upload_dir, 0777, true);
                }
                if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $upload_dir . $filename)) {
                    $profile_picture = $filename;
                }
            }
        }

        try {
            $db->beginTransaction();

            $insert = "INSERT INTO users (name, first_name, other_names, last_name, email, password, role, status, profile_picture";
            if ($role === 'student') { $insert .= ", student_id"; }
            $insert .= ") VALUES (:name, :first_name, :other_names, :last_name, :email, :password, :role, :status, :profile_picture";
            if ($role === 'student') { $insert .= ", :student_id"; }
            $insert .= ")";

            $stmt = $db->prepare($insert);
            $stmt->bindValue(':name', $name);
            $stmt->bindValue(':first_name', $first_name);
            $stmt->bindValue(':other_names', $other_names !== '' ? $other_names : null);
            $stmt->bindValue(':last_name', $last_name);
            $stmt->bindValue(':email', $email);
            $stmt->bindValue(':password', $hashed_password);
            $stmt->bindValue(':role', $role);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':profile_picture', $profile_picture);
            if ($role === 'student') { $stmt->bindValue(':student_id', $student_id_val); }
            $stmt->execute();

            $new_user_id = (int)$db->lastInsertId();

            // Minimal matching profile rows so the account behaves like one
            // created through the dedicated student/staff flows.
            if ($role === 'student') {
                $sp = $db->prepare("INSERT INTO student_profiles (user_id, student_id, admission_date)
                                    VALUES (:uid, :sid, :adm)");
                $sp->execute([':uid' => $new_user_id, ':sid' => $student_id_val, ':adm' => date('Y-m-d')]);
            } elseif (in_array($role, $staff_roles, true)) {
                $tp = $db->prepare("INSERT INTO teacher_profiles (user_id, employee_id, joining_date)
                                    VALUES (:uid, :eid, :join)");
                $tp->execute([':uid' => $new_user_id, ':eid' => $employee_id_val, ':join' => date('Y-m-d')]);
            }

            $db->commit();

            // Mirror into the central login directory so the new user can sign in
            // from the main login page (no-op when already on the central DB).
            require_once '../includes/user_directory.php';
            syncUserToCentralDirectory([
                'school_id'    => $_SESSION['school_id'] ?? null,
                'name'         => $name,
                'email'        => $email,
                'password'     => $hashed_password,
                'role'         => $role,
                'status'       => $status,
                'student_id'   => $role === 'student' ? $student_id_val : null,
                'employee_id'  => in_array($role, $staff_roles, true) ? $employee_id_val : null,
                'joining_date' => date('Y-m-d'),
            ]);

            header('Location: index.php?success=' . urlencode('User "' . $name . '" created successfully.'));
            exit();
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if ((int)$e->getCode() === 23000) {
                $errors[] = 'A user with that email, Student ID or Employee ID already exists.';
            } else {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

$title = "Create User";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'User Management', 'url' => 'index.php'],
    ['title' => 'Create User'],
];
include '../includes/header.php';
include '../includes/sidebar.php';

// A blank suggested student ID for the live form default.
$suggested_student_id = $database->generateStudentId();
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create User</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Add a new account to the system.</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 dark:bg-red-900 dark:border-red-700 dark:text-red-300">
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($errors as $err): ?>
                        <li><?php echo htmlspecialchars($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                    <form action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-6"
                          x-data="{ role: '<?php echo htmlspecialchars($role); ?>', staffRoles: <?php echo json_encode($staff_roles); ?> }">

                        <!-- Profile Picture -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Profile Picture (Optional)</label>
                            <div class="flex items-center space-x-4">
                                <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center overflow-hidden border border-gray-300 dark:border-gray-600" id="imagePreviewContainer">
                                    <i class="fas fa-user text-3xl text-gray-400" id="defaultIcon"></i>
                                    <img id="imagePreview" src="#" alt="Preview" class="hidden w-full h-full object-cover">
                                </div>
                                <div>
                                    <input type="file" name="profile_picture" accept="image/jpeg, image/png, image/gif"
                                        data-cropper data-crop-preview="#imagePreview" data-crop-icon="#defaultIcon"
                                        class="block w-full text-sm">
                                    <p class="mt-1 text-xs text-gray-500">PNG, JPG, GIF up to 2MB.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Names -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name *</label>
                                <input type="text" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($first_name); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="other_names" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Other Name(s)</label>
                                <input type="text" id="other_names" name="other_names" value="<?php echo htmlspecialchars($other_names); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Last Name *</label>
                                <input type="text" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($last_name); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <!-- Email -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address *</label>
                            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($email); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <!-- Passwords -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password *</label>
                                <input type="password" id="password" name="password" required minlength="6"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <p class="mt-1 text-xs text-gray-500">Minimum 6 characters.</p>
                            </div>
                            <div>
                                <label for="password_confirm" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Confirm Password *</label>
                                <input type="password" id="password_confirm" name="password_confirm" required minlength="6"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <!-- Role & Status -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="role" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Role *</label>
                                <select id="role" name="role" x-model="role"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php foreach ($creatable_roles as $r): ?>
                                    <option value="<?php echo $r; ?>" <?php echo $role === $r ? 'selected' : ''; ?>><?php echo formatRoleName($r); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Account Status</label>
                                <select id="status" name="status"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active (can log in)</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive (login disabled)</option>
                                </select>
                            </div>
                        </div>

                        <!-- Student ID (students only) -->
                        <div x-show="role === 'student'" x-cloak class="pt-4 border-t border-gray-200 dark:border-gray-700">
                            <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student ID</label>
                            <input type="text" id="student_id" name="student_id"
                                value="<?php echo htmlspecialchars($student_id_val !== '' ? $student_id_val : $suggested_student_id); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <p class="mt-1 text-xs text-gray-500">Auto-generated; change it if needed. Leave as-is to accept the suggestion.</p>
                        </div>

                        <!-- Employee ID (staff roles only) -->
                        <div x-show="staffRoles.includes(role)" x-cloak class="pt-4 border-t border-gray-200 dark:border-gray-700">
                            <label for="employee_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Employee / Staff ID *</label>
                            <input type="text" id="employee_id" name="employee_id" value="<?php echo htmlspecialchars($employee_id_val); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <p class="mt-1 text-xs text-gray-500">Required for staff accounts so they can sign in by Employee ID.</p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" class="px-5 py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">Cancel</a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-6 py-2.5 rounded-lg shadow transition-colors flex items-center">
                                <i class="fas fa-user-plus mr-2"></i>Create User
                            </button>
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

<style>[x-cloak]{display:none!important;}</style>
