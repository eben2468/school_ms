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

// Handle POST: Add Qualification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_qualification'])) {
    $staff_id = filter_input(INPUT_POST, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
    $type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING);
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $institution = filter_input(INPUT_POST, 'institution', FILTER_SANITIZE_STRING);
    $date_obtained = filter_input(INPUT_POST, 'date_obtained', FILTER_SANITIZE_STRING) ?: null;
    $expiry_date = filter_input(INPUT_POST, 'expiry_date', FILTER_SANITIZE_STRING) ?: null;
    $notes = $_POST['notes'] ?? '';
    
    // Determine status
    $status = 'active';
    if ($expiry_date && strtotime($expiry_date) < time()) {
        $status = 'expired';
    }
    
    try {
        $stmt = $db->prepare("
            INSERT INTO staff_qualifications (staff_id, type, title, institution, date_obtained, expiry_date, status, notes)
            VALUES (:staff_id, :type, :title, :institution, :date_obtained, :expiry_date, :status, :notes)
        ");
        $stmt->execute([
            ':staff_id' => $staff_id,
            ':type' => $type,
            ':title' => $title,
            ':institution' => $institution,
            ':date_obtained' => $date_obtained,
            ':expiry_date' => $expiry_date,
            ':status' => $status,
            ':notes' => $notes
        ]);
        $success_msg = "Qualification added successfully.";
    } catch (PDOException $e) {
        $error_msg = "Error adding qualification: " . $e->getMessage();
    }
}

// Fetch active staff for dropdown
$staff_stmt = $db->query("SELECT id, name FROM users WHERE role IN ($staff_roles_in) AND status = 'active' ORDER BY name");
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Update statuses dynamically before fetching
$db->exec("UPDATE staff_qualifications SET status = 'expired' WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE() AND status != 'expired'");

