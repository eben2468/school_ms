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

// Get all submissions and statistics if user is teacher/admin
$submissions = [];
$stats = null;
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

    // Fetch stats
    $stats_query = "SELECT 
                   COUNT(sc.id) as total_students,
                   SUM(CASE WHEN sa.id IS NOT NULL THEN 1 ELSE 0 END) as submitted_count,
                   SUM(CASE WHEN sa.grade IS NOT NULL THEN 1 ELSE 0 END) as graded_count,
                   AVG(sa.grade) as average_grade
                   FROM student_classes sc
                   LEFT JOIN student_assignments sa ON sc.student_id = sa.student_id AND sa.assignment_id = :assignment_id
                   WHERE sc.class_id = :class_id AND sc.status = 'active'";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':assignment_id', $assignment_id);
    $stats_stmt->bindParam(':class_id', $assignment['class_id']);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle submission (for students)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user_role === 'student') {
    $submission_text = filter_input(INPUT_POST, 'submission_text', FILTER_SANITIZE_STRING);
    $is_overdue = strtotime($assignment['due_date']) < time();

    if ($is_overdue) {
        $error = "This assignment is overdue. Submissions or changes are no longer accepted.";
    } else {
        try {
            $file_path = $submission ? $submission['file_path'] : null;

            // Handle file deletion if requested
            if (isset($_POST['delete_file']) && $_POST['delete_file'] === '1') {
                if ($file_path && file_exists('../../' . $file_path)) {
                    unlink('../../' . $file_path);
                }
                $file_path = null;
            }

            // Handle file upload
            if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/submissions/';
                $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
                $max_size = 10 * 1024 * 1024; // 10MB

                $file_info = pathinfo($_FILES['submission_file']['name']);
                $file_extension = strtolower($file_info['extension']);

                if (!in_array($file_extension, $allowed_types)) {
                    $error = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
                } elseif ($_FILES['submission_file']['size'] > $max_size) {
                    $error = "File size too large. Maximum size is 10MB.";
                } else {
                    // Generate unique filename
                    $unique_filename = uniqid() . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '', $_FILES['submission_file']['name']);
                    $upload_path = $upload_dir . $unique_filename;

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }

                    if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $upload_path)) {
                        // Delete previous file if it exists
                        if ($submission && $submission['file_path'] && file_exists('../../' . $submission['file_path'])) {
                            unlink('../../' . $submission['file_path']);
                        }
                        $file_path = 'uploads/submissions/' . $unique_filename;
                    } else {
                        $error = "Failed to upload file. Please try again.";
                    }
                }
            }

            if (!isset($error)) {
                if ($submission) {
                    // Update existing submission
                    $update_query = "UPDATE student_assignments SET submission_text = :submission_text, file_path = :file_path, status = 'submitted', submitted_at = NOW() WHERE id = :submission_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':submission_text', $submission_text);
                    $update_stmt->bindParam(':file_path', $file_path);
                    $update_stmt->bindParam(':submission_id', $submission['id']);
                    $update_stmt->execute();
                    $success = "Submission updated successfully.";
                } else {
                    // Create new submission
                    $insert_query = "INSERT INTO student_assignments (assignment_id, student_id, submission_text, file_path, submitted_at) VALUES (:assignment_id, :student_id, :submission_text, :file_path, NOW())";
                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':assignment_id', $assignment_id);
                    $insert_stmt->bindParam(':student_id', $user_id);
                    $insert_stmt->bindParam(':submission_text', $submission_text);
                    $insert_stmt->bindParam(':file_path', $file_path);
                    $insert_stmt->execute();
                    $success = "Assignment submitted successfully.";
                }

                // Refresh submission data
                $submission_stmt->execute();
                $submission = $submission_stmt->fetch(PDO::FETCH_ASSOC);
            }

        } catch (PDOException $e) {
            $error = "Error submitting assignment: " . $e->getMessage();
        }
    }
}

