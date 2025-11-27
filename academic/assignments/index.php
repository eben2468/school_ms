<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Handle assignment deletion (teachers and admins only)
if (isset($_POST['delete_assignment']) && isset($_POST['assignment_id']) && in_array($user_role, ['super_admin', 'school_admin', 'teacher'])) {
    $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Check if user can delete this assignment
        if ($user_role === 'teacher') {
            $check_query = "SELECT teacher_id FROM assignments WHERE id = :assignment_id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':assignment_id', $assignment_id);
            $check_stmt->execute();
            $assignment = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment || $assignment['teacher_id'] != $user_id) {
                $error = "You can only delete your own assignments.";
            }
        }
        
        if (!isset($error)) {
            $query = "DELETE FROM assignments WHERE id = :assignment_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':assignment_id', $assignment_id);
            $stmt->execute();
            $success = "Assignment deleted successfully.";
        }
    } catch (PDOException $e) {
        $error = "Error deleting assignment. Please try again.";
    }
}

// Fetch assignments with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$subject_filter = isset($_GET['subject_id']) ? $_GET['subject_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "a.title LIKE :search";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "a.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($subject_filter) {
    $where_conditions[] = "a.subject_id = :subject_id";
    $params[':subject_id'] = $subject_filter;
}

// Role-based filtering
if ($user_role === 'teacher') {
    $where_conditions[] = "a.teacher_id = :teacher_id";
    $params[':teacher_id'] = $user_id;
} elseif ($user_role === 'student') {
    $where_conditions[] = "a.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active')";
    $params[':student_id'] = $user_id;
}

