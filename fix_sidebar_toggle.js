// Emergency Sidebar Toggle Fix
// This script provides a backup toggle mechanism

(function() {
    'use strict';
    
    console.log('Emergency sidebar toggle fix loaded');
    
    // Wait for DOM to be ready
    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }
    
    ready(function() {
        console.log('DOM ready, setting up emergency toggle');
        
        // Find the toggle button
        const toggleButton = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        
        if (!toggleButton) {
            console.error('Toggle button not found');
            return;
        }
        
        if (!sidebar) {
            console.error('Sidebar not found');
            return;
        }
        
        console.log('Toggle button and sidebar found');
        
        // Get current state from localStorage
        let isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        console.log('Initial collapsed state from localStorage:', isCollapsed);
        
        // Function to update sidebar state
        function updateSidebarState(collapsed) {
            console.log('Updating sidebar state to:', collapsed);
            
            // Update localStorage
            localStorage.setItem('sidebarCollapsed', collapsed);
            
            // Update Alpine store if available
            if (window.Alpine && window.Alpine.store('sidebar')) {
                window.Alpine.store('sidebar').collapsed = collapsed;
                console.log('Updated Alpine store');
            }
            
            // Update sidebar classes
            if (collapsed) {
                sidebar.classList.remove('w-72');
                sidebar.classList.add('w-16');
            } else {
                sidebar.classList.remove('w-16');
                sidebar.classList.add('w-72');
            }
            
            // Update sidebar space div
            const sidebarSpace = document.getElementById('sidebar-space') || document.querySelector('.transition-all.duration-300.lg\\:block.hidden, .w-72.flex-shrink-0, .w-16.flex-shrink-0, .w-0.transition-all');
            if (sidebarSpace) {
                if (collapsed) {
                    sidebarSpace.className = 'w-16 transition-all duration-300 lg:block hidden';
                } else {
                    sidebarSpace.className = 'w-72 transition-all duration-300 lg:block hidden';
                }
                console.log('Updated sidebar space div');
            }
            
            // Update main content if needed
            const mainContent = document.querySelector('main');
            if (mainContent && !sidebarSpace) {
                if (collapsed) {
                    // Sidebar is collapsed - account for icon space (64px)
                    mainContent.style.marginLeft = '64px';
                    mainContent.style.width = 'calc(100% - 64px)';
                } else {
                    // Sidebar is expanded (72 = w-72 = 18rem = 288px)
                    mainContent.style.marginLeft = '288px';
                    mainContent.style.width = 'calc(100% - 288px)';
                }
                mainContent.style.transition = 'margin-left 0.3s ease-in-out, width 0.3s ease-in-out';
                console.log('Updated main content layout');
            }
            
            console.log('Sidebar state updated successfully');
        }
        
        // Set initial state
        updateSidebarState(isCollapsed);
        
        // Add click handler
        toggleButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Emergency toggle clicked');
            
            // Toggle state
            isCollapsed = !isCollapsed;
            updateSidebarState(isCollapsed);
        });
        
        console.log('Emergency toggle handler attached');
        
        // Add keyboard shortcut (Ctrl+B)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                isCollapsed = !isCollapsed;
                updateSidebarState(isCollapsed);
                console.log('Keyboard shortcut triggered');
            }
        });
        
        // Add double-click on sidebar to toggle
        sidebar.addEventListener('dblclick', function() {
            isCollapsed = !isCollapsed;
            updateSidebarState(isCollapsed);
            console.log('Double-click toggle triggered');
        });
        
        console.log('Emergency sidebar toggle setup complete');
    });
})();
