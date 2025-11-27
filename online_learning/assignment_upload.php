<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assignment'])) {
    $assignment_id = $_POST['assignment_id'];
    $submission_text = $_POST['submission_text'] ?? '';
    $submission_type = 'text';
    
    // Handle file upload
    $file_path = null;
    $file_name = null;
    $file_size = null;
    
    if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/assignments/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_name = $_FILES['assignment_file']['name'];
        $file_size = $_FILES['assignment_file']['size'];
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $new_file_name = 'assignment_' . $assignment_id . '_' . $user_id . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $new_file_name;
        
        if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $file_path)) {
            $submission_type = !empty($submission_text) ? 'both' : 'file';
        }
    }
    
    // Check if submission already exists
    $check_query = "SELECT id FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?";
    $stmt = $conn->prepare($check_query);
    $stmt->bind_param("ii", $assignment_id, $user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    
    if ($existing) {
        // Update existing submission
        $update_query = "UPDATE assignment_submissions SET 
                        submission_text = ?, file_path = ?, file_name = ?, file_size = ?, 
                        submission_type = ?, status = 'submitted', submitted_at = NOW()
                        WHERE assignment_id = ? AND student_id = ?";
        $stmt = $conn->prepare($update_query);
        $stmt->bind_param("sssissi", $submission_text, $file_path, $file_name, $file_size, $submission_type, $assignment_id, $user_id);
    } else {
        // Create new submission
        $insert_query = "INSERT INTO assignment_submissions 
                        (assignment_id, student_id, submission_text, file_path, file_name, file_size, submission_type, status, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'submitted', NOW())";
        $stmt = $conn->prepare($insert_query);
        $stmt->bind_param("iisssis", $assignment_id, $user_id, $submission_text, $file_path, $file_name, $file_size, $submission_type);
    }
    
    if ($stmt->execute()) {
        $success_message = "Assignment submitted successfully!";
    } else {
        $error_message = "Error submitting assignment. Please try again.";
    }
}

