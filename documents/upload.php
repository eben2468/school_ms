<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$errors = [];
$success = '';

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $document_type = $_POST['document_type'];
    $access_level = $_POST['access_level'];
    $related_user_id = !empty($_POST['related_user_id']) ? $_POST['related_user_id'] : null;
    
    // Validation
    if (empty($title)) {
        $errors[] = "Document title is required.";
    }
    
    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "Please select a file to upload.";
    }
    
    if (empty($errors) && isset($_FILES['document_file'])) {
        $upload_dir = '../uploads/documents/';
        $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'txt', 'zip', 'rar'];
        $max_size = 50 * 1024 * 1024; // 50MB
        
        $file = $_FILES['document_file'];
        $file_info = pathinfo($file['name']);
        $file_extension = strtolower($file_info['extension']);
        $original_name = $file_info['filename'];
        
        // Validate file
        if (!in_array($file_extension, $allowed_types)) {
            $errors[] = "Invalid file type. Allowed types: " . implode(', ', $allowed_types);
        }
        
        if ($file['size'] > $max_size) {
            $errors[] = "File size too large. Maximum size is 50MB.";
        }
        
        if (empty($errors)) {
            // Create directory if it doesn't exist
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate unique filename
            $unique_filename = uniqid() . '_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $unique_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                // Save to database
                try {
                    $query = "INSERT INTO documents (title, description, file_path, file_type, file_size, uploaded_by, document_type, access_level, related_user_id)
                              VALUES (:title, :description, :file_path, :file_type, :file_size, :uploaded_by, :document_type, :access_level, :related_user_id)";

                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':title', $title);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':file_path', $unique_filename);
                    $stmt->bindParam(':file_type', $file_extension);
                    $stmt->bindParam(':file_size', $file['size'], PDO::PARAM_INT);
                    $stmt->bindParam(':uploaded_by', $user_id, PDO::PARAM_INT);
                    $stmt->bindParam(':document_type', $document_type);
                    $stmt->bindParam(':access_level', $access_level);
                    $stmt->bindParam(':related_user_id', $related_user_id, PDO::PARAM_INT);

                    if ($stmt->execute()) {
                        $success = "Document uploaded successfully!";
                        // Clear form data
                        $_POST = [];
                    } else {
                        $errors[] = "Failed to save document information.";
                    }
                } catch (PDOException $e) {
                    $errors[] = "Database error: " . $e->getMessage();
                }
            } else {
                $errors[] = "Failed to upload file. Please try again.";
            }
        }
    }
}

