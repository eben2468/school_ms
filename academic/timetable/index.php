<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

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
            $query = "INSERT INTO class_schedule (class_id, day, time_slot, subject_id, teacher_id)
                     VALUES (:class_id, :day, :time_slot, :subject_id, :teacher_id)";
            $stmt = $db->prepare($query);

            foreach ($schedules as $schedule) {
                if (empty($schedule['subject_teacher'])) continue;

                // Split the subject_teacher value to get subject_id and teacher_id
                list($subject_id, $teacher_id) = explode('_', $schedule['subject_teacher']);

                $stmt->bindParam(':class_id', $class_id);
                $stmt->bindParam(':day', $schedule['day']);
                $stmt->bindParam(':time_slot', $schedule['time_slot']);
                $stmt->bindParam(':subject_id', $subject_id);
                $stmt->bindParam(':teacher_id', $teacher_id);
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
              JOIN subjects s ON cs.subject_id = s.id
              JOIN users u ON cs.teacher_id = u.id
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
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Timetable Management</h1>
                <div class="flex space-x-3">
                    <?php if ($selected_class_id): ?>
                    <!-- Action Buttons -->
                    <div class="flex space-x-2">
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
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
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
                                    ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo $time_slot; ?>
                                        </td>
                                        <?php
                                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                        foreach ($days as $day):
                                            $current_schedule = array_filter($schedules ?? [], function($s) use ($day, $time_slot) {
                                                return $s['day'] === $day && $s['time_slot'] === $time_slot;
                                            });
                                            $current_schedule = reset($current_schedule);
                                        ?>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][subject_teacher]"
                                                class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm">
                                                <option value="">No Class</option>
                                                <?php foreach ($class_teachers as $teacher): ?>
                                                    <?php
                                                    $value = $teacher['subject_id'] . '_' . $teacher['teacher_id'];
                                                    $selected = $current_schedule && 
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
                                            <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][day]" 
                                                   value="<?php echo $day; ?>">
                                            <input type="hidden" name="schedules[<?php echo $day; ?>_<?php echo $time_slot; ?>][time_slot]" 
                                                   value="<?php echo $time_slot; ?>">
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

function printSchedule() {
    // Create a print-friendly version
    const printWindow = window.open('', '_blank');
    const scheduleTable = document.querySelector('table').outerHTML;
    const className = document.querySelector('select[name="class_id"] option:checked').textContent;

    printWindow.document.write(`
        <html>
        <head>
            <title>Timetable - ${className}</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                h1 { color: #333; }
                @media print { body { margin: 0; } }
            </style>
        </head>
        <body>
            <h1>Class Timetable - ${className}</h1>
            <p>Generated on: ${new Date().toLocaleDateString()}</p>
            ${scheduleTable.replace(/<select[^>]*>.*?<\/select>/g, function(match) {
                const selectedOption = match.match(/<option[^>]*selected[^>]*>(.*?)<\/option>/);
                return selectedOption ? selectedOption[1] : 'No Class';
            })}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.print();
}

function autoFillSchedule() {
    if (confirm('Auto-fill will distribute subjects evenly across the week. Continue?')) {
        // Get all available subjects
        const firstSelect = document.querySelector('select[name*="schedules"]');
        const options = Array.from(firstSelect.options).filter(opt => opt.value !== '');

        if (options.length === 0) {
            alert('No subjects available for auto-fill');
            return;
        }

        // Get all schedule selects
        const selects = document.querySelectorAll('select[name*="schedules"]');
        let optionIndex = 0;

        selects.forEach((select, index) => {
            // Skip lunch break slots (assuming 11:00-12:00 is lunch)
            if (!select.name.includes('11:00-12:00')) {
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
    const selects = document.querySelectorAll('select[name*="schedules"]');
    const conflicts = [];
    const teacherSchedule = {};

    selects.forEach(select => {
        if (select.value) {
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
        alert('Schedule conflicts found:\\n' + conflicts.join('\\n'));
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
    const rows = tbody.querySelectorAll('tr');
    const lastRow = rows[rows.length - 1];

    if (!lastRow) return;

    // Get the last time slot to calculate the next one
    const lastTimeCell = lastRow.querySelector('td:first-child');
    const lastTimeSlot = lastTimeCell.textContent.trim();
    const [, endTime] = lastTimeSlot.split('-');

    // Calculate next time slot (assuming 1 hour duration + 15 min break)
    const endDateTime = new Date(`2000-01-01 ${endTime}`);
    endDateTime.setMinutes(endDateTime.getMinutes() + 15); // Add break
    const nextStartTime = endDateTime.toTimeString().slice(0, 5);
    endDateTime.setHours(endDateTime.getHours() + 1); // Add 1 hour period
    const nextEndTime = endDateTime.toTimeString().slice(0, 5);
    const newTimeSlot = `${nextStartTime}-${nextEndTime}`;

    // Clone the last row
    const newRow = lastRow.cloneNode(true);

    // Update the time slot
    newRow.querySelector('td:first-child').textContent = newTimeSlot;

    // Clear all select values and update names
    const selects = newRow.querySelectorAll('select');
    const hiddenInputs = newRow.querySelectorAll('input[type="hidden"]');

    selects.forEach(select => {
        select.value = '';
        // Update the name attribute to include the new time slot
        const oldName = select.name;
        const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
        select.name = newName;
    });

    hiddenInputs.forEach(input => {
        if (input.name.includes('time_slot')) {
            input.value = newTimeSlot;
        }
        // Update the name attribute
        const oldName = input.name;
        const newName = oldName.replace(/schedules\[(.+?)_(.+?)\]/, `schedules[$1_${newTimeSlot}]`);
        input.name = newName;
    });

    tbody.appendChild(newRow);

    if (window.SchoolMS && window.SchoolMS.showNotification) {
        window.SchoolMS.showNotification(`Added new time slot: ${newTimeSlot}`, 'success');
    } else {
        alert(`Added new time slot: ${newTimeSlot}`);
    }
}

function removeTimeSlot() {
    const tbody = document.getElementById('timetable-body');
    const rows = tbody.querySelectorAll('tr');

    if (rows.length <= 1) {
        if (window.SchoolMS && window.SchoolMS.showNotification) {
            window.SchoolMS.showNotification('Cannot remove the last time slot', 'warning');
        } else {
            alert('Cannot remove the last time slot');
        }
        return;
    }

    const lastRow = rows[rows.length - 1];
    const timeSlot = lastRow.querySelector('td:first-child').textContent.trim();

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