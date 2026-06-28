<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Make sure optional student profile columns exist before importing into them.
ensureStudentProfileColumns($db);

// ── Importable student fields (mirrors the student_profiles schema and the
//    enroll.php / edit.php forms so bulk import stays consistent). ──────────
$student_fields = [
    'first_name'              => ['label' => 'First Name',                  'required' => true],
    'other_names'             => ['label' => 'Other Name(s)',               'required' => false],
    'last_name'               => ['label' => 'Last Name',                   'required' => true],
    'email'                   => ['label' => 'Email Address',               'required' => true],
    'password'                => ['label' => 'Password',                    'required' => false],
    'student_id'              => ['label' => 'Student ID',                  'required' => false],
    'class'                   => ['label' => 'Class (name or ID)',          'required' => true],
    'admission_date'          => ['label' => 'Admission Date',              'required' => false],
    'admission_number'        => ['label' => 'Admission Number',            'required' => false],
    'date_of_birth'           => ['label' => 'Date of Birth',               'required' => true],
    'gender'                  => ['label' => 'Gender (male/female/other)',  'required' => false],
    'blood_group'             => ['label' => 'Blood Group',                 'required' => false],
    'student_type'            => ['label' => 'Student Type (day/boarding)', 'required' => false],
    'nationality'             => ['label' => 'Nationality',                 'required' => false],
    'religion'                => ['label' => 'Religion',                    'required' => false],
    'address'                 => ['label' => 'Address',                     'required' => false],
    'phone'                   => ['label' => 'Phone Number',                'required' => false],
    'emergency_contact_name'  => ['label' => 'Emergency Contact Name',      'required' => false],
    'emergency_contact_phone' => ['label' => 'Emergency Contact Phone',     'required' => false],
    'guardian_name'           => ['label' => 'Guardian Name',               'required' => false],
    'guardian_phone'          => ['label' => 'Guardian Phone',              'required' => false],
    'guardian_email'          => ['label' => 'Guardian Email',              'required' => false],
    'parent_name'             => ['label' => 'Parent Name',                 'required' => false],
    'parent_phone'            => ['label' => 'Parent Phone',                'required' => false],
    'parent_email'            => ['label' => 'Parent Email',                'required' => false],
    'previous_school'         => ['label' => 'Previous School',             'required' => false],
    'medical_conditions'      => ['label' => 'Medical Conditions/Allergies', 'required' => false],
    'status'                  => ['label' => 'Status (active/inactive)',    'required' => false],
];

// Load active classes (used for validation, the template and instructions).
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes = $db->query($classes_query)->fetchAll(PDO::FETCH_ASSOC);
$class_by_id   = [];
$class_by_name = [];
foreach ($classes as $c) {
    $class_by_id[(int)$c['id']] = $c;
    $class_by_name[strtolower(trim($c['name']))] = $c;
    $class_by_name[strtolower(trim($c['grade_level'] . ' - ' . $c['name']))] = $c;
}
// Use REAL existing class IDs in the sample so the example import works as-is.
$sample_class_ids = array_column($classes, 'id');
$sample_class_1 = $sample_class_ids[0] ?? 1;
$sample_class_2 = $sample_class_ids[1] ?? $sample_class_1;
// A friendly class value for the template example (name preferred, else ID).
$sample_class_value = $classes[0]['name'] ?? (string)$sample_class_1;

$success_count = 0;
$error_count = 0;
$errors = [];
// Login credentials assigned to each imported student, so the admin can hand
// them out (the importer sets a default password when the CSV leaves it blank).
$created_credentials = [];