// Handle grading (for teachers/admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    if (isset($_POST['grade_submission'])) {
        $sub_id = filter_input(INPUT_POST, 'submission_id', FILTER_SANITIZE_NUMBER_INT);
        $grade = filter_input(INPUT_POST, 'grade', FILTER_VALIDATE_FLOAT);
        $feedback = filter_input(INPUT_POST, 'feedback', FILTER_SANITIZE_STRING);

        if ($sub_id === false || $grade === false) {
            $error = "Invalid grade or submission ID.";
        } else {
            try {
                // Verify access to submission
                $verify_sub_query = "SELECT sa.*, a.teacher_id, a.class_id
                                     FROM student_assignments sa
                                     JOIN assignments a ON sa.assignment_id = a.id
                                     WHERE sa.id = :submission_id";
                $verify_sub_stmt = $db->prepare($verify_sub_query);
                $verify_sub_stmt->bindParam(':submission_id', $sub_id);
                $verify_sub_stmt->execute();
                $sub_record = $verify_sub_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sub_record) {
                    $error = "Submission not found.";
                } elseif ($user_role === 'teacher' && $sub_record['teacher_id'] != $user_id) {
                    // Check if teacher teaches this class
                    $verify_teacher_query = "SELECT COUNT(*) as count FROM class_teachers 
                                             WHERE teacher_id = :teacher_id AND class_id = :class_id";
                    $verify_teacher_stmt = $db->prepare($verify_teacher_query);
                    $verify_teacher_stmt->bindParam(':teacher_id', $user_id);
                    $verify_teacher_stmt->bindParam(':class_id', $sub_record['class_id']);
                    $verify_teacher_stmt->execute();
                    $teacher_check = $verify_teacher_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($teacher_check['count'] == 0) {
                        $error = "You do not have permission to grade this submission.";
                    }
                }

                if (!isset($error)) {
                    $grade_query = "UPDATE student_assignments SET grade = :grade, feedback = :feedback, status = 'graded', graded_at = NOW() WHERE id = :submission_id";
                    $grade_stmt = $db->prepare($grade_query);
                    $grade_stmt->bindParam(':grade', $grade);
                    $grade_stmt->bindParam(':feedback', $feedback);
                    $grade_stmt->bindParam(':submission_id', $sub_id);
                    $grade_stmt->execute();
                    $success = "Submission graded successfully.";

                    // Refresh submissions list
                    $submissions_stmt->execute();
                    $submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (PDOException $e) {
                $error = "Error updating grade: " . $e->getMessage();
            }
        }
    }
}

