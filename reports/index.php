<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('reports');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get report statistics
$stats = [
    'total_students' => 0,
    'total_teachers' => 0,
    'total_classes' => 0,
    'pending_fees' => 0
];

// Get total students
$student_query = "SELECT COUNT(*) as count FROM users WHERE role = 'student' AND status = 'active'";
$student_stmt = $db->prepare($student_query);
$student_stmt->execute();
$stats['total_students'] = $student_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total teachers
$teacher_query = "SELECT COUNT(*) as count FROM users WHERE role = 'teacher' AND status = 'active'";
$teacher_stmt = $db->prepare($teacher_query);
$teacher_stmt->execute();
$stats['total_teachers'] = $teacher_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total classes
$class_query = "SELECT COUNT(*) as count FROM classes WHERE status = 'active'";
$class_stmt = $db->prepare($class_query);
$class_stmt->execute();
$stats['total_classes'] = $class_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get pending fees
$fees_query = "SELECT SUM(amount) as total FROM fees WHERE status = 'unpaid'";
$fees_stmt = $db->prepare($fees_query);
$fees_stmt->execute();
$fees_result = $fees_stmt->fetch(PDO::FETCH_ASSOC);
$stats['pending_fees'] = $fees_result['total'] ?? 0;

// Summary stat cards configuration
$stat_cards = [
    ['label' => 'Total Students', 'value' => number_format($stats['total_students']), 'icon' => 'fa-user-graduate', 'color' => 'blue'],
    ['label' => 'Total Teachers', 'value' => number_format($stats['total_teachers']), 'icon' => 'fa-chalkboard-teacher', 'color' => 'green'],
    ['label' => 'Active Classes', 'value' => number_format($stats['total_classes']), 'icon' => 'fa-chalkboard', 'color' => 'purple'],
    ['label' => 'Pending Fees', 'value' => '$' . number_format($stats['pending_fees'], 2), 'icon' => 'fa-dollar-sign', 'color' => 'red'],
];

$stat_color_map = [
    'blue'   => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400'],
    'green'  => ['bg' => 'bg-green-100 dark:bg-green-900/40', 'text' => 'text-green-600 dark:text-green-400'],
    'purple' => ['bg' => 'bg-purple-100 dark:bg-purple-900/40', 'text' => 'text-purple-600 dark:text-purple-400'],
    'red'    => ['bg' => 'bg-red-100 dark:bg-red-900/40', 'text' => 'text-red-600 dark:text-red-400'],
];

