<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$assignment_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!$assignment_id) {
    header("Location: index.php");
    exit();
}

// Get assignment details
$query = "SELECT a.*, s.name as subject_name, c.name as class_name, c.grade_level, u.name as teacher_name
          FROM assignments a
          JOIN subjects s ON a.subject_id = s.id
          JOIN classes c ON a.class_id = c.id
          JOIN users u ON a.teacher_id = u.id
          WHERE a.id = :assignment_id";

// Add permission check for students
if ($user_role === 'student') {
    $query .= " AND a.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :user_id)";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':assignment_id', $assignment_id);
if ($user_role === 'student') {
    $stmt->bindParam(':user_id', $user_id);
}
$stmt->execute();
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    header("Location: index.php");
    exit();
}

// Get student submission if user is a student
$submission = null;
if ($user_role === 'student') {
    $submission_query = "SELECT * FROM student_assignments WHERE assignment_id = :assignment_id AND student_id = :user_id";
    $submission_stmt = $db->prepare($submission_query);
    $submission_stmt->bindParam(':assignment_id', $assignment_id);
    $submission_stmt->bindParam(':user_id', $user_id);
    $submission_stmt->execute();
    $submission = $submission_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all submissions if user is teacher/admin
$submissions = [];
if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    $submissions_query = "SELECT sa.*, u.name as student_name, sp.student_id
                         FROM student_assignments sa
                         JOIN users u ON sa.student_id = u.id
                         LEFT JOIN student_profiles sp ON u.id = sp.user_id
                         WHERE sa.assignment_id = :assignment_id
                         ORDER BY sa.submitted_at DESC";
    $submissions_stmt = $db->prepare($submissions_query);
    $submissions_stmt->bindParam(':assignment_id', $assignment_id);
    $submissions_stmt->execute();
    $submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle submission (for students)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'student') {
    $submission_text = filter_input(INPUT_POST, 'submission_text', FILTER_SANITIZE_STRING);
    
    try {
        if ($submission) {
            // Update existing submission
            $update_query = "UPDATE student_assignments SET submission_text = :submission_text, submitted_at = NOW() WHERE id = :submission_id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':submission_text', $submission_text);
            $update_stmt->bindParam(':submission_id', $submission['id']);
            $update_stmt->execute();
            $success = "Submission updated successfully.";
        } else {
            // Create new submission
            $insert_query = "INSERT INTO student_assignments (assignment_id, student_id, submission_text, submitted_at) VALUES (:assignment_id, :student_id, :submission_text, NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':assignment_id', $assignment_id);
            $insert_stmt->bindParam(':student_id', $user_id);
            $insert_stmt->bindParam(':submission_text', $submission_text);
            $insert_stmt->execute();
            $success = "Assignment submitted successfully.";
        }
        
        // Refresh submission data
        $submission_stmt->execute();
        $submission = $submission_stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        $error = "Error submitting assignment. Please try again.";
    }
}

