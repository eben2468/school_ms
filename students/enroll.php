<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check database connection
if (!$db) {
    die("Database connection failed. Please check your database configuration.");
}

// Verify required tables exist
$required_tables = ['users', 'student_profiles', 'student_classes', 'classes'];
foreach ($required_tables as $table) {
    try {
        $check_stmt = $db->query("SHOW TABLES LIKE '$table'");
        if ($check_stmt->rowCount() == 0) {
            die("Required table '$table' does not exist. Please run the database setup script.");
        }
    } catch (PDOException $e) {
        die("Database error checking table '$table': " . $e->getMessage());
    }
}

// Fetch active classes
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if there are any active classes
if (empty($classes)) {
    $errors[] = "No active classes found. Please create classes first before enrolling students.";
}

// Fetch parents
$parents_query = "SELECT id, name, email FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name";
$parents_stmt = $db->query($parents_query);
$parents = $parents_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $password = $_POST['password'];
    $date_of_birth = filter_input(INPUT_POST, 'date_of_birth', FILTER_SANITIZE_STRING);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_STRING);
    $blood_group = filter_input(INPUT_POST, 'blood_group', FILTER_SANITIZE_STRING);
    $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $emergency_contact_name = filter_input(INPUT_POST, 'emergency_contact_name', FILTER_SANITIZE_STRING);
    $emergency_contact_phone = filter_input(INPUT_POST, 'emergency_contact_phone', FILTER_SANITIZE_STRING);
    $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_SANITIZE_NUMBER_INT);
    $guardian_name = filter_input(INPUT_POST, 'guardian_name', FILTER_SANITIZE_STRING);
    $guardian_phone = filter_input(INPUT_POST, 'guardian_phone', FILTER_SANITIZE_STRING);
    $guardian_email = filter_input(INPUT_POST, 'guardian_email', FILTER_SANITIZE_EMAIL);
    $medical_conditions = filter_input(INPUT_POST, 'medical_conditions', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $admission_date = filter_input(INPUT_POST, 'admission_date', FILTER_SANITIZE_STRING);
    
    // Validation
    $errors = [];
    if (empty($name)) $errors[] = "Student name is required.";
    if (empty($email)) $errors[] = "Email is required.";
    if (empty($password)) $errors[] = "Password is required.";
    if (empty($date_of_birth)) $errors[] = "Date of birth is required.";
    if (empty($class_id)) $errors[] = "Class selection is required.";
    if (empty($admission_date)) $errors[] = "Admission date is required.";
    
    // Check if email already exists
    if (!empty($email)) {
        $email_check = "SELECT id FROM users WHERE email = :email";
        $email_stmt = $db->prepare($email_check);
        $email_stmt->bindParam(':email', $email);
        $email_stmt->execute();
        if ($email_stmt->rowCount() > 0) {
            $errors[] = "Email address already exists.";
        }
    }

    // Validate class ID exists and is active
    if (!empty($class_id)) {
        $class_check = "SELECT id FROM classes WHERE id = :class_id AND status = 'active'";
        $class_stmt = $db->prepare($class_check);
        $class_stmt->bindParam(':class_id', $class_id);
        $class_stmt->execute();
        if ($class_stmt->rowCount() == 0) {
            $errors[] = "Selected class is not valid or inactive.";
        }
    }

    // Validate parent ID if provided and not empty
    if (!empty($parent_id) && $parent_id !== '') {
        $parent_check = "SELECT id, name FROM users WHERE id = :parent_id AND role = 'parent' AND status = 'active'";
        $parent_stmt = $db->prepare($parent_check);
        $parent_stmt->bindParam(':parent_id', $parent_id);
        $parent_stmt->execute();
        $parent_result = $parent_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$parent_result) {
            $errors[] = "Selected parent is not valid or inactive. Please select a different parent or leave it empty.";
        }
    } else {
        // If parent_id is empty or null, set it to null for the database
        $parent_id = null;
    }
    
    if (empty($errors)) {
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

            // Auto-create parent account if guardian email is provided and no parent is selected
            $created_parent_id = null;
            if (empty($parent_id) && !empty($guardian_email) && !empty($guardian_name)) {
                // Check if a user with this email already exists
                $existing_user_query = "SELECT id, role FROM users WHERE email = :email";
                $existing_user_stmt = $db->prepare($existing_user_query);
                $existing_user_stmt->bindParam(':email', $guardian_email);
                $existing_user_stmt->execute();
                $existing_user = $existing_user_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_user) {
                    if ($existing_user['role'] === 'parent') {
                        // Use existing parent account
                        $created_parent_id = $existing_user['id'];
                    } else {
                        // Email exists but not a parent - log this but continue
                        error_log("Guardian email {$guardian_email} exists but user is not a parent (role: {$existing_user['role']})");
                    }
                } else {
                    // Create new parent account
                    $parent_password = password_hash('parent123', PASSWORD_DEFAULT);
                    $create_parent_query = "INSERT INTO users (name, email, password, role, status, created_at) VALUES (:name, :email, :password, 'parent', 'active', NOW())";
                    $create_parent_stmt = $db->prepare($create_parent_query);
                    $create_parent_stmt->bindParam(':name', $guardian_name);
                    $create_parent_stmt->bindParam(':email', $guardian_email);
                    $create_parent_stmt->bindParam(':password', $parent_password);
                    $create_parent_stmt->execute();

                    $created_parent_id = $db->lastInsertId();

                    // Log the parent creation for admin notification
                    error_log("Auto-created parent account for {$guardian_name} ({$guardian_email}) with default password 'parent123'");
                }

                // Use the created/found parent ID
                if ($created_parent_id) {
                    $parent_id = $created_parent_id;
                }
            }

            // Create student profile
            $profile_query = "INSERT INTO student_profiles (
                user_id, student_id, admission_date, date_of_birth, gender, blood_group, 
                address, phone, emergency_contact_name, emergency_contact_phone, 
                parent_id, guardian_name, guardian_phone, guardian_email, medical_conditions
            ) VALUES (
                :user_id, :student_id, :admission_date, :date_of_birth, :gender, :blood_group,
                :address, :phone, :emergency_contact_name, :emergency_contact_phone,
                :parent_id, :guardian_name, :guardian_phone, :guardian_email, :medical_conditions
            )";
            $profile_stmt = $db->prepare($profile_query);
            $profile_stmt->bindParam(':user_id', $user_id);
            $profile_stmt->bindParam(':student_id', $student_id);
            $profile_stmt->bindParam(':admission_date', $admission_date);
            $profile_stmt->bindParam(':date_of_birth', $date_of_birth);
            $profile_stmt->bindParam(':gender', $gender);
            $profile_stmt->bindParam(':blood_group', $blood_group);
            $profile_stmt->bindParam(':address', $address);
            $profile_stmt->bindParam(':phone', $phone);
            $profile_stmt->bindParam(':emergency_contact_name', $emergency_contact_name);
            $profile_stmt->bindParam(':emergency_contact_phone', $emergency_contact_phone);
            $profile_stmt->bindParam(':parent_id', $parent_id, PDO::PARAM_INT);
            $profile_stmt->bindParam(':guardian_name', $guardian_name);
            $profile_stmt->bindParam(':guardian_phone', $guardian_phone);
            $profile_stmt->bindParam(':guardian_email', $guardian_email);
            $profile_stmt->bindParam(':medical_conditions', $medical_conditions);
            $profile_stmt->execute();
            
            // Assign to class
            $class_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
            $class_stmt = $db->prepare($class_query);
            $class_stmt->bindParam(':student_id', $user_id);
            $class_stmt->bindParam(':class_id', $class_id);
            $class_stmt->execute();

            // Create parent-student relationship if parent exists
            if ($parent_id) {
                $parent_student_query = "INSERT INTO parent_students (parent_id, student_id, relationship, is_primary)
                                        VALUES (:parent_id, :student_id, 'guardian', TRUE)
                                        ON DUPLICATE KEY UPDATE is_primary = TRUE";
                $parent_student_stmt = $db->prepare($parent_student_query);
                $parent_student_stmt->bindParam(':parent_id', $parent_id);
                $parent_student_stmt->bindParam(':student_id', $user_id);
                $parent_student_stmt->execute();
            }

            $db->commit();

            // Prepare success message
            $success_message = "Student enrolled successfully with ID: $student_id";
            if ($created_parent_id && !empty($guardian_email)) {
                $success_message .= ". Parent account created for {$guardian_name} ({$guardian_email}) with default password 'parent123'. Please ask them to change their password after first login.";
            }

            header("Location: index.php?success=" . urlencode($success_message));
            exit();
        } catch (PDOException $e) {
            $db->rollBack();
            // Log the actual error for debugging
            error_log("Student enrollment error: " . $e->getMessage());
            error_log("Parent ID value: " . var_export($parent_id, true));
            error_log("Class ID value: " . var_export($class_id, true));

            // Show more specific error messages based on the error
            if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                if (strpos($e->getMessage(), 'email') !== false) {
                    $errors[] = "Email address already exists in the system.";
                } elseif (strpos($e->getMessage(), 'student_id') !== false) {
                    $errors[] = "Student ID already exists. Please try again.";
                } else {
                    $errors[] = "Duplicate entry detected. Please check your data.";
                }
            } elseif (strpos($e->getMessage(), "doesn't exist") !== false) {
                $errors[] = "Database table missing. Please contact administrator.";
            } elseif (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                if (strpos($e->getMessage(), 'parent_id') !== false) {
                    $errors[] = "Invalid parent selection. The selected parent does not exist or is inactive. Please select a different parent or leave it empty.";
                } elseif (strpos($e->getMessage(), 'class_id') !== false) {
                    $errors[] = "Invalid class selection. The selected class does not exist or is inactive. Please select a different class.";
                } else {
                    $errors[] = "Invalid selection. Please check your class and parent selections.";
                }
            } else {
                // For debugging, show the actual error message
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Enroll New Student</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Students
                </a>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form action="" method="POST" class="p-6 space-y-8">
                    <!-- Basic Information -->
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Basic Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700">Full Name *</label>
                                <input type="text" id="name" name="name" required
                                    value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address *</label>
                                <input type="email" id="email" name="email" required
                                    value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700">Password *</label>
                                <input type="password" id="password" name="password" required
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="date_of_birth" class="block text-sm font-medium text-gray-700">Date of Birth *</label>
                                <input type="date" id="date_of_birth" name="date_of_birth" required
                                    value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700">Gender</label>
                                <select id="gender" name="gender"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="female" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="other" <?php echo (isset($_POST['gender']) && $_POST['gender'] === 'other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="blood_group" class="block text-sm font-medium text-gray-700">Blood Group</label>
                                <select id="blood_group" name="blood_group"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Blood Group</option>
                                    <?php
                                    $blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                    foreach ($blood_groups as $group):
                                    ?>
                                    <option value="<?php echo $group; ?>" <?php echo (isset($_POST['blood_group']) && $_POST['blood_group'] === $group) ? 'selected' : ''; ?>><?php echo $group; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Contact Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700">Address</label>
                                <textarea id="address" name="address" rows="3"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="tel" id="phone" name="phone"
                                    value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700">Emergency Contact Name</label>
                                <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                    value="<?php echo isset($_POST['emergency_contact_name']) ? htmlspecialchars($_POST['emergency_contact_name']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700">Emergency Contact Phone</label>
                                <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                    value="<?php echo isset($_POST['emergency_contact_phone']) ? htmlspecialchars($_POST['emergency_contact_phone']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian Information -->
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Parent/Guardian Information</h2>

                        <!-- Auto-creation Notice -->
                        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-blue-400 text-lg"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">Automatic Parent Account Creation</h3>
                                    <div class="mt-1 text-sm text-blue-700">
                                        <p>If you provide guardian details with an email address (and don't select an existing parent), we'll automatically create a parent account with:</p>
                                        <ul class="list-disc list-inside mt-2 space-y-1">
                                            <li>Email: Guardian's email address</li>
                                            <li>Default password: <strong>parent123</strong></li>
                                            <li>Role: Parent (with access to student information)</li>
                                        </ul>
                                        <p class="mt-2 font-medium">The parent should change their password after first login.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="parent_id" class="block text-sm font-medium text-gray-700">Select Existing Parent</label>
                                <select id="parent_id" name="parent_id" onchange="handleParentSelection()"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Parent (Optional)</option>
                                    <?php if (empty($parents)): ?>
                                    <option value="" disabled>No parents available - Create parent users first</option>
                                    <?php else: ?>
                                    <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo $parent['id']; ?>" <?php echo (isset($_POST['parent_id']) && $_POST['parent_id'] == $parent['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($parent['name'] . ' (' . $parent['email'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">
                                    <?php if (empty($parents)): ?>
                                    <span class="text-orange-600">No parent users found. You can create parent users in the Users section, or fill guardian details below.</span>
                                    <?php else: ?>
                                    Select an existing parent user, or fill guardian details below
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div>
                                <label for="guardian_name" class="block text-sm font-medium text-gray-700">Guardian Name</label>
                                <input type="text" id="guardian_name" name="guardian_name"
                                    value="<?php echo isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="guardian_phone" class="block text-sm font-medium text-gray-700">Guardian Phone</label>
                                <input type="tel" id="guardian_phone" name="guardian_phone"
                                    value="<?php echo isset($_POST['guardian_phone']) ? htmlspecialchars($_POST['guardian_phone']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="guardian_email" class="block text-sm font-medium text-gray-700">Guardian Email</label>
                                <input type="email" id="guardian_email" name="guardian_email"
                                    value="<?php echo isset($_POST['guardian_email']) ? htmlspecialchars($_POST['guardian_email']) : ''; ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Academic Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="class_id" class="block text-sm font-medium text-gray-700">Assign to Class *</label>
                                <select id="class_id" name="class_id" required
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="admission_date" class="block text-sm font-medium text-gray-700">Admission Date *</label>
                                <input type="date" id="admission_date" name="admission_date" required
                                    value="<?php echo isset($_POST['admission_date']) ? htmlspecialchars($_POST['admission_date']) : date('Y-m-d'); ?>"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div>
                        <h2 class="text-xl font-semibold text-gray-800 mb-4">Medical Information</h2>
                        <div>
                            <label for="medical_conditions" class="block text-sm font-medium text-gray-700">Medical Conditions/Allergies</label>
                            <textarea id="medical_conditions" name="medical_conditions" rows="3"
                                placeholder="List any medical conditions, allergies, or special medical needs..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"><?php echo isset($_POST['medical_conditions']) ? htmlspecialchars($_POST['medical_conditions']) : ''; ?></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <a href="index.php" 
                            class="px-6 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </a>
                        <button type="submit"
                            class="px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            Enroll Student
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
// Handle parent selection
function handleParentSelection() {
    const parentSelect = document.getElementById('parent_id');
    const guardianFields = ['guardian_name', 'guardian_phone', 'guardian_email'];
    const autoCreateNotice = document.querySelector('.bg-blue-50');

    if (parentSelect.value) {
        // If a parent is selected, clear guardian fields and make them optional
        guardianFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = '';
                field.style.backgroundColor = '#f9fafb';
                field.placeholder = 'Optional (parent selected above)';
            }
        });
        // Hide auto-creation notice
        if (autoCreateNotice) {
            autoCreateNotice.style.display = 'none';
        }
    } else {
        // If no parent selected, make guardian fields normal
        guardianFields.forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.style.backgroundColor = '';
                field.placeholder = '';
            }
        });
        // Show auto-creation notice
        if (autoCreateNotice) {
            autoCreateNotice.style.display = 'block';
        }
    }
}

