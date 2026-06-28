<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/sms_helper.php';
require_once '../../includes/email_helper.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Fetch active classes for group selection
$classes = [];
try {
    $classes_stmt = $db->query("SELECT id, name, grade_level, section FROM classes WHERE status = 'active' ORDER BY name ASC");
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
}

// Fetch all active users (excluding current sender) for search and pick
$all_users = [];
try {
    $users_stmt = $db->query("SELECT id, name, email, role FROM users WHERE status = 'active' AND id != $user_id ORDER BY name ASC");
    $all_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching users: " . $e->getMessage());
}

$success_summary = null;
$error_message = null;

// Handle composition broadcast POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_broadcast'])) {
    $channels = $_POST['channels'] ?? []; // internal, email, sms
    $recipient_type = $_POST['recipient_type'] ?? 'groups'; // groups, individuals
    
    $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $message_content = $_POST['message'] ?? '';
    
    if (empty($channels)) {
        $error_message = "Please select at least one delivery channel (Internal Message, Email, or SMS).";
    } elseif (empty($message_content)) {
        $error_message = "Message content cannot be empty.";
    } elseif ((in_array('internal', $channels) || in_array('email', $channels)) && empty($subject)) {
        $error_message = "A subject is required for Internal Messages and Emails.";
    } else {
        // Resolve Target Users
        $target_user_ids = [];
        
        if ($recipient_type === 'groups') {
            $selected_roles = $_POST['roles'] ?? [];
            $selected_classes = $_POST['classes'] ?? [];
            $class_target = $_POST['class_target'] ?? 'students'; // students, parents, both
            
            // 1. Resolve users by Roles
            if (!empty($selected_roles)) {
                $role_placeholders = implode(',', array_fill(0, count($selected_roles), '?'));
                $role_query = "SELECT id FROM users WHERE role IN ($role_placeholders) AND status = 'active'";
                $role_stmt = $db->prepare($role_query);
                $role_stmt->execute($selected_roles);
                while ($row = $role_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $target_user_ids[$row['id']] = true;
                }
            }
            
            // 2. Resolve users by Class Groups
            if (!empty($selected_classes)) {
                $class_placeholders = implode(',', array_fill(0, count($selected_classes), '?'));
                
                // Get students in classes
                if ($class_target === 'students' || $class_target === 'both') {
                    $student_query = "
                        SELECT u.id 
                        FROM users u
                        JOIN student_classes sc ON u.id = sc.student_id
                        WHERE sc.class_id IN ($class_placeholders) AND sc.status = 'active' AND u.status = 'active'";
                    $student_stmt = $db->prepare($student_query);
                    $student_stmt->execute($selected_classes);
                    while ($row = $student_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $target_user_ids[$row['id']] = true;
                    }
                }
                
                // Get parents of students in classes
                if ($class_target === 'parents' || $class_target === 'both') {
                    $parent_query = "
                        SELECT DISTINCT ps.parent_id as id 
                        FROM parent_students ps
                        JOIN student_classes sc ON ps.student_id = sc.student_id
                        JOIN users u ON ps.parent_id = u.id
                        WHERE sc.class_id IN ($class_placeholders) AND sc.status = 'active' AND u.status = 'active'";
                    $parent_stmt = $db->prepare($parent_query);
                    $parent_stmt->execute($selected_classes);
                    while ($row = $parent_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $target_user_ids[$row['id']] = true;
                    }
                }
            }
        } else {
            // Pick Selected Individuals
            $selected_individual_ids = $_POST['individual_ids'] ?? [];
            foreach ($selected_individual_ids as $ind_id) {
                $target_user_ids[intval($ind_id)] = true;
            }
        }
        
        $resolved_user_ids = array_keys($target_user_ids);
        
        if (empty($resolved_user_ids)) {
            $error_message = "No matching active recipients found for the selected targets.";
        } else {
            // Resolve contact info for all resolved recipients
            $recipients_data = [];
            
            // Fetch users basic details
            $users_in_placeholders = implode(',', array_fill(0, count($resolved_user_ids), '?'));
            $details_query = "SELECT id, name, email, role FROM users WHERE id IN ($users_in_placeholders)";
            $details_stmt = $db->prepare($details_query);
            $details_stmt->execute($resolved_user_ids);
            $users_basics = $details_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($users_basics as $user) {
                $uid = $user['id'];
                $urole = $user['role'];
                $phone = null;
                
                // Query roles profiles for phone numbers
                if ($urole === 'student') {
                    $prof_stmt = $db->prepare("SELECT phone FROM student_profiles WHERE user_id = ?");
                    $prof_stmt->execute([$uid]);
                    $prof = $prof_stmt->fetch(PDO::FETCH_ASSOC);
                    $phone = $prof ? $prof['phone'] : null;
                } elseif ($urole === 'parent') {
                    // Pull guardian phone from linked student profile
                    $prof_stmt = $db->prepare("
                        SELECT sp.guardian_phone 
                        FROM parent_students ps
                        JOIN student_profiles sp ON ps.student_id = sp.user_id
                        WHERE ps.parent_id = ?
                        LIMIT 1");
                    $prof_stmt->execute([$uid]);
                    $prof = $prof_stmt->fetch(PDO::FETCH_ASSOC);
                    $phone = $prof ? $prof['guardian_phone'] : null;
                } else {
                    // Staff
                    $prof_stmt = $db->prepare("SELECT phone FROM teacher_profiles WHERE user_id = ?");
                    $prof_stmt->execute([$uid]);
                    $prof = $prof_stmt->fetch(PDO::FETCH_ASSOC);
                    $phone = $prof ? $prof['phone'] : null;
                }
                
                $recipients_data[] = [
                    'id' => $uid,
                    'name' => $user['name'],
                    'email' => $user['email'],
                    'phone' => $phone
                ];
            }
            
            // Broadcast execution
            $internal_count = 0;
            $email_count = 0;
            $sms_count = 0;

            $email_failed = 0;
            $sms_failed = 0;
            $sms_no_phone = 0;
            $sms_errors = []; // unique gateway error reasons, for diagnostics

            $sms_gateway_stmt = $db->query("SELECT sms_gateway FROM school_settings LIMIT 1");
            $gateway_row = $sms_gateway_stmt->fetch(PDO::FETCH_ASSOC);
            $sms_gateway = $gateway_row['sms_gateway'] ?? 'disabled';
            
            foreach ($recipients_data as $recipient) {
                // 1. Internal Message
                if (in_array('internal', $channels)) {
                    try {
                        $msg_stmt = $db->prepare("
                            INSERT INTO messages (sender_id, recipient_id, subject, content, is_read, sent_at)
                            VALUES (:sender_id, :recipient_id, :subject, :content, FALSE, NOW())");
                        $msg_stmt->execute([
                            ':sender_id' => $user_id,
                            ':recipient_id' => $recipient['id'],
                            ':subject' => $subject,
                            ':content' => $message_content
                        ]);
                        $internal_count++;
                    } catch (PDOException $e) {
                        error_log("Failed to insert internal message to {$recipient['id']}: " . $e->getMessage());
                    }
                }
                
                // 2. Email Channel
                if (in_array('email', $channels)) {
                    if (!empty($recipient['email'])) {
                        $email_res = sendEmail($recipient['email'], $subject, nl2br($message_content));
                        if ($email_res['success']) {
                            $email_count++;
                        } else {
                            $email_failed++;
                        }
                    } else {
                        $email_failed++;
                    }
                }
                
                // 3. SMS Channel
                if (in_array('sms', $channels)) {
                    if (!empty($recipient['phone'])) {
                        $sms_res = sendSMS($recipient['phone'], $message_content);
                        if ($sms_res['success']) {
                            $sms_count++;
                            logSMS($recipient['phone'], $message_content, 'success', $sms_gateway, $sms_res);
                        } else {
                            $sms_failed++;
                            // Capture the real reason (gateway disabled, rejected, etc.)
                            // so it can be shown instead of a vague "failed".
                            if (!empty($sms_res['message'])) {
                                $sms_errors[$sms_res['message']] = true;
                            }
                            logSMS($recipient['phone'], $message_content, 'failed', $sms_gateway, $sms_res);
                        }
                    } else {
                        // Recipient genuinely has no phone number on file.
                        $sms_no_phone++;
                    }
                }
            }
            
            $success_summary = [
                'total_recipients' => count($recipients_data),
                'internal' => $internal_count,
                'email' => $email_count,
                'email_failed' => $email_failed,
                'sms' => $sms_count,
                'sms_failed' => $sms_failed,
                'sms_no_phone' => $sms_no_phone,
                'sms_errors' => array_keys($sms_errors),
                'channels' => $channels
            ];
        }
    }
}

$title = "Compose Message";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Communication', 'url' => '../index.php'],
    ['title' => 'Messages', 'url' => 'index.php'],
    ['title' => 'Compose Message']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-5xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white flex items-center">
                        <i class="fas fa-paper-plane mr-3 text-indigo-600 dark:text-indigo-400"></i>
                        Compose Broadcast Message
                    </h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium px-4 py-2 rounded-lg transition-colors duration-150 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Messages
                    </a>
                </div>

                <?php if ($success_summary): ?>
                <div class="bg-green-50 border border-green-200 dark:bg-green-950/20 dark:border-green-900 text-green-800 dark:text-green-300 rounded-xl p-6 mb-6 shadow-md">
                    <h3 class="text-lg font-bold mb-2 flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2 text-xl"></i>
                        Message Broadcast Complete!
                    </h3>
                    <p class="text-sm mb-4">Your message was processed and broadcast to <b><?php echo $success_summary['total_recipients']; ?></b> unique recipient(s).</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs font-semibold uppercase">
                        <?php if (in_array('internal', $success_summary['channels'])): ?>
                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-green-100 dark:border-green-900/30">
                            <span class="text-gray-500 block text-[10px]">Internal Inbox</span>
                            <span class="text-green-600 dark:text-green-400 text-base font-bold"><?php echo $success_summary['internal']; ?> sent</span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (in_array('email', $success_summary['channels'])): ?>
                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-green-100 dark:border-green-900/30">
                            <span class="text-gray-500 block text-[10px]">Email Delivery</span>
                            <span class="text-green-600 dark:text-green-400 text-base font-bold"><?php echo $success_summary['email']; ?> sent</span>
                            <?php if ($success_summary['email_failed'] > 0): ?>
                            <span class="text-red-500 text-[10px] block mt-1"><?php echo $success_summary['email_failed']; ?> unavailable/failed</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (in_array('sms', $success_summary['channels'])): ?>
                        <div class="p-3 bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-green-100 dark:border-green-900/30">
                            <span class="text-gray-500 block text-[10px]">SMS Notifications</span>
                            <span class="text-green-600 dark:text-green-400 text-base font-bold"><?php echo $success_summary['sms']; ?> sent</span>
                            <?php if (($success_summary['sms_no_phone'] ?? 0) > 0): ?>
                            <span class="text-amber-500 text-[10px] block mt-1"><?php echo $success_summary['sms_no_phone']; ?> no phone on file</span>
                            <?php endif; ?>
                            <?php if ($success_summary['sms_failed'] > 0): ?>
                            <span class="text-red-500 text-[10px] block mt-1"><?php echo $success_summary['sms_failed']; ?> failed at gateway</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($success_summary['sms_errors'])): ?>
                    <div class="mt-4 p-3 bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-900/40 rounded-lg normal-case">
                        <p class="text-[11px] font-bold text-red-700 dark:text-red-400 mb-1 flex items-center">
                            <i class="fas fa-triangle-exclamation mr-1"></i> SMS gateway reported:
                        </p>
                        <ul class="list-disc list-inside text-[11px] text-red-600 dark:text-red-300 space-y-0.5 font-normal">
                            <?php foreach ($success_summary['sms_errors'] as $err): ?>
                            <li><?php echo htmlspecialchars($err); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="text-[10px] text-red-500/80 dark:text-red-400/70 mt-2 font-normal">
                            Check your provider, API key/secret and sender ID under Settings &rsaquo; SMS Integration.
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-50 border border-red-200 dark:bg-red-950/20 dark:border-red-900 text-red-800 dark:text-red-300 rounded-xl p-4 mb-6 flex items-center">
                    <i class="fas fa-exclamation-triangle mr-3 text-lg text-red-500"></i>
                    <p class="text-sm font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>

                <!-- Composition Card -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <form method="POST" class="p-6 lg:p-8 space-y-8">
                        <input type="hidden" name="send_broadcast" value="1">
                        
                        <!-- 1. Channel Selection -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-3 flex items-center">
                                <span class="bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 w-7 h-7 rounded-full flex items-center justify-center text-sm mr-2 font-bold">1</span>
                                Select Delivery Channels
                            </h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Choose one or more ways to deliver this message.</p>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <!-- Channel Checkbox: Internal -->
                                <label class="relative flex items-start p-4 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" name="channels[]" value="internal" checked
                                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 dark:bg-gray-700">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <span class="font-semibold text-gray-900 dark:text-white block"><i class="fas fa-comments text-indigo-500 mr-1"></i> Internal Message</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Delivered directly to user's system inbox</span>
                                    </div>
                                </label>

                                <!-- Channel Checkbox: Email -->
                                <label class="relative flex items-start p-4 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" name="channels[]" value="email" id="email-channel"
                                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 dark:bg-gray-700">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <span class="font-semibold text-gray-900 dark:text-white block"><i class="fas fa-envelope text-blue-500 mr-1"></i> Email Broadcast</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Sent to target email addresses via SMTP</span>
                                    </div>
                                </label>

                                <!-- Channel Checkbox: SMS -->
                                <label class="relative flex items-start p-4 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                                    <div class="flex items-center h-5">
                                        <input type="checkbox" name="channels[]" value="sms" id="sms-channel"
                                            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 dark:bg-gray-700">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <span class="font-semibold text-gray-900 dark:text-white block"><i class="fas fa-sms text-green-500 mr-1"></i> SMS Gateway</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Sent to mobile numbers (160 char limit warning)</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- 2. Target Recipients -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-3 flex items-center">
                                <span class="bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 w-7 h-7 rounded-full flex items-center justify-center text-sm mr-2 font-bold">2</span>
                                Target Recipients
                            </h3>

                            <!-- Recipient Type Segmented Control -->
                            <div class="flex bg-gray-100 dark:bg-gray-900 p-1.5 rounded-lg max-w-sm mb-6 border border-gray-200 dark:border-gray-800">
                                <button type="button" id="btn-type-groups" onclick="setRecipientType('groups')"
                                    class="flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 bg-white dark:bg-gray-800 text-indigo-600 dark:text-indigo-400 shadow-sm">
                                    <i class="fas fa-users mr-2"></i>Send by Groups
                                </button>
                                <button type="button" id="btn-type-individuals" onclick="setRecipientType('individuals')"
                                    class="flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white">
                                    <i class="fas fa-user mr-2"></i>Send to Individuals
                                </button>
                            </div>
                            <input type="hidden" name="recipient_type" id="recipient_type" value="groups">

                            <!-- Groups Selection Section -->
                            <div id="section-groups" class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Role Checklist -->
                                    <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800">
                                        <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-3 border-b border-gray-200 dark:border-gray-700 pb-2">Target Roles</h4>
                                        <div class="space-y-2">
                                            <label class="flex items-center">
                                                <input type="checkbox" name="roles[]" value="student" class="w-4 h-4 text-indigo-600 border-gray-300 rounded dark:bg-gray-700">
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">All Students</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input type="checkbox" name="roles[]" value="parent" class="w-4 h-4 text-indigo-600 border-gray-300 rounded dark:bg-gray-700">
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">All Parents</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input type="checkbox" name="roles[]" value="teacher" class="w-4 h-4 text-indigo-600 border-gray-300 rounded dark:bg-gray-700">
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">All Teachers</span>
                                            </label>
                                            <label class="flex items-center">
                                                <input type="checkbox" name="roles[]" value="librarian" class="w-4 h-4 text-indigo-600 border-gray-300 rounded dark:bg-gray-700">
                                                <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Staff (Other Roles)</span>
                                            </label>
                                        </div>
                                    </div>

                                    <!-- Class Checklist -->
                                    <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800 flex flex-col">
                                        <h4 class="text-sm font-bold text-gray-900 dark:text-white mb-3 border-b border-gray-200 dark:border-gray-700 pb-2 flex justify-between items-center">
                                            <span>Target Class Groups</span>
                                            <!-- Class Target Type Selector -->
                                            <select name="class_target" class="text-xs bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-700 rounded px-2 py-0.5 font-medium text-gray-700 dark:text-gray-300 focus:outline-none">
                                                <option value="students">Students only</option>
                                                <option value="parents">Parents only</option>
                                                <option value="both">Both Students & Parents</option>
                                            </select>
                                        </h4>
                                        <div class="space-y-2 overflow-y-auto max-h-40 pr-1 flex-1">
                                            <?php if (!empty($classes)): ?>
                                                <?php foreach ($classes as $class): ?>
                                                <label class="flex items-center">
                                                    <input type="checkbox" name="classes[]" value="<?php echo $class['id']; ?>" class="w-4 h-4 text-indigo-600 border-gray-300 rounded dark:bg-gray-700">
                                                    <span class="ml-2 text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($class['name'] . ' (' . $class['grade_level'] . ')'); ?></span>
                                                </label>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-xs text-gray-400">No active classes found.</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Individuals Selection Section -->
                            <div id="section-individuals" class="hidden space-y-4">
                                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl border border-gray-200 dark:border-gray-800">
                                    <div class="relative mb-4">
                                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                        <input type="text" id="user-search" oninput="filterUserList()" placeholder="Type to search users by name or role..."
                                            class="w-full pl-9 pr-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg dark:bg-gray-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <!-- Multi-select user catalog list -->
                                        <div>
                                            <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-2 uppercase">Matching Users (Click to add)</h4>
                                            <div id="user-pool-container" class="space-y-1 overflow-y-auto max-h-60 pr-1 border border-gray-200 dark:border-gray-700 rounded-lg p-2 bg-white dark:bg-gray-800">
                                                <!-- Dynamic load -->
                                            </div>
                                        </div>
                                        
                                        <!-- Selected recipients basket -->
                                        <div>
                                            <h4 class="text-xs font-bold text-gray-500 dark:text-gray-400 mb-2 uppercase flex justify-between">
                                                <span>Selected Recipients</span>
                                                <button type="button" onclick="clearSelectedUsers()" class="text-red-500 hover:text-red-700 hover:underline lowercase font-normal">Clear all</button>
                                            </h4>
                                            <div id="user-basket-container" class="space-y-1 overflow-y-auto max-h-60 pr-1 border border-gray-200 dark:border-gray-700 rounded-lg p-2 bg-white dark:bg-gray-800 flex flex-col">
                                                <div id="basket-empty-msg" class="text-xs text-gray-400 text-center py-10 flex-1 flex flex-col items-center justify-center">
                                                    <i class="fas fa-users-cog text-2xl mb-2"></i>
                                                    No recipients selected yet.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Message Drafting -->
                        <div>
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                                    <span class="bg-indigo-100 dark:bg-indigo-900/50 text-indigo-600 dark:text-indigo-400 w-7 h-7 rounded-full flex items-center justify-center text-sm mr-2 font-bold">3</span>
                                    Draft Message
                                </h3>
                                <button type="button" onclick="openDraftAI({ contentType: draftAiDetectType(), subjectField: 'subject', bodyField: 'message' })"
                                    class="inline-flex items-center text-sm font-medium px-3 py-2 rounded-lg text-white bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-700 hover:to-indigo-700 shadow-md transition-all duration-200">
                                    <i class="fas fa-wand-magic-sparkles mr-2"></i>Draft with AI
                                </button>
                            </div>
                            
                            <div class="space-y-4">
                                <div>
                                    <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Subject Line <span class="text-red-500" id="subject-required">*</span>
                                    </label>
                                    <input type="text" id="subject" name="subject" required
                                        placeholder="Enter message subject"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-white text-sm">
                                </div>
                                
                                <div>
                                    <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                        Message Content <span class="text-red-500">*</span>
                                    </label>
                                    <textarea id="message" name="message" rows="8" required
                                        placeholder="Type your message content here..."
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-900 dark:text-white text-sm"></textarea>
                                    
                                    <div class="flex justify-between items-center mt-2">
                                        <span id="sms-warning" class="text-xs text-yellow-600 dark:text-yellow-400 hidden">
                                            <i class="fas fa-exclamation-circle mr-1"></i> SMS channel active. Keep message short to avoid splitting.
                                        </span>
                                        <span id="char-counter" class="text-xs text-gray-400 ml-auto font-mono">0 characters</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Action Submit -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                            <button type="submit" 
                                class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-xl transition-all duration-200 flex items-center shadow-lg hover:shadow-indigo-600/30">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Send Broadcast Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>
        
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<?php include '../../includes/draft_ai_modal.php'; ?>
<script>
// Draft AI endpoint relative to communication/messages/
window.DRAFT_AI_ENDPOINT = '../draft_ai.php';

