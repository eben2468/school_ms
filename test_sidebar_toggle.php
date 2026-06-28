<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
require_once 'includes/settings_helper.php';

// Simple test page to verify sidebar toggle functionality
$title = "Sidebar Toggle Test";
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1" style="margin-top: 80px;">
            <div class="w-full">
                <!-- Page Header -->
                <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold">Sidebar Toggle Test</h1>
                            <p class="text-white/80 mt-1">Test the dynamic sidebar toggle functionality</p>
                        </div>
                        <div class="text-right">
                            <p class="text-sm text-white/70">Test Page</p>
                        </div>
                    </div>
                </div>

                <!-- Test Content -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Instructions -->
                    <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">How to Test</h3>
                        <div class="space-y-3 text-gray-600">
                            <p>1. Click the hamburger menu (☰) button in the header to toggle the sidebar</p>
                            <p>2. Watch how the main content area dynamically adjusts its width</p>
                            <p>3. The sidebar should collapse to show only icons (64px width)</p>
                            <p>4. The main content should expand to fill the available space</p>
                            <p>5. Transitions should be smooth (300ms duration)</p>
                        </div>
                    </div>

                    <!-- Current State Display -->
                    <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Current State</h3>
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-gray-600">Sidebar State:</span>
                                <span class="font-medium" x-data x-text="$store.sidebar?.collapsed ? 'Collapsed' : 'Expanded'">Loading...</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-600">Sidebar Width:</span>
                                <span class="font-medium" x-data x-text="$store.sidebar?.collapsed ? '64px (w-16)' : '288px (w-72)'">Loading...</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-gray-600">Screen Width:</span>
                                <span class="font-medium" id="screen-width">Loading...</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Visual Indicator -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 mb-8">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Visual Layout Test</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="bg-blue-100 p-4 rounded-lg text-center">
                            <h4 class="font-medium text-blue-900">Left Column</h4>
                            <p class="text-blue-600 text-sm">Should adjust width smoothly</p>
                        </div>
                        <div class="bg-green-100 p-4 rounded-lg text-center">
                            <h4 class="font-medium text-green-900">Center Column</h4>
                            <p class="text-green-600 text-sm">Content should reflow</p>
                        </div>
                        <div class="bg-purple-100 p-4 rounded-lg text-center">
                            <h4 class="font-medium text-purple-900">Right Column</h4>
                            <p class="text-purple-600 text-sm">Layout should be responsive</p>
                        </div>
                    </div>
                </div>

                <!-- Test Results -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Expected Behavior</h3>
                    <div class="space-y-4">
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-green-100 rounded-full flex items-center justify-center mt-0.5">
                                <i class="fas fa-check text-green-600 text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Desktop (≥1024px)</p>
                                <p class="text-gray-600 text-sm">Sidebar toggles between 288px and 64px width. Main content adjusts accordingly.</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-blue-100 rounded-full flex items-center justify-center mt-0.5">
                                <i class="fas fa-mobile-alt text-blue-600 text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Mobile (<1024px)</p>
                                <p class="text-gray-600 text-sm">Sidebar slides in/out from left. Main content uses full width.</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-3">
                            <div class="w-6 h-6 bg-purple-100 rounded-full flex items-center justify-center mt-0.5">
                                <i class="fas fa-magic text-purple-600 text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium text-gray-900">Smooth Transitions</p>
                                <p class="text-gray-600 text-sm">All layout changes should have smooth 300ms transitions.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Update screen width display
function updateScreenWidth() {
    const screenWidthElement = document.getElementById('screen-width');
    if (screenWidthElement) {
        screenWidthElement.textContent = window.innerWidth + 'px';
    }
}

// Update on load and resize
updateScreenWidth();
window.addEventListener('resize', updateScreenWidth);

// Log sidebar state changes for debugging
document.addEventListener('alpine:initialized', function() {
    if (window.Alpine && window.Alpine.store('sidebar')) {
        console.log('Sidebar store initialized:', window.Alpine.store('sidebar'));
        
        // Watch for sidebar state changes
        const originalToggle = window.Alpine.store('sidebar').toggle;
        window.Alpine.store('sidebar').toggle = function() {
            console.log('Sidebar toggle called. Current state:', this.collapsed);
            originalToggle.call(this);
            console.log('New sidebar state:', this.collapsed);
        };
    }
});
</script>

<?php include 'includes/footer.php'; ?>