// Report category cards configuration
$report_categories = [
    [
        'title' => 'Academic Reports',
        'desc' => 'Student performance, grades, and academic progress reports.',
        'icon' => 'fa-graduation-cap',
        'color' => 'blue',
        'gradient' => 'linear-gradient(135deg, #3b82f6, #4f46e5)',
        'links' => [
            ['href' => 'academic.php', 'icon' => 'fa-chart-line', 'label' => 'Academic Progress'],
            ['href' => 'grades.php', 'icon' => 'fa-star', 'label' => 'Grade Reports'],
            ['href' => 'transcripts.php', 'icon' => 'fa-file-alt', 'label' => 'Transcripts'],
            ['href' => '../academic/reports/index.php', 'icon' => 'fa-file-invoice', 'label' => 'Term Report Cards'],
        ],
    ],
    [
        'title' => 'Attendance Reports',
        'desc' => 'Daily, weekly, and monthly attendance analytics.',
        'icon' => 'fa-calendar-check',
        'color' => 'green',
        'gradient' => 'linear-gradient(135deg, #10b981, #16a34a)',
        'links' => [
            ['href' => '../attendance/reports.php', 'icon' => 'fa-chart-bar', 'label' => 'Attendance Analytics'],
            ['href' => 'absenteeism.php', 'icon' => 'fa-user-times', 'label' => 'Absenteeism Report'],
            ['href' => 'punctuality.php', 'icon' => 'fa-clock', 'label' => 'Punctuality Report'],
        ],
    ],
    [
        'title' => 'Financial Reports',
        'desc' => 'Fee collection, payments, and financial analytics.',
        'icon' => 'fa-money-bill-wave',
        'color' => 'amber',
        'gradient' => 'linear-gradient(135deg, #f59e0b, #eab308)',
        'roles' => ['super_admin', 'school_admin', 'principal', 'accountant'],
        'links' => [
            ['href' => '../finance/reports.php', 'icon' => 'fa-chart-pie', 'label' => 'Financial Overview'],
            ['href' => 'fee_collection.php', 'icon' => 'fa-coins', 'label' => 'Fee Collection'],
            ['href' => 'outstanding_fees.php', 'icon' => 'fa-exclamation-triangle', 'label' => 'Outstanding Fees'],
        ],
    ],
    [
        'title' => 'Class Reports',
        'desc' => 'Class-wise performance and analytics.',
        'icon' => 'fa-chalkboard',
        'color' => 'purple',
        'gradient' => 'linear-gradient(135deg, #a855f7, #c026d3)',
        'links' => [
            ['href' => 'class.php', 'icon' => 'fa-users', 'label' => 'Class Performance'],
            ['href' => 'subject_performance.php', 'icon' => 'fa-book', 'label' => 'Subject Performance'],
            ['href' => 'teacher_performance.php', 'icon' => 'fa-chalkboard-teacher', 'label' => 'Teacher Performance'],
        ],
    ],
    [
        'title' => 'Library Reports',
        'desc' => 'Book circulation and library usage reports.',
        'icon' => 'fa-book',
        'color' => 'indigo',
        'gradient' => 'linear-gradient(135deg, #6366f1, #7c3aed)',
        'links' => [
            ['href' => 'library_usage.php', 'icon' => 'fa-chart-area', 'label' => 'Library Usage'],
            ['href' => 'book_circulation.php', 'icon' => 'fa-exchange-alt', 'label' => 'Book Circulation'],
            ['href' => 'overdue_books.php', 'icon' => 'fa-clock', 'label' => 'Overdue Books'],
        ],
    ],
    [
        'title' => 'Custom Reports',
        'desc' => 'Create and generate custom reports.',
        'icon' => 'fa-cogs',
        'color' => 'slate',
        'gradient' => 'linear-gradient(135deg, #64748b, #4b5563)',
        'links' => [
            ['href' => 'custom_report_builder.php', 'icon' => 'fa-tools', 'label' => 'Report Builder'],
            ['href' => 'saved_reports.php', 'icon' => 'fa-save', 'label' => 'Saved Reports'],
            ['href' => 'scheduled_reports.php', 'icon' => 'fa-calendar-alt', 'label' => 'Scheduled Reports'],
        ],
    ],
];

// Hover styles for category link rows (full literal classes for the Tailwind CDN build)
$link_hover_map = [
    'blue'   => 'hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-700 dark:hover:text-blue-400',
    'green'  => 'hover:bg-green-50 dark:hover:bg-green-900/20 hover:text-green-700 dark:hover:text-green-400',
    'amber'  => 'hover:bg-amber-50 dark:hover:bg-amber-900/20 hover:text-amber-700 dark:hover:text-amber-400',
    'purple' => 'hover:bg-purple-50 dark:hover:bg-purple-900/20 hover:text-purple-700 dark:hover:text-purple-400',
    'indigo' => 'hover:bg-indigo-50 dark:hover:bg-indigo-900/20 hover:text-indigo-700 dark:hover:text-indigo-400',
    'slate'  => 'hover:bg-slate-50 dark:hover:bg-slate-700/40 hover:text-slate-700 dark:hover:text-slate-300',
];
$link_icon_map = [
    'blue'   => 'text-blue-500',
    'green'  => 'text-green-500',
    'amber'  => 'text-amber-500',
    'purple' => 'text-purple-500',
    'indigo' => 'text-indigo-500',
    'slate'  => 'text-slate-500',
];