// Show dynamic feedback for guardian email
function handleGuardianEmailInput() {
    const guardianEmail = document.getElementById('guardian_email');
    const guardianName = document.getElementById('guardian_name');
    const parentSelect = document.getElementById('parent_id');

    if (guardianEmail && guardianName && !parentSelect.value) {
        const email = guardianEmail.value.trim();
        const name = guardianName.value.trim();

        if (email && name) {
            // Show feedback that parent account will be created
            let feedbackDiv = document.getElementById('parent-creation-feedback');
            if (!feedbackDiv) {
                feedbackDiv = document.createElement('div');
                feedbackDiv.id = 'parent-creation-feedback';
                feedbackDiv.className = 'mt-2 p-3 bg-green-50 border border-green-200 rounded-lg';
                guardianEmail.parentNode.appendChild(feedbackDiv);
            }

            feedbackDiv.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-user-plus text-green-600 mr-2"></i>
                    <span class="text-sm text-green-700">
                        <strong>Parent account will be created:</strong> ${name} (${email}) with password "parent123"
                    </span>
                </div>
            `;
            feedbackDiv.style.display = 'block';
        } else {
            // Hide feedback if fields are empty
            const feedbackDiv = document.getElementById('parent-creation-feedback');
            if (feedbackDiv) {
                feedbackDiv.style.display = 'none';
            }
        }
    }
}

// Student ID will be auto-generated server-side in format STU20254927

// Initialize parent selection handling
document.addEventListener('DOMContentLoaded', function() {
    handleParentSelection();

    // Add event listeners for guardian fields
    const guardianEmail = document.getElementById('guardian_email');
    const guardianName = document.getElementById('guardian_name');

    if (guardianEmail) {
        guardianEmail.addEventListener('input', handleGuardianEmailInput);
        guardianEmail.addEventListener('blur', handleGuardianEmailInput);
    }

    if (guardianName) {
        guardianName.addEventListener('input', handleGuardianEmailInput);
        guardianName.addEventListener('blur', handleGuardianEmailInput);
    }
});
</script>