$is_overdue = strtotime($assignment['due_date']) < time();
$days_until_due = ceil((strtotime($assignment['due_date']) - time()) / (60 * 60 * 24));
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <div class="mb-6 assignment-details-header">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white mb-3">Assignment Details</h1>
                <div class="flex space-x-3 no-stack">
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher']) && $assignment['teacher_id'] == $user_id): ?>
                    <a href="edit.php?id=<?php echo $assignment_id; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 flex items-center">
                        <i class="fas fa-edit mr-2"></i>Edit Assignment
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center">
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

            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher']) && $stats): ?>
            <!-- Teacher Stats Dashboard -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <!-- Total Students -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-md border border-gray-100 dark:border-gray-700 flex items-center space-x-4">
                    <div class="p-3 rounded-lg bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                        <i class="fas fa-users text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Students</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_students']; ?></p>
                    </div>
                </div>

                <!-- Submitted -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-md border border-gray-100 dark:border-gray-700 flex items-center space-x-4">
                    <div class="p-3 rounded-lg bg-yellow-50 dark:bg-yellow-900/30 text-yellow-600 dark:text-yellow-450">
                        <i class="fas fa-file-upload text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Submitted</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            <?php echo $stats['submitted_count']; ?>
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">/ <?php echo $stats['total_students']; ?></span>
                        </p>
                    </div>
                </div>

                <!-- Graded -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-md border border-gray-100 dark:border-gray-700 flex items-center space-x-4">
                    <div class="p-3 rounded-lg bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-400">
                        <i class="fas fa-check-circle text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Graded</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            <?php echo $stats['graded_count']; ?>
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">/ <?php echo $stats['submitted_count']; ?></span>
                        </p>
                    </div>
                </div>

                <!-- Average Grade -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-md border border-gray-100 dark:border-gray-700 flex items-center space-x-4">
                    <div class="p-3 rounded-lg bg-purple-50 dark:bg-purple-900/30 text-purple-600 dark:text-purple-400">
                        <i class="fas fa-trophy text-xl"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Grade</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white">
                            <?php echo $stats['average_grade'] ? number_format($stats['average_grade'], 1) : 'N/A'; ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Assignment Details -->
                <div class="lg:col-span-2">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($assignment['title']); ?></h2>
                                    <div class="flex items-center space-x-4 text-sm text-gray-600 dark:text-gray-400">
                                        <span><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                        <span><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($assignment['class_name']); ?></span>
                                        <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($assignment['teacher_name']); ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <?php if ($is_overdue): ?>
                                    <span class="px-3 py-1 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-400 rounded-full text-sm font-medium">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                    </span>
                                    <?php elseif ($days_until_due <= 1): ?>
                                    <span class="px-3 py-1 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400 rounded-full text-sm font-medium">
                                        <i class="fas fa-clock mr-1"></i>Due Soon
                                    </span>
                                    <?php else: ?>
                                    <span class="px-3 py-1 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 rounded-full text-sm font-medium">
                                        <i class="fas fa-check mr-1"></i>Active
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-3">Description</h3>
                            <div class="prose max-w-none text-gray-700 dark:text-gray-300 mb-6">
                                <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                            </div>

                            <!-- Attachment Section -->
                            <?php if (!empty($assignment['attachment_path'])): ?>
                            <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                                <h4 class="text-md font-semibold text-gray-900 dark:text-white mb-3 flex items-center">
                                    <i class="fas fa-paperclip text-blue-600 dark:text-blue-400 mr-2"></i>
                                    Attachment
                                </h4>
                                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 dark:from-gray-800 dark:to-gray-800 dark:border-gray-700 rounded-lg p-4">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center">
                                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/50 rounded-lg flex items-center justify-center mr-4">
                                                <?php
                                                $file_extension = strtolower(pathinfo($assignment['attachment_name'], PATHINFO_EXTENSION));
                                                $icon_class = 'fas fa-file text-gray-700 dark:text-gray-300';
                                                if (in_array($file_extension, ['pdf'])) $icon_class = 'fas fa-file-pdf text-red-600 dark:text-red-400';
                                                elseif (in_array($file_extension, ['doc', 'docx'])) $icon_class = 'fas fa-file-word text-blue-600 dark:text-blue-400';
                                                elseif (in_array($file_extension, ['jpg', 'jpeg', 'png'])) $icon_class = 'fas fa-file-image text-green-600 dark:text-green-400';
                                                elseif (in_array($file_extension, ['zip', 'rar'])) $icon_class = 'fas fa-file-archive text-purple-600 dark:text-purple-400';
                                                elseif (in_array($file_extension, ['txt'])) $icon_class = 'fas fa-file-alt text-gray-600 dark:text-gray-400';
                                                ?>
                                                <i class="<?php echo $icon_class; ?> text-xl"></i>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['attachment_name']); ?></p>
                                                <p class="text-sm text-gray-600 dark:text-gray-400">
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

                    <!-- Student Grade & Feedback Display -->
                    <?php if ($user_role === 'student' && $submission && $submission['grade'] !== null): ?>
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 dark:from-emerald-950/20 dark:to-green-950/20 border border-green-200 dark:border-green-800 rounded-xl shadow-md p-6 mt-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                            <div class="flex items-start space-x-4">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900/50 rounded-full flex items-center justify-center text-green-600 dark:text-green-400 shrink-0">
                                    <i class="fas fa-graduation-cap text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Graded Assignment</h3>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-0.5">Your assignment has been graded by <?php echo htmlspecialchars($assignment['teacher_name']); ?>.</p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 bg-white dark:bg-gray-800 border border-green-100 dark:border-green-900/50 px-4 py-2 rounded-lg shadow-sm">
                                <span class="text-sm font-semibold text-gray-500 dark:text-gray-400">Score:</span>
                                <span class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo floatval($submission['grade']); ?></span>
                                <span class="text-gray-400 dark:text-gray-500">/</span>
                                <span class="text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo isset($assignment['total_marks']) ? $assignment['total_marks'] : 100; ?></span>
                            </div>
                        </div>
                        <?php if (!empty($submission['feedback'])): ?>
                        <div class="mt-4 pt-4 border-t border-green-150 dark:border-green-900/30">
                            <p class="text-sm font-semibold text-green-800 dark:text-green-400 mb-1">Teacher's Feedback:</p>
                            <div class="text-gray-700 dark:text-gray-300 text-sm italic bg-white/50 dark:bg-gray-900/20 rounded-lg p-3 border border-green-100/50 dark:border-green-900/20">
                                <?php echo nl2br(htmlspecialchars($submission['feedback'])); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Student Submission Form (for students only) -->
                    <?php if ($user_role === 'student'): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mt-6">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                <?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                            </h3>
                            <?php if ($submission): ?>
                            <span class="px-2.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 rounded-full text-xs font-semibold">
                                <i class="fas fa-check-circle mr-1"></i>Submitted on time
                            </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!$is_overdue): ?>
                        <form action="" method="POST" enctype="multipart/form-data" class="p-6">
                            <div class="mb-4">
                                <label for="submission_text" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Submission Notes / Answers</label>
                                <textarea id="submission_text" name="submission_text" rows="6" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                    placeholder="Enter your assignment answers or description here..."><?php echo $submission ? htmlspecialchars($submission['submission_text']) : ''; ?></textarea>
                            </div>

                            <!-- File Upload Area -->
                            <div class="mb-6">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-paperclip mr-1 text-blue-500"></i>Attachment File (Optional)
                                </label>
                                
                                <?php if ($submission && !empty($submission['file_path'])): ?>
                                <!-- Show existing file -->
                                <div class="bg-gray-50 dark:bg-gray-700/30 border border-gray-200 dark:border-gray-700 rounded-lg p-4 mb-4 flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/50 rounded flex items-center justify-center text-blue-600 dark:text-blue-400">
                                            <i class="fas fa-file-alt text-lg"></i>
                                        </div>
                                        <div>
                                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php 
                                                $fn = basename($submission['file_path']);
                                                echo htmlspecialchars(preg_replace('/^[a-f0-9]+_[0-9]+_/', '', $fn));
                                                ?>
                                            </p>
                                            <a href="download_submission.php?id=<?php echo $submission['id']; ?>" class="text-xs text-blue-600 hover:underline dark:text-blue-400 flex items-center mt-0.5">
                                                <i class="fas fa-download mr-1"></i>Download submitted file
                                            </a>
                                        </div>
                                    </div>
                                    <button type="button" onclick="markFileForDeletion()" class="text-red-500 hover:text-red-700 dark:hover:text-red-400 text-sm font-medium flex items-center space-x-1">
                                        <i class="fas fa-trash-alt"></i>
                                        <span>Delete file</span>
                                    </button>
                                </div>
                                <input type="hidden" id="delete_file_input" name="delete_file" value="0">
                                <?php endif; ?>

                                <div id="upload-container" class="<?php echo ($submission && !empty($submission['file_path'])) ? 'hidden' : ''; ?>">
                                    <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 dark:border-gray-600 border-dashed rounded-lg hover:border-blue-500 dark:hover:border-blue-400 transition-colors duration-200 bg-gray-50/50 dark:bg-gray-900/10">
                                        <div class="space-y-1 text-center">
                                            <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <div class="flex text-sm text-gray-600 dark:text-gray-400 justify-center">
                                                <label for="submission_file" class="relative cursor-pointer rounded-md font-medium text-blue-600 dark:text-blue-400 hover:text-blue-500 dark:hover:text-blue-300 focus-within:outline-none">
                                                    <span>Upload a file</span>
                                                    <input id="submission_file" name="submission_file" type="file" class="sr-only" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip,.rar" onchange="updateSubmissionFileName(this)">
                                                </label>
                                                <p class="pl-1 text-gray-500 dark:text-gray-400">or drag and drop</p>
                                            </div>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">PDF, DOC, DOCX, TXT, Images, ZIP up to 10MB</p>
                                            <div id="sub-file-name" class="text-sm text-gray-700 dark:text-gray-300 font-medium hidden"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end pt-4 border-t border-gray-150 dark:border-gray-700">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium transition-colors duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i><?php echo $submission ? 'Update Submission' : 'Submit Assignment'; ?>
                                </button>
                            </div>
                        </form>
                        <?php else: ?>
                        <div class="p-6">
                            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                                <p class="text-red-700 dark:text-red-400 font-medium">This assignment is overdue. Submissions are no longer accepted.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Submissions List (for teachers/admins) -->
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mt-6">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Submissions (<?php echo count($submissions); ?>)</h3>
                        </div>
                        
                        <div class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($submissions)): ?>
                            <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                                No submissions yet.
                            </div>
                            <?php else: ?>
                            <?php foreach ($submissions as $sub): ?>
                            <div class="p-6">
                                <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 mb-4">
                                    <div>
                                        <h4 class="font-semibold text-gray-900 dark:text-white text-lg flex items-center">
                                            <?php echo htmlspecialchars($sub['student_name']); ?>
                                            <?php if ($sub['grade'] !== null): ?>
                                                <span class="ml-2 px-2.5 py-0.5 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-400 rounded-full text-xs font-semibold">
                                                    Graded
                                                </span>
                                            <?php else: ?>
                                                <span class="ml-2 px-2.5 py-0.5 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-400 rounded-full text-xs font-semibold">
                                                    Pending Grade
                                                </span>
                                            <?php endif; ?>
                                        </h4>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">Student ID: <?php echo htmlspecialchars($sub['student_id'] ?: 'N/A'); ?></p>
                                    </div>
                                    <div class="text-left md:text-right text-xs text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center md:justify-end space-x-1">
                                            <i class="far fa-calendar-alt"></i>
                                            <span>Submitted: <?php echo date('M j, Y g:i A', strtotime($sub['submitted_at'])); ?></span>
                                        </div>
                                        <?php
                                        $sub_overdue = strtotime($sub['submitted_at']) > strtotime($assignment['due_date']);
                                        if ($sub_overdue):
                                        ?>
                                            <span class="inline-block mt-1 px-2 py-0.5 bg-red-100 dark:bg-red-950/20 text-red-700 dark:text-red-400 rounded text-xs font-bold">
                                                LATE SUBMISSION
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-block mt-1 px-2 py-0.5 bg-green-100 dark:bg-green-950/20 text-green-700 dark:text-green-400 rounded text-xs font-bold">
                                                ON TIME
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Submission Content -->
                                <div class="bg-gray-50 dark:bg-gray-700/30 border border-gray-150 dark:border-gray-700/50 rounded-lg p-4 mb-4">
                                    <p class="text-gray-750 dark:text-gray-300 text-sm whitespace-pre-wrap leading-relaxed"><?php echo htmlspecialchars($sub['submission_text']); ?></p>
                                </div>

                                <!-- Submission File attachment if exists -->
                                <?php if (!empty($sub['file_path'])): ?>
                                <div class="mb-4 bg-blue-50/50 dark:bg-blue-950/10 border border-blue-100 dark:border-blue-900/20 rounded-lg p-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                                    <div class="flex items-center space-x-3 min-w-0">
                                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/40 rounded flex items-center justify-center text-blue-600 dark:text-blue-400 flex-shrink-0">
                                            <i class="fas fa-file-download text-lg"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-805 dark:text-gray-250 break-all">
                                                <?php 
                                                $fn = basename($sub['file_path']);
                                                echo htmlspecialchars(preg_replace('/^[a-f0-9]+_[0-9]+_/', '', $fn));
                                                ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php
                                                $sub_file = '../../' . $sub['file_path'];
                                                if (file_exists($sub_file)) {
                                                    echo number_format(filesize($sub_file) / (1024 * 1024), 2) . ' MB';
                                                } else {
                                                    echo 'File missing';
                                                }
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                    <a href="download_submission.php?id=<?php echo $sub['id']; ?>"
                                       class="inline-flex items-center justify-center px-3.5 py-1.5 border border-transparent text-xs font-semibold rounded bg-blue-600 hover:bg-blue-700 text-white shadow-sm transition-colors duration-150 flex-shrink-0 self-start sm:self-auto whitespace-nowrap">
                                        <i class="fas fa-download mr-1.5"></i>Download File
                                    </a>
                                </div>
                                <?php endif; ?>

                                <!-- Grade display & Action Button -->
                                <div class="flex flex-wrap items-center justify-between gap-4 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700/50">
                                    <?php if ($sub['grade'] !== null): ?>
                                    <div class="flex items-center space-x-2">
                                        <span class="text-sm text-gray-500 dark:text-gray-400 font-semibold">Assigned Score:</span>
                                        <span class="text-lg font-bold text-green-600 dark:text-green-400"><?php echo floatval($sub['grade']); ?></span>
                                        <span class="text-gray-400 dark:text-gray-500">/</span>
                                        <span class="text-sm text-gray-550 dark:text-gray-400"><?php echo isset($assignment['total_marks']) ? $assignment['total_marks'] : 100; ?></span>
                                    </div>
                                    <?php else: ?>
                                    <span class="text-sm text-gray-500 dark:text-gray-450 italic">Not graded yet</span>
                                    <?php endif; ?>

                                    <button type="button" onclick="toggleGradingForm(<?php echo $sub['id']; ?>)" 
                                            class="inline-flex items-center text-sm font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                        <i class="fas fa-edit mr-1.5"></i>
                                        <span><?php echo $sub['grade'] !== null ? 'Modify Grade & Feedback' : 'Grade Submission'; ?></span>
                                    </button>
                                </div>

                                <!-- Collapsible Grading Form -->
                                <div id="grading-form-<?php echo $sub['id']; ?>" class="hidden mt-4 bg-gray-50 dark:bg-gray-900/20 border border-gray-250 dark:border-gray-700 rounded-lg p-5">
                                    <h5 class="text-sm font-bold text-gray-900 dark:text-white mb-3">Grade Student Submission</h5>
                                    
                                    <form action="" method="POST" class="space-y-4">
                                        <input type="hidden" name="grade_submission" value="1">
                                        <input type="hidden" name="submission_id" value="<?php echo $sub['id']; ?>">
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                            <div class="md:col-span-1">
                                                <label class="block text-xs font-semibold text-gray-550 dark:text-gray-400 uppercase tracking-wider mb-1.5">Grade (out of <?php echo isset($assignment['total_marks']) ? $assignment['total_marks'] : 100; ?>)</label>
                                                <input type="number" name="grade" step="0.5" min="0" max="<?php echo isset($assignment['total_marks']) ? $assignment['total_marks'] : 100; ?>" required
                                                       value="<?php echo $sub['grade'] !== null ? floatval($sub['grade']) : ''; ?>"
                                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 dark:bg-gray-700 dark:text-white rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="0.0">
                                            </div>
                                            <div class="md:col-span-3">
                                                <label class="block text-xs font-semibold text-gray-550 dark:text-gray-400 uppercase tracking-wider mb-1.5">Feedback / Comments</label>
                                                <input type="text" name="feedback" 
                                                       value="<?php echo htmlspecialchars($sub['feedback'] ?: ''); ?>"
                                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 dark:bg-gray-700 dark:text-white rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                                                       placeholder="Enter constructive feedback for the student...">
                                            </div>
                                        </div>
                                        
                                        <div class="flex justify-end space-x-2 pt-2">
                                            <button type="button" onclick="toggleGradingForm(<?php echo $sub['id']; ?>)"
                                                    class="px-3.5 py-1.5 border border-gray-300 dark:border-gray-600 text-xs font-semibold rounded text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-750">
                                                Cancel
                                            </button>
                                            <button type="submit"
                                                    class="px-4 py-1.5 bg-green-600 hover:bg-green-700 text-white text-xs font-semibold rounded shadow-sm">
                                                Submit Grade
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Assignment Info Sidebar -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Assignment Information</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Due Date</label>
                                <p class="text-gray-900 dark:text-white font-semibold"><?php echo date('F j, Y g:i A', strtotime($assignment['due_date'])); ?></p>
                                <div id="countdown-timer" class="mt-1 text-sm font-semibold"></div>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Created</label>
                                <p class="text-gray-900 dark:text-white"><?php echo date('F j, Y', strtotime($assignment['created_at'])); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Class</label>
                                <p class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['grade_level'] . ' - ' . $assignment['class_name']); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Subject</label>
                                <p class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['subject_name']); ?></p>
                            </div>
                            
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Teacher</label>
                                <p class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['teacher_name']); ?></p>
                            </div>
 
                            <?php if (!empty($assignment['attachment_path'])): ?>
                            <div>
                                <label class="text-sm font-medium text-gray-500 dark:text-gray-400">Attachment</label>
                                <div class="flex items-center mt-1">
                                    <i class="fas fa-paperclip text-blue-600 dark:text-blue-400 mr-2"></i>
                                    <a href="download.php?assignment_id=<?php echo $assignment['id']; ?>"
                                       class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium">
                                        <?php echo htmlspecialchars($assignment['attachment_name']); ?>
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
 
                        <!-- Student's Submission Status -->
                        <?php if ($user_role === 'student'): ?>
                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <h4 class="text-md font-semibold text-gray-900 dark:text-white mb-3">Your Submission</h4>
                            <?php if ($submission): ?>
                            <div class="space-y-2">
                                <div class="flex items-center">
                                    <i class="fas fa-check-circle text-green-500 mr-2"></i>
                                    <span class="text-sm text-green-700 dark:text-green-400 font-semibold">Submitted</span>
                                </div>
                                <p class="text-sm text-gray-650 dark:text-gray-400">
                                    <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?>
                                </p>
                                <?php if ($submission['file_path']): ?>
                                <div class="flex items-center mt-1 text-xs text-blue-600 dark:text-blue-400 bg-blue-50/50 dark:bg-blue-900/10 p-2 rounded border border-blue-100/50 dark:border-blue-900/10">
                                    <i class="fas fa-paperclip mr-1.5 text-blue-500"></i>
                                    <a href="download_submission.php?id=<?php echo $submission['id']; ?>" class="hover:underline truncate text-blue-700 dark:text-blue-300 font-medium">
                                        <?php 
                                        $fn = basename($submission['file_path']);
                                        echo htmlspecialchars(preg_replace('/^[a-f0-9]+_[0-9]+_/', '', $fn));
                                        ?>
                                    </a>
                                </div>
                                <?php endif; ?>
                                <?php if ($submission['grade'] !== null): ?>
                                <div class="bg-green-50 dark:bg-green-950/20 rounded-lg p-3 mt-2 border border-green-100 dark:border-green-900/20">
                                    <p class="text-xs text-green-700 dark:text-green-455 font-semibold">GRADED STATUS</p>
                                    <p class="text-sm font-bold text-green-900 dark:text-green-300 mt-0.5">Score: <?php echo floatval($submission['grade']); ?> / <?php echo isset($assignment['total_marks']) ? $assignment['total_marks'] : 100; ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php else: ?>
                            <div class="flex items-center">
                                <i class="fas fa-clock text-yellow-500 mr-2"></i>
                                <span class="text-sm text-yellow-750 dark:text-yellow-400 font-semibold">Not submitted</span>
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

