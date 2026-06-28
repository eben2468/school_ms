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
        
        // Find elements
        const toggleButton = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (!toggleButton) {
            console.error('Toggle button not found');
            return;
        }
        
        if (!sidebar) {
            console.error('Sidebar not found');
            return;
        }
        
        console.log('Toggle button, sidebar and overlay elements found');
        
        // Get current state from localStorage (always expanded on desktop by default)
        let isCollapsed = window.innerWidth < 1024 ? false : localStorage.getItem('sidebarCollapsed') === 'true';
        console.log('Initial collapsed state from localStorage:', isCollapsed);
        
        // Function to update sidebar state
        function updateSidebarState(collapsed) {
            if (window.innerWidth < 1024) {
                collapsed = false;
            }
            console.log('Updating sidebar state to:', collapsed);
            
            // Update localStorage
            if (window.innerWidth >= 1024) {
                localStorage.setItem('sidebarCollapsed', collapsed);
            }
            
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
            const sidebarSpace = document.getElementById('sidebar-space') || document.querySelector('.sidebar-spacer') || document.querySelector('.transition-all.duration-300.lg\\:block.hidden, .w-72.flex-shrink-0, .w-16.flex-shrink-0, .w-0.transition-all');
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
        
        // Function to close sidebar on mobile
        function closeMobileSidebar() {
            sidebar.classList.add('-translate-x-full');
            sidebar.classList.remove('sidebar-open');
            if (overlay) overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Function to open sidebar on mobile
        function openMobileSidebar() {
            sidebar.classList.remove('-translate-x-full');
            sidebar.classList.add('sidebar-open');
            if (overlay) overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        
        // Set initial state
        updateSidebarState(isCollapsed);
        
        // Add click handler for toggle button
        toggleButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Sidebar toggle clicked');
            
            if (window.innerWidth < 1024) {
                // Mobile behavior: toggle classes to slide sidebar in/out and show overlay
                if (sidebar.classList.contains('sidebar-open')) {
                    closeMobileSidebar();
                } else {
                    openMobileSidebar();
                }
            } else {
                // Desktop behavior: toggle collapsed state
                isCollapsed = !isCollapsed;
                updateSidebarState(isCollapsed);
            }
        });
        
        // Add click handler for overlay (clicking outside sidebar on mobile closes it)
        if (overlay) {
            overlay.addEventListener('click', function(e) {
                console.log('Overlay clicked, closing sidebar');
                closeMobileSidebar();
            });
        }
        
        // Close sidebar on window resize between desktop and mobile breakpoints
        window.addEventListener('resize', function() {
            if (window.innerWidth < 1024) {
                // Resize to mobile: always ensure sidebar starts closed and overlays cleanly
                closeMobileSidebar();
            } else {
                // Resize to desktop: restore sidebar visibility and apply collapsed state
                sidebar.classList.remove('-translate-x-full');
                sidebar.classList.remove('sidebar-open');
                if (overlay) overlay.classList.remove('active');
                document.body.style.overflow = '';
                
                isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                updateSidebarState(isCollapsed);
            }
        });
        
        // Add keyboard shortcut (Ctrl+B)
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                if (window.innerWidth >= 1024) {
                    isCollapsed = !isCollapsed;
                    updateSidebarState(isCollapsed);
                    console.log('Keyboard shortcut triggered (desktop)');
                } else {
                    // Mobile: toggle open/close
                    if (sidebar.classList.contains('sidebar-open')) {
                        closeMobileSidebar();
                    } else {
                        openMobileSidebar();
                    }
                    console.log('Keyboard shortcut triggered (mobile)');
                }
            }
        });
        
        // Add double-click on sidebar to toggle
        sidebar.addEventListener('dblclick', function(e) {
            // Only trigger on desktop to prevent accidental mobile zoom/closing issues
            if (window.innerWidth >= 1024 && !e.target.closest('input, button, a')) {
                isCollapsed = !isCollapsed;
                updateSidebarState(isCollapsed);
                console.log('Double-click toggle triggered');
            }
        });
        
        console.log('Sidebar toggle setup complete');
    });
})();
