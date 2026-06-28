<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create') {
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
            $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'active';
            
            if (empty($name)) {
                $error = "Category name is required.";
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO finance_fee_categories (name, description, status) VALUES (:name, :description, :status)");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description,
                        ':status' => $status
                    ]);
                    $cat_id = $db->lastInsertId();
                    logFinanceAudit('Create Fee Category', 'Categories', $cat_id, "Created fee category: $name", $db);
                    $success = "Fee Category '$name' created successfully!";
                } catch (PDOException $e) {
                    $error = "Error creating fee category: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'update') {
            $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
            $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
            
            if (empty($name) || empty($id)) {
                $error = "Category ID and Name are required.";
            } else {
                try {
                    $stmt = $db->prepare("UPDATE finance_fee_categories SET name = :name, description = :description, status = :status WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':description' => $description,
                        ':status' => $status,
                        ':id' => $id
                    ]);
                    logFinanceAudit('Update Fee Category', 'Categories', $id, "Updated fee category: $name", $db);
                    $success = "Fee Category updated successfully!";
                } catch (PDOException $e) {
                    $error = "Error updating fee category: " . $e->getMessage();
                }
            }
        } elseif ($_POST['action'] === 'delete') {
            $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
            if (!empty($id)) {
                try {
                    // Check usage in structures
                    $stmt = $db->prepare("SELECT COUNT(*) FROM finance_fee_structures WHERE category_id = :id");
                    $stmt->execute([':id' => $id]);
                    $usage = $stmt->fetchColumn();
                    
                    if ($usage > 0) {
                        $error = "Cannot delete category. It is being used in $usage fee structures.";
                    } else {
                        $stmt = $db->prepare("DELETE FROM finance_fee_categories WHERE id = :id");
                        $stmt->execute([':id' => $id]);
                        logFinanceAudit('Delete Fee Category', 'Categories', $id, "Deleted fee category ID: $id", $db);
                        $success = "Fee Category deleted successfully.";
                    }
                } catch (PDOException $e) {
                    $error = "Error deleting category: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch categories
$query = "SELECT fc.*, COUNT(fs.id) as structure_count 
          FROM finance_fee_categories fc
          LEFT JOIN finance_fee_structures fs ON fc.id = fs.category_id
          GROUP BY fc.id 
          ORDER BY fc.name ASC";
$categories = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <style>
                .fee-card {
                    position: relative;
                    background: #ffffff;
                    border-radius: 1.25rem;
                    border: 1px solid #eef0f4;
                    box-shadow: 0 1px 2px rgba(16, 24, 40, .06);
                    overflow: hidden;
                    display: flex;
                    flex-direction: column;
                    justify-content: space-between;
                    transition: transform .4s cubic-bezier(.34, 1.56, .64, 1), box-shadow .4s ease, border-color .4s ease;
                    animation: feeIn .5s ease backwards;
                }
                .dark .fee-card { background: #1f2937; border-color: #374151; }
                .fee-card:hover {
                    transform: translateY(-7px);
                    box-shadow: 0 26px 46px -20px rgba(16, 24, 40, .45);
                    border-color: transparent;
                }
                .fee-card-accent {
                    position: absolute; top: 0; left: 0; right: 0; height: 5px;
                    background: linear-gradient(90deg, var(--c1), var(--c2));
                    transform: scaleX(0); transform-origin: left;
                    transition: transform .45s ease;
                }
                .fee-card:hover .fee-card-accent { transform: scaleX(1); }
                .fee-card-watermark {
                    position: absolute; right: -10px; top: 30px;
                    font-size: 7rem; line-height: 1;
                    color: var(--c1); opacity: .05;
                    transform: rotate(-12deg);
                    transition: transform .5s ease, opacity .5s ease;
                    pointer-events: none;
                }
                .fee-card:hover .fee-card-watermark { transform: rotate(-4deg) scale(1.08); opacity: .09; }
                .fee-icon {
                    width: 3.25rem; height: 3.25rem; border-radius: 1rem;
                    display: flex; align-items: center; justify-content: center;
                    color: #fff; font-size: 1.2rem;
                    background: linear-gradient(135deg, var(--c1), var(--c2));
                    box-shadow: 0 10px 20px -8px var(--c1);
                    transition: transform .4s cubic-bezier(.34, 1.56, .64, 1);
                }
                .fee-card:hover .fee-icon { transform: scale(1.1) rotate(-6deg); }
                .status-pill {
                    display: inline-flex; align-items: center; gap: .4rem;
                    padding: .25rem .7rem; border-radius: 9999px;
                    font-size: .7rem; font-weight: 700; letter-spacing: .02em;
                }
                .status-pill .status-dot { width: .45rem; height: .45rem; border-radius: 9999px; }
                .status-pill--active { background: #ecfdf5; color: #047857; }
                .status-pill--active .status-dot { background: #10b981; box-shadow: 0 0 0 3px rgba(16,185,129,.18); animation: pulseDot 1.8s ease-in-out infinite; }
                .status-pill--inactive { background: #f3f4f6; color: #6b7280; }
                .status-pill--inactive .status-dot { background: #9ca3af; }
                .dark .status-pill--active { background: rgba(6,95,70,.35); color: #6ee7b7; }
                .dark .status-pill--inactive { background: #374151; color: #d1d5db; }
                .fee-desc {
                    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
                    overflow: hidden; min-height: 2.6em; line-height: 1.45;
                }
                .fee-count { display: inline-flex; align-items: center; gap: .45rem; font-size: .75rem; color: #9ca3af; font-weight: 500; }
                .fee-count strong { color: var(--c1); font-weight: 800; }
                .dark .fee-count { color: #6b7280; }
                .fee-action {
                    width: 2.2rem; height: 2.2rem; border-radius: .6rem;
                    display: inline-flex; align-items: center; justify-content: center;
                    color: #9ca3af; transition: all .2s ease;
                }
                @keyframes feeIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
                @keyframes pulseDot { 0%, 100% { box-shadow: 0 0 0 0 rgba(16,185,129,.35); } 50% { box-shadow: 0 0 0 4px rgba(16,185,129,0); } }
            </style>
            <div class="w-full">
                <!-- Premium Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Fee Categories</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage school billing fee classification structures</p>
                    </div>
                    <div class="flex space-x-3">
                        <button onclick="openModal('createModal')" class="bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg hover:shadow-xl transition duration-300 flex items-center gap-2">
                            <i class="fas fa-plus"></i> Add Category
                        </button>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 rounded-xl shadow-sm mb-6 dark:bg-emerald-950/20 dark:text-emerald-300 flex items-center gap-3">
                    <i class="fas fa-check-circle text-emerald-500 text-lg"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 rounded-xl shadow-sm mb-6 dark:bg-rose-950/20 dark:text-rose-300 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-rose-500 text-lg"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

<?php
                // Pick a colour theme + icon for a category based on its name keywords.
                function fee_category_theme($name) {
                    $themes = [
                        'boarding'   => ['fa-bed',            '#6366f1', '#8b5cf6'],
                        'hostel'     => ['fa-building',       '#6366f1', '#818cf8'],
                        'exam'       => ['fa-file-pen',       '#f59e0b', '#f97316'],
                        'feed'       => ['fa-utensils',       '#f43f5e', '#fb7185'],
                        'canteen'    => ['fa-utensils',       '#f43f5e', '#fb7185'],
                        'ict'        => ['fa-laptop-code',    '#06b6d4', '#0ea5e9'],
                        'computer'   => ['fa-laptop-code',    '#06b6d4', '#0ea5e9'],
                        'librar'     => ['fa-book-open',      '#8b5cf6', '#a855f7'],
                        'transport'  => ['fa-bus',            '#3b82f6', '#2563eb'],
                        'bus'        => ['fa-bus',            '#3b82f6', '#2563eb'],
                        'tuition'    => ['fa-graduation-cap', '#10b981', '#059669'],
                        'sport'      => ['fa-futbol',         '#22c55e', '#16a34a'],
                        'lab'        => ['fa-flask',          '#14b8a6', '#0d9488'],
                        'health'     => ['fa-heart-pulse',    '#ef4444', '#f43f5e'],
                        'uniform'    => ['fa-shirt',          '#0ea5e9', '#6366f1'],
                        'admission'  => ['fa-id-card',        '#8b5cf6', '#6366f1'],
                    ];
                    $key = strtolower($name);
                    foreach ($themes as $needle => $theme) {
                        if (strpos($key, $needle) !== false) return $theme;
                    }
                    return ['fa-tag', '#10b981', '#059669']; // default emerald
                }
                ?>

                <!-- Grid List of Categories -->
                <?php if (empty($categories)): ?>
                <div class="text-center py-20 bg-white dark:bg-gray-800 rounded-2xl border border-dashed border-gray-200 dark:border-gray-700">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-500 text-2xl">
                        <i class="fas fa-tags"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white">No fee categories yet</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 mb-5">Create your first category to start classifying school fees.</p>
                    <button onclick="openModal('createModal')" class="inline-flex items-center gap-2 bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg transition">
                        <i class="fas fa-plus"></i> Add Category
                    </button>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($categories as $i => $cat):
                        [$cat_icon, $c1, $c2] = fee_category_theme($cat['name']);
                        $is_active = $cat['status'] === 'active';
                    ?>
                    <div class="fee-card group" style="--c1: <?php echo $c1; ?>; --c2: <?php echo $c2; ?>; animation-delay: <?php echo ($i * 0.06); ?>s;">
                        <span class="fee-card-accent"></span>
                        <i class="fas <?php echo $cat_icon; ?> fee-card-watermark"></i>
                        <div class="p-6 relative">
                            <div class="flex justify-between items-start mb-5">
                                <div class="fee-icon">
                                    <i class="fas <?php echo $cat_icon; ?>"></i>
                                </div>
                                <span class="status-pill <?php echo $is_active ? 'status-pill--active' : 'status-pill--inactive'; ?>">
                                    <span class="status-dot"></span>
                                    <?php echo ucfirst($cat['status']); ?>
                                </span>
                            </div>
                            <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-2 tracking-tight"><?php echo htmlspecialchars($cat['name']); ?></h3>
                            <p class="fee-desc text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($cat['description'] ?: 'No description provided.'); ?></p>
                        </div>
                        <div class="px-6 py-4 bg-gray-50/80 dark:bg-gray-900/40 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                            <span class="fee-count">
                                <i class="fas fa-link"></i>
                                <span><strong><?php echo (int)$cat['structure_count']; ?></strong> active structure(s)</span>
                            </span>
                            <div class="flex space-x-2">
                                <button title="Edit" onclick='openEditModal(<?php echo json_encode($cat); ?>)' class="fee-action hover:text-blue-600 dark:hover:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/30">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button title="Delete" onclick="confirmDelete(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')" class="fee-action hover:text-rose-600 dark:hover:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30">
                                    <i class="fas fa-trash-can"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div id="createModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="create">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Create Fee Category</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category Name</label>
                            <input type="text" name="name" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                            <textarea name="description" rows="3" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/80 border-t border-gray-100 dark:border-gray-700 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('createModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl shadow-lg transition">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="editId">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Edit Fee Category</h3>
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category Name</label>
                            <input type="text" name="name" id="editName" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                            <textarea name="description" id="editDescription" rows="3" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition"></textarea>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" id="editStatus" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 dark:bg-gray-800/80 border-t border-gray-100 dark:border-gray-700 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl shadow-lg transition">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" action="" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function openEditModal(cat) {
    document.getElementById('editId').value = cat.id;
    document.getElementById('editName').value = cat.name;
    document.getElementById('editDescription').value = cat.description;
    document.getElementById('editStatus').value = cat.status;
    openModal('editModal');
}
function confirmDelete(id, name) {
    if (confirm("Are you sure you want to delete the category '" + name + "'?")) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

