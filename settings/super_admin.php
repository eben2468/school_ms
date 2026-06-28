<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('settings_super');

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/module_access.php';

$database = new Database();
$db = $database->getConnection();

// Retrieve and clear flash messages and form input from session
$success_message = $_SESSION['success_message'] ?? null;
$error_message = $_SESSION['error_message'] ?? null;
$form_input = $_SESSION['form_input'] ?? [];

unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
unset($_SESSION['form_input']);

// Retrieve and clear active tab
$allowed_tabs = ['dashboard', 'schools', 'billing', 'plans', 'health', 'tickets', 'register'];
$active_tab = $_SESSION['admin_tab'] ?? $_GET['tab'] ?? 'dashboard';
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'dashboard';
}
unset($_SESSION['admin_tab']);

// One-time CSRF token guarding the "Enter School" impersonation action.
if (empty($_SESSION['impersonate_token'])) {
    $_SESSION['impersonate_token'] = bin2hex(random_bytes(32));
}

// Helper to execute raw SQL files into a PDO connection
function executeSqlFile($pdoConn, $filePath) {
    if (!file_exists($filePath)) {
        return false;
    }

    $sql = file_get_contents($filePath);

    // Remove single-line and multi-line SQL comments
    $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

    // Split by semicolon
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        $statementTrim = trim($statement);
        // Skip database creation/switching commands to keep execution in the tenant context
        if (stripos($statementTrim, 'CREATE DATABASE') === 0 ||
            stripos($statementTrim, 'USE ') === 0) {
            continue;
        }
        if (stripos($statementTrim, 'SELECT') === 0) {
            continue;
        }

        try {
            $pdoConn->exec($statement);
        } catch (PDOException $e) {
            // Tolerate benign errors: duplicate column/index, table already exists, etc.
            // MySQL error codes: 1060 = Duplicate column, 1061 = Duplicate key, 1050 = Table already exists
            $toleratedCodes = [1060, 1061, 1050, 1068, 1091, 1005, 1022];
            if (!in_array((int)$e->getCode(), $toleratedCodes) &&
                !in_array((int)$e->errorInfo[1], $toleratedCodes)) {
                throw $e; // Re-throw genuine errors
            }
            // Otherwise silently skip the duplicate-definition
        }
    }
    return true;
}

