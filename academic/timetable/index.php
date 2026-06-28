<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
require_once '../../includes/signature_helper.php';
$database = new Database();
$db = $database->getConnection();

// Headmaster signature for the printable timetable (embedded when enabled).
$tt_headmaster_sig = signatureImg(getSchoolSignature('headmaster')['url'], 38);

$school_settings = getSchoolSettings();
$school_name = $school_settings['school_name'] ?? 'Greenwood Academy';
$school_logo = getSchoolLogo();
$academic_year = getCurrentAcademicYear();

// Handle time slot updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_time_slots') {
    try {
        $start_time = $_POST['start_time'];
        $period_duration = (int)$_POST['period_duration'];
        $break_duration = (int)$_POST['break_duration'];

        // Store time slot settings in session for this page
        $_SESSION['timetable_settings'] = [
            'start_time' => $start_time,
            'period_duration' => $period_duration,
            'break_duration' => $break_duration
        ];

        $success = "Time slots updated successfully";
    } catch (Exception $e) {
        $error = "Error updating time slots: " . $e->getMessage();
    }
}

// Handle schedule updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] !== 'update_time_slots')) {
    try {
        $db->beginTransaction();

        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
        $schedules = $_POST['schedules'] ?? [];

        // Clear existing schedules for the class
        $query = "DELETE FROM class_schedule WHERE class_id = :class_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        // Insert new schedules
        if (!empty($schedules)) {
            $query = "INSERT INTO class_schedule (class_id, day, time_slot, subject_id, teacher_id, is_break, break_name)
                     VALUES (:class_id, :day, :time_slot, :subject_id, :teacher_id, :is_break, :break_name)";
            $stmt = $db->prepare($query);

            foreach ($schedules as $schedule) {
                $is_break = isset($schedule['is_break']) && $schedule['is_break'] == 1 ? 1 : 0;
                $break_name = $is_break ? ($schedule['break_name'] ?? 'Break') : null;

                if (!$is_break && empty($schedule['subject_teacher'])) continue;

                $subject_id = null;
                $teacher_id = null;

                if (!$is_break) {
                    // Split the subject_teacher value to get subject_id and teacher_id
                    list($subject_id, $teacher_id) = explode('_', $schedule['subject_teacher']);
                }

                $stmt->bindParam(':class_id', $class_id);
                $stmt->bindParam(':day', $schedule['day']);
                $stmt->bindParam(':time_slot', $schedule['time_slot']);
                $stmt->bindParam(':subject_id', $subject_id, $subject_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindParam(':teacher_id', $teacher_id, $teacher_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $stmt->bindParam(':is_break', $is_break, PDO::PARAM_INT);
                $stmt->bindParam(':break_name', $break_name, $break_name === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->execute();
            }
        }

        $db->commit();
        $success = "Schedule updated successfully";
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error updating schedule: " . $e->getMessage();
    }
}

// Fetch active classes
$query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$stmt = $db->query($query);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get selected class schedule
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
if ($selected_class_id) {
    $query = "SELECT cs.*, s.name as subject_name, u.name as teacher_name 
              FROM class_schedule cs
              LEFT JOIN subjects s ON cs.subject_id = s.id
              LEFT JOIN users u ON cs.teacher_id = u.id
              WHERE cs.class_id = :class_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $selected_class_id);
    $stmt->execute();
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get class teachers and subjects - if none assigned, get all subjects with all teachers
    $query = "SELECT ct.*, s.name as subject_name, u.name as teacher_name
              FROM class_teachers ct
              JOIN subjects s ON ct.subject_id = s.id
              JOIN users u ON ct.teacher_id = u.id
              WHERE ct.class_id = :class_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $selected_class_id);
    $stmt->execute();
    $class_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // If no class teachers assigned, get all subjects with all teachers
    if (empty($class_teachers)) {
        $query = "SELECT s.id as subject_id, u.id as teacher_id, s.name as subject_name, u.name as teacher_name
                  FROM subjects s
                  CROSS JOIN users u
                  WHERE u.role = 'teacher'
                  ORDER BY s.name, u.name";
        $stmt = $db->query($query);
        $class_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="mb-6 timetable-header">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white mb-3">Timetable Management</h1>
                <div class="flex space-x-3 no-stack">
                    <?php if ($selected_class_id): ?>
                    <!-- Action Buttons -->
                    <div class="flex space-x-2 no-stack flex-wrap gap-2">
                        <button onclick="copySchedule()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg text-sm" title="Copy schedule from another class">
                            <i class="fas fa-copy mr-2"></i>Copy Schedule
                        </button>
                        <button onclick="adjustTimeSlots()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm" title="Adjust time slots">
                            <i class="fas fa-clock mr-2"></i>Adjust Times
                        </button>
                        <button onclick="clearSchedule()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm" title="Clear all schedule">
                            <i class="fas fa-trash mr-2"></i>Clear All
                        </button>
                        <button onclick="printSchedule()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg text-sm" title="Print schedule">
                            <i class="fas fa-print mr-2"></i>Print
                        </button>
                    </div>
                    <?php endif; ?>
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Academic Management
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Class Selection -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <label for="class_id" class="block text-sm font-medium text-gray-700">Select Class</label>
                            <select id="class_id" name="class_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="">Select a class...</option>
                                <?php foreach ($classes as $class): ?>
                                    <?php $selected = $selected_class_id == $class['id'] ? 'selected' : ''; ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $selected; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - 
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id && !empty($class_teachers)): ?>
            <!-- Schedule Editor -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6">
                    <form action="" method="POST">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Monday</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tuesday</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Wednesday</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thursday</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Friday</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200" id="timetable-body">
                                    <?php
                                    // Generate time slots based on settings
                                    $settings = $_SESSION['timetable_settings'] ?? [
                                        'start_time' => '08:00',
                                        'period_duration' => 60,
                                        'break_duration' => 15
                                    ];

                                    function generateTimeSlots($start_time, $period_duration, $break_duration, $num_periods = 7) {
                                        $slots = [];
                                        $current_time = new DateTime($start_time);

                                        for ($i = 0; $i < $num_periods; $i++) {
                                            $start = $current_time->format('H:i');
                                            $current_time->add(new DateInterval('PT' . $period_duration . 'M'));
                                            $end = $current_time->format('H:i');

                                            $slots[] = $start . '-' . $end;

                                            // Add break time (except after last period)
                                            if ($i < $num_periods - 1) {
                                                $current_time->add(new DateInterval('PT' . $break_duration . 'M'));
                                            }
                                        }

                                        return $slots;
                                    }

                                    $time_slots = generateTimeSlots($settings['start_time'], $settings['period_duration'], $settings['break_duration']);
                                    foreach ($time_slots as $index => $time_slot):
                                        $is_break_row = false;
                                        $row_break_name = '';
                                        if (!empty($schedules)) {
                                            foreach ($schedules as $s) {
                                                if ($s['time_slot'] === $time_slot && $s['is_break'] == 1) {
                                                    $is_break_row = true;
                                                    $row_break_name = $s['break_name'] ?? 'Break';
                                                    break;
                                                }
                                            }
                                        }
                                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                    ?>
                                    <tr id="row_<?php echo $index; ?>" class="timetable-row <?php echo $is_break_row ? 'is-break-active bg-purple-50 dark:bg-purple-950/20' : ''; ?>" data-time-slot="<?php echo htmlspecialchars($time_slot); ?>">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white flex flex-col justify-center items-start gap-1">
                                            <span class="font-bold time-slot-label"><?php echo $time_slot; ?></span>
                                            <button type="button" onclick="toggleBreakRow('row_<?php echo $index; ?>')" 
                                                    class="text-xs px-2 py-1 rounded transition-colors flex items-center gap-1 <?php echo $is_break_row ? 'bg-purple-600 hover:bg-purple-700 text-white' : 'bg-purple-100 hover:bg-purple-200 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 dark:hover:bg-purple-900/60'; ?>">
                                                <i class="fas <?php echo $is_break_row ? 'fa-school' : 'fa-coffee'; ?>"></i>
                                                <span class="btn-text"><?php echo $is_break_row ? 'Set Class' : 'Set Break'; ?></span>
                                            </button>
                                        </td>
                                        
                                        <!-- Break merged column (spanning 5 days) -->
                                        <td colspan="5" class="px-6 py-4 break-cell-container <?php echo !$is_break_row ? 'hidden' : ''; ?>">
                                            <div class="flex items-center gap-3">
                                                 <span class="text-sm font-semibold text-purple-700 dark:text-purple-300 flex items-center gap-1">
                                                     <i class="fas fa-mug-hot"></i> Break Name:
                                                 </span>
                                                <input type="text" 
                                                       value="<?php echo htmlspecialchars($row_break_name); ?>" 
                                                       placeholder="e.g. Lunch Break, Recess, Assembly" 
                                                       oninput="updateBreakName('row_<?php echo $index; ?>', this.value)"
                                                       class="break-name-input flex-grow px-3 py-1.5 border border-purple-300 dark:border-purple-800 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500 text-sm dark:bg-gray-800 dark:text-white">
                                            </div>
                                            <!-- Hidden day elements for breaks -->
                                            <?php foreach ($days as $day): ?>
                                                <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][day]" value="<?php echo $day; ?>">
                                                <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][time_slot]" value="<?php echo $time_slot; ?>">
                                                <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][is_break]" value="<?php echo $is_break_row ? '1' : '0'; ?>" class="is-break-flag">
                                                <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][break_name]" value="<?php echo htmlspecialchars($row_break_name); ?>" class="break-name-flag">
                                            <?php endforeach; ?>
                                        </td>

                                        <!-- Standard Day Columns -->
                                        <?php
                                        foreach ($days as $day):
                                            $current_schedule = array_filter($schedules ?? [], function($s) use ($day, $time_slot) {
                                                return $s['day'] === $day && $s['time_slot'] === $time_slot;
                                            });
                                            $current_schedule = reset($current_schedule);
                                        ?>
                                        <td class="px-6 py-4 whitespace-nowrap day-cell-container <?php echo $is_break_row ? 'hidden' : ''; ?>">
                                            <select name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][subject_teacher]"
                                                class="subject-teacher-select block w-full px-3 py-2 border border-gray-300 dark:border-gray-700 dark:bg-gray-800 dark:text-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm">
                                                <option value="">No Class</option>
                                                <?php foreach ($class_teachers as $teacher): ?>
                                                    <?php
                                                    $value = $teacher['subject_id'] . '_' . $teacher['teacher_id'];
                                                    $selected = !$is_break_row && $current_schedule && 
                                                               $current_schedule['subject_id'] == $teacher['subject_id'] && 
                                                               $current_schedule['teacher_id'] == $teacher['teacher_id'] 
                                                               ? 'selected' : '';
                                                    ?>
                                                    <option value="<?php echo $value; ?>" <?php echo $selected; ?>>
                                                        <?php echo htmlspecialchars($teacher['subject_name']); ?> - 
                                                        <?php echo htmlspecialchars($teacher['teacher_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][day]" value="<?php echo $day; ?>" class="day-val">
                                            <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][time_slot]" value="<?php echo $time_slot; ?>" class="time-slot-val">
                                        </td>
                                        <?php endforeach; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-6 flex flex-col sm:flex-row gap-4">
                            <button type="submit"
                                class="flex-1 flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-save mr-2"></i>Save Schedule
                            </button>
                            <button type="button" onclick="autoFillSchedule()"
                                class="flex-1 flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-magic mr-2"></i>Auto Fill
                            </button>
                            <button type="button" onclick="validateSchedule()"
                                class="flex-1 flex justify-center py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-check-circle mr-2"></i>Validate
                            </button>
                        </div>

                        <!-- Row Management -->
                        <div class="mt-4 flex justify-center gap-4">
                            <button type="button" onclick="addTimeSlot()"
                                class="px-4 py-2 border border-green-300 rounded-md shadow-sm text-sm font-medium text-green-700 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                <i class="fas fa-plus mr-2"></i>Add Time Slot
                            </button>
                            <button type="button" onclick="removeTimeSlot()"
                                class="px-4 py-2 border border-red-300 rounded-md shadow-sm text-sm font-medium text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                <i class="fas fa-minus mr-2"></i>Remove Last Time Slot
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php elseif ($selected_class_id): ?>
            <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded">
                No teachers have been assigned to this class yet. Please assign teachers to subjects in the class details page first.
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

<script>
// Timetable Management Functions

function copySchedule() {
    // Show modal to select source class
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
    modal.innerHTML = `
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Copy Schedule From</h3>
                <select id="sourceClass" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">Select source class...</option>
                    <?php foreach ($classes as $class): ?>
                        <?php if ($class['id'] != $selected_class_id): ?>
                        <option value="<?php echo $class['id']; ?>">
                            Grade <?php echo htmlspecialchars($class['grade_level']); ?> -
                            <?php echo htmlspecialchars($class['name']); ?>
                        </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <div class="mt-4 flex justify-end space-x-2">
                    <button onclick="this.closest('.fixed').remove()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Cancel</button>
                    <button onclick="performCopySchedule()" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Copy</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function performCopySchedule() {
    const sourceClassId = document.getElementById('sourceClass').value;
    if (!sourceClassId) {
        alert('Please select a source class');
        return;
    }

    // In a real implementation, this would make an AJAX call to copy the schedule
    if (window.SchoolMS && window.SchoolMS.showNotification) {
        window.SchoolMS.showNotification('Schedule copy functionality will be implemented soon!', 'info');
    } else {
        alert('Schedule copy functionality will be implemented soon!');
    }

    document.querySelector('.fixed').remove();
}

function adjustTimeSlots() {
    // Get current settings
    const currentSettings = <?php echo json_encode($_SESSION['timetable_settings'] ?? ['start_time' => '08:00', 'period_duration' => 60, 'break_duration' => 15]); ?>;

    // Show modal for time slot adjustments
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
    modal.innerHTML = `
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Adjust Time Slots</h3>
                <form id="timeSlotForm" method="POST">
                    <input type="hidden" name="action" value="update_time_slots">
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start Time</label>
                            <input type="time" name="start_time" value="${currentSettings.start_time}"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Period Duration (minutes)</label>
                            <input type="number" name="period_duration" value="${currentSettings.period_duration}"
                                min="30" max="120" step="5"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Break Duration (minutes)</label>
                            <input type="number" name="break_duration" value="${currentSettings.break_duration}"
                                min="0" max="60" step="5"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                        </div>
                    </div>
                    <div class="mt-4 flex justify-end space-x-2">
                        <button type="button" onclick="this.closest('.fixed').remove()"
                            class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">Cancel</button>
                        <button type="submit"
                            class="px-4 py-2 bg-purple-600 text-white rounded-md hover:bg-purple-700">Apply</button>
                    </div>
                </form>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function applyTimeAdjustments() {
    // This function is now handled by the form submission
    document.querySelector('.fixed').remove();
}

function clearSchedule() {
    if (confirm('Are you sure you want to clear the entire schedule? This action cannot be undone.')) {
        // Clear all select elements
        const selects = document.querySelectorAll('select[name*="schedules"]');
        selects.forEach(select => {
            select.value = '';
        });

        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification('Schedule cleared. Remember to save changes.', 'warning');
        } else {
            alert('Schedule cleared. Remember to save changes.');
        }
    }
}

function toggleBreakRow(rowId) {
    const row = document.getElementById(rowId);
    if (!row) return;

    const isBreakActive = row.classList.contains('is-break-active');
    const breakCell = row.querySelector('.break-cell-container');
    const dayCells = row.querySelectorAll('.day-cell-container');
    const btn = row.querySelector('button');
    const btnIcon = btn.querySelector('i');
    const btnText = btn.querySelector('.btn-text');
    const isBreakFlags = row.querySelectorAll('.is-break-flag');
    const breakNameInput = row.querySelector('.break-name-input');

    if (isBreakActive) {
        // Toggle to normal class row
        row.classList.remove('is-break-active', 'bg-purple-50', 'dark:bg-purple-950/20');
        breakCell.classList.add('hidden');
        dayCells.forEach(cell => cell.classList.remove('hidden'));
        
        btn.className = 'text-xs px-2 py-1 rounded transition-colors flex items-center gap-1 bg-purple-100 hover:bg-purple-200 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 dark:hover:bg-purple-900/60';
        btnIcon.className = 'fas fa-coffee';
        btnText.textContent = 'Set Break';
        
        isBreakFlags.forEach(flag => flag.value = '0');
    } else {
        // Toggle to break row
        row.classList.add('is-break-active', 'bg-purple-50', 'dark:bg-purple-950/20');
        breakCell.classList.remove('hidden');
        dayCells.forEach(cell => cell.classList.add('hidden'));
        
        btn.className = 'text-xs px-2 py-1 rounded transition-colors flex items-center gap-1 bg-purple-600 hover:bg-purple-700 text-white';
        btnIcon.className = 'fas fa-school';
        btnText.textContent = 'Set Class';
        
        isBreakFlags.forEach(flag => flag.value = '1');
        if (!breakNameInput.value.trim()) {
            breakNameInput.value = 'Lunch Break';
            updateBreakName(rowId, 'Lunch Break');
        }
    }
}

function updateBreakName(rowId, value) {
    const row = document.getElementById(rowId);
    if (!row) return;
    const breakNameFlags = row.querySelectorAll('.break-name-flag');
    breakNameFlags.forEach(flag => flag.value = value);
}

function printSchedule() {
    const className = document.querySelector('select[name="class_id"] option:checked').textContent;
    const printWindow = window.open('', '_blank');
    
    const schoolName = <?php echo json_encode($school_name); ?>;
    const schoolLogo = <?php echo json_encode($school_logo); ?>;
    const academicYear = <?php echo json_encode($academic_year); ?>;
    const schoolAddress = <?php echo json_encode($school_settings['school_address'] ?? ''); ?>;
    const schoolPhone = <?php echo json_encode($school_settings['school_phone'] ?? ''); ?>;
    const schoolEmail = <?php echo json_encode($school_settings['school_email'] ?? ''); ?>;
    const schoolWebsite = <?php echo json_encode($school_settings['school_website'] ?? ''); ?>;

    const rows = document.querySelectorAll('.timetable-row');
    let tableHtml = `
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width: 15%;">Time</th>
                    <th style="width: 17%;">Monday</th>
                    <th style="width: 17%;">Tuesday</th>
                    <th style="width: 17%;">Wednesday</th>
                    <th style="width: 17%;">Thursday</th>
                    <th style="width: 17%;">Friday</th>
                </tr>
            </thead>
            <tbody>
    `;

    rows.forEach(row => {
        const timeSlot = row.querySelector('.time-slot-label').textContent.trim();
        const isBreak = row.classList.contains('is-break-active');
        
        tableHtml += `<tr>`;
        tableHtml += `<td class="time-cell">${timeSlot}</td>`;
        
        if (isBreak) {
            const breakName = row.querySelector('.break-name-input').value.trim() || 'Break';
            tableHtml += `
                <td colspan="5" class="break-cell">
                    <span class="break-icon">☕</span> ${breakName.toUpperCase()}
                </td>
            `;
        } else {
            const daySelects = row.querySelectorAll('.subject-teacher-select');
            daySelects.forEach(select => {
                const selectedText = select.options[select.selectedIndex]?.text || '';
                const hasClass = select.value !== '';
                
                tableHtml += `<td class="class-cell ${!hasClass ? 'empty-cell' : ''}">`;
                if (hasClass) {
                    const parts = selectedText.split(' - ');
                    const subject = parts[0] || '';
                    const teacher = parts[1] || '';
                    tableHtml += `
                        <div class="subject">${subject}</div>
                        <div class="teacher">${teacher}</div>
                    `;
                } else {
                    tableHtml += `<span class="no-class">-</span>`;
                }
                tableHtml += `</td>`;
            });
        }
        tableHtml += `</tr>`;
    });
    
    tableHtml += `
            </tbody>
        </table>
    `;

    const currentDate = new Date().toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });

    const logoImgHtml = schoolLogo ? `<img src="${schoolLogo}" alt="School Logo" class="school-logo">` : '';

    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Class Timetable - ${className}</title>
            <style>
                @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
                
                * {
                    box-sizing: border-box;
                    margin: 0;
                    padding: 0;
                }
                
                body {
                    font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    color: #1f2937;
                    line-height: 1.5;
                    padding: 40px;
                    background-color: #ffffff;
                }
                
                .print-header {
                    display: flex;
                    align-items: center;
                    border-bottom: 3px double #e5e7eb;
                    padding-bottom: 20px;
                    margin-bottom: 30px;
                }
                
                .logo-container {
                    flex-shrink: 0;
                    margin-right: 25px;
                }
                
                .school-logo {
                    max-height: 90px;
                    width: auto;
                    object-fit: contain;
                    margin-right: 25px;
                }
                
                .header-info {
                    flex-grow: 1;
                }
                
                .school-name {
                    font-size: 26px;
                    font-weight: 700;
                    color: #1e3a8a;
                    letter-spacing: -0.025em;
                    margin-bottom: 4px;
                    text-transform: uppercase;
                }
                
                .school-details {
                    font-size: 13px;
                    color: #4b5563;
                    font-weight: 400;
                }
                
                .school-details span {
                    margin-right: 15px;
                    display: inline-block;
                }
                
                .school-title-container {
                    display: flex;
                    justify-content: space-between;
                    align-items: flex-end;
                    margin-bottom: 20px;
                }
                
                .timetable-title {
                    font-size: 20px;
                    font-weight: 700;
                    color: #111827;
                    letter-spacing: -0.02em;
                }
                
                .academic-meta {
                    font-size: 13px;
                    color: #4b5563;
                    font-weight: 500;
                    background-color: #f3f4f6;
                    padding: 4px 12px;
                    border-radius: 9999px;
                    border: 1px solid #e5e7eb;
                }
                
                .print-table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-bottom: 30px;
                }
                
                .print-table th, 
                .print-table td {
                    border: 1px solid #d1d5db;
                    padding: 12px 10px;
                    text-align: center;
                    vertical-align: middle;
                }
                
                .print-table th {
                    background-color: #1e3a8a;
                    color: #ffffff;
                    font-weight: 600;
                    font-size: 13px;
                    text-transform: uppercase;
                    letter-spacing: 0.05em;
                }
                
                .time-cell {
                    font-weight: 600;
                    color: #374151;
                    font-size: 12px;
                    background-color: #f9fafb;
                }
                
                .break-cell {
                    background-color: #f3e8ff;
                    color: #6b21a8;
                    font-weight: 700;
                    font-size: 14px;
                    letter-spacing: 0.1em;
                    text-align: center;
                    border: 1px solid #d8b4fe;
                }
                
                .break-icon {
                    margin-right: 6px;
                }
                
                .class-cell {
                    font-size: 12px;
                }
                
                .subject {
                    font-weight: 600;
                    color: #111827;
                    margin-bottom: 2px;
                }
                
                .teacher {
                    color: #6b7280;
                    font-size: 11px;
                    font-style: italic;
                }
                
                .empty-cell {
                    background-color: #fafafa;
                }
                
                .no-class {
                    color: #d1d5db;
                    font-size: 16px;
                }
                
                .print-footer {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    font-size: 11px;
                    color: #9ca3af;
                    margin-top: 50px;
                    border-top: 1px solid #e5e7eb;
                    padding-top: 15px;
                }
                
                .signature-block {
                    text-align: right;
                    font-size: 12px;
                    color: #4b5563;
                }
                
                .sig-line {
                    width: 200px;
                    border-bottom: 1px solid #9ca3af;
                    margin-bottom: 6px;
                    height: 40px;
                    display: inline-block;
                }
                
                @media print {
                    body {
                        padding: 0;
                    }
                    .print-table th {
                        background-color: #1e3a8a !important;
                        color: #ffffff !important;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                    .break-cell {
                        background-color: #f3e8ff !important;
                        color: #6b21a8 !important;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                    .time-cell {
                        background-color: #f9fafb !important;
                        -webkit-print-color-adjust: exact;
                        print-color-adjust: exact;
                    }
                }
            </style>
        </head>
        <body>
            <div class="print-header">
                ${logoImgHtml}
                <div class="header-info">
                    <h1 class="school-name">${schoolName}</h1>
                    <div class="school-details">
                        ${schoolAddress ? `<span><strong>Add:</strong> ${schoolAddress}</span>` : ''}
                        ${schoolPhone ? `<span><strong>Tel:</strong> ${schoolPhone}</span>` : ''}
                        ${schoolEmail ? `<span><strong>Email:</strong> ${schoolEmail}</span>` : ''}
                        ${schoolWebsite ? `<span><strong>Web:</strong> ${schoolWebsite}</span>` : ''}
                    </div>
                </div>
            </div>
            
            <div class="school-title-container">
                <div class="timetable-title">Class Timetable: ${className}</div>
                <div class="academic-meta">Academic Year: ${academicYear}</div>
            </div>
            
            ${tableHtml}
            
            <div class="print-footer">
                <div>Generated on: ${currentDate} | School Management System</div>
                <div class="signature-block">
                    <div class="sig-line"><?php echo $tt_headmaster_sig; ?></div><br>
                    <div>Headmaster/Headmistress Signature</div>
                </div>
            </div>
        </body>
        </html>
    `);
    
    printWindow.document.close();
    
    printWindow.onload = function() {
        printWindow.focus();
        printWindow.print();
    };
}

function autoFillSchedule() {
    if (confirm('Auto-fill will distribute subjects evenly across the week. Continue?')) {
        const firstSelect = document.querySelector('.subject-teacher-select');
        if (!firstSelect) {
            alert('No slots available for auto-fill');
            return;
        }
        const options = Array.from(firstSelect.options).filter(opt => opt.value !== '');

        if (options.length === 0) {
            alert('No subjects available for auto-fill');
            return;
        }

        const selects = document.querySelectorAll('.subject-teacher-select');
        let optionIndex = 0;

        selects.forEach((select) => {
            const row = select.closest('.timetable-row');
            if (row && !row.classList.contains('is-break-active')) {
                select.value = options[optionIndex % options.length].value;
                optionIndex++;
            }
        });

        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification('Schedule auto-filled. Review and save changes.', 'success');
        } else {
            alert('Schedule auto-filled. Review and save changes.');
        }
    }
}

function validateSchedule() {
    const selects = document.querySelectorAll('.subject-teacher-select');
    const conflicts = [];
    const teacherSchedule = {};

    selects.forEach(select => {
        const row = select.closest('.timetable-row');
        if (row && !row.classList.contains('is-break-active') && select.value) {
            const [subjectId, teacherId] = select.value.split('_');
            const match = select.name.match(/schedules\[(.+?)_(.+?)\]/);
            if (match) {
                const [, day, timeSlot] = match;
                const key = `${teacherId}_${day}_${timeSlot}`;

                if (teacherSchedule[key]) {
                    conflicts.push(`Teacher conflict: ${day} ${timeSlot}`);
                } else {
                    teacherSchedule[key] = true;
                }
            }
        }
    });

    if (conflicts.length > 0) {
        alert('Schedule conflicts found:\n' + conflicts.join('\n'));
    } else {
        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification('Schedule validation passed! No conflicts found.', 'success');
        } else {
            alert('Schedule validation passed! No conflicts found.');
        }
    }
}

function addTimeSlot() {
    const tbody = document.getElementById('timetable-body');
    const rows = tbody.querySelectorAll('.timetable-row');
    const lastRow = rows[rows.length - 1];

    if (!lastRow) return;

    const lastTimeSlot = lastRow.querySelector('.time-slot-label').textContent.trim();
    const [, endTime] = lastTimeSlot.split('-');

    const endDateTime = new Date(`2000-01-01 ${endTime}`);
    endDateTime.setMinutes(endDateTime.getMinutes() + 15); 
    const nextStartTime = endDateTime.toTimeString().slice(0, 5);
    endDateTime.setHours(endDateTime.getHours() + 1); 
    const nextEndTime = endDateTime.toTimeString().slice(0, 5);
    const newTimeSlot = `${nextStartTime}-${nextEndTime}`;

    const newIndex = rows.length;

    const newRow = lastRow.cloneNode(true);
    newRow.id = `row_${newIndex}`;

    newRow.classList.remove('is-break-active', 'bg-purple-50', 'dark:bg-purple-950/20');
    
    const breakCell = newRow.querySelector('.break-cell-container');
    breakCell.classList.add('hidden');
    
    const breakNameInput = newRow.querySelector('.break-name-input');
    breakNameInput.value = '';
    
    const dayCells = newRow.querySelectorAll('.day-cell-container');
    dayCells.forEach(cell => cell.classList.remove('hidden'));

    newRow.querySelector('.time-slot-label').textContent = newTimeSlot;

    const toggleBtn = newRow.querySelector('button');
    toggleBtn.setAttribute('onclick', `toggleBreakRow('row_${newIndex}')`);
    toggleBtn.className = 'text-xs px-2 py-1 rounded transition-colors flex items-center gap-1 bg-purple-100 hover:bg-purple-200 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 dark:hover:bg-purple-900/60';
    toggleBtn.querySelector('i').className = 'fas fa-coffee';
    toggleBtn.querySelector('.btn-text').textContent = 'Set Break';

    const selects = newRow.querySelectorAll('.subject-teacher-select');
    selects.forEach(select => {
        select.value = '';
        const oldName = select.name;
        const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
        select.name = newName;
    });

    const isBreakFlags = newRow.querySelectorAll('.is-break-flag');
    isBreakFlags.forEach(input => {
        input.value = '0';
        const oldName = input.name;
        const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
        input.name = newName;
    });

    const breakNameFlags = newRow.querySelectorAll('.break-name-flag');
    breakNameFlags.forEach(input => {
        input.value = '';
        const oldName = input.name;
        const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
        input.name = newName;
    });

    const hiddenInputs = newRow.querySelectorAll('input[type="hidden"]');
    hiddenInputs.forEach(input => {
        if (!input.classList.contains('is-break-flag') && !input.classList.contains('break-name-flag')) {
            const oldName = input.name;
            const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
            input.name = newName;
            
            if (input.name.includes('time_slot')) {
                input.value = newTimeSlot;
            }
        }
    });

    breakNameInput.setAttribute('oninput', `updateBreakName('row_${newIndex}', this.value)`);

    tbody.appendChild(newRow);

    if (window.SchoolMS && window.SchoolMS.showNotification) {
        window.SchoolMS.showNotification(`Added new time slot: ${newTimeSlot}`, 'success');
    } else {
        alert(`Added new time slot: ${newTimeSlot}`);
    }
}

function removeTimeSlot() {
    const tbody = document.getElementById('timetable-body');
    const rows = tbody.querySelectorAll('.timetable-row');

    if (rows.length <= 1) {
        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification('Cannot remove the last time slot', 'warning');
        } else {
            alert('Cannot remove the last time slot');
        }
        return;
    }

    const lastRow = rows[rows.length - 1];
    const timeSlot = lastRow.querySelector('.time-slot-label').textContent.trim();

    if (confirm(`Are you sure you want to remove the time slot: ${timeSlot}?`)) {
        lastRow.remove();

        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification(`Removed time slot: ${timeSlot}`, 'info');
        } else {
            alert(`Removed time slot: ${timeSlot}`);
        }
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    if (e.ctrlKey || e.metaKey) {
        switch(e.key) {
            case 's':
                e.preventDefault();
                document.querySelector('button[type="submit"]').click();
                break;
            case 'p':
                e.preventDefault();
                printSchedule();
                break;
        }
    }
});
</script>