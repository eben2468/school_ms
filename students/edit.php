<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$student_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$student_id) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db->beginTransaction();
        
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
        $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
        $date_of_birth = $_POST['date_of_birth'];
        $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
        $emergency_contact_name = filter_input(INPUT_POST, 'emergency_contact_name', FILTER_SANITIZE_STRING);
        $emergency_contact_phone = filter_input(INPUT_POST, 'emergency_contact_phone', FILTER_SANITIZE_STRING);
        $status = $_POST['status'];

        // Additional fields
        $student_id_field = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_STRING);
        $gender = $_POST['gender'] ?? '';
        $blood_group = filter_input(INPUT_POST, 'blood_group', FILTER_SANITIZE_STRING);
        $guardian_name = filter_input(INPUT_POST, 'guardian_name', FILTER_SANITIZE_STRING);
        $guardian_phone = filter_input(INPUT_POST, 'guardian_phone', FILTER_SANITIZE_STRING);
        $guardian_email = filter_input(INPUT_POST, 'guardian_email', FILTER_SANITIZE_EMAIL);
        $medical_conditions = filter_input(INPUT_POST, 'medical_conditions', FILTER_SANITIZE_STRING);
        $admission_date = $_POST['admission_date'] ?? '';
        $previous_school = filter_input(INPUT_POST, 'previous_school', FILTER_SANITIZE_STRING);
        
        // Update user table
        $user_query = "UPDATE users SET name = :name, email = :email, status = :status WHERE id = :id AND role = 'student'";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':name', $name);
        $user_stmt->bindParam(':email', $email);
        $user_stmt->bindParam(':status', $status);
        $user_stmt->bindParam(':id', $student_id);
        $user_stmt->execute();
        
        // Update student profile
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
        $profile_stmt->bindParam(':student_id', $student_id_field);
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
        $profile_stmt->bindParam(':admission_date', $admission_date);
        $profile_stmt->bindParam(':previous_school', $previous_school);
        $profile_stmt->bindParam(':user_id', $student_id);
        $profile_stmt->execute();
        
        $db->commit();
        $success = "Student information updated successfully.";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error updating student information: " . $e->getMessage();
    }
}

// Get student details
$query = "SELECT u.*, sp.* FROM users u 
          LEFT JOIN student_profiles sp ON u.id = sp.user_id 
          WHERE u.id = :id AND u.role = 'student'";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $student_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: index.php");
    exit();
}

$title = "Edit Student";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Students', 'url' => 'index.php'],
    ['title' => 'Edit Student']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Student</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Students
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

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Information</h3>
                    </div>
                    
                    <form action="" method="POST" class="p-6 space-y-8">
                        <!-- Basic Information Section -->
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <i class="fas fa-user mr-2 text-blue-600"></i>Basic Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Full Name *</label>
                                    <input type="text" id="name" name="name" required
                                        value="<?php echo htmlspecialchars($student['name']); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student ID</label>
                                    <input type="text" id="student_id" name="student_id"
                                        value="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email Address *</label>
                                    <input type="email" id="email" name="email" required
                                        value="<?php echo htmlspecialchars($student['email']); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone Number</label>
                                    <input type="tel" id="phone" name="phone"
                                        value="<?php echo htmlspecialchars($student['phone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="date_of_birth" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date of Birth</label>
                                    <input type="date" id="date_of_birth" name="date_of_birth"
                                        value="<?php echo htmlspecialchars($student['date_of_birth'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Gender</label>
                                    <select id="gender" name="gender"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Gender</option>
                                        <option value="male" <?php echo ($student['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo ($student['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo ($student['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="blood_group" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Blood Group</label>
                                    <select id="blood_group" name="blood_group"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Blood Group</option>
                                        <?php
                                        $blood_groups = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
                                        foreach ($blood_groups as $group) {
                                            $selected = ($student['blood_group'] ?? '') === $group ? 'selected' : '';
                                            echo "<option value=\"$group\" $selected>$group</option>";
                                        }
                                        ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="admission_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Admission Date</label>
                                    <input type="date" id="admission_date" name="admission_date"
                                        value="<?php echo htmlspecialchars($student['admission_date'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status *</label>
                                    <select id="status" name="status" required
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="active" <?php echo $student['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="inactive" <?php echo $student['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Guardian Information Section -->
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <i class="fas fa-users mr-2 text-green-600"></i>Guardian Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                <div>
                                    <label for="guardian_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Name</label>
                                    <input type="text" id="guardian_name" name="guardian_name"
                                        value="<?php echo htmlspecialchars($student['guardian_name'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="guardian_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Phone</label>
                                    <input type="tel" id="guardian_phone" name="guardian_phone"
                                        value="<?php echo htmlspecialchars($student['guardian_phone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="guardian_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Guardian Email</label>
                                    <input type="email" id="guardian_email" name="guardian_email"
                                        value="<?php echo htmlspecialchars($student['guardian_email'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Emergency Contact Section -->
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <i class="fas fa-phone mr-2 text-red-600"></i>Emergency Contact
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="emergency_contact_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Emergency Contact Name</label>
                                    <input type="text" id="emergency_contact_name" name="emergency_contact_name"
                                        value="<?php echo htmlspecialchars($student['emergency_contact_name'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>

                                <div>
                                    <label for="emergency_contact_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Emergency Contact Phone</label>
                                    <input type="tel" id="emergency_contact_phone" name="emergency_contact_phone"
                                        value="<?php echo htmlspecialchars($student['emergency_contact_phone'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Address & Additional Information -->
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <i class="fas fa-map-marker-alt mr-2 text-purple-600"></i>Address & Additional Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Home Address</label>
                                    <textarea id="address" name="address" rows="3"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="Enter complete home address"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea>
                                </div>

                                <div>
                                    <label for="previous_school" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Previous School</label>
                                    <textarea id="previous_school" name="previous_school" rows="3"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="Name and details of previous school"><?php echo htmlspecialchars($student['previous_school'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Medical Information -->
                        <div>
                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 border-b border-gray-200 dark:border-gray-600 pb-2">
                                <i class="fas fa-heartbeat mr-2 text-red-600"></i>Medical Information
                            </h4>
                            <div>
                                <label for="medical_conditions" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Medical Conditions / Allergies</label>
                                <textarea id="medical_conditions" name="medical_conditions" rows="4"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    placeholder="List any medical conditions, allergies, medications, or special medical needs"><?php echo htmlspecialchars($student['medical_conditions'] ?? ''); ?></textarea>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Include any important medical information that the school should be aware of</p>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex space-x-4">
                                <a href="index.php"
                                   class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium transition-colors duration-200">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="submit"
                                    class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200 shadow-lg hover:shadow-xl">
                                    <i class="fas fa-save mr-2"></i>Update Student Information
                                </button>
                            </div>
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
