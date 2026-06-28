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
$request_id = $_GET['id'] ?? null;

if (!$request_id) {
    header("Location: transcripts.php");
    exit();
}

// Fetch request details
try {
    $req_query = "
        SELECT tr.*, s.name as student_name, s.student_id as student_number,
               r.name as requester_name, p.name as processor_name
        FROM transcript_requests tr
        LEFT JOIN users s ON tr.student_id = s.id
        LEFT JOIN users r ON tr.requested_by = r.id
        LEFT JOIN users p ON tr.processed_by = p.id
        WHERE tr.id = :request_id
    ";
    $req_stmt = $db->prepare($req_query);
    $req_stmt->bindParam(':request_id', $request_id);
    $req_stmt->execute();
    $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        header("Location: transcripts.php");
        exit();
    }
    
    // Check access permission
    $has_access = false;
    if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        $has_access = true;
    } elseif ($request['requested_by'] == $user_id || $request['student_id'] == $user_id) {
        $has_access = true;
    } elseif ($role === 'parent') {
        // Check if student is parent's child
        $parent_query = "SELECT 1 FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':parent_id', $user_id);
        $parent_stmt->bindParam(':student_id', $request['student_id']);
        $parent_stmt->execute();
        if ($parent_stmt->fetch()) {
            $has_access = true;
        }
    }
    
    if (!$has_access) {
        header("Location: transcripts.php");
        exit();
    }
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

$title = "Transcript Request Details";
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
                                <h1 class="text-3xl font-bold mb-2">Transcript Request Details</h1>
                                <p class="text-blue-100 text-lg">Details and document preview for transcript request #<?php echo $request['id']; ?></p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-20 h-20 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-alt text-4xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="flex justify-between items-center mb-6">
                    <a href="transcripts.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Requests
                    </a>
                    <div class="flex space-x-3">
                        <?php if ($request['status'] === 'ready'): ?>
                        <a href="transcript_download.php?id=<?php echo $request['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i>Download Transcript
                        </a>
                        <?php endif; ?>
                        
                        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal']) && $request['status'] === 'pending'): ?>
                        <button onclick="processRequest(<?php echo $request['id']; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-cog mr-2"></i>Process & Generate
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Info Panel -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 space-y-6">
                        <h2 class="text-xl font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3">
                            Request Information
                        </h2>
                        
                        <div class="space-y-4">
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Student Name</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($request['student_name']); ?></p>
                                <span class="text-xs text-gray-400">ID: <?php echo htmlspecialchars($request['student_number']); ?></span>
                            </div>
                            
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Requested By</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($request['requester_name']); ?></p>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Request Type</span>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo ucfirst(htmlspecialchars($request['request_type'])); ?></p>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Status</span>
                                    <div>
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
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Purpose</span>
                                <p class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($request['purpose']); ?></p>
                            </div>
                            
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Delivery Method</span>
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo ucfirst(str_replace('_', ' ', $request['delivery_method'])); ?></p>
                            </div>
                            
                            <?php if ($request['delivery_address']): ?>
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Delivery Address / Details</span>
                                <p class="text-sm text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-700 p-3 rounded-lg border border-gray-200 dark:border-gray-600 whitespace-pre-line"><?php echo htmlspecialchars($request['delivery_address']); ?></p>
                            </div>
                            <?php endif; ?>
                            
                            <div class="grid grid-cols-2 gap-4 border-t border-gray-200 dark:border-gray-700 pt-4">
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Fee</span>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">₵ <?php echo number_format($request['fee_amount'], 2); ?></p>
                                </div>
                                <div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Payment Status</span>
                                    <p class="text-sm font-medium <?php echo $request['payment_status'] === 'paid' ? 'text-green-600' : 'text-yellow-600'; ?>">
                                        <?php echo ucfirst(htmlspecialchars($request['payment_status'])); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="border-t border-gray-200 dark:border-gray-700 pt-4 text-xs text-gray-500 space-y-1">
                                <div>Requested On: <?php echo date('M j, Y H:i', strtotime($request['created_at'])); ?></div>
                                <?php if ($request['processed_at']): ?>
                                <div>Processed On: <?php echo date('M j, Y H:i', strtotime($request['processed_at'])); ?></div>
                                <div>Processed By: <?php echo htmlspecialchars($request['processor_name']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Preview Panel -->
                    <div class="lg:col-span-2 flex flex-col">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 flex-1 flex flex-col">
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 mb-4 flex items-center justify-between">
                                <span>Transcript Preview</span>
                                <?php if ($request['status'] === 'ready'): ?>
                                <button onclick="printTranscript()" class="text-sm bg-blue-50 dark:bg-blue-900 text-blue-600 dark:text-blue-400 px-3 py-1 rounded hover:bg-blue-100 dark:hover:bg-blue-800">
                                    <i class="fas fa-print mr-1"></i>Print Preview
                                </button>
                                <?php endif; ?>
                            </h2>
                            
                            <div class="flex-1 min-h-[500px] bg-gray-100 dark:bg-gray-900 rounded-lg flex items-center justify-center overflow-hidden position-relative">
                                <?php if ($request['status'] === 'ready' && $request['generated_file_path']): ?>
                                <iframe id="transcriptFrame" src="transcript_download.php?id=<?php echo $request['id']; ?>&preview=1" class="w-full h-full border-0 min-h-[600px] bg-white"></iframe>
                                <?php else: ?>
                                <div class="text-center p-8">
                                    <i class="fas fa-hourglass-half text-gray-400 text-6xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-700 dark:text-gray-300">Transcript Not Generated Yet</h3>
                                    <p class="text-sm text-gray-500 max-w-sm mt-1">
                                        Once the request is processed, a dynamic preview of the generated official transcript will appear here.
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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
function processRequest(requestId) {
    if (confirm('Mark this transcript request as processed and generate academic records?')) {
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
                alert('Transcript generated successfully!');
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

function printTranscript() {
    const frame = document.getElementById('transcriptFrame');
    if (frame) {
        frame.contentWindow.focus();
        frame.contentWindow.print();
    }
}
</script>
