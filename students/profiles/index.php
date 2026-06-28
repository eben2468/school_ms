<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher', 'registrar'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle student status update
if (isset($_POST['update_status']) && isset($_POST['student_id']) && isset($_POST['status'])) {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE users SET status = :status WHERE id = :student_id AND role = 'student'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':student_id', $student_id);
    
    if ($stmt->execute()) {
        $success_message = "Student status updated successfully!";
    } else {
        $error_message = "Error updating student status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class']) ? $_GET['class'] : '';
$section_filter = isset($_GET['section']) ? $_GET['section'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';

// Build where conditions
$where_conditions = ["u.role = 'student'"];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.student_id LIKE :search OR u.email LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "u.class = :class";
    $params[':class'] = $class_filter;
}

if ($section_filter) {
    $where_conditions[] = "u.section = :section";
    $params[':section'] = $section_filter;
}

if ($status_filter) {
    $where_conditions[] = "u.status = :status";
    $params[':status'] = $status_filter;
}

if ($gender_filter) {
    $where_conditions[] = "sp.gender = :gender";
    $params[':gender'] = $gender_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch students with profile information
$query = "SELECT u.*, sp.date_of_birth, sp.gender, sp.address, sp.phone, 
          sp.parent_name, sp.parent_phone, sp.parent_email,
          sp.emergency_contact_name, sp.emergency_contact_phone
          FROM users u
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          $where_clause
          ORDER BY u.class, u.section, u.name";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT DISTINCT class FROM users WHERE role = 'student' AND class IS NOT NULL ORDER BY class";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get sections for filter
$sections_query = "SELECT DISTINCT section FROM users WHERE role = 'student' AND section IS NOT NULL ORDER BY section";
$sections_stmt = $db->query($sections_query);
$sections = $sections_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get student statistics
$stats_query = "SELECT 
    COUNT(*) as total_students,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_students,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_students,
    COUNT(CASE WHEN status = 'graduated' THEN 1 END) as graduated_students
    FROM users WHERE role = 'student'";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Student Profiles";
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
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Student Profiles</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Students
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Student
                    </a>
                    <a href="bulk_import.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-upload mr-2"></i>Bulk Import
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Students</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_students']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-user-graduate text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Students</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_students']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inactive Students</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['inactive_students']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-user-times text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Graduated</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['graduated_students']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-graduation-cap text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by name, ID, or email..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="class" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $class_filter === $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="section" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Sections</option>
                                <?php foreach ($sections as $section): ?>
                                <option value="<?php echo htmlspecialchars($section); ?>" <?php echo $section_filter === $section ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($section); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
                            </select>
                        </div>
                        <div>
                            <select name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Genders</option>
                                <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
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
                            <div class="flex-grow">
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($student['name']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium">ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                                <?php if ($student['class'] && $student['section']): ?>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($student['class'] . ' - ' . $student['section']); ?></p>
                                <?php endif; ?>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                switch($student['status']) {
                                    case 'active': echo 'bg-green-100 text-green-800'; break;
                                    case 'inactive': echo 'bg-red-100 text-red-800'; break;
                                    case 'graduated': echo 'bg-purple-100 text-purple-800'; break;
                                    default: echo 'bg-gray-100 text-gray-800';
                                }
                                ?>">
                                <?php echo ucfirst($student['status']); ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <?php if ($student['email']): ?>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-envelope text-blue-500 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['email']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($student['phone']): ?>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-phone text-green-500 mr-2"></i>
                                <span><?php echo htmlspecialchars($student['phone']); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($student['date_of_birth']): ?>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-birthday-cake text-purple-500 mr-2"></i>
                                <span><?php echo date('M j, Y', strtotime($student['date_of_birth'])); ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($student['gender']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-<?php echo $student['gender'] === 'male' ? 'mars' : 'venus'; ?> text-pink-500 mr-2"></i>
                                <span><?php echo ucfirst($student['gender']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($student['parent_name']): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900">Parent/Guardian:</div>
                                <div class="text-gray-600"><?php echo htmlspecialchars($student['parent_name']); ?></div>
                                <?php if ($student['parent_phone']): ?>
                                <div class="text-gray-600"><?php echo htmlspecialchars($student['parent_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $student['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Profile
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $student['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($student['status'] === 'active'): ?>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <input type="hidden" name="status" value="inactive">
                                    <button type="submit" name="update_status" 
                                        class="text-red-600 hover:text-red-800"
                                        title="Deactivate Student">
                                        <i class="fas fa-user-times"></i>
                                    </button>
                                </form>
                                <?php elseif ($student['status'] === 'inactive'): ?>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                    <input type="hidden" name="status" value="active">
                                    <button type="submit" name="update_status" 
                                        class="text-green-600 hover:text-green-800"
                                        title="Activate Student">
                                        <i class="fas fa-user-check"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($students)): ?>
            <div class="text-center py-12">
                <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No students found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $class_filter || $section_filter || $status_filter || $gender_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No students have been added yet.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Add First Student
                </a>
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