$title = "Reports & Analytics";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Reports &amp; Analytics</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Generate, explore, and export institutional reports across academics, attendance, finance, and more.</p>
                    </div>
                    <button onclick="exportAllReports()" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                        <i class="fas fa-download mr-2"></i>Export All
                    </button>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <?php foreach ($stat_cards as $card): $c = $stat_color_map[$card['color']]; ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between hover:shadow-xl hover:-translate-y-0.5 transition-all duration-300">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400"><?php echo $card['label']; ?></p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $card['value']; ?></h3>
                        </div>
                        <div class="w-12 h-12 <?php echo $c['bg']; ?> rounded-lg flex items-center justify-center">
                            <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-xl"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Report Categories -->
                <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Categories</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($report_categories as $cat): ?>
                        <?php
                        // Respect role-based visibility (e.g. Financial Reports)
                        if (isset($cat['roles']) && !in_array($user_role, $cat['roles'])) {
                            continue;
                        }
                        $hover = $link_hover_map[$cat['color']] ?? $link_hover_map['slate'];
                        $linkIcon = $link_icon_map[$cat['color']] ?? $link_icon_map['slate'];
                        ?>
                        <div class="group bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden hover:shadow-xl hover:-translate-y-1 transition-all duration-300">
                            <div class="p-6">
                                <div class="flex items-center gap-4 mb-4">
                                    <div class="w-12 h-12 rounded-xl flex items-center justify-center shadow-md group-hover:scale-105 transition-transform duration-300" style="background-image: <?php echo $cat['gradient']; ?>;">
                                        <i class="fas <?php echo $cat['icon']; ?> text-white text-xl"></i>
                                    </div>
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($cat['title']); ?></h3>
                                </div>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4"><?php echo htmlspecialchars($cat['desc']); ?></p>
                                <div class="space-y-1">
                                    <?php foreach ($cat['links'] as $link): ?>
                                    <a href="<?php echo htmlspecialchars($link['href']); ?>"
                                       class="flex items-center justify-between px-3 py-2.5 rounded-lg text-sm font-medium text-gray-600 dark:text-gray-300 <?php echo $hover; ?> transition-colors group/link">
                                        <span class="flex items-center">
                                            <i class="fas <?php echo $link['icon']; ?> w-5 <?php echo $linkIcon; ?> mr-2"></i>
                                            <?php echo htmlspecialchars($link['label']); ?>
                                        </span>
                                        <i class="fas fa-chevron-right text-xs text-gray-300 dark:text-gray-600 opacity-0 -translate-x-1 group-hover/link:opacity-100 group-hover/link:translate-x-0 transition-all duration-200"></i>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function exportAllReports() {
    ExportUtils.showExportModal({
        title: 'Export Reports Summary',
        csvCallback: () => {
            // Prepare summary data for export
            const data = [
                {
                    'Report Type': 'Academic Performance',
                    'Description': 'Student grades and performance metrics',
                    'Last Updated': '<?php echo date('Y-m-d'); ?>',
                    'Status': 'Available'
                },
                {
                    'Report Type': 'Attendance',
                    'Description': 'Student and staff attendance records',
                    'Last Updated': '<?php echo date('Y-m-d'); ?>',
                    'Status': 'Available'
                },
                {
                    'Report Type': 'Financial',
                    'Description': 'Fee collection and financial summaries',
                    'Last Updated': '<?php echo date('Y-m-d'); ?>',
                    'Status': 'Available'
                },
                {
                    'Report Type': 'Library',
                    'Description': 'Book loans and library statistics',
                    'Last Updated': '<?php echo date('Y-m-d'); ?>',
                    'Status': 'Available'
                }
            ];

            ExportUtils.exportArrayToCSV(
                data,
                ExportUtils.generateFilename('reports_summary'),
                ['Report Type', 'Description', 'Last Updated', 'Status']
            );
            ExportUtils.showSuccessMessage('Reports summary exported successfully!');
        },
        pdfCallback: () => {
            ExportUtils.exportToPDF('Reports Summary', 'main');
        }
    });
}
</script>
