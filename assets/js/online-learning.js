// Online Learning Tools JavaScript
console.log('Online Learning page loaded successfully');
console.log('Title should be: Online Learning Tools');

// Force title to be correct
if (document.title.includes('0') || document.title === '0 - School Management System' || document.title === '0') {
    document.title = 'Online Learning Tools - School Management System';
    console.log('Fixed page title to: ' + document.title);
}

function showIntegrationModal() {
    const modal = document.getElementById('integrationModal');
    if (modal) {
        modal.classList.remove('hidden');
    }
}

function hideIntegrationModal() {
    const modal = document.getElementById('integrationModal');
    if (modal) {
        modal.classList.add('hidden');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Online Learning DOM loaded');

    // Force title to be correct (backup check)
    if (document.title.includes('0') || document.title === '0 - School Management System' || document.title === '0') {
        document.title = 'Online Learning Tools - School Management System';
        console.log('DOM Ready: Fixed page title to: ' + document.title);
    }

    // Force gradient background on header
    const header = document.querySelector('.online-learning-header');
    if (header) {
        header.style.background = 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)';
        header.style.backgroundImage = 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)';
        header.style.color = 'white';
        console.log('Forced gradient background on header');

        // Also force colors on title and subtitle
        const title = header.querySelector('.online-learning-title, h1');
        const subtitle = header.querySelector('.online-learning-subtitle, p');

        if (title) {
            title.style.color = 'white';
            title.style.fontSize = '2rem';
        }

        if (subtitle) {
            subtitle.style.color = 'rgba(219, 234, 254, 1)';
        }
    }

    // Handle button clicks with data attributes
    document.addEventListener('click', function(e) {
        const action = e.target.getAttribute('data-action');
        if (action === 'show-integration-modal') {
            showIntegrationModal();
        } else if (action === 'hide-integration-modal') {
            hideIntegrationModal();
        }
    });

    // Close modal when clicking outside
    const modal = document.getElementById('integrationModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                hideIntegrationModal();
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
});