<script>
// Toggle teacher grading form
function toggleGradingForm(subId) {
    const form = document.getElementById('grading-form-' + subId);
    if (form) {
        form.classList.toggle('hidden');
    }
}

// Student upload handlers
function markFileForDeletion() {
    const delInput = document.getElementById('delete_file_input');
    if (delInput) delInput.value = '1';
    
    // Hide the existing file container
    const existingFileDiv = document.querySelector('button[onclick="markFileForDeletion()"]').closest('.flex.items-center.justify-between');
    if (existingFileDiv) existingFileDiv.classList.add('hidden');
    
    // Show upload area
    const uploadArea = document.getElementById('upload-container');
    if (uploadArea) uploadArea.classList.remove('hidden');
}

function updateSubmissionFileName(input) {
    const fileNameDiv = document.getElementById('sub-file-name');
    const uploadArea = input.closest('.border-dashed');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2); // Convert to MB

        fileNameDiv.innerHTML = `
            <div class="flex items-center justify-center space-x-2 mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 rounded-md">
                <i class="fas fa-file text-blue-600 dark:text-blue-400"></i>
                <span class="font-medium">${fileName}</span>
                <span class="text-xs">(${fileSize} MB)</span>
                <button type="button" onclick="clearSubmissionFile(event)" class="text-red-500 hover:text-red-700 ml-2">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        fileNameDiv.classList.remove('hidden');
        if (uploadArea) uploadArea.classList.add('border-blue-400', 'bg-blue-50/50', 'dark:bg-blue-900/10');
    }
}

function clearSubmissionFile(e) {
    if (e) e.stopPropagation();
    const input = document.getElementById('submission_file');
    const fileNameDiv = document.getElementById('sub-file-name');
    const uploadArea = document.querySelector('.border-dashed');

    if (input) input.value = '';
    if (fileNameDiv) {
        fileNameDiv.innerHTML = '';
        fileNameDiv.classList.add('hidden');
    }
    if (uploadArea) uploadArea.classList.remove('border-blue-400', 'bg-blue-50/50', 'dark:bg-blue-900/10');
}

// Drag & drop handlers
const uploadContainer = document.getElementById('upload-container');
if (uploadContainer) {
    const uploadArea = uploadContainer.querySelector('.border-dashed');
    const fileInput = document.getElementById('submission_file');

    if (uploadArea && fileInput) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, e => {
                e.preventDefault();
                e.stopPropagation();
            }, false);
        });

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.add('border-blue-400', 'bg-blue-50/50', 'dark:bg-blue-900/10');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, () => {
                uploadArea.classList.remove('border-blue-400', 'bg-blue-50/50', 'dark:bg-blue-900/10');
            }, false);
        });

        uploadArea.addEventListener('drop', e => {
            const dt = e.dataTransfer;
            const files = dt.files;

            if (files.length > 0) {
                fileInput.files = files;
                updateSubmissionFileName(fileInput);
            }
        }, false);
    }
}

// Countdown timer countdown
const dueDate = new Date("<?php echo date('Y-m-d H:i:s', strtotime($assignment['due_date'])); ?>").getTime();
function updateCountdown() {
    const now = new Date().getTime();
    const distance = dueDate - now;
    const timerElement = document.getElementById('countdown-timer');
    if (!timerElement) return;

    if (distance < 0) {
        timerElement.innerHTML = `<span class="text-red-650 dark:text-red-400 flex items-center mt-1"><i class="fas fa-exclamation-circle mr-1"></i>Overdue</span>`;
        clearInterval(countdownInterval);
        return;
    }

    const days = Math.floor(distance / (1000 * 60 * 60 * 24));
    const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((distance % (1000 * 60)) / 1000);

    let timeString = "";
    if (days > 0) timeString += days + "d ";
    if (hours > 0 || days > 0) timeString += hours + "h ";
    timeString += minutes + "m " + seconds + "s";

    timerElement.innerHTML = `<span class="text-blue-600 dark:text-blue-400 flex items-center mt-1"><i class="far fa-clock mr-1"></i>${timeString} remaining</span>`;
}

if (document.getElementById('countdown-timer')) {
    updateCountdown();
    var countdownInterval = setInterval(updateCountdown, 1000);
}
</script>
