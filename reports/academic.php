<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$selected_class = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$selected_subject = isset($_GET['subject_id']) ? $_GET['subject_id'] : '';
$selected_term = isset($_GET['term']) ? $_GET['term'] : 'first';

// Get classes
$classes_query = "SELECT * FROM classes WHERE status = 'active' ORDER BY name";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subjects
$subjects_query = "SELECT * FROM subjects ORDER BY name";
$subjects_stmt = $db->prepare($subjects_query);
$subjects_stmt->execute();
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get academic performance data
$performance_data = [];
if ($selected_class) {
    $performance_query = "
        SELECT 
            u.id as student_id,
            u.name as student_name,
            sp.student_id as roll_number,
            AVG(er.marks_obtained) as average_marks,
            COUNT(er.id) as total_exams,
            AVG(er.marks_obtained / e.total_marks * 100) as percentage
        FROM users u
        JOIN student_classes sc ON u.id = sc.student_id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN exam_results er ON u.id = er.student_id
        LEFT JOIN exams e ON er.exam_id = e.id
        WHERE sc.class_id = :class_id 
        AND sc.status = 'active' 
        AND u.role = 'student'
        AND e.academic_term = :term
        " . ($selected_subject ? "AND e.subject_id = :subject_id" : "") . "
        GROUP BY u.id, u.name, sp.student_id
        ORDER BY percentage DESC
    ";
    
    $performance_stmt = $db->prepare($performance_query);
    $performance_stmt->bindParam(':class_id', $selected_class);
    $performance_stmt->bindParam(':term', $selected_term);
    if ($selected_subject) {
        $performance_stmt->bindParam(':subject_id', $selected_subject);
    }
    $performance_stmt->execute();
    $performance_data = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Academic Progress Report";
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
                <h1 class="text-3xl font-semibold text-gray-800">Academic Progress Report</h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                    </a>
                    <?php if (!empty($performance_data)): ?>
                    <button onclick="exportReport()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Export PDF
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h2 class="text-lg font-semibold text-gray-800 mb-4">Report Filters</h2>
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Class</label>
                        <select name="class_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <option value="">Select Class</option>
                            <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Subject (Optional)</label>
                        <select name="subject_id" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo $selected_subject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Term</label>
                        <select name="term" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="first" <?php echo $selected_term == 'first' ? 'selected' : ''; ?>>First Term</option>
                            <option value="second" <?php echo $selected_term == 'second' ? 'selected' : ''; ?>>Second Term</option>
                            <option value="third" <?php echo $selected_term == 'third' ? 'selected' : ''; ?>>Third Term</option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-search mr-2"></i>Generate Report
                        </button>
                    </div>
                </form>
            </div>

            <?php if (!empty($performance_data)): ?>
            <!-- Report Results -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Academic Performance Results</h2>
                    <p class="text-sm text-gray-600">
                        Showing results for 
                        <?php 
                        $class_name = '';
                        foreach ($classes as $class) {
                            if ($class['id'] == $selected_class) {
                                $class_name = $class['name'];
                                break;
                            }
                        }
                        echo htmlspecialchars($class_name);
                        ?>
                        <?php if ($selected_subject): ?>
                        - <?php 
                        foreach ($subjects as $subject) {
                            if ($subject['id'] == $selected_subject) {
                                echo htmlspecialchars($subject['name']);
                                break;
                            }
                        }
                        ?>
                        <?php endif; ?>
                        (<?php echo ucfirst($selected_term); ?> Term)
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rank</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Roll Number</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Exams</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Average Marks</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Performance</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php 
                            $rank = 1;
                            foreach ($performance_data as $student): 
                                $percentage = $student['percentage'] ?? 0;
                                $grade = '';
                                $performance_class = '';
                                
                                if ($percentage >= 90) {
                                    $grade = 'A+';
                                    $performance_class = 'text-green-600 bg-green-100';
                                } elseif ($percentage >= 80) {
                                    $grade = 'A';
                                    $performance_class = 'text-green-600 bg-green-100';
                                } elseif ($percentage >= 70) {
                                    $grade = 'B';
                                    $performance_class = 'text-blue-600 bg-blue-100';
                                } elseif ($percentage >= 60) {
                                    $grade = 'C';
                                    $performance_class = 'text-yellow-600 bg-yellow-100';
                                } elseif ($percentage >= 50) {
                                    $grade = 'D';
                                    $performance_class = 'text-orange-600 bg-orange-100';
                                } else {
                                    $grade = 'F';
                                    $performance_class = 'text-red-600 bg-red-100';
                                }
                            ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo $rank; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['student_name']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $student['total_exams'] ?? 0; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($student['average_marks'] ?? 0, 1); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($percentage, 1); ?>%
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $performance_class; ?>">
                                        <?php echo $grade; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($percentage >= 80): ?>
                                        <span class="text-green-600"><i class="fas fa-arrow-up mr-1"></i>Excellent</span>
                                    <?php elseif ($percentage >= 60): ?>
                                        <span class="text-blue-600"><i class="fas fa-arrow-right mr-1"></i>Good</span>
                                    <?php elseif ($percentage >= 50): ?>
                                        <span class="text-yellow-600"><i class="fas fa-arrow-down mr-1"></i>Average</span>
                                    <?php else: ?>
                                        <span class="text-red-600"><i class="fas fa-exclamation-triangle mr-1"></i>Needs Improvement</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                            $rank++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- Summary Statistics -->
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <?php
                        $total_students = count($performance_data);
                        $class_average = 0;
                        $excellent_count = 0;
                        $needs_improvement = 0;
                        
                        foreach ($performance_data as $student) {
                            $percentage = $student['percentage'] ?? 0;
                            $class_average += $percentage;
                            if ($percentage >= 80) $excellent_count++;
                            if ($percentage < 50) $needs_improvement++;
                        }
                        
                        $class_average = $total_students > 0 ? $class_average / $total_students : 0;
                        ?>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $total_students; ?></div>
                            <div class="text-sm text-gray-600">Total Students</div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo number_format($class_average, 1); ?>%</div>
                            <div class="text-sm text-gray-600">Class Average</div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-purple-600"><?php echo $excellent_count; ?></div>
                            <div class="text-sm text-gray-600">Excellent (80%+)</div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-red-600"><?php echo $needs_improvement; ?></div>
                            <div class="text-sm text-gray-600">Needs Improvement</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php elseif ($selected_class): ?>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-gray-500">
                    <i class="fas fa-chart-line text-4xl mb-4"></i>
                    <p class="text-lg">No academic data found for the selected criteria.</p>
                    <p class="text-sm">Try selecting a different class, subject, or term.</p>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-gray-500">
                    <i class="fas fa-filter text-4xl mb-4"></i>
                    <p class="text-lg">Please select a class to generate the academic report.</p>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function exportReport() {
    // Implementation for PDF export
    window.print();
}
</script>