// Status filtering for students
if ($user_role === 'student' && $status_filter) {
    if ($status_filter === 'submitted') {
        $where_conditions[] = "EXISTS (SELECT 1 FROM student_assignments sa WHERE sa.assignment_id = a.id AND sa.student_id = :student_id_status)";
        $params[':student_id_status'] = $user_id;
    } elseif ($status_filter === 'pending') {
        $where_conditions[] = "NOT EXISTS (SELECT 1 FROM student_assignments sa WHERE sa.assignment_id = a.id AND sa.student_id = :student_id_status)";
        $params[':student_id_status'] = $user_id;
    }
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total assignments
$count_query = "SELECT COUNT(*) as total FROM assignments a $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_assignments = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_assignments / $limit);

// Fetch assignments
$query = "SELECT a.*, 
          c.name as class_name, 
          s.name as subject_name, 
          u.name as teacher_name,
          COUNT(sa.id) as submission_count
          FROM assignments a 
          JOIN classes c ON a.class_id = c.id
          JOIN subjects s ON a.subject_id = s.id
          JOIN users u ON a.teacher_id = u.id
          LEFT JOIN student_assignments sa ON a.id = sa.assignment_id
          $where_clause 
          GROUP BY a.id
          ORDER BY a.due_date DESC 
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes and subjects for filters
$classes_query = "SELECT id, name FROM classes WHERE status = 'active' ORDER BY name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$subjects_query = "SELECT id, name FROM subjects ORDER BY name";
$subjects_stmt = $db->query($subjects_query);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

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
                <h1 class="text-3xl font-semibold text-gray-800">
                    <?php echo $user_role === 'student' ? 'My Assignments' : 'Assignment Management'; ?>
                </h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Academic
                    </a>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher'])): ?>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Create Assignment
                    </a>
                    <?php endif; ?>
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

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search assignments..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="subject_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($user_role === 'student'): ?>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="submitted" <?php echo $status_filter === 'submitted' ? 'selected' : ''; ?>>Submitted</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Assignments List -->
            <div class="space-y-4">
                <?php foreach ($assignments as $assignment): ?>
                <?php
                $is_overdue = strtotime($assignment['due_date']) < time();
                $due_soon = strtotime($assignment['due_date']) < strtotime('+2 days');
                
                // Check if student has submitted (for student view)
                $is_submitted = false;
                if ($user_role === 'student') {
                    $submission_query = "SELECT id FROM student_assignments WHERE assignment_id = :assignment_id AND student_id = :student_id";
                    $submission_stmt = $db->prepare($submission_query);
                    $submission_stmt->bindParam(':assignment_id', $assignment['id']);
                    $submission_stmt->bindParam(':student_id', $user_id);
                    $submission_stmt->execute();
                    $is_submitted = $submission_stmt->rowCount() > 0;
                }
                ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <!-- Header with gradient background -->
                    <div class="bg-gradient-to-r from-indigo-500 to-purple-600 p-4">
                        <div class="flex justify-between items-start">
                            <div class="text-white flex-grow">
                                <h3 class="text-lg font-bold mb-2"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                <div class="flex flex-wrap gap-3 text-sm text-indigo-100">
                                    <span class="flex items-center">
                                        <i class="fas fa-chalkboard mr-1"></i>
                                        <?php echo htmlspecialchars($assignment['class_name']); ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-book mr-1"></i>
                                        <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                    </span>
                                    <span class="flex items-center">
                                        <i class="fas fa-user mr-1"></i>
                                        <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="flex flex-col items-end space-y-2">
                                <div class="flex space-x-2">
                                    <?php if ($user_role === 'student'): ?>
                                        <?php if ($is_submitted): ?>
                                        <span class="px-3 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-check mr-1"></i>Submitted
                                        </span>
                                        <?php elseif ($is_overdue): ?>
                                        <span class="px-3 py-1 bg-red-100 text-red-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                        </span>
                                        <?php elseif ($due_soon): ?>
                                        <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-clock mr-1"></i>Due Soon
                                        </span>
                                        <?php else: ?>
                                        <span class="px-3 py-1 bg-blue-100 text-blue-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-hourglass-half mr-1"></i>Pending
                                        </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($is_overdue): ?>
                                        <span class="px-3 py-1 bg-red-100 text-red-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Overdue
                                        </span>
                                        <?php elseif ($due_soon): ?>
                                        <span class="px-3 py-1 bg-yellow-100 text-yellow-800 text-xs font-semibold rounded-full">
                                            <i class="fas fa-clock mr-1"></i>Due Soon
                                        </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="p-6">
                        <!-- Due Date and Submissions Info -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center mr-3">
                                        <i class="fas fa-calendar-alt text-red-600 dark:text-red-400 text-sm"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Due Date</div>
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3">
                                        <i class="fas fa-users text-blue-600 dark:text-blue-400 text-sm"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Submissions</div>
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo $assignment['submission_count']; ?> submitted
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Description -->
                        <?php if ($assignment['description']): ?>
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-4">
                            <p class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed">
                                <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                            </p>
                        </div>
                        <?php endif; ?>

                        <!-- Footer -->
                        <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-600">
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                Created: <?php echo date('M j, Y', strtotime($assignment['created_at'])); ?>
                            </div>
                            <div class="flex space-x-2">
                                <a href="view.php?id=<?php echo $assignment['id']; ?>"
                                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                    <i class="fas fa-eye mr-2"></i>
                                    View Details
                                </a>
                                <?php if ($user_role === 'student' && !$is_submitted && !$is_overdue): ?>
                                <a href="submit.php?id=<?php echo $assignment['id']; ?>"
                                    class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200">
                                    <i class="fas fa-upload mr-2"></i>
                                    Submit
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin']) || ($user_role === 'teacher' && $assignment['teacher_id'] == $user_id)): ?>
                                <a href="edit.php?id=<?php echo $assignment['id']; ?>"
                                    class="inline-flex items-center p-2 border border-transparent rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200"
                                    title="Edit Assignment">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline"
                                    onsubmit="return confirm('Are you sure you want to delete this assignment?')">
                                    <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                    <button type="submit" name="delete_assignment"
                                        class="inline-flex items-center p-2 border border-transparent rounded-md text-red-400 hover:text-red-600 hover:bg-red-100 dark:hover:bg-red-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors duration-200"
                                        title="Delete Assignment">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($assignments)): ?>
            <div class="text-center py-12">
                <i class="fas fa-tasks text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No assignments found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($user_role === 'student'): ?>
                        No assignments have been assigned to your classes yet.
                    <?php else: ?>
                        Get started by creating your first assignment.
                    <?php endif; ?>
                </p>
                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher'])): ?>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create Assignment
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $class_filter ? "&class_id=$class_filter" : ''; ?><?php echo $subject_filter ? "&subject_id=$subject_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </nav>
            </div>
            <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
