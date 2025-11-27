<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'counselor', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $session_date = filter_input(INPUT_POST, 'session_date', FILTER_SANITIZE_STRING);
    $session_type = filter_input(INPUT_POST, 'session_type', FILTER_SANITIZE_STRING);
    $reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_STRING);
    $concerns = filter_input(INPUT_POST, 'concerns', FILTER_SANITIZE_STRING);
    $observations = filter_input(INPUT_POST, 'observations', FILTER_SANITIZE_STRING);
    $recommendations = filter_input(INPUT_POST, 'recommendations', FILTER_SANITIZE_STRING);
    $follow_up_required = filter_input(INPUT_POST, 'follow_up_required', FILTER_SANITIZE_STRING);
    $follow_up_date = filter_input(INPUT_POST, 'follow_up_date', FILTER_SANITIZE_STRING);
    $confidential_notes = filter_input(INPUT_POST, 'confidential_notes', FILTER_SANITIZE_STRING);
    
    if ($student_id && $session_date && $session_type) {
        try {
            $query = "INSERT INTO counseling_sessions (student_id, session_date, session_type, reason, concerns, observations, recommendations, follow_up_required, follow_up_date, confidential_notes, counselor_id, created_at) 
                     VALUES (:student_id, :session_date, :session_type, :reason, :concerns, :observations, :recommendations, :follow_up_required, :follow_up_date, :confidential_notes, :counselor_id, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':session_date', $session_date);
            $stmt->bindParam(':session_type', $session_type);
            $stmt->bindParam(':reason', $reason);
            $stmt->bindParam(':concerns', $concerns);
            $stmt->bindParam(':observations', $observations);
            $stmt->bindParam(':recommendations', $recommendations);
            $stmt->bindParam(':follow_up_required', $follow_up_required);
            $stmt->bindParam(':follow_up_date', $follow_up_date);
            $stmt->bindParam(':confidential_notes', $confidential_notes);
            $stmt->bindParam(':counselor_id', $_SESSION['user_id']);
            $stmt->execute();
            
            $success = "Counseling session record created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating session record: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get students for dropdown
$students_query = "SELECT u.id, u.name, sp.student_id FROM users u 
                   LEFT JOIN student_profiles sp ON u.id = sp.user_id 
                   WHERE u.role = 'student' AND u.status = 'active' 
                   ORDER BY u.name";
$students_stmt = $db->query($students_query);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Counseling Session";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Health', 'url' => '../index.php'],
    ['title' => 'Counseling', 'url' => 'index.php'],
    ['title' => 'Create Session']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Counseling Session</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
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

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student *</label>
                                    <select id="student_id" name="student_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                                <?php if ($student['student_id']): ?>
                                                    (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="session_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Date *</label>
                                    <input type="date" id="session_date" name="session_date" value="<?php echo date('Y-m-d'); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="session_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Type *</label>
                                    <select id="session_type" name="session_type" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Type</option>
                                        <option value="individual">Individual Counseling</option>
                                        <option value="group">Group Counseling</option>
                                        <option value="crisis">Crisis Intervention</option>
                                        <option value="academic">Academic Counseling</option>
                                        <option value="career">Career Guidance</option>
                                        <option value="behavioral">Behavioral Support</option>
                                        <option value="family">Family Counseling</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="follow_up_required" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Required</label>
                                    <select id="follow_up_required" name="follow_up_required"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="no">No</option>
                                        <option value="yes">Yes</option>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason for Session</label>
                                    <textarea id="reason" name="reason" rows="2"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Reason for counseling session"></textarea>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="concerns" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student Concerns</label>
                                    <textarea id="concerns" name="concerns" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Student's expressed concerns and issues"></textarea>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="observations" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Counselor Observations</label>
                                    <textarea id="observations" name="observations" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Counselor's observations during the session"></textarea>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="recommendations" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Recommendations</label>
                                    <textarea id="recommendations" name="recommendations" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Recommendations and action plans"></textarea>
                                </div>

                                <div>
                                    <label for="follow_up_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Date</label>
                                    <input type="date" id="follow_up_date" name="follow_up_date"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="md:col-span-2">
                                    <label for="confidential_notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Confidential Notes</label>
                                    <textarea id="confidential_notes" name="confidential_notes" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Confidential notes (restricted access)"></textarea>
                                    <p class="mt-1 text-xs text-gray-500">These notes are confidential and only accessible to authorized counseling staff.</p>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Session
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Show/hide follow-up date based on follow-up required selection
document.getElementById('follow_up_required').addEventListener('change', function() {
    const followUpDate = document.getElementById('follow_up_date').parentElement;
    if (this.value === 'yes') {
        followUpDate.style.display = 'block';
        document.getElementById('follow_up_date').required = true;
    } else {
        followUpDate.style.display = 'none';
        document.getElementById('follow_up_date').required = false;
        document.getElementById('follow_up_date').value = '';
    }
});
</script>
