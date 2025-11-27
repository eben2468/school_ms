<?php
// Include settings helper
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/includes/settings_helper.php';

$current_year = date('Y');
$role = $_SESSION['role'] ?? '';
$school_name = getSchoolSetting('school_name', 'School Management System');
$school_email = getSchoolSetting('school_email', 'info@school.edu');
$school_phone = getSchoolSetting('school_phone', '+1 (234) 567-8900');
$school_address = getSchoolSetting('school_address', '123 Education Street, Learning City, LC 12345');
?>

<!-- Modern Footer -->
<footer class="text-white mt-8 shadow-2xl relative z-10" style="background: var(--footer-gradient);" x-data="{ showBackToTop: false }" @scroll.window="showBackToTop = window.pageYOffset > 300">
    <div class="container mx-auto px-6 py-8">
        <!-- Main Footer Content -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- School Information -->
            <div class="space-y-4">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm">
                        <i class="fas fa-graduation-cap text-xl text-white"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white footer-school-name" data-school-name><?php echo htmlspecialchars($school_name); ?></h3>
                        <p class="text-sm text-blue-100">Excellence in Education</p>
                    </div>
                </div>
                <p class="text-sm text-blue-100 leading-relaxed">
                    Empowering education through innovative technology and efficient management solutions for the digital age.
                </p>
                <div class="flex space-x-4">
                    <a href="#" class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fab fa-facebook-f text-sm text-white"></i>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fab fa-twitter text-sm text-white"></i>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fab fa-linkedin-in text-sm text-white"></i>
                    </a>
                    <a href="#" class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fab fa-instagram text-sm text-white"></i>
                    </a>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white">Quick Links</h3>
                <ul class="space-y-3">
                    <li>
                        <a href="/school_ms/dashboard.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-tachometer-alt w-4 text-blue-100"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <li>
                        <a href="/school_ms/academic/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-graduation-cap w-4 text-blue-100"></i>
                            <span>Academics</span>
                        </a>
                    </li>
                    <li>
                        <a href="/school_ms/students/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-user-graduate w-4 text-blue-100"></i>
                            <span>Students</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="/school_ms/library/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-book w-4 text-blue-100"></i>
                            <span>Library</span>
                        </a>
                    </li>
                    <li>
                        <a href="/school_ms/settings.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-cog w-4 text-blue-100"></i>
                            <span>Settings</span>
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Contact Information -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white">Contact Info</h3>
                <div class="space-y-3">
                    <div class="flex items-start space-x-3">
                        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5 backdrop-blur-sm">
                            <i class="fas fa-map-marker-alt text-sm text-white"></i>
                        </div>
                        <div>
                            <?php
                            $address_lines = explode(',', $school_address);
                            foreach ($address_lines as $line):
                                $line = trim($line);
                                if (!empty($line)):
                            ?>
                            <p class="text-sm text-blue-100"><?php echo htmlspecialchars($line); ?></p>
                            <?php
                                endif;
                            endforeach;
                            ?>
                        </div>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-phone text-sm text-white"></i>
                        </div>
                        <a href="tel:<?php echo htmlspecialchars($school_phone); ?>" class="text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <?php echo htmlspecialchars($school_phone); ?>
                        </a>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-envelope text-sm text-white"></i>
                        </div>
                        <a href="mailto:<?php echo htmlspecialchars($school_email); ?>" class="text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <?php echo htmlspecialchars($school_email); ?>
                        </a>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-clock text-sm text-white"></i>
                        </div>
                        <div>
                            <p class="text-sm text-blue-100">Mon - Fri: 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Status & Support -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white">System Status</h3>
                <div class="space-y-3">
                    <div class="flex items-center justify-between p-3 bg-white/10 rounded-lg border border-white/20 backdrop-blur-sm">
                        <div class="flex items-center space-x-2">
                            <div class="w-3 h-3 bg-green-400 rounded-full animate-pulse"></div>
                            <span class="text-sm text-white">System Status</span>
                        </div>
                        <span class="text-xs text-green-300 font-medium">Online</span>
                    </div>
                    <div class="flex items-center justify-between p-3 bg-white/10 rounded-lg border border-white/20 backdrop-blur-sm">
                        <div class="flex items-center space-x-2">
                            <div class="w-3 h-3 bg-blue-400 rounded-full"></div>
                            <span class="text-sm text-white">Version</span>
                        </div>
                        <span class="text-xs text-blue-300 font-medium">v2.0.1</span>
                    </div>
                    <div class="space-y-2">
                        <a href="/school_ms/help.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-question-circle mr-2 text-blue-100"></i>Help Center
                        </a>
                        <a href="/school_ms/support.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-headset mr-2 text-blue-100"></i>Technical Support
                        </a>
                        <a href="/school_ms/feedback.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-comment-alt mr-2 text-blue-100"></i>Send Feedback
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer Bottom -->
        <div class="border-t border-white/20 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                <div class="flex flex-col md:flex-row items-center space-y-2 md:space-y-0 md:space-x-6">
                    <p class="text-sm text-white font-medium">
                        &copy; <?php echo $current_year; ?> <?php echo htmlspecialchars($school_name); ?>. All rights reserved.
                    </p>
                    <div class="flex items-center space-x-4 text-xs text-blue-100">
                        <a href="/school_ms/privacy.php" class="hover:text-white transition-colors duration-200">Privacy Policy</a>
                        <span class="text-blue-200">•</span>
                        <a href="/school_ms/terms.php" class="hover:text-white transition-colors duration-200">Terms of Service</a>
                        <span class="text-blue-200">•</span>
                        <a href="/school_ms/cookies.php" class="hover:text-white transition-colors duration-200">Cookie Policy</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="text-xs text-blue-100">
                        Last updated: <?php echo date('M j, Y'); ?>
                    </div>
                    <button @click="window.scrollTo({top: 0, behavior: 'smooth'})" x-show="showBackToTop" x-transition class="w-10 h-10 bg-white/20 hover:bg-white/30 rounded-lg flex items-center justify-center transition-colors duration-200 backdrop-blur-sm" title="Back to top" style="display: none;">
                        <i class="fas fa-arrow-up text-sm text-white"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer Scripts -->
    <script>
        // Footer functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Smooth scroll for footer links
            const footerLinks = document.querySelectorAll('footer a[href^="/school_ms/"]');
            footerLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    // Add subtle loading animation
                    const icon = this.querySelector('i');
                    if (icon) {
                        icon.classList.add('fa-spin');
                        setTimeout(() => {
                            icon.classList.remove('fa-spin');
                        }, 500);
                    }
                });
            });

            // System status check (simulated)
            function updateSystemStatus() {
                const statusIndicator = document.querySelector('.bg-green-500');
                const statusText = document.querySelector('.text-green-400');

                // Simulate status check
                fetch('/school_ms/api/status.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status === 'online') {
                            statusIndicator?.classList.remove('bg-red-500', 'bg-yellow-500');
                            statusIndicator?.classList.add('bg-green-500');
                            statusText?.classList.remove('text-red-400', 'text-yellow-400');
                            statusText?.classList.add('text-green-400');
                            if (statusText) statusText.textContent = 'Online';
                        }
                    })
                    .catch(() => {
                        // Handle offline status
                        statusIndicator?.classList.remove('bg-green-500', 'bg-yellow-500');
                        statusIndicator?.classList.add('bg-red-500');
                        statusText?.classList.remove('text-green-400', 'text-yellow-400');
                        statusText?.classList.add('text-red-400');
                        if (statusText) statusText.textContent = 'Offline';
                    });
            }

            // Check status every 30 seconds
            updateSystemStatus();
            setInterval(updateSystemStatus, 30000);

            // Add hover effects to social media links
            const socialLinks = document.querySelectorAll('footer a[href="#"]');
            socialLinks.forEach(link => {
                link.addEventListener('mouseenter', function() {
                    this.style.transform = 'translateY(-2px)';
                });
                link.addEventListener('mouseleave', function() {
                    this.style.transform = 'translateY(0)';
                });
            });
        });
    </script>
</footer>
</body>
</html>