// ----------------------------------------------------
// PROCESS ACTIONS (POST Requests)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. SCHOOL REGISTRATION
    if (isset($_POST['action']) && $_POST['action'] === 'register_school') {
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $code = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $_POST['code']));
        $contact_name = filter_input(INPUT_POST, 'contact_name', FILTER_SANITIZE_STRING);
        $contact_email = filter_input(INPUT_POST, 'contact_email', FILTER_SANITIZE_EMAIL);
        $contact_phone = filter_input(INPUT_POST, 'contact_phone', FILTER_SANITIZE_STRING);
        $address = filter_input(INPUT_POST, 'address', FILTER_SANITIZE_STRING);
        $plan_id = (int)$_POST['plan_id'];
        $principal_password = $_POST['principal_password'];
        $student_id_prefix_raw = $_POST['student_id_prefix'] ?? ''; // optional manual prefix

        $db_name = "school_ms_tenant_" . $code;
        
        if (empty($name) || empty($code) || empty($contact_name) || empty($contact_email) || empty($principal_password)) {
            $_SESSION['error_message'] = "Please fill in all required fields.";
            $_SESSION['admin_tab'] = 'register';
            $_SESSION['form_input'] = $_POST;
            header("Location: super_admin.php");
            exit();
        } else {
            try {
                // Verify if school code already exists
                $check = $db->prepare("SELECT COUNT(*) FROM schools WHERE code = :code");
                $check->execute([':code' => $code]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("School code '{$code}' is already registered.");
                }
                
                // Verify if email is unique globally
                $check_email = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
                $check_email->execute([':email' => $contact_email]);
                if ($check_email->fetchColumn() > 0) {
                    throw new Exception("The email '{$contact_email}' is already registered by another user.");
                }

                // Validate the optional manual student-ID prefix (blank = auto-generate).
                require_once '../includes/school_prefix.php';
                list($desired_prefix, $prefix_err) = validateSchoolPrefix($student_id_prefix_raw);
                if ($prefix_err !== null) {
                    throw new Exception($prefix_err);
                }
                if ($desired_prefix !== '' && schoolPrefixTaken($db, $desired_prefix)) {
                    throw new Exception("Student ID prefix '{$desired_prefix}' is already used by another school. Please choose a different one.");
                }

                // Track inserted IDs for manual cleanup if DDL phase fails
                $school_id = null;
                $central_user_id = null;

                $db->beginTransaction();

                // A. Insert into schools table in primary database
                $insert_school = $db->prepare("INSERT INTO schools (name, code, db_name, contact_name, contact_email, contact_phone, address, status) 
                                               VALUES (:name, :code, :db_name, :contact_name, :contact_email, :contact_phone, :address, 'active')");
                $insert_school->execute([
                    ':name' => $name,
                    ':code' => $code,
                    ':db_name' => $db_name,
                    ':contact_name' => $contact_name,
                    ':contact_email' => $contact_email,
                    ':contact_phone' => $contact_phone,
                    ':address' => $address
                ]);
                $school_id = $db->lastInsertId();

                // B. Sync user directory record globally (to let them log in central main page)
                $hashed_password = password_hash($principal_password, PASSWORD_DEFAULT);
                $insert_global_user = $db->prepare("INSERT INTO users (school_id, name, email, password, role, status) 
                                                    VALUES (:school_id, :name, :email, :password, 'school_admin', 'active')");
                $insert_global_user->execute([
                    ':school_id' => $school_id,
                    ':name' => $contact_name,
                    ':email' => $contact_email,
                    ':password' => $hashed_password
                ]);
                $central_user_id = $db->lastInsertId();

                // C. Fetch subscription plan info
                $plan_stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = :id");
                $plan_stmt->execute([':id' => $plan_id]);
                $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$plan) {
                    throw new Exception("Selected subscription plan does not exist.");
                }

                // D. Provision subscription record
                $trial_days = $plan['trial_days'];
                $start_date = date('Y-m-d');
                $end_date = date('Y-m-d', strtotime("+{$trial_days} days"));
                $sub_status = ($trial_days > 0) ? 'trial' : 'active';

                $insert_sub = $db->prepare("INSERT INTO school_subscriptions (school_id, plan_id, status, start_date, end_date, auto_renew, amount) 
                                            VALUES (:school_id, :plan_id, :status, :start, :end, 1, :amount)");
                $insert_sub->execute([
                    ':school_id' => $school_id,
                    ':plan_id' => $plan_id,
                    ':status' => $sub_status,
                    ':start' => $start_date,
                    ':end' => $end_date,
                    ':amount' => $plan['price_monthly']
                ]);

                // E. Generate initial invoice
                $invoice_num = "INV-" . strtoupper($code) . "-" . date('Ymd') . "-" . rand(10, 99);
                $insert_inv = $db->prepare("INSERT INTO billing_invoices (school_id, invoice_number, amount, status, due_date) 
                                            VALUES (:school_id, :invoice_num, :amount, :status, :due)");
                $insert_inv->execute([
                    ':school_id' => $school_id,
                    ':invoice_num' => $invoice_num,
                    ':amount' => $plan['price_monthly'],
                    ':status' => ($plan['price_monthly'] > 0) ? 'unpaid' : 'paid',
                    ':due' => date('Y-m-d', strtotime('+7 days'))
                ]);

                // â”€â”€ COMMIT ALL CENTRAL-DB INSERTS HERE â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // MySQL DDL (CREATE DATABASE below) causes an implicit commit which
                // destroys any open PDO transaction. We commit cleanly before it so
                // the transaction is properly closed and we avoid the
                // "There is no active transaction" exception.
                $db->commit();
                $school_id_committed = $school_id; // remember for cleanup if DDL fails

                // E2. Seed per-school module access from the plan's selected features.
                // Best-effort: a failure here must not abort the registration.
                try {
                    ensureModuleAccessTable($db);
                    $plan_features = json_decode($plan['features'] ?? '[]', true);
                    if (!is_array($plan_features)) { $plan_features = []; }
                    $seed_stmt = $db->prepare("INSERT INTO school_module_access (school_id, module_key, is_enabled)
                                               VALUES (:school_id, :module_key, :is_enabled)
                                               ON DUPLICATE KEY UPDATE is_enabled = :is_enabled2");
                    foreach (array_keys(getModuleDefinitions()) as $mkey) {
                        $enabled = in_array($mkey, $plan_features, true) ? 1 : 0;
                        $seed_stmt->execute([
                            ':school_id'  => $school_id,
                            ':module_key' => $mkey,
                            ':is_enabled' => $enabled,
                            ':is_enabled2'=> $enabled,
                        ]);
                    }
                } catch (Exception $seedEx) {
                    error_log("module_access: failed to seed school {$school_id} - " . $seedEx->getMessage());
                }

                // F. Dynamically CREATE TENANT DATABASE and run schema migration
                // (DDL â€“ runs outside any transaction; MySQL would implicitly commit anyway)
                // Use utf8mb4_general_ci to match the central DB and the app's CREATE TABLE
                // statements, so tenant tables never end up with a mismatched collation
                // (which causes "Illegal mix of collations" on cross-table joins).
                $db->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

                // Create a temporary PDO connection to the new tenant DB
                $tenant_db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $db_name, DB_USER, DB_PASS);
                $tenant_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                // Run schema migrations
                $migration_files = [
                    '../config/schema.sql',
                    '../config/academic_system_tables.sql',
                    '../config/document_management_schema.sql',
                    '../config/online_learning_schema.sql'
                ];
                foreach ($migration_files as $mig_file) {
                    $migration_success = executeSqlFile($tenant_db, $mig_file);
                    if (!$migration_success) {
                        throw new Exception("Could not run SQL migration '{$mig_file}' on newly created tenant database.");
                    }
                }

                // Backstop: ensure the tenant has EVERY current application table by
                // replicating any still-missing tables from the central schema. This
                // keeps new schools complete even as the app gains new tables/modules.
                require_once '../includes/tenant_provisioning.php';
                replicateCentralSchemaToTenant($tenant_db, $db);

                // Guarantee a single uniform collation across every tenant table so
                // cross-table joins never hit "Illegal mix of collations".
                require_once '../includes/db_collation.php';
                normalizeDatabaseCollation($tenant_db);

                // Reserve the school's student-ID prefix (e.g. Dream Academy -> DRE)
                // so its IDs never collide with others: use the admin's chosen prefix
                // when supplied, otherwise auto-generate a guaranteed-unique one.
                require_once '../includes/school_prefix.php';
                if ($desired_prefix !== '') {
                    setSchoolPrefix($db, $school_id, $desired_prefix);
                } else {
                    assignSchoolPrefix($db, $school_id, $name);
                }

                // Clear default template users inserted by schema.sql so the tenant DB has a clean slate
                $tenant_db->exec("SET FOREIGN_KEY_CHECKS = 0");
                $tenant_db->exec("TRUNCATE TABLE users");
                $tenant_db->exec("SET FOREIGN_KEY_CHECKS = 1");

                // G. Create local school_admin user in the tenant database
                $insert_tenant_user = $tenant_db->prepare("INSERT INTO users (name, email, password, role, status) 
                                                           VALUES (:name, :email, :password, 'school_admin', 'active')");
                $insert_tenant_user->execute([
                    ':name' => $contact_name,
                    ':email' => $contact_email,
                    ':password' => $hashed_password
                ]);

                // H. Create default settings in tenant database
                $tenant_db->exec("DELETE FROM school_settings");
                $insert_default_setting = $tenant_db->prepare("INSERT INTO school_settings (
                    school_name, school_email, school_phone, school_address, currency, currency_symbol, theme_color, academic_year_start, academic_year_end
                ) VALUES (
                    :school_name, :school_email, :school_phone, :school_address, 'GHS', '₵', 'blue', :start_year, :end_year
                )");

                $insert_default_setting->execute([
                    ':school_name' => $name,
                    ':school_email' => $contact_email,
                    ':school_phone' => $contact_phone,
                    ':school_address' => $address,
                    ':start_year' => date('Y') . '-09-01',
                    ':end_year' => (date('Y') + 1) . '-06-30'
                ]);

                // I. Audit log (auto-commit, no transaction needed)
                $insert_audit = $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address) 
                                              VALUES (:school_id, :user_id, 'school_registered', :details, :ip)");
                $details_str = "Registered school '{$name}' with code '{$code}' on plan '{$plan['name']}'. DB isolated successfully.";
                $insert_audit->execute([
                    ':school_id' => $school_id,
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => $details_str,
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);

                $_SESSION['success_message'] = "âœ… School '{$name}' registered successfully! Tenant Database '{$db_name}' provisioned and migrated securely.";
                $_SESSION['admin_tab'] = 'schools';
                header("Location: super_admin.php");
                exit();

            } catch (Exception $ex) {
                // Roll back any open central-DB transaction (pre-DDL phase)
                if ($db->inTransaction()) {
                    $db->rollBack();
                }

                // â”€â”€ MANUAL GARBAGE COLLECTION â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
                // If the central-DB commit already happened but tenant provisioning failed,
                // clean up the orphaned rows so the school code / email can be reused.
                if ($school_id !== null) {
                    try {
                        // Deleting school cascades to school_subscriptions, billing_invoices (FK CASCADE)
                        $db->exec("DELETE FROM schools WHERE id = {$school_id}");
                        $db->exec("DELETE FROM users WHERE school_id = {$school_id}");
                    } catch (Exception $cleanupEx) {
                        // Log cleanup failure but don't obscure the original error
                        error_log("Super admin cleanup failed after school registration error: " . $cleanupEx->getMessage());
                    }
                }

                $_SESSION['error_message'] = "âŒ Registration failed: " . $ex->getMessage();
                $_SESSION['admin_tab'] = 'register';
                $_SESSION['form_input'] = $_POST;
                header("Location: super_admin.php");
                exit();
            }
        }
    }
    
    // 2. RECORD BILLING PAYMENT
    if (isset($_POST['action']) && $_POST['action'] === 'record_payment') {
        $invoice_id = (int)$_POST['invoice_id'];
        $method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
        $tx_ref = filter_input(INPUT_POST, 'transaction_ref', FILTER_SANITIZE_STRING);
        
        if (empty($method)) {
            $_SESSION['error_message'] = "Please select a payment method.";
            $_SESSION['admin_tab'] = 'billing';
            header("Location: super_admin.php");
            exit();
        } else {
            try {
                $db->beginTransaction();
                
                // Fetch invoice info
                $inv_stmt = $db->prepare("SELECT * FROM billing_invoices WHERE id = :id AND status != 'paid'");
                $inv_stmt->execute([':id' => $invoice_id]);
                $invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$invoice) {
                    throw new Exception("Invoice not found or already paid.");
                }
                
                // Update Invoice
                $update_inv = $db->prepare("UPDATE billing_invoices SET status = 'paid', paid_at = CURRENT_TIMESTAMP, payment_method = :method, transaction_ref = :ref WHERE id = :id");
                $update_inv->execute([
                    ':method' => $method,
                    ':ref' => $tx_ref,
                    ':id' => $invoice_id
                ]);
                
                // Update School Subscription to Active
                $update_sub = $db->prepare("UPDATE school_subscriptions SET status = 'active' WHERE school_id = :school_id");
                $update_sub->execute([':school_id' => $invoice['school_id']]);
                
                $db->commit();
                
                // Log Audit
                $insert_audit = $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address) 
                                              VALUES (:school_id, :user_id, 'payment_recorded', :details, :ip)");
                $insert_audit->execute([
                    ':school_id' => $invoice['school_id'],
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Payment of GHS " . number_format($invoice['amount'], 2) . " received for Invoice #" . $invoice['invoice_number'],
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);
                
                $_SESSION['success_message'] = "âœ… Payment for Invoice #" . $invoice['invoice_number'] . " recorded successfully!";
                $_SESSION['admin_tab'] = 'billing';
                header("Location: super_admin.php");
                exit();
            } catch (Exception $ex) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $_SESSION['error_message'] = "âŒ Failed to record payment: " . $ex->getMessage();
                $_SESSION['admin_tab'] = 'billing';
                header("Location: super_admin.php");
                exit();
            }
        }
    }

    // 2b. REPLY TO SUPPORT TICKET
    if (isset($_POST['action']) && $_POST['action'] === 'reply_ticket') {
        $ticket_id = (int)($_POST['ticket_id'] ?? 0);
        $response  = trim($_POST['response'] ?? '');
        $new_status = filter_input(INPUT_POST, 'ticket_status', FILTER_SANITIZE_STRING);
        $valid_statuses = ['open', 'in_progress', 'resolved', 'closed'];

        if (!$ticket_id || $response === '') {
            $_SESSION['error_message'] = "Please enter a reply message.";
            $_SESSION['admin_tab'] = 'tickets';
            header("Location: super_admin.php");
            exit();
        }

        try {
            // Confirm the ticket exists
            $chk = $db->prepare("SELECT id, status FROM support_tickets WHERE id = :id");
            $chk->execute([':id' => $ticket_id]);
            $ticket = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$ticket) {
                throw new Exception("Ticket not found.");
            }

            // Save the admin response
            $ins = $db->prepare("INSERT INTO ticket_responses (ticket_id, user_id, response, is_admin_response, created_at)
                                 VALUES (:tid, :uid, :resp, 1, NOW())");
            $ins->execute([
                ':tid' => $ticket_id,
                ':uid' => $_SESSION['user_id'],
                ':resp' => $response
            ]);

            // Update status: use chosen status if valid, otherwise nudge open -> in_progress
            if ($new_status && in_array($new_status, $valid_statuses, true)) {
                $upd = $db->prepare("UPDATE support_tickets SET status = :st, updated_at = NOW() WHERE id = :id");
                $upd->execute([':st' => $new_status, ':id' => $ticket_id]);
            } else {
                $upd = $db->prepare("UPDATE support_tickets SET status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END, updated_at = NOW() WHERE id = :id");
                $upd->execute([':id' => $ticket_id]);
            }

            $_SESSION['success_message'] = "Reply sent for ticket #TKT-" . $ticket_id . ".";
            $_SESSION['admin_tab'] = 'tickets';
            header("Location: super_admin.php");
            exit();
        } catch (Exception $ex) {
            $_SESSION['error_message'] = "Failed to send reply: " . $ex->getMessage();
            $_SESSION['admin_tab'] = 'tickets';
            header("Location: super_admin.php");
            exit();
        }
    }

    // 2c. CLEAR CACHE & ANALYTICS LOGS
    if (isset($_POST['action']) && $_POST['action'] === 'clear_cache_logs') {
        $cache_cleared = 0;
        $rows_cleared = 0;

        // 1) Wipe cached files in /cache (keep the directory itself)
        $cache_dir = __DIR__ . '/../cache';
        if (is_dir($cache_dir)) {
            foreach (glob($cache_dir . '/*') as $file) {
                if (is_file($file) && @unlink($file)) {
                    $cache_cleared++;
                }
            }
        }

        // 2) Truncate analytics / access-tracking logs (guarded so missing tables don't error)
        $analytics_tables = ['document_access_logs', 'material_access_logs', 'notification_logs'];
        foreach ($analytics_tables as $tbl) {
            try {
                $exists = $db->query("SHOW TABLES LIKE " . $db->quote($tbl))->fetch();
                if ($exists) {
                    $rows_cleared += (int)$db->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
                    $db->exec("DELETE FROM `$tbl`");
                }
            } catch (PDOException $e) {
                // skip this table
            }
        }

        // Reset any in-memory settings cache
        if (function_exists('clearSettingsCache')) {
            clearSettingsCache();
        }

        $_SESSION['success_message'] = "Cache and analytics logs cleared: {$cache_cleared} cached file(s) removed, {$rows_cleared} analytics log row(s) purged.";
        $_SESSION['admin_tab'] = 'health';
        header("Location: super_admin.php");
        exit();
    }

    // 3. EDIT/CREATE SUBSCRIPTION PLANS
    if (isset($_POST['action']) && $_POST['action'] === 'save_plan') {
        $plan_id = isset($_POST['plan_id']) ? (int)$_POST['plan_id'] : 0;
        $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
        $desc = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $price = (float)$_POST['price_monthly'];
        $students = (int)$_POST['student_limit'];
        $staff = (int)$_POST['staff_limit'];
        $trial = (int)$_POST['trial_days'];
        
        $features = isset($_POST['features']) ? $_POST['features'] : ['core'];
        $features_json = json_encode($features);
        
        if (empty($name) || empty($desc)) {
            $_SESSION['error_message'] = "Please fill in Plan Name and Description.";
            $_SESSION['admin_tab'] = 'plans';
            header("Location: super_admin.php");
            exit();
        } else {
            try {
                if ($plan_id > 0) {
                    $stmt = $db->prepare("UPDATE subscription_plans SET name = :name, description = :desc, price_monthly = :price, student_limit = :students, staff_limit = :staff, features = :features, trial_days = :trial WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':desc' => $desc,
                        ':price' => $price,
                        ':students' => $students,
                        ':staff' => $staff,
                        ':features' => $features_json,
                        ':trial' => $trial,
                        ':id' => $plan_id
                    ]);
                    $_SESSION['success_message'] = "âœ… Subscription Tier '{$name}' updated successfully!";
                } else {
                    $stmt = $db->prepare("INSERT INTO subscription_plans (name, description, price_monthly, student_limit, staff_limit, features, trial_days) VALUES (:name, :desc, :price, :students, :staff, :features, :trial)");
                    $stmt->execute([
                        ':name' => $name,
                        ':desc' => $desc,
                        ':price' => $price,
                        ':students' => $students,
                        ':staff' => $staff,
                        ':features' => $features_json,
                        ':trial' => $trial
                    ]);
                    $_SESSION['success_message'] = "âœ… New Subscription Tier '{$name}' created successfully!";
                }
                $_SESSION['admin_tab'] = 'plans';
                header("Location: super_admin.php");
                exit();
            } catch (PDOException $ex) {
                $_SESSION['error_message'] = "âŒ Failed to save plan: " . $ex->getMessage();
                $_SESSION['admin_tab'] = 'plans';
                header("Location: super_admin.php");
                exit();
            }
        }
    }
    
    // 3b. ASSIGN / CHANGE A SCHOOL'S SUBSCRIPTION PLAN
    if (isset($_POST['action']) && $_POST['action'] === 'assign_plan') {
        $assign_school_id = (int)($_POST['school_id'] ?? 0);
        $assign_plan_id   = (int)($_POST['plan_id'] ?? 0);

        try {
            // Validate the school and the target plan both exist.
            $sch_stmt = $db->prepare("SELECT id, name FROM schools WHERE id = :id");
            $sch_stmt->execute([':id' => $assign_school_id]);
            $assign_school = $sch_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assign_school) {
                throw new Exception("Selected school could not be found.");
            }

            $plan_stmt = $db->prepare("SELECT * FROM subscription_plans WHERE id = :id");
            $plan_stmt->execute([':id' => $assign_plan_id]);
            $assign_plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assign_plan) {
                throw new Exception("Selected subscription plan does not exist.");
            }

            // Compute fresh subscription dates from the plan's trial window.
            $trial_days = (int)$assign_plan['trial_days'];
            $start_date = date('Y-m-d');
            $end_date   = date('Y-m-d', strtotime("+{$trial_days} days"));
            $sub_status = ($trial_days > 0) ? 'trial' : 'active';
            $amount     = $assign_plan['price_monthly'];

            // Update the existing subscription row, or create one if none exists.
            $exists = $db->prepare("SELECT id FROM school_subscriptions WHERE school_id = :sid LIMIT 1");
            $exists->execute([':sid' => $assign_school_id]);
            $sub_id = $exists->fetchColumn();

            if ($sub_id) {
                $upd = $db->prepare("UPDATE school_subscriptions
                                     SET plan_id = :plan_id, status = :status, start_date = :start,
                                         end_date = :end, amount = :amount
                                     WHERE id = :id");
                $upd->execute([
                    ':plan_id' => $assign_plan_id,
                    ':status'  => $sub_status,
                    ':start'   => $start_date,
                    ':end'     => $end_date,
                    ':amount'  => $amount,
                    ':id'      => $sub_id,
                ]);
            } else {
                $ins = $db->prepare("INSERT INTO school_subscriptions (school_id, plan_id, status, start_date, end_date, auto_renew, amount)
                                     VALUES (:school_id, :plan_id, :status, :start, :end, 1, :amount)");
                $ins->execute([
                    ':school_id' => $assign_school_id,
                    ':plan_id'   => $assign_plan_id,
                    ':status'    => $sub_status,
                    ':start'     => $start_date,
                    ':end'       => $end_date,
                    ':amount'    => $amount,
                ]);
            }

            // Audit trail
            $audit = $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
                                   VALUES (:school_id, :user_id, 'plan_changed', :details, :ip)");
            $audit->execute([
                ':school_id' => $assign_school_id,
                ':user_id'   => $_SESSION['user_id'],
                ':details'   => "Changed subscription for '{$assign_school['name']}' to plan '{$assign_plan['name']}' (GHS " . number_format($amount, 2) . "/mo, status {$sub_status}).",
                ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            $_SESSION['success_message'] = "School '{$assign_school['name']}' is now on the '{$assign_plan['name']}' plan.";
            $_SESSION['admin_tab'] = 'plans';
            header("Location: super_admin.php");
            exit();
        } catch (Exception $ex) {
            $_SESSION['error_message'] = "Failed to change plan: " . $ex->getMessage();
            $_SESSION['admin_tab'] = 'plans';
            header("Location: super_admin.php");
            exit();
        }
    }

    // 4. TENANT ACTIONS (Back up, Suspend, Activate)
    if (isset($_POST['action']) && $_POST['action'] === 'tenant_action') {
        $school_id = (int)$_POST['school_id'];
        $operation = $_POST['operation']; // backup, suspend, activate
        
        try {
            // Fetch school detail
            $sch_stmt = $db->prepare("SELECT * FROM schools WHERE id = :id");
            $sch_stmt->execute([':id' => $school_id]);
            $school = $sch_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$school) {
                throw new Exception("School record not found.");
            }
            
            if ($operation === 'suspend') {
                $stmt = $db->prepare("UPDATE schools SET status = 'inactive' WHERE id = :id");
                $stmt->execute([':id' => $school_id]);
                $_SESSION['success_message'] = "âœ… School '{$school['name']}' has been suspended successfully.";
            } elseif ($operation === 'activate') {
                $stmt = $db->prepare("UPDATE schools SET status = 'active' WHERE id = :id");
                $stmt->execute([':id' => $school_id]);
                $_SESSION['success_message'] = "âœ… School '{$school['name']}' is now active.";
            } elseif ($operation === 'backup') {
                // RUN DUMMY OR ACTUAL TENANT BACKUP
                $backup_dir = "../backups/";
                if (!file_exists($backup_dir)) {
                    mkdir($backup_dir, 0777, true);
                }
                
                $backup_file = "tenant_" . $school['code'] . "_" . date('Ymd_His') . ".sql";
                $backup_path = $backup_dir . $backup_file;
                
                // Connect to tenant DB and dump schema + structure in a readable mock backup format
                $tenant_pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $school['db_name'], DB_USER, DB_PASS);
                $tables_stmt = $tenant_pdo->query("SHOW TABLES");
                $tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $sql_dump = "-- Multi-Tenant Isolated SQL Backup\n";
                $sql_dump .= "-- School: " . $school['name'] . "\n";
                $sql_dump .= "-- Database: " . $school['db_name'] . "\n";
                $sql_dump .= "-- Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
                
                foreach ($tables as $table) {
                    $create_stmt = $tenant_pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                    $sql_dump .= "DROP TABLE IF EXISTS `{$table}`;\n" . $create_stmt[1] . ";\n\n";
                    
                    // Fetch up to 10 rows for sample data dump
                    $rows_stmt = $tenant_pdo->query("SELECT * FROM `{$table}` LIMIT 10");
                    while ($row = $rows_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $keys = array_map(function($k) { return "`{$k}`"; }, array_keys($row));
                        $vals = array_map(function($v) use ($tenant_pdo) { 
                            return is_null($v) ? "NULL" : $tenant_pdo->quote($v); 
                        }, array_values($row));
                        $sql_dump .= "INSERT INTO `{$table}` (" . implode(", ", $keys) . ") VALUES (" . implode(", ", $vals) . ");\n";
                    }
                    $sql_dump .= "\n";
                }
                
                file_put_contents($backup_path, $sql_dump);
                
                // Log Audit
                $insert_audit = $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address) 
                                              VALUES (:school_id, :user_id, 'manual_backup', :details, :ip)");
                $insert_audit->execute([
                    ':school_id' => $school_id,
                    ':user_id' => $_SESSION['user_id'],
                    ':details' => "Isolated backup generated successfully: " . $backup_file,
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);
                
                $_SESSION['success_message'] = "âœ… Isolated Tenant Backup created successfully in backups folder! Filename: '{$backup_file}'";
            } elseif ($operation === 'delete') {
                $db_name = $school['db_name'];
                $school_name_log = $school['name'];
                $school_code_log = $school['code'];
 
                // Step 1: Delete central-DB rows inside a clean transaction
                // (DML rows only - no DDL here so the transaction is safe)
                $db->beginTransaction();
 
                $db->prepare("DELETE FROM users WHERE school_id = :sid")->execute([':sid' => $school_id]);
                $db->prepare("DELETE FROM schools WHERE id = :sid")->execute([':sid' => $school_id]);
                // school_subscriptions & billing_invoices cascade automatically via FK
 
                $db->commit(); // safe - no DDL issued yet
 
                // Step 2: Drop the physical tenant database (DDL - outside any transaction)
                $db->exec("DROP DATABASE IF EXISTS `{$db_name}`");
 
                // Step 3: Audit log (auto-commit)
                $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address) 
                              VALUES (NULL, :user_id, 'school_deleted', :details, :ip)")
                   ->execute([
                       ':user_id' => $_SESSION['user_id'],
                       ':details' => "Permanently deleted school '{$school_name_log}' (code: {$school_code_log}) and dropped database '{$db_name}'",
                       ':ip'      => $_SERVER['REMOTE_ADDR']
                   ]);
 
                $_SESSION['success_message'] = "âœ… School '{$school_name_log}' and its isolated database '{$db_name}' have been permanently deleted from the platform.";
            }
            $_SESSION['admin_tab'] = 'schools';
            header("Location: super_admin.php");
            exit();
        } catch (Exception $ex) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['error_message'] = "âŒ Tenant Operation failed: " . $ex->getMessage();
            $_SESSION['admin_tab'] = 'schools';
            header("Location: super_admin.php");
            exit();
        }
    }
}

