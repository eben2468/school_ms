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

// 1. Date Selector
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// 4. Handle POST: Save Attendance
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $attendance_data = $_POST['attendance'] ?? [];
    
    if (!empty($attendance_data)) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("
                INSERT INTO staff_attendance 
                (staff_id, date, status, check_in, check_out, notes, marked_by, created_at) 
                VALUES (:staff_id, :date, :status, :check_in, :check_out, :notes, :recorded_by, NOW())
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status),
                check_in = VALUES(check_in),
                check_out = VALUES(check_out),
                notes = VALUES(notes),
                updated_at = NOW()
            ");

            foreach ($attendance_data as $staff_id => $data) {
                // Ensure status is valid before inserting
                $status = in_array($data['status'], ['present', 'absent', 'late', 'half_day', 'on_leave']) ? $data['status'] : 'present';
                $check_in = !empty($data['check_in']) ? $data['check_in'] : null;
                $check_out = !empty($data['check_out']) ? $data['check_out'] : null;
                $notes = !empty($data['notes']) ? trim($data['notes']) : null;
                
                $stmt->execute([
                    ':staff_id' => $staff_id,
                    ':date' => $selected_date,
                    ':status' => $status,
                    ':check_in' => $check_in,
                    ':check_out' => $check_out,
                    ':notes' => $notes,
                    ':recorded_by' => $_SESSION['user_id']
                ]);
            }
            
            $db->commit();
            $success_msg = "Attendance saved successfully for $selected_date";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_msg = "Error saving attendance: " . $e->getMessage();
        }
    }
}

// 2. Quick Stats Row
$stats_query = "
    SELECT status, COUNT(*) as count 
    FROM staff_attendance 
    WHERE date = :date 
    GROUP BY status
";
$stmt = $db->prepare($stats_query);
$stmt->execute([':date' => $selected_date]);
$stats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

$present = $stats['present'] ?? 0;
$absent = $stats['absent'] ?? 0;
$late = $stats['late'] ?? 0;
$on_leave = $stats['on_leave'] ?? 0;

// Fetch staff for Attendance Marking Form
$staff_query = "
    SELECT u.id, u.name, tp.employee_id, tp.department,
           sa.status, sa.check_in, sa.check_out, sa.notes
    FROM users u
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    LEFT JOIN staff_attendance sa ON u.id = sa.staff_id AND sa.date = :date
    WHERE u.role IN ($staff_roles_in) AND u.status = 'active'
    ORDER BY u.name
";
$stmt = $db->prepare($staff_query);
$stmt->execute([':date' => $selected_date]);
$active_staff = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. Monthly Summary Section
$summary_month = isset($_GET['month']) ? $_GET['month'] : date('m');
$summary_year = isset($_GET['year']) ? $_GET['year'] : date('Y');
$days_in_month = cal_days_in_month(CAL_GREGORIAN, $summary_month, $summary_year);
// Approximating working days (excluding weekends). A real system might use a calendar table.
$total_working_days = 0;
for ($d = 1; $d <= $days_in_month; $d++) {
    $day_of_week = date('N', strtotime("$summary_year-$summary_month-$d"));
    if ($day_of_week <= 5) $total_working_days++;
}
if ($total_working_days == 0) $total_working_days = 1; // Prevent division by zero

$summary_query = "
    SELECT u.id, u.name,
           SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) as present_days,
           SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
           SUM(CASE WHEN sa.status = 'late' THEN 1 ELSE 0 END) as late_days,
           SUM(CASE WHEN sa.status = 'half_day' THEN 1 ELSE 0 END) as half_days,
           SUM(CASE WHEN sa.status = 'on_leave' THEN 1 ELSE 0 END) as leave_days
    FROM users u
    LEFT JOIN staff_attendance sa ON u.id = sa.staff_id 
        AND MONTH(sa.date) = :month 
        AND YEAR(sa.date) = :year
    WHERE u.role IN ($staff_roles_in) AND u.status = 'active'
    GROUP BY u.id, u.name
    ORDER BY u.name
