<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('documents');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('documents'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'] ?? 'User';
$is_parent = ($role === 'parent');

// Debug: Ensure variables are set
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Get statistics based on role
$stats = [];

try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Admin statistics
        $recent_uploads_query = "SELECT COUNT(*) as count FROM documents WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $recent_uploads_stmt = $db->prepare($recent_uploads_query);
        $recent_uploads_stmt->execute();
        $recent_uploads = $recent_uploads_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $certificates_query = "SELECT COUNT(*) as count FROM documents WHERE document_type = 'certificate'";
        $certificates_stmt = $db->prepare($certificates_query);
        $certificates_stmt->execute();
        $certificates = $certificates_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $shared_files_query = "SELECT COUNT(DISTINCT document_id) as count FROM document_shares";
        $shared_files_stmt = $db->prepare($shared_files_query);
        $shared_files_stmt->execute();
        $shared_files = $shared_files_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stats = [
            'recent_uploads' => $recent_uploads,
            'certificates' => $certificates,
            'issued_certificates' => $certificates,
            'shared_files' => $shared_files
        ];
    } elseif ($role === 'teacher') {
        // Teacher statistics
        $my_uploads_query = "SELECT COUNT(*) as count FROM documents WHERE uploaded_by = :user_id";
        $my_uploads_stmt = $db->prepare($my_uploads_query);
        $my_uploads_stmt->bindParam(':user_id', $user_id);
        $my_uploads_stmt->execute();
        $my_uploads = $my_uploads_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $my_shares_query = "SELECT COUNT(*) as count FROM document_shares WHERE shared_by = :user_id";
        $my_shares_stmt = $db->prepare($my_shares_query);
        $my_shares_stmt->bindParam(':user_id', $user_id);
        $my_shares_stmt->execute();
        $my_shares = $my_shares_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $total_downloads_query = "SELECT SUM(download_count) as total FROM documents WHERE uploaded_by = :user_id";
        $total_downloads_stmt = $db->prepare($total_downloads_query);
        $total_downloads_stmt->bindParam(':user_id', $user_id);
        $total_downloads_stmt->execute();
        $total_downloads = $total_downloads_stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

        $stats = [
            'my_uploads' => $my_uploads,
            'my_shares' => $my_shares,
            'recent_uploads' => $my_uploads,
            'total_downloads' => $total_downloads
        ];
    } else {
        // Student/Parent statistics
        $accessible_docs_query = "
            SELECT COUNT(*) as count FROM documents du
            LEFT JOIN document_shares sd ON du.id = sd.document_id
            WHERE du.access_level IN ('public', 'students', 'parents')
            OR sd.shared_with_user_id = :user_id
            OR (sd.shared_with_role = :role AND sd.shared_with_user_id IS NULL)
        ";
        $accessible_docs_stmt = $db->prepare($accessible_docs_query);
        $accessible_docs_stmt->bindParam(':user_id', $user_id);
        $accessible_docs_stmt->bindParam(':role', $role);
        $accessible_docs_stmt->execute();
        $accessible_docs = $accessible_docs_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $my_certificates_query = "SELECT COUNT(*) as count FROM documents WHERE document_type = 'certificate' AND title LIKE :name";
        $my_certificates_stmt = $db->prepare($my_certificates_query);
        $search_name = '%' . $user_name . '%';
        $my_certificates_stmt->bindParam(':name', $search_name);
        $my_certificates_stmt->execute();
        $my_certificates = $my_certificates_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $transcript_requests_query = "SELECT COUNT(*) as count FROM transcript_requests WHERE requested_by = :user_id";
        $transcript_requests_stmt = $db->prepare($transcript_requests_query);
        $transcript_requests_stmt->bindParam(':user_id', $user_id);
        $transcript_requests_stmt->execute();
        $transcript_requests = $transcript_requests_stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stats = [
            'accessible_docs' => $accessible_docs,
            'my_certificates' => $my_certificates,
            'my_downloads' => 0,
            'transcript_requests' => $transcript_requests
        ];
    }
} catch (PDOException $e) {
    // Fallback to dummy data if tables don't exist
    $stats = [
        'recent_uploads' => 0,
        'certificates' => 0,
        'issued_certificates' => 0,
        'shared_files' => 0,
        'my_uploads' => 0,
        'my_shares' => 0,
        'total_downloads' => 0,
        'accessible_docs' => 0,
        'my_certificates' => 0,
        'my_downloads' => 0,
        'transcript_requests' => 0
    ];
}

