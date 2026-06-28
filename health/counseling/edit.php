<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'counselor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$session_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$session_id) {
    header("Location: index.php");
    exit();
}

// Fetch record
$query = "SELECT cs.*, u.name as student_name FROM counseling_sessions cs
          JOIN users u ON cs.student_id = u.id
          WHERE cs.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $session_id);
$stmt->execute();
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $session_date = filter_input(INPUT_POST, 'session_date', FILTER_SANITIZE_STRING);
    $session_time = filter_input(INPUT_POST, 'session_time', FILTER_SANITIZE_STRING);
    $duration = filter_input(INPUT_POST, 'duration', FILTER_SANITIZE_NUMBER_INT);
    $session_type = filter_input(INPUT_POST, 'session_type', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $follow_up_required = filter_input(INPUT_POST, 'follow_up_required', FILTER_SANITIZE_STRING);
    $follow_up_date = filter_input(INPUT_POST, 'follow_up_date', FILTER_SANITIZE_STRING) ?: null;
    
    $reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_STRING);
    $concerns = filter_input(INPUT_POST, 'concerns', FILTER_SANITIZE_STRING);
    $observations = filter_input(INPUT_POST, 'observations', FILTER_SANITIZE_STRING);
    $recommendations = filter_input(INPUT_POST, 'recommendations', FILTER_SANITIZE_STRING);
    $confidential_notes = filter_input(INPUT_POST, 'confidential_notes', FILTER_SANITIZE_STRING);
    
    if ($session_date && $session_type && $status) {
        try {
            $query = "UPDATE counseling_sessions SET 
                        session_date = :session_date, 
                        session_time = :session_time, 
                        duration = :duration, 
                        duration_minutes = :duration, 
                        session_type = :session_type, 
                        status = :status, 
                        follow_up_required = :follow_up_required, 
                        follow_up_date = :follow_up_date, 
                        reason = :reason, 
                        concerns = :concerns, 
                        observations = :observations, 
                        recommendations = :recommendations, 
                        confidential_notes = :confidential_notes
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':session_date', $session_date);
            $stmt->bindParam(':session_time', $session_time);
            $stmt->bindParam(':duration', $duration);
            $stmt->bindParam(':session_type', $session_type);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':follow_up_required', $follow_up_required);
            $stmt->bindParam(':follow_up_date', $follow_up_date);
            $stmt->bindParam(':reason', $reason);
            $stmt->bindParam(':concerns', $concerns);
            $stmt->bindParam(':observations', $observations);
            $stmt->bindParam(':recommendations', $recommendations);
            $stmt->bindParam(':confidential_notes', $confidential_notes);
            $stmt->bindParam(':id', $session_id);
            
            $stmt->execute();
            $success = "Counseling session updated successfully!";
            
            // Refresh local session data
            $session['session_date'] = $session_date;
            $session['session_time'] = $session_time;
            $session['duration'] = $duration;
            $session['session_type'] = $session_type;
            $session['status'] = $status;
            $session['follow_up_required'] = $follow_up_required;
            $session['follow_up_date'] = $follow_up_date;
            $session['reason'] = $reason;
            $session['concerns'] = $concerns;
            $session['observations'] = $observations;
            $session['recommendations'] = $recommendations;
            $session['confidential_notes'] = $confidential_notes;
            
        } catch (PDOException $e) {
            $error = "Error updating session: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

$title = "Edit Counseling Session";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Counseling Session</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Editing session for <?php echo htmlspecialchars($session['student_name']); ?></p>
                    </div>
                    <a href="view.php?id=<?php echo $session['id']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Details
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

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            
                            <!-- Student and date/time -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Student</label>
                                    <input type="text" value="<?php echo htmlspecialchars($session['student_name']); ?>" disabled 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                </div>

                                <div>
                                    <label for="session_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Date *</label>
                                    <input type="date" id="session_date" name="session_date" value="<?php echo htmlspecialchars($session['session_date']); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="session_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Time *</label>
                                    <input type="time" id="session_time" name="session_time" value="<?php echo htmlspecialchars(date('H:i', strtotime($session['session_time'] ?? '00:00:00'))); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Duration, Type, Status -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label for="duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Duration (minutes) *</label>
                                    <input type="number" id="duration" name="duration" value="<?php echo htmlspecialchars($session['duration'] ?? $session['duration_minutes'] ?? '60'); ?>" min="5" max="300" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="session_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Session Type *</label>
                                    <select id="session_type" name="session_type" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="individual" <?php echo $session['session_type'] === 'individual' ? 'selected' : ''; ?>>Individual Counseling</option>
                                        <option value="group" <?php echo $session['session_type'] === 'group' ? 'selected' : ''; ?>>Group Counseling</option>
                                        <option value="crisis" <?php echo $session['session_type'] === 'crisis' ? 'selected' : ''; ?>>Crisis Intervention</option>
                                        <option value="academic" <?php echo $session['session_type'] === 'academic' ? 'selected' : ''; ?>>Academic Counseling</option>
                                        <option value="career" <?php echo $session['session_type'] === 'career' ? 'selected' : ''; ?>>Career Guidance</option>
                                        <option value="behavioral" <?php echo $session['session_type'] === 'behavioral' ? 'selected' : ''; ?>>Behavioral Support</option>
                                        <option value="family" <?php echo $session['session_type'] === 'family' ? 'selected' : ''; ?>>Family Counseling</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                                    <select id="status" name="status" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="scheduled" <?php echo $session['status'] === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                        <option value="completed" <?php echo $session['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                        <option value="cancelled" <?php echo $session['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        <option value="no_show" <?php echo $session['status'] === 'no_show' ? 'selected' : ''; ?>>No Show</option>
                                    </select>
                                </div>
                            </div>

                            <!-- Follow up parameters -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="follow_up_required" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Required</label>
                                    <select id="follow_up_required" name="follow_up_required"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="no" <?php echo $session['follow_up_required'] === 'no' || $session['follow_up_required'] === '0' ? 'selected' : ''; ?>>No</option>
                                        <option value="yes" <?php echo $session['follow_up_required'] === 'yes' || $session['follow_up_required'] === '1' ? 'selected' : ''; ?>>Yes</option>
                                    </select>
                                </div>

                                <div id="follow_up_date_container" style="<?php echo ($session['follow_up_required'] === 'yes' || $session['follow_up_required'] === '1') ? 'display: block;' : 'display: none;'; ?>">
                                    <label for="follow_up_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Date</label>
                                    <input type="date" id="follow_up_date" name="follow_up_date" value="<?php echo htmlspecialchars($session['follow_up_date'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Clinical Details -->
                            <div class="space-y-4">
                                <div>
                                    <label for="reason" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Reason for Session</label>
                                    <textarea id="reason" name="reason" rows="2"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Reason for counseling"><?php echo htmlspecialchars($session['reason']); ?></textarea>
                                </div>

                                <div>
                                    <label for="concerns" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student Concerns</label>
                                    <textarea id="concerns" name="concerns" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Student's expressed concerns"><?php echo htmlspecialchars($session['concerns']); ?></textarea>
                                </div>

                                <div>
                                    <label for="observations" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Counselor Observations</label>
                                    <textarea id="observations" name="observations" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Counselor's observations"><?php echo htmlspecialchars($session['observations']); ?></textarea>
                                </div>

                                <div>
                                    <label for="recommendations" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Recommendations</label>
                                    <textarea id="recommendations" name="recommendations" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Recommendations and action plan"><?php echo htmlspecialchars($session['recommendations']); ?></textarea>
                                </div>

                                <div>
                                    <label for="confidential_notes" class="block text-sm font-medium text-red-700 dark:text-red-400"><i class="fas fa-lock mr-1"></i>Confidential Notes</label>
                                    <textarea id="confidential_notes" name="confidential_notes" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Confidential notes (only visible to counselor/admins)"><?php echo htmlspecialchars($session['confidential_notes']); ?></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="view.php?id=<?php echo $session['id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg font-medium">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                    <i class="fas fa-save mr-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Show/hide follow-up date based on selection
document.getElementById('follow_up_required').addEventListener('change', function() {
    const container = document.getElementById('follow_up_date_container');
    if (this.value === 'yes') {
        container.style.display = 'block';
        document.getElementById('follow_up_date').required = true;
    } else {
        container.style.display = 'none';
        document.getElementById('follow_up_date').required = false;
        document.getElementById('follow_up_date').value = '';
    }
});
</script>
