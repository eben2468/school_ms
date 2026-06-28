<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('staff');

// Only roles that may create staff can run a bulk import (matches create.php).
if (!in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/schema_helpers.php';

$database = new Database();
$db = $database->getConnection();

// Heal older tenant DBs missing newer teacher_profiles columns before importing.
ensureTeacherProfileColumns($db);

// Valid staff roles & their display labels (same set used in index.php / create.php).
$role_labels = [
    'teacher'           => 'Teacher',
    'librarian'         => 'Librarian',
    'accountant'        => 'Accountant',
    'nurse'             => 'Nurse',
    'counselor'         => 'Counselor',
    'transport_officer' => 'Transport Officer',
    'hostel_warden'     => 'Hostel Warden',
    'canteen_manager'   => 'Canteen Manager',
    'hr'                => 'Human Resource',
];

// Columns expected in the CSV (in template order).
$template_columns = [
    'first_name', 'other_names', 'last_name', 'email', 'password', 'role',
    'employee_id', 'phone', 'gender', 'date_of_birth', 'department', 'position',
    'contract_type', 'joining_date', 'qualification', 'specialization',
    'experience_years', 'salary',
];

// ── Sample CSV template download ────────────────────────────────────────
if (isset($_GET['download']) && $_GET['download'] === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=staff_import_template.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, $template_columns);
    // Two example rows demonstrating the expected format.
    fputcsv($out, [
        'Jane', 'A.', 'Mensah', 'jane.mensah@school.com', 'Staff@123', 'teacher',
        '', '+233200000001', 'female', '1990-05-12', 'Science', 'Senior Teacher',
        'full_time', date('Y-m-d'), 'M.Sc. Physics', 'Physics', '6', '2500',
    ]);
    fputcsv($out, [
        'Kwame', '', 'Boateng', 'kwame.boateng@school.com', '', 'accountant',
        'EMP20260050', '+233200000002', 'male', '1988-11-03', 'Finance', 'Accounts Officer',
        'full_time', date('Y-m-d'), 'B.Com Accounting', 'Payroll', '4', '2200',
    ]);
    fclose($out);
    exit();
}

$import_summary = null; // populated after a successful POST run