// Get recent documents
$recent_documents = [];

try {
    // Parents only see documents meant for them (public/parents) or explicitly
    // shared with them; never staff- or student-restricted files.
    $allowed_levels = $is_parent ? "('public', 'parents')" : "('public', 'staff', 'students', 'parents')";
    $recent_docs_query = "
        SELECT du.*, u.name as uploader_name
        FROM documents du
        LEFT JOIN users u ON du.uploaded_by = u.id
        WHERE du.access_level IN $allowed_levels
        " . ($is_parent ? "" : "OR du.uploaded_by = :user_id") . "
        OR EXISTS (
            SELECT 1 FROM document_shares sd
            WHERE sd.document_id = du.id
            AND (sd.shared_with_user_id = :user_id OR sd.shared_with_role = :role)
        )
        ORDER BY du.created_at DESC
        LIMIT 10
    ";
    $recent_docs_stmt = $db->prepare($recent_docs_query);
    $recent_docs_stmt->bindParam(':user_id', $user_id);
    $recent_docs_stmt->bindParam(':role', $role);
    $recent_docs_stmt->execute();
    $recent_documents = $recent_docs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_documents = [];
}

$title = "Document & File Management";

// Force output buffering to catch any errors
ob_start();

// Add proper headers
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

include '../includes/header.php';
include '../includes/sidebar.php';

// Force title in HTML after headers are included
echo '<script>
if (document.title === "0" || document.title === "0 - School Management System" || document.title.startsWith("0")) {
    document.title = "Document & File Management - School Management System";
}
</script>';
?>