// Pick the most relevant content type based on the channels currently selected.
function draftAiDetectType() {
    const sms = document.getElementById('sms-channel');
    const email = document.getElementById('email-channel');
    if (sms && sms.checked && !(email && email.checked)) { return 'sms'; }
    if (email && email.checked) { return 'email'; }
    return 'general';
}
</script>
<script>
// JSON catalog of all active users
const usersCatalog = <?php echo json_encode($all_users); ?>;
const selectedUserIds = new Set();

function setRecipientType(type) {
    const btnGroups = document.getElementById('btn-type-groups');
    const btnIndivs = document.getElementById('btn-type-individuals');
    const secGroups = document.getElementById('section-groups');
    const secIndivs = document.getElementById('section-individuals');
    const inputType = document.getElementById('recipient_type');
    
    inputType.value = type;
    
    if (type === 'groups') {
        btnGroups.className = "flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 bg-white dark:bg-gray-800 text-indigo-600 dark:text-indigo-400 shadow-sm";
        btnIndivs.className = "flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white";
        secGroups.classList.remove('hidden');
        secIndivs.classList.add('hidden');
    } else {
        btnIndivs.className = "flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 bg-white dark:bg-gray-800 text-indigo-600 dark:text-indigo-400 shadow-sm";
        btnGroups.className = "flex-1 py-2 text-sm font-semibold rounded-md transition-all duration-150 text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white";
        secIndivs.classList.remove('hidden');
        secGroups.classList.add('hidden');
        renderUserCatalog();
    }
}

