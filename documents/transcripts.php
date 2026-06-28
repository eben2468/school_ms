<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle transcript request submission
if ($_POST && isset($_POST['submit_request'])) {
    $student_id = $_POST['student_id'];
    $request_type = $_POST['request_type'];
    $purpose = $_POST['purpose'];
    $delivery_method = $_POST['delivery_method'];
    $delivery_address = $_POST['delivery_address'] ?? '';

    try {
        $insert_query = "
            INSERT INTO transcript_requests
            (student_id, requested_by, request_type, purpose, delivery_method, delivery_address, status, created_at)
            VALUES (:student_id, :requested_by, :request_type, :purpose, :delivery_method, :delivery_address, 'pending', NOW())
        ";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':student_id', $student_id);
        $insert_stmt->bindParam(':requested_by', $user_id);
        $insert_stmt->bindParam(':request_type', $request_type);
        $insert_stmt->bindParam(':purpose', $purpose);
        $insert_stmt->bindParam(':delivery_method', $delivery_method);
        $insert_stmt->bindParam(':delivery_address', $delivery_address);
        $insert_stmt->execute();

        $success_message = "Transcript request submitted successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to submit request: " . $e->getMessage();
    }
}

// Get transcript requests
$requests = [];
try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Admins can see all requests
        $requests_query = "
            SELECT tr.*, s.name as student_name, s.student_id as student_number,
                   r.name as requester_name, p.name as processor_name
            FROM transcript_requests tr
            LEFT JOIN users s ON tr.student_id = s.id
            LEFT JOIN users r ON tr.requested_by = r.id
            LEFT JOIN users p ON tr.processed_by = p.id
            ORDER BY tr.created_at DESC
        ";
        $requests_stmt = $db->prepare($requests_query);
        $requests_stmt->execute();
        $requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Students/Parents can only see their own requests
        $requests_query = "
            SELECT tr.*, s.name as student_name, s.student_id as student_number,
                   r.name as requester_name, p.name as processor_name
            FROM transcript_requests tr
            LEFT JOIN users s ON tr.student_id = s.id
            LEFT JOIN users r ON tr.requested_by = r.id
            LEFT JOIN users p ON tr.processed_by = p.id
            WHERE tr.requested_by = :user_id
            ORDER BY tr.created_at DESC
        ";
        $requests_stmt = $db->prepare($requests_query);
        $requests_stmt->bindParam(':user_id', $user_id);
        $requests_stmt->execute();
        $requests = $requests_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $requests = [];
}

// Get students for dropdown (for admins and parents)
$students = [];
try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        $students_query = "SELECT id, name, student_id FROM users WHERE role = 'student' ORDER BY name";
        $students_stmt = $db->prepare($students_query);
        $students_stmt->execute();
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'parent') {
        $students_query = "
            SELECT u.id, u.name, u.student_id
            FROM users u
            INNER JOIN parent_students ps ON u.id = ps.student_id
            WHERE ps.parent_id = :parent_id
            ORDER BY u.name
        ";
        $students_stmt = $db->prepare($students_query);
        $students_stmt->bindParam(':parent_id', $user_id);
        $students_stmt->execute();
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'student') {
        $students = [['id' => $user_id, 'name' => $_SESSION['name'], 'student_id' => $_SESSION['student_id'] ?? '']];
    }
} catch (PDOException $e) {
    $students = [];
}

$title = "Student Transcripts";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Student Transcripts</h1>
                                <p class="text-blue-100 text-lg">Archive and generate official student transcripts and academic records</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-scroll mr-2"></i>
                                        Document Management
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-scroll text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end items-center mb-6">
                    <div class="flex space-x-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                        <button onclick="showRequestModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i>Generate Transcript
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <!-- Request New Transcript -->
                <?php if (!empty($students)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Request New Transcript</h2>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student</label>
                                    <select name="student_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                        <option value="<?php echo $student['id']; ?>">
                                            <?php echo htmlspecialchars($student['name']); ?>
                                            <?php if ($student['student_id']): ?>
                                            (ID: <?php echo htmlspecialchars($student['student_id']); ?>)
                                            <?php endif; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Request Type</label>
                                    <select name="request_type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Type</option>
                                        <option value="official">Official Transcript</option>
                                        <option value="unofficial">Unofficial Transcript</option>
                                        <option value="electronic">Electronic Transcript</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Purpose</label>
                                <input type="text" name="purpose" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="e.g., College Application, Job Application">
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Delivery Method</label>
                                    <select name="delivery_method" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Method</option>
                                        <option value="pickup">Pickup from School</option>
                                        <option value="mail">Mail to Address</option>
                                        <option value="email">Email (Electronic Only)</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Delivery Address (if applicable)</label>
                                    <textarea name="delivery_address" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter mailing address or email"></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" name="submit_request" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Request
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Transcript Requests -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Transcript Requests</h2>
                    </div>

                    <?php if (empty($requests)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-scroll text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Transcript Requests</h3>
                        <p class="text-gray-500 dark:text-gray-400">No transcript requests have been submitted yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Purpose</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Delivery</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Requested</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($requests as $request): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($request['student_name']); ?></div>
                                            <?php if ($request['student_number']): ?>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($request['student_number']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo ucfirst($request['request_type']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($request['purpose']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo ucfirst(str_replace('_', ' ', $request['delivery_method'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($request['status']) {
                                                case 'pending': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                case 'processing': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'ready': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'delivered': echo 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'; break;
                                                case 'cancelled': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, Y', strtotime($request['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($request['status'] === 'ready'): ?>
                                        <button onclick="downloadTranscript(<?php echo $request['id']; ?>)" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium mr-3">
                                            Download
                                        </button>
                                        <?php endif; ?>

                                        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal']) && $request['status'] === 'pending'): ?>
                                        <button onclick="processRequest(<?php echo $request['id']; ?>)" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium mr-3">
                                            Process
                                        </button>
                                        <?php endif; ?>

                                        <button onclick="viewDetails(<?php echo $request['id']; ?>)" class="text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-300 font-medium">
                                            Details
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function downloadTranscript(requestId) {
    window.location.href = `transcript_download.php?id=${requestId}`;
}

function processRequest(requestId) {
    if (confirm('Mark this transcript request as processed and ready for delivery?')) {
        fetch('process_transcript.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ request_id: requestId, action: 'process' })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('Request processed successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while processing the request.');
        });
    }
}

function viewDetails(requestId) {
    // Open details modal or redirect to details page
    window.location.href = `transcript_details.php?id=${requestId}`;
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function(e) {
            const deliveryMethod = document.querySelector('select[name="delivery_method"]').value;
            const deliveryAddress = document.querySelector('textarea[name="delivery_address"]').value;

            if ((deliveryMethod === 'mail' || deliveryMethod === 'email') && !deliveryAddress.trim()) {
                e.preventDefault();
                alert('Please provide a delivery address for the selected delivery method.');
                return false;
            }
        });
    }
});
</script>