// Fetch Qualifications
$query = "
    SELECT q.*, u.name as staff_name, u.role, tp.employee_id
    FROM staff_qualifications q
    JOIN users u ON q.staff_id = u.id
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    ORDER BY q.date_obtained DESC
";
$stmt = $db->query($query);
$qualifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Expiry alerts (expiring within 90 days)
$expiring_stmt = $db->query("
    SELECT q.*, u.name as staff_name, DATEDIFF(q.expiry_date, CURDATE()) as days_left
    FROM staff_qualifications q
    JOIN users u ON q.staff_id = u.id
    WHERE q.expiry_date IS NOT NULL 
    AND q.status = 'active'
    AND q.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
    ORDER BY q.expiry_date ASC
");
$expiring_soon = $expiring_stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired
    FROM staff_qualifications
")->fetch(PDO::FETCH_ASSOC);

$title = "Qualifications & Certifications";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{ activeTab: 'browse', viewModal: false, selectedQual: null }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Qualifications & Certifications</h1>
                                <p class="text-blue-100 text-lg">Manage staff credentials and track expirations</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-certificate text-6xl text-white/80"></i>
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
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border-l-4 border-blue-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Total Records</p>
                        <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $stats['total'] ?? 0; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border-l-4 border-green-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Active Credentials</p>
                        <h3 class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['active'] ?? 0; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border-l-4 border-yellow-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Expiring Soon</p>
                        <h3 class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo count($expiring_soon); ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border-l-4 border-red-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Expired</p>
                        <h3 class="text-2xl font-bold text-red-600 dark:text-red-400"><?php echo $stats['expired'] ?? 0; ?></h3>
                    </div>
                </div>

                <!-- Expiry Alerts Section -->
                <?php if(!empty($expiring_soon)): ?>
                <div class="mb-8">
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-exclamation-triangle text-amber-500 mr-2"></i> Action Required: Expiring Soon</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach($expiring_soon as $exp): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-amber-200 dark:border-amber-700/50 flex items-start">
                            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mr-4">
                                <i class="fas fa-clock"></i>
                            </div>
                            <div>
                                <h4 class="font-bold text-gray-800 dark:text-white text-sm"><?php echo htmlspecialchars($exp['title']); ?></h4>
                                <p class="text-xs text-gray-500 dark:text-gray-400 mb-2"><?php echo htmlspecialchars($exp['staff_name']); ?></p>
                                <span class="bg-amber-100 text-amber-800 text-xs font-semibold px-2 py-0.5 rounded">
                                    Expires in <?php echo $exp['days_left']; ?> days (<?php echo date('M d, Y', strtotime($exp['expiry_date'])); ?>)
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Navigation -->
                <div class="flex space-x-1 mb-6 bg-gray-200 dark:bg-gray-800 p-1 rounded-xl w-fit">
                    <button @click="activeTab = 'browse'" :class="{'bg-white dark:bg-gray-700 shadow': activeTab === 'browse', 'text-gray-600 dark:text-gray-400': activeTab !== 'browse'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-list mr-2"></i>Browse All
                    </button>
                    <button @click="activeTab = 'add'" :class="{'bg-white dark:bg-gray-700 shadow': activeTab === 'add', 'text-gray-600 dark:text-gray-400': activeTab !== 'add'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-plus mr-2"></i>Add New
                    </button>
                </div>

                <!-- Browse Tab -->
                <div x-show="activeTab === 'browse'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm uppercase">
                                    <tr>
                                        <th class="px-6 py-4 font-semibold">Staff Member</th>
                                        <th class="px-6 py-4 font-semibold">Qualification</th>
                                        <th class="px-6 py-4 font-semibold">Institution</th>
                                        <th class="px-6 py-4 font-semibold">Dates</th>
                                        <th class="px-6 py-4 font-semibold">Status</th>
                                        <th class="px-6 py-4 font-semibold text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($qualifications)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No qualifications recorded yet.</td></tr>
                                    <?php else: foreach($qualifications as $q): 
                                        $type_badges = [
                                            'degree' => 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300',
                                            'diploma' => 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
                                            'certification' => 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
                                            'license' => 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
                                            'training' => 'bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300',
                                            'other' => 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-300'
                                        ];
                                        $type_badge = $type_badges[$q['type']] ?? $type_badges['other'];
                                    ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                                        <td class="px-6 py-4">
                                            <div class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($q['staff_name']); ?></div>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars($q['employee_id'] ?? '-'); ?></div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="font-semibold text-gray-800 dark:text-gray-200 mb-1"><?php echo htmlspecialchars($q['title']); ?></div>
                                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider <?php echo $type_badge; ?>">
                                                <?php echo htmlspecialchars($q['type']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($q['institution'] ?? '-'); ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="text-gray-600 dark:text-gray-400">Obtained: <?php echo $q['date_obtained'] ? date('M Y', strtotime($q['date_obtained'])) : '-'; ?></div>
                                            <?php if($q['expiry_date']): ?>
                                            <div class="text-gray-500 dark:text-gray-500 text-xs mt-1">Expires: <?php echo date('M d, Y', strtotime($q['expiry_date'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if($q['status'] === 'active'): ?>
                                                <span class="bg-green-100 text-green-800 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-check-circle mr-1"></i>Active</span>
                                            <?php else: ?>
                                                <span class="bg-red-100 text-red-800 px-2.5 py-1 rounded-full text-xs font-semibold"><i class="fas fa-times-circle mr-1"></i>Expired</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-right">
                                            <button @click="selectedQual = <?php echo htmlspecialchars(json_encode($q)); ?>; viewModal = true" class="text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors text-sm font-medium">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- View Modal -->
                <div x-show="viewModal" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div x-show="viewModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="viewModal = false"></div>
                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div x-show="viewModal" class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                            <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                <h3 class="text-lg leading-6 font-bold text-gray-900 dark:text-white mb-4 border-b pb-2">Qualification Details</h3>
                                <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
                                    <p><strong>Staff:</strong> <span x-text="selectedQual?.staff_name"></span></p>
                                    <p><strong>Type:</strong> <span x-text="selectedQual?.type" class="uppercase"></span></p>
                                    <p><strong>Title:</strong> <span x-text="selectedQual?.title"></span></p>
                                    <p><strong>Institution:</strong> <span x-text="selectedQual?.institution || '-'"></span></p>
                                    <p><strong>Date Obtained:</strong> <span x-text="selectedQual?.date_obtained || '-'"></span></p>
                                    <p><strong>Expiry Date:</strong> <span x-text="selectedQual?.expiry_date || 'N/A'"></span></p>
                                    <p><strong>Status:</strong> <span x-text="selectedQual?.status" class="uppercase font-bold" :class="selectedQual?.status === 'active' ? 'text-green-600' : 'text-red-600'"></span></p>
                                    <p><strong>Notes:</strong></p>
                                    <div class="bg-gray-50 dark:bg-gray-700/50 p-3 rounded-lg" x-text="selectedQual?.notes || 'No notes provided.'"></div>
                                </div>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-800/80 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-200 dark:border-gray-700">
                                <button type="button" @click="viewModal = false" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-700 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:w-auto sm:text-sm">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Add Tab -->
                <div x-show="activeTab === 'add'" style="display: none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <form action="" method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 overflow-hidden max-w-4xl">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Record New Qualification</h2>
                        </div>
                        <div class="p-6 lg:p-8">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Staff Member *</label>
                                    <select name="staff_id" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">-- Select Staff Member --</option>
                                        <?php foreach($staff_list as $st): ?>
                                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Qualification Type *</label>
                                    <select name="type" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="degree">Degree</option>
                                        <option value="diploma">Diploma</option>
                                        <option value="certification">Certification</option>
                                        <option value="license">Professional License</option>
                                        <option value="training">Training Workshop</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Title / Name *</label>
                                    <input type="text" name="title" required placeholder="e.g., Bachelor of Science in Education" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Institution / Issuing Body</label>
                                    <input type="text" name="institution" placeholder="e.g., University of Oxford" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Date Obtained</label>
                                    <input type="date" name="date_obtained" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Expiry Date (if applicable)</label>
                                    <input type="date" name="expiry_date" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Notes</label>
                                    <textarea name="notes" rows="3" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Any additional information..."></textarea>
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Document Proof (Placeholder)</label>
                                    <input type="file" class="w-full px-4 py-2 border border-dashed border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                                </div>
                            </div>
                        </div>
                        <div class="p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex justify-end space-x-4">
                            <button type="button" @click="activeTab = 'browse'" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">
                                Cancel
                            </button>
                            <button type="submit" name="add_qualification" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-medium shadow transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Record
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </main>
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
