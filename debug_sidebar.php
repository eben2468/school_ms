<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
require_once 'includes/settings_helper.php';

$title = "Sidebar Debug";
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1" style="margin-top: 80px;">
            <div class="w-full">
                <!-- Debug Panel -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 mb-6">
                    <h2 class="text-xl font-bold text-gray-900 mb-4">Sidebar Debug Panel</h2>
                    
                    <!-- Manual Toggle Button -->
                    <div class="mb-6">
                        <button id="manual-toggle" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors">
                            Manual Toggle Test
                        </button>
                        <button id="force-expand" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition-colors ml-2">
                            Force Expand
                        </button>
                        <button id="force-collapse" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition-colors ml-2">
                            Force Collapse
                        </button>
                        <button id="clear-storage" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors ml-2">
                            Clear Storage
                        </button>
                    </div>

                    <!-- Status Display -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h3 class="font-semibold text-gray-900 mb-2">Current State</h3>
                            <div class="space-y-2 text-sm">
                                <div>Alpine Store State: <span id="alpine-state" class="font-mono">Loading...</span></div>
                                <div>LocalStorage: <span id="storage-state" class="font-mono">Loading...</span></div>
                                <div>Sidebar Element: <span id="sidebar-element" class="font-mono">Loading...</span></div>
                                <div>Toggle Button: <span id="toggle-button" class="font-mono">Loading...</span></div>
                                <div>Screen Width: <span id="screen-width" class="font-mono">Loading...</span></div>
                            </div>
                        </div>
                        <div>
                            <h3 class="font-semibold text-gray-900 mb-2">CSS Classes</h3>
                            <div class="space-y-2 text-sm">
                                <div>Sidebar Classes: <span id="sidebar-classes" class="font-mono text-xs">Loading...</span></div>
                                <div>Sidebar Space Classes: <span id="space-classes" class="font-mono text-xs">Loading...</span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Console Log -->
                    <div class="mt-6">
                        <h3 class="font-semibold text-gray-900 mb-2">Debug Log</h3>
                        <div id="debug-log" class="bg-gray-100 p-3 rounded text-xs font-mono h-32 overflow-y-auto"></div>
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
let debugLog = [];

function log(message) {
    const timestamp = new Date().toLocaleTimeString();
    debugLog.push(`[${timestamp}] ${message}`);
    updateDebugDisplay();
}

function updateDebugDisplay() {
    const logElement = document.getElementById('debug-log');
    if (logElement) {
        logElement.innerHTML = debugLog.slice(-20).join('\n');
        logElement.scrollTop = logElement.scrollHeight;
    }
}

function updateStatus() {
    // Alpine Store State
    const alpineStateEl = document.getElementById('alpine-state');
    if (alpineStateEl) {
        if (window.Alpine && window.Alpine.store('sidebar')) {
            alpineStateEl.textContent = window.Alpine.store('sidebar').collapsed ? 'Collapsed' : 'Expanded';
            alpineStateEl.className = 'font-mono ' + (window.Alpine.store('sidebar').collapsed ? 'text-red-600' : 'text-green-600');
        } else {
            alpineStateEl.textContent = 'Not Available';
            alpineStateEl.className = 'font-mono text-red-600';
        }
    }

    // LocalStorage
    const storageStateEl = document.getElementById('storage-state');
    if (storageStateEl) {
        const stored = localStorage.getItem('sidebarCollapsed');
        storageStateEl.textContent = stored || 'null';
        storageStateEl.className = 'font-mono ' + (stored === 'true' ? 'text-red-600' : 'text-green-600');
    }

    // Sidebar Element
    const sidebarElementEl = document.getElementById('sidebar-element');
    if (sidebarElementEl) {
        const sidebar = document.getElementById('sidebar');
        sidebarElementEl.textContent = sidebar ? 'Found' : 'Not Found';
        sidebarElementEl.className = 'font-mono ' + (sidebar ? 'text-green-600' : 'text-red-600');
    }

    // Toggle Button
    const toggleButtonEl = document.getElementById('toggle-button');
    if (toggleButtonEl) {
        const button = document.getElementById('sidebar-toggle');
        toggleButtonEl.textContent = button ? 'Found' : 'Not Found';
        toggleButtonEl.className = 'font-mono ' + (button ? 'text-green-600' : 'text-red-600');
    }

    // Screen Width
    const screenWidthEl = document.getElementById('screen-width');
    if (screenWidthEl) {
        screenWidthEl.textContent = window.innerWidth + 'px';
    }

    // Sidebar Classes
    const sidebarClassesEl = document.getElementById('sidebar-classes');
    if (sidebarClassesEl) {
        const sidebar = document.getElementById('sidebar');
        sidebarClassesEl.textContent = sidebar ? sidebar.className : 'N/A';
    }

    // Sidebar Space Classes
    const spaceClassesEl = document.getElementById('space-classes');
    if (spaceClassesEl) {
        const space = document.querySelector('.w-72.flex-shrink-0, .w-16.flex-shrink-0');
        spaceClassesEl.textContent = space ? space.className : 'N/A';
    }
}

// Initialize when Alpine is ready
document.addEventListener('alpine:initialized', function() {
    log('Alpine initialized');
    updateStatus();
    
    // Set up manual toggle
    document.getElementById('manual-toggle')?.addEventListener('click', function() {
        log('Manual toggle clicked');
        if (window.Alpine && window.Alpine.store('sidebar')) {
            window.Alpine.store('sidebar').toggle();
            log('Toggle called via Alpine store');
            setTimeout(updateStatus, 100);
        } else {
            log('Alpine store not available');
        }
    });

    // Force expand
    document.getElementById('force-expand')?.addEventListener('click', function() {
        log('Force expand clicked');
        if (window.Alpine && window.Alpine.store('sidebar')) {
            window.Alpine.store('sidebar').collapsed = false;
            localStorage.setItem('sidebarCollapsed', 'false');
            log('Forced to expanded state');
            setTimeout(updateStatus, 100);
        }
    });

    // Force collapse
    document.getElementById('force-collapse')?.addEventListener('click', function() {
        log('Force collapse clicked');
        if (window.Alpine && window.Alpine.store('sidebar')) {
            window.Alpine.store('sidebar').collapsed = true;
            localStorage.setItem('sidebarCollapsed', 'true');
            log('Forced to collapsed state');
            setTimeout(updateStatus, 100);
        }
    });

    // Clear storage
    document.getElementById('clear-storage')?.addEventListener('click', function() {
        log('Clear storage clicked');
        localStorage.removeItem('sidebarCollapsed');
        if (window.Alpine && window.Alpine.store('sidebar')) {
            window.Alpine.store('sidebar').collapsed = false;
        }
        log('Storage cleared and reset to expanded');
        setTimeout(updateStatus, 100);
    });

    // Monitor the original toggle button
    const originalToggle = document.getElementById('sidebar-toggle');
    if (originalToggle) {
        originalToggle.addEventListener('click', function() {
            log('Original toggle button clicked');
            setTimeout(updateStatus, 100);
        });
    }
});

// Update status periodically
setInterval(updateStatus, 1000);

// Initial status update
setTimeout(updateStatus, 500);

log('Debug script loaded');
</script>
