<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Helper function to check if user has required role
function hasRole($roles) {
    return in_array($_SESSION['role'], $roles);
}

// Get filter parameters
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$term_filter = $_GET['term'] ?? '';
$year_filter = $_GET['year'] ?? '';
$student_filter = $_GET['student'] ?? '';

try {
    // Get current academic year and term
    $current_year_query = "SELECT * FROM academic_years WHERE status = 'active' LIMIT 1";
    $current_year = $db->query($current_year_query)->fetch(PDO::FETCH_ASSOC);
    
    $current_term_query = "SELECT * FROM academic_terms WHERE status = 'active' LIMIT 1";
    $current_term = $db->query($current_term_query)->fetch(PDO::FETCH_ASSOC);

    // Get all academic years for filter
    $years_query = "SELECT * FROM academic_years ORDER BY year_name DESC";
    $years = $db->query($years_query)->fetchAll(PDO::FETCH_ASSOC);

    // Get all academic terms for filter
    $terms_query = "SELECT * FROM academic_terms ORDER BY term_number";
    $terms = $db->query($terms_query)->fetchAll(PDO::FETCH_ASSOC);

    // Get classes for filter
    $classes_query = "SELECT * FROM classes WHERE status = 'active' ORDER BY name";
    $classes = $db->query($classes_query)->fetchAll(PDO::FETCH_ASSOC);

    // Get subjects for filter
    $subjects_query = "SELECT * FROM subjects WHERE status = 'active' ORDER BY name";
    $subjects = $db->query($subjects_query)->fetchAll(PDO::FETCH_ASSOC);

    // Build WHERE conditions for grades query
    $where_conditions = [];
    $params = [];

    if ($class_filter) {
        $where_conditions[] = "sar.class_id = :class_id";
        $params[':class_id'] = $class_filter;
    }

    if ($subject_filter) {
        $where_conditions[] = "sar.subject_id = :subject_id";
        $params[':subject_id'] = $subject_filter;
    }

    if ($term_filter) {
        $where_conditions[] = "sar.academic_term_id = :term_id";
        $params[':term_id'] = $term_filter;
    }

    if ($year_filter) {
        $where_conditions[] = "sar.academic_year_id = :year_id";
        $params[':year_id'] = $year_filter;
    } else if ($current_year) {
        $where_conditions[] = "sar.academic_year_id = :current_year_id";
        $params[':current_year_id'] = $current_year['id'];
    }

    if ($student_filter) {
        $where_conditions[] = "(u.name LIKE :student_name OR sp.student_id LIKE :student_id)";
        $params[':student_name'] = "%$student_filter%";
        $params[':student_id'] = "%$student_filter%";
    }

    // For teachers, only show their subjects
    if ($user_role === 'teacher') {
        $where_conditions[] = "sar.teacher_id = :teacher_id";
        $params[':teacher_id'] = $user_id;
    }

    // Get grades/academic records
    $grades_sql = "SELECT 
        sar.*,
        u.name as student_name,
        sp.student_id as student_number,
        s.name as subject_name, s.code as subject_code,
        c.name as class_name, c.grade_level,
        ay.year_name,
        at.term_name,
        teacher.name as teacher_name
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN subjects s ON sar.subject_id = s.id
    JOIN classes c ON sar.class_id = c.id
    JOIN academic_years ay ON sar.academic_year_id = ay.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id";

    if (!empty($where_conditions)) {
        $grades_sql .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $grades_sql .= " ORDER BY u.name, s.name, at.term_number";

    $stmt = $db->prepare($grades_sql);
    $stmt->execute($params);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $stats_sql = "SELECT 
        COUNT(*) as total_records,
        AVG(total_score) as average_score,
        COUNT(DISTINCT student_id) as total_students,
        COUNT(DISTINCT subject_id) as total_subjects
    FROM student_academic_records sar";

    if (!empty($where_conditions)) {
        $stats_sql .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $stats_stmt = $db->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Calculate grade letter from score
function calculateGrade($score) {
    if ($score >= 90) return 'A+';
    if ($score >= 80) return 'A';
    if ($score >= 70) return 'B+';
    if ($score >= 60) return 'B';
    if ($score >= 50) return 'C+';
    if ($score >= 40) return 'C';
    if ($score >= 30) return 'D';
    return 'F';
}

// Get grade color
function getGradeColor($grade) {
    switch ($grade) {
        case 'A+':
        case 'A': return 'text-green-600 bg-green-100';
        case 'B+':
        case 'B': return 'text-blue-600 bg-blue-100';
        case 'C+':
        case 'C': return 'text-yellow-600 bg-yellow-100';
        case 'D': return 'text-orange-600 bg-orange-100';
        case 'F': return 'text-red-600 bg-red-100';
        default: return 'text-gray-600 bg-gray-100';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades Management - School Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#3b82f6',
                        secondary: '#1e40af',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 dark:bg-gray-900">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include '../../includes/sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Header -->
            <?php include '../../includes/header.php'; ?>
            
            <!-- Content Wrapper -->
            <main class="p-6 lg:p-8 flex-1">
                <div class="max-w-full mx-auto" style="margin-top: 20px; padding-left: 20px; padding-right: 20px;">
                    
                    <!-- Page Title -->
                    <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-4 mb-8 text-white">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">
                                    <i class="fas fa-chart-bar mr-3"></i>
                                    Grades Management
                                </h1>
                                <p class="text-blue-100">Comprehensive student grade tracking and management</p>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold"><?= $stats['total_records'] ?? 0 ?></div>
                                <div class="text-sm text-blue-100">Total Records</div>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Students</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['total_students'] ?? 0 ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-green-600 dark:text-green-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Subjects</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['total_subjects'] ?? 0 ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-chart-line text-yellow-600 dark:text-yellow-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Average Score</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['average_score'] ?? 0, 1) ?>%</p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clipboard-list text-purple-600 dark:text-purple-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Records</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['total_records'] ?? 0 ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters and Actions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <!-- Filters -->
                            <form method="GET" class="flex flex-wrap gap-4">
                                <div class="flex flex-col">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Academic Year</label>
                                    <select name="year" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Years</option>
                                        <?php foreach ($years as $year): ?>
                                            <option value="<?= $year['id'] ?>" <?= $year_filter == $year['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($year['year_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="flex flex-col">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Term</label>
                                    <select name="term" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Terms</option>
                                        <?php foreach ($terms as $term): ?>
                                            <option value="<?= $term['id'] ?>" <?= $term_filter == $term['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($term['term_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="flex flex-col">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Class</label>
                                    <select name="class" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Classes</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($class['name']) ?> - <?= htmlspecialchars($class['grade_level']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="flex flex-col">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
                                    <select name="subject" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Subjects</option>
                                        <?php foreach ($subjects as $subject): ?>
                                            <option value="<?= $subject['id'] ?>" <?= $subject_filter == $subject['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($subject['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="flex flex-col">
                                    <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Student</label>
                                    <input type="text" name="student" value="<?= htmlspecialchars($student_filter) ?>"
                                           placeholder="Search student..."
                                           class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="flex items-end">
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-search mr-2"></i>Filter
                                    </button>
                                </div>
                            </form>

                            <!-- Action Buttons -->
                            <div class="flex gap-2">
                                <?php if (hasRole(['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <a href="create.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-plus mr-2"></i>Add Grade
                                    </a>
                                    <a href="bulk_entry.php" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-table mr-2"></i>Bulk Entry
                                    </a>
                                <?php endif; ?>
                                <a href="export.php?<?= http_build_query($_GET) ?>" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition-colors">
                                    <i class="fas fa-download mr-2"></i>Export
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Grades Table -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-table mr-2"></i>
                                Student Grades
                            </h2>
                        </div>

                        <?php if (empty($grades)): ?>
                            <div class="p-8 text-center">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-chart-bar text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Grades Found</h3>
                                <p class="text-gray-600 dark:text-gray-400 mb-4">No grade records match your current filters.</p>
                                <?php if (hasRole(['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-plus mr-2"></i>Add First Grade
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Term</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">CA</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Exam</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($grades as $grade): ?>
                                            <?php
                                                $calculated_grade = calculateGrade($grade['total_score']);
                                                $grade_color = getGradeColor($calculated_grade);
                                            ?>
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                            <span class="text-sm font-medium text-blue-600 dark:text-blue-400">
                                                                <?= strtoupper(substr($grade['student_name'], 0, 1)) ?>
                                                            </span>
                                                        </div>
                                                        <div class="ml-3">
                                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                                <?= htmlspecialchars($grade['student_name']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                                ID: <?= htmlspecialchars($grade['student_number']) ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= htmlspecialchars($grade['subject_name']) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        <?= htmlspecialchars($grade['subject_code']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900 dark:text-white">
                                                        <?= htmlspecialchars($grade['class_name']) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        <?= htmlspecialchars($grade['grade_level']) ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="text-sm text-gray-900 dark:text-white">
                                                        <?= htmlspecialchars($grade['term_name']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= number_format($grade['continuous_assessment'] ?? 0, 1) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= number_format($grade['exam_score'] ?? 0, 1) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="text-sm font-bold text-gray-900 dark:text-white">
                                                        <?= number_format($grade['total_score'] ?? 0, 1) ?>%
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $grade_color ?>">
                                                        <?= $calculated_grade ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <div class="flex space-x-2">
                                                        <a href="view.php?id=<?= $grade['id'] ?>" class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <?php if (hasRole(['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                                            <a href="edit.php?id=<?= $grade['id'] ?>" class="text-green-600 hover:text-green-900 dark:text-green-400 dark:hover:text-green-300">
                                                                <i class="fas fa-edit"></i>
                                                            </a>
                                                            <?php if (hasRole(['super_admin', 'school_admin', 'principal'])): ?>
                                                                <button onclick="deleteGrade(<?= $grade['id'] ?>)" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>
            </main>

            <!-- Footer -->
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>

    <script>
        function deleteGrade(id) {
            if (confirm('Are you sure you want to delete this grade record? This action cannot be undone.')) {
                window.location.href = 'delete.php?id=' + id;
            }
        }
    </script>
</body>
</html>
