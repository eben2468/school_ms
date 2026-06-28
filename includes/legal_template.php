<?php
/**
 * Shared template for legal pages (Privacy, Terms, Cookies).
 * Follows the standard authenticated page structure: header + sidebar + main + footer.
 *
 * Each page defines the following variables, then includes this file:
 *   $legal_title       string  Page title (e.g. "Privacy Policy")
 *   $legal_subtitle    string  Short description shown in the hero
 *   $legal_icon        string  Font Awesome icon class (e.g. "fas fa-shield-alt")
 *   $legal_effective   string  Effective / last updated date label
 *   $legal_intro       string  Intro paragraph HTML
 *   $legal_sections    array   List of ['id' => , 'title' => , 'icon' => , 'body' => HTML]
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /index.php");
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/settings_helper.php';

$school_name    = getSchoolSetting('school_name', 'School Management System');
$school_email   = getSchoolSetting('school_email', 'info@school.edu');
$school_phone   = getSchoolSetting('school_phone', '+1 (234) 567-8900');

$title = $legal_title;
$breadcrumbs = [
    ['title' => $legal_title]
];

// Related legal pages for cross-linking.
$related_pages = [
    'privacy.php' => ['Privacy Policy', 'fas fa-shield-alt'],
    'terms.php'   => ['Terms of Service', 'fas fa-file-contract'],
    'cookies.php' => ['Cookie Policy', 'fas fa-cookie-bite'],
];
$current_file = basename($_SERVER['PHP_SELF']);

include $_SERVER['DOCUMENT_ROOT'] . '/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/includes/sidebar.php';
?>

<style>
    /* TOC active link */
    .toc-link {
        border-left: 3px solid transparent;
        transition: all .2s ease;
    }
    .toc-link.active,
    .toc-link:hover {
        border-left-color: var(--theme-primary);
        color: var(--theme-secondary);
        background: var(--theme-light);
    }
    .dark .toc-link.active,
    .dark .toc-link:hover {
        background: rgba(255,255,255,0.06);
        color: #fff;
    }

    /* Content readability */
    .legal-body { line-height: 1.75; }
    .legal-body p { margin-bottom: 1rem; }
    .legal-body ul { list-style: disc; padding-left: 1.5rem; margin-bottom: 1rem; }
    .legal-body li { margin-bottom: .5rem; }
    .legal-body a { color: var(--theme-secondary); text-decoration: underline; }
    .dark .legal-body a { color: #93c5fd; }

    /* Contact callout links sit on the gradient — keep them white */
    .legal-body .legal-contact-link,
    .dark .legal-body .legal-contact-link { color: #fff !important; text-decoration: none; }

    /* Anchor offset so the fixed top bar doesn't cover headings */
    .legal-section { scroll-margin-top: 7rem; }
</style>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($legal_title); ?></h1>
                                <p class="text-blue-100 text-lg"><?php echo htmlspecialchars($legal_subtitle); ?></p>
                                <div class="mt-4 inline-flex items-center text-sm text-blue-100 bg-white/15 border border-white/25 rounded-full px-4 py-1.5 backdrop-blur-sm">
                                    <i class="fas fa-calendar-check mr-2"></i>
                                    Effective / Last updated: <span class="font-semibold ml-1"><?php echo htmlspecialchars($legal_effective); ?></span>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="<?php echo htmlspecialchars($legal_icon); ?> text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Content Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

                    <!-- Table of Contents -->
                    <aside class="lg:col-span-4 xl:col-span-3 order-1">
                        <div class="lg:sticky lg:top-24 space-y-6">
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">On this page</h2>
                                <nav class="space-y-1" id="toc">
                                    <?php foreach ($legal_sections as $s): ?>
                                    <a href="#<?php echo $s['id']; ?>" data-target="<?php echo $s['id']; ?>"
                                       class="toc-link block pl-3 pr-2 py-2 rounded-r-md text-sm text-gray-600 dark:text-gray-300 font-medium">
                                        <i class="<?php echo htmlspecialchars($s['icon']); ?> w-5 text-center mr-1 opacity-70"></i>
                                        <?php echo htmlspecialchars($s['title']); ?>
                                    </a>
                                    <?php endforeach; ?>
                                </nav>
                            </div>

                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-3">Related</h2>
                                <div class="space-y-1">
                                    <?php foreach ($related_pages as $file => $meta): if ($file === $current_file) continue; ?>
                                    <a href="/<?php echo $file; ?>"
                                       class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                        <i class="<?php echo $meta[1]; ?> w-5 text-center opacity-70"></i>
                                        <?php echo htmlspecialchars($meta[0]); ?>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </aside>

                    <!-- Content -->
                    <article class="lg:col-span-8 xl:col-span-9 order-2">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 sm:p-10 legal-body text-gray-700 dark:text-gray-300">
                            <?php if (!empty($legal_intro)): ?>
                            <div class="mb-8 p-4 rounded-xl border-l-4" style="border-color: var(--theme-primary); background: var(--theme-light);">
                                <p class="m-0 text-gray-700"><?php echo $legal_intro; ?></p>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($legal_sections as $i => $s): ?>
                            <section id="<?php echo $s['id']; ?>" class="legal-section <?php echo $i > 0 ? 'mt-10 pt-8 border-t border-gray-100 dark:border-gray-700' : ''; ?>">
                                <h2 class="flex items-center gap-3 text-xl sm:text-2xl font-bold text-gray-900 dark:text-white mb-4">
                                    <span class="w-9 h-9 rounded-lg flex items-center justify-center text-white text-sm flex-shrink-0" style="background: var(--header-gradient);">
                                        <i class="<?php echo htmlspecialchars($s['icon']); ?>"></i>
                                    </span>
                                    <?php echo htmlspecialchars($s['title']); ?>
                                </h2>
                                <div><?php echo $s['body']; ?></div>
                            </section>
                            <?php endforeach; ?>

                            <!-- Contact callout -->
                            <section class="mt-10 pt-8 border-t border-gray-100 dark:border-gray-700">
                                <div class="rounded-2xl p-6 text-white" style="background: var(--header-gradient);">
                                    <h2 class="text-lg font-bold mb-2"><i class="fas fa-envelope-open-text mr-2"></i>Questions about this policy?</h2>
                                    <p class="text-white/85 text-sm mb-4">If you have any questions, concerns, or requests regarding this document, please contact us.</p>
                                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 text-sm">
                                        <a href="mailto:<?php echo htmlspecialchars($school_email); ?>" class="legal-contact-link inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/25 rounded-lg px-4 py-2 transition-colors text-white">
                                            <i class="fas fa-envelope"></i><?php echo htmlspecialchars($school_email); ?>
                                        </a>
                                        <a href="tel:<?php echo htmlspecialchars($school_phone); ?>" class="legal-contact-link inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/25 rounded-lg px-4 py-2 transition-colors text-white">
                                            <i class="fas fa-phone"></i><?php echo htmlspecialchars($school_phone); ?>
                                        </a>
                                    </div>
                                </div>
                            </section>
                        </div>
                    </article>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include $_SERVER['DOCUMENT_ROOT'] . '/includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
    // Scrollspy: highlight the TOC link for the section currently in view.
    document.addEventListener('DOMContentLoaded', function () {
        const links = Array.from(document.querySelectorAll('.toc-link'));
        const sections = links
            .map(l => document.getElementById(l.dataset.target))
            .filter(Boolean);

        if ('IntersectionObserver' in window && sections.length) {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        links.forEach(l => l.classList.toggle('active', l.dataset.target === entry.target.id));
                    }
                });
            }, { rootMargin: '-30% 0px -60% 0px', threshold: 0 });
            sections.forEach(s => observer.observe(s));
        }
    });
</script>