";
$stmt = $db->prepare($summary_query);
$stmt->execute([':month' => $summary_month, ':year' => $summary_year]);
$monthly_summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Staff Attendance";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{ markAllAs: 'present' }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Staff Attendance</h1>
                                <p class="text-blue-100 text-lg">Track and manage daily staff attendance</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-calendar-check text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_msg)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error_msg)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
                <?php endif; ?>

                <!-- Top Controls -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-100 dark:border-gray-700 flex flex-col sm:flex-row justify-between items-center">
                    <form action="" method="GET" class="flex items-center space-x-4">
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Date:</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" onchange="this.form.submit()" class="border border-gray-300 dark:border-gray-600 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </form>
                    <a href="?export=attendance&date=<?php echo $selected_date; ?>" class="mt-4 sm:mt-0 inline-flex items-center text-emerald-600 bg-emerald-50 hover:bg-emerald-100 px-4 py-2 rounded-lg transition-colors font-medium">
                        <i class="fas fa-file-export mr-2"></i>Export
                    </a>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-green-500 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Present</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $present; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center text-green-600"><i class="fas fa-user-check text-xl"></i></div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-red-500 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Absent</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $absent; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center text-red-600"><i class="fas fa-user-times text-xl"></i></div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-yellow-500 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Late</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $late; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 rounded-full flex items-center justify-center text-yellow-600"><i class="fas fa-user-clock text-xl"></i></div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-blue-500 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">On Leave</p>
                            <h3 class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $on_leave; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center text-blue-600"><i class="fas fa-bed text-xl"></i></div>
                    </div>
                </div>

                <!-- Attendance Marking Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-12">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-800/50">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white">Mark Attendance</h2>
                        <button type="button" @click="document.querySelectorAll('.status-radio').forEach(r => { if(r.value === markAllAs) r.checked = true; })" class="text-blue-600 hover:text-blue-800 font-medium text-sm transition">
                            <i class="fas fa-check-double mr-1"></i>Mark All As Present
                        </button>
                    </div>
                    
                    <form action="" method="POST">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm uppercase">
                                    <tr>
                                        <th class="px-6 py-4 font-semibold">Staff Member</th>
                                        <th class="px-6 py-4 font-semibold">Status</th>
                                        <th class="px-6 py-4 font-semibold">Time In</th>
                                        <th class="px-6 py-4 font-semibold">Time Out</th>
                                        <th class="px-6 py-4 font-semibold w-1/4">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($active_staff as $staff): 
                                        $current_status = $staff['status'] ?? 'present';
                                    ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                                        <td class="px-6 py-4">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold mr-3 flex-shrink-0">
                                                    <?php echo strtoupper(substr($staff['name'], 0, 1)); ?>
                                                </div>
                                                <div>
                                                    <div class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($staff['name']); ?></div>
                                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($staff['employee_id'] . ' • ' . $staff['department']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="flex space-x-3">
                                                <label class="inline-flex items-center cursor-pointer" title="Present">
                                                    <input type="radio" name="attendance[<?php echo $staff['id']; ?>][status]" value="present" class="status-radio text-green-500 focus:ring-green-500" <?php echo $current_status === 'present' ? 'checked' : ''; ?>>
                                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300 font-medium">P</span>
                                                </label>
                                                <label class="inline-flex items-center cursor-pointer" title="Absent">
                                                    <input type="radio" name="attendance[<?php echo $staff['id']; ?>][status]" value="absent" class="status-radio text-red-500 focus:ring-red-500" <?php echo $current_status === 'absent' ? 'checked' : ''; ?>>
                                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300 font-medium">A</span>
                                                </label>
                                                <label class="inline-flex items-center cursor-pointer" title="Late">
                                                    <input type="radio" name="attendance[<?php echo $staff['id']; ?>][status]" value="late" class="status-radio text-yellow-500 focus:ring-yellow-500" <?php echo $current_status === 'late' ? 'checked' : ''; ?>>
                                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300 font-medium">L</span>
                                                </label>
                                                <label class="inline-flex items-center cursor-pointer" title="Half Day">
                                                    <input type="radio" name="attendance[<?php echo $staff['id']; ?>][status]" value="half_day" class="status-radio text-purple-500 focus:ring-purple-500" <?php echo $current_status === 'half_day' ? 'checked' : ''; ?>>
                                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300 font-medium">H</span>
                                                </label>
                                                <label class="inline-flex items-center cursor-pointer" title="On Leave">
                                                    <input type="radio" name="attendance[<?php echo $staff['id']; ?>][status]" value="on_leave" class="status-radio text-blue-500 focus:ring-blue-500" <?php echo $current_status === 'on_leave' ? 'checked' : ''; ?>>
                                                    <span class="ml-1 text-sm text-gray-700 dark:text-gray-300 font-medium">LV</span>
                                                </label>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="time" name="attendance[<?php echo $staff['id']; ?>][check_in]" value="<?php echo htmlspecialchars($staff['check_in'] ?? ''); ?>" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="time" name="attendance[<?php echo $staff['id']; ?>][check_out]" value="<?php echo htmlspecialchars($staff['check_out'] ?? ''); ?>" class="border border-gray-300 dark:border-gray-600 rounded-lg px-2 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="text" name="attendance[<?php echo $staff['id']; ?>][notes]" value="<?php echo htmlspecialchars($staff['notes'] ?? ''); ?>" placeholder="Notes..." class="w-full border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1 text-sm focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="p-6 bg-gray-50 dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                            <button type="submit" name="save_attendance" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-medium shadow transition-colors">
                                <i class="fas fa-save mr-2"></i>Save Attendance
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Monthly Summary -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-800/50">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white">Monthly Summary</h2>
                        <form action="" method="GET" class="flex space-x-2">
                            <select name="month" onchange="this.form.submit()" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                                <?php for($m=1; $m<=12; $m++): ?>
                                    <option value="<?php echo str_pad($m, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($summary_month == str_pad($m, 2, '0', STR_PAD_LEFT)) ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0,0,0,$m,1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <select name="year" onchange="this.form.submit()" class="border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 text-sm dark:bg-gray-700 dark:text-white">
                                <?php for($y=date('Y')-2; $y<=date('Y')+1; $y++): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($summary_year == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </form>
                    </div>
                    <div class="overflow-x-auto p-4">
                        <table class="w-full text-left whitespace-nowrap">
                            <thead class="text-gray-500 dark:text-gray-400 text-sm border-b dark:border-gray-700">
                                <tr>
                                    <th class="pb-3 font-semibold">Staff Member</th>
                                    <th class="pb-3 font-semibold text-center">Present</th>
                                    <th class="pb-3 font-semibold text-center">Absent</th>
                                    <th class="pb-3 font-semibold text-center">Late</th>
                                    <th class="pb-3 font-semibold text-center">Half Day</th>
                                    <th class="pb-3 font-semibold text-center">Leave</th>
                                    <th class="pb-3 font-semibold text-center">Attendance %</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($monthly_summary as $sum): 
                                    $score = $sum['present_days'] + ($sum['half_days'] * 0.5) + $sum['late_days'];
                                    $percentage = min(100, round(($score / max(1, $total_working_days)) * 100, 1));
                                    
                                    if ($percentage >= 90) $color = 'text-green-600 bg-green-50 dark:bg-green-900/30 dark:text-green-400';
                                    elseif ($percentage >= 75) $color = 'text-yellow-600 bg-yellow-50 dark:bg-yellow-900/30 dark:text-yellow-400';
                                    else $color = 'text-red-600 bg-red-50 dark:bg-red-900/30 dark:text-red-400';
                                ?>
                                <tr>
                                    <td class="py-3 font-medium text-gray-800 dark:text-white"><?php echo htmlspecialchars($sum['name']); ?></td>
                                    <td class="py-3 text-center"><?php echo $sum['present_days']; ?></td>
                                    <td class="py-3 text-center"><?php echo $sum['absent_days']; ?></td>
                                    <td class="py-3 text-center"><?php echo $sum['late_days']; ?></td>
                                    <td class="py-3 text-center"><?php echo $sum['half_days']; ?></td>
                                    <td class="py-3 text-center"><?php echo $sum['leave_days']; ?></td>
                                    <td class="py-3 text-center">
                                        <span class="px-2.5 py-1 rounded-full text-xs font-bold <?php echo $color; ?>">
                                            <?php echo $percentage; ?>%
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
