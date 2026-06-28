<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$id) {
    header("Location: ../index.php");
    exit();
}

// Fetch class details
$query = "SELECT * FROM classes WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: ../index.php");
    exit();
}

// Teachers may only view classes they are assigned to teach.
if ($_SESSION['role'] === 'teacher') {
    $access_stmt = $db->prepare("SELECT COUNT(*) FROM class_teachers WHERE class_id = :class_id AND teacher_id = :teacher_id");
    $access_stmt->execute([':class_id' => $id, ':teacher_id' => $_SESSION['user_id']]);
    if ($access_stmt->fetchColumn() == 0) {
        header("Location: index.php?error=" . urlencode("You can only view classes you teach."));
        exit();
    }
}

// Fetch subjects and their assigned teachers for this class
$query = "SELECT s.name as subject_name, s.code as subject_code, u.name as teacher_name 
          FROM subjects s 
          LEFT JOIN class_teachers ct ON s.id = ct.subject_id AND ct.class_id = :class_id 
          LEFT JOIN users u ON ct.teacher_id = u.id 
          WHERE s.class_id = :class_id 
          ORDER BY s.name ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':class_id', $id);
$stmt->execute();
$class_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch enrolled students
$query = "SELECT u.*, sc.status as enrollment_status, sc.created_at as enrollment_date 
          FROM users u 
          JOIN student_classes sc ON u.id = sc.student_id 
          WHERE sc.class_id = :class_id AND u.role = 'student'
          ORDER BY u.name ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':class_id', $id);
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="class-details-header">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white mb-3">Class Details</h1>
                    <div class="flex no-stack space-x-4">
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                        <a href="edit.php?id=<?php echo $id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            Edit Class
                        </a>
                        <?php endif; ?>
                        <a href="../index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Academic Management
                        </a>
                    </div>
                </div>

                <!-- Class Information -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-6">
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <h2 class="text-sm font-medium text-gray-500">Class Name</h2>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($class['name']); ?></p>
                        </div>
                        <div>
                            <h2 class="text-sm font-medium text-gray-500">Grade Level</h2>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($class['grade_level']); ?></p>
                        </div>
                        <div>
                            <h2 class="text-sm font-medium text-gray-500">Academic Year</h2>
                            <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($class['academic_year']); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Teachers and Subjects -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-semibold text-gray-800">Teachers & Subjects</h2>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php if (empty($class_teachers)): ?>
                            <p class="text-gray-500 text-center py-4">No subjects are currently offered in this class.</p>
                            <?php else: ?>
                                <?php foreach ($class_teachers as $teacher): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div>
                                        <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($teacher['subject_name']); ?></h3>
                                        <p class="text-sm text-gray-500">
                                            Teacher: <?php echo $teacher['teacher_name'] ? htmlspecialchars($teacher['teacher_name']) : '<span class="text-red-500 italic font-medium">Not Assigned</span>'; ?> |
                                            Code: <?php echo htmlspecialchars($teacher['subject_code']); ?>
                                        </p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Enrolled Students -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h2 class="text-xl font-semibold text-gray-800">Enrolled Students</h2>
                            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="manage_students.php?class_id=<?php echo $id; ?>" class="text-blue-600 hover:text-blue-800">
                                Manage Students
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php foreach ($students as $student): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($student['name']); ?></h3>
                                    <p class="text-sm text-gray-500">
                                        Status: <span class="<?php echo $student['enrollment_status'] === 'active' ? 'text-green-600' : 'text-red-600'; ?>">
                                            <?php echo ucfirst($student['enrollment_status']); ?>
                                        </span>
                                        | Enrolled: <?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?>
                                    </p>
                                </div>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                <a href="../student_profile.php?id=<?php echo $student['id']; ?>" class="text-blue-600 hover:text-blue-800">
                                    View Profile
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Class Schedule -->
            <div class="mt-6 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Class Schedule</h2>
                </div>
                <div class="p-6">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Monday</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tuesday</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Wednesday</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thursday</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Friday</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php
                                // Get all schedules for this class
                                $schedule_query = "SELECT cs.*, s.name as subject_name, s.code as subject_code, u.name as teacher_name
                                                  FROM class_schedule cs
                                                  LEFT JOIN subjects s ON cs.subject_id = s.id
                                                  LEFT JOIN users u ON cs.teacher_id = u.id
                                                  WHERE cs.class_id = :class_id
                                                  ORDER BY cs.time_slot, cs.day";
                                $stmt = $db->prepare($schedule_query);
                                $stmt->bindParam(':class_id', $id);
                                $stmt->execute();
                                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                // Get unique time slots from the database
                                $time_slots_query = "SELECT DISTINCT time_slot FROM class_schedule WHERE class_id = :class_id ORDER BY time_slot";
                                $stmt = $db->prepare($time_slots_query);
                                $stmt->bindParam(':class_id', $id);
                                $stmt->execute();
                                $db_time_slots = $stmt->fetchAll(PDO::FETCH_COLUMN);

                                // Use database time slots if available, otherwise use default
                                $time_slots = !empty($db_time_slots) ? $db_time_slots : [
                                    '08:00-09:00', '09:00-10:00', '10:00-11:00', '11:00-12:00',
                                    '13:00-14:00', '14:00-15:00', '15:00-16:00'
                                ];

                                foreach ($time_slots as $time_slot):
                                ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo $time_slot; ?>
                                    </td>
                                    <?php
                                    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                    
                                    // Check if this time slot is a break
                                    $is_break_slot = false;
                                    $break_title = 'Break';
                                    foreach ($days as $day) {
                                        $day_schedule = array_filter($schedules, function($s) use ($day, $time_slot) {
                                            return $s['day'] === $day && $s['time_slot'] === $time_slot;
                                        });
                                        $day_schedule = reset($day_schedule);
                                        if ($day_schedule && isset($day_schedule['is_break']) && $day_schedule['is_break'] == 1) {
                                            $is_break_slot = true;
                                            if (!empty($day_schedule['break_name'])) {
                                                $break_title = $day_schedule['break_name'];
                                            }
                                            break;
                                        }
                                    }

                                    if ($is_break_slot):
                                    ?>
                                    <td colspan="5" class="px-6 py-4 whitespace-nowrap text-center text-sm font-semibold bg-purple-50 text-purple-700 dark:bg-purple-950/30 dark:text-purple-300 rounded-md">
                                        <div class="flex items-center justify-center space-x-2">
                                            <i class="fas fa-coffee text-purple-500 animate-pulse"></i>
                                            <span><?php echo htmlspecialchars($break_title); ?></span>
                                        </div>
                                    </td>
                                    <?php else: ?>
                                        <?php
                                        foreach ($days as $day):
                                            $schedule = array_filter($schedules, function($s) use ($day, $time_slot) {
                                                return $s['day'] === $day && $s['time_slot'] === $time_slot;
                                            });
                                            $schedule = reset($schedule);
                                        ?>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?php if ($schedule): ?>
                                            <div class="font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($schedule['subject_name'] ?? 'Unknown Subject'); ?>
                                                <br><span class="text-sm text-gray-500 dark:text-gray-400">
                                                    <?php echo htmlspecialchars($schedule['teacher_name'] ?? 'No Teacher Assigned'); ?>
                                                </span>
                                            </div>
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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