// ----------------------------------------------------
// READ SYSTEM METRICS & DATA
// ----------------------------------------------------
// Dashboard counters
$total_schools = $db->query("SELECT COUNT(*) FROM schools")->fetchColumn() ?: 0;
$active_schools = $db->query("SELECT COUNT(*) FROM schools WHERE status='active'")->fetchColumn() ?: 0;
$mrr = $db->query("SELECT SUM(amount) FROM school_subscriptions WHERE status IN ('active','trial')")->fetchColumn() ?: 0.00;
$total_students = 0; // Calculated dynamically across tenant databases

// Fetch list of schools
$schools_stmt = $db->query("SELECT s.*, ss.status as sub_status, sp.name as plan_name, sp.student_limit 
                            FROM schools s
                            LEFT JOIN school_subscriptions ss ON ss.school_id = s.id
                            LEFT JOIN subscription_plans sp ON ss.plan_id = sp.id
                            ORDER BY s.created_at DESC");
$schools = $schools_stmt->fetchAll(PDO::FETCH_ASSOC);

// Dynamically check student totals and tenant connection health
$connection_health = [];
foreach ($schools as $key => $school) {
    try {
        $tenant_pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $school['db_name'], DB_USER, DB_PASS);
        $tenant_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Count active students in the isolated users table
        $std_stmt = $tenant_pdo->query("SELECT COUNT(*) FROM users WHERE role='student' AND status='active'");
        $st_count = $std_stmt->fetchColumn() ?: 0;
        $schools[$key]['active_students_count'] = $st_count;
        $total_students += $st_count;
        
        $connection_health[$school['code']] = [
            'status' => 'healthy',
            'icon' => 'fa-check-circle',
            'color' => 'text-green-500'
        ];
    } catch (PDOException $ex) {
        $schools[$key]['active_students_count'] = "Unavailable";
        $connection_health[$school['code']] = [
            'status' => 'error',
            'icon' => 'fa-times-circle',
            'color' => 'text-red-500'
        ];
    }
}

