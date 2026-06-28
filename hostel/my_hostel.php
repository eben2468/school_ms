<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('hostel_student'); // students only

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('hostel'); // blocked if the school's hostel module is disabled

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$success = '';
$error = '';

// Fetch the student's current (active) allocation with room + block details.
$alloc_stmt = $db->prepare("
    SELECT ha.id AS allocation_id, ha.allocation_date,
           hr.id AS room_id, hr.room_number, hr.floor_number, hr.room_type, hr.capacity, hr.current_occupancy,
           hb.id AS block_id, hb.name AS block_name, hb.block_type, hb.description AS block_description,
           hb.total_floors, w.name AS warden_name
    FROM hostel_allocations ha
    JOIN hostel_rooms hr ON ha.room_id = hr.id
    JOIN hostel_blocks hb ON hr.block_id = hb.id
    LEFT JOIN users w ON hb.warden_id = w.id
    WHERE ha.student_id = :uid AND ha.status = 'active'
    ORDER BY ha.allocation_date DESC
    LIMIT 1
");
$alloc_stmt->execute([':uid' => $user_id]);
$allocation = $alloc_stmt->fetch(PDO::FETCH_ASSOC);

// Handle repair-issue submission (only possible when allocated a room).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'report_issue') {
    if (!$allocation) {
        $error = "You can only report an issue once you have been allocated a room.";
    } else {
        $title = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
        $description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
        $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'medium';
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        if ($title === '' || $description === '') {
            $error = "Please provide both a title and a description for the issue.";
        } else {
            try {
                $ins = $db->prepare("
                    INSERT INTO hostel_maintenance (room_id, reported_by, title, description, priority, status, created_at)
                    VALUES (:room_id, :reported_by, :title, :description, :priority, 'pending', NOW())
                ");
                $ins->execute([
                    ':room_id' => $allocation['room_id'],
                    ':reported_by' => $user_id,
                    ':title' => $title,
                    ':description' => $description,
                    ':priority' => $priority,
                ]);
                $success = "Your repair request has been submitted to the hostel office.";
            } catch (PDOException $e) {
                $error = "Could not submit your request. Please try again later.";
                error_log('Student hostel repair report error: ' . $e->getMessage());
            }
        }
    }
}

// Roommates: other active allocations sharing the same room.
$roommates = [];
if ($allocation) {
    $rm = $db->prepare("
        SELECT u.name, sp.student_id AS reg_no, ha.allocation_date
        FROM hostel_allocations ha
        JOIN users u ON ha.student_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE ha.room_id = :room_id AND ha.status = 'active' AND ha.student_id <> :uid
        ORDER BY u.name
    ");
    $rm->execute([':room_id' => $allocation['room_id'], ':uid' => $user_id]);
    $roommates = $rm->fetchAll(PDO::FETCH_ASSOC);
}

// The student's own reported maintenance requests.
$my_requests = [];
if ($allocation) {
    $mr = $db->prepare("
        SELECT title, description, priority, status, created_at, resolved_date
        FROM hostel_maintenance
        WHERE reported_by = :uid
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $mr->execute([':uid' => $user_id]);
    $my_requests = $mr->fetchAll(PDO::FETCH_ASSOC);
}

$block_type_label = $allocation ? ucfirst($allocation['block_type']) : '';

$title = "My Hostel";
include '../includes/header.php';
include '../includes/sidebar.php';

// Small helpers for badge colours
function priorityBadge($p) {
    switch ($p) {
        case 'high':   return 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-300';
        case 'low':    return 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
        default:       return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    }
}
function statusBadge($s) {
    switch ($s) {
        case 'resolved':    return 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300';
        case 'in_progress': return 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
        case 'cancelled':   return 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400';
        default:            return 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';
    }
}
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;"
     x-data="{ reportOpen: false }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-6xl mx-auto">

                <!-- Header -->
                <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden mb-8">
                    <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
                    <div class="relative flex items-center justify-between gap-4">
                        <div>
                            <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-bed mr-1.5"></i> Hostel</p>
                            <h1 class="text-2xl sm:text-3xl font-bold mb-1">My Hostel</h1>
                            <p class="text-white/85 text-sm sm:text-base">Your accommodation details, roommates and repair requests.</p>
                        </div>
                        <div class="hidden md:flex flex-shrink-0">
                            <div class="w-24 h-24 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                                <i class="fas fa-building text-4xl text-white/85"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="mb-6 bg-emerald-50 border border-emerald-300 text-emerald-800 dark:bg-emerald-900/20 dark:text-emerald-300 px-4 py-3 rounded-xl flex items-center gap-2">
                    <i class="fas fa-check-circle"></i><span><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="mb-6 bg-rose-50 border border-rose-300 text-rose-800 dark:bg-rose-900/20 dark:text-rose-300 px-4 py-3 rounded-xl flex items-center gap-2">
                    <i class="fas fa-exclamation-circle"></i><span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!$allocation): ?>
                <!-- No allocation -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <div class="w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-bed text-2xl text-gray-400"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">No room allocated yet</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">You have not been assigned a hostel room. Please contact the hostel office if you believe this is a mistake.</p>
                </div>
                <?php else: ?>

                <!-- Accommodation overview -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                    <!-- Block card -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 bg-blue-100 dark:bg-blue-900/40 rounded-xl flex items-center justify-center"><i class="fas fa-building text-blue-600 dark:text-blue-400"></i></div>
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Your Block</p>
                                <p class="font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['block_name']); ?></p>
                            </div>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Type</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($block_type_label); ?></dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Floors</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo (int)$allocation['total_floors']; ?></dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Warden</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['warden_name'] ?: 'Not assigned'); ?></dd></div>
                        </dl>
                    </div>

                    <!-- Room card -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 bg-emerald-100 dark:bg-emerald-900/40 rounded-xl flex items-center justify-center"><i class="fas fa-door-open text-emerald-600 dark:text-emerald-400"></i></div>
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Your Room</p>
                                <p class="font-bold text-gray-900 dark:text-white">Room <?php echo htmlspecialchars($allocation['room_number']); ?></p>
                            </div>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Floor</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo (int)$allocation['floor_number']; ?></dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Room Type</dt><dd class="font-medium text-gray-900 dark:text-white capitalize"><?php echo htmlspecialchars($allocation['room_type']); ?></dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Capacity</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo (int)$allocation['capacity']; ?> beds</dd></div>
                        </dl>
                    </div>

                    <!-- Allocation card -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-11 h-11 bg-violet-100 dark:bg-violet-900/40 rounded-xl flex items-center justify-center"><i class="fas fa-calendar-check text-violet-600 dark:text-violet-400"></i></div>
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Allocation</p>
                                <p class="font-bold text-gray-900 dark:text-white">Active</p>
                            </div>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Since</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo $allocation['allocation_date'] ? date('M j, Y', strtotime($allocation['allocation_date'])) : '—'; ?></dd></div>
                            <div class="flex justify-between"><dt class="text-gray-500 dark:text-gray-400">Occupants</dt><dd class="font-medium text-gray-900 dark:text-white"><?php echo count($roommates) + 1; ?> / <?php echo (int)$allocation['capacity']; ?></dd></div>
                        </dl>
                        <button @click="reportOpen = !reportOpen" class="mt-4 w-full bg-orange-500 hover:bg-orange-600 text-white text-sm font-semibold px-4 py-2.5 rounded-xl transition flex items-center justify-center gap-2">
                            <i class="fas fa-screwdriver-wrench"></i> Report a Repair Issue
                        </button>
                    </div>
                </div>

                <!-- Report form (toggle) -->
                <div x-show="reportOpen" x-transition x-cloak class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2"><i class="fas fa-screwdriver-wrench text-orange-500"></i> Report a Repair Issue</h3>
                        <button @click="reportOpen = false" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">This request is logged against <strong><?php echo htmlspecialchars($allocation['block_name']); ?> &middot; Room <?php echo htmlspecialchars($allocation['room_number']); ?></strong> and sent to the hostel office.</p>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="report_issue">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Issue Title *</label>
                                <input type="text" name="title" required maxlength="150" placeholder="e.g. Leaking bathroom pipe, faulty fan"
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-orange-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Priority</label>
                                <select name="priority" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-orange-500 dark:bg-gray-700 dark:text-white">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description *</label>
                            <textarea name="description" rows="4" required placeholder="Describe the problem in detail..."
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-orange-500 dark:bg-gray-700 dark:text-white"></textarea>
                        </div>
                        <div class="flex justify-end gap-3">
                            <button type="button" @click="reportOpen = false" class="px-5 py-2 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                            <button type="submit" class="px-6 py-2 rounded-xl bg-orange-500 hover:bg-orange-600 text-white font-bold flex items-center gap-2"><i class="fas fa-paper-plane"></i> Submit Request</button>
                        </div>
                    </form>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Roommates -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-users text-blue-500"></i> My Roommates</h3>
                        <?php if (empty($roommates)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-user-friends text-3xl text-gray-300 dark:text-gray-600 mb-2"></i>
                                <p class="text-sm text-gray-500 dark:text-gray-400">You currently have the room to yourself.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($roommates as $mate): ?>
                                <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center font-bold text-blue-600 dark:text-blue-400">
                                        <?php echo strtoupper(substr($mate['name'], 0, 1)); ?>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($mate['name']); ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($mate['reg_no'] ?: 'Student'); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- My repair requests -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2"><i class="fas fa-clipboard-list text-orange-500"></i> My Repair Requests</h3>
                        <?php if (empty($my_requests)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-clipboard-check text-3xl text-gray-300 dark:text-gray-600 mb-2"></i>
                                <p class="text-sm text-gray-500 dark:text-gray-400">No repair requests submitted yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-3 max-h-80 overflow-y-auto">
                                <?php foreach ($my_requests as $req): ?>
                                <div class="p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                                    <div class="flex items-start justify-between gap-2 mb-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($req['title']); ?></p>
                                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap <?php echo statusBadge($req['status']); ?>"><?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?></span>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mb-2 line-clamp-2"><?php echo htmlspecialchars($req['description']); ?></p>
                                    <div class="flex items-center gap-2 text-[11px] text-gray-400">
                                        <span class="px-2 py-0.5 rounded-full <?php echo priorityBadge($req['priority']); ?>"><?php echo ucfirst($req['priority']); ?> priority</span>
                                        <span><i class="far fa-clock mr-1"></i><?php echo date('M j, Y', strtotime($req['created_at'])); ?></span>
                                        <?php if ($req['status'] === 'resolved' && $req['resolved_date']): ?>
                                        <span class="text-emerald-500"><i class="fas fa-check mr-1"></i>Resolved <?php echo date('M j', strtotime($req['resolved_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
<style>[x-cloak]{display:none !important;}</style>