// Generate the search list of users
function renderUserCatalog(query = '') {
    const container = document.getElementById('user-pool-container');
    container.innerHTML = '';
    
    const term = query.toLowerCase().trim();
    
    const matches = usersCatalog.filter(user => {
        // Exclude already selected
        if (selectedUserIds.has(user.id)) return false;
        
        if (term === '') return true;
        const nameMatch = user.name.toLowerCase().includes(term);
        const roleMatch = user.role.toLowerCase().includes(term);
        const emailMatch = user.email.toLowerCase().includes(term);
        return nameMatch || roleMatch || emailMatch;
    });
    
    if (matches.length === 0) {
        container.innerHTML = `<div class="text-xs text-gray-400 text-center py-4">No users match search.</div>`;
        return;
    }
    
    matches.forEach(user => {
        const div = document.createElement('div');
        div.className = "flex justify-between items-center p-2 rounded hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer text-xs transition-colors duration-100";
        div.onclick = () => selectUser(user);
        
        const roleLabel = getRoleLabel(user.role);
        
        div.innerHTML = `
            <div class="min-w-0">
                <span class="font-bold text-gray-800 dark:text-gray-200 block truncate">${escapeHtml(user.name)}</span>
                <span class="text-gray-400 text-[10px] block truncate">${escapeHtml(user.email)}</span>
            </div>
            <span class="px-2 py-0.5 font-semibold text-[9px] rounded-full uppercase bg-indigo-50 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 ml-2 whitespace-nowrap">${roleLabel}</span>
        `;
        container.appendChild(div);
    });
}

