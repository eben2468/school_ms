<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Fetch classes and subjects based on user role
if ($user_role === 'teacher') {
    // Teachers can only create assignments for classes they teach
    $classes_query = "SELECT DISTINCT c.id, c.name, c.grade_level 
                     FROM classes c 
                     JOIN class_teachers ct ON c.id = ct.class_id 
                     WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
                     ORDER BY c.grade_level, c.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':teacher_id', $user_id);
    $classes_stmt->execute();
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subjects_query = "SELECT DISTINCT s.id, s.name, s.code 
                      FROM subjects s 
                      JOIN class_teachers ct ON s.id = ct.subject_id 
                      WHERE ct.teacher_id = :teacher_id
                      ORDER BY s.name";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->bindParam(':teacher_id', $user_id);
    $subjects_stmt->execute();
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Admins can create assignments for any class/subject
    $classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $classes_stmt = $db->query($classes_query);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subjects_query = "SELECT id, name, code FROM subjects ORDER BY name";
    $subjects_stmt = $db->query($subjects_query);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $due_date = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
    $due_time = filter_input(INPUT_POST, 'due_time', FILTER_SANITIZE_STRING);

    // Combine date and time
    $due_datetime = $due_date . ' ' . $due_time;

    // Handle file upload
    $attachment_path = null;
    $attachment_name = null;

    if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../../uploads/assignments/';
        $allowed_types = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
        $max_size = 10 * 1024 * 1024; // 10MB

        $file_info = pathinfo($_FILES['attachment']['name']);
        $file_extension = strtolower($file_info['extension']);
        $original_name = $file_info['filename'];

        // Validate file
        if (!in_array($file_extension, $allowed_types)) {
            $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
        }

        if ($_FILES['attachment']['size'] > $max_size) {
            $errors[] = "File size too large. Maximum size is 10MB.";
        }

        if (empty($errors)) {
            // Generate unique filename
            $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $unique_filename;

            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Move uploaded file
            if (move_uploaded_file($_FILES['attachment']['tmp_name'], $upload_path)) {
                $attachment_path = 'uploads/assignments/' . $unique_filename;
                $attachment_name = $_FILES['attachment']['name'];
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
    }

    // Validation
    $errors = array_merge($errors ?? [], []);
    if (empty($title)) $errors[] = "Title is required.";
    if (empty($class_id)) $errors[] = "Class is required.";
    if (empty($subject_id)) $errors[] = "Subject is required.";
    if (empty($due_date)) $errors[] = "Due date is required.";
    if (empty($due_time)) $errors[] = "Due time is required.";
    if (strtotime($due_datetime) <= time()) $errors[] = "Due date must be in the future.";
    
    // Verify teacher can assign to this class/subject
    if ($user_role === 'teacher' && empty($errors)) {
        $verify_query = "SELECT COUNT(*) as count FROM class_teachers 
                        WHERE teacher_id = :teacher_id AND class_id = :class_id AND subject_id = :subject_id";
        $verify_stmt = $db->prepare($verify_query);
        $verify_stmt->bindParam(':teacher_id', $user_id);
        $verify_stmt->bindParam(':class_id', $class_id);
        $verify_stmt->bindParam(':subject_id', $subject_id);
        $verify_stmt->execute();
        $verification = $verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($verification['count'] == 0) {
            $errors[] = "You can only create assignments for classes and subjects you teach.";
        }
    }
    
    if (empty($errors)) {
        try {
            $query = "INSERT INTO assignments (title, description, class_id, subject_id, teacher_id, due_date, attachment_path, attachment_name)
                     VALUES (:title, :description, :class_id, :subject_id, :teacher_id, :due_date, :attachment_path, :attachment_name)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->bindParam(':subject_id', $subject_id);
            $stmt->bindParam(':teacher_id', $user_id);
            $stmt->bindParam(':due_date', $due_datetime);
            $stmt->bindParam(':attachment_path', $attachment_path);
            $stmt->bindParam(':attachment_name', $attachment_name);
            $stmt->execute();

            header("Location: index.php?success=Assignment created successfully");
            exit();
        } catch (PDOException $e) {
            $errors[] = "Error creating assignment. Please try again.";
            // If there was an error and we uploaded a file, clean it up
            if ($attachment_path && file_exists('../../' . $attachment_path)) {
                unlink('../../' . $attachment_path);
            }
        }
    }
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Create New Assignment</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Assignments
                </a>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form action="" method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700">Assignment Title *</label>
                        <input type="text" id="title" name="title" required
                            value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter assignment title">
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter assignment description, instructions, and requirements"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700">Class *</label>
                            <select id="class_id" name="class_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" 
                                    <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name'] . ' (' . $class['grade_level'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="subject_id" class="block text-sm font-medium text-gray-700">Subject *</label>
                            <select id="subject_id" name="subject_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select Subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" 
                                    <?php echo (isset($_POST['subject_id']) && $_POST['subject_id'] == $subject['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['name'] . ' (' . $subject['code'] . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700">Due Date *</label>
                            <input type="date" id="due_date" name="due_date" required
                                value="<?php echo isset($_POST['due_date']) ? htmlspecialchars($_POST['due_date']) : ''; ?>"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="due_time" class="block text-sm font-medium text-gray-700">Due Time *</label>
                            <input type="time" id="due_time" name="due_time" required
                                value="<?php echo isset($_POST['due_time']) ? htmlspecialchars($_POST['due_time']) : '23:59'; ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- File Attachment -->
                    <div>
                        <label for="attachment" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-paperclip mr-2"></i>Attachment (Optional)
                        </label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-gray-400 transition-colors duration-200">
                            <div class="space-y-1 text-center">
                                <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48" aria-hidden="true">
                                    <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <div class="flex text-sm text-gray-600">
                                    <label for="attachment" class="relative cursor-pointer bg-white rounded-md font-medium text-blue-600 hover:text-blue-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-blue-500">
                                        <span>Upload a file</span>
                                        <input id="attachment" name="attachment" type="file" class="sr-only" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip,.rar" onchange="updateFileName(this)">
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">PDF, DOC, DOCX, TXT, JPG, PNG, ZIP up to 10MB</p>
                                <div id="file-name" class="text-sm text-gray-700 font-medium hidden"></div>
                            </div>
                        </div>
                        <p class="mt-2 text-sm text-gray-500">
                            <i class="fas fa-info-circle mr-1"></i>
                            You can attach reference materials, instructions, or templates for students.
                        </p>
                    </div>

                    <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-200 p-4 rounded-lg">
                        <h3 class="text-sm font-medium text-gray-700 mb-3 flex items-center">
                            <i class="fas fa-lightbulb text-yellow-500 mr-2"></i>
                            Assignment Guidelines
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <ul class="text-sm text-gray-600 space-y-2">
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5 text-xs"></i>
                                    Provide clear instructions and requirements
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5 text-xs"></i>
                                    Set a reasonable due date to give students enough time
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check-circle text-green-500 mr-2 mt-0.5 text-xs"></i>
                                    Consider the workload from other subjects
                                </li>
                            </ul>
                            <ul class="text-sm text-gray-600 space-y-2">
                                <li class="flex items-start">
                                    <i class="fas fa-bell text-blue-500 mr-2 mt-0.5 text-xs"></i>
                                    Students will be notified automatically
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-paperclip text-purple-500 mr-2 mt-0.5 text-xs"></i>
                                    Attach reference materials if needed
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-shield-alt text-red-500 mr-2 mt-0.5 text-xs"></i>
                                    Maximum file size: 10MB
                                </li>
                            </ul>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <a href="index.php" 
                            class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Cancel
                        </a>
                        <button type="submit"
                            class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Create Assignment
                        </button>
                    </div>
                </form>
            </div>
        </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// File upload handling
function updateFileName(input) {
    const fileNameDiv = document.getElementById('file-name');
    const uploadArea = input.closest('.border-dashed');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2); // Convert to MB

        fileNameDiv.innerHTML = `
            <div class="flex items-center justify-center space-x-2 mt-2 p-2 bg-blue-50 rounded-md">
                <i class="fas fa-file text-blue-600"></i>
                <span class="text-blue-800 font-medium">${fileName}</span>
                <span class="text-blue-600 text-xs">(${fileSize} MB)</span>
                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700 ml-2">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        fileNameDiv.classList.remove('hidden');
        uploadArea.classList.add('border-blue-400', 'bg-blue-50');
    }
}

function clearFile() {
    const input = document.getElementById('attachment');
    const fileNameDiv = document.getElementById('file-name');
    const uploadArea = input.closest('.border-dashed');

    input.value = '';
    fileNameDiv.classList.add('hidden');
    uploadArea.classList.remove('border-blue-400', 'bg-blue-50');
}

// Drag and drop functionality
const uploadArea = document.querySelector('.border-dashed');
const fileInput = document.getElementById('attachment');

['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, preventDefaults, false);
});

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

['dragenter', 'dragover'].forEach(eventName => {
    uploadArea.addEventListener(eventName, highlight, false);
});

['dragleave', 'drop'].forEach(eventName => {
    uploadArea.addEventListener(eventName, unhighlight, false);
});

function highlight(e) {
    uploadArea.classList.add('border-blue-400', 'bg-blue-50');
}

function unhighlight(e) {
    uploadArea.classList.remove('border-blue-400', 'bg-blue-50');
}

uploadArea.addEventListener('drop', handleDrop, false);

function handleDrop(e) {
    const dt = e.dataTransfer;
    const files = dt.files;

    if (files.length > 0) {
        fileInput.files = files;
        updateFileName(fileInput);
    }
}

// Auto-populate subject based on class selection for teachers
document.getElementById('class_id').addEventListener('change', function() {
    const classId = this.value;
    const subjectSelect = document.getElementById('subject_id');

    if (classId && <?php echo json_encode($user_role === 'teacher'); ?>) {
        // For teachers, filter subjects based on what they teach for the selected class
        fetch(`get_teacher_subjects.php?class_id=${classId}`)
            .then(response => response.json())
            .then(subjects => {
                subjectSelect.innerHTML = '<option value="">Select Subject</option>';
                subjects.forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.id;
                    option.textContent = `${subject.name} (${subject.code})`;
                    subjectSelect.appendChild(option);
                });
            })
            .catch(error => console.error('Error fetching subjects:', error));
    }
});
</script>