// ── Handle file upload and processing ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    csrf_require('bulk_import.php');
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = "File upload failed. Please try again.";
    } elseif (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
        $error = "Please upload a .csv file.";
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $error = "The file is too large. Maximum allowed size is 5 MB.";
    } elseif (($handle = fopen($file['tmp_name'], 'r')) === FALSE) {
        $error = "Could not read the uploaded file.";
    } else {
        $header = fgetcsv($handle);
        if ($header === false) {
            $error = "The CSV file is empty.";
            fclose($handle);
        } else {
            // Normalise the header row into a column-name => index map so the
            // column order is flexible.
            if (isset($header[0])) {
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            }
            $col = [];
            foreach ($header as $i => $h) {
                $key = strtolower(trim(str_replace([' ', '-'], '_', (string)$h)));
                if ($key !== '') $col[$key] = $i;
            }
            $get = function ($row, $key) use ($col) {
                if (!isset($col[$key])) return '';
                return trim((string)($row[$col[$key]] ?? ''));
            };

            // Require the core columns (a single "name" column is accepted as a
            // fallback for first_name/last_name for backward compatibility).
            $missing = [];
            if (!isset($col['first_name']) && !isset($col['name'])) $missing[] = 'first_name';
            if (!isset($col['last_name']) && !isset($col['name']))  $missing[] = 'last_name';
            if (!isset($col['email'])) $missing[] = 'email';
            if (!isset($col['class'])) $missing[] = 'class';

            if (!empty($missing)) {
                $error = "The CSV is missing required columns: " . implode(', ', $missing)
                       . ". Download the template for the correct format.";
                fclose($handle);
            } else {
                require_once '../includes/settings_helper.php';
                if (function_exists('isUserRegistrationAllowed') && !isUserRegistrationAllowed()) {
                    $error = "New user registration is currently disabled in System Settings.";
                    fclose($handle);
                } else {
                    require_once '../includes/plan_limits.php';
                    require_once '../includes/user_directory.php';

                    $seen_emails = [];
                    $seen_sids   = [];
                    $row_number  = 1; // header is row 1

                    while (($data = fgetcsv($handle)) !== FALSE) {
                        $row_number++;
                        // Skip completely blank lines.
                        if (count(array_filter($data, fn($v) => trim((string)$v) !== '')) === 0) {
                            continue;
                        }

                        // Resolve the name parts (prefer explicit columns).
                        $first_name = $get($data, 'first_name');
                        $other_names = $get($data, 'other_names');
                        $last_name  = $get($data, 'last_name');
                        if ($first_name === '' && $last_name === '' && $get($data, 'name') !== '') {
                            $parts = preg_split('/\s+/', trim($get($data, 'name')));
                            $n = count($parts);
                            if ($n === 1) { $first_name = $parts[0]; }
                            elseif ($n === 2) { $first_name = $parts[0]; $last_name = $parts[1]; }
                            else { $first_name = $parts[0]; $last_name = $parts[$n - 1]; $other_names = implode(' ', array_slice($parts, 1, $n - 2)); }
                        }

                        $email      = strtolower($get($data, 'email'));
                        $password   = $get($data, 'password');
                        $student_id = $get($data, 'student_id');
                        $class_val  = $get($data, 'class');
                        $admission_date = $get($data, 'admission_date');
                        $admission_number = $get($data, 'admission_number');
                        $date_of_birth = $get($data, 'date_of_birth');
                        $gender     = strtolower($get($data, 'gender'));
                        $blood_group = $get($data, 'blood_group');
                        $student_type = strtolower($get($data, 'student_type'));
                        $nationality = $get($data, 'nationality');
                        $religion   = $get($data, 'religion');
                        $address    = $get($data, 'address');
                        $phone      = $get($data, 'phone');
                        $ec_name    = $get($data, 'emergency_contact_name');
                        $ec_phone   = $get($data, 'emergency_contact_phone');
                        $guardian_name = $get($data, 'guardian_name');
                        $guardian_phone = $get($data, 'guardian_phone');
                        $guardian_email = strtolower($get($data, 'guardian_email'));
                        $parent_name = $get($data, 'parent_name');
                        $parent_phone = $get($data, 'parent_phone');
                        $parent_email = strtolower($get($data, 'parent_email'));
                        $previous_school = $get($data, 'previous_school');
                        $medical_conditions = $get($data, 'medical_conditions');
                        $status     = strtolower($get($data, 'status'));

                        // ── Validation ──
                        if ($first_name === '' || $last_name === '') {
                            $errors[] = "Row $row_number: First name and last name are required."; $error_count++; continue;
                        }
                        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            $errors[] = "Row $row_number: A valid email address is required."; $error_count++; continue;
                        }
                        if ($date_of_birth === '' || !strtotime($date_of_birth)) {
                            $errors[] = "Row $row_number: A valid date of birth is required (YYYY-MM-DD)."; $error_count++; continue;
                        }
                        // Resolve class (accepts a numeric ID or a class name).
                        $resolved_class = null;
                        if (ctype_digit($class_val) && isset($class_by_id[(int)$class_val])) {
                            $resolved_class = $class_by_id[(int)$class_val];
                        } elseif (isset($class_by_name[strtolower($class_val)])) {
                            $resolved_class = $class_by_name[strtolower($class_val)];
                        }
                        if ($resolved_class === null) {
                            $errors[] = "Row $row_number: Class '" . htmlspecialchars($class_val) . "' was not found or is inactive."; $error_count++; continue;
                        }
                        if (isset($seen_emails[$email])) {
                            $errors[] = "Row $row_number: Duplicate email '$email' within the file — skipped."; $error_count++; continue;
                        }
                        $chk = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                        $chk->execute([':email' => $email]);
                        if ($chk->fetchColumn() > 0) {
                            $errors[] = "Row $row_number: A user with email '$email' already exists — skipped."; $error_count++; continue;
                        }

                        // Student ID: auto-generate when blank, else enforce uniqueness.
                        if ($student_id === '') {
                            $student_id = $database->generateStudentId();
                            while (isset($seen_sids[$student_id])) {
                                $student_id = $database->generateStudentId();
                            }
                        } else {
                            if (isset($seen_sids[$student_id])) {
                                $errors[] = "Row $row_number: Duplicate student ID '$student_id' within the file — skipped."; $error_count++; continue;
                            }
                            $sc = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE student_id = :sid");
                            $sc->execute([':sid' => $student_id]);
                            if ($sc->fetchColumn() > 0) {
                                $errors[] = "Row $row_number: Student ID '$student_id' is already in use — skipped."; $error_count++; continue;
                            }
                        }

                        // Enforce the school's subscription plan student capacity per row.
                        $cap = checkStudentCapacity($db, $_SESSION['school_id'] ?? 0, 1);
                        if (!$cap['allowed']) {
                            $errors[] = "Row $row_number: " . planCapacityMessage('student', $cap);
                            $error_count++;
                            break; // capacity will not free up later in this run
                        }

                        // Normalise optional / enum fields.
                        if (!in_array($gender, ['male', 'female', 'other'], true)) $gender = null;
                        if (!in_array($student_type, ['day', 'boarding'], true)) $student_type = 'day';
                        if (!in_array($status, ['active', 'inactive'], true)) $status = 'active';
                        $admission_date = ($admission_date !== '' && strtotime($admission_date)) ? date('Y-m-d', strtotime($admission_date)) : date('Y-m-d');
                        $date_of_birth  = date('Y-m-d', strtotime($date_of_birth));
                        if ($password === '' || strlen($password) < 6) $password = 'Student@123';

                        $name = trim($first_name . ' ' . trim($other_names . ' ' . $last_name));

                        try {
                            $db->beginTransaction();
                            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                            // 1. User account
                            $user_stmt = $db->prepare("INSERT INTO users (name, first_name, other_names, last_name, email, password, role, status, student_id)
                                                       VALUES (:name, :first_name, :other_names, :last_name, :email, :password, 'student', :status, :student_id)");
                            $user_stmt->execute([
                                ':name' => $name,
                                ':first_name' => $first_name,
                                ':other_names' => $other_names ?: null,
                                ':last_name' => $last_name,
                                ':email' => $email,
                                ':password' => $hashed_password,
                                ':status' => $status,
                                ':student_id' => $student_id,
                            ]);
                            $user_id = $db->lastInsertId();

                            // 2. Auto-create a parent account when guardian details are supplied.
                            $parent_id = null;
                            $parent_password = null;
                            if ($guardian_email !== '' && $guardian_name !== '' && filter_var($guardian_email, FILTER_VALIDATE_EMAIL)) {
                                $pchk = $db->prepare("SELECT id, role FROM users WHERE email = :email");
                                $pchk->execute([':email' => $guardian_email]);
                                $existing = $pchk->fetch(PDO::FETCH_ASSOC);
                                if ($existing) {
                                    if ($existing['role'] === 'parent') $parent_id = $existing['id'];
                                } else {
                                    $parent_password = password_hash('parent123', PASSWORD_DEFAULT);
                                    $cp = $db->prepare("INSERT INTO users (name, email, password, role, status, created_at) VALUES (:name, :email, :password, 'parent', 'active', NOW())");
                                    $cp->execute([':name' => $guardian_name, ':email' => $guardian_email, ':password' => $parent_password]);
                                    $parent_id = $db->lastInsertId();
                                }
                            }

                            // 3. Student profile (all schema fields)
                            $profile_stmt = $db->prepare("INSERT INTO student_profiles (
                                user_id, student_id, admission_date, admission_number, student_type,
                                date_of_birth, gender, blood_group, address, phone,
                                emergency_contact_name, emergency_contact_phone, parent_id,
                                guardian_name, guardian_phone, guardian_email,
                                parent_name, parent_phone, parent_email,
                                medical_conditions, previous_school, nationality, religion, class_id
                            ) VALUES (
                                :user_id, :student_id, :admission_date, :admission_number, :student_type,
                                :date_of_birth, :gender, :blood_group, :address, :phone,
                                :ec_name, :ec_phone, :parent_id,
                                :guardian_name, :guardian_phone, :guardian_email,
                                :parent_name, :parent_phone, :parent_email,
                                :medical_conditions, :previous_school, :nationality, :religion, :class_id
                            )");
                            $profile_stmt->execute([
                                ':user_id' => $user_id,
                                ':student_id' => $student_id,
                                ':admission_date' => $admission_date,
                                ':admission_number' => $admission_number ?: null,
                                ':student_type' => $student_type,
                                ':date_of_birth' => $date_of_birth,
                                ':gender' => $gender,
                                ':blood_group' => $blood_group ?: null,
                                ':address' => $address ?: null,
                                ':phone' => $phone ?: null,
                                ':ec_name' => $ec_name ?: null,
                                ':ec_phone' => $ec_phone ?: null,
                                ':parent_id' => $parent_id,
                                ':guardian_name' => $guardian_name ?: null,
                                ':guardian_phone' => $guardian_phone ?: null,
                                ':guardian_email' => $guardian_email ?: null,
                                ':parent_name' => $parent_name ?: null,
                                ':parent_phone' => $parent_phone ?: null,
                                ':parent_email' => $parent_email ?: null,
                                ':medical_conditions' => $medical_conditions ?: null,
                                ':previous_school' => $previous_school ?: null,
                                ':nationality' => $nationality ?: null,
                                ':religion' => $religion ?: null,
                                ':class_id' => $resolved_class['id'],
                            ]);

                            // 4. Active class enrolment
                            $class_stmt = $db->prepare("INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')");
                            $class_stmt->execute([':student_id' => $user_id, ':class_id' => $resolved_class['id']]);

                            // 5. Parent-student relationship
                            if ($parent_id) {
                                $pl = $db->prepare("INSERT INTO parent_students (parent_id, student_id, relationship, is_primary)
                                                    VALUES (:pid, :sid, 'guardian', TRUE)
                                                    ON DUPLICATE KEY UPDATE is_primary = TRUE");
                                $pl->execute([':pid' => $parent_id, ':sid' => $user_id]);
                            }

                            $db->commit();

                            // Mirror new accounts into the central login directory.
                            syncUserToCentralDirectory([
                                'school_id'  => $_SESSION['school_id'] ?? null,
                                'name'       => $name,
                                'email'      => $email,
                                'password'   => $hashed_password,
                                'role'       => 'student',
                                'status'     => $status,
                                'student_id' => $student_id,
                            ]);
                            if ($parent_password !== null) {
                                syncUserToCentralDirectory([
                                    'school_id' => $_SESSION['school_id'] ?? null,
                                    'name'      => $guardian_name,
                                    'email'     => $guardian_email,
                                    'password'  => $parent_password,
                                    'role'      => 'parent',
                                    'status'    => 'active',
                                ]);
                            }

                            $seen_emails[$email]    = true;
                            $seen_sids[$student_id] = true;
                            $created_credentials[] = [
                                'name'       => $name,
                                'student_id' => $student_id,
                                'email'      => $email,
                                'password'   => $password, // plaintext used (CSV value or default)
                                'default'    => ($password === 'Student@123'),
                            ];
                            $success_count++;
                        } catch (PDOException $e) {
                            if ($db->inTransaction()) $db->rollBack();
                            $errors[] = "Row $row_number: Database error - " . $e->getMessage();
                            $error_count++;
                        }
                    }
                    fclose($handle);
                }
            }
        }
    }
}

