<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get health records with filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$condition_filter = filter_input(INPUT_GET, 'condition', FILTER_SANITIZE_STRING);

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(s.name LIKE :search OR sp.student_id LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "sc.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($condition_filter && $condition_filter !== 'all') {
    $where_conditions[] = "hr.medical_conditions LIKE :condition";
    $params[':condition'] = "%$condition_filter%";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_query = "SELECT COUNT(*) FROM health_records hr
                JOIN users u ON hr.student_id = u.id
                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Fetch health records
$query = "SELECT hr.*, u.name as student_name, sp.student_id, c.name as class_name,
                 sp.blood_group as blood_type, sp.medical_conditions,
                 sp.emergency_contact_name, sp.emergency_contact_phone
          FROM health_records hr
          JOIN users u ON hr.student_id = u.id
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY u.name ASC
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$health_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Health Records";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800">Health Records</h1>
                    <div class="flex space-x-3">
                        <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Health
                        </a>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Add Record
                        </a>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700">Search Student</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                placeholder="Search by name or ID..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700">Class</label>
                            <select id="class_id" name="class_id" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" 
                                    <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="condition" class="block text-sm font-medium text-gray-700">Medical Condition</label>
                            <select id="condition" name="condition" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="all">All Conditions</option>
                                <option value="asthma" <?php echo $condition_filter === 'asthma' ? 'selected' : ''; ?>>Asthma</option>
                                <option value="diabetes" <?php echo $condition_filter === 'diabetes' ? 'selected' : ''; ?>>Diabetes</option>
                                <option value="allergies" <?php echo $condition_filter === 'allergies' ? 'selected' : ''; ?>>Allergies</option>
                                <option value="epilepsy" <?php echo $condition_filter === 'epilepsy' ? 'selected' : ''; ?>>Epilepsy</option>
                                <option value="heart" <?php echo $condition_filter === 'heart' ? 'selected' : ''; ?>>Heart Condition</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Health Records Table -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Blood Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Medical Conditions</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Emergency Contact</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($health_records)): ?>
                                    <?php foreach ($health_records as $record): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['student_name']); ?></div>
                                                <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($record['student_id']); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($record['class_name'] ?? 'Not Assigned'); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo htmlspecialchars($record['blood_type'] ?? 'Unknown'); ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php if ($record['medical_conditions']): ?>
                                                    <?php 
                                                    $conditions = explode(',', $record['medical_conditions']);
                                                    foreach ($conditions as $condition): 
                                                    ?>
                                                    <span class="inline-block px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full mr-1 mb-1">
                                                        <?php echo htmlspecialchars(trim($condition)); ?>
                                                    </span>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <span class="text-gray-500">None reported</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900"><?php echo htmlspecialchars($record['emergency_contact_name'] ?? 'Not provided'); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo htmlspecialchars($record['emergency_contact_phone'] ?? ''); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="view.php?id=<?php echo $record['id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $record['id']; ?>" class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="medical_history.php?student_id=<?php echo $record['student_id']; ?>" class="text-purple-600 hover:text-purple-900">
                                                    <i class="fas fa-history"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <i class="fas fa-heartbeat text-gray-400 text-4xl mb-4"></i>
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">No health records found</h3>
                                        <p class="text-gray-500 mb-4">
                                            <?php if ($search || $class_filter || $condition_filter): ?>
                                                Try adjusting your search criteria.
                                            <?php else: ?>
                                                No health records have been created yet.
                                            <?php endif; ?>
                                        </p>
                                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                            Add First Record
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $class_filter ? "&class_id=$class_filter" : ''; ?><?php echo $condition_filter ? "&condition=$condition_filter" : ''; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $class_filter ? "&class_id=$class_filter" : ''; ?><?php echo $condition_filter ? "&condition=$condition_filter" : ''; ?>" 
                               class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_records); ?></span> of 
                                    <span class="font-medium"><?php echo $total_records; ?></span> results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $class_filter ? "&class_id=$class_filter" : ''; ?><?php echo $condition_filter ? "&condition=$condition_filter" : ''; ?>" 
                                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                    <?php endfor; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Stats -->
                <div class="mt-8 grid grid-cols-1 md:grid-cols-4 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100">
                                <i class="fas fa-users text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Records</p>
                                <p class="text-2xl font-semibold text-blue-600"><?php echo $total_records; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">With Conditions</p>
                                <p class="text-2xl font-semibold text-red-600">
                                    <?php 
                                    $conditions_count = 0;
                                    foreach ($health_records as $record) {
                                        if (!empty($record['medical_conditions'])) {
                                            $conditions_count++;
                                        }
                                    }
                                    echo $conditions_count;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100">
                                <i class="fas fa-phone text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Emergency Contacts</p>
                                <p class="text-2xl font-semibold text-green-600">
                                    <?php 
                                    $emergency_count = 0;
                                    foreach ($health_records as $record) {
                                        if (!empty($record['emergency_contact_name'])) {
                                            $emergency_count++;
                                        }
                                    }
                                    echo $emergency_count;
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100">
                                <i class="fas fa-tint text-purple-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Blood Types Recorded</p>
                                <p class="text-2xl font-semibold text-purple-600">
                                    <?php 
                                    $blood_types = 0;
                                    foreach ($health_records as $record) {
                                        if (!empty($record['blood_type'])) {
                                            $blood_types++;
                                        }
                                    }
                                    echo $blood_types;
                                    ?>
                                </p>
                            </div>
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
