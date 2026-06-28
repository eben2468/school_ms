<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$record_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$record_id) {
    header("Location: index.php");
    exit();
}

// Fetch record details
$query = "SELECT hr.*, u.name as student_name, sp.student_id as student_identifier, 
                 c.name as class_name, ru.name as recorded_by_name
          FROM health_records hr
          JOIN users u ON hr.student_id = u.id
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN users ru ON hr.recorded_by = ru.id
          WHERE hr.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $record_id);
$stmt->execute();
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    header("Location: index.php");
    exit();
}

$status_colors = [
    'active' => 'text-yellow-800 bg-yellow-100 border-yellow-200 dark:bg-yellow-900/20 dark:text-yellow-400 dark:border-yellow-900/30',
    'resolved' => 'text-green-800 bg-green-100 border-green-200 dark:bg-green-900/20 dark:text-green-400 dark:border-green-900/30',
    'referred' => 'text-blue-800 bg-blue-100 border-blue-200 dark:bg-blue-900/20 dark:text-blue-400 dark:border-blue-900/30'
];

$title = "View Clinic Visit Record";
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
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Clinic Visit Details</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Visit log for <?php echo htmlspecialchars($record['student_name']); ?></p>
                    </div>
                    <div class="flex space-x-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <a href="edit.php?id=<?php echo $record['id']; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-edit mr-2"></i>Edit Visit
                        </a>
                        <a href="../records/medical_history.php?student_id=<?php echo $record['student_id']; ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                            <i class="fas fa-history mr-2"></i>Full History
                        </a>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    
                    <!-- Left: Student Info & Metadata -->
                    <div class="space-y-6 col-span-1">
                        <!-- Student card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <div class="text-center">
                                <div class="w-16 h-16 bg-emerald-100 dark:bg-emerald-900/35 rounded-full flex items-center justify-center mx-auto mb-3 text-emerald-600 dark:text-emerald-400">
                                    <i class="fas fa-stethoscope text-2xl"></i>
                                </div>
                                <h3 class="text-md font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($record['student_name']); ?></h3>
                                <p class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($record['student_identifier'] ?? 'N/A'); ?></p>
                                <span class="inline-block mt-2 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                    <?php echo htmlspecialchars($record['class_name'] ?? 'Not Assigned'); ?>
                                </span>
                            </div>
                            
                            <hr class="my-5 border-gray-150 dark:border-gray-700">
                            
                            <div class="space-y-2.5 text-xs">
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Visit Date:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo date('M j, Y', strtotime($record['visit_date'])); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Visit Time:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo date('g:i A', strtotime($record['visit_time'])); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Category:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200 uppercase"><?php echo htmlspecialchars($record['record_type'] ?: 'Illness'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-400">Recorded By:</span>
                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($record['recorded_by_name'] ?? 'Medical Staff'); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Status Badge Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wider mb-2">Current Status</h4>
                            <div class="text-center py-2 px-4 border rounded-lg <?php echo $status_colors[$record['status']] ?? 'bg-gray-100 text-gray-800 border-gray-200'; ?> font-bold text-sm uppercase">
                                <?php echo htmlspecialchars($record['status']); ?>
                            </div>
                            <?php if ($record['follow_up_date']): ?>
                                <div class="mt-4 text-xs text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-calendar-alt mr-1"></i> Follow-up scheduled for:<br>
                                    <strong class="text-gray-800 dark:text-gray-200 font-semibold"><?php echo date('M j, Y', strtotime($record['follow_up_date'])); ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: Visit Details & Vitals -->
                    <div class="space-y-6 col-span-2">
                        <!-- Vitals -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <h3 class="text-md font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Vital Signs</h3>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="bg-gray-50 dark:bg-gray-700/20 p-3.5 rounded-lg text-center">
                                    <div class="text-[10px] text-gray-400 uppercase font-semibold">Temperature</div>
                                    <div class="text-base font-bold text-gray-800 dark:text-gray-200 mt-1">
                                        <?php echo $record['temperature_f'] ? $record['temperature_f'] . ' °F' : 'N/A'; ?>
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/20 p-3.5 rounded-lg text-center">
                                    <div class="text-[10px] text-gray-400 uppercase font-semibold">Blood Pressure</div>
                                    <div class="text-base font-bold text-gray-800 dark:text-gray-200 mt-1">
                                        <?php echo $record['blood_pressure'] ?: 'N/A'; ?>
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/20 p-3.5 rounded-lg text-center">
                                    <div class="text-[10px] text-gray-400 uppercase font-semibold">Pulse Rate</div>
                                    <div class="text-base font-bold text-gray-800 dark:text-gray-200 mt-1">
                                        <?php echo $record['pulse_rate'] ? $record['pulse_rate'] . ' bpm' : 'N/A'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Clinical Detail Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 space-y-6">
                            <div>
                                <h4 class="text-xs text-gray-400 uppercase font-semibold mb-1">Chief Complaint</h4>
                                <p class="text-lg font-bold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($record['complaint'] ?: 'No complaint specified'); ?>
                                </p>
                            </div>

                            <hr class="border-gray-100 dark:border-gray-700">

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Symptoms & Observations</h4>
                                <div class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg text-sm text-gray-800 dark:text-gray-200">
                                    <?php echo $record['symptoms'] ? nl2br(htmlspecialchars($record['symptoms'])) : '<span class="text-gray-400 italic">No symptoms recorded</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Treatment Administered</h4>
                                <div class="p-3 bg-emerald-50/50 dark:bg-emerald-950/10 rounded-lg text-sm text-gray-800 dark:text-gray-200 border border-emerald-50 dark:border-emerald-900/10">
                                    <?php echo $record['treatment'] ? nl2br(htmlspecialchars($record['treatment'])) : '<span class="text-gray-400 italic">No treatment recorded</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Medications Provided / Prescribed</h4>
                                <div class="p-3 bg-blue-50/50 dark:bg-blue-950/10 rounded-lg text-sm text-gray-800 dark:text-gray-200 border border-blue-50 dark:border-blue-900/10">
                                    <?php echo $record['medication'] ? nl2br(htmlspecialchars($record['medication'])) : '<span class="text-gray-400 italic">No medications recorded</span>'; ?>
                                </div>
                            </div>

                            <?php if ($record['notes']): ?>
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Additional Remarks / Notes</h4>
                                <div class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-lg text-sm text-gray-800 dark:text-gray-200">
                                    <?php echo nl2br(htmlspecialchars($record['notes'])); ?>
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
