<?php
// Include settings helper
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings_helper.php';
// Application version (single source of truth)
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/version.php';

$current_year = date('Y');
$role = $_SESSION['role'] ?? '';
$school_name = getSchoolSetting('school_name', 'School Management System');
$school_email = getSchoolSetting('school_email', 'info@school.edu');
$school_phone = getSchoolSetting('school_phone', '+1 (234) 567-8900');
$school_address = getSchoolSetting('school_address', '123 Education Street, Learning City, LC 12345');
$footer_tagline = getSchoolSetting('footer_tagline', 'Excellence in Education');
$office_hours = getSchoolSetting('office_hours', 'Mon - Fri: 8:00 AM - 5:00 PM');
$footer_description = getSchoolSetting('footer_description', 'Empowering education through innovative technology and efficient management solutions for the digital age.');

// Social media links (configured in Settings > School Information). Only links
// that have been set are shown.
$footer_socials = [];
$social_meta = [
    'social_facebook'  => ['Facebook',   'fab fa-facebook-f',  '#1877F2'],
    'social_twitter'   => ['Twitter / X','fab fa-x-twitter',   '#0f1419'],
    'social_linkedin'  => ['LinkedIn',   'fab fa-linkedin-in', '#0A66C2'],
    'social_instagram' => ['Instagram',  'fab fa-instagram',   'linear-gradient(45deg,#f09433,#e6683c,#dc2743,#cc2366,#bc1888)'],
    'social_youtube'   => ['YouTube',    'fab fa-youtube',     '#FF0000'],
    'social_tiktok'    => ['TikTok',     'fab fa-tiktok',      '#010101'],
    'social_whatsapp'  => ['WhatsApp',   'fab fa-whatsapp',    '#25D366'],
    'social_telegram'  => ['Telegram',   'fab fa-telegram',    '#229ED9'],
];
foreach ($social_meta as $key => $meta) {
    $url = trim((string)getSchoolSetting($key, ''));
    if ($url !== '') {
        $footer_socials[] = ['url' => $url, 'label' => $meta[0], 'icon' => $meta[1], 'brand' => $meta[2]];
    }
}
?>

<style>
    /* Footer social buttons */
    .footer-socials .social-btn {
        width: 2.6rem;
        height: 2.6rem;
        border-radius: 9999px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 0.95rem;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.22);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(8px);
        position: relative;
        overflow: hidden;
        transition: transform .28s cubic-bezier(.34,1.56,.64,1), background .35s ease, box-shadow .35s ease, border-color .35s ease;
        animation: socialIn .5s ease backwards;
    }
    /* Brand-coloured fill that wipes up on hover */
    .footer-socials .social-btn::before {
        content: '';
        position: absolute;
        inset: 0;
        background: var(--brand);
        opacity: 0;
        transform: scale(0.4);
        transition: opacity .35s ease, transform .35s ease;
        z-index: 0;
    }
    .footer-socials .social-btn i {
        position: relative;
        z-index: 1;
        transition: transform .28s ease;
    }
    .footer-socials .social-btn:hover,
    .footer-socials .social-btn:focus-visible {
        transform: translateY(-5px) scale(1.1);
        border-color: rgba(255, 255, 255, 0.55);
        box-shadow: 0 12px 22px -8px rgba(0, 0, 0, 0.55), 0 0 0 4px rgba(255, 255, 255, 0.08);
        outline: none;
    }
    .footer-socials .social-btn:hover::before,
    .footer-socials .social-btn:focus-visible::before {
        opacity: 1;
        transform: scale(1);
    }
    .footer-socials .social-btn:hover i {
        transform: scale(1.18) rotate(-6deg);
    }
    .footer-socials .social-btn:active {
        transform: translateY(-2px) scale(1.04);
    }
    @keyframes socialIn {
        from { opacity: 0; transform: translateY(10px) scale(0.85); }
        to   { opacity: 1; transform: translateY(0) scale(1); }
    }
</style>