$is_overdue = strtotime($assignment['due_date']) < time();
$days_until_due = ceil((strtotime($assignment['due_date']) - time()) / (60 * 60 * 24));
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Assignment Details</h1>
                <div class="flex space-x-3">
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher']) && $assignment['teacher_id'] == $user_id): ?>
                    <a href="edit.php?id=<?php echo $assignment_id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-edit mr-2"></i>Edit Assignment
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Assignments
                    </a>
                </div>
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
                <!-- Assignment Details -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900 mb-2"><?php echo htmlspecialchars($assignment['title']); ?></h2>
                                    <div class="flex items-center space-x-4 text-sm text-gray-600">
                                        <span><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                        <span><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($assignment['class_name']); ?></span>
                                        <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($assignment['teacher_name']); ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <?php if ($is_overdue): ?>
                                    <span class="px-3 py-1 bg-red-100 text-red-800 rounded-full text-sm font-medium">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                    </span>
                                    <?php elseif ($days_until_due <= 1): ?>
                                    <span class="px-3 py-1 bg-yellow-100 text-yellow-800 rounded-full text-sm font-medium">
                                        <i class="fas fa-clock mr-1"></i>Due Soon
                                    </span>
                                    <?php else: ?>
                                    <span class="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                                        <i class="fas fa-check mr-1"></i>Active
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-3">Description</h3>
                            <div class="prose max-w-none text-gray-700 mb-6">
                                <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                            </div>

                            <!-- Attachment Section -->
                            <?php if (!empty($assignment['attachment_path'])): ?>
                            <div class="border-t border-gray-200 pt-6">
                                <h4 class="text-md font-semibold text-gray-900 mb-3 flex items-center">
                                    <i class="fas fa-paperclip text-blue-600 mr-2"></i>
                                    Attachment
                                </h4>
                                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 rounded-lg p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center mr-4">
                                                <?php
                                                $file_extension = strtolower(pathinfo($assignment['attachment_name'], PATHINFO_EXTENSION));
                                                $icon_class = 'fas fa-file';
                                                if (in_array($file_extension, ['pdf'])) $icon_class = 'fas fa-file-pdf text-red-600';
                                                elseif (in_array($file_extension, ['doc', 'docx'])) $icon_class = 'fas fa-file-word text-blue-600';
                                                elseif (in_array($file_extension, ['jpg', 'jpeg', 'png'])) $icon_class = 'fas fa-file-image text-green-600';
                                                elseif (in_array($file_extension, ['zip', 'rar'])) $icon_class = 'fas fa-file-archive text-purple-600';
                                                elseif (in_array($file_extension, ['txt'])) $icon_class = 'fas fa-file-alt text-gray-600';
                                                ?>
                                                <i class="<?php echo $icon_class; ?> text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($assignment['attachment_name']); ?></p>
                                                <p class="text-sm text-gray-600">
                                                    <?php
                                                    $file_path = '../../' . $assignment['attachment_path'];
                                                    if (file_exists($file_path)) {
                                                        $file_size = filesize($file_path);
                                                        echo number_format($file_size / 1024, 1) . ' KB';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                        <a href="download.php?assignment_id=<?php echo $assignment['id']; ?>"
                                           class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                            <i class="fas fa-download mr-2"></i>
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Student Submission Form (for students only) -->
                    <?php if ($user_role === 'student'): ?>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 mt-6">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">
                                <?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                            </h3>
                        </div>
                        
                        <?php if (!$is_overdue): ?>
                        <form action="" method="POST" class="p-6">
                            <div class="mb-4">
                                <label for="submission_text" class="block text-sm font-medium text-gray-700 mb-2">Your Submission</label>
                                <textarea id="submission_text" name="submission_text" rows="8" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Enter your assignment submission here..."><?php echo $submission ? htmlspecialchars($submission['submission_text']) : ''; ?></textarea>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                    <i class="fas fa-paper-plane mr-2"></i><?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="p-6">
                            <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                                <p class="text-red-700">This assignment is overdue. Submissions are no longer accepted.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Submissions List (for teachers/admins) -->
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 mt-6">
                        <div class="p-6 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-900">Student Submissions (<?php echo count($submissions); ?>)</h3>
                        </div>
                        
                        <div class="divide-y divide-gray-200">
                            <?php if (empty($submissions)): ?>
                            <div class="p-6 text-center text-gray-500">
                                No submissions yet.
                            </div>
                            <?php else: ?>
                            <?php foreach ($submissions as $sub): ?>
                            <div class="p-6">
                                <div class="flex items-start justify-between mb-3">
                                    <div>
                                        <h4 class="font-medium text-gray-900"><?php echo htmlspecialchars($sub['student_name']); ?></h4>
                                        <p class="text-sm text-gray-600">ID: <?php echo htmlspecialchars($sub['student_id']); ?></p>
                                    </div>
                                    <div class="text-right text-sm text-gray-500">
                                        Submitted: <?php echo date('M j, Y g:i A', strtotime($sub['submitted_at'])); ?>
                                        <?php if ($sub['grade']): ?>
                                        <div class="mt-1">
                                            <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded text-xs font-medium">
                                                Grade: <?php echo $sub['grade']; ?>
                                            </span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-4">
                                    <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($sub['submission_text'])); ?></p>
                                </div>
                                <?php if ($sub['feedback']): ?>
                                <div class="mt-3 bg-blue-50 rounded-lg p-3">
                                    <p class="text-sm font-medium text-blue-900">Feedback:</p>
                                    <p class="text-blue-800"><?php echo nl2br(htmlspecialchars($sub['feedback'])); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Assignment Info Sidebar -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Assignment Information</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="text-sm font-medium text-gray-500">Due Date</label>
                                <p class="text-gray-900"><?php echo date('F j, Y g:i A', strtotime($assignment['due_date'])); ?></p>
                                <?php if (!$is_overdue && $days_until_due >= 0): ?>
                                <p class="text-sm text-gray-600"><?php echo $days_until_due; ?> day(s) remaining</p>
                                <?php endif; ?>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500">Created</label>
                                <p class="text-gray-900"><?php echo date('F j, Y', strtotime($assignment['created_at'])); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500">Class</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($assignment['grade_level'] . ' - ' . $assignment['class_name']); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500">Subject</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($assignment['subject_name']); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500">Teacher</label>
                                <p class="text-gray-900"><?php echo htmlspecialchars($assignment['teacher_name']); ?></p>
                            </div>

                            <?php if (!empty($assignment['attachment_path'])): ?>
                            <div>
                                <label class="text-sm font-medium text-gray-500">Attachment</label>
                                <div class="flex items-center mt-1">
                                    <i class="fas fa-paperclip text-blue-600 mr-2"></i>
                                    <a href="download.php?assignment_id=<?php echo $assignment['id']; ?>"
                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        <?php echo htmlspecialchars($assignment['attachment_name']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Student's Submission Status -->
                        <?php if ($user_role === 'student'): ?>
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <h4 class="text-md font-semibold text-gray-900 mb-3">Your Submission</h4>
                            <?php if ($submission): ?>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                    <span class="text-sm text-green-700">Submitted</span>
                                </div>
                                <p class="text-sm text-gray-600">
                                    <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                </p>
                                <?php if ($submission['grade']): ?>
                                <div class="bg-blue-50 rounded-lg p-3">
                                    <p class="text-sm font-medium text-blue-900">Grade: <?php echo $submission['grade']; ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="flex items-center">
                                <i class="fas fa-clock text-yellow-500 mr-2"></i>
                                <span class="text-sm text-yellow-700">Not submitted</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
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