// ── Handle the upload & import ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require('bulk_import.php');

    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. Please choose a valid CSV file and try again.";
    } else {
        $file = $_FILES['import_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $max_size = 2 * 1024 * 1024; // 2 MB

        // Security: validate extension, size and MIME before reading.
        $allowed_mimes = ['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/csv', 'application/octet-stream'];
        $mime = function_exists('mime_content_type') ? @mime_content_type($file['tmp_name']) : '';

        if ($ext !== 'csv') {
            $error = "Only .csv files are accepted.";
        } elseif ($file['size'] > $max_size) {
            $error = "The file is too large. Maximum allowed size is 2 MB.";
        } elseif ($mime && !in_array($mime, $allowed_mimes)) {
            $error = "The uploaded file does not appear to be a valid CSV.";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if ($handle === false) {
                $error = "Unable to read the uploaded file.";
            } else {
                // Read & normalise the header row into a column => index map.
                $header = fgetcsv($handle);
                if ($header === false) {
                    $error = "The CSV file is empty.";
                    fclose($handle);
                } else {
                    // Strip BOM from the first header cell if present.
                    if (isset($header[0])) {
                        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
                    }
                    $col = [];
                    foreach ($header as $i => $name) {
                        $key = strtolower(trim(str_replace([' ', '-'], '_', $name)));
                        $col[$key] = $i;
                    }

                    if (!isset($col['first_name'], $col['last_name'], $col['email'], $col['role'])) {
                        $error = "The CSV is missing required columns. It must include at least: first_name, last_name, email, role. Download the template for the correct format.";
                        fclose($handle);
                    } else {
                        // Pre-load departments (name => id) for matching.
                        $dept_map = [];
                        try {
                            foreach ($db->query("SELECT id, name FROM staff_departments WHERE status = 'active'")->fetchAll(PDO::FETCH_ASSOC) as $d) {
                                $dept_map[strtolower(trim($d['name']))] = ['id' => $d['id'], 'name' => $d['name']];
                            }
                        } catch (PDOException $e) { /* table may not exist yet */ }

                        // Seed the auto employee-ID counter.
                        $year = date('Y');
                        $next_emp_num = 1;
                        try {
                            $last = $db->query("SELECT employee_id FROM teacher_profiles WHERE employee_id LIKE 'EMP{$year}%' ORDER BY employee_id DESC LIMIT 1")->fetchColumn();
                            if ($last && preg_match('/^EMP\d{4}(\d+)$/', $last, $m)) {
                                $next_emp_num = (int)$m[1] + 1;
                            }
                        } catch (PDOException $e) { /* ignore */ }

                        $get = function ($row, $key) use ($col) {
                            if (!isset($col[$key])) return '';
                            return trim($row[$col[$key]] ?? '');
                        };

                        $imported   = 0;
                        $skipped    = 0;
                        $row_errors = [];
                        // Login credentials assigned to each imported staff member, so the
                        // admin can hand them out (a default password is set when blank).
                        $created_credentials = [];
                        $seen_emails = [];   // within-file duplicate guard
                        $seen_emp_ids = [];
                        $line = 1;           // header is line 1

                        require_once '../includes/settings_helper.php';
                        $registration_open = function_exists('isUserRegistrationAllowed') ? isUserRegistrationAllowed() : true;

                        if (!$registration_open) {
                            $error = "New user registration is currently disabled in System Settings.";
                            fclose($handle);
                        } else {
                            require_once '../includes/user_directory.php';

                            while (($data = fgetcsv($handle)) !== false) {
                                $line++;
                                // Skip completely blank lines.
                                if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                                    continue;
                                }

                                $first_name = $get($data, 'first_name');
                                $other_names = $get($data, 'other_names');
                                $last_name  = $get($data, 'last_name');
                                $email      = strtolower($get($data, 'email'));
                                $password   = $get($data, 'password');
                                $role       = strtolower($get($data, 'role'));
                                $employee_id = $get($data, 'employee_id');
                                $phone      = $get($data, 'phone');
                                $gender     = strtolower($get($data, 'gender'));
                                $dob        = $get($data, 'date_of_birth');
                                $department = $get($data, 'department');
                                $position   = $get($data, 'position');
                                $contract   = strtolower($get($data, 'contract_type'));
                                $joining    = $get($data, 'joining_date');
                                $qualification = $get($data, 'qualification');
                                $specialization = $get($data, 'specialization');
                                $experience = $get($data, 'experience_years');
                                $salary     = $get($data, 'salary');

                                // ── Per-row validation ──
                                if ($first_name === '' || $last_name === '') {
                                    $row_errors[] = "Row $line: First name and last name are required.";
                                    $skipped++; continue;
                                }
                                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                                    $row_errors[] = "Row $line: A valid email address is required.";
                                    $skipped++; continue;
                                }
                                if (!array_key_exists($role, $role_labels)) {
                                    $row_errors[] = "Row $line: Invalid role '" . htmlspecialchars($role) . "'.";
                                    $skipped++; continue;
                                }
                                if (isset($seen_emails[$email])) {
                                    $row_errors[] = "Row $line: Duplicate email '$email' within the file — skipped.";
                                    $skipped++; continue;
                                }

                                // Duplicate against existing users.
                                $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                                $chk->execute([':email' => $email]);
                                if ($chk->fetchColumn() > 0) {
                                    $row_errors[] = "Row $line: A user with email '$email' already exists — skipped.";
                                    $skipped++; continue;
                                }

                                // Resolve employee ID (auto-generate if blank).
                                if ($employee_id === '') {
                                    do {
                                        $employee_id = 'EMP' . $year . str_pad($next_emp_num, 4, '0', STR_PAD_LEFT);
                                        $next_emp_num++;
                                        $ec = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE employee_id = :eid");
                                        $ec->execute([':eid' => $employee_id]);
                                        $exists = $ec->fetchColumn() > 0 || isset($seen_emp_ids[$employee_id]);
                                    } while ($exists);
                                } else {
                                    if (isset($seen_emp_ids[$employee_id])) {
                                        $row_errors[] = "Row $line: Duplicate employee ID '$employee_id' within the file — skipped.";
                                        $skipped++; continue;
                                    }
                                    $ec = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE employee_id = :eid");
                                    $ec->execute([':eid' => $employee_id]);
                                    if ($ec->fetchColumn() > 0) {
                                        $row_errors[] = "Row $line: Employee ID '$employee_id' is already in use — skipped.";
                                        $skipped++; continue;
                                    }
                                }

                                // Normalise optional fields.
                                if (!in_array($gender, ['male', 'female', 'other'], true)) $gender = null;
                                if (!in_array($contract, ['full_time', 'part_time', 'contract', 'temporary'], true)) $contract = 'full_time';
                                $joining = ($joining !== '' && strtotime($joining)) ? date('Y-m-d', strtotime($joining)) : date('Y-m-d');
                                $dob = ($dob !== '' && strtotime($dob)) ? date('Y-m-d', strtotime($dob)) : null;
                                $dept_id = null; $dept_name = null;
                                if ($department !== '' && isset($dept_map[strtolower($department)])) {
                                    $dept_id   = $dept_map[strtolower($department)]['id'];
                                    $dept_name = $dept_map[strtolower($department)]['name'];
                                } elseif ($department !== '') {
                                    $dept_name = $department; // keep free-text dept name even if unmatched
                                }
                                if ($password === '' || strlen($password) < 8) {
                                    $password = 'Staff@123'; // default; user should change on first login
                                }

                                $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));

                                // ── Insert (each row in its own transaction) ──
                                try {
                                    $db->beginTransaction();
                                    $hashed = password_hash($password, PASSWORD_BCRYPT);

                                    $us = $db->prepare("INSERT INTO users (name, first_name, other_names, last_name, email, password, role, status)
                                                        VALUES (:name, :first_name, :other_names, :last_name, :email, :password, :role, 'active')");
                                    $us->execute([
                                        ':name' => $name,
                                        ':first_name' => $first_name,
                                        ':other_names' => $other_names ?: null,
                                        ':last_name' => $last_name,
                                        ':email' => $email,
                                        ':password' => $hashed,
                                        ':role' => $role,
                                    ]);
                                    $user_id = $db->lastInsertId();

                                    $ps = $db->prepare("INSERT INTO teacher_profiles
                                        (user_id, employee_id, date_of_birth, gender, phone, qualification,
                                         experience_years, joining_date, salary, department, department_id,
                                         position, specialization, contract_type)
                                        VALUES
                                        (:user_id, :employee_id, :dob, :gender, :phone, :qualification,
                                         :experience, :joining, :salary, :department, :department_id,
                                         :position, :specialization, :contract)");
                                    $ps->execute([
                                        ':user_id' => $user_id,
                                        ':employee_id' => $employee_id,
                                        ':dob' => $dob,
                                        ':gender' => $gender,
                                        ':phone' => $phone ?: null,
                                        ':qualification' => $qualification ?: null,
                                        ':experience' => ($experience !== '') ? (int)$experience : 0,
                                        ':joining' => $joining,
                                        ':salary' => ($salary !== '') ? $salary : null,
                                        ':department' => $dept_name,
                                        ':department_id' => $dept_id,
                                        ':position' => $position ?: null,
                                        ':specialization' => $specialization ?: null,
                                        ':contract' => $contract,
                                    ]);

                                    $db->commit();

                                    // Mirror to the central login directory.
                                    syncUserToCentralDirectory([
                                        'school_id'    => $_SESSION['school_id'] ?? null,
                                        'name'         => $name,
                                        'email'        => $email,
                                        'password'     => $hashed,
                                        'role'         => $role,
                                        'status'       => 'active',
                                        'employee_id'  => $employee_id,
                                        'joining_date' => $joining,
                                    ]);

                                    $seen_emails[$email]     = true;
                                    $seen_emp_ids[$employee_id] = true;
                                    $created_credentials[] = [
                                        'name'        => $name,
                                        'employee_id' => $employee_id,
                                        'role'        => $role_labels[$role] ?? $role,
                                        'email'       => $email,
                                        'password'    => $password, // plaintext used (CSV value or default)
                                        'default'     => ($password === 'Staff@123'),
                                    ];
                                    $imported++;
                                } catch (PDOException $e) {
                                    if ($db->inTransaction()) $db->rollBack();
                                    $row_errors[] = "Row $line: Database error — " . $e->getMessage();
                                    $skipped++;
                                }
                            }
                            fclose($handle);

                            $import_summary = [
                                'imported'    => $imported,
                                'skipped'     => $skipped,
                                'errors'      => $row_errors,
                                'credentials' => $created_credentials,
                            ];
                            if ($imported > 0) {
                                $success = "Successfully imported $imported staff member" . ($imported === 1 ? '' : 's') . ".";
                            }
                            if ($imported === 0 && $skipped > 0) {
                                $error = "No staff members were imported. Please review the issues below.";
                            }
                        }
                    }
                }
            }
        }
    }
}

