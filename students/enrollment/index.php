<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'registrar'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle enrollment status update
if (isset($_POST['update_status']) && isset($_POST['enrollment_id']) && isset($_POST['status'])) {
    $enrollment_id = filter_input(INPUT_POST, 'enrollment_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE student_enrollments SET status = :status WHERE id = :enrollment_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':enrollment_id', $enrollment_id);
    
    if ($stmt->execute()) {
        $success_message = "Enrollment status updated successfully!";
    } else {
        $error_message = "Error updating enrollment status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$class_filter = isset($_GET['class']) ? $_GET['class'] : '';
$academic_year_filter = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(se.student_name LIKE :search OR se.student_id LIKE :search OR se.parent_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "se.status = :status";
    $params[':status'] = $status_filter;
}

if ($class_filter) {
    $where_conditions[] = "se.class = :class";
    $params[':class'] = $class_filter;
}

if ($academic_year_filter) {
    $where_conditions[] = "se.academic_year = :academic_year";
    $params[':academic_year'] = $academic_year_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch enrollments
$query = "SELECT se.*, 
          CASE WHEN u.id IS NOT NULL THEN 'Yes' ELSE 'No' END as user_created
          FROM student_enrollments se
          LEFT JOIN users u ON se.student_id = u.student_id
          $where_clause
          ORDER BY se.application_date DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT DISTINCT class FROM student_enrollments WHERE class IS NOT NULL ORDER BY class";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get academic years for filter
$years_query = "SELECT DISTINCT academic_year FROM student_enrollments WHERE academic_year IS NOT NULL ORDER BY academic_year DESC";
$years_stmt = $db->query($years_query);
$academic_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get enrollment statistics
$stats_query = "SELECT 
    COUNT(*) as total_applications,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_applications,
    COUNT(CASE WHEN status = 'approved' THEN 1 END) as approved_applications,
    COUNT(CASE WHEN status = 'rejected' THEN 1 END) as rejected_applications
    FROM student_enrollments";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Student Enrollment";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Student Enrollment</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Students
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>New Application
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
                            <p class="text-sm font-medium text-gray-600">Total Applications</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_applications']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['pending_applications']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Approved</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['approved_applications']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Rejected</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['rejected_applications']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by student name, ID, or parent..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
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
                            <select name="academic_year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Years</option>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $academic_year_filter === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                                <?php endforeach; ?>
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

            <!-- Enrollments Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class & Year</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Parent/Guardian</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Application Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($enrollments as $enrollment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($enrollment['student_name']); ?></div>
                                    <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($enrollment['student_id']); ?></div>
                                    <div class="text-xs text-gray-400">DOB: <?php echo date('M j, Y', strtotime($enrollment['date_of_birth'])); ?></div>
                                    <?php if ($enrollment['user_created'] === 'Yes'): ?>
                                    <div class="text-xs text-green-600">✓ User Account Created</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($enrollment['class']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($enrollment['academic_year']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($enrollment['parent_name']); ?></div>
                                    <?php if ($enrollment['parent_phone']): ?>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($enrollment['parent_phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($enrollment['parent_email']): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($enrollment['parent_email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($enrollment['application_date'])); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_classes = [
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'approved' => 'bg-green-100 text-green-800',
                                        'rejected' => 'bg-red-100 text-red-800'
                                    ];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$enrollment['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($enrollment['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="view.php?id=<?php echo $enrollment['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                    <a href="edit.php?id=<?php echo $enrollment['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                    
                                    <?php if ($enrollment['status'] === 'pending'): ?>
                                    <form action="" method="POST" class="inline mr-2">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['id']; ?>">
                                        <input type="hidden" name="status" value="approved">
                                        <button type="submit" name="update_status" class="text-green-600 hover:text-green-900">Approve</button>
                                    </form>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="enrollment_id" value="<?php echo $enrollment['id']; ?>">
                                        <input type="hidden" name="status" value="rejected">
                                        <button type="submit" name="update_status" class="text-red-600 hover:text-red-900" 
                                                onclick="return confirm('Are you sure you want to reject this application?')">Reject</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($enrollments)): ?>
            <div class="text-center py-12">
                <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No enrollment applications found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $class_filter || $academic_year_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No enrollment applications have been submitted yet.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Application
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
