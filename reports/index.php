<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'accountant'])) {
    header("Location: ../auth/login.php");
    exit();
}

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

$title = "Reports & Analytics";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Reports & Analytics</h1>
                <div class="flex space-x-3">
                    <button onclick="exportAllReports()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Export All
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Students</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_students']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-user-graduate text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Teachers</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['total_teachers']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-chalkboard-teacher text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Classes</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['total_classes']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-chalkboard text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Fees</p>
                            <p class="text-2xl font-bold text-red-600">$<?php echo number_format($stats['pending_fees'], 2); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-dollar-sign text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Report Categories -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Academic Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Academic Reports</h3>
                            <div class="p-2 bg-blue-100 rounded-lg">
                                <i class="fas fa-graduation-cap text-blue-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Student performance, grades, and academic progress reports.</p>
                        <div class="space-y-2">
                            <a href="academic.php" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-blue-50 rounded">
                                <i class="fas fa-chart-line mr-2"></i>Academic Progress
                            </a>
                            <a href="grades.php" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-blue-50 rounded">
                                <i class="fas fa-star mr-2"></i>Grade Reports
                            </a>
                            <a href="transcripts.php" class="block w-full text-left px-4 py-2 text-sm text-blue-600 hover:bg-blue-50 rounded">
                                <i class="fas fa-file-alt mr-2"></i>Transcripts
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Attendance Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Attendance Reports</h3>
                            <div class="p-2 bg-green-100 rounded-lg">
                                <i class="fas fa-calendar-check text-green-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Daily, weekly, and monthly attendance analytics.</p>
                        <div class="space-y-2">
                            <a href="../attendance/reports.php" class="block w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-green-50 rounded">
                                <i class="fas fa-chart-bar mr-2"></i>Attendance Analytics
                            </a>
                            <a href="absenteeism.php" class="block w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-green-50 rounded">
                                <i class="fas fa-user-times mr-2"></i>Absenteeism Report
                            </a>
                            <a href="punctuality.php" class="block w-full text-left px-4 py-2 text-sm text-green-600 hover:bg-green-50 rounded">
                                <i class="fas fa-clock mr-2"></i>Punctuality Report
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Financial Reports -->
                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'accountant'])): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Financial Reports</h3>
                            <div class="p-2 bg-yellow-100 rounded-lg">
                                <i class="fas fa-money-bill-wave text-yellow-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Fee collection, payments, and financial analytics.</p>
                        <div class="space-y-2">
                            <a href="../finance/reports.php" class="block w-full text-left px-4 py-2 text-sm text-yellow-600 hover:bg-yellow-50 rounded">
                                <i class="fas fa-chart-pie mr-2"></i>Financial Overview
                            </a>
                            <a href="fee_collection.php" class="block w-full text-left px-4 py-2 text-sm text-yellow-600 hover:bg-yellow-50 rounded">
                                <i class="fas fa-coins mr-2"></i>Fee Collection
                            </a>
                            <a href="outstanding_fees.php" class="block w-full text-left px-4 py-2 text-sm text-yellow-600 hover:bg-yellow-50 rounded">
                                <i class="fas fa-exclamation-triangle mr-2"></i>Outstanding Fees
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Class Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Class Reports</h3>
                            <div class="p-2 bg-purple-100 rounded-lg">
                                <i class="fas fa-chalkboard text-purple-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Class-wise performance and analytics.</p>
                        <div class="space-y-2">
                            <a href="class.php" class="block w-full text-left px-4 py-2 text-sm text-purple-600 hover:bg-purple-50 rounded">
                                <i class="fas fa-users mr-2"></i>Class Performance
                            </a>
                            <a href="subject_performance.php" class="block w-full text-left px-4 py-2 text-sm text-purple-600 hover:bg-purple-50 rounded">
                                <i class="fas fa-book mr-2"></i>Subject Performance
                            </a>
                            <a href="teacher_performance.php" class="block w-full text-left px-4 py-2 text-sm text-purple-600 hover:bg-purple-50 rounded">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>Teacher Performance
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Library Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Library Reports</h3>
                            <div class="p-2 bg-indigo-100 rounded-lg">
                                <i class="fas fa-book text-indigo-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Book circulation and library usage reports.</p>
                        <div class="space-y-2">
                            <a href="library_usage.php" class="block w-full text-left px-4 py-2 text-sm text-indigo-600 hover:bg-indigo-50 rounded">
                                <i class="fas fa-chart-area mr-2"></i>Library Usage
                            </a>
                            <a href="book_circulation.php" class="block w-full text-left px-4 py-2 text-sm text-indigo-600 hover:bg-indigo-50 rounded">
                                <i class="fas fa-exchange-alt mr-2"></i>Book Circulation
                            </a>
                            <a href="overdue_books.php" class="block w-full text-left px-4 py-2 text-sm text-indigo-600 hover:bg-indigo-50 rounded">
                                <i class="fas fa-clock mr-2"></i>Overdue Books
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Custom Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Custom Reports</h3>
                            <div class="p-2 bg-gray-100 rounded-lg">
                                <i class="fas fa-cogs text-gray-600"></i>
                            </div>
                        </div>
                        <p class="text-gray-600 mb-4">Create and generate custom reports.</p>
                        <div class="space-y-2">
                            <a href="custom_report_builder.php" class="block w-full text-left px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded">
                                <i class="fas fa-tools mr-2"></i>Report Builder
                            </a>
                            <a href="saved_reports.php" class="block w-full text-left px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded">
                                <i class="fas fa-save mr-2"></i>Saved Reports
                            </a>
                            <a href="scheduled_reports.php" class="block w-full text-left px-4 py-2 text-sm text-gray-600 hover:bg-gray-50 rounded">
                                <i class="fas fa-calendar-alt mr-2"></i>Scheduled Reports
                            </a>
                        </div>
                    </div>
                </div>
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
