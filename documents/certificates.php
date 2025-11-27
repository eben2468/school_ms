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
$user_name = $_SESSION['name'];

// Get certificates based on role
$certificates = [];
try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Admin can see all certificates
        $query = "SELECT gc.*, u.name as student_name, ct.name as template_name, ib.name as issued_by_name
                  FROM generated_certificates gc
                  LEFT JOIN users u ON gc.student_id = u.id
                  LEFT JOIN certificate_templates ct ON gc.template_id = ct.id
                  LEFT JOIN users ib ON gc.issued_by = ib.id
                  ORDER BY gc.created_at DESC";
        $stmt = $db->prepare($query);
    } elseif ($role === 'teacher') {
        // Teachers see certificates they issued
        $query = "SELECT gc.*, u.name as student_name, ct.name as template_name, ib.name as issued_by_name
                  FROM generated_certificates gc
                  LEFT JOIN users u ON gc.student_id = u.id
                  LEFT JOIN certificate_templates ct ON gc.template_id = ct.id
                  LEFT JOIN users ib ON gc.issued_by = ib.id
                  WHERE gc.issued_by = :user_id
                  ORDER BY gc.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
    } else {
        // Students/Parents see their own certificates
        $query = "SELECT gc.*, u.name as student_name, ct.name as template_name, ib.name as issued_by_name
                  FROM generated_certificates gc
                  LEFT JOIN users u ON gc.student_id = u.id
                  LEFT JOIN certificate_templates ct ON gc.template_id = ct.id
                  LEFT JOIN users ib ON gc.issued_by = ib.id
                  WHERE gc.student_id = :user_id
                  ORDER BY gc.created_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
    }
    
    $stmt->execute();
    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $certificates = [];
}

// Get certificate templates for admins/teachers
$templates = [];
if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    try {
        $query = "SELECT * FROM certificate_templates WHERE is_active = 1 ORDER BY name";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $templates = [];
    }
}

$title = "Certificates & IDs";
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
                                <h1 class="text-3xl font-bold mb-2">Certificates & IDs</h1>
                                <p class="text-blue-100 text-lg">Generate and manage certificates and ID cards</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-certificate mr-2"></i>
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
                                    <i class="fas fa-certificate text-6xl text-white/80"></i>
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
                        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <button onclick="showGenerateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i>Generate Certificate
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Certificate Types -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-graduation-cap text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Academic</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Graduation, completion, and academic achievement certificates</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-trophy text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Achievement</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Awards, honors, and special recognition certificates</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-users text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Participation</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Event participation and activity completion certificates</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center">
                        <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-id-card text-orange-600 dark:text-orange-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">ID Cards</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Student and staff identification cards with QR codes</p>
                    </div>
                </div>

                <!-- Generated Certificates -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                            <?php if ($role === 'teacher'): ?>
                            Certificates I've Issued
                            <?php elseif (in_array($role, ['student', 'parent'])): ?>
                            My Certificates
                            <?php else: ?>
                            All Generated Certificates
                            <?php endif; ?>
                        </h2>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($certificates)): ?>
                        <div class="space-y-4">
                            <?php foreach ($certificates as $cert): ?>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-6 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($cert['title']); ?>
                                            </h3>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs 
                                                <?php 
                                                switch($cert['status']) {
                                                    case 'issued': echo 'bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200'; break;
                                                    case 'draft': echo 'bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200'; break;
                                                    case 'revoked': echo 'bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200'; break;
                                                }
                                                ?>">
                                                <?php echo ucfirst($cert['status']); ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($cert['description']): ?>
                                        <p class="text-gray-600 dark:text-gray-400 mb-3"><?php echo htmlspecialchars($cert['description']); ?></p>
                                        <?php endif; ?>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 text-sm">
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Student:</span>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($cert['student_name']); ?></p>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Certificate Number:</span>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($cert['certificate_number']); ?></p>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Issue Date:</span>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($cert['issue_date'])); ?></p>
                                            </div>
                                            <div>
                                                <span class="text-gray-500 dark:text-gray-400">Issued By:</span>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($cert['issued_by_name']); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="flex flex-col space-y-2 ml-6">
                                        <?php if ($cert['status'] === 'issued'): ?>
                                        <button onclick="downloadCertificate('<?php echo $cert['id']; ?>')" 
                                                class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                            <i class="fas fa-download mr-2"></i>Download
                                        </button>
                                        <button onclick="verifyCertificate('<?php echo $cert['verification_code']; ?>')" 
                                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200">
                                            <i class="fas fa-check-circle mr-2"></i>Verify
                                        </button>
                                        <?php endif; ?>
                                        
                                        <?php if (in_array($role, ['super_admin', 'school_admin']) || ($role === 'teacher' && $cert['issued_by'] == $user_id)): ?>
                                        <button onclick="editCertificate(<?php echo $cert['id']; ?>)" 
                                                class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors duration-200">
                                            <i class="fas fa-edit mr-2"></i>Edit
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-12">
                            <i class="fas fa-certificate text-gray-400 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Certificates Found</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-6">
                                <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                Generate your first certificate to get started.
                                <?php else: ?>
                                No certificates have been issued yet.
                                <?php endif; ?>
                            </p>
                            <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                            <button onclick="showGenerateModal()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i>Generate Certificate
                            </button>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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

<!-- Generate Certificate Modal -->
<div id="generateModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Generate Certificate</h3>
                <button onclick="hideGenerateModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <form class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Certificate Template</label>
                    <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="">Select Template</option>
                        <?php foreach ($templates as $template): ?>
                        <option value="<?php echo $template['id']; ?>"><?php echo htmlspecialchars($template['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student</label>
                    <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="">Select Student</option>
                        <!-- Students will be loaded dynamically -->
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Certificate Title</label>
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter certificate title">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter certificate description"></textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Issue Date</label>
                    <input type="date" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="hideGenerateModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-certificate mr-2"></i>Generate Certificate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Verification Modal -->
<div id="verificationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Certificate Verification</h3>
                <button onclick="hideVerificationModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6 text-center">
            <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-2xl"></i>
            </div>
            <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Certificate Verified</h4>
            <p class="text-gray-600 dark:text-gray-400 mb-4">This certificate is authentic and has been verified.</p>
            <div id="verificationDetails" class="text-left bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-4">
                <!-- Verification details will be loaded here -->
            </div>
            <button onclick="hideVerificationModal()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function showGenerateModal() {
    document.getElementById('generateModal').classList.remove('hidden');
}

function hideGenerateModal() {
    document.getElementById('generateModal').classList.add('hidden');
}

function showVerificationModal() {
    document.getElementById('verificationModal').classList.remove('hidden');
}

function hideVerificationModal() {
    document.getElementById('verificationModal').classList.add('hidden');
}

function downloadCertificate(certificateId) {
    // Implement download functionality
    window.open(`download_certificate.php?id=${certificateId}`, '_blank');
}

function verifyCertificate(verificationCode) {
    // Show verification modal with details
    showVerificationModal();
    // In a real implementation, you would fetch verification details from the server
}

function editCertificate(certificateId) {
    // Implement edit functionality
    alert('Edit functionality will be implemented in the next update.');
}

// Close modals when clicking outside
document.getElementById('generateModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideGenerateModal();
    }
});

document.getElementById('verificationModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideVerificationModal();
    }
});
</script>