function selectUser(user) {
    selectedUserIds.add(user.id);
    renderUserCatalog(document.getElementById('user-search').value);
    renderUserBasket();
}

function deselectUser(userId) {
    selectedUserIds.delete(userId);
    renderUserCatalog(document.getElementById('user-search').value);
    renderUserBasket();
}

function clearSelectedUsers() {
    selectedUserIds.clear();
    renderUserCatalog(document.getElementById('user-search').value);
    renderUserBasket();
}

function renderUserBasket() {
    const container = document.getElementById('user-basket-container');
    
    // Clear everything except base empty message
    const emptyMsg = document.getElementById('basket-empty-msg');
    
    // Remove old basket nodes
    const oldItems = container.querySelectorAll('.basket-item');
    oldItems.forEach(node => node.remove());
    
    if (selectedUserIds.size === 0) {
        emptyMsg.classList.remove('hidden');
        return;
    }
    
    emptyMsg.classList.add('hidden');
    
    selectedUserIds.forEach(id => {
        const user = usersCatalog.find(u => u.id === id);
        if (!user) return;
        
        const div = document.createElement('div');
        div.className = "basket-item flex justify-between items-center p-2 bg-gray-50 dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-800 text-xs transition-colors duration-100";
        
        // Add hidden input to post recipient user ids
        div.innerHTML = `
            <input type="hidden" name="individual_ids[]" value="${user.id}">
            <div class="min-w-0">
                <span class="font-bold text-gray-800 dark:text-gray-200 block truncate">${escapeHtml(user.name)}</span>
                <span class="text-gray-400 text-[10px] block truncate">${getRoleLabel(user.role)}</span>
            </div>
            <button type="button" onclick="deselectUser(${user.id})" class="text-red-500 hover:text-red-700 p-1 ml-2 text-sm focus:outline-none">
                <i class="fas fa-times"></i>
            </button>
        `;
        container.appendChild(div);
    });
}

