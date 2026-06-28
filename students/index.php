<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('students');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle student status updates
if (isset($_POST['update_status']) && isset($_POST['student_id'])) {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
    
    try {
        $query = "UPDATE users SET status = :status WHERE id = :student_id AND role = 'student'";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':status', $new_status);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        $success = "Student status updated successfully.";
    } catch (PDOException $e) {
        $error = "Error updating student status.";
    }
}

// Fetch students with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$where_conditions = ["u.role = 'student'"];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.email LIKE :search OR u.student_id LIKE :search OR sp.student_id LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "sc.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($status_filter) {
    $where_conditions[] = "u.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Count total students
$count_query = "SELECT COUNT(DISTINCT u.id) as total 
                FROM users u 
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_students = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_students / $limit);

// Fetch students with their details
$query = "SELECT DISTINCT u.id, u.name, u.email, u.status, u.created_at, u.profile_picture,
          COALESCE(u.student_id, sp.student_id) as student_id, sp.admission_date, sp.phone, sp.date_of_birth,
          c.name as class_name, c.grade_level,
          p.name as parent_name
          FROM users u 
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN users p ON sp.parent_id = p.id
          $where_clause 
          ORDER BY u.name 
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
$title = "Student Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Student Management']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Student Management</h1>
                                <p class="text-blue-100 text-lg">Manage student enrollment and information</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        Student records & enrollment
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-user-graduate text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end items-center mb-6">
                    <div class="flex space-x-3">
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'])): ?>
                        <a href="enroll.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-user-plus mr-2"></i>Enroll Student
                        </a>
                        <a href="bulk_import.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-upload mr-2"></i>Bulk Import
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
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search students..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Students Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($students as $student): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div class="flex items-center space-x-3">
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center overflow-hidden border border-gray-200">
                                    <?php if (!empty($student['profile_picture'])): ?>
                                        <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-user text-blue-600 text-lg"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($student['name']); ?></h3>
                                    <p class="text-sm text-gray-600"><?php echo htmlspecialchars($student['student_id'] ?? 'No ID'); ?></p>
                                </div>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $student['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </div>

                        <div class="space-y-2 text-sm text-gray-600">
                            <div class="flex items-center">
                                <i class="fas fa-envelope w-4 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['email']); ?></span>
                            </div>
                            <?php if ($student['phone']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-phone w-4 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['phone']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($student['class_name']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-chalkboard w-4 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['grade_level'] . ' - ' . $student['class_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($student['parent_name']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-user-friends w-4 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['parent_name']); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($student['admission_date']): ?>
                            <div class="flex items-center">
                                <i class="fas fa-calendar w-4 mr-2"></i>
                                <span>Admitted: <?php echo date('M j, Y', strtotime($student['admission_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-4 flex justify-between items-center">
                            <a href="profile.php?id=<?php echo $student['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Profile
                            </a>
                            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'])): ?>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $student['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $student['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                    <button type="submit" name="update_status" 
                                        class="text-<?php echo $student['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $student['status'] === 'active' ? 'red' : 'green'; ?>-800"
                                        title="<?php echo $student['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Student">
                                        <i class="fas fa-<?php echo $student['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($students)): ?>
            <div class="text-center py-12">
                <i class="fas fa-users text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No students found</h3>
                <p class="text-gray-500 mb-4">Get started by enrolling your first student.</p>
                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'])): ?>
                <a href="enroll.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                    Enroll Student
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center w-full">
                <div class="max-w-full overflow-x-auto pb-3 px-2 flex justify-start scrollbar-thin">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px whitespace-nowrap flex-nowrap min-w-max" aria-label="Pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $class_filter ? "&class_id=$class_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                            <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                    </nav>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