<!-- Modern Footer -->
<footer class="text-white mt-8 shadow-2xl relative z-10" style="background: var(--footer-gradient);" x-data="{ showBackToTop: false }" @scroll.window="showBackToTop = window.pageYOffset > 300">
    <div class="container mx-auto px-6 py-8">
        <!-- Main Footer Content -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
            <!-- School Information -->
            <div class="space-y-4">
                <div class="flex items-center space-x-3">
                    <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center backdrop-blur-sm overflow-hidden">
                        <?php $footer_logo_url = function_exists('getSchoolLogo') ? getSchoolLogo() : ''; ?>
                        <?php if ($footer_logo_url): ?>
                            <img src="<?php echo htmlspecialchars($footer_logo_url); ?>" alt="<?php echo htmlspecialchars($school_name); ?> logo" class="w-full h-full object-contain p-1">
                        <?php else: ?>
                            <i class="fas fa-graduation-cap text-xl text-white"></i>
                        <?php endif; ?>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white footer-school-name" data-school-name><?php echo htmlspecialchars($school_name); ?></h3>
                        <p class="text-sm text-blue-100"><?php echo htmlspecialchars($footer_tagline); ?></p>
                    </div>
                </div>
                <?php if (trim($footer_description) !== ''): ?>
                <p class="text-sm text-blue-100 leading-relaxed">
                    <?php echo nl2br(htmlspecialchars($footer_description)); ?>
                </p>
                <?php endif; ?>
                <?php if (!empty($footer_socials)): ?>
                <div class="footer-socials flex flex-wrap gap-3 pt-1">
                    <?php foreach ($footer_socials as $i => $s): ?>
                    <a href="<?php echo htmlspecialchars($s['url']); ?>" target="_blank" rel="noopener noreferrer"
                       title="<?php echo htmlspecialchars($s['label']); ?>" aria-label="<?php echo htmlspecialchars($s['label']); ?>"
                       class="social-btn" style="--brand: <?php echo $s['brand']; ?>; animation-delay: <?php echo ($i * 0.07); ?>s;">
                        <i class="<?php echo htmlspecialchars($s['icon']); ?>"></i>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Quick Links -->
            <div class="space-y-4">
                <h3 class="text-lg font-semibold text-white">Quick Links</h3>
                <ul class="space-y-3">
                    <li>
                        <a href="<?php echo ($role === 'parent') ? '/parent/dashboard.php' : '/dashboard.php'; ?>" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-tachometer-alt w-4 text-blue-100"></i>
                            <span>Dashboard</span>
                        </a>
                    </li>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <li>
                        <a href="/academic/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-graduation-cap w-4 text-blue-100"></i>
                            <span>Academics</span>
                        </a>
                    </li>
                    <li>
                        <a href="/students/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-user-graduate w-4 text-blue-100"></i>
                            <span>Students</span>
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="/library/books/index.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
                            <i class="fas fa-book w-4 text-blue-100"></i>
                            <span>Library</span>
                        </a>
                    </li>
                    <li>
                        <a href="/settings/school.php" class="text-blue-100 hover:text-white transition-colors duration-200 flex items-center space-x-2">
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
                    <?php if (!empty($office_hours)): ?>
                    <div class="flex items-center space-x-3">
                        <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-clock text-sm text-white"></i>
                        </div>
                        <div>
                            <p class="text-sm text-blue-100"><?php echo htmlspecialchars($office_hours); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
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
                        <span class="text-xs text-blue-300 font-medium">v<?php echo APP_VERSION; ?></span>
                    </div>
                    <div class="space-y-2">
                        <a href="/help.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-question-circle mr-2 text-blue-100"></i>Help Center
                        </a>
                        <a href="/support.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-headset mr-2 text-blue-100"></i>Technical Support
                        </a>
                        <a href="/feedback.php" class="block text-sm text-blue-100 hover:text-white transition-colors duration-200">
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
                        <a href="/privacy.php" class="hover:text-white transition-colors duration-200">Privacy Policy</a>
                        <span class="text-blue-200">•</span>
                        <a href="/terms.php" class="hover:text-white transition-colors duration-200">Terms of Service</a>
                        <span class="text-blue-200">•</span>
                        <a href="/cookies.php" class="hover:text-white transition-colors duration-200">Cookie Policy</a>
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
            const footerLinks = document.querySelectorAll('footer a[href^="/"]');
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
                fetch('/api/status.php')
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

    <!-- CSRF auto-attach: injects the token into POST forms and same-origin AJAX -->
    <script>
    (function () {
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) return;
        var token = meta.getAttribute('content');

        // 1) Ensure every POST form carries a hidden csrf_token field.
        function stampForms() {
            document.querySelectorAll('form').forEach(function (form) {
                var method = (form.getAttribute('method') || '').toLowerCase();
                if (method !== 'post') return;
                if (form.querySelector('input[name="csrf_token"]')) return;
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                form.appendChild(input);
            });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', stampForms);
        } else {
            stampForms();
        }
        // Catch dynamically added forms right before submit.
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (form && (form.getAttribute('method') || '').toLowerCase() === 'post'
                && !form.querySelector('input[name="csrf_token"]')) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'csrf_token';
                input.value = token;
                form.appendChild(input);
            }
        }, true);

        // 2) Attach the token header to same-origin fetch() POSTs.
        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function (input, init) {
                init = init || {};
                var method = (init.method || (typeof input === 'object' && input.method) || 'GET').toUpperCase();
                var url = (typeof input === 'string') ? input : (input && input.url) || '';
                var sameOrigin = url === '' || url.indexOf('http') !== 0 || url.indexOf(window.location.origin) === 0;
                if (method !== 'GET' && method !== 'HEAD' && sameOrigin) {
                    init.headers = init.headers || {};
                    if (init.headers instanceof Headers) {
                        if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', token);
                    } else if (!init.headers['X-CSRF-Token']) {
                        init.headers['X-CSRF-Token'] = token;
                    }
                }
                return origFetch.call(this, input, init);
            };
        }

        // 3) Attach the token header to same-origin XMLHttpRequest POSTs.
        if (window.XMLHttpRequest) {
            var origOpen = XMLHttpRequest.prototype.open;
            var origSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__csrfUnsafe = method && ['POST', 'PUT', 'PATCH', 'DELETE'].indexOf(method.toUpperCase()) !== -1;
                return origOpen.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                if (this.__csrfUnsafe) {
                    try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) {}
                }
                return origSend.apply(this, arguments);
            };
        }
    })();
    </script>
</footer>
</body>
</html>