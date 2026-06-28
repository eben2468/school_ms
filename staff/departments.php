<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$staff_roles = ['teacher','librarian','accountant','nurse','counselor','transport_officer','hostel_warden','canteen_manager','hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $head_id = filter_input(INPUT_POST, 'head_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $description = $_POST['description'] ?? '';
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        
        try {
            $stmt = $db->prepare("INSERT INTO staff_departments (name, head_id, description, status) VALUES (:name, :head_id, :desc, :status)");
            $stmt->execute([':name' => $name, ':head_id' => $head_id, ':desc' => $description, ':status' => $status]);
            $success_msg = "Department created successfully.";
        } catch (PDOException $e) {
            $error_msg = "Error creating department: " . $e->getMessage();
        }
    } elseif ($action === 'update') {
        $id = filter_input(INPUT_POST, 'dept_id', FILTER_SANITIZE_NUMBER_INT);
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $head_id = filter_input(INPUT_POST, 'head_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $description = $_POST['description'] ?? '';
        $status = $_POST['status'] === 'active' ? 'active' : 'inactive';
        
        try {
            $stmt = $db->prepare("UPDATE staff_departments SET name = :name, head_id = :head_id, description = :desc, status = :status WHERE id = :id");
            $stmt->execute([':name' => $name, ':head_id' => $head_id, ':desc' => $description, ':status' => $status, ':id' => $id]);
            $success_msg = "Department updated successfully.";
        } catch (PDOException $e) {
            $error_msg = "Error updating department: " . $e->getMessage();
        }
    } elseif ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'dept_id', FILTER_SANITIZE_NUMBER_INT);
        try {
            $stmt = $db->prepare("UPDATE staff_departments SET status = 'inactive' WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $success_msg = "Department deactivated successfully.";
        } catch (PDOException $e) {
            $error_msg = "Error deleting department: " . $e->getMessage();
        }
    }
}

// Fetch active staff for head selection
$staff_list = $db->query("SELECT id, name FROM users WHERE role IN ($staff_roles_in) AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Departments with stats
$query = "
    SELECT d.*, u.name as head_name,
           (SELECT COUNT(*) FROM teacher_profiles tp 
            JOIN users su ON tp.user_id = su.id
            WHERE (tp.department = d.name OR tp.department_id = d.id) 
            AND su.status = 'active' AND su.role IN ($staff_roles_in)) as staff_count
    FROM staff_departments d
    LEFT JOIN users u ON d.head_id = u.id
    ORDER BY d.name
";
$departments = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Stats
$total_depts = count($departments);
$active_depts = count(array_filter($departments, function($d){ return $d['status'] === 'active'; }));
$total_assigned_staff = array_sum(array_column($departments, 'staff_count'));

$title = "Department Management";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{ showForm: false, editMode: false, currentDept: {} }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Department Management</h1>
                                <p class="text-blue-100 text-lg">Organize staff into departments and faculties</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-sitemap text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_msg)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error_msg)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-blue-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Total Departments</p>
                        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $total_depts; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-green-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Active Departments</p>
                        <h3 class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $active_depts; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-purple-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Assigned Staff</p>
                        <h3 class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo $total_assigned_staff; ?></h3>
                    </div>
                </div>

                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800 dark:text-white">All Departments</h2>
                    <button @click="showForm = !showForm; editMode = false; currentDept = {}" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg shadow font-medium transition-colors">
                        <i class="fas fa-plus mr-2"></i>Add Department
                    </button>
                </div>

                <!-- Add/Edit Form -->
                <div x-show="showForm" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-4" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="mb-8">
                    <form action="" method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4 border-b dark:border-gray-700 pb-2" x-text="editMode ? 'Edit Department' : 'Create New Department'"></h3>
                        
                        <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
                        <input type="hidden" name="dept_id" :value="currentDept.id">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Department Name *</label>
                                <input type="text" name="name" :value="currentDept.name" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Department Head</label>
                                <select name="head_id" :value="currentDept.head_id" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">-- None --</option>
                                    <?php foreach($staff_list as $st): ?>
                                        <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                                <textarea name="description" rows="2" :value="currentDept.description" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status</label>
                                <select name="status" :value="currentDept.status || 'active'" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="showForm = false" class="px-5 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium">Cancel</button>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg shadow font-medium transition-colors" x-text="editMode ? 'Save Changes' : 'Create Department'"></button>
                        </div>
                    </form>
                </div>

                <!-- Department List -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if(empty($departments)): ?>
                        <div class="col-span-full text-center py-8 text-gray-500">No departments found.</div>
                    <?php else: foreach($departments as $dept): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 overflow-hidden hover:shadow-xl transition-shadow flex flex-col">
                            <div class="p-6 flex-1">
                                <div class="flex justify-between items-start mb-4">
                                    <h3 class="text-xl font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($dept['name']); ?></h3>
                                    <?php if($dept['status'] === 'active'): ?>
                                        <span class="bg-green-100 text-green-800 px-2.5 py-1 rounded-full text-xs font-semibold">Active</span>
                                    <?php else: ?>
                                        <span class="bg-red-100 text-red-800 px-2.5 py-1 rounded-full text-xs font-semibold">Inactive</span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 line-clamp-2 min-h-[40px]">
                                    <?php echo htmlspecialchars($dept['description'] ?: 'No description provided.'); ?>
                                </p>
                                <div class="flex items-center text-sm text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-user-tie w-5 text-blue-500"></i>
                                    <span class="font-medium mr-1">Head:</span> <?php echo htmlspecialchars($dept['head_name'] ?? 'Not assigned'); ?>
                                </div>
                                <div class="flex items-center text-sm text-gray-700 dark:text-gray-300">
                                    <i class="fas fa-users w-5 text-purple-500"></i>
                                    <span class="font-medium mr-1">Staff:</span> <?php echo $dept['staff_count']; ?> members
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800/50 p-4 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                                <a href="index.php?department=<?php echo urlencode($dept['name']); ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View Members</a>
                                <div class="flex items-center gap-2 no-stack">
                                    <button @click="showForm = true; editMode = true; currentDept = { id: '<?php echo $dept['id']; ?>', name: '<?php echo addslashes($dept['name']); ?>', head_id: '<?php echo $dept['head_id']; ?>', description: '<?php echo addslashes($dept['description'] ?? ''); ?>', status: '<?php echo $dept['status']; ?>' }" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-500 hover:text-white transition-colors duration-200" title="Edit">
                                        <i class="fas fa-edit text-sm"></i>
                                    </button>
                                    <form action="" method="POST" class="inline-flex" onsubmit="return confirm('Are you sure you want to deactivate this department?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="dept_id" value="<?php echo $dept['id']; ?>">
                                        <button type="submit" class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors duration-200" title="Delete">
                                            <i class="fas fa-trash text-sm"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>

            </div>
        </main>
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
