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

// Calculate BMI
$bmi = null;
$bmi_class = '';
$bmi_color = '';
if ($record['height_cm'] && $record['weight_kg']) {
    $height_m = $record['height_cm'] / 100;
    $bmi = $record['weight_kg'] / ($height_m * $height_m);
    
    if ($bmi < 18.5) {
        $bmi_class = 'Underweight';
        $bmi_color = 'text-yellow-600 bg-yellow-50 dark:bg-yellow-900/20';
    } elseif ($bmi < 25) {
        $bmi_class = 'Normal';
        $bmi_color = 'text-green-600 bg-green-50 dark:bg-green-900/20';
    } elseif ($bmi < 30) {
        $bmi_class = 'Overweight';
        $bmi_color = 'text-orange-600 bg-orange-50 dark:bg-orange-900/20';
    } else {
        $bmi_class = 'Obese';
        $bmi_color = 'text-red-600 bg-red-50 dark:bg-red-900/20';
    }
}

$title = "View Health Record";
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Health Record Details</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Assessment details for <?php echo htmlspecialchars($record['student_name']); ?></p>
                    </div>
                    <div class="flex flex-row items-center gap-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Records
                        </a>
                        <a href="edit.php?id=<?php echo $record['id']; ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-edit mr-2"></i>Edit
                        </a>
                        <a href="medical_history.php?student_id=<?php echo $record['student_id']; ?>" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-history mr-2"></i>Full History
                        </a>
                    </div>
                </div>

                <!-- Main Layout -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    
                    <!-- Left Column: Student Quick Info & Vitals -->
                    <div class="md:col-span-1 space-y-6">
                        <!-- Student Profile Card -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <div class="text-center">
                                <div class="w-20 h-20 bg-blue-100 dark:bg-blue-900/35 rounded-full flex items-center justify-center mx-auto mb-4 text-blue-600 dark:text-blue-400">
                                    <i class="fas fa-user-graduate text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($record['student_name']); ?></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($record['student_identifier'] ?? 'N/A'); ?></p>
                                <span class="inline-block mt-2 px-3 py-1 rounded-full text-xs font-semibold bg-blue-50 dark:bg-blue-900/25 text-blue-600 dark:text-blue-400">
                                    <?php echo htmlspecialchars($record['class_name'] ?? 'Not Assigned'); ?>
                                </span>
                            </div>
                            
                            <hr class="my-6 border-gray-200 dark:border-gray-700">
                            
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Assessment Date:</span>
                                    <span class="font-medium text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($record['record_date'])); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Recorded By:</span>
                                    <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($record['recorded_by_name'] ?? 'Unknown'); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500 dark:text-gray-400">Created At:</span>
                                    <span class="font-medium text-gray-900 dark:text-white"><?php echo date('M j, Y g:i A', strtotime($record['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- BMI Display -->
                        <?php if ($bmi): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <h3 class="text-md font-semibold text-gray-800 dark:text-white mb-4">Body Mass Index (BMI)</h3>
                            <div class="text-center">
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo number_format($bmi, 1); ?></p>
                                <span class="inline-block mt-2 px-3 py-1 rounded-full text-sm font-semibold <?php echo $bmi_color; ?>">
                                    <?php echo $bmi_class; ?>
                                </span>
                            </div>
                            <div class="mt-4 text-xs text-gray-500 dark:text-gray-400 text-center">
                                Calculated from weight (<?php echo $record['weight_kg']; ?> kg) & height (<?php echo $record['height_cm']; ?> cm).
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right Column: Vital Signs & Clinical Info -->
                    <div class="md:col-span-2 space-y-6">
                        <!-- Vital Signs Grid -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Vital Signs</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-lg">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-medium">Height</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?php echo $record['height_cm'] ? $record['height_cm'] . ' cm' : 'N/A'; ?></div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-lg">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-medium">Weight</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?php echo $record['weight_kg'] ? $record['weight_kg'] . ' kg' : 'N/A'; ?></div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-lg">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-medium">Blood Pressure</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?php echo $record['blood_pressure'] ?: 'N/A'; ?></div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-lg">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-medium">Temperature</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?php echo $record['temperature_f'] ? $record['temperature_f'] . ' °F' : 'N/A'; ?></div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-700/30 p-4 rounded-lg col-span-2">
                                    <div class="text-xs text-gray-500 dark:text-gray-400 uppercase font-medium">Pulse Rate</div>
                                    <div class="text-lg font-bold text-gray-900 dark:text-white mt-1"><?php echo $record['pulse_rate'] ? $record['pulse_rate'] . ' bpm' : 'N/A'; ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- Medical Information Details -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 space-y-6">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-file-medical text-blue-500 mr-2"></i>Medical Summary</h3>
                            
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Medical Conditions</h4>
                                <div class="p-3 bg-red-50 dark:bg-red-950/20 rounded-lg border border-red-100 dark:border-red-900/30 text-gray-700 dark:text-gray-300 text-sm">
                                    <?php echo $record['medical_conditions'] ? nl2br(htmlspecialchars($record['medical_conditions'])) : '<span class="text-gray-400">None reported</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Allergies</h4>
                                <div class="p-3 bg-yellow-50 dark:bg-yellow-950/20 rounded-lg border border-yellow-100 dark:border-yellow-900/30 text-gray-700 dark:text-gray-300 text-sm">
                                    <?php echo $record['allergies'] ? nl2br(htmlspecialchars($record['allergies'])) : '<span class="text-gray-400">None reported</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Current Medications</h4>
                                <div class="p-3 bg-blue-50 dark:bg-blue-950/20 rounded-lg border border-blue-100 dark:border-blue-900/30 text-gray-700 dark:text-gray-300 text-sm">
                                    <?php echo $record['medications'] ? nl2br(htmlspecialchars($record['medications'])) : '<span class="text-gray-400">None reported</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vaccination Status</h4>
                                <div class="p-3 bg-green-50 dark:bg-green-950/20 rounded-lg border border-green-100 dark:border-green-900/30 text-gray-700 dark:text-gray-300 text-sm">
                                    <?php echo $record['vaccination_status'] ? nl2br(htmlspecialchars($record['vaccination_status'])) : '<span class="text-gray-400">No vaccination record submitted</span>'; ?>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Additional Notes</h4>
                                <div class="p-4 bg-gray-50 dark:bg-gray-700/25 rounded-lg text-gray-700 dark:text-gray-300 text-sm">
                                    <?php echo $record['notes'] ? nl2br(htmlspecialchars($record['notes'])) : '<span class="text-gray-400">No additional notes</span>'; ?>
                                </div>
                            </div>
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