// Get users for related user dropdown
$users = [];
try {
    $query = "SELECT id, name, role FROM users WHERE status = 'active' ORDER BY name";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

$title = "Upload Documents";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

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
                                <h1 class="text-3xl font-bold mb-2">Upload Documents</h1>
                                <p class="text-blue-100 text-lg">Upload and organize documents securely</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-upload mr-2"></i>
                                        Secure document management
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-cloud-upload-alt text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <div class="flex justify-end mb-6">
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Documents
                    </a>
                </div>

                <!-- Success/Error Messages -->
                <?php if (!empty($success)): ?>
                <div class="bg-green-100 dark:bg-green-900 border border-green-400 dark:border-green-600 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 dark:bg-red-900 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <span class="font-medium">Please fix the following errors:</span>
                    </div>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <!-- Upload Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Document Information</h2>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="p-6 space-y-6">
                        <!-- File Upload Area -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Select Document File *
                            </label>
                            <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center hover:border-blue-400 dark:hover:border-blue-500 transition-colors duration-200" id="uploadArea">
                                <input type="file" name="document_file" id="document_file" class="hidden" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.txt,.zip,.rar" onchange="handleFileSelect(this)">
                                <div id="uploadContent">
                                    <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                                    <p class="text-gray-600 dark:text-gray-400 mb-2">Click to upload or drag and drop</p>
                                    <p class="text-sm text-gray-500 dark:text-gray-500">PDF, DOC, XLS, Images, ZIP (Max 50MB)</p>
                                    <button type="button" onclick="document.getElementById('document_file').click()" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                        Choose File
                                    </button>
                                </div>
                                <div id="fileName" class="hidden"></div>
                            </div>
                        </div>

                        <!-- Document Details -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document Title *
                                </label>
                                <input type="text" name="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" 
                                       placeholder="Enter document title" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Document Type
                                </label>
                                <select name="document_type" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="other" <?php echo ($_POST['document_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                                    <option value="certificate" <?php echo ($_POST['document_type'] ?? '') === 'certificate' ? 'selected' : ''; ?>>Certificate</option>
                                    <option value="transcript" <?php echo ($_POST['document_type'] ?? '') === 'transcript' ? 'selected' : ''; ?>>Transcript</option>
                                    <option value="report" <?php echo ($_POST['document_type'] ?? '') === 'report' ? 'selected' : ''; ?>>Report</option>
                                    <option value="policy" <?php echo ($_POST['document_type'] ?? '') === 'policy' ? 'selected' : ''; ?>>Policy</option>
                                    <option value="form" <?php echo ($_POST['document_type'] ?? '') === 'form' ? 'selected' : ''; ?>>Form</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Description
                            </label>
                            <textarea name="description" rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" 
                                      placeholder="Enter document description"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Access Level
                                </label>
                                <select name="access_level" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="staff" <?php echo ($_POST['access_level'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff Only</option>
                                    <option value="public" <?php echo ($_POST['access_level'] ?? '') === 'public' ? 'selected' : ''; ?>>Public</option>
                                    <option value="students" <?php echo ($_POST['access_level'] ?? '') === 'students' ? 'selected' : ''; ?>>Students</option>
                                    <option value="parents" <?php echo ($_POST['access_level'] ?? '') === 'parents' ? 'selected' : ''; ?>>Parents</option>
                                    <option value="admin_only" <?php echo ($_POST['access_level'] ?? '') === 'admin_only' ? 'selected' : ''; ?>>Admin Only</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Related User (Optional)
                                </label>
                                <select name="related_user_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select User</option>
                                    <?php foreach ($users as $user): ?>
                                    <option value="<?php echo $user['id']; ?>" <?php echo ($_POST['related_user_id'] ?? '') == $user['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($user['name'] . ' (' . ucfirst(str_replace('_', ' ', $user['role'])) . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Submit Button -->
                        <div class="flex justify-end space-x-3 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200 transition-colors duration-200">
                                Cancel
                            </a>
                            <button type="submit" name="upload_document" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-upload mr-2"></i>
                                Upload Document
                            </button>
                        </div>
                    </form>
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
function handleFileSelect(input) {
    const uploadArea = document.getElementById('uploadArea');
    const uploadContent = document.getElementById('uploadContent');
    const fileNameDiv = document.getElementById('fileName');

    if (input.files && input.files[0]) {
        const file = input.files[0];
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2); // Convert to MB

        fileNameDiv.innerHTML = `
            <div class="flex items-center justify-center space-x-2 mt-2 p-2 bg-blue-50 dark:bg-blue-900 rounded-md">
                <i class="fas fa-file text-blue-600 dark:text-blue-400"></i>
                <span class="text-blue-800 dark:text-blue-200 font-medium">${fileName}</span>
                <span class="text-blue-600 dark:text-blue-400 text-xs">(${fileSize} MB)</span>
                <button type="button" onclick="clearFile()" class="text-red-500 hover:text-red-700 ml-2">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        fileNameDiv.classList.remove('hidden');
        uploadContent.classList.add('hidden');
        uploadArea.classList.add('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900');
    }
}

function clearFile() {
    const input = document.getElementById('document_file');
    const uploadArea = document.getElementById('uploadArea');
    const uploadContent = document.getElementById('uploadContent');
    const fileNameDiv = document.getElementById('fileName');

    input.value = '';
    fileNameDiv.classList.add('hidden');
    uploadContent.classList.remove('hidden');
    uploadArea.classList.remove('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900');
}

// Drag and drop functionality
const uploadArea = document.getElementById('uploadArea');

uploadArea.addEventListener('dragover', function(e) {
    e.preventDefault();
    this.classList.add('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900');
});

uploadArea.addEventListener('dragleave', function(e) {
    e.preventDefault();
    this.classList.remove('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900');
});

uploadArea.addEventListener('drop', function(e) {
    e.preventDefault();
    this.classList.remove('border-blue-400', 'bg-blue-50', 'dark:bg-blue-900');
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        document.getElementById('document_file').files = files;
        handleFileSelect(document.getElementById('document_file'));
    }
});
</script>