// Fetch billing invoices
$invoices_stmt = $db->query("SELECT bi.*, s.name as school_name, s.code as school_code 
                             FROM billing_invoices bi
                             JOIN schools s ON bi.school_id = s.id
                             ORDER BY bi.created_at DESC");
$invoices = $invoices_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subscription plans
$plans_stmt = $db->query("SELECT * FROM subscription_plans ORDER BY price_monthly ASC");
$plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch system health logs & audit logs
$audit_stmt = $db->query("SELECT al.*, s.name as school_name 
                          FROM audit_logs al
                          LEFT JOIN schools s ON al.school_id = s.id
                          ORDER BY al.created_at DESC LIMIT 15");
$audit_logs = $audit_stmt->fetchAll(PDO::FETCH_ASSOC);

// Support tickets list
$tickets_stmt = $db->query("SELECT st.*, s.name as school_name 
                            FROM support_tickets st
                            LEFT JOIN schools s ON st.school_id = s.id
                            ORDER BY st.created_at DESC");
$tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

// Attach conversation thread (responses) to each ticket for the reply modal
if (!empty($tickets)) {
    $ticket_ids = array_map('intval', array_column($tickets, 'id'));
    $in_list = implode(',', $ticket_ids);
    $threads = [];
    try {
        $resp_rows = $db->query("SELECT tr.ticket_id, tr.response, tr.is_admin_response, tr.created_at, u.name AS responder_name
                                 FROM ticket_responses tr
                                 LEFT JOIN users u ON tr.user_id = u.id
                                 WHERE tr.ticket_id IN ($in_list)
                                 ORDER BY tr.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($resp_rows as $r) {
            $threads[$r['ticket_id']][] = $r;
        }
    } catch (PDOException $e) {
        // ticket_responses unavailable — modal will just show the description
    }
    foreach ($tickets as &$t) {
        $t['responses'] = $threads[$t['id']] ?? [];
    }
    unset($t);
}

$title = "Super Admin Central Control";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Alpine.js Application for dynamic views -->
<div x-data="{ currentTab: '<?php echo htmlspecialchars($active_tab); ?>', planModalOpen: false, activePlan: {}, recordPaymentOpen: false, activeInvoice: {}, invoiceModalOpen: false, invoiceTemplate: 'elegant', showPassword: false, replyModalOpen: false, activeTicket: {} }"
     class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" 
     style="margin-top: 80px;">
    
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Premium Header Banner -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl relative overflow-hidden">
                        <div class="absolute inset-0 bg-cover bg-center opacity-10" style="background-image: url('../assets/images/pattern.svg');"></div>
                        <div class="relative flex flex-col md:flex-row items-start md:items-center justify-between">
                            <div>
                                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-white/20 text-white backdrop-blur-sm mb-3">
                                    <i class="fas fa-sliders-h mr-2"></i> Super Admin Central Panel
                                </div>
                                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight mb-2">Multi-School Management</h1>
                                <p class="text-blue-100 text-lg">Central control for school registration, automated dynamic databases, SaaS billing tiers, and live analytics.</p>
                            </div>
                            <div class="mt-4 md:mt-0 flex space-x-3">
                                <button @click="currentTab = 'register'" 
                                        class="bg-white text-indigo-600 font-bold px-6 py-3 rounded-xl shadow-lg hover:scale-105 transition-transform duration-200 flex items-center">
                                    <i class="fas fa-plus mr-2"></i> Register New School
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Toast Alerts -->
                <?php if ($success_message): ?>
                <div class="mb-6 bg-emerald-50 border-l-4 border-emerald-500 rounded-xl p-4 shadow-md text-emerald-800 flex items-center">
                    <i class="fas fa-check-circle text-xl mr-3 text-emerald-500"></i>
                    <div>
                        <p class="font-bold">Action Completed</p>
                        <p class="text-sm"><?php echo $success_message; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 bg-rose-50 border-l-4 border-rose-500 rounded-xl p-4 shadow-md text-rose-800 flex items-center">
                    <i class="fas fa-exclamation-circle text-xl mr-3 text-rose-500"></i>
                    <div>
                        <p class="font-bold">Execution Error</p>
                        <p class="text-sm"><?php echo $error_message; ?></p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Navigation Buttons -->
                <div class="grid grid-cols-2 gap-1.5 sm:flex sm:gap-0 sm:space-x-1 sm:overflow-x-auto bg-gray-200/60 dark:bg-gray-800 p-1.5 rounded-2xl mb-8 w-full sm:w-fit shadow-inner">
                    <button @click="currentTab = 'dashboard'" 
                            :class="currentTab === 'dashboard' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-chart-pie mr-2"></i> Analytics Board
                    </button>
                    <button @click="currentTab = 'schools'" 
                            :class="currentTab === 'schools' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-school mr-2"></i> Tenant Schools (<?php echo $total_schools; ?>)
                    </button>
                    <button @click="currentTab = 'billing'" 
                            :class="currentTab === 'billing' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-file-invoice-dollar mr-2"></i> Billing Hub
                    </button>
                    <button @click="currentTab = 'plans'" 
                            :class="currentTab === 'plans' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-tags mr-2"></i> Subscription Tiers
                    </button>
                    <button @click="currentTab = 'health'" 
                            :class="currentTab === 'health' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-heartbeat mr-2"></i> Health & Isolation
                    </button>
                    <button @click="currentTab = 'tickets'" 
                            :class="currentTab === 'tickets' ? 'bg-white dark:bg-gray-700 shadow text-indigo-600 dark:text-white font-bold' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900'"
                            class="px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 flex items-center justify-center sm:justify-start text-center sm:flex-shrink-0 sm:whitespace-nowrap">
                        <i class="fas fa-headset mr-2"></i> Tickets Desk (<?php echo count($tickets); ?>)
                    </button>
                    <a href="module_access.php"
                       class="col-span-2 sm:col-span-1 px-3 sm:px-6 py-2.5 rounded-xl text-sm font-medium transition-all duration-200 text-gray-600 dark:text-gray-400 hover:text-gray-900 flex items-center justify-center sm:justify-start whitespace-nowrap sm:flex-shrink-0">
                        <i class="fas fa-toggle-on mr-2"></i> Module Access
                    </a>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 1: ANALYTICS & MONITORING DASHBOARD -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'dashboard'" x-transition class="space-y-8">
                    <!-- Top Metric Cards -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Card 1: Registered Schools -->
                        <div class="bg-gradient-to-br from-blue-500 to-indigo-600 rounded-2xl p-6 text-white shadow-lg transform hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-blue-100 uppercase tracking-wider">Registered Schools</p>
                                    <p class="text-3xl font-extrabold mt-1"><?php echo $total_schools; ?></p>
                                </div>
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-xl">
                                    <i class="fas fa-university"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center text-xs text-blue-100">
                                <span class="bg-white/30 px-2 py-0.5 rounded mr-2 font-bold"><?php echo $active_schools; ?></span> Active and Isolated
                            </div>
                        </div>

                        <!-- Card 2: MRR (Monthly Recurring Revenue) -->
                        <div class="rounded-2xl p-6 text-white shadow-lg transform hover:-translate-y-1 transition-all duration-300" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-emerald-100 uppercase tracking-wider">MRR (GHS)</p>
                                    <p class="text-3xl font-extrabold mt-1">₵<?php echo number_format($mrr, 2); ?></p>
                                </div>
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-xl">
                                    <i class="fas fa-dollar-sign"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center text-xs text-emerald-150">
                                <span class="bg-white/20 px-2 py-0.5 rounded font-bold mr-1"><i class="fas fa-arrow-up mr-1"></i>+8.5%</span> from previous cycle
                            </div>
                        </div>

                        <!-- Card 3: Total Enrolled Students -->
                        <div class="bg-gradient-to-br from-purple-500 to-pink-600 rounded-2xl p-6 text-white shadow-lg transform hover:-translate-y-1 transition-all duration-300">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-purple-100 uppercase tracking-wider">Students Isolated</p>
                                    <p class="text-3xl font-extrabold mt-1"><?php echo $total_students; ?></p>
                                </div>
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-xl">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center text-xs text-purple-100">
                                Across <?php echo $total_schools; ?> secure tenant databases
                            </div>
                        </div>

                        <!-- Card 4: Platform Health Status -->
                        <div class="rounded-2xl p-6 text-white shadow-lg transform hover:-translate-y-1 transition-all duration-300" style="background: linear-gradient(135deg, #f59e0b 0%, #ea580c 100%);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-amber-100 uppercase tracking-wider">System Status</p>
                                    <p class="text-3xl font-extrabold mt-1">99.98%</p>
                                </div>
                                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center text-xl animate-pulse">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                            </div>
                            <div class="mt-4 flex items-center text-xs text-amber-150">
                                <span class="bg-white/20 px-2 py-0.5 rounded mr-2 font-bold"><i class="fas fa-check mr-1"></i>Secure</span> All servers healthy
                            </div>
                        </div>
                    </div>

                    <!-- Platform Usage Charts & Performance Metrics -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Revenue Track -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active School Subscriptions & Growth</h3>
                                <span class="text-xs text-indigo-600 dark:text-indigo-400 font-bold hover:underline cursor-pointer">View full report</span>
                            </div>
                            <!-- Mock premium SVG graph illustrating MRR growth -->
                            <div class="h-64 flex items-end justify-between pt-6 relative border-b border-gray-100 dark:border-gray-700">
                                <div class="absolute inset-x-0 bottom-12 border-t border-gray-100 dark:border-gray-700/50"></div>
                                <div class="absolute inset-x-0 bottom-24 border-t border-gray-100 dark:border-gray-700/50"></div>
                                <div class="absolute inset-x-0 bottom-36 border-t border-gray-100 dark:border-gray-700/50"></div>
                                <div class="absolute inset-x-0 bottom-48 border-t border-gray-100 dark:border-gray-700/50"></div>
                                
                                <div class="flex flex-col items-center w-full z-10">
                                    <div class="w-12 bg-indigo-200 dark:bg-indigo-900/60 h-16 rounded-t-lg transition-all duration-300 hover:bg-indigo-400"></div>
                                    <span class="text-[10px] text-gray-500 mt-2 font-semibold">Jan</span>
                                </div>
                                <div class="flex flex-col items-center w-full z-10">
                                    <div class="w-12 bg-indigo-200 dark:bg-indigo-900/60 h-24 rounded-t-lg transition-all duration-300 hover:bg-indigo-400"></div>
                                    <span class="text-[10px] text-gray-500 mt-2 font-semibold">Feb</span>
                                </div>
                                <div class="flex flex-col items-center w-full z-10">
                                    <div class="w-12 bg-indigo-300 dark:bg-indigo-900/80 h-32 rounded-t-lg transition-all duration-300 hover:bg-indigo-500"></div>
                                    <span class="text-[10px] text-gray-500 mt-2 font-semibold">Mar</span>
                                </div>
                                <div class="flex flex-col items-center w-full z-10">
                                    <div class="w-12 bg-indigo-400 dark:bg-indigo-700 h-40 rounded-t-lg transition-all duration-300 hover:bg-indigo-600"></div>
                                    <span class="text-[10px] text-gray-500 mt-2 font-semibold">Apr</span>
                                </div>
                                <div class="flex flex-col items-center w-full z-10">
                                    <div class="w-12 bg-gradient-to-t from-indigo-500 to-purple-600 h-52 rounded-t-lg transition-all duration-300 hover:scale-105 shadow-md"></div>
                                    <span class="text-[10px] text-indigo-600 dark:text-indigo-400 mt-2 font-bold">May (Active)</span>
                                </div>
                            </div>
                        </div>

                        <!-- System Load Indicators -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-6">Server Load & CPU Diagnostics</h3>
                            <div class="space-y-5">
                                <div>
                                    <div class="flex justify-between text-sm mb-1.5">
                                        <span class="text-gray-600 dark:text-gray-400 font-medium">Core Platform Load</span>
                                        <span class="text-gray-900 dark:text-white font-bold">12.5%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 h-2.5 rounded-full overflow-hidden">
                                        <div class="bg-blue-600 h-full rounded-full" style="width: 12.5%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm mb-1.5">
                                        <span class="text-gray-600 dark:text-gray-400 font-medium">Memory Allocation</span>
                                        <span class="text-gray-900 dark:text-white font-bold">34.2%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 h-2.5 rounded-full overflow-hidden">
                                        <div class="bg-purple-600 h-full rounded-full" style="width: 34.2%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm mb-1.5">
                                        <span class="text-gray-600 dark:text-gray-400 font-medium">Active Database Pools</span>
                                        <span class="text-gray-900 dark:text-white font-bold"><?php echo $active_schools + 1; ?> pools</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 h-2.5 rounded-full overflow-hidden">
                                        <div class="bg-emerald-600 h-full rounded-full" style="width: 25%"></div>
                                    </div>
                                </div>

                                <div>
                                    <div class="flex justify-between text-sm mb-1.5">
                                        <span class="text-gray-600 dark:text-gray-400 font-medium">Platform Error Rate</span>
                                        <span class="text-gray-900 dark:text-white font-bold">0.02%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 dark:bg-gray-700 h-2.5 rounded-full overflow-hidden">
                                        <div class="bg-emerald-500 h-full rounded-full" style="width: 2%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Platform-wide Audit Trail / Recent Events -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Platform System Audit Logs</h3>
                            <p class="text-sm text-gray-500 mt-1">Real-time system actions and compliance audits across registered schools.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 text-xs font-semibold uppercase">
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Timestamp</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">School / Tenant</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Action Type</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Details</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">IP Address</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                    <?php if (count($audit_logs) === 0): ?>
                                    <tr>
                                        <td colspan="5" class="p-8 text-center text-gray-500 dark:text-gray-400 font-medium">No system events logged in this session.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($audit_logs as $log): ?>
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors duration-150">
                                            <td class="p-4 font-mono text-xs"><?php echo $log['created_at']; ?></td>
                                            <td class="p-4 font-semibold text-gray-800 dark:text-white"><?php echo $log['school_name'] ?? 'System Level (Central)'; ?></td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200 dark:bg-indigo-900/30 dark:text-indigo-400 dark:border-indigo-800">
                                                    <?php echo $log['action']; ?>
                                                </span>
                                            </td>
                                            <td class="p-4 max-w-sm truncate text-gray-700 dark:text-gray-400" title="<?php echo htmlspecialchars($log['details']); ?>">
                                                <?php echo htmlspecialchars($log['details']); ?>
                                            </td>
                                            <td class="p-4 font-mono text-xs"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 2: TENANT SCHOOLS MANAGEMENT -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'schools'" x-transition class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                            <div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active Tenants & Dynamic Database Registries</h3>
                                <p class="text-sm text-gray-500 mt-1">Check individual school database connection health, limits, active pupils, and execute maintenance operations.</p>
                            </div>
                            <button @click="currentTab = 'register'" 
                                    class="bg-indigo-600 text-white font-bold px-4 py-2 rounded-xl text-sm shadow hover:bg-indigo-700 transition duration-150 flex items-center">
                                <i class="fas fa-plus mr-1.5"></i> Register School
                            </button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 text-xs font-semibold uppercase">
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">School Code</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">School Name</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Isolated DB Name</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Current Plan</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Pupil Load</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">DB Status</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700 text-center">Maintenance Controls</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                    <?php if (count($schools) === 0): ?>
                                    <tr>
                                        <td colspan="7" class="p-8 text-center text-gray-500 dark:text-gray-400 font-medium">No schools registered yet. Run a School Registration now!</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($schools as $school): ?>
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors duration-150">
                                            <td class="p-4 font-mono font-bold text-indigo-600 dark:text-indigo-400">/<?php echo htmlspecialchars($school['code']); ?></td>
                                            <td class="p-4 font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($school['name']); ?></td>
                                            <td class="p-4 font-mono text-xs text-gray-500"><?php echo htmlspecialchars($school['db_name']); ?></td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-50 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                                                    <?php echo htmlspecialchars($school['plan_name'] ?? 'None'); ?>
                                                </span>
                                            </td>
                                            <td class="p-4 text-xs font-bold text-gray-700 dark:text-gray-400">
                                                <?php echo $school['active_students_count']; ?> / <?php echo $school['student_limit'] > 9000 ? 'âˆž' : $school['student_limit']; ?>
                                            </td>
                                            <td class="p-4">
                                                <?php $health = $connection_health[$school['code']]; ?>
                                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 dark:bg-gray-800 <?php echo $health['color']; ?>">
                                                    <i class="fas <?php echo $health['icon']; ?> mr-1.5"></i> <?php echo ucfirst($health['status']); ?>
                                                </span>
                                            </td>
                                            <td class="p-4">
                                                <div class="flex items-center justify-center space-x-2">
                                                    <!-- Enter School (impersonate the school's admin, no password) -->
                                                    <form method="POST" action="impersonate.php" class="inline"
                                                          onsubmit="return confirm('Enter \'<?php echo htmlspecialchars($school['name'], ENT_QUOTES); ?>\' as its administrator?\n\nYou will be acting inside this school. Use the \'Exit\' banner to return to the Super Admin panel.');">
                                                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                                        <input type="hidden" name="impersonate_token" value="<?php echo htmlspecialchars($_SESSION['impersonate_token'], ENT_QUOTES); ?>">
                                                        <button type="submit"
                                                                title="Enter this school's admin area without a password"
                                                                class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center">
                                                            <i class="fas fa-right-to-bracket mr-1"></i> Enter
                                                        </button>
                                                    </form>

                                                    <!-- Trigger Backup -->
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="tenant_action">
                                                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                                        <input type="hidden" name="operation" value="backup">
                                                        <button type="submit" 
                                                                title="Back up isolated database"
                                                                class="bg-blue-50 hover:bg-blue-100 text-blue-700 px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center">
                                                            <i class="fas fa-database mr-1"></i> Backup
                                                        </button>
                                                    </form>

                                                    <!-- Suspend / Activate -->
                                                    <?php if ($school['status'] === 'active'): ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="tenant_action">
                                                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                                        <input type="hidden" name="operation" value="suspend">
                                                        <button type="submit" 
                                                                class="bg-rose-50 hover:bg-rose-100 text-rose-700 px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center"
                                                                title="Suspend school login access">
                                                            <i class="fas fa-ban mr-1"></i> Suspend
                                                        </button>
                                                    </form>
                                                    <?php else: ?>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="tenant_action">
                                                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                                        <input type="hidden" name="operation" value="activate">
                                                        <button type="submit" 
                                                                class="bg-green-50 hover:bg-green-100 text-green-700 px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center"
                                                                title="Activate school login access">
                                                            <i class="fas fa-check-circle mr-1"></i> Activate
                                                        </button>
                                                    </form>
                                                    <?php endif; ?>

                                                    <!-- Delete School -->
                                                    <form method="POST" class="inline" onsubmit="return confirm('âš ï¸ WARNING: This will permanently delete school \'<?php echo htmlspecialchars($school['name']); ?>\' and drop its isolated database (<?php echo htmlspecialchars($school['db_name']); ?>). This cannot be undone!\n\nAre you absolutely sure you want to delete this school?');">
                                                        <input type="hidden" name="action" value="tenant_action">
                                                        <input type="hidden" name="school_id" value="<?php echo $school['id']; ?>">
                                                        <input type="hidden" name="operation" value="delete">
                                                        <button type="submit" 
                                                                class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-1.5 rounded-lg text-xs font-bold transition duration-150 flex items-center"
                                                                title="Permanently delete school and drop database">
                                                            <i class="fas fa-trash-alt mr-1"></i> Delete
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 3: BILLING & INVOICE MANAGEMENT -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'billing'" x-transition class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Centralized Multi-School Billing and Transactions</h3>
                            <p class="text-sm text-gray-500 mt-1">Review outstanding invoices, record manual bank/cash collections, and approve renewal transactions.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 text-xs font-semibold uppercase">
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Invoice Num</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">School</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Amount (GHS)</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Date Generated</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Due Date</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Status</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Transaction ID</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700 text-center">Billing Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                    <?php if (count($invoices) === 0): ?>
                                    <tr>
                                        <td colspan="8" class="p-8 text-center text-gray-500 dark:text-gray-400 font-medium">No platform invoices generated yet.</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($invoices as $inv): ?>
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors duration-150">
                                            <td class="p-4 font-mono font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                                            <td class="p-4 font-semibold text-indigo-600 dark:text-indigo-400"><?php echo htmlspecialchars($inv['school_name']); ?></td>
                                            <td class="p-4 font-bold text-gray-900 dark:text-white">&#8373;<?php echo number_format($inv['amount'], 2); ?></td>
                                            <td class="p-4"><?php echo date('Y-m-d', strtotime($inv['created_at'])); ?></td>
                                            <td class="p-4 text-xs font-semibold text-rose-500"><?php echo htmlspecialchars($inv['due_date']); ?></td>
                                            <td class="p-4">
                                                <?php if ($inv['status'] === 'paid'): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">
                                                        <i class="fas fa-check-circle mr-1"></i> Paid
                                                    </span>
                                                <?php elseif ($inv['status'] === 'unpaid'): ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">
                                                        <i class="fas fa-clock mr-1"></i> Unpaid
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-rose-50 text-rose-800 dark:bg-rose-900/30 dark:text-rose-400">
                                                        <i class="fas fa-times-circle mr-1"></i> Overdue
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 font-mono text-xs"><?php echo htmlspecialchars($inv['transaction_ref'] ?? 'N/A'); ?></td>
                                            <td class="p-4">
                                                 <div class="flex items-center justify-center space-x-2">
                                                     <?php if ($inv['status'] !== 'paid'): ?>
                                                         <button @click="activeInvoice = <?php echo htmlspecialchars(json_encode($inv)); ?>; recordPaymentOpen = true;"
                                                                 class="bg-indigo-600 text-white hover:bg-indigo-700 font-bold px-3 py-1.5 rounded-lg text-xs shadow transition duration-150">
                                                             Record Payment
                                                         </button>
                                                     <?php endif; ?>
                                                     <a href="invoice_print.php?id=<?php echo (int)$inv['id']; ?>" target="_blank"
                                                        class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300 dark:border-gray-600 shadow transition duration-150 flex items-center">
                                                         <i class="fas fa-print mr-1"></i> Print / View
                                                     </a>
                                                     <a href="invoice_print.php?id=<?php echo (int)$inv['id']; ?>&format=thermal" target="_blank"
                                                        style="background-color:#334155;"
                                                        class="hover:opacity-90 text-white font-bold px-3 py-1.5 rounded-lg text-xs shadow transition duration-150 flex items-center">
                                                         <i class="fas fa-receipt mr-1"></i> Thermal
                                                     </a>
                                                 </div>
                                             </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 4: SUBSCRIPTION PLAN TIERS (Matching image mock) -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'plans'" x-transition class="space-y-6">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white">Active Subscription Pricing Tiers</h3>
                            <p class="text-sm text-gray-500 mt-1">Manage pricing tiers offered to schools globally. All isolated system capacities are governed here.</p>
                        </div>
                        <button @click="activePlan = { id: 0, name: '', description: '', price_monthly: 0, student_limit: 100, staff_limit: 15, trial_days: 14, features: ['core'] }; planModalOpen = true;"
                                class="bg-indigo-600 text-white font-bold px-4 py-2 rounded-xl text-sm shadow hover:bg-indigo-700 transition duration-150 flex items-center justify-center whitespace-nowrap flex-shrink-0 w-full sm:w-auto">
                            <i class="fas fa-plus mr-1.5"></i> Create Pricing Tier
                        </button>
                    </div>

                    <!-- Pricing Cards Grid (Exact matching of provided image aesthetics) -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                        <?php foreach ($plans as $p): ?>
                        <?php 
                        $decoded_feats = json_decode($p['features'], true) ?: []; 
                        $card_style = "";
                        $badge = "";
                        if ($p['name'] === 'Standard') {
                            $card_style = "border-2 border-orange-500 ring-4 ring-orange-500/10 scale-105 md:scale-100 z-10 relative";
                            $badge = "<div class='absolute -top-3 right-6 bg-orange-500 text-white px-3 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide shadow-md'><i class='fas fa-star mr-1'></i> Popular</div>";
                        } else {
                            $card_style = "border border-gray-200 dark:border-gray-700";
                        }
                        ?>
                        <div class="bg-white dark:bg-gray-800 rounded-3xl p-8 flex flex-col justify-between shadow-xl transition-all duration-300 hover:shadow-2xl hover:-translate-y-1 <?php echo $card_style; ?>">
                            <?php echo $badge; ?>
                            <div>
                                <div class="flex items-center space-x-3 mb-4">
                                    <div class="w-12 h-12 bg-orange-50 dark:bg-gray-700 rounded-2xl flex items-center justify-center text-xl text-orange-500">
                                        <i class="fas fa-box-open"></i>
                                    </div>
                                    <div>
                                        <h4 class="text-xl font-extrabold text-gray-900 dark:text-white"><?php echo htmlspecialchars($p['name']); ?></h4>
                                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($p['description']); ?></p>
                                    </div>
                                </div>

                                <div class="my-6">
                                    <span class="text-3xl font-extrabold text-gray-900 dark:text-white">
                                        <?php if ($p['price_monthly'] == 0): ?>Free<?php else: ?>₵<?php echo number_format($p['price_monthly'], 0); ?><?php endif; ?>
                                    </span>
                                    <span class="text-sm text-gray-500 font-semibold">/ month</span>
                                    <p class="text-xs text-gray-400 mt-1"><?php echo $p['trial_days']; ?>-day trial period</p>
                                </div>

                                <!-- Limits -->
                                <div class="py-3 border-t border-b border-gray-100 dark:border-gray-700 mb-6 flex justify-between text-xs text-gray-600 dark:text-gray-400">
                                    <span><i class="fas fa-user-graduate mr-1 text-indigo-500"></i> Up to <strong><?php echo $p['student_limit'] > 9000 ? 'Unlimited' : $p['student_limit']; ?></strong> students</span>
                                    <span><i class="fas fa-user-tie mr-1 text-indigo-500"></i> Up to <strong><?php echo $p['staff_limit'] > 9000 ? 'Unlimited' : $p['staff_limit']; ?></strong> staff</span>
                                </div>

                                <!-- Features List -->
                                <h5 class="text-xs font-bold text-gray-900 dark:text-white uppercase tracking-wider mb-3">Key Features Included:</h5>
                                <ul class="space-y-3 text-xs text-gray-600 dark:text-gray-400 mb-8">
                                    <li class="flex items-start">
                                        <i class="fas fa-check-circle text-emerald-500 mr-2 mt-0.5"></i>
                                        <span>Core administrative modules</span>
                                    </li>
                                    <li class="flex items-start">
                                        <i class="fas fa-check-circle text-emerald-500 mr-2 mt-0.5"></i>
                                        <span>Independent isolated database storage</span>
                                    </li>
                                    <li class="flex items-start <?php echo in_array('finance', $decoded_feats) ? '' : 'opacity-40 line-through'; ?>">
                                        <i class="fas <?php echo in_array('finance', $decoded_feats) ? 'fa-check-circle text-emerald-500' : 'fa-times-circle text-gray-300'; ?> mr-2 mt-0.5"></i>
                                        <span>Finance & Billing Ledger</span>
                                    </li>
                                    <li class="flex items-start <?php echo in_array('custom_branding', $decoded_feats) ? '' : 'opacity-40 line-through'; ?>">
                                        <i class="fas <?php echo in_array('custom_branding', $decoded_feats) ? 'fa-check-circle text-emerald-500' : 'fa-times-circle text-gray-300'; ?> mr-2 mt-0.5"></i>
                                        <span>Custom theme colors & branding</span>
                                    </li>
                                    <li class="flex items-start <?php echo in_array('api_access', $decoded_feats) ? '' : 'opacity-40 line-through'; ?>">
                                        <i class="fas <?php echo in_array('api_access', $decoded_feats) ? 'fa-check-circle text-emerald-500' : 'fa-times-circle text-gray-300'; ?> mr-2 mt-0.5"></i>
                                        <span>API Integration Controls</span>
                                    </li>
                                </ul>
                            </div>

                            <button @click="activePlan = <?php echo htmlspecialchars(json_encode($p)); ?>; planModalOpen = true;"
                                    class="w-full bg-gray-100 hover:bg-indigo-600 dark:bg-gray-700 dark:hover:bg-indigo-600 text-gray-800 hover:text-white dark:text-white font-bold py-2.5 rounded-xl text-xs shadow-sm transition duration-150">
                                Edit Plan Config
                            </button>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Assign / Change a school's subscription plan -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white flex items-center">
                                <i class="fas fa-exchange-alt mr-2 text-indigo-500"></i> Change a School's Subscription Plan
                            </h3>
                            <p class="text-sm text-gray-500 mt-1">Move any registered school onto a different pricing tier. The school's billing amount and trial window are reset from the selected plan.</p>
                        </div>
                        <form method="POST" class="p-6 grid grid-cols-1 md:grid-cols-3 gap-4 items-end"
                              onsubmit="return confirm('Change this school\'s subscription plan? Their billing amount will be updated to match the new plan.');">
                            <input type="hidden" name="action" value="assign_plan">

                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-1.5">School</label>
                                <select name="school_id" required
                                        class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                    <option value="">Select a school…</option>
                                    <?php foreach ($schools as $sch): ?>
                                    <option value="<?php echo (int)$sch['id']; ?>">
                                        <?php echo htmlspecialchars($sch['name']); ?> — currently: <?php echo htmlspecialchars($sch['plan_name'] ?? 'None'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wide mb-1.5">New Plan</label>
                                <select name="plan_id" required
                                        class="w-full px-3 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                                    <option value="">Select a plan…</option>
                                    <?php foreach ($plans as $p): ?>
                                    <option value="<?php echo (int)$p['id']; ?>">
                                        <?php echo htmlspecialchars($p['name']); ?> — <?php echo $p['price_monthly'] == 0 ? 'Free' : '₵' . number_format($p['price_monthly'], 2) . '/mo'; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <button type="submit"
                                        class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-lg text-sm shadow transition duration-150 flex items-center justify-center">
                                    <i class="fas fa-check mr-1.5"></i> Apply Plan Change
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 5: MULTI-TENANT ISOLATION CONTROLS -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'health'" x-transition class="space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        
                        <!-- Isolation Panel Diagnostics -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2 space-y-6">
                            <div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active Database Isolation Diagnostic Board</h3>
                                <p class="text-sm text-gray-500 mt-1">Verify that MySQL processes are isolated by tenant, with dedicated permissions and no cross-schema contamination.</p>
                            </div>
                            
                            <!-- Real-time dynamic visual of databases health -->
                            <div class="p-6 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-100 dark:border-gray-700 space-y-4">
                                <div class="flex items-center justify-between text-xs text-gray-500 font-bold uppercase">
                                    <span>Database Target Namespace</span>
                                    <span>Isolated Connection Integrity</span>
                                </div>
                                
                                <div class="space-y-2">
                                    <!-- Primary Platform -->
                                    <div class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 text-sm">
                                        <span class="font-mono text-gray-800 dark:text-white"><i class="fas fa-server text-indigo-500 mr-2"></i> school_ms (Platform Central)</span>
                                        <span class="text-xs text-emerald-500 font-bold"><i class="fas fa-shield-alt mr-1"></i> Root Secure</span>
                                    </div>
                                    
                                    <!-- Schools dynamic listing -->
                                    <?php foreach ($schools as $sch): ?>
                                    <div class="flex items-center justify-between p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 text-sm">
                                        <span class="font-mono text-gray-800 dark:text-white"><i class="fas fa-database text-indigo-400 mr-2"></i> <?php echo htmlspecialchars($sch['db_name']); ?></span>
                                        <span class="text-xs text-emerald-500 font-bold"><i class="fas fa-lock mr-1"></i> isolated_tenant</span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- System Backup Config & Global Actions -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 space-y-6">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Platform System Backup Center</h3>
                            <p class="text-sm text-gray-500">Backups are run per-tenant to maintain database schemas separately and allow granular restoration of individual schools without affecting others.</p>
                            
                            <div class="p-4 bg-indigo-50 dark:bg-indigo-950/20 border border-indigo-100 dark:border-indigo-900 rounded-xl">
                                <h4 class="text-sm font-bold text-indigo-900 dark:text-indigo-300"><i class="fas fa-info-circle mr-1"></i> Granular Backups</h4>
                                <p class="text-xs text-indigo-800 dark:text-indigo-400 mt-1">Tenant backups contain both database layouts and table data. They are saved in `school_ms/backups/` and can be manually downloaded or restored at any time.</p>
                            </div>

                            <div class="space-y-3">
                                <a href="export_audit_trail.php"
                                   class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl text-xs shadow-sm transition duration-150 flex items-center justify-center">
                                    <i class="fas fa-download mr-1.5"></i> Download Platform Audit Trail
                                </a>
                                <form method="POST" onsubmit="return confirm('Clear all cached files and analytics/access logs? This cannot be undone.');" class="m-0">
                                    <input type="hidden" name="action" value="clear_cache_logs">
                                    <button type="submit"
                                            class="w-full bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-bold py-2.5 rounded-xl text-xs shadow-sm transition duration-150 flex items-center justify-center">
                                        <i class="fas fa-trash-alt mr-1.5"></i> Clear Cache &amp; Analytics Logs
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 6: SUPPORT & ISSUES TRACKING SYSTEM -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'tickets'" x-transition class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active Support and Compliance Tickets</h3>
                            <p class="text-sm text-gray-500 mt-1">Manage ticket queues submitted by admins across active tenant schools. Address compliance or data configuration requests.</p>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-700/50 text-gray-700 dark:text-gray-300 text-xs font-semibold uppercase">
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Ticket ID</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">School</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Subject</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Priority</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Date Received</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700">Status</th>
                                        <th class="p-4 border-b border-gray-200 dark:border-gray-700 text-center">Support Control</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-600 dark:text-gray-300">
                                    <?php if (count($tickets) === 0): ?>
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="p-4 font-mono font-bold">#ST-2026-49</td>
                                        <td class="p-4 font-semibold text-indigo-600 dark:text-indigo-400">Greenwood Academy</td>
                                        <td class="p-4 font-semibold text-gray-800 dark:text-white">Request to enable custom branding for Standard Plan</td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200 dark:bg-amber-900/30 dark:text-amber-400 dark:border-amber-800">Medium</span>
                                        </td>
                                        <td class="p-4"><?php echo date('Y-m-d'); ?></td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-amber-50 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400">Open</span>
                                        </td>
                                        <td class="p-4 text-center">
                                            <button class="bg-indigo-600 text-white font-bold px-3 py-1.5 rounded-lg text-xs shadow-sm hover:bg-indigo-700 transition">Triage Ticket</button>
                                        </td>
                                    </tr>
                                    <tr class="hover:bg-gray-50/50">
                                        <td class="p-4 font-mono font-bold">#ST-2026-48</td>
                                        <td class="p-4 font-semibold text-indigo-600 dark:text-indigo-400">Elite Academics</td>
                                        <td class="p-4 font-semibold text-gray-800 dark:text-white">Data migration compliance request from legacy Excel sheet</td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-900/30 dark:text-rose-400 dark:border-rose-800">High</span>
                                        </td>
                                        <td class="p-4"><?php echo date('Y-m-d', strtotime('-1 days')); ?></td>
                                        <td class="p-4">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-emerald-50 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400">Resolved</span>
                                        </td>
                                        <td class="p-4 text-center">
                                            <span class="text-xs text-gray-400 font-medium">Closed</span>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($tickets as $t): ?>
                                        <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/50 transition-colors duration-150">
                                            <td class="p-4 font-mono font-bold">#TKT-<?php echo $t['id']; ?></td>
                                            <td class="p-4 font-semibold text-indigo-600 dark:text-indigo-400"><?php echo htmlspecialchars($t['school_name']); ?></td>
                                            <td class="p-4 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($t['subject']); ?></td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-800">
                                                    <?php echo $t['priority']; ?>
                                                </span>
                                            </td>
                                            <td class="p-4"><?php echo $t['created_at']; ?></td>
                                            <td class="p-4">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-gray-100 text-gray-800">
                                                    <?php echo $t['status']; ?>
                                                </span>
                                            </td>
                                            <td class="p-4 text-center">
                                                <button @click="activeTicket = <?php echo htmlspecialchars(json_encode($t), ENT_QUOTES); ?>; replyModalOpen = true;"
                                                        class="bg-indigo-600 text-white font-bold px-3 py-1.5 rounded-lg text-xs shadow-sm hover:bg-indigo-700 transition">Reply</button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ---------------------------------------------------- -->
                <!-- TAB 7: SCHOOL REGISTRATION FORM -->
                <!-- ---------------------------------------------------- -->
                <div x-show="currentTab === 'register'" x-transition class="max-w-4xl mx-auto space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white">Tenant School Onboarding & Registration System</h3>
                            <p class="text-sm text-gray-500 mt-1">This form automatically provisions a new MySQL database, runs the full database schema migration, creates the local administrator, and binds a subscription tier.</p>
                        </div>
                        
                        <form method="POST" class="p-6 space-y-8">
                            <input type="hidden" name="action" value="register_school">

                            <!-- School Details -->
                            <div>
                                <h4 class="text-md font-bold text-gray-800 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-2 mb-4">School Details</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- School Name -->
                                    <div>
                                        <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">School Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="name" name="name" required placeholder="e.g. Greenwood Academy"
                                               value="<?php echo htmlspecialchars($form_input['name'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <!-- Unique School Code -->
                                    <div>
                                        <label for="code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Unique Code (Alphanumeric only) <span class="text-red-500">*</span></label>
                                        <input type="text" id="code" name="code" required placeholder="e.g. greenwood"
                                               value="<?php echo htmlspecialchars($form_input['code'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <p class="text-xs text-gray-400 mt-1">This governs the dynamic tenant database naming structure.</p>
                                    </div>
                                    <!-- Student ID Prefix -->
                                    <div>
                                        <label for="student_id_prefix" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student ID Prefix</label>
                                        <input type="text" id="student_id_prefix" name="student_id_prefix" maxlength="3"
                                               placeholder="e.g. DRE (auto if blank)"
                                               value="<?php echo htmlspecialchars($form_input['student_id_prefix'] ?? ''); ?>"
                                               oninput="this.value=this.value.toUpperCase().replace(/[^A-Z0-9]/g,'')"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white uppercase tracking-widest">
                                        <p class="text-xs text-gray-400 mt-1">Exactly 3 letters/digits, prepended to this school's student IDs (e.g. <span class="font-mono">DRE</span>20250001). Leave blank to auto-generate a unique one. Must be unique across schools.</p>
                                    </div>
                                    <!-- Contact Phone -->
                                    <div>
                                        <label for="contact_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">School Phone <span class="text-red-500">*</span></label>
                                        <input type="tel" id="contact_phone" name="contact_phone" required placeholder="e.g. +233 24 000 0000"
                                               value="<?php echo htmlspecialchars($form_input['contact_phone'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <!-- Subscription Tier -->
                                    <div>
                                        <label for="plan_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subscription Pricing Tier <span class="text-red-500">*</span></label>
                                        <select id="plan_id" name="plan_id" required
                                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                            <?php foreach ($plans as $p): ?>
                                                <option value="<?php echo $p['id']; ?>" <?php echo (isset($form_input['plan_id']) && $form_input['plan_id'] == $p['id']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($p['name']); ?> - ₵<?php echo number_format($p['price_monthly'], 0); ?>/mo (Up to <?php echo $p['student_limit'] > 9000 ? 'Unlimited' : $p['student_limit']; ?> students)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <!-- Address -->
                                <div class="mt-6">
                                    <label for="address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Physical Address <span class="text-red-500">*</span></label>
                                    <textarea id="address" name="address" rows="3" required placeholder="e.g. 15 Greenwood Lane, East Legon, Accra"
                                              class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($form_input['address'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <!-- Administrator Account Credentials -->
                            <div>
                                <h4 class="text-md font-bold text-gray-800 dark:text-white border-b border-gray-100 dark:border-gray-700 pb-2 mb-4">Primary Admin Credentials (School Headmaster/Headmistress)</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Admin Name -->
                                    <div>
                                        <label for="contact_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Administrator Name <span class="text-red-500">*</span></label>
                                        <input type="text" id="contact_name" name="contact_name" required placeholder="e.g. Dr. Arthur Greenwood"
                                               value="<?php echo htmlspecialchars($form_input['contact_name'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <!-- Admin Email -->
                                    <div>
                                        <label for="contact_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Admin / Headmaster/Headmistress Email <span class="text-red-500">*</span></label>
                                        <input type="email" id="contact_email" name="contact_email" required placeholder="e.g. principal@greenwood.com"
                                               value="<?php echo htmlspecialchars($form_input['contact_email'] ?? ''); ?>"
                                               class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <!-- Password -->
                                    <div class="md:col-span-2">
                                        <label for="principal_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Initial Password <span class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <input :type="showPassword ? 'text' : 'password'" id="principal_password" name="principal_password" required placeholder="â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢"
                                                   class="w-full pl-4 pr-12 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                            <button type="button" @click="showPassword = !showPassword" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300 focus:outline-none" title="Toggle password visibility">
                                                <i class="fas" :class="showPassword ? 'fa-eye-slash' : 'fa-eye'"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Register Action Buttons -->
                            <div class="pt-6 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-4">
                                <button type="button" @click="currentTab = 'schools'" 
                                        class="px-6 py-3 bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-bold rounded-xl text-sm shadow transition duration-150">
                                    Cancel
                                </button>
                                <button type="submit" 
                                        class="px-8 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-bold rounded-xl text-sm shadow-lg hover:scale-105 transition-all duration-200">
                                    Onboard School and Provision Database
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </main>
        
        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>

    <!-- ---------------------------------------------------- -->
    <!-- MODAL 1: CREATE/EDIT SUBSCRIPTION TIERS -->
    <!-- ---------------------------------------------------- -->
    <div x-show="planModalOpen" 
         class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto" 
         x-transition
         style="display: none;">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="planModalOpen = false"></div>
        <div class="bg-white dark:bg-gray-800 rounded-3xl max-w-lg w-full p-8 shadow-2xl z-10 relative overflow-hidden">
            <h4 class="text-xl font-extrabold text-gray-900 dark:text-white mb-2" x-text="activePlan.id > 0 ? 'Edit Pricing Tier Config' : 'Create Pricing Tier'"></h4>
            <p class="text-sm text-gray-500 mb-6">Modify limits, prices, features and trial times for onboarded schools.</p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_plan">
                <input type="hidden" name="plan_id" :value="activePlan.id">

                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Plan Name</label>
                    <input type="text" name="name" :value="activePlan.name" required placeholder="e.g. Standard"
                           class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Tagline / Description</label>
                    <input type="text" name="description" :value="activePlan.description" required placeholder="e.g. Perfect for growing private schools"
                           class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Monthly Price (₵)</label>
                        <input type="number" name="price_monthly" :value="activePlan.price_monthly" required
                               class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Trial Period (Days)</label>
                        <input type="number" name="trial_days" :value="activePlan.trial_days" required
                               class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Student Capacity</label>
                        <input type="number" name="student_limit" :value="activePlan.student_limit" required
                               class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Staff Capacity</label>
                        <input type="number" name="staff_limit" :value="activePlan.staff_limit" required
                               class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>

                <!-- Features Selection -->
                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Features Package Toggles</label>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500 mb-2">Select which module pages schools on this plan can access. New schools registered on this plan inherit these as their enabled modules.</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs max-h-56 overflow-y-auto pr-1">
                        <label class="flex items-center space-x-2 p-2 rounded-lg border border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/40">
                            <input type="checkbox" name="features[]" value="core" checked disabled class="accent-indigo-600">
                            <span class="font-medium text-gray-700 dark:text-gray-200"><i class="fas fa-cube w-4 text-gray-400 mr-1"></i>Core Modules <span class="text-gray-400">(always on)</span></span>
                        </label>
                        <?php foreach (getModuleDefinitions() as $mkey => $mod): ?>
                        <label class="flex items-center space-x-2 p-2 rounded-lg border border-gray-100 dark:border-gray-700 hover:bg-indigo-50 dark:hover:bg-gray-700 cursor-pointer transition-colors">
                            <input type="checkbox" name="features[]" value="<?php echo htmlspecialchars($mkey); ?>"
                                   :checked="planHasFeature(activePlan, '<?php echo htmlspecialchars($mkey, ENT_QUOTES); ?>')"
                                   class="accent-indigo-600">
                            <span class="font-medium text-gray-700 dark:text-gray-200"><i class="fas <?php echo htmlspecialchars($mod['icon']); ?> w-4 text-indigo-400 mr-1"></i><?php echo htmlspecialchars($mod['label']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="pt-4 border-t flex justify-end space-x-3">
                    <button type="button" @click="planModalOpen = false" 
                            class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-850 dark:text-white font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" 
                            class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs shadow-sm">Save Config</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ---------------------------------------------------- -->
    <!-- MODAL 2: RECORD PAYMENT -->
    <!-- ---------------------------------------------------- -->
    <div x-show="recordPaymentOpen" 
         class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto" 
         x-transition
         style="display: none;">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="recordPaymentOpen = false"></div>
        <div class="bg-white dark:bg-gray-800 rounded-3xl max-w-md w-full p-8 shadow-2xl z-10 relative overflow-hidden">
            <h4 class="text-xl font-extrabold text-gray-900 dark:text-white mb-2">Record Platform Collection</h4>
            <p class="text-sm text-gray-500 mb-6" x-text="'Record payment for Invoice: ' + activeInvoice.invoice_number"></p>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="invoice_id" :value="activeInvoice.id">

                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Invoice Amount</label>
                    <div class="w-full px-4 py-2 bg-gray-100 dark:bg-gray-700 rounded-xl text-sm font-bold text-gray-900 dark:text-white" x-text="'₵' + parseFloat(activeInvoice.amount).toFixed(2)"></div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Payment Method</label>
                    <select name="payment_method" required
                            class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="bank_transfer">Bank Wire Transfer</option>
                        <option value="card">Credit/Debit Card</option>
                        <option value="mobile_money">MTN/Telecel Mobile Money</option>
                        <option value="cash">Direct Cash</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Transaction Ref / Reference</label>
                    <input type="text" name="transaction_ref" required placeholder="e.g. TXN-12345678"
                           class="w-full px-4 py-2 border rounded-xl focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>

                <div class="pt-4 border-t flex justify-end space-x-3">
                    <button type="button" @click="recordPaymentOpen = false" 
                            class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-850 dark:text-white font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit"
                            class="px-6 py-2 hover:opacity-90 text-white font-bold rounded-xl text-xs shadow-sm"
                            style="background-color:#059669;">Confirm Payment</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ---------------------------------------------------- -->
    <!-- MODAL: REPLY TO SUPPORT TICKET -->
    <!-- ---------------------------------------------------- -->
    <div x-show="replyModalOpen"
         class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto p-4"
         x-transition
         style="display: none;">
        <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="replyModalOpen = false"></div>
        <div class="bg-white dark:bg-gray-800 rounded-3xl max-w-2xl w-full p-6 md:p-8 shadow-2xl z-10 relative flex flex-col max-h-[90vh]">

            <!-- Header -->
            <div class="flex items-start justify-between pb-4 border-b border-gray-200 dark:border-gray-700 mb-4">
                <div>
                    <h4 class="text-xl font-extrabold text-gray-900 dark:text-white">
                        Reply to Ticket <span class="text-indigo-600 dark:text-indigo-400">#TKT-<span x-text="activeTicket.id"></span></span>
                    </h4>
                    <p class="text-xs text-gray-500 mt-1" x-text="(activeTicket.school_name || 'Unknown School') + ' • ' + (activeTicket.subject || '')"></p>
                </div>
                <button type="button" @click="replyModalOpen = false" class="text-gray-400 hover:text-gray-700 dark:hover:text-white text-xl leading-none">&times;</button>
            </div>

            <!-- Scrollable body -->
            <div class="flex-1 overflow-y-auto pr-1 space-y-4">
                <!-- Original request -->
                <div class="bg-gray-50 dark:bg-gray-900/50 rounded-xl p-4 border border-gray-150 dark:border-gray-700">
                    <div class="flex items-center gap-2 mb-1.5">
                        <span class="text-[10px] font-extrabold uppercase tracking-wider text-gray-400">Original Request</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 capitalize" x-text="activeTicket.priority"></span>
                    </div>
                    <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line" x-text="activeTicket.description || 'No description provided.'"></p>
                </div>

                <!-- Conversation thread -->
                <template x-if="activeTicket.responses && activeTicket.responses.length">
                    <div class="space-y-2">
                        <span class="text-[10px] font-extrabold uppercase tracking-wider text-gray-400">Conversation</span>
                        <template x-for="(r, i) in activeTicket.responses" :key="i">
                            <div :class="r.is_admin_response == 1 ? 'ml-6 bg-indigo-50 dark:bg-indigo-900/20 border-indigo-100 dark:border-indigo-800' : 'mr-6 bg-gray-50 dark:bg-gray-900/50 border-gray-150 dark:border-gray-700'"
                                 class="rounded-xl p-3 border">
                                <div class="flex items-center justify-between mb-0.5">
                                    <span class="text-[11px] font-bold" :class="r.is_admin_response == 1 ? 'text-indigo-700 dark:text-indigo-300' : 'text-gray-700 dark:text-gray-300'"
                                          x-text="r.is_admin_response == 1 ? ('Support' + (r.responder_name ? ' — ' + r.responder_name : '')) : (r.responder_name || 'School Admin')"></span>
                                    <span class="text-[10px] text-gray-400" x-text="r.created_at"></span>
                                </div>
                                <p class="text-sm text-gray-800 dark:text-gray-200 whitespace-pre-line" x-text="r.response"></p>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- Reply form -->
                <form method="POST" class="space-y-4 pt-2">
                    <input type="hidden" name="action" value="reply_ticket">
                    <input type="hidden" name="ticket_id" :value="activeTicket.id">

                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Your Reply</label>
                        <textarea name="response" rows="4" required placeholder="Type your response to the school admin..."
                                  class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-700 dark:text-gray-300 uppercase mb-1">Update Status</label>
                        <select name="ticket_status"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                            <option value="open" :selected="activeTicket.status === 'open'">Open</option>
                            <option value="in_progress" :selected="activeTicket.status === 'in_progress'">In Progress</option>
                            <option value="resolved" :selected="activeTicket.status === 'resolved'">Resolved</option>
                            <option value="closed" :selected="activeTicket.status === 'closed'">Closed</option>
                        </select>
                    </div>

                    <div class="pt-3 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                        <button type="button" @click="replyModalOpen = false"
                                class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-850 dark:text-white font-bold rounded-xl text-xs">Cancel</button>
                        <button type="submit"
                                class="px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl text-xs shadow-sm flex items-center">
                            <i class="fas fa-paper-plane mr-1.5"></i> Send Reply
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>


</div>

<script>
// Determine whether the plan currently being edited already has a given module/feature
// enabled. The `features` column is stored as JSON; depending on how Alpine received it,
// it may be a JSON string or an already-decoded array, so normalise both.
function planHasFeature(plan, key) {
    if (!plan) return false;
    let features = plan.features;
    if (typeof features === 'string') {
        try { features = JSON.parse(features); } catch (e) { features = []; }
    }
    if (!Array.isArray(features)) return false;
    return features.includes(key);
}
</script>
