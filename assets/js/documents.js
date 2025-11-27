// Document & File Management JavaScript
console.log('Documents page loaded successfully');
console.log('Title should be: Document & File Management');
console.log('Current page title:', document.title);
console.log('Current URL:', window.location.href);

// Modal functions
function showCategoriesModal() {
    const modal = document.getElementById('categoriesModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
}

function hideCategoriesModal() {
    const modal = document.getElementById('categoriesModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}

function showSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        // Focus on search input
        const searchInput = modal.querySelector('input[type="text"]');
        if (searchInput) {
            setTimeout(() => searchInput.focus(), 100);
        }
    }
}

function hideSearchModal() {
    const modal = document.getElementById('searchModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
}

// Document operations
function downloadDocument(documentId) {
    if (!documentId) {
        showNotification('Error: Document ID not found', 'error');
        return;
    }

    // Create a temporary link to trigger download
    const link = document.createElement('a');
    link.href = `download.php?id=${documentId}`;
    link.download = '';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    showNotification('Download started', 'success');
}

function shareDocument(documentId) {
    if (!documentId) {
        showNotification('Error: Document ID not found', 'error');
        return;
    }

    // Show share modal or redirect to share page
    window.location.href = `share.php?id=${documentId}`;
}

// Notification system
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notification => notification.remove());

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;

    // Set colors based on type
    switch(type) {
        case 'success':
            notification.classList.add('bg-green-500', 'text-white');
            break;
        case 'error':
            notification.classList.add('bg-red-500', 'text-white');
            break;
        case 'warning':
            notification.classList.add('bg-yellow-500', 'text-white');
            break;
        default:
            notification.classList.add('bg-blue-500', 'text-white');
    }

    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info'} mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);

    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, 5000);
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Documents DOM loaded');

    // Force correct page title if it's showing "0" - with multiple checks
    function fixPageTitle() {
        if (document.title === '0' || document.title === '0 - School Management System' || document.title.startsWith('0') || document.title.trim() === '') {
            document.title = 'Document & File Management - School Management System';
            console.log('Fixed page title to:', document.title);
        }
    }

    // Fix title immediately
    fixPageTitle();

    // Fix title again after a short delay to catch any late changes
    setTimeout(fixPageTitle, 100);
    setTimeout(fixPageTitle, 500);
    setTimeout(fixPageTitle, 1000);

    // Monitor title changes and fix if needed
    const titleObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList' && mutation.target === document.querySelector('title')) {
                fixPageTitle();
            }
        });
    });

    const titleElement = document.querySelector('title');
    if (titleElement) {
        titleObserver.observe(titleElement, { childList: true, characterData: true, subtree: true });
    }

    // No need to force header styling - using standard system header now
    
    // Handle button clicks with data attributes
    document.addEventListener('click', function(e) {
        const action = e.target.getAttribute('data-action') || e.target.closest('[data-action]')?.getAttribute('data-action');

        switch(action) {
            case 'show-categories-modal':
                showCategoriesModal();
                break;
            case 'hide-categories-modal':
                hideCategoriesModal();
                break;
            case 'show-search-modal':
                showSearchModal();
                break;
            case 'hide-search-modal':
                hideSearchModal();
                break;
        }

        // Handle download buttons
        if (e.target.closest('.download-btn')) {
            e.preventDefault();
            const documentId = e.target.closest('.download-btn').getAttribute('data-document-id');
            downloadDocument(documentId);
        }

        // Handle share buttons
        if (e.target.closest('.share-btn')) {
            e.preventDefault();
            const documentId = e.target.closest('.share-btn').getAttribute('data-document-id');
            shareDocument(documentId);
        }
    });

    // Close modals when clicking outside
    const categoriesModal = document.getElementById('categoriesModal');
    if (categoriesModal) {
        categoriesModal.addEventListener('click', function(e) {
            if (e.target === this) {
                hideCategoriesModal();
            }
        });
    }

    const searchModal = document.getElementById('searchModal');
    if (searchModal) {
        searchModal.addEventListener('click', function(e) {
            if (e.target === this) {
                hideSearchModal();
            }
        });
    }

    // Animate statistics on page load
    const statNumbers = document.querySelectorAll('.text-3xl.font-bold');
    statNumbers.forEach(stat => {
        const finalValue = parseInt(stat.textContent) || 0;
        if (isNaN(finalValue)) return;

        let currentValue = 0;
        const increment = Math.ceil(finalValue / 20);
        const timer = setInterval(() => {
            currentValue += increment;
            if (currentValue >= finalValue) {
                currentValue = finalValue;
                clearInterval(timer);
            }
            stat.textContent = currentValue;
        }, 50);
    });

    // Initialize drag and drop functionality
    initializeDragAndDrop();

    // Handle search form submission
    const searchForm = document.querySelector('#searchModal form');
    if (searchForm) {
        searchForm.addEventListener('submit', handleSearch);
    }
});

// File drag and drop functionality
function initializeDragAndDrop() {
    const dropZones = document.querySelectorAll('.drop-zone');

    dropZones.forEach(dropZone => {
        dropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('drag-over');
        });

        dropZone.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');
        });

        dropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileUpload(files);
            }
        });
    });
}

function handleFileUpload(files) {
    const formData = new FormData();

    for (let i = 0; i < files.length; i++) {
        formData.append('files[]', files[i]);
    }

    showNotification('Uploading files...', 'info');

    fetch('upload_handler.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification(`${files.length} file(s) uploaded successfully!`, 'success');
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        } else {
            showNotification('Upload failed: ' + (data.message || 'Unknown error'), 'error');
        }
    })
    .catch(error => {
        console.error('Upload error:', error);
        showNotification('Upload failed: Network error', 'error');
    });
}

// Search handling
function handleSearch(e) {
    e.preventDefault();

    const formData = new FormData(e.target);
    const searchParams = new URLSearchParams();

    for (let [key, value] of formData.entries()) {
        if (value.trim()) {
            searchParams.append(key, value);
        }
    }

    // Redirect to search results page
    window.location.href = `search.php?${searchParams.toString()}`;
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Escape key closes modals
    if (e.key === 'Escape') {
        const visibleModals = document.querySelectorAll('.fixed.inset-0:not(.hidden)');
        visibleModals.forEach(modal => {
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        });
    }

    // Ctrl+F opens search modal
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        showSearchModal();
    }
});
