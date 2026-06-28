<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor', 'counselor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class_id']) ? $_GET['class_id'] : '';

// Build conditions
$where_conditions = ["u.role = 'student'", "u.status = 'active'"];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR sp.student_id LIKE :search OR sp.emergency_contact_name LIKE :search OR sp.parent_name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "sc.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch student emergency contacts
$query = "SELECT u.name as student_name, sp.*, c.name as class_name,
                 u.email as student_email
          FROM users u
          JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY u.name ASC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$contacts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Emergency Contacts Directory";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                
                <!-- Page Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-850 dark:text-white"><i class="fas fa-ambulance text-red-500 mr-2"></i>Emergency Contacts Directory</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Quick access to student emergency contacts and parent details</p>
                    </div>
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Health
                    </a>
                </div>

                <!-- Search and Filter -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4 mb-6 border border-gray-150 dark:border-gray-700">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="search" class="block text-xs font-semibold text-gray-400 uppercase mb-1">Search Student / Parent / Contact</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Search name, ID or emergency contact..." 
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="class_id" class="block text-xs font-semibold text-gray-400 uppercase mb-1">Class Filter</label>
                            <select id="class_id" name="class_id" 
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-sm focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Contacts Grid -->
                <?php if (!empty($contacts)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($contacts as $contact): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-150 dark:border-gray-750 p-6 flex flex-col justify-between hover:shadow-lg transition-shadow duration-200">
                            <div>
                                <!-- Header: Student details -->
                                <div class="flex justify-between items-start mb-4">
                                    <div>
                                        <h3 class="text-base font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($contact['student_name']); ?></h3>
                                        <span class="text-xs text-gray-400 font-semibold block">ID: <?php echo htmlspecialchars($contact['student_id']); ?></span>
                                    </div>
                                    <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-55 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400">
                                        <?php echo htmlspecialchars($contact['class_name'] ?? 'Not Assigned'); ?>
                                    </span>
                                </div>

                                <hr class="my-3.5 border-gray-100 dark:border-gray-700">

                                <!-- Body: Contacts -->
                                <div class="space-y-4 text-xs">
                                    <!-- Primary Emergency Contact -->
                                    <div class="p-3 bg-red-50/45 dark:bg-red-950/10 rounded-lg border border-red-50 dark:border-red-950/15">
                                        <span class="font-bold text-red-700 dark:text-red-400 uppercase tracking-wider block text-[10px] mb-1.5"><i class="fas fa-exclamation-triangle mr-1"></i>Primary Emergency Contact</span>
                                        <div class="flex justify-between text-gray-800 dark:text-gray-200">
                                            <span class="font-semibold"><?php echo htmlspecialchars($contact['emergency_contact_name'] ?? 'Not Provided'); ?></span>
                                            <a href="tel:<?php echo htmlspecialchars($contact['emergency_contact_phone'] ?? ''); ?>" class="text-blue-600 hover:underline"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($contact['emergency_contact_phone'] ?? ''); ?></a>
                                        </div>
                                    </div>

                                    <!-- Parent/Guardian details -->
                                    <div class="p-3 bg-gray-50/50 dark:bg-gray-700/25 rounded-lg border border-gray-100 dark:border-gray-700/40">
                                        <span class="font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider block text-[10px] mb-1.5">Parent / Guardian</span>
                                        <div class="space-y-1 text-gray-850 dark:text-gray-200">
                                            <div class="flex justify-between">
                                                <span>Name:</span>
                                                <span class="font-medium"><?php echo htmlspecialchars($contact['parent_name'] ?? $contact['guardian_name'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="flex justify-between">
                                                <span>Phone:</span>
                                                <a href="tel:<?php echo htmlspecialchars($contact['parent_phone'] ?? $contact['guardian_phone'] ?? ''); ?>" class="text-blue-600 hover:underline"><i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($contact['parent_phone'] ?? $contact['guardian_phone'] ?? 'N/A'); ?></a>
                                            </div>
                                            <div class="flex justify-between">
                                                <span>Email:</span>
                                                <span class="truncate max-w-[150px]"><?php echo htmlspecialchars($contact['parent_email'] ?? $contact['guardian_email'] ?? 'N/A'); ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Health alerts -->
                                    <?php if (!empty($contact['medical_conditions'])): ?>
                                    <div class="p-2.5 bg-yellow-50/35 dark:bg-yellow-950/10 border border-yellow-100 dark:border-yellow-950/20 rounded-md">
                                        <span class="font-bold text-yellow-700 dark:text-yellow-400 block mb-0.5"><i class="fas fa-heartbeat mr-1"></i>Medical Alerts:</span>
                                        <p class="text-gray-750 dark:text-gray-300"><?php echo htmlspecialchars($contact['medical_conditions']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700 flex justify-between">
                                <a href="../records/medical_history.php?student_id=<?php echo $contact['user_id']; ?>" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold"><i class="fas fa-history mr-1"></i>Medical History</a>
                                <a href="../records/view.php?id=<?php echo $contact['user_id']; ?>" class="text-xs text-gray-500 hover:text-gray-700"><i class="fas fa-eye mr-1"></i>View Profile</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-12 text-center border border-gray-100 dark:border-gray-700">
                    <i class="fas fa-ambulance text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Emergency Contacts Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No students found matching the search criteria or class filter.</p>
                </div>
                <?php endif; ?>

            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
