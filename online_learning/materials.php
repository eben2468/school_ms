<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// File Downloader / Viewer Endpoint
if (isset($_GET['download_id'])) {
    $download_id = filter_input(INPUT_GET, 'download_id', FILTER_SANITIZE_NUMBER_INT);
    if ($download_id) {
        try {
            $stmt = $db->prepare("SELECT * FROM learning_materials WHERE id = :id");
            $stmt->execute([':id' => $download_id]);
            $material = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($material) {
                // Authorization: a student may only access public materials or
                // materials for their own active class. This mirrors the access
                // restriction applied to the materials listing below, so the
                // download endpoint cannot be used to reach another class's files.
                if ($role === 'student') {
                    $sc_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :sid AND status = 'active' LIMIT 1");
                    $sc_stmt->execute([':sid' => $user_id]);
                    $sc_id = $sc_stmt->fetchColumn() ?: null;
                    $allowed = ($material['access_level'] === 'public')
                        || (in_array($material['access_level'], ['class_only', 'subject_only'], true)
                            && $sc_id && (int)$material['class_id'] === (int)$sc_id);
                    if (!$allowed) {
                        http_response_code(403);
                        die('Access denied. You can only access materials for your own class.');
                    }
                } elseif ($role === 'teacher') {
                    // Teachers cannot pull another teacher's private material.
                    if ($material['access_level'] === 'private' && (int)$material['uploaded_by'] !== (int)$user_id) {
                        http_response_code(403);
                        die('Access denied.');
                    }
                }

                // Access log entry
                $log_stmt = $db->prepare("INSERT INTO material_access_logs (material_id, user_id, access_type, ip_address, user_agent) 
                                          VALUES (:material_id, :user_id, 'download', :ip, :ua)");
                $log_stmt->execute([
                    ':material_id' => $download_id,
                    ':user_id' => $user_id,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ]);

                // Increment download count
                $inc_stmt = $db->prepare("UPDATE learning_materials SET download_count = download_count + 1 WHERE id = :id");
                $inc_stmt->execute([':id' => $download_id]);

                // If link, redirect
                if ($material['material_type'] === 'link') {
                    header("Location: " . $material['file_path']);
                    exit();
                } else {
                    $full_path = '../' . $material['file_path'];
                    if (file_exists($full_path)) {
                        header('Content-Description: File Transfer');
                        header('Content-Type: application/octet-stream');
                        header('Content-Disposition: attachment; filename="' . basename($material['title'] . '.' . pathinfo($material['file_path'], PATHINFO_EXTENSION)) . '"');
                        header('Expires: 0');
                        header('Cache-Control: must-revalidate');
                        header('Pragma: public');
                        header('Content-Length: ' . filesize($full_path));
                        readfile($full_path);
                        exit();
                    } else {
                        die("File not found on server. Path: " . htmlspecialchars($material['file_path']));
                    }
                }
            }
        } catch (PDOException $e) {
            die("Database error: " . $e->getMessage());
        }
    }
}

$success_message = '';
$error_message = '';

// Handle POST upload for Teachers/Admins
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_material') {
    if (!in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
        $error_message = "Unauthorized action.";
    } else {
        $material_type = filter_input(INPUT_POST, 'material_type', FILTER_SANITIZE_STRING);
        $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $access_level = filter_input(INPUT_POST, 'access_level', FILTER_SANITIZE_STRING) ?: 'class_only';

        if (empty($title)) {
            $error_message = "Title is required.";
        } elseif ($material_type === 'link') {
            $link_url = filter_input(INPUT_POST, 'link_url', FILTER_SANITIZE_URL);
            if (empty($link_url)) {
                $error_message = "External Link URL is required.";
            } else {
                try {
                    $stmt = $db->prepare("INSERT INTO learning_materials (title, description, file_path, file_type, file_size, material_type, uploaded_by, class_id, subject_id, access_level, created_at)
                                          VALUES (:title, :description, :file_path, 'link', 0, 'link', :uploaded_by, :class_id, :subject_id, :access_level, NOW())");
                    $stmt->execute([
                        ':title' => $title,
                        ':description' => $description,
                        ':file_path' => $link_url,
                        ':uploaded_by' => $user_id,
                        ':class_id' => $class_id,
                        ':subject_id' => $subject_id,
                        ':access_level' => $access_level
                    ]);
                    $success_message = "Learning material link added successfully!";
                } catch (PDOException $e) {
                    $error_message = "Database Error: " . $e->getMessage();
                }
            }
        } else {
            // File upload
            if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['material_file'];
                $upload_dir = '../uploads/learning_materials/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_name = $file['name'];
                $file_size = $file['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'mp4', 'mkv', 'avi', 'mp3', 'wav', 'png', 'jpg', 'jpeg'];
                if (!in_array($file_ext, $allowed_extensions)) {
                    $error_message = "Unsupported file type. Extensions allowed: " . implode(', ', $allowed_extensions);
                } elseif ($file_size > 100 * 1024 * 1024) { // 100MB
                    $error_message = "File is too large. Max size is 100MB.";
                } else {
                    $unique_filename = 'material_' . time() . '_' . rand(1000, 9999) . '.' . $file_ext;
                    $relative_path = 'uploads/learning_materials/' . $unique_filename;
                    $full_destination = '../' . $relative_path;

                    if (move_uploaded_file($file['tmp_name'], $full_destination)) {
                        try {
                            $stmt = $db->prepare("INSERT INTO learning_materials (title, description, file_path, file_type, file_size, material_type, uploaded_by, class_id, subject_id, access_level, created_at)
                                                  VALUES (:title, :description, :file_path, :file_type, :file_size, :material_type, :uploaded_by, :class_id, :subject_id, :access_level, NOW())");
                            $stmt->execute([
                                ':title' => $title,
                                ':description' => $description,
                                ':file_path' => $relative_path,
                                ':file_type' => $file_ext,
                                ':file_size' => $file_size,
                                ':material_type' => $material_type,
                                ':uploaded_by' => $user_id,
                                ':class_id' => $class_id,
                                ':subject_id' => $subject_id,
                                ':access_level' => $access_level
                            ]);
                            $success_message = "Learning material uploaded successfully!";
                        } catch (PDOException $e) {
                            $error_message = "Database Error: " . $e->getMessage();
                        }
                    } else {
                        $error_message = "Failed to move uploaded file.";
                    }
                }
            } else {
                $error_message = "Please select a file to upload.";
            }
        }
    }
}

// Fetch lists for filters
$classes = [];
$subjects = [];
try {
    if ($role === 'student') {
        // Students only get their own active class (and its subjects) in the
        // filter dropdowns, so no other class names are exposed in the UI.
        $sc_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :sid AND status = 'active' LIMIT 1");
        $sc_stmt->execute([':sid' => $user_id]);
        $own_class_id = $sc_stmt->fetchColumn() ?: 0;

        $cls_stmt = $db->prepare("SELECT id, name FROM classes WHERE id = :cid ORDER BY name");
        $cls_stmt->execute([':cid' => $own_class_id]);
        $classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);

        $subj_stmt = $db->prepare("SELECT id, name, class_id FROM subjects WHERE class_id = :cid ORDER BY name");
        $subj_stmt->execute([':cid' => $own_class_id]);
        $subjects = $subj_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $classes = $db->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = $db->query("SELECT id, name, class_id FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignore
}

// Build Search and Filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$type_filter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: '';
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$subject_filter = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT) ?: '';

// Determine Student Class ID to restrict access
$student_class_id = null;
if ($role === 'student') {
    try {
        $class_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $class_stmt->execute([':student_id' => $user_id]);
        $student_class_id = $class_stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        // Ignore
    }
}

// Build Materials Query
$where_clauses = ["1=1"];
$query_params = [];

if ($search !== '') {
    $where_clauses[] = "(lm.title LIKE :search OR lm.description LIKE :search)";
    $query_params[':search'] = '%' . $search . '%';
}

if ($type_filter !== '') {
    $where_clauses[] = "lm.material_type = :type";
    $query_params[':type'] = $type_filter;
}

if ($class_filter !== '') {
    $where_clauses[] = "lm.class_id = :class_id";
    $query_params[':class_id'] = $class_filter;
}

if ($subject_filter !== '') {
    $where_clauses[] = "lm.subject_id = :subject_id";
    $query_params[':subject_id'] = $subject_filter;
}

// Access Restrictions
if ($role === 'student') {
    if ($student_class_id) {
        $where_clauses[] = "(lm.access_level = 'public' OR ((lm.access_level = 'class_only' OR lm.access_level = 'subject_only') AND lm.class_id = :student_class_id))";
        $query_params[':student_class_id'] = $student_class_id;
    } else {
        $where_clauses[] = "lm.access_level = 'public'";
    }
} elseif ($role === 'teacher') {
    $where_clauses[] = "(lm.access_level != 'private' OR lm.uploaded_by = :current_user)";
    $query_params[':current_user'] = $user_id;
}

$materials = [];
try {
    $materials_query = "
        SELECT lm.*, u.name as uploaded_by_name, c.name as class_name, s.name as subject_name
        FROM learning_materials lm
        JOIN users u ON lm.uploaded_by = u.id
        LEFT JOIN classes c ON lm.class_id = c.id
        LEFT JOIN subjects s ON lm.subject_id = s.id
        WHERE " . implode(' AND ', $where_clauses) . "
        ORDER BY lm.created_at DESC
    ";
    $stmt = $db->prepare($materials_query);
    $stmt->execute($query_params);
    $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Error fetching materials: " . $e->getMessage();
}

$title = "Learning Materials";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
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
                                <h1 class="text-3xl font-bold mb-2">Learning Materials</h1>
                                <p class="text-blue-100 text-lg">Access and manage course materials, documents, and resources</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-book mr-2"></i>
                                        Online Learning
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-book text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-green-100 border border-green-200 text-green-800 dark:bg-green-900 dark:border-green-800 dark:text-green-200">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-100 border border-red-200 text-red-800 dark:bg-red-900 dark:border-red-800 dark:text-red-200">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="flex justify-end items-center mb-6">
                    <div class="flex space-x-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                        <button onclick="showUploadModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i>Upload Material
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Material Types Quick Filter Cards -->
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8">
                    <a href="materials.php?type=document" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700 text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-200">
                        <div class="w-10 h-10 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-file-pdf text-red-600 dark:text-red-400 text-lg"></i>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white block">Documents</span>
                    </a>

                    <a href="materials.php?type=video" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700 text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-200">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-video text-blue-600 dark:text-blue-400 text-lg"></i>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white block">Videos</span>
                    </a>

                    <a href="materials.php?type=audio" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700 text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-200">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-volume-up text-green-600 dark:text-green-400 text-lg"></i>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white block">Audio</span>
                    </a>

                    <a href="materials.php?type=presentation" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700 text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-200">
                        <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/30 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-file-powerpoint text-purple-600 dark:text-purple-400 text-lg"></i>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white block">Presentations</span>
                    </a>

                    <a href="materials.php?type=link" class="bg-white dark:bg-gray-800 rounded-xl shadow p-4 border border-gray-200 dark:border-gray-700 text-center hover:bg-gray-50 dark:hover:bg-gray-700 transition duration-200">
                        <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center mx-auto mb-2">
                            <i class="fas fa-link text-orange-600 dark:text-orange-400 text-lg"></i>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 dark:text-white block">Links</span>
                    </a>
                </div>

                <!-- Search and Filter -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search materials..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <select name="type" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Types</option>
                                    <option value="document" <?php echo $type_filter === 'document' ? 'selected' : ''; ?>>Documents</option>
                                    <option value="video" <?php echo $type_filter === 'video' ? 'selected' : ''; ?>>Videos</option>
                                    <option value="audio" <?php echo $type_filter === 'audio' ? 'selected' : ''; ?>>Audio</option>
                                    <option value="presentation" <?php echo $type_filter === 'presentation' ? 'selected' : ''; ?>>Presentations</option>
                                    <option value="link" <?php echo $type_filter === 'link' ? 'selected' : ''; ?>>Links</option>
                                </select>
                            </div>
                            <div>
                                <select id="filter_class" name="class_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $cls): ?>
                                        <option value="<?php echo $cls['id']; ?>" <?php echo $class_filter == $cls['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cls['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <select id="filter_subject" name="subject_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Subjects</option>
                                    <?php foreach ($subjects as $sub): ?>
                                        <option value="<?php echo $sub['id']; ?>" data-class-id="<?php echo $sub['class_id']; ?>" <?php echo $subject_filter == $sub['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sub['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg transition-colors">
                                    <i class="fas fa-search mr-2"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Materials Display -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Available Materials</h2>

                    <?php if (empty($materials)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-folder-open text-gray-400 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Materials Found</h3>
                            <p class="text-gray-500 dark:text-gray-400 mb-6">There are no materials available matching your filters.</p>
                        </div>
                    <?php else: ?>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <?php foreach ($materials as $mat):
                                $type = $mat['material_type'];
                                $icon_class = 'fas fa-file-alt text-gray-600';
                                $bg_color = 'bg-gray-100 dark:bg-gray-750';
                                
                                if ($type === 'document') {
                                    $icon_class = 'fas fa-file-pdf text-red-600 dark:text-red-400';
                                    $bg_color = 'bg-red-50 dark:bg-red-900/10';
                                } elseif ($type === 'video') {
                                    $icon_class = 'fas fa-video text-blue-600 dark:text-blue-400';
                                    $bg_color = 'bg-blue-50 dark:bg-blue-900/10';
                                } elseif ($type === 'audio') {
                                    $icon_class = 'fas fa-volume-up text-green-600 dark:text-green-400';
                                    $bg_color = 'bg-green-50 dark:bg-green-900/10';
                                } elseif ($type === 'presentation') {
                                    $icon_class = 'fas fa-file-powerpoint text-purple-600 dark:text-purple-400';
                                    $bg_color = 'bg-purple-50 dark:bg-purple-900/10';
                                } elseif ($type === 'link') {
                                    $icon_class = 'fas fa-link text-orange-600 dark:text-orange-400';
                                    $bg_color = 'bg-orange-50 dark:bg-orange-900/10';
                                }
                            ?>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-5 hover:shadow-lg transition duration-200 flex flex-col justify-between bg-white dark:bg-gray-800">
                                <div>
                                    <div class="flex items-start justify-between mb-3">
                                        <div class="flex items-center space-x-3 min-w-0">
                                            <div class="w-10 h-10 <?php echo $bg_color; ?> rounded-lg flex items-center justify-center flex-shrink-0">
                                                <i class="<?php echo $icon_class; ?> text-lg"></i>
                                            </div>
                                            <div class="min-w-0">
                                                <h4 class="font-bold text-gray-900 dark:text-white truncate" title="<?php echo htmlspecialchars($mat['title']); ?>">
                                                    <?php echo htmlspecialchars($mat['title']); ?>
                                                </h4>
                                                <span class="text-[10px] font-semibold uppercase tracking-wider text-blue-600 dark:text-blue-400">
                                                    <?php echo htmlspecialchars($mat['subject_name'] ?? 'General'); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 line-clamp-2"><?php echo htmlspecialchars($mat['description'] ?? ''); ?></p>
                                </div>
                                <div class="border-t border-gray-150 dark:border-gray-700 pt-3 flex items-center justify-between text-xs">
                                    <div class="text-gray-500 dark:text-gray-400">
                                        <span>By: <?php echo htmlspecialchars($mat['uploaded_by_name']); ?></span><br>
                                        <span><?php echo date('M d, Y', strtotime($mat['created_at'])); ?></span>
                                    </div>
                                    <div>
                                        <a href="materials.php?download_id=<?php echo $mat['id']; ?>" <?php echo $type === 'link' ? 'target="_blank"' : ''; ?> class="inline-flex items-center bg-blue-50 hover:bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 dark:text-blue-300 font-semibold px-3 py-1.5 rounded-lg transition-colors">
                                            <?php if ($type === 'link'): ?>
                                                <i class="fas fa-external-link-alt mr-1"></i>Open Link
                                            <?php else: ?>
                                                <i class="fas fa-download mr-1"></i>Download
                                            <?php endif; ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
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

<!-- Upload Material Modal -->
<?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
<div id="uploadModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full" style="max-height: 85vh; overflow-y: auto;">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Upload Learning Material</h3>
                <button onclick="hideUploadModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="upload_material">
                
                <div>
                    <label for="upload_material_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Material Type</label>
                    <select id="upload_material_type" name="material_type" onchange="toggleUploadTypeFields()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="document">Document</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="presentation">Presentation</option>
                        <option value="link">External Link</option>
                    </select>
                </div>
                <div>
                    <label for="upload_title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title *</label>
                    <input type="text" id="upload_title" name="title" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter material title">
                </div>
                <div>
                    <label for="upload_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea id="upload_description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter material description"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="upload_class" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                        <select id="upload_class" name="class_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Class (Optional)</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="upload_subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                        <select id="upload_subject" name="subject_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Subject (Optional)</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo $sub['id']; ?>" data-class-id="<?php echo $sub['class_id']; ?>"><?php echo htmlspecialchars($sub['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="upload_access" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Level</label>
                    <select id="upload_access" name="access_level" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="class_only">Class Only</option>
                        <option value="public">Public (All Roles)</option>
                        <option value="private">Private (Only Me)</option>
                    </select>
                </div>
                
                <!-- File Upload Container -->
                <div id="file_upload_container">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">File Upload *</label>
                    <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700/50" onclick="document.getElementById('material_file_input').click()">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400 mb-2" id="file_drag_label">Click to upload or drag and drop</p>
                        <p class="text-sm text-gray-500 dark:text-gray-500">PDF, DOC, PPT, MP4, MP3, XLS, PNG, JPG (Max 100MB)</p>
                        <input type="file" id="material_file_input" name="material_file" class="hidden" onchange="handleMaterialFileChange(this)">
                    </div>
                    <div id="material_file_info" class="hidden mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg flex items-center justify-between">
                        <span id="material_file_name" class="text-sm font-medium text-gray-700 dark:text-gray-300"></span>
                        <button type="button" class="text-red-500 hover:text-red-700" onclick="clearSelectedMaterialFile()">
                            <i class="fas fa-times-circle"></i>
                        </button>
                    </div>
                </div>

                <!-- External Link Container -->
                <div id="link_url_container" class="hidden">
                    <label for="upload_link_url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">External Link URL *</label>
                    <input type="url" id="upload_link_url" name="link_url" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="https://example.com/lesson">
                </div>

                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" onclick="hideUploadModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-upload mr-2"></i>Upload Material
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showUploadModal() {
    document.getElementById('uploadModal').classList.remove('hidden');
}

function hideUploadModal() {
    document.getElementById('uploadModal').classList.add('hidden');
}

function toggleUploadTypeFields() {
    const type = document.getElementById('upload_material_type').value;
    const fileContainer = document.getElementById('file_upload_container');
    const linkContainer = document.getElementById('link_url_container');
    const fileInput = document.getElementById('material_file_input');
    const linkInput = document.getElementById('upload_link_url');

    if (type === 'link') {
        fileContainer.classList.add('hidden');
        linkContainer.classList.remove('hidden');
        fileInput.removeAttribute('required');
        linkInput.setAttribute('required', 'required');
    } else {
        fileContainer.classList.remove('hidden');
        linkContainer.classList.add('hidden');
        fileInput.setAttribute('required', 'required');
        linkInput.removeAttribute('required');
    }
}

function handleMaterialFileChange(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];
        document.getElementById('material_file_name').textContent = file.name;
        document.getElementById('material_file_info').classList.remove('hidden');
    }
}

function clearSelectedMaterialFile() {
    document.getElementById('material_file_input').value = '';
    document.getElementById('material_file_info').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('uploadModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideUploadModal();
    }
});

function setupDynamicSubjectFilter(classSelectId, subjectSelectId) {
    const classSelect = document.getElementById(classSelectId);
    const subjectSelect = document.getElementById(subjectSelectId);
    if (!classSelect || !subjectSelect) return;

    const originalOptions = Array.from(subjectSelect.options).map(opt => ({
        value: opt.value,
        text: opt.text,
        classId: opt.getAttribute('data-class-id')
    }));

    function updateSubjects() {
        const selectedClassId = classSelect.value;
        const currentSelectedValue = subjectSelect.value;
        
        subjectSelect.innerHTML = '';
        
        originalOptions.forEach(opt => {
            if (!selectedClassId || !opt.classId || opt.classId == selectedClassId || opt.value === '') {
                const newOpt = document.createElement('option');
                newOpt.value = opt.value;
                newOpt.textContent = opt.text;
                if (opt.classId) {
                    newOpt.setAttribute('data-class-id', opt.classId);
                }
                if (opt.value === currentSelectedValue) {
                    newOpt.selected = true;
                }
                subjectSelect.appendChild(newOpt);
            }
        });
    }

    classSelect.addEventListener('change', updateSubjects);
    updateSubjects();
}

document.addEventListener('DOMContentLoaded', function() {
    setupDynamicSubjectFilter('filter_class', 'filter_subject');
    setupDynamicSubjectFilter('upload_class', 'upload_subject');
});
</script>