function filterUserList() {
    const query = document.getElementById('user-search').value;
    renderUserCatalog(query);
}

function getRoleLabel(role) {
    return role.replace('_', ' ');
}

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// SMS Warning character display
const messageTextarea = document.getElementById('message');
const charCounter = document.getElementById('char-counter');
const smsChannel = document.getElementById('sms-channel');
const emailChannel = document.getElementById('email-channel');
const internalChannel = document.querySelector('input[value="internal"]');
const smsWarning = document.getElementById('sms-warning');
const subjectInput = document.getElementById('subject');
const subjectRequired = document.getElementById('subject-required');

function updateCountersAndValidation() {
    const len = messageTextarea.value.length;
    charCounter.textContent = `${len} characters`;
    
    const smsActive = smsChannel.checked;
    if (smsActive) {
        smsWarning.classList.remove('hidden');
    } else {
        smsWarning.classList.add('hidden');
    }
    
    // Subject is only required if internal message or email channel is active
    const isSubjectRequired = emailChannel.checked || internalChannel.checked;
    if (isSubjectRequired) {
        subjectInput.required = true;
        subjectRequired.classList.remove('hidden');
    } else {
        subjectInput.required = false;
        subjectRequired.classList.add('hidden');
    }
}

messageTextarea.addEventListener('input', updateCountersAndValidation);
smsChannel.addEventListener('change', updateCountersAndValidation);
emailChannel.addEventListener('change', updateCountersAndValidation);
internalChannel.addEventListener('change', updateCountersAndValidation);

// Auto-select user if reply_to is specified in the URL query string
window.addEventListener('DOMContentLoaded', (event) => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('reply_to')) {
        const replyToId = parseInt(urlParams.get('reply_to'));
        const user = usersCatalog.find(u => u.id === replyToId);
        if (user) {
            setRecipientType('individuals');
            selectUser(user);
        }
    }
});

// Initial call
updateCountersAndValidation();
</script>