$title = "Bulk Import Students";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Bulk Import Students</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Students
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <?php if ($success_count > 0 || $error_count > 0): ?>
                <div class="mb-6">
                    <?php if ($success_count > 0): ?>
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        <i class="fas fa-check-circle mr-2"></i>Successfully imported <?php echo $success_count; ?> student<?php echo $success_count === 1 ? '' : 's'; ?>.
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($created_credentials)): ?>
                    <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg p-5 mb-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-white"><i class="fas fa-key mr-2 text-amber-500"></i>Login credentials</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Students sign in with their <strong>Student ID</strong> (or email) and the password below. Save or download this now — passwords are not shown again.</p>
                            </div>
                            <button type="button" onclick="downloadCredentialsCsv()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                <i class="fas fa-download mr-2"></i>Download CSV
                            </button>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-800 dark:text-amber-300 text-xs rounded p-2 mb-3">
                            <i class="fas fa-exclamation-triangle mr-1"></i>Rows that left the password blank were given the default <span class="font-mono font-semibold">Student@123</span>. Ask students to change it after first login.
                        </div>
                        <div class="overflow-x-auto max-h-80 overflow-y-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="text-xs uppercase text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
                                    <tr>
                                        <th class="py-2 pr-4">Name</th>
                                        <th class="py-2 pr-4">Student ID</th>
                                        <th class="py-2 pr-4">Email</th>
                                        <th class="py-2 pr-4">Password</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($created_credentials as $cred): ?>
                                    <tr class="border-b dark:border-gray-700/60">
                                        <td class="py-2 pr-4 text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($cred['name']); ?></td>
                                        <td class="py-2 pr-4 font-mono text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($cred['student_id']); ?></td>
                                        <td class="py-2 pr-4 text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($cred['email'] ?: '—'); ?></td>
                                        <td class="py-2 pr-4 font-mono <?php echo $cred['default'] ? 'text-amber-600 dark:text-amber-400' : 'text-gray-800 dark:text-gray-200'; ?>"><?php echo htmlspecialchars($cred['password']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <script>
                        var importedCredentials = <?php echo json_encode($created_credentials, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                        function downloadCredentialsCsv() {
                            var rows = [['Name','Student ID','Email','Password']];
                            importedCredentials.forEach(function (c) {
                                rows.push([c.name || '', c.student_id || '', c.email || '', c.password || '']);
                            });
                            var csv = rows.map(function (r) {
                                return r.map(function (f) { return '"' + String(f).replace(/"/g, '""') + '"'; }).join(',');
                            }).join('\r\n');
                            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                            var link = document.createElement('a');
                            link.href = URL.createObjectURL(blob);
                            link.download = 'student_login_credentials_' + new Date().toISOString().slice(0,10) + '.csv';
                            document.body.appendChild(link); link.click(); document.body.removeChild(link);
                        }
                    </script>
                    <?php endif; ?>

                    <?php if ($error_count > 0): ?>
                    <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded mb-4">
                        <p class="font-medium"><i class="fas fa-list-ul mr-2"></i><?php echo $error_count; ?> row<?php echo $error_count === 1 ? '' : 's'; ?> skipped:</p>
                        <ul class="mt-2 list-disc list-inside text-sm max-h-64 overflow-y-auto">
                            <?php foreach (array_slice($errors, 0, 50) as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                            <?php if (count($errors) > 50): ?>
                            <li>... and <?php echo count($errors) - 50; ?> more.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Instructions -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6 mb-6">
                    <h2 class="text-lg font-semibold text-blue-900 dark:text-blue-300 mb-3">Import Instructions</h2>
                    <div class="text-blue-800 dark:text-blue-300 space-y-2 text-sm">
                        <p>1. Download the CSV template below and fill in your student data (one student per row).</p>
                        <p>2. The first row must be the header row using the exact column names shown below.</p>
                        <p>3. Required fields: <strong>first_name, last_name, email, class, date_of_birth</strong>.</p>
                        <p>4. <strong>class</strong> accepts a class name or numeric ID and must match an active class (see list below).</p>
                        <p>5. Use the date format <strong>YYYY-MM-DD</strong> for date of birth and admission date.</p>
                        <p>6. <strong>student_id</strong> is auto-generated (e.g. STU<?php echo date('Y'); ?>0001) when left blank.</p>
                        <p>7. If <strong>guardian_name</strong> + <strong>guardian_email</strong> are provided, a linked parent account is auto-created.</p>
                        <p><strong>Note:</strong> Blank passwords default to <strong>Student@123</strong> (students should change it on first login). Auto-created parents use <strong>parent123</strong>.</p>
                        <p><strong>Duplicates:</strong> Existing or repeated emails / student IDs are skipped and listed in the report.</p>
                    </div>
                </div>

                <!-- CSV Template Download -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Download Template</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-4">The template includes every importable column with two example rows:</p>
                    <button onclick="downloadTemplate()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Download CSV Template
                    </button>
                </div>

                <!-- Fields Reference -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">CSV Columns</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Columns marked <span class="text-red-600 font-semibold">*</span> are required. Order is flexible.</p>
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($student_fields as $key => $meta): ?>
                        <span class="px-2.5 py-1 rounded-md text-xs font-mono <?php echo $meta['required']
                            ? 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-800'
                            : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'; ?>"
                            title="<?php echo htmlspecialchars($meta['label']); ?>">
                            <?php echo $key; ?><?php echo $meta['required'] ? ' *' : ''; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Available Classes -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Available Classes</h2>
                    <?php if (!empty($classes)): ?>
                    <p class="text-gray-600 dark:text-gray-400 mb-4">Use the class name or ID in the <span class="font-mono">class</span> column:</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($classes as $class): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3">
                            <div class="font-medium text-gray-900 dark:text-white">ID: <?php echo $class['id']; ?></div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-orange-600"><i class="fas fa-exclamation-triangle mr-1"></i>No active classes exist yet. Create classes before importing students.</p>
                    <?php endif; ?>
                </div>

                <!-- File Upload Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Upload CSV File</h2>
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <?php echo csrf_field(); ?>
                        <div>
                            <label for="csv_file" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Select CSV File
                            </label>
                            <input type="file" id="csv_file" name="csv_file" accept=".csv" required
                                class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Only CSV files are allowed. Maximum file size: 5MB</p>
                        </div>

                        <div class="flex items-center">
                            <input type="checkbox" id="confirm" name="confirm" required class="mr-2">
                            <label for="confirm" class="text-sm text-gray-700 dark:text-gray-300">
                                I confirm that the data is accurate and I want to proceed with the import
                            </label>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-upload mr-2"></i>Import Students
                        </button>
                    </form>
                </div>

                <!-- Sample Data Format -->
                <div class="mt-6 bg-gray-50 dark:bg-gray-800 rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Sample Data Format</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm whitespace-nowrap">
                            <thead>
                                <tr class="bg-gray-200 dark:bg-gray-700">
                                    <?php foreach (array_keys($student_fields) as $key): ?>
                                    <th class="px-3 py-2 text-left font-mono text-xs"><?php echo $key; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody class="text-gray-700 dark:text-gray-300">
                                <tr class="border-b dark:border-gray-700">
                                    <td class="px-3 py-2">Ama</td><td class="px-3 py-2">Serwaa</td><td class="px-3 py-2">Owusu</td>
                                    <td class="px-3 py-2">ama.owusu@email.com</td><td class="px-3 py-2"></td><td class="px-3 py-2"></td>
                                    <td class="px-3 py-2"><?php echo htmlspecialchars($sample_class_value); ?></td><td class="px-3 py-2"><?php echo date('Y-m-d'); ?></td><td class="px-3 py-2"></td>
                                    <td class="px-3 py-2">2012-03-15</td><td class="px-3 py-2">female</td><td class="px-3 py-2">O+</td>
                                    <td class="px-3 py-2">day</td><td class="px-3 py-2">Ghanaian</td><td class="px-3 py-2">Christianity</td>
                                    <td class="px-3 py-2">12 Palm St, Accra</td><td class="px-3 py-2">+233200000001</td>
                                    <td class="px-3 py-2">Akosua Owusu</td><td class="px-3 py-2">+233200000002</td>
                                    <td class="px-3 py-2">Akosua Owusu</td><td class="px-3 py-2">+233200000002</td><td class="px-3 py-2">akosua@email.com</td>
                                    <td class="px-3 py-2">Kwabena Owusu</td><td class="px-3 py-2">+233200000003</td><td class="px-3 py-2">kwabena@email.com</td>
                                    <td class="px-3 py-2">Little Stars Academy</td><td class="px-3 py-2">None</td><td class="px-3 py-2">active</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function downloadTemplate() {
    const headers = <?php echo json_encode(array_keys($student_fields)); ?>;
    const sampleData = [
        ['Ama', 'Serwaa', 'Owusu', 'ama.owusu@email.com', '', '', '<?php echo addslashes($sample_class_value); ?>', '<?php echo date('Y-m-d'); ?>', '', '2012-03-15', 'female', 'O+', 'day', 'Ghanaian', 'Christianity', '12 Palm Street, Accra', '+233200000001', 'Akosua Owusu', '+233200000002', 'Akosua Owusu', '+233200000002', 'akosua.owusu@email.com', 'Kwabena Owusu', '+233200000003', 'kwabena.owusu@email.com', 'Little Stars Academy', 'None', 'active'],
        ['Kofi', '', 'Mensah', 'kofi.mensah@email.com', '', '', '<?php echo addslashes((string)$sample_class_2); ?>', '<?php echo date('Y-m-d'); ?>', '', '2011-08-22', 'male', 'A+', 'boarding', 'Ghanaian', 'Islam', '456 Oak Ave, Kumasi', '+233111222333', 'Yaa Mensah', '+233444555666', 'Yaa Mensah', '+233444555666', 'yaa.mensah@email.com', 'Robert Mensah', '+233444555777', 'robert.mensah@email.com', 'Bright Future School', 'Asthma', 'active']
    ];

    let csvContent = headers.join(',') + '\n';
    sampleData.forEach(row => {
        csvContent += row.map(field => `"${String(field).replace(/"/g, '""')}"`).join(',') + '\n';
    });

    const blob = new Blob(['﻿' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'student_import_template.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    window.URL.revokeObjectURL(url);
}
</script>
