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

// Handle file sharing
if ($_POST && isset($_POST['share_file'])) {
    $document_id = $_POST['document_id'];
    $shared_with = $_POST['shared_with'] ?? null;
    if (empty($shared_with)) {
        $shared_with = null;
    }
    $shared_with_role = $_POST['shared_with_role'] ?? null;
    if ($shared_with_role === 'admin') {
        $shared_with_role = 'school_admin';
    }
    if (empty($shared_with_role)) {
        $shared_with_role = null;
    }
    $access_type = $_POST['access_type'];
    $expires_at = $_POST['expires_at'] ?? null;
    if (empty($expires_at)) {
        $expires_at = null;
    }

    try {
        $insert_query = "
            INSERT INTO document_shares
            (document_id, shared_by, shared_with_user_id, shared_with_role, permission_level, expiry_date, created_at)
            VALUES (:document_id, :shared_by, :shared_with, :shared_with_role, :access_type, :expires_at, NOW())
        ";
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':document_id', $document_id);
        $insert_stmt->bindParam(':shared_by', $user_id);
        $insert_stmt->bindParam(':shared_with', $shared_with);
        $insert_stmt->bindParam(':shared_with_role', $shared_with_role);
        $insert_stmt->bindParam(':access_type', $access_type);
        $insert_stmt->bindParam(':expires_at', $expires_at);
        $insert_stmt->execute();

        // Send notifications
        if ($shared_with) {
            $doc_query = "SELECT title FROM documents WHERE id = :doc_id";
            $doc_stmt = $db->prepare($doc_query);
            $doc_stmt->bindParam(':doc_id', $document_id);
            $doc_stmt->execute();
            $doc_title = $doc_stmt->fetchColumn() ?: 'a file';

            $notif_query = "
                INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                VALUES (:user_id, :title, :message, 'info', 0, NOW())
            ";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->bindParam(':user_id', $shared_with);
            $notif_title = "New Shared File";
            $sender_name = $_SESSION['name'] ?? 'Someone';
            $notif_msg = htmlspecialchars($sender_name) . " shared a file with you: \"" . htmlspecialchars($doc_title) . "\".";
            $notif_stmt->bindParam(':title', $notif_title);
            $notif_stmt->bindParam(':message', $notif_msg);
            $notif_stmt->execute();
        } elseif ($shared_with_role) {
            $doc_query = "SELECT title FROM documents WHERE id = :doc_id";
            $doc_stmt = $db->prepare($doc_query);
            $doc_stmt->bindParam(':doc_id', $document_id);
            $doc_stmt->execute();
            $doc_title = $doc_stmt->fetchColumn() ?: 'a file';

            $users_query = "SELECT id FROM users WHERE role = :role";
            $users_stmt = $db->prepare($users_query);
            $users_stmt->bindParam(':role', $shared_with_role);
            $users_stmt->execute();
            $role_users = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

            $notif_query = "
                INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                VALUES (:user_id, :title, :message, 'info', 0, NOW())
            ";
            $notif_stmt = $db->prepare($notif_query);
            $notif_title = "New Shared File";
            $sender_name = $_SESSION['name'] ?? 'Someone';
            $notif_msg = htmlspecialchars($sender_name) . " shared a file with your department: \"" . htmlspecialchars($doc_title) . "\".";
            $notif_stmt->bindParam(':title', $notif_title);
            $notif_stmt->bindParam(':message', $notif_msg);

            foreach ($role_users as $r_user_id) {
                if ($r_user_id != $user_id) {
                    $notif_stmt->bindParam(':user_id', $r_user_id);
                    $notif_stmt->execute();
                }
            }
        }

        $success_message = "File shared successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to share file: " . $e->getMessage();
    }
}

// Get shared files
$shared_files = [];
try {
    $shared_query = "
        SELECT sd.*, sd.expiry_date as expires_at, sd.permission_level as access_type, d.title, d.file_name, d.file_type, d.file_size, d.created_at as upload_date,
               sharer.name as shared_by_name, recipient.name as shared_with_name
        FROM document_shares sd
        LEFT JOIN documents d ON sd.document_id = d.id
        LEFT JOIN users sharer ON sd.shared_by = sharer.id
        LEFT JOIN users recipient ON sd.shared_with_user_id = recipient.id
        WHERE sd.shared_with_user_id = :user_id
        OR sd.shared_with_role = :role
        OR sd.shared_by = :user_id
        ORDER BY sd.created_at DESC
    ";
    $shared_stmt = $db->prepare($shared_query);
    $shared_stmt->bindParam(':user_id', $user_id);
    $shared_stmt->bindParam(':role', $role);
    $shared_stmt->execute();
    $shared_files = $shared_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $shared_files = [];
}

// Get user's documents for sharing
$my_documents = [];
if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    try {
        $docs_query = "
            SELECT id, title, file_name, file_type
            FROM documents
            WHERE uploaded_by = :user_id
            ORDER BY title
        ";
        $docs_stmt = $db->prepare($docs_query);
        $docs_stmt->bindParam(':user_id', $user_id);
        $docs_stmt->execute();
        $my_documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $my_documents = [];
    }
}

// Get users for sharing
$users = [];
try {
    $users_query = "SELECT id, name, role FROM users WHERE id != :user_id ORDER BY name";
    $users_stmt = $db->prepare($users_query);
    $users_stmt->bindParam(':user_id', $user_id);
    $users_stmt->execute();
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $users = [];
}

