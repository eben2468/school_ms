<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor', 'counselor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);

if (!$student_id) {
    header("Location: index.php");
    exit();
}

// Fetch student details
$student_query = "SELECT u.name as student_name, sp.*, c.name as class_name
                  FROM users u
                  LEFT JOIN student_profiles sp ON u.id = sp.user_id
                  LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                  LEFT JOIN classes c ON sc.class_id = c.id
                  WHERE u.id = :student_id AND u.role = 'student'";
$stmt = $db->prepare($student_query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    echo "Student not found or invalid role.";
    exit();
}

// Fetch all health/medical records
$records_query = "SELECT hr.*, ru.name as recorded_by_name
                  FROM health_records hr
                  LEFT JOIN users ru ON hr.recorded_by = ru.id
                  WHERE hr.student_id = :student_id
                  ORDER BY hr.visit_date DESC, hr.record_date DESC";
$stmt = $db->prepare($records_query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$health_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all counseling sessions
$counseling_query = "SELECT cs.*, cu.name as counselor_name
                     FROM counseling_sessions cs
                     LEFT JOIN users cu ON cs.counselor_id = cu.id
                     WHERE cs.student_id = :student_id
                     ORDER BY cs.session_date DESC";
$stmt = $db->prepare($counseling_query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$counseling_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge and sort all items for timeline
$timeline_items = [];

foreach ($health_records as $hr) {
    $date = $hr['visit_date'] ?: $hr['record_date'];
    $is_visit = !empty($hr['complaint']) || !empty($hr['symptoms']) || !empty($hr['treatment']);
    
    $timeline_items[] = [
        'date' => $date,
        'time' => $hr['visit_time'] ?? '00:00:00',
        'type' => $is_visit ? 'visit' : 'assessment',
        'title' => $is_visit ? 'Clinic Visit: ' . ($hr['complaint'] ?: 'General') : 'Health Profile Assessment',
        'badge' => $is_visit ? 'Clinic Visit' : 'Assessment',
        'badge_color' => $is_visit ? 'bg-emerald-100 text-emerald-800' : 'bg-blue-100 text-blue-800',
        'icon' => $is_visit ? 'fa-stethoscope' : 'fa-file-medical',
        'icon_color' => $is_visit ? 'text-emerald-600 bg-emerald-100' : 'text-blue-600 bg-blue-100',
        'data' => $hr,
        'id' => $hr['id']
    ];
}

foreach ($counseling_records as $cr) {
    // Hide counseling records from nurses/doctors if counselor restricted, but in school systems general timeline can show session presence
    $can_see_counseling_details = in_array($_SESSION['role'], ['super_admin', 'school_admin', 'counselor']);
    
    $timeline_items[] = [
        'date' => $cr['session_date'],
        'time' => $cr['session_time'] ?? '00:00:00',
        'type' => 'counseling',
        'title' => 'Counseling Session: ' . ucfirst($cr['session_type']),
        'badge' => 'Counseling',
        'badge_color' => 'bg-purple-100 text-purple-800',
        'icon' => 'fa-comments',
        'icon_color' => 'text-purple-600 bg-purple-100',
        'data' => $cr,
        'id' => $cr['id'],
        'restricted' => !$can_see_counseling_details
    ];
}

// Sort timeline by date DESC, then time DESC
usort($timeline_items, function($a, $b) {
    $date_cmp = strcmp($b['date'], $a['date']);
    if ($date_cmp !== 0) return $date_cmp;
    return strcmp($b['time'], $a['time']);
});

$title = "Student Medical History";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-5xl mx-auto">
                
                <!-- Page Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Student Medical History</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Timeline of clinical records, assessments, and counseling sessions</p>
                    </div>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Records
                    </a>
                </div>

                <!-- Student Summary Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900/35 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                                <i class="fas fa-user-graduate text-2xl"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['student_name']); ?></h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Student ID: <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?></p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Class: <?php echo htmlspecialchars($student['class_name'] ?? 'Not Assigned'); ?></p>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
                            <div class="bg-gray-50 dark:bg-gray-700/30 px-4 py-2 rounded-lg">
                                <span class="text-xs text-gray-500 dark:text-gray-400 block font-medium uppercase">Blood Group</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['blood_group'] ?? 'Unknown'); ?></span>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/30 px-4 py-2 rounded-lg col-span-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400 block font-medium uppercase">Gender</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars(ucfirst($student['gender'] ?? 'Not specified')); ?></span>
                            </div>
                            <div class="bg-gray-50 dark:bg-gray-700/30 px-4 py-2 rounded-lg col-span-2 md:col-span-1">
                                <span class="text-xs text-gray-500 dark:text-gray-400 block font-medium uppercase">Emergency Contact</span>
                                <span class="font-semibold text-gray-900 dark:text-white block truncate"><?php echo htmlspecialchars($student['emergency_contact_name'] ?? 'N/A'); ?></span>
                                <span class="text-xs text-gray-500"><?php echo htmlspecialchars($student['emergency_contact_phone'] ?? ''); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($student['medical_conditions']): ?>
                    <div class="mt-4 p-3 bg-red-50 dark:bg-red-950/20 border border-red-100 dark:border-red-900/35 rounded-lg text-sm text-red-800 dark:text-red-300">
                        <strong class="font-semibold">Chronic Conditions:</strong> <?php echo htmlspecialchars($student['medical_conditions']); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- History Timeline -->
                <div class="relative pl-8 border-l-2 border-blue-200 dark:border-blue-900/50 space-y-8">
                    <?php if (!empty($timeline_items)): ?>
                        <?php foreach ($timeline_items as $item): ?>
                            <div class="relative">
                                <!-- Timeline Icon Pin -->
                                <span class="absolute -left-12 top-1.5 w-8 h-8 rounded-full flex items-center justify-center shadow-md <?php echo $item['icon_color']; ?>">
                                    <i class="fas <?php echo $item['icon']; ?> text-sm"></i>
                                </span>

                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-5">
                                    <!-- Header Info -->
                                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
                                        <div>
                                            <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-semibold mr-2 <?php echo $item['badge_color']; ?>">
                                                <?php echo $item['badge']; ?>
                                            </span>
                                            <span class="text-sm text-gray-500 dark:text-gray-400 font-medium"><?php echo date('F j, Y', strtotime($item['date'])); ?> at <?php echo date('g:i A', strtotime($item['time'])); ?></span>
                                        </div>
                                        <div>
                                            <?php if ($item['type'] === 'visit'): ?>
                                                <a href="../medical_records/view.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:underline text-sm font-semibold">View Visit Form</a>
                                            <?php elseif ($item['type'] === 'assessment'): ?>
                                                <a href="view.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:underline text-sm font-semibold">View Assessment</a>
                                            <?php elseif ($item['type'] === 'counseling' && !$item['restricted']): ?>
                                                <a href="../counseling/view.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:underline text-sm font-semibold">View Counseling details</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <h3 class="text-md font-bold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($item['title']); ?></h3>

                                    <!-- Body content depending on type -->
                                    <div class="text-sm text-gray-600 dark:text-gray-300">
                                        <?php if ($item['type'] === 'visit'): ?>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-2">
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Symptoms</span>
                                                    <p class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($item['data']['symptoms'] ?: 'Not recorded'); ?></p>
                                                </div>
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Treatment & Medication</span>
                                                    <p class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($item['data']['treatment'] ?: 'No treatment logged'); ?></p>
                                                    <?php if ($item['data']['medication']): ?>
                                                        <span class="inline-block mt-1 text-xs px-2 py-0.5 bg-blue-50 text-blue-600 rounded">Med: <?php echo htmlspecialchars($item['data']['medication']); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                        <?php elseif ($item['type'] === 'assessment'): ?>
                                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-2">
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Vitals</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200">BP: <?php echo htmlspecialchars($item['data']['blood_pressure'] ?: 'N/A'); ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Temp</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo $item['data']['temperature_f'] ? $item['data']['temperature_f'] . ' °F' : 'N/A'; ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Height / Weight</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo $item['data']['height_cm'] ? $item['data']['height_cm'] . 'cm' : 'N/A'; ?> / <?php echo $item['data']['weight_kg'] ? $item['data']['weight_kg'] . 'kg' : 'N/A'; ?></span>
                                                </div>
                                                <div>
                                                    <span class="text-xs text-gray-400 uppercase font-medium block">Allergies</span>
                                                    <span class="font-medium text-gray-800 dark:text-gray-200 truncate block"><?php echo htmlspecialchars($item['data']['allergies'] ?: 'None'); ?></span>
                                                </div>
                                            </div>

                                        <?php elseif ($item['type'] === 'counseling'): ?>
                                            <?php if ($item['restricted']): ?>
                                                <p class="italic text-gray-400"><i class="fas fa-lock mr-2"></i>Details of counseling sessions are confidential and restricted to counselor and administrators.</p>
                                            <?php else: ?>
                                                <div class="space-y-2">
                                                    <div>
                                                        <span class="text-xs text-gray-400 uppercase font-medium block">Reason for session</span>
                                                        <p class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($item['data']['reason'] ?: 'Not specified'); ?></p>
                                                    </div>
                                                    <?php if ($item['data']['recommendations']): ?>
                                                    <div>
                                                        <span class="text-xs text-gray-400 uppercase font-medium block">Recommendations</span>
                                                        <p class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($item['data']['recommendations']); ?></p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Recorder signature -->
                                    <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-700/50 flex justify-between text-xs text-gray-400 dark:text-gray-500">
                                        <span>Logged by: <?php echo htmlspecialchars(($item['type'] === 'counseling') ? ($item['data']['counselor_name'] ?? 'Counselor') : ($item['data']['recorded_by_name'] ?? 'Medical Staff')); ?></span>
                                        <?php if ($item['type'] === 'visit'): ?>
                                            <span class="font-semibold uppercase tracking-wider <?php echo $item['data']['status'] === 'resolved' ? 'text-green-500' : ($item['data']['status'] === 'referred' ? 'text-blue-500' : 'text-yellow-500'); ?>">
                                                <?php echo $item['data']['status']; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-8 text-center border border-gray-100 dark:border-gray-700">
                            <i class="fas fa-history text-gray-300 text-5xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Medical History Available</h3>
                            <p class="text-gray-500 dark:text-gray-400">There are no visits, assessments, or counseling sessions logged for this student.</p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
