// School Management System - Enhanced JavaScript Functionality

// Global App Object
window.SchoolMS = {
    // Configuration
    config: {
        apiUrl: '/api/',
        notificationDuration: 5000,
        searchDelay: 300
    },

    // Initialize the application
    init() {
        this.initTheme();
        this.initNotifications();
        this.initSearch();
        this.initKeyboardShortcuts();
        this.initTooltips();
        this.initFormValidation();
        this.initDataTables();
        console.log('School Management System initialized');
    },

    // Theme Management
    initTheme() {
        const savedTheme = localStorage.getItem('darkMode');
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        const isDark = savedTheme === 'true' || (savedTheme === null && prefersDark);
        
        if (isDark) {
            document.documentElement.classList.add('dark');
        }

        // Listen for system theme changes
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (localStorage.getItem('darkMode') === null) {
                document.documentElement.classList.toggle('dark', e.matches);
            }
        });
    },

    toggleTheme() {
        const isDark = document.documentElement.classList.contains('dark');
        document.documentElement.classList.toggle('dark', !isDark);
        localStorage.setItem('darkMode', !isDark);
        
        // Trigger custom event
        window.dispatchEvent(new CustomEvent('themeChanged', { 
            detail: { isDark: !isDark } 
        }));
    },

    // Notification System
    initNotifications() {
        this.notificationContainer = this.createNotificationContainer();
    },

    createNotificationContainer() {
        let container = document.getElementById('notification-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'notification-container';
            container.className = 'fixed top-4 right-4 z-50 space-y-2';
            document.body.appendChild(container);
        }
        return container;
    },

    showNotification(message, type = 'info', duration = null) {
        const notification = document.createElement('div');
        const isDark = document.documentElement.classList.contains('dark');
        
        notification.className = `notification ${type} transform transition-all duration-300 ease-in-out`;
        
        const icons = {
            success: 'fas fa-check-circle',
            error: 'fas fa-exclamation-circle',
            warning: 'fas fa-exclamation-triangle',
            info: 'fas fa-info-circle'
        };

        notification.innerHTML = `
            <div class="flex items-start space-x-3">
                <i class="${icons[type]} text-lg"></i>
                <div class="flex-1">
                    <p class="text-sm font-medium">${message}</p>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;

        this.notificationContainer.appendChild(notification);

        // Auto remove after duration
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.transform = 'translateX(100%)';
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 300);
            }
        }, duration || this.config.notificationDuration);

        return notification;
    },

    // Search Functionality
    initSearch() {
        this.searchCache = new Map();
        this.searchTimeout = null;
    },

    performSearch(query, callback) {
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        this.searchTimeout = setTimeout(() => {
            if (this.searchCache.has(query)) {
                callback(this.searchCache.get(query));
                return;
            }

            fetch(`${this.config.apiUrl}search.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ query })
            })
            .then(response => response.json())
            .then(data => {
                this.searchCache.set(query, data);
                callback(data);
            })
            .catch(error => {
                console.error('Search error:', error);
                callback([]);
            });
        }, this.config.searchDelay);
    },

    // Keyboard Shortcuts
    initKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + K for search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                this.openSearch();
            }

            // Ctrl/Cmd + D for dark mode toggle
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                this.toggleTheme();
            }

            // ESC to close modals/overlays
            if (e.key === 'Escape') {
                this.closeModals();
            }
        });
    },

    openSearch() {
        const searchModal = document.querySelector('[x-data*="searchOpen"]');
        if (searchModal) {
            // Trigger Alpine.js to open search
            searchModal.__x.$data.searchOpen = true;
        }
    },

    closeModals() {
        // Close search modal
        const searchModal = document.querySelector('[x-data*="searchOpen"]');
        if (searchModal && searchModal.__x.$data.searchOpen) {
            searchModal.__x.$data.searchOpen = false;
        }

        // Close other modals
        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
        });
    },

    // Tooltip System
    initTooltips() {
        document.addEventListener('mouseenter', (e) => {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-tooltip')) {
                this.showTooltip(e.target, e.target.getAttribute('data-tooltip'));
            }
        }, true);

        document.addEventListener('mouseleave', (e) => {
            if (e.target && e.target.hasAttribute && e.target.hasAttribute('data-tooltip')) {
                this.hideTooltip();
            }
        }, true);
    },

    showTooltip(element, text) {
        this.hideTooltip(); // Remove any existing tooltip

        const tooltip = document.createElement('div');
        tooltip.id = 'tooltip';
        tooltip.className = 'absolute z-50 px-2 py-1 text-sm bg-gray-900 text-white rounded shadow-lg pointer-events-none';
        tooltip.textContent = text;

        document.body.appendChild(tooltip);

        const rect = element.getBoundingClientRect();
        tooltip.style.left = rect.left + (rect.width / 2) - (tooltip.offsetWidth / 2) + 'px';
        tooltip.style.top = rect.top - tooltip.offsetHeight - 5 + 'px';
    },

    hideTooltip() {
        const tooltip = document.getElementById('tooltip');
        if (tooltip) {
            tooltip.remove();
        }
    },

    // Form Validation
    initFormValidation() {
        document.addEventListener('submit', (e) => {
            const form = e.target;
            if (form.hasAttribute('data-validate')) {
                if (!this.validateForm(form)) {
                    e.preventDefault();
                }
            }
        });
    },

    validateForm(form) {
        let isValid = true;
        const inputs = form.querySelectorAll('input[required], select[required], textarea[required]');

        inputs.forEach(input => {
            if (!input.value.trim()) {
                this.showFieldError(input, 'This field is required');
                isValid = false;
            } else {
                this.clearFieldError(input);
            }
        });

        return isValid;
    },

    showFieldError(input, message) {
        this.clearFieldError(input);
        
        input.classList.add('border-red-500');
        const error = document.createElement('div');
        error.className = 'field-error text-red-500 text-sm mt-1';
        error.textContent = message;
        input.parentElement.appendChild(error);
    },

    clearFieldError(input) {
        input.classList.remove('border-red-500');
        const error = input.parentElement.querySelector('.field-error');
        if (error) {
            error.remove();
        }
    },

    // Data Tables Enhancement
    initDataTables() {
        document.querySelectorAll('.data-table').forEach(table => {
            this.enhanceTable(table);
        });
    },

    enhanceTable(table) {
        // Add sorting functionality
        const headers = table.querySelectorAll('th[data-sort]');
        headers.forEach(header => {
            header.style.cursor = 'pointer';
            header.addEventListener('click', () => {
                this.sortTable(table, header.getAttribute('data-sort'));
            });
        });
    },

    sortTable(table, column) {
        // Basic table sorting implementation
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr'));
        
        rows.sort((a, b) => {
            const aVal = a.querySelector(`td[data-${column}]`)?.textContent || '';
            const bVal = b.querySelector(`td[data-${column}]`)?.textContent || '';
            return aVal.localeCompare(bVal);
        });

        rows.forEach(row => tbody.appendChild(row));
    },

    // Utility Functions
    formatDate(date) {
        return new Intl.DateTimeFormat('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        }).format(new Date(date));
    },

    formatCurrency(amount) {
        return '₵' + new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }).format(amount);
    },

    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.SchoolMS.init();
});

// Export for module usage
if (typeof module !== 'undefined' && module.exports) {
    module.exports = window.SchoolMS;
}
