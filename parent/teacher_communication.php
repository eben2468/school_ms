<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.student_id, c.name as class_name, c.section
    FROM users u
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.role = 'student'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $user_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

$student_id = $_GET['student_id'] ?? null;

// Validate student belongs to parent
if ($student_id) {
    $valid_student = false;
    foreach ($children as $child) {
        if ($child['id'] == $student_id) {
            $valid_student = true;
            $selected_student = $child;
            break;
        }
    }
    if (!$valid_student) {
        $student_id = null;
    }
}

// Get teachers for selected student
$teachers = [];
if ($student_id) {
    $teachers_query = "
        SELECT DISTINCT u.id, u.name, u.email, s.name as subject_name
        FROM users u
        INNER JOIN subjects s ON u.id = s.teacher_id
        INNER JOIN classes c ON s.class_id = c.id
        WHERE c.id = (SELECT class_id FROM users WHERE id = :student_id)
        AND u.role = 'teacher'
        ORDER BY u.name
    ";
    $teachers_stmt = $db->prepare($teachers_query);
    $teachers_stmt->bindParam(':student_id', $student_id);
    $teachers_stmt->execute();
    $teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get messages for selected student
$messages = [];
if ($student_id) {
    $messages_query = "
        SELECT m.*, 
               sender.name as sender_name,
               recipient.name as recipient_name
        FROM communication_messages m
        LEFT JOIN users sender ON m.sender_id = sender.id
        LEFT JOIN users recipient ON m.recipient_id = recipient.id
        WHERE (m.sender_id = :user_id OR m.recipient_id = :user_id)
        AND m.status != 'draft'
        ORDER BY m.created_at DESC
        LIMIT 20
    ";
    $messages_stmt = $db->prepare($messages_query);
    $messages_stmt->bindParam(':user_id', $user_id);
    $messages_stmt->execute();
    $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle sending new message
if ($_POST && isset($_POST['send_message'])) {
    $teacher_id = $_POST['teacher_id'];
    $subject = $_POST['subject'];
    $message = $_POST['message'];
    $priority = $_POST['priority'] ?? 'medium';
    
    try {
        $insert_query = "
            INSERT INTO communication_messages (sender_id, recipient_id, subject, message, priority, status, sent_at)
            VALUES (:sender_id, :recipient_id, :subject, :message, :priority, 'sent', NOW())
        ";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':sender_id', $user_id);
        $insert_stmt->bindParam(':recipient_id', $teacher_id);
        $insert_stmt->bindParam(':subject', $subject);
        $insert_stmt->bindParam(':message', $message);
        $insert_stmt->bindParam(':priority', $priority);
        $insert_stmt->execute();
        
        $success_message = "Message sent successfully!";
        
        // Refresh messages
        header("Location: teacher_communication.php?student_id=" . $student_id . "&success=1");
        exit();
    } catch (PDOException $e) {
        $error_message = "Failed to send message. Please try again.";
    }
}

$title = "Teacher Communication";
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
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Teacher Communication</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Communicate with your child's teachers</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Success Message -->
                <?php if (isset($_GET['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i>Message sent successfully!
                </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <a href="?student_id=<?php echo $child['id']; ?>" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white block"><?php echo htmlspecialchars($child['name']); ?></span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($child['class_name']); ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_id && isset($selected_student)): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($selected_student['name']); ?></h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo htmlspecialchars($selected_student['class_name']); ?>
                                    <?php if ($selected_student['student_id']): ?>
                                    • ID: <?php echo htmlspecialchars($selected_student['student_id']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        <button onclick="openMessageModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-plus mr-2"></i>New Message
                        </button>
                    </div>
                </div>

                <!-- Teachers List -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Teachers</h3>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($teachers)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($teachers as $teacher): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mr-3">
                                        <i class="fas fa-chalkboard-teacher text-green-600 dark:text-green-400"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($teacher['name']); ?></h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($teacher['subject_name']); ?></p>
                                    </div>
                                </div>
                                <button onclick="openMessageModal(<?php echo $teacher['id']; ?>, '<?php echo addslashes($teacher['name']); ?>')" 
                                        class="mt-3 w-full text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                                    Send Message
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-600 dark:text-gray-400">No teachers found for this class.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Messages -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Messages</h3>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($messages)): ?>
                        <div class="space-y-4">
                            <?php foreach ($messages as $msg): ?>
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                                <div class="flex items-start justify-between mb-2">
                                    <div>
                                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($msg['subject']); ?></h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php if ($msg['sender_id'] == $user_id): ?>
                                            To: <?php echo htmlspecialchars($msg['recipient_name']); ?>
                                            <?php else: ?>
                                            From: <?php echo htmlspecialchars($msg['sender_name']); ?>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($msg['priority']) {
                                                case 'high': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                case 'medium': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                default: echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($msg['priority']); ?>
                                        </span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            <?php echo date('M j, Y g:i A', strtotime($msg['created_at'])); ?>
                                        </p>
                                    </div>
                                </div>
                                <p class="text-gray-700 dark:text-gray-300"><?php echo nl2br(htmlspecialchars($msg['message'])); ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-comments text-gray-400 text-4xl mb-4"></i>
                            <p class="text-gray-600 dark:text-gray-400">No messages yet. Start a conversation with your child's teachers.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Children Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No student records are associated with your account.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div id="messageModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Send Message</h3>
                <form method="POST">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">To Teacher</label>
                        <select id="modal_teacher_id" name="teacher_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                            <option value="">Select Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name'] . ' - ' . $teacher['subject_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                        <input type="text" name="subject" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority</label>
                        <select name="priority" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Message</label>
                        <textarea name="message" rows="4" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeMessageModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="send_message" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openMessageModal(teacherId = null, teacherName = null) {
    if (teacherId) {
        document.getElementById('modal_teacher_id').value = teacherId;
    }
    document.getElementById('messageModal').classList.remove('hidden');
}

function closeMessageModal() {
    document.getElementById('messageModal').classList.add('hidden');
}
</script>