$title = "Bulk Import Staff";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-5xl mx-auto">

                <!-- ═══════════════ Page Header ═══════════════ -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Bulk Import Staff</h1>
                                <p class="text-blue-100 text-lg">Add many staff members at once from a CSV file</p>
                                <div class="mt-4">
                                    <a href="index.php" class="inline-flex items-center text-sm text-white hover:text-blue-200 transition">
                                        <i class="fas fa-arrow-left mr-2"></i> Back to Staff Directory
                                    </a>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-import text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════ Flash Messages ═══════════════ -->
                <?php if (isset($success)): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border border-green-400 dark:border-green-600 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl mb-6 flex items-center shadow">
                    <i class="fas fa-check-circle mr-2 text-lg"></i><?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl mb-6 flex items-center shadow">
                    <i class="fas fa-exclamation-circle mr-2 text-lg"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- ═══════════════ Import Report ═══════════════ -->
                <?php if ($import_summary !== null): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 mb-8 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                        <h3 class="text-xl font-bold text-gray-800 dark:text-white flex items-center">
                            <i class="fas fa-clipboard-check mr-2 text-blue-500"></i>Import Report
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                            <div class="flex items-center p-4 rounded-xl bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800">
                                <div class="w-12 h-12 rounded-xl bg-green-500 flex items-center justify-center mr-4">
                                    <i class="fas fa-user-check text-white text-lg"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Imported</p>
                                    <p class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $import_summary['imported']; ?></p>
                                </div>
                            </div>
                            <div class="flex items-center p-4 rounded-xl bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800">
                                <div class="w-12 h-12 rounded-xl bg-yellow-500 flex items-center justify-center mr-4">
                                    <i class="fas fa-exclamation-triangle text-white text-lg"></i>
                                </div>
                                <div>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Skipped</p>
                                    <p class="text-2xl font-bold text-gray-800 dark:text-white"><?php echo $import_summary['skipped']; ?></p>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($import_summary['errors'])): ?>
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4">
                            <p class="font-semibold text-yellow-800 dark:text-yellow-300 mb-2 flex items-center">
                                <i class="fas fa-list-ul mr-2"></i>Issues encountered (<?php echo count($import_summary['errors']); ?>)
                            </p>
                            <ul class="list-disc list-inside space-y-1 max-h-64 overflow-y-auto">
                                <?php foreach (array_slice($import_summary['errors'], 0, 100) as $msg): ?>
                                <li class="text-sm text-yellow-700 dark:text-yellow-400"><?php echo htmlspecialchars($msg); ?></li>
                                <?php endforeach; ?>
                                <?php if (count($import_summary['errors']) > 100): ?>
                                <li class="text-sm text-yellow-700 dark:text-yellow-400">… and <?php echo count($import_summary['errors']) - 100; ?> more.</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($import_summary['credentials'])): ?>
                        <div class="mt-6 border border-gray-200 dark:border-gray-700 rounded-xl p-5">
                            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-key mr-2 text-amber-500"></i>Login credentials</h4>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Staff sign in with their <strong>Staff ID</strong> (or email) and the password below. Save or download this now — passwords are not shown again.</p>
                                </div>
                                <button type="button" onclick="downloadStaffCredentialsCsv()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                    <i class="fas fa-download mr-2"></i>Download CSV
                                </button>
                            </div>
                            <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-xs rounded p-2 mb-3">
                                <i class="fas fa-exclamation-triangle mr-1"></i>Rows that left the password blank were given the default <span class="font-mono font-semibold">Staff@123</span>. Ask staff to change it after first login.
                            </div>
                            <div class="overflow-x-auto max-h-80 overflow-y-auto">
                                <table class="w-full text-sm text-left">
                                    <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
                                        <tr>
                                            <th class="py-2 pr-4">Name</th>
                                            <th class="py-2 pr-4">Staff ID</th>
                                            <th class="py-2 pr-4">Role</th>
                                            <th class="py-2 pr-4">Email</th>
                                            <th class="py-2 pr-4">Password</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($import_summary['credentials'] as $cred): ?>
                                        <tr class="border-b dark:border-gray-700/60">
                                            <td class="py-2 pr-4 text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($cred['name']); ?></td>
                                            <td class="py-2 pr-4 font-mono text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($cred['employee_id']); ?></td>
                                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($cred['role']); ?></td>
                                            <td class="py-2 pr-4 text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($cred['email'] ?: '—'); ?></td>
                                            <td class="py-2 pr-4 font-mono <?php echo $cred['default'] ? 'text-amber-600 dark:text-amber-400' : 'text-gray-800 dark:text-gray-200'; ?>"><?php echo htmlspecialchars($cred['password']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <script>
                            var staffCredentials = <?php echo json_encode($import_summary['credentials'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                            function downloadStaffCredentialsCsv() {
                                var rows = [['Name','Staff ID','Role','Email','Password']];
                                staffCredentials.forEach(function (c) {
                                    rows.push([c.name || '', c.employee_id || '', c.role || '', c.email || '', c.password || '']);
                                });
                                var csv = rows.map(function (r) {
                                    return r.map(function (f) { return '"' + String(f).replace(/"/g, '""') + '"'; }).join(',');
                                }).join('\r\n');
                                var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                                var link = document.createElement('a');
                                link.href = URL.createObjectURL(blob);
                                link.download = 'staff_login_credentials_' + new Date().toISOString().slice(0,10) + '.csv';
                                document.body.appendChild(link); link.click(); document.body.removeChild(link);
                            }
                        </script>
                        <?php endif; ?>

                        <div class="mt-6 flex flex-wrap gap-3">
                            <a href="index.php" class="inline-flex items-center bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-5 py-2.5 rounded-xl font-medium shadow-lg shadow-blue-500/25 transition-all duration-200">
                                <i class="fas fa-users mr-2"></i>Go to Staff Directory
                            </a>
                            <a href="bulk_import.php" class="inline-flex items-center bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-medium transition-colors">
                                <i class="fas fa-redo mr-2"></i>Import Another File
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    <!-- ═══════════════ Upload Form ═══════════════ -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                                <h3 class="text-xl font-bold text-gray-800 dark:text-white">Upload CSV File</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Select a CSV that matches the template format</p>
                            </div>
                            <form action="" method="POST" enctype="multipart/form-data" class="p-6 md:p-8"
                                  x-data="{ fileName: '', dragging: false }">
                                <?php echo csrf_field(); ?>

                                <!-- Drag & drop area -->
                                <label for="import_file"
                                       class="flex flex-col items-center justify-center w-full p-8 border-2 border-dashed rounded-2xl cursor-pointer transition-colors"
                                       :class="dragging ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-gray-300 dark:border-gray-600 hover:border-blue-400 hover:bg-gray-50 dark:hover:bg-gray-700/40'"
                                       @dragover.prevent="dragging = true"
                                       @dragleave.prevent="dragging = false"
                                       @drop.prevent="dragging = false; const f = $event.dataTransfer.files[0]; if (f) { $refs.file.files = $event.dataTransfer.files; fileName = f.name; }">
                                    <div class="w-16 h-16 rounded-full bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center mb-4">
                                        <i class="fas fa-cloud-upload-alt text-3xl text-blue-500"></i>
                                    </div>
                                    <p class="text-gray-700 dark:text-gray-300 font-semibold mb-1" x-show="!fileName">
                                        Drag &amp; drop your CSV here, or <span class="text-blue-600">browse</span>
                                    </p>
                                    <p class="text-blue-600 dark:text-blue-400 font-semibold mb-1" x-show="fileName" x-text="fileName"></p>
                                    <p class="text-xs text-gray-400">CSV only · max 2 MB</p>
                                    <input type="file" id="import_file" name="import_file" accept=".csv" required x-ref="file"
                                           @change="fileName = $event.target.files.length ? $event.target.files[0].name : ''"
                                           class="hidden">
                                </label>

                                <div class="flex flex-col sm:flex-row gap-3 mt-6">
                                    <button type="submit"
                                            class="inline-flex items-center justify-center bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-3 rounded-xl font-semibold shadow-lg shadow-blue-500/25 transition-all duration-200">
                                        <i class="fas fa-upload mr-2"></i>Import Staff
                                    </button>
                                    <a href="bulk_import.php?download=template"
                                       class="inline-flex items-center justify-center bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 px-6 py-3 rounded-xl font-medium transition-colors">
                                        <i class="fas fa-download mr-2 text-emerald-500"></i>Download Template
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- ═══════════════ Instructions ═══════════════ -->
                    <div class="lg:col-span-1">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 overflow-hidden h-full">
                            <div class="p-6 border-b border-gray-100 dark:border-gray-700">
                                <h3 class="text-lg font-bold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-info-circle mr-2 text-blue-500"></i>How it works
                                </h3>
                            </div>
                            <div class="p-6 space-y-4 text-sm text-gray-600 dark:text-gray-400">
                                <div class="flex">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-xs font-bold flex items-center justify-center mr-3">1</span>
                                    <p>Download the template and fill in one staff member per row.</p>
                                </div>
                                <div class="flex">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-xs font-bold flex items-center justify-center mr-3">2</span>
                                    <p>Upload the completed CSV. Rows are validated individually.</p>
                                </div>
                                <div class="flex">
                                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 text-xs font-bold flex items-center justify-center mr-3">3</span>
                                    <p>Review the report. Valid rows are saved; problem rows are skipped and listed.</p>
                                </div>

                                <div class="border-t border-gray-100 dark:border-gray-700 pt-4">
                                    <p class="font-semibold text-gray-700 dark:text-gray-300 mb-2">Required columns</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach (['first_name', 'last_name', 'email', 'role'] as $c): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 text-xs font-mono"><?php echo $c; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div>
                                    <p class="font-semibold text-gray-700 dark:text-gray-300 mb-2">Valid roles</p>
                                    <div class="flex flex-wrap gap-1.5">
                                        <?php foreach (array_keys($role_labels) as $r): ?>
                                        <span class="px-2 py-0.5 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs font-mono"><?php echo $r; ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-3 text-xs">
                                    <p class="text-blue-700 dark:text-blue-300">
                                        <i class="fas fa-key mr-1"></i>
                                        If the <span class="font-mono">password</span> column is blank, the default
                                        <span class="font-mono font-semibold">Staff@123</span> is used. Staff should change it on first login.
                                    </p>
                                </div>
                                <p class="text-xs text-gray-400">
                                    <i class="fas fa-shield-alt mr-1"></i>
                                    Duplicate emails / employee IDs (existing or repeated in the file) are skipped automatically.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