$title = "Shared Files";
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
                                <h1 class="text-3xl font-bold mb-2">Shared Files</h1>
                                <p class="text-blue-100 text-lg">Share files securely between departments with access controls</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-share-alt mr-2"></i>
                                        File Sharing
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-share-alt text-6xl text-white/80"></i>
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
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <button onclick="showShareModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-share-alt mr-2"></i>Share File
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

                <!-- Shared Files -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Shared Files</h2>
                    </div>

                    <?php if (empty($shared_files)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-share-alt text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Shared Files</h3>
                        <p class="text-gray-500 dark:text-gray-400">No files have been shared with you yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">File</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Shared By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Shared With</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Access</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Expires</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Shared</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($shared_files as $file): ?>
                                <?php
                                $is_expired = $file['expires_at'] && strtotime($file['expires_at']) < time();
                                $file_icon = 'fas fa-file';
                                switch(strtolower($file['file_type'])) {
                                    case 'pdf': $file_icon = 'fas fa-file-pdf text-red-600'; break;
                                    case 'doc':
                                    case 'docx': $file_icon = 'fas fa-file-word text-blue-600'; break;
                                    case 'xls':
                                    case 'xlsx': $file_icon = 'fas fa-file-excel text-green-600'; break;
                                    case 'jpg':
                                    case 'jpeg':
                                    case 'png': $file_icon = 'fas fa-file-image text-purple-600'; break;
                                }
                                ?>
                                <tr class="<?php echo $is_expired ? 'opacity-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <i class="<?php echo $file_icon; ?> mr-3"></i>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($file['title']); ?></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($file['file_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($file['shared_by_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php if ($file['shared_with_name']): ?>
                                            <?php echo htmlspecialchars($file['shared_with_name']); ?>
                                        <?php else: ?>
                                            <span class="text-blue-600 dark:text-blue-400"><?php echo htmlspecialchars(formatRoleName($file['shared_with_role'])); ?> Role</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($file['access_type']) {
                                                case 'view': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'download': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'edit': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($file['access_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php if ($file['expires_at']): ?>
                                            <?php echo date('M j, Y', strtotime($file['expires_at'])); ?>
                                            <?php if ($is_expired): ?>
                                            <span class="text-red-600 dark:text-red-400">(Expired)</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Never
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, Y', strtotime($file['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if (!$is_expired): ?>
                                            <?php if (in_array($file['access_type'], ['view', 'download', 'edit'])): ?>
                                            <a href="download.php?id=<?php echo $file['document_id']; ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium mr-3">
                                                <i class="fas fa-download mr-1"></i>Download
                                            </a>
                                            <?php endif; ?>

                                            <?php if ($file['shared_by'] == $user_id): ?>
                                            <button onclick="revokeShare(<?php echo $file['id']; ?>)" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium">
                                                <i class="fas fa-times mr-1"></i>Revoke
                                            </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Expired</span>
                                        <?php endif; ?>
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

<!-- Share File Modal -->
<?php if (!empty($my_documents)): ?>
<div id="shareModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Share File</h3>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select File</label>
                        <select name="document_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Choose a file to share</option>
                            <?php foreach ($my_documents as $doc): ?>
                            <option value="<?php echo $doc['id']; ?>">
                                <?php echo htmlspecialchars($doc['title']); ?> (<?php echo strtoupper($doc['file_type']); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Share With</label>
                        <div class="space-y-2">
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="share_type" value="user" class="form-radio" checked>
                                    <span class="ml-2">Specific User</span>
                                </label>
                            </div>
                            <div>
                                <label class="inline-flex items-center">
                                    <input type="radio" name="share_type" value="role" class="form-radio">
                                    <span class="ml-2">All Users with Role</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div id="userSelect">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select User</label>
                        <select name="shared_with" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Choose a user</option>
                            <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>">
                                <?php echo htmlspecialchars($user['name']); ?> (<?php echo htmlspecialchars(formatRoleName($user['role'])); ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="roleSelect" class="hidden">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Role</label>
                        <select name="shared_with_role" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Choose a role</option>
                            <option value="student">All Students</option>
                            <option value="teacher">All Teachers</option>
                            <option value="parent">All Parents</option>
                            <option value="school_admin">All Admins</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Type</label>
                        <select name="access_type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="view">View Only</option>
                            <option value="download">View & Download</option>
                            <option value="edit">View, Download & Edit</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Expiry Date (Optional)</label>
                        <input type="datetime-local" name="expires_at" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeShareModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="share_file" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-share-alt mr-2"></i>Share File
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showShareModal() {
    document.getElementById('shareModal').classList.remove('hidden');
}

function closeShareModal() {
    document.getElementById('shareModal').classList.add('hidden');
}

function revokeShare(shareId) {
    if (confirm('Are you sure you want to revoke this file share?')) {
        fetch('revoke_share.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ share_id: shareId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('File share revoked successfully!');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while revoking the share.');
        });
    }
}

// Handle share type radio buttons
document.addEventListener('DOMContentLoaded', function() {
    const shareTypeRadios = document.querySelectorAll('input[name="share_type"]');
    const userSelect = document.getElementById('userSelect');
    const roleSelect = document.getElementById('roleSelect');

    shareTypeRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'user') {
                userSelect.classList.remove('hidden');
                roleSelect.classList.add('hidden');
                userSelect.querySelector('select').required = true;
                roleSelect.querySelector('select').required = false;
            } else {
                userSelect.classList.add('hidden');
                roleSelect.classList.remove('hidden');
                userSelect.querySelector('select').required = false;
                roleSelect.querySelector('select').required = true;
            }
        });
    });
});
</script>