// Get assignments for the student
$assignments = [];
if ($role === 'student') {
    // Get student's class
    $class_query = "SELECT class_id FROM student_classes WHERE student_id = ? AND status = 'active'";
    $stmt = $conn->prepare($class_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $class_result = $stmt->get_result();
    
    if ($class_result->num_rows > 0) {
        $class = $class_result->fetch_assoc();
        $class_id = $class['class_id'];
        
        // Get assignments for this class
        $assignment_query = "SELECT a.*, s.name as subject_name, u.name as teacher_name,
                           asub.id as submission_id, asub.status as submission_status, 
                           asub.submitted_at, asub.grade, asub.feedback
                           FROM assignments a
                           JOIN subjects s ON a.subject_id = s.id
                           JOIN users u ON a.teacher_id = u.id
                           LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.student_id = ?
                           WHERE a.class_id = ? AND a.status = 'active'
                           ORDER BY a.due_date ASC";
        $stmt = $conn->prepare($assignment_query);
        $stmt->bind_param("ii", $user_id, $class_id);
        $stmt->execute();
        $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
} elseif ($role === 'teacher') {
    // Get assignments created by this teacher
    $assignment_query = "SELECT a.*, s.name as subject_name, c.name as class_name,
                        COUNT(asub.id) as total_submissions,
                        COUNT(CASE WHEN asub.status = 'submitted' THEN 1 END) as submitted_count
                        FROM assignments a
                        JOIN subjects s ON a.subject_id = s.id
                        JOIN classes c ON a.class_id = c.id
                        LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id
                        WHERE a.teacher_id = ? AND a.status = 'active'
                        GROUP BY a.id
                        ORDER BY a.due_date ASC";
    $stmt = $conn->prepare($assignment_query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $assignments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assignment Upload - Greenwood Academy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {"50":"#eff6ff","100":"#dbeafe","200":"#bfdbfe","300":"#93c5fd","400":"#60a5fa","500":"#3b82f6","600":"#2563eb","700":"#1d4ed8","800":"#1e40af","900":"#1e3a8a","950":"#172554"}
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <!-- Header -->
    <?php include '../includes/header.php'; ?>

    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="lg:ml-64 pt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <!-- Page Header -->
            <div class="mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Assignment Upload</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-2">
                            <?php echo $role === 'student' ? 'Submit your assignments' : 'Manage assignment submissions'; ?>
                        </p>
                    </div>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
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

            <!-- Assignments List -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                        <?php echo $role === 'student' ? 'My Assignments' : 'Assignment Submissions'; ?>
                    </h2>
                </div>
                
                <div class="p-6">
                    <?php if (empty($assignments)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-tasks text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Assignments</h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            <?php echo $role === 'student' ? 'No assignments have been assigned yet.' : 'You haven\'t created any assignments yet.'; ?>
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="space-y-6">
                        <?php foreach ($assignments as $assignment): 
                            $is_overdue = strtotime($assignment['due_date']) < time();
                            $is_submitted = !empty($assignment['submission_id']);
                            
                            if ($is_overdue && !$is_submitted) {
                                $status_class = 'border-red-500 bg-red-50 dark:bg-red-900/20';
                                $status_text = 'Overdue';
                                $status_icon = 'fas fa-exclamation-triangle text-red-500';
                            } elseif ($is_submitted) {
                                if ($assignment['grade']) {
                                    $status_class = 'border-green-500 bg-green-50 dark:bg-green-900/20';
                                    $status_text = 'Graded';
                                    $status_icon = 'fas fa-check-circle text-green-500';
                                } else {
                                    $status_class = 'border-blue-500 bg-blue-50 dark:bg-blue-900/20';
                                    $status_text = 'Submitted';
                                    $status_icon = 'fas fa-clock text-blue-500';
                                }
                            } else {
                                $status_class = 'border-yellow-500 bg-yellow-50 dark:bg-yellow-900/20';
                                $status_text = 'Pending';
                                $status_icon = 'fas fa-hourglass-half text-yellow-500';
                            }
                        ?>
                        <div class="border-2 <?php echo $status_class; ?> rounded-lg p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </h3>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                                            <i class="<?php echo $status_icon; ?> mr-1"></i>
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    
                                    <?php if ($assignment['description']): ?>
                                    <p class="text-gray-600 dark:text-gray-400 mb-3"><?php echo htmlspecialchars($assignment['description']); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Subject:</span>
                                            <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['subject_name']); ?></p>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">
                                                <?php echo $role === 'student' ? 'Teacher:' : 'Class:'; ?>
                                            </span>
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                <?php echo $role === 'student' ? htmlspecialchars($assignment['teacher_name']) : htmlspecialchars($assignment['class_name']); ?>
                                            </p>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Due Date:</span>
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                            </p>
                                        </div>
                                    </div>
                                    
                                    <?php if ($role === 'student' && $is_submitted): ?>
                                    <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <h4 class="font-medium text-gray-900 dark:text-white mb-2">Submission Details</h4>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Submitted:</span>
                                                <p class="font-medium text-gray-900 dark:text-white">
                                                    <?php echo date('M j, Y g:i A', strtotime($assignment['submitted_at'])); ?>
                                                </p>
                                            </div>
                                            <?php if ($assignment['grade']): ?>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Grade:</span>
                                                <p class="font-medium text-green-600"><?php echo $assignment['grade']; ?>%</p>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($assignment['feedback']): ?>
                                        <div class="mt-3">
                                            <span class="text-gray-500 dark:text-gray-400">Feedback:</span>
                                            <p class="text-gray-900 dark:text-white mt-1"><?php echo htmlspecialchars($assignment['feedback']); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($role === 'teacher'): ?>
                                    <div class="mt-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                        <h4 class="font-medium text-gray-900 dark:text-white mb-2">Submission Statistics</h4>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Total Submissions:</span>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo $assignment['submitted_count']; ?></p>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Pending:</span>
                                                <p class="font-medium text-gray-900 dark:text-white">
                                                    <?php echo ($assignment['total_submissions'] - $assignment['submitted_count']); ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="flex flex-col space-y-2 ml-6">
                                    <?php if ($role === 'student' && !$is_overdue): ?>
                                    <button onclick="showSubmissionModal(<?php echo $assignment['id']; ?>, '<?php echo htmlspecialchars($assignment['title']); ?>')" 
                                            class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                        <i class="fas fa-upload mr-2"></i>
                                        <?php echo $is_submitted ? 'Resubmit' : 'Submit'; ?>
                                    </button>
                                    <?php endif; ?>
                                    
                                    <?php if ($role === 'teacher'): ?>
                                    <a href="assignment_submissions.php?id=<?php echo $assignment['id']; ?>" 
                                       class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors text-center">
                                        <i class="fas fa-eye mr-2"></i>View Submissions
                                    </a>
                                    <?php endif; ?>
                                    
                                    <?php if ($assignment['attachment_path']): ?>
                                    <a href="<?php echo htmlspecialchars($assignment['attachment_path']); ?>" target="_blank"
                                       class="bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors text-center">
                                        <i class="fas fa-download mr-2"></i>Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Submission Modal -->
    <div id="submissionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Submit Assignment</h3>
                    <button onclick="hideSubmissionModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <form method="POST" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" id="assignment_id" name="assignment_id" value="">

                    <div>
                        <h4 id="assignment_title" class="text-lg font-medium text-gray-900 dark:text-white mb-4"></h4>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Written Submission
                        </label>
                        <textarea name="submission_text" rows="6"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                  placeholder="Enter your assignment text here..."></textarea>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            You can type your assignment directly here or upload a file below.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            File Upload
                        </label>
                        <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center">
                            <input type="file" name="assignment_file" id="assignment_file"
                                   class="hidden" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png">
                            <div id="file_drop_area" onclick="document.getElementById('assignment_file').click()"
                                 class="cursor-pointer">
                                <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                <p class="text-gray-600 dark:text-gray-400 mb-2">
                                    Click to upload or drag and drop
                                </p>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    PDF, DOC, DOCX, TXT, JPG, PNG (Max 10MB)
                                </p>
                            </div>
                            <div id="file_info" class="hidden mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-2">
                                        <i class="fas fa-file text-blue-600"></i>
                                        <span id="file_name" class="text-sm font-medium text-gray-900 dark:text-white"></span>
                                    </div>
                                    <button type="button" onclick="removeFile()" class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-1"></i>
                            <div>
                                <h4 class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Important Notes</h4>
                                <ul class="text-sm text-yellow-700 dark:text-yellow-300 mt-1 space-y-1">
                                    <li>• Make sure your submission is complete before submitting</li>
                                    <li>• You can resubmit before the due date if needed</li>
                                    <li>• Late submissions may not be accepted</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="hideSubmissionModal()"
                                class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="submit_assignment"
                                class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                            <i class="fas fa-upload mr-2"></i>Submit Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <?php include '../includes/footer.php'; ?>

    <script>
        function showSubmissionModal(assignmentId, assignmentTitle) {
            document.getElementById('assignment_id').value = assignmentId;
            document.getElementById('assignment_title').textContent = assignmentTitle;
            document.getElementById('submissionModal').classList.remove('hidden');
        }

        function hideSubmissionModal() {
            document.getElementById('submissionModal').classList.add('hidden');
            // Reset form
            document.querySelector('#submissionModal form').reset();
            document.getElementById('file_info').classList.add('hidden');
        }

        // File upload handling
        document.getElementById('assignment_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                // Check file size (10MB limit)
                if (file.size > 10 * 1024 * 1024) {
                    alert('File size must be less than 10MB');
                    e.target.value = '';
                    return;
                }

                document.getElementById('file_name').textContent = file.name;
                document.getElementById('file_info').classList.remove('hidden');
            }
        });

        function removeFile() {
            document.getElementById('assignment_file').value = '';
            document.getElementById('file_info').classList.add('hidden');
        }

        // Drag and drop functionality
        const dropArea = document.getElementById('file_drop_area');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropArea.addEventListener(eventName, unhighlight, false);
        });

        function highlight(e) {
            dropArea.classList.add('border-blue-500', 'bg-blue-50');
        }

        function unhighlight(e) {
            dropArea.classList.remove('border-blue-500', 'bg-blue-50');
        }

        dropArea.addEventListener('drop', handleDrop, false);

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;

            if (files.length > 0) {
                document.getElementById('assignment_file').files = files;
                const event = new Event('change', { bubbles: true });
                document.getElementById('assignment_file').dispatchEvent(event);
            }
        }

        // Close modal when clicking outside
        document.getElementById('submissionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideSubmissionModal();
            }
        });
    </script>
