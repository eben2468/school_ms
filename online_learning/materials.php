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

$title = "Learning Materials";
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

                <!-- Material Types -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-file-pdf text-red-600 dark:text-red-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Documents</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">PDF files, presentations, and text documents</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-video text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Videos</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Educational videos and recorded lectures</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-volume-up text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Audio</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">Audio lectures and educational podcasts</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-presentation text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Presentations</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">PowerPoint and interactive presentations</p>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                        <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-link text-orange-600 dark:text-orange-400 text-xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Links</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">External resources and educational websites</p>
                    </div>
                </div>

                <!-- Search and Filter -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <input type="text" placeholder="Search materials..." class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Types</option>
                                    <option value="document">Documents</option>
                                    <option value="video">Videos</option>
                                    <option value="audio">Audio</option>
                                    <option value="presentation">Presentations</option>
                                    <option value="link">Links</option>
                                </select>
                            </div>
                            <div>
                                <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Classes</option>
                                    <option value="class1">Class 1</option>
                                    <option value="class2">Class 2</option>
                                </select>
                            </div>
                            <div>
                                <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Subjects</option>
                                    <option value="math">Mathematics</option>
                                    <option value="science">Science</option>
                                    <option value="english">English</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Materials Grid -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Available Materials</h2>
                            <div class="flex space-x-2">
                                <button class="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" title="Grid View">
                                    <i class="fas fa-th-large"></i>
                                </button>
                                <button class="p-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200" title="List View">
                                    <i class="fas fa-list"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        <!-- Sample Materials -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <!-- Sample Material 1 -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-file-pdf text-red-600 dark:text-red-400"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900 dark:text-white">Introduction to Algebra</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Mathematics • PDF • 2.5 MB</p>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Basic concepts and fundamentals of algebraic expressions and equations.</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Uploaded 2 days ago</span>
                                    <div class="flex space-x-2">
                                        <button class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-eye mr-1"></i>View
                                        </button>
                                        <button class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm">
                                            <i class="fas fa-download mr-1"></i>Download
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Sample Material 2 -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-video text-blue-600 dark:text-blue-400"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900 dark:text-white">Cell Biology Lecture</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Biology • Video • 45 min</p>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Comprehensive overview of cell structure and cellular processes.</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Uploaded 1 week ago</span>
                                    <div class="flex space-x-2">
                                        <button class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-play mr-1"></i>Play
                                        </button>
                                        <button class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm">
                                            <i class="fas fa-download mr-1"></i>Download
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Sample Material 3 -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-center space-x-3 mb-3">
                                    <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-presentation text-purple-600 dark:text-purple-400"></i>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-900 dark:text-white">World War II Overview</h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">History • Presentation • 15 slides</p>
                                    </div>
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Interactive presentation covering major events and impacts of WWII.</p>
                                <div class="flex items-center justify-between">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Uploaded 3 days ago</span>
                                    <div class="flex space-x-2">
                                        <button class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-eye mr-1"></i>View
                                        </button>
                                        <button class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm">
                                            <i class="fas fa-download mr-1"></i>Download
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Add more sample materials as needed -->
                        </div>

                        <!-- Empty State (when no materials) -->
                        <div class="text-center py-12 hidden">
                            <i class="fas fa-folder-open text-gray-400 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Materials Found</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-6">
                                <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                                Upload your first learning material to get started.
                                <?php else: ?>
                                No learning materials are currently available.
                                <?php endif; ?>
                            </p>
                            <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                            <button onclick="showUploadModal()" class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i>Upload Material
                            </button>
                            <?php endif; ?>
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

<!-- Upload Material Modal -->
<div id="uploadModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Upload Learning Material</h3>
                <button onclick="hideUploadModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <form class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Material Type</label>
                    <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="document">Document</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="presentation">Presentation</option>
                        <option value="link">External Link</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Title</label>
                    <input type="text" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter material title">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter material description"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                        <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Class</option>
                            <option value="class1">Class 1</option>
                            <option value="class2">Class 2</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                        <select class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Subject</option>
                            <option value="math">Mathematics</option>
                            <option value="science">Science</option>
                            <option value="english">English</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">File Upload</label>
                    <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg p-6 text-center">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-4"></i>
                        <p class="text-gray-600 dark:text-gray-400 mb-2">Click to upload or drag and drop</p>
                        <p class="text-sm text-gray-500 dark:text-gray-500">PDF, DOC, PPT, MP4, MP3 (Max 100MB)</p>
                        <input type="file" class="hidden" accept=".pdf,.doc,.docx,.ppt,.pptx,.mp4,.mp3,.jpg,.jpeg,.png">
                        <button type="button" class="mt-4 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            Choose File
                        </button>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 pt-4">
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

<script>
function showUploadModal() {
    document.getElementById('uploadModal').classList.remove('hidden');
}

function hideUploadModal() {
    document.getElementById('uploadModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('uploadModal').addEventListener('click', function(e) {
    if (e.target === this) {
        hideUploadModal();
    }
});
</script>
