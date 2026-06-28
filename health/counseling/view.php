<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'counselor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$session_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$session_id) {
    header("Location: index.php");
    exit();
}

// Fetch session details
$query = "SELECT cs.*, u.name as student_name, sp.student_id as student_identifier, 
                 c.name as class_name, cu.name as counselor_name
          FROM counseling_sessions cs
          JOIN users u ON cs.student_id = u.id
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN users cu ON cs.counselor_id = cu.id
          WHERE cs.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $session_id);
$stmt->execute();
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    header("Location: index.php");
    exit();
}

$status_colors = [
    'scheduled' => 'text-yellow-800 bg-yellow-100 border-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400 dark:border-yellow-900/30',
    'completed' => 'text-green-800 bg-green-100 border-green-200 dark:bg-green-900/20 dark:text-green-400 dark:border-green-900/30',
    'cancelled' => 'text-red-800 bg-red-100 border-red-200 dark:bg-red-900/20 dark:text-red-400 dark:border-red-900/30',
    'no_show' => 'text-gray-800 bg-gray-100 border-gray-250 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-650'
];

$title = "View Counseling Session";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                
                <!-- Page Header -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Counseling Session Details</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Session for <?php echo htmlspecialchars($session['student_name']); ?></p>
                    </div>
                    <div class="flex flex-row items-center gap-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <a href="edit.php?id=<?php echo $session['id']; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-edit mr-2"></i>Edit Session
                        </a>
                        <a href="../records/medical_history.php?student_id=<?php echo $session['student_id']; ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-history mr-2"></i>Full History
                        </a>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    
                    <!-- Left: Metadata & Status -->
                    <div class="space-y-6 col-span-1">
                        <!-- Student card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900/35 rounded-full flex items-center justify-center mx-auto mb-3 text-purple-600 dark:text-purple-400">
                                    <i class="fas fa-comments text-2xl"></i>
                                </div>
                                <h3 class="text-md font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($session['student_name']); ?></h3>
                                <p class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($session['student_identifier'] ?? 'N/A'); ?></p>
                                <span class="inline-block mt-2 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    <?php echo htmlspecialchars($session['class_name'] ?? 'Not Assigned'); ?>
                                </span>
                            </div>
                            
                            <hr class="my-5 border-gray-150 dark:border-gray-700">
                            
                            <div class="space-y-2.5 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Session Date:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo date('M j, Y', strtotime($session['session_date'])); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Session Time:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo $session['session_time'] ? date('g:i A', strtotime($session['session_time'])) : 'N/A'; ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Duration:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($session['duration'] ?? $session['duration_minutes'] ?? '60'); ?> mins</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Session Type:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 uppercase"><?php echo htmlspecialchars($session['session_type']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Counselor:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($session['counselor_name'] ?? 'Counselor'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Status Badge Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Session Status</h4>
                            <div class="text-center py-2 px-4 border rounded-lg <?php echo $status_colors[$session['status']] ?? 'bg-gray-100 text-gray-800 border-gray-250'; ?> font-bold text-sm uppercase">
                                <?php echo htmlspecialchars(str_replace('_', ' ', $session['status'])); ?>
                            </div>
                            <?php if ($session['follow_up_date']): ?>
                                <div class="mt-4 text-xs text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-calendar-alt mr-1"></i> Follow-up date:<br>
                                    <strong class="text-gray-800 dark:text-gray-200 font-semibold"><?php echo date('M j, Y', strtotime($session['follow_up_date'])); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: Counseling Details -->
                    <div class="space-y-6 col-span-2">
                        <!-- Vitals -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 space-y-6">
                            <div>
                                <h4 class="text-xs text-gray-400 uppercase font-semibold mb-1">Reason for Session</h4>
                                <p class="text-lg font-bold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($session['reason'] ?: 'No reason specified'); ?>
                                </p>
                            </div>

                            <hr class="border-gray-100 dark:border-gray-700">

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Student Concerns</h4>
                                <div class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg text-sm text-gray-800 dark:text-gray-200">
                                    <?php echo $session['concerns'] ? nl2br(htmlspecialchars($session['concerns'])) : '<span class="text-gray-400 italic">No concerns recorded</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Counselor Observations</h4>
                                <div class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg text-sm text-gray-800 dark:text-gray-200">
                                    <?php echo $session['observations'] ? nl2br(htmlspecialchars($session['observations'])) : '<span class="text-gray-400 italic">No observations recorded</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Recommendations</h4>
                                <div class="p-3 bg-purple-50/50 dark:bg-purple-950/10 rounded-lg text-sm text-gray-800 dark:text-gray-200 border border-purple-50 dark:border-purple-900/10">
                                    <?php echo $session['recommendations'] ? nl2br(htmlspecialchars($session['recommendations'])) : '<span class="text-gray-400 italic">No recommendations recorded</span>'; ?>
                                </div>
                            </div>

                            <?php if ($_SESSION['role'] === 'counselor' || $_SESSION['role'] === 'super_admin' || $_SESSION['role'] === 'school_admin'): ?>
                                <div>
                                    <h4 class="text-sm font-semibold text-red-700 dark:text-red-400 mb-2"><i class="fas fa-lock mr-2"></i>Confidential Notes</h4>
                                    <div class="p-4 bg-red-50/30 dark:bg-red-950/10 rounded-lg text-sm text-gray-800 dark:text-gray-200 border border-red-100 dark:border-red-900/20">
                                        <?php echo $session['confidential_notes'] ? nl2br(htmlspecialchars($session['confidential_notes'])) : '<span class="text-gray-400 italic">No confidential notes recorded</span>'; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