<!-- Document & File Management Page - Cache Buster: <?php echo time(); ?> -->
<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen documents-container">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <?php if ($is_parent): ?>
                                <h1 class="text-3xl font-bold mb-2">My Documents</h1>
                                <p class="text-blue-100 text-lg">Access and download your children's certificates, transcripts and shared files</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-download mr-2"></i>
                                        <span>View &amp; Download</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-certificate mr-2"></i>
                                        <span>Certificates &amp; Transcripts</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-share-alt mr-2"></i>
                                        <span>Shared Files</span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <h1 class="text-3xl font-bold mb-2">Document & File Management</h1>
                                <p class="text-blue-100 text-lg">Manage, share, and organize all your documents securely</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-cloud-upload-alt mr-2"></i>
                                        <span>Upload & Download</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-certificate mr-2"></i>
                                        <span>Certificates & IDs</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-share-alt mr-2"></i>
                                        <span>Secure Sharing</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-alt text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <!-- Recent Uploads -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Recent Uploads</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['recent_uploads'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-upload mr-1"></i>
                                    Last 30 days
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-cloud-upload-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Certificates -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Certificate Templates</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['certificates'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-certificate mr-1"></i>
                                    Available
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-certificate text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Issued Certificates -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Issued Certificates</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['issued_certificates'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-award mr-1"></i>
                                    Generated
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-award text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Shared Files -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Shared Files</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['shared_files'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-share-alt mr-1"></i>
                                    Active shares
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-share-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($role === 'teacher'): ?>
                    <!-- Teacher specific stats -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Uploads</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_uploads'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-file mr-1"></i>
                                    Total files
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-upload text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Files Shared</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_shares'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-share mr-1"></i>
                                    Active
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-share text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Recent Uploads</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['recent_uploads'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-clock mr-1"></i>
                                    This week
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Downloads</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_downloads'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-download mr-1"></i>
                                    All time
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-download text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php else: // Student/Parent ?>
                    <!-- Student/Parent specific stats -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Accessible Documents</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['accessible_docs'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-eye mr-1"></i>
                                    Available
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Certificates</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_certificates'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-award mr-1"></i>
                                    Issued
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-certificate text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Downloads</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_downloads'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-download mr-1"></i>
                                    Total
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-download text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Transcript Requests</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['transcript_requests'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-scroll mr-1"></i>
                                    Submitted
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-scroll text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions Grid -->
                <?php if ($is_parent): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Certificates & IDs -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-certificate text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-2 py-1 rounded-full">View</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Certificates &amp; IDs</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">View and download your children's certificates and ID cards.</p>
                        <a href="certificates.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm">
                            <span>View Certificates</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Transcripts -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-scroll text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 px-2 py-1 rounded-full">Academic</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Transcripts</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">View and download your children's official academic transcripts.</p>
                        <a href="transcripts.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm">
                            <span>View Transcripts</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Shared Files -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-share-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200 px-2 py-1 rounded-full">Shared</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Shared Files</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Documents and files the school has shared with you.</p>
                        <a href="shared.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm">
                            <span>View Shared Files</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Search -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-search text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 px-2 py-1 rounded-full">Find</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Search Documents</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Search the documents that are available to you.</p>
                        <button data-action="show-search-modal" class="inline-flex items-center text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium text-sm">
                            <span>Search</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Upload Documents -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-cloud-upload-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full">Upload</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Upload Documents</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Upload and organize student and staff documents securely.</p>
                        <a href="upload.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm">
                            <span>Upload Files</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Certificates & IDs -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-certificate text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-2 py-1 rounded-full">Generate</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Certificates & IDs</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Generate certificates and ID cards with QR codes and verification.</p>
                        <a href="certificate_generator.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm">
                            <span>Generate Certificates</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Transcripts -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-scroll text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 px-2 py-1 rounded-full">Archive</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Student Transcripts</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Archive and generate official student transcripts and academic records.</p>
                        <a href="transcripts.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm">
                            <span>View Transcripts</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Shared Files -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-share-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200 px-2 py-1 rounded-full">Share</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Secure File Sharing</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Share files securely between departments with access controls.</p>
                        <a href="shared.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm">
                            <span>Shared Files</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Document Categories -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-folder text-indigo-600 dark:text-indigo-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200 px-2 py-1 rounded-full">Organize</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Document Categories</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Browse documents by categories and departments for easy access.</p>
                        <button data-action="show-categories-modal" class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium text-sm">
                            <span>Browse Categories</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </button>
                    </div>

                    <!-- Document Search -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-search text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 px-2 py-1 rounded-full">Search</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Advanced Search</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Search documents by name, type, date, or content with filters.</p>
                        <button data-action="show-search-modal" class="inline-flex items-center text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium text-sm">
                            <span>Search Documents</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </button>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Documents -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><?php echo $is_parent ? 'Documents Available to You' : 'Recent Documents'; ?></h2>
                            <?php if (!$is_parent): ?>
                            <a href="upload.php" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                                Upload New
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_documents)): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_documents as $doc): ?>
                            <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200">
                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <?php
                                    $icon = 'fas fa-file';
                                    switch($doc['file_type']) {
                                        case 'pdf': $icon = 'fas fa-file-pdf text-red-600'; break;
                                        case 'doc':
                                        case 'docx': $icon = 'fas fa-file-word text-blue-600'; break;
                                        case 'xls':
                                        case 'xlsx': $icon = 'fas fa-file-excel text-green-600'; break;
                                        case 'jpg':
                                        case 'jpeg':
                                        case 'png': $icon = 'fas fa-file-image text-purple-600'; break;
                                        default: $icon = 'fas fa-file text-gray-600'; break;
                                    }
                                    ?>
                                    <i class="<?php echo $icon; ?>"></i>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($doc['title']); ?></h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        Uploaded by <?php echo htmlspecialchars($doc['uploader_name']); ?> •
                                        <?php echo ucfirst(str_replace('_', ' ', $doc['document_type'])); ?> •
                                        <?php echo number_format($doc['file_size'] / 1024, 1); ?> KB
                                    </p>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 px-2 py-1 rounded-full">
                                        <?php echo strtoupper($doc['file_type']); ?>
                                    </span>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                                    </div>
                                    <div class="flex space-x-1">
                                        <a href="download.php?id=<?php echo $doc['id']; ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 p-1" title="Download">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <?php if (!$is_parent): ?>
                                        <a href="shared.php" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 p-1" title="Share">
                                            <i class="fas fa-share-alt"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-file-alt text-gray-400 text-4xl mb-4"></i>
                            <?php if ($is_parent): ?>
                            <p class="text-gray-600 dark:text-gray-400">No documents are available to you yet. Your children's certificates, transcripts and any files the school shares with you will appear here.</p>
                            <a href="certificates.php" class="inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200">
                                <i class="fas fa-certificate mr-2"></i>
                                View Certificates
                            </a>
                            <?php else: ?>
                            <p class="text-gray-600 dark:text-gray-400">No documents found. Start by uploading your first document.</p>
                            <a href="upload.php" class="inline-flex items-center mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-upload mr-2"></i>
                                Upload Document
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Document Management Features -->
                <?php if ($is_parent): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">What You Can Do Here</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-certificate text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Certificates &amp; IDs</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">View and download your children's certificates and ID cards.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-scroll text-purple-600 dark:text-purple-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Academic Transcripts</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Access official academic transcripts and records.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-share-alt text-orange-600 dark:text-orange-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Shared Files</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Open documents the school has shared with you.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-download text-blue-600 dark:text-blue-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Easy Downloads</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Download any available document with a single click.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-shield-alt text-red-600 dark:text-red-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Private &amp; Secure</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">You only see documents for your children or shared with you.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-headset text-indigo-600 dark:text-indigo-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Need a Document?</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Contact the school office to request additional records.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Document Management Features</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Secure File Upload</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Upload documents with virus scanning and validation</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Certificate Generation</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Generate certificates and ID cards with QR codes</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Transcript Archive</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Archive and manage student academic transcripts</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Access Control</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Role-based access and permission management</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Version Control</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Track document versions and changes</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Audit Trail</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Complete audit trail for all document activities</p>
                                </div>
                            </div>
                        </div>
                    </div>
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

<!-- Categories Modal -->
<div id="categoriesModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Document Categories</h3>
                <button data-action="hide-categories-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-graduation-cap text-green-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Academic Records</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Student transcripts, certificates, and academic documents</p>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-building text-blue-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Administrative</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">School policies, procedures, and administrative documents</p>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-user-graduate text-purple-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Student Files</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Individual student documents and records</p>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-users text-orange-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Staff Documents</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Employee records and staff-related documents</p>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-dollar-sign text-red-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Financial Records</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Fee receipts, financial statements, and payment records</p>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-file-alt text-cyan-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Forms & Templates</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Application forms, templates, and blank documents</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search Modal -->
<div id="searchModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Advanced Document Search</h3>
                <button data-action="hide-search-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <form class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search Term</label>
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter document name or content...">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Document Type</label>
                        <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">All Types</option>
                            <option value="certificate">Certificate</option>
                            <option value="transcript">Transcript</option>
                            <option value="report">Report</option>
                            <option value="policy">Policy</option>
                            <option value="form">Form</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">File Type</label>
                        <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">All Files</option>
                            <option value="pdf">PDF</option>
                            <option value="doc">Word Document</option>
                            <option value="xls">Excel</option>
                            <option value="jpg">Image</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date From</label>
                        <input type="date" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date To</label>
                        <input type="date" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" data-action="hide-search-modal" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-search mr-2"></i>
                        Search Documents
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Inline JavaScript for modal functionality and title fix
document.addEventListener('DOMContentLoaded', function() {
    // Force correct title immediately
    if (document.title === "0" || document.title === "0 - School Management System" || document.title.startsWith("0") || document.title.trim() === "") {
        document.title = "Document & File Management - School Management System";
        console.log('Fixed title to:', document.title);
    }

    // Modal functions
    function showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
    }

    // Handle button clicks
    document.addEventListener('click', function(e) {
        const action = e.target.getAttribute('data-action') || e.target.closest('[data-action]')?.getAttribute('data-action');

        switch(action) {
            case 'show-categories-modal':
                showModal('categoriesModal');
                break;
            case 'hide-categories-modal':
                hideModal('categoriesModal');
                break;
            case 'show-search-modal':
                showModal('searchModal');
                break;
            case 'hide-search-modal':
                hideModal('searchModal');
                break;
        }
    });

    // Close modals when clicking outside
    document.querySelectorAll('.fixed.inset-0').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.add('hidden');
                document.body.style.overflow = 'auto';
            }
        });
    });

    // Escape key closes modals
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(modal => {
                modal.classList.add('hidden');
                document.body.style.overflow = 'auto';
            });
        }
    });
});
</script>
