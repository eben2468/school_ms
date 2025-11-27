<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle class status toggle
if (isset($_POST['toggle_status']) && isset($_POST['class_id'])) {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE classes SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :class_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $class_id);
    $stmt->execute();
    header("Location: index.php");
    exit();
}

// Fetch classes with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$grade_filter = isset($_GET['grade']) ? $_GET['grade'] : '';
$year_filter = isset($_GET['year']) ? $_GET['year'] : '';

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "name LIKE :search";
    $params[':search'] = "%$search%";
}

if ($grade_filter) {
    $where_conditions[] = "grade_level = :grade";
    $params[':grade'] = $grade_filter;
}

if ($year_filter) {
    $where_conditions[] = "academic_year = :year";
    $params[':year'] = $year_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total classes
$count_query = "SELECT COUNT(*) as total FROM classes $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_classes = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_classes / $limit);

// Fetch classes with student count and main teacher
$query = "SELECT c.*,
          COUNT(DISTINCT sc.student_id) as student_count,
          COUNT(DISTINCT ct.teacher_id) as teacher_count,
          u.name as main_teacher_name
          FROM classes c
          LEFT JOIN student_classes sc ON c.id = sc.class_id AND sc.status = 'active'
          LEFT JOIN class_teachers ct ON c.id = ct.class_id
          LEFT JOIN users u ON c.main_teacher_id = u.id
          $where_clause
          GROUP BY c.id, u.name
          ORDER BY c.grade_level, c.name
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique grade levels and academic years for filters
$grade_query = "SELECT DISTINCT grade_level FROM classes ORDER BY grade_level";
$grade_stmt = $db->query($grade_query);
$grades = $grade_stmt->fetchAll(PDO::FETCH_COLUMN);

$year_query = "SELECT DISTINCT academic_year FROM classes ORDER BY academic_year DESC";
$year_stmt = $db->query($year_query);
$years = $year_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <!-- Header Section -->
            <div class="mb-8">
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Class Management</h1>
                            <p class="text-blue-100 text-lg">Manage classes, students, and academic organization</p>
                            <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                <div class="flex items-center">
                                    <i class="fas fa-users mr-2"></i>
                                    Academic Management
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-clock mr-2"></i>
                                    <?php echo date('l, F j, Y'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-users text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-end items-center mb-6">
                <div class="flex space-x-3">
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Academic
                    </a>
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-plus mr-2"></i>Add New Class
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by class name" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="grade" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Grades</option>
                                <?php foreach ($grades as $grade): ?>
                                <option value="<?php echo htmlspecialchars($grade); ?>" <?php echo $grade_filter === $grade ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($grade); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-48">
                            <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Years</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year_filter === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Classes Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($classes as $class): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <!-- Header with gradient background -->
                    <div class="bg-gradient-to-r from-blue-500 to-purple-600 p-4">
                        <div class="flex justify-between items-start">
                            <div class="text-white">
                                <h3 class="text-lg font-bold"><?php echo htmlspecialchars($class['name']); ?></h3>
                                <p class="text-blue-100 text-sm"><?php echo htmlspecialchars($class['grade_level']); ?></p>
                                <p class="text-blue-200 text-xs"><?php echo htmlspecialchars($class['academic_year']); ?></p>
                            </div>
                            <span class="px-3 py-1 text-xs font-semibold rounded-full
                                <?php echo $class['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($class['status']); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="p-6">
                        <!-- Stats -->
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $class['student_count']; ?></div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Students</div>
                            </div>
                            <div class="text-center p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $class['teacher_count']; ?></div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Teachers</div>
                            </div>
                        </div>

                        <!-- Main Teacher Info -->
                        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 mb-4">
                            <div class="flex items-center">
                                <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-user-tie text-purple-600 dark:text-purple-400 text-sm"></i>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">Main Teacher</div>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo $class['main_teacher_name'] ? htmlspecialchars($class['main_teacher_name']) : 'Not Assigned'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $class['id']; ?>"
                                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                <i class="fas fa-eye mr-2"></i>
                                View Details
                            </a>
                            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'])): ?>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $class['id']; ?>"
                                    class="inline-flex items-center p-2 border border-transparent rounded-md text-gray-400 hover:text-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200"
                                    title="Edit Class">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                    <button type="submit" name="toggle_status"
                                        class="inline-flex items-center p-2 border border-transparent rounded-md text-<?php echo $class['status'] === 'active' ? 'red' : 'green'; ?>-400 hover:text-<?php echo $class['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:bg-<?php echo $class['status'] === 'active' ? 'red' : 'green'; ?>-100 dark:hover:bg-<?php echo $class['status'] === 'active' ? 'red' : 'green'; ?>-900/20 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-<?php echo $class['status'] === 'active' ? 'red' : 'green'; ?>-500 transition-colors duration-200"
                                        title="<?php echo $class['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Class">
                                        <i class="fas fa-<?php echo $class['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $grade_filter ? "&grade=$grade_filter" : ''; ?><?php echo $year_filter ? "&year=$year_filter" : ''; ?>" 
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
