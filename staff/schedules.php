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

// Fetch active staff for dropdowns
$staff_stmt = $db->query("SELECT id, name FROM users WHERE role IN ($staff_roles_in) AND status = 'active' ORDER BY name");
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle POST: Manage Schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_schedule'])) {
    $staff_id = filter_input(INPUT_POST, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
    $effective_from = filter_input(INPUT_POST, 'effective_from', FILTER_SANITIZE_STRING);
    $effective_to = filter_input(INPUT_POST, 'effective_to', FILTER_SANITIZE_STRING) ?: null;
    $schedules = $_POST['schedules'] ?? [];

    if ($staff_id && $effective_from) {
        try {
            $db->beginTransaction();

            // Soft-delete or hard-delete overlapping future schedules if needed. 
            // For simplicity, we just delete existing records from effective_from onwards to replace them.
            $del_stmt = $db->prepare("DELETE FROM staff_schedules WHERE staff_id = :staff_id AND effective_from >= :from_date");
            $del_stmt->execute([':staff_id' => $staff_id, ':from_date' => $effective_from]);

            $ins_stmt = $db->prepare("
                INSERT INTO staff_schedules (staff_id, day_of_week, shift_start, shift_end, break_start, break_end, location, effective_from, effective_to)
                VALUES (:staff_id, :day_of_week, :shift_start, :shift_end, :break_start, :break_end, :location, :effective_from, :effective_to)
            ");

            $inserted_count = 0;
            foreach ($schedules as $day => $data) {
                if (isset($data['active']) && $data['active'] === 'on') {
                    $ins_stmt->execute([
                        ':staff_id' => $staff_id,
                        ':day_of_week' => $day,
                        ':shift_start' => !empty($data['start']) ? $data['start'] : null,
                        ':shift_end' => !empty($data['end']) ? $data['end'] : null,
                        ':break_start' => !empty($data['break_start']) ? $data['break_start'] : null,
                        ':break_end' => !empty($data['break_end']) ? $data['break_end'] : null,
                        ':location' => !empty($data['location']) ? trim($data['location']) : null,
                        ':effective_from' => $effective_from,
                        ':effective_to' => $effective_to
                    ]);
                    $inserted_count++;
                }
            }

            $db->commit();
            $success_msg = "Schedule saved successfully. $inserted_count day(s) scheduled.";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_msg = "Error saving schedule: " . $e->getMessage();
        }
    } else {
        $error_msg = "Staff and Effective From date are required.";
    }
}

// Fetch Today's Staff
$today_day = date('l'); // Monday, Tuesday, etc.
$today_staff_query = "
    SELECT u.id, u.name, tp.department, ss.shift_start, ss.shift_end, ss.location
    FROM staff_schedules ss
    JOIN users u ON ss.staff_id = u.id
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE ss.day_of_week = :today 
      AND u.status = 'active'
      AND CURDATE() >= ss.effective_from 
      AND (ss.effective_to IS NULL OR CURDATE() <= ss.effective_to)
    ORDER BY ss.shift_start, u.name
";
$stmt = $db->prepare($today_staff_query);
$stmt->execute([':today' => $today_day]);
$today_staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Staff Schedules";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="scheduleManager()">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Staff Schedules</h1>
                                <p class="text-blue-100 text-lg">Manage working hours and shifts</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-clock text-6xl text-white/80"></i>
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

                <!-- Tab Navigation -->
                <div class="flex space-x-1 mb-6 bg-gray-200 dark:bg-gray-800 p-1 rounded-xl w-fit">
                    <button @click="activeView = 'weekly'" :class="{'bg-white dark:bg-gray-700 shadow': activeView === 'weekly', 'text-gray-600 dark:text-gray-400': activeView !== 'weekly'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-calendar-alt mr-2"></i>Weekly Overview
                    </button>
                    <button @click="activeView = 'manage'" :class="{'bg-white dark:bg-gray-700 shadow': activeView === 'manage', 'text-gray-600 dark:text-gray-400': activeView !== 'manage'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-edit mr-2"></i>Manage Schedules
                    </button>
                </div>

                <!-- View: Weekly Overview -->
                <div x-show="activeView === 'weekly'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 mb-8 p-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 mb-6">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">View Staff Schedule</h2>
                            <div class="w-full sm:w-auto min-w-[250px]">
                                <select x-model="selectedStaffId" @change="fetchSchedule()" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">-- Select Staff Member --</option>
                                    <?php foreach($staff_list as $st): ?>
                                        <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Schedule Grid -->
                        <div class="overflow-x-auto" x-show="selectedStaffId">
                            <table class="w-full text-left border-collapse">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="p-4 font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Day</th>
                                        <th class="p-4 font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Shift</th>
                                        <th class="p-4 font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Break</th>
                                        <th class="p-4 font-semibold text-gray-600 dark:text-gray-300 border border-gray-200 dark:border-gray-700">Location</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-for="day in ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']" :key="day">
                                        <tr>
                                            <td class="p-4 border border-gray-200 dark:border-gray-700 font-medium text-gray-800 dark:text-gray-200 bg-gray-50 dark:bg-gray-800/30 w-32" x-text="day"></td>
                                            <td class="p-4 border border-gray-200 dark:border-gray-700" :class="hasSchedule(day) ? 'bg-blue-50/50 dark:bg-blue-900/10' : 'bg-gray-50 dark:bg-gray-800/20'">
                                                <div x-show="hasSchedule(day)" class="font-medium text-blue-700 dark:text-blue-400">
                                                    <i class="fas fa-clock mr-1 text-sm"></i> 
                                                    <span x-text="formatTime(getSchedule(day).shift_start) + ' - ' + formatTime(getSchedule(day).shift_end)"></span>
                                                </div>
                                                <div x-show="!hasSchedule(day)" class="text-gray-400 text-sm italic">Off / No schedule</div>
                                            </td>
                                            <td class="p-4 border border-gray-200 dark:border-gray-700" :class="hasBreak(day) ? 'bg-yellow-50/50 dark:bg-yellow-900/10' : ''">
                                                <div x-show="hasBreak(day)" class="text-yellow-700 dark:text-yellow-500 text-sm">
                                                    <i class="fas fa-mug-hot mr-1"></i>
                                                    <span x-text="formatTime(getSchedule(day).break_start) + ' - ' + formatTime(getSchedule(day).break_end)"></span>
                                                </div>
                                            </td>
                                            <td class="p-4 border border-gray-200 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-400" x-text="getSchedule(day)?.location || '-'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div x-show="!selectedStaffId" class="text-center py-12 text-gray-500 dark:text-gray-400">
                            <i class="fas fa-hand-pointer text-4xl mb-3 opacity-50"></i>
                            <p>Select a staff member to view their schedule</p>
                        </div>
                    </div>

                    <!-- Today's Schedule -->
                    <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Scheduled for Today (<?php echo $today_day; ?>)</h3>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm uppercase">
                                    <tr>
                                        <th class="px-6 py-4 font-semibold">Staff Member</th>
                                        <th class="px-6 py-4 font-semibold">Department</th>
                                        <th class="px-6 py-4 font-semibold">Shift</th>
                                        <th class="px-6 py-4 font-semibold">Location</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($today_staff)): ?>
                                        <tr><td colspan="4" class="px-6 py-8 text-center text-gray-500">No staff scheduled for today.</td></tr>
                                    <?php else: foreach($today_staff as $staff): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                                        <td class="px-6 py-4 font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($staff['name']); ?></td>
                                        <td class="px-6 py-4 text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($staff['department'] ?? '-'); ?></td>
                                        <td class="px-6 py-4">
                                            <span class="bg-blue-100 text-blue-800 text-xs font-bold px-2 py-1 rounded">
                                                <?php echo date('H:i', strtotime($staff['shift_start'])) . ' - ' . date('H:i', strtotime($staff['shift_end'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($staff['location'] ?? '-'); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- View: Manage Schedules -->
                <div x-show="activeView === 'manage'" style="display: none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <form action="" method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 mb-8 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex flex-col sm:flex-row gap-6">
                            <div class="flex-1">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Staff Member *</label>
                                <select name="staff_id" required x-model="manageStaffId" @change="fetchScheduleForEdit()" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">-- Select Staff Member --</option>
                                    <?php foreach($staff_list as $st): ?>
                                        <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Effective From *</label>
                                <input type="date" name="effective_from" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="flex-1">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Effective To (Optional)</label>
                                <input type="date" name="effective_to" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="overflow-x-auto p-6" x-show="manageStaffId">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="text-gray-500 dark:text-gray-400 text-sm border-b dark:border-gray-700">
                                    <tr>
                                        <th class="pb-3 font-semibold w-8">Active</th>
                                        <th class="pb-3 font-semibold">Day</th>
                                        <th class="pb-3 font-semibold">Shift Start</th>
                                        <th class="pb-3 font-semibold">Shift End</th>
                                        <th class="pb-3 font-semibold">Break Start</th>
                                        <th class="pb-3 font-semibold">Break End</th>
                                        <th class="pb-3 font-semibold">Location</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <template x-for="day in ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']" :key="day">
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                                            <td class="py-4">
                                                <input type="checkbox" :name="'schedules['+day+'][active]'" x-model="formData[day].active" class="w-5 h-5 text-blue-600 rounded border-gray-300 focus:ring-blue-500">
                                            </td>
                                            <td class="py-4 font-medium text-gray-800 dark:text-white" x-text="day"></td>
                                            <td class="py-4"><input type="time" :name="'schedules['+day+'][start]'" x-model="formData[day].start" :disabled="!formData[day].active" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white disabled:opacity-50"></td>
                                            <td class="py-4"><input type="time" :name="'schedules['+day+'][end]'" x-model="formData[day].end" :disabled="!formData[day].active" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white disabled:opacity-50"></td>
                                            <td class="py-4"><input type="time" :name="'schedules['+day+'][break_start]'" x-model="formData[day].break_start" :disabled="!formData[day].active" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white disabled:opacity-50"></td>
                                            <td class="py-4"><input type="time" :name="'schedules['+day+'][break_end]'" x-model="formData[day].break_end" :disabled="!formData[day].active" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white disabled:opacity-50"></td>
                                            <td class="py-4"><input type="text" :name="'schedules['+day+'][location]'" x-model="formData[day].location" :disabled="!formData[day].active" placeholder="e.g. Room 101" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white disabled:opacity-50 w-full"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div class="p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex justify-end" x-show="manageStaffId">
                            <button type="button" @click="applyMonToFri()" class="mr-4 text-blue-600 hover:text-blue-800 font-medium text-sm transition">
                                <i class="fas fa-copy mr-1"></i>Copy Mon to Fri
                            </button>
                            <button type="submit" name="manage_schedule" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-medium shadow transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Schedule
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

<script>
function scheduleManager() {
    return {
        activeView: 'weekly',
        selectedStaffId: '',
        manageStaffId: '',
        scheduleData: [],
        formData: {
            'Monday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Tuesday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Wednesday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Thursday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Friday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Saturday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''},
            'Sunday': {active: false, start: '', end: '', break_start: '', break_end: '', location: ''}
        },
        fetchSchedule() {
            if(!this.selectedStaffId) { this.scheduleData = []; return; }
            // Fetch via API (we will create api.php later, for now simulate empty or use AJAX)
            fetch(`api.php?action=get_schedule&staff_id=${this.selectedStaffId}`)
                .then(res => res.ok ? res.json() : {data: []})
                .then(res => { this.scheduleData = res.data || []; })
                .catch(err => { this.scheduleData = []; });
        },
        fetchScheduleForEdit() {
            if(!this.manageStaffId) return;
            // Reset form
            for(let day in this.formData) {
                this.formData[day] = {active: false, start: '', end: '', break_start: '', break_end: '', location: ''};
            }
            fetch(`api.php?action=get_schedule&staff_id=${this.manageStaffId}`)
                .then(res => res.ok ? res.json() : {data: []})
                .then(res => {
                    const data = res.data || [];
                    data.forEach(item => {
                        if(this.formData[item.day_of_week]) {
                            this.formData[item.day_of_week] = {
                                active: true,
                                start: item.shift_start ? item.shift_start.substring(0,5) : '',
                                end: item.shift_end ? item.shift_end.substring(0,5) : '',
                                break_start: item.break_start ? item.break_start.substring(0,5) : '',
                                break_end: item.break_end ? item.break_end.substring(0,5) : '',
                                location: item.location || ''
                            };
                        }
                    });
                });
        },
        hasSchedule(day) { return this.getSchedule(day) !== null; },
        hasBreak(day) { const s = this.getSchedule(day); return s && s.break_start; },
        getSchedule(day) { return this.scheduleData.find(s => s.day_of_week === day) || null; },
        formatTime(time) { return time ? time.substring(0, 5) : ''; },
        applyMonToFri() {
            const m = this.formData['Monday'];
            if(!m.active) return alert('Monday must be active to copy its schedule.');
            ['Tuesday', 'Wednesday', 'Thursday', 'Friday'].forEach(d => {
                this.formData[d] = { active: true, start: m.start, end: m.end, break_start: m.break_start, break_end: m.break_end, location: m.location };
            });
        }
    }
}
</script>
