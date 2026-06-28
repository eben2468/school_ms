<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$exam_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$exam_id) {
    header("Location: index.php");
    exit();
}

// Get exam details with subject and class information
$query = "SELECT e.*, s.name as subject_name, s.code as subject_code,
          c.name as class_name, c.grade_level,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id) as total_submissions,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id AND e.passing_marks IS NOT NULL AND er.marks_obtained >= e.passing_marks) as passed_count,
          (SELECT COUNT(*) FROM student_classes sc WHERE sc.class_id = e.class_id AND sc.status = 'active') as total_students
          FROM exams e
          LEFT JOIN subjects s ON e.subject_id = s.id
          LEFT JOIN classes c ON e.class_id = c.id
          WHERE e.id = :exam_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':exam_id', $exam_id);
$stmt->execute();
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: index.php");
    exit();
}

$title = "View Exam - " . $exam['subject_name'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Exam Details</h1>
                <div class="space-x-4">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Exams
                    </a>
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="edit.php?id=<?php echo $exam_id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-edit mr-2"></i> Edit Exam
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Exam Status Card -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <?php
                    $exam_datetime = strtotime($exam['exam_date'] . ' ' . $exam['start_time']);
                    $now = time();
                    $end_time = $exam_datetime + ($exam['duration'] * 60);
                    
                    $status = '';
                    $status_bg = '';
                    $status_text = '';
                    
                    if ($now < $exam_datetime) {
                        $status = 'Upcoming';
                        $status_bg = 'bg-yellow-100';
                        $status_text = 'text-yellow-800';
                    } elseif ($now < $end_time) {
                        $status = 'In Progress';
                        $status_bg = 'bg-green-100';
                        $status_text = 'text-green-800';
                    } else {
                        $status = 'Completed';
                        $status_bg = 'bg-blue-100';
                        $status_text = 'text-blue-800';
                    }
                    ?>
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-semibold text-gray-800">
                                <?php echo htmlspecialchars($exam['subject_name']); ?> Examination
                            </h2>
                            <p class="text-gray-600 mt-1">
                                Grade <?php echo htmlspecialchars($exam['grade_level']); ?> - 
                                <?php echo htmlspecialchars($exam['class_name']); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="px-3 py-1 <?php echo $status_bg . ' ' . $status_text; ?> rounded-full text-sm font-semibold">
                                <?php echo $status; ?>
                            </span>
                            <?php if ($status === 'Upcoming'): ?>
                            <p class="text-sm text-gray-500 mt-1">
                                Starts in <?php echo human_time_diff($now, $exam_datetime); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Exam Details -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <!-- Basic Information -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Basic Information</h3>
                    </div>
                    <div class="p-6">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Subject Code</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($exam['subject_code']); ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Exam Type</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php
                                    $exam_type_labels = [
                                        'midterm' => 'Midterm',
                                        'final' => 'Final',
                                        'quiz' => 'Quiz',
                                        'assignment' => 'Assignment',
                                        'project' => 'Project'
                                    ];
                                    echo htmlspecialchars($exam_type_labels[$exam['exam_type']] ?? ucfirst($exam['exam_type']));
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Academic Term</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php
                                    $term_labels = [
                                        'first' => 'First Term',
                                        'second' => 'Second Term',
                                        'third' => 'Third Term'
                                    ];
                                    echo htmlspecialchars($term_labels[$exam['academic_term']] ?? ucfirst($exam['academic_term']));
                                    ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Academic Year</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($exam['academic_year'] ?? 'N/A'); ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Schedule Information -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Schedule Information</h3>
                    </div>
                    <div class="p-6">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Date</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('l, F j, Y', strtotime($exam['exam_date'])); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Time</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo date('g:i A', strtotime($exam['start_time'])); ?> - 
                                    <?php echo date('g:i A', strtotime($exam['start_time']) + ($exam['duration'] * 60)); ?>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Duration</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo $exam['duration']; ?> minutes</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Marks Information -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Marks Information</h3>
                    </div>
                    <div class="p-6">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Maximum Marks</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo $exam['total_marks'] ?? 'N/A'; ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Passing Marks</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo $exam['passing_marks'] !== null ? htmlspecialchars($exam['passing_marks']) : 'N/A'; ?></dd>
                            </div>
                            <?php if ($exam['passing_marks'] !== null): ?>
                            <div class="pt-4 border-t border-gray-200">
                                <dt class="text-sm font-medium text-gray-500">Pass Percentage</dt>
                                <dd class="mt-1">
                                     <?php if ($exam['total_submissions'] > 0): ?>
                                     <div class="relative pt-1">
                                         <?php $percentage = round(($exam['passed_count'] / $exam['total_submissions']) * 100); ?>
                                         <div class="overflow-hidden h-2 text-xs flex rounded bg-gray-200">
                                             <div style="width: <?php echo $percentage; ?>%"
                                                 class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-green-500">
                                             </div>
                                         </div>
                                         <div class="text-sm text-gray-900 mt-1">
                                             <?php echo $percentage; ?>% (<?php echo $exam['passed_count']; ?> out of <?php echo $exam['total_submissions']; ?>)
                                         </div>
                                     </div>
                                     <?php else: ?>
                                     <span class="text-sm text-gray-500">No results submitted yet</span>
                                     <?php endif; ?>
                                </dd>
                            </div>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <!-- Submission Status -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Submission Status</h3>
                    </div>
                    <div class="p-6">
                        <dl class="space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Total Students</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo $exam['total_students']; ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Submissions</dt>
                                <dd class="mt-1 text-sm text-gray-900"><?php echo $exam['total_submissions']; ?></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Pending</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo $exam['total_students'] - $exam['total_submissions']; ?>
                                </dd>
                            </div>
                            <div class="pt-4">
                                <?php if ($status === 'Completed'): ?>
                                <a href="results.php?id=<?php echo $exam_id; ?>" 
                                   class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700">
                                    <i class="fas fa-chart-bar mr-2"></i>
                                    View Full Results
                                </a>
                                <?php endif; ?>
                            </div>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<?php
function human_time_diff($from, $to) {
    $diff = abs($to - $from);
    
    if ($diff < 3600) {
        $mins = round($diff / 60);
        return $mins . ' minute' . ($mins == 1 ? '' : 's');
    } elseif ($diff < 86400) {
        $hours = round($diff / 3600);
        return $hours . ' hour' . ($hours == 1 ? '' : 's');
    } else {
        $days = round($diff / 86400);
        return $days . ' day' . ($days == 1 ? '' : 's');
    }
}
?>