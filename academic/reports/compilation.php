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
$user_id   = $_SESSION['user_id'];

// Get current academic context defaults
$academic_context = $database->getCurrentAcademicContext();

// Get all academic years
$years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get classes (filtered for teachers)
if ($user_role === 'teacher') {
    $classes_stmt = $db->prepare("
        SELECT DISTINCT c.id, c.name, c.grade_level, c.section
        FROM classes c
        JOIN class_teachers ct ON c.id = ct.class_id
        WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
        ORDER BY c.grade_level, c.name
    ");
    $classes_stmt->execute([':teacher_id' => $user_id]);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = $db->query("SELECT id, name, grade_level, section FROM classes WHERE status = 'active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get grading scales for JS
try {
    $grading_scales = $db->query("SELECT min_score, max_score, grade, interpretation FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If grading_scales table doesn't exist, redirect to setup
    if ($e->getCode() == '42S02') {
        header("Location: ../../fix_missing_grading_scales.php");
        exit();
    }
    // For other errors, use empty array
    $grading_scales = [];
}

$title = "Term Report Compilation";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" x-data="compilationApp()" x-init="init()">

                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-green-700 via-teal-700 to-cyan-700 rounded-xl p-6 text-white shadow-lg relative overflow-hidden">
                        <!-- Decorative pattern -->
                        <div class="absolute inset-0 opacity-10">
                            <div class="absolute top-0 right-0 w-64 h-64 bg-white rounded-full -translate-y-1/2 translate-x-1/2"></div>
                            <div class="absolute bottom-0 left-0 w-48 h-48 bg-white rounded-full translate-y-1/2 -translate-x-1/2"></div>
                        </div>
                        <div class="relative flex flex-col md:flex-row md:items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">
                                    <i class="fas fa-file-invoice mr-3"></i>Term Report Compilation
                                </h1>
                                <p class="text-green-100 text-lg">Record CA and exam marks for your subjects, then compile terminal reports.</p>
                            </div>
                            <div class="mt-4 md:mt-0 flex gap-3 flex-wrap">
                                <a href="index.php" class="bg-white/15 hover:bg-white/25 border border-white/30 text-white font-semibold px-5 py-2.5 rounded-lg shadow transition flex items-center backdrop-blur-sm">
                                    <i class="fas fa-file-alt mr-2"></i> View Reports
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../../reports/index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Reports</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Term Report Compilation</span>
                </div>

                <!-- Filter Controls -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center mb-4">
                        <div class="w-10 h-10 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center mr-3">
                            <i class="fas fa-filter text-green-600 dark:text-green-400"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Select Context</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Choose academic year, term, class and subject to begin</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                        <!-- Academic Year -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                            <select x-model="selectedYearId" @change="onYearChange()" id="filter-year"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">Select Year</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo ($year['id'] == ($academic_context['year_id'] ?? '')) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Term -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Term</label>
                            <select x-model="selectedTermId" @change="onTermChange()" id="filter-term"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white transition"
                                :disabled="terms.length === 0">
                                <option value="">Select Term</option>
                                <template x-for="term in terms" :key="term.id">
                                    <option :value="term.id" x-text="term.term_name" :selected="term.id == defaultTermId"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Class -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select x-model="selectedClassId" @change="onClassChange()" id="filter-class"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Subject -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                            <select x-model="selectedSubjectId" @change="loadStudents()" id="filter-subject"
                                class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white transition"
                                :disabled="subjects.length === 0">
                                <option value="">Select Subject</option>
                                <template x-for="subj in subjects" :key="subj.id">
                                    <option :value="subj.id" x-text="subj.name"></option>
                                </template>
                            </select>
                        </div>

                        <!-- Load button -->
                        <div>
                            <button @click="loadStudents()" :disabled="!canLoad()"
                                class="w-full font-semibold py-2.5 px-6 rounded-lg transition shadow-md flex items-center justify-center"
                                :class="canLoad() ? 'bg-green-600 hover:bg-green-700 text-white' : 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-300 cursor-not-allowed border border-gray-300 dark:border-gray-500'">
                                <i class="fas fa-search mr-2"></i> Load Students
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Loading Spinner -->
                <div x-show="loading" class="flex justify-center py-12">
                    <div class="animate-spin rounded-full h-12 w-12 border-4 border-green-200 border-t-green-600"></div>
                </div>

                <!-- Marks Entry Grid -->
                <div x-show="students.length > 0 && !loading" x-transition class="space-y-6">

                    <!-- Summary Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400 mr-4">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="students.length"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Students</div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-full flex items-center justify-center text-green-600 dark:text-green-400 mr-4">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="filledCount()"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Entries Filled</div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                            <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/30 rounded-full flex items-center justify-center text-amber-600 dark:text-amber-400 mr-4">
                                <i class="fas fa-chart-line text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="classAverage()"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Class Average</div>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center text-purple-600 dark:text-purple-400 mr-4">
                                <i class="fas fa-trophy text-xl"></i>
                            </div>
                            <div>
                                <div class="text-2xl font-bold text-gray-900 dark:text-white" x-text="highestScore()"></div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Highest Score</div>
                            </div>
                        </div>
                    </div>

                    <!-- Data Table -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex flex-col md:flex-row md:items-center justify-between gap-4">
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                                    <i class="fas fa-table mr-2 text-green-600"></i>Marks Entry Sheet
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter Continuous Assessment (CA) and Exam scores for each student</p>
                            </div>
                            <div class="flex flex-wrap gap-3">
                                <button @click="saveAllMarks()" :disabled="saving"
                                    class="font-semibold py-2.5 px-6 rounded-lg transition shadow-md flex items-center"
                                    :class="saving ? 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-300 cursor-not-allowed border border-gray-300 dark:border-gray-500' : 'bg-green-600 hover:bg-green-700 text-white'">
                                    <i class="fas mr-2" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                                    <span x-text="saving ? 'Saving...' : 'Save All Marks'"></span>
                                </button>
                                <button @click="compileReports()" :disabled="compiling"
                                    class="font-semibold py-2.5 px-6 rounded-lg transition shadow-md flex items-center"
                                    :class="compiling ? 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-300 cursor-not-allowed border border-gray-300 dark:border-gray-500' : 'bg-indigo-600 hover:bg-indigo-700 text-white'">
                                    <i class="fas mr-2" :class="compiling ? 'fa-spinner fa-spin' : 'fa-magic'"></i>
                                    <span x-text="compiling ? 'Compiling...' : 'Compile Term Reports'"></span>
                                </button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700" id="marks-table">
                                <thead class="bg-gray-50 dark:bg-gray-700/50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-8">#</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-32">CA Score</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-32">Exam Score</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-28">Total</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-20">Grade</th>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-48">Remarks</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-24">Subjects</th>
                                        <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-24">Report</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                    <template x-for="(student, index) in students" :key="student.id">
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                            <!-- Row Number -->
                                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400" x-text="index + 1"></td>

                                            <!-- Student Name -->
                                            <td class="px-4 py-3">
                                                <div class="flex items-center">
                                                    <div class="w-9 h-9 bg-gradient-to-br from-green-400 to-teal-500 rounded-full flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
                                                        <span x-text="student.name.charAt(0).toUpperCase()"></span>
                                                    </div>
                                                    <div class="ml-3 min-w-0">
                                                        <div class="text-sm font-semibold text-gray-900 dark:text-white truncate" x-text="student.name"></div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="student.student_id || 'N/A'"></div>
                                                    </div>
                                                </div>
                                            </td>

                                            <!-- CA Score -->
                                            <td class="px-4 py-3">
                                                <input type="number" x-model.number="student.ca" min="0" max="100" step="0.5"
                                                    @input="recalculate(student)"
                                                    class="w-full text-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white text-sm transition"
                                                    placeholder="0">
                                            </td>

                                            <!-- Exam Score -->
                                            <td class="px-4 py-3">
                                                <input type="number" x-model.number="student.exam" min="0" max="100" step="0.5"
                                                    @input="recalculate(student)"
                                                    class="w-full text-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 dark:bg-gray-700 dark:text-white text-sm transition"
                                                    placeholder="0">
                                            </td>

                                            <!-- Total -->
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center justify-center min-w-[3rem] px-3 py-1.5 rounded-lg text-sm font-bold transition-colors"
                                                    :class="totalColorClass(student.total)">
                                                    <span x-text="student.total !== null ? student.total.toFixed(1) : '—'"></span>
                                                </span>
                                            </td>

                                            <!-- Grade -->
                                            <td class="px-4 py-3 text-center">
                                                <span class="inline-flex items-center justify-center min-w-[2.5rem] px-2.5 py-1 rounded-full text-xs font-bold"
                                                    :class="gradeColorClass(student.grade)">
                                                    <span x-text="student.grade || '—'"></span>
                                                </span>
                                            </td>

                                            <!-- Remarks -->
                                            <td class="px-4 py-3">
                                                <input type="text" x-model="student.remarks" maxlength="200"
                                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 text-sm transition"
                                                    readonly placeholder="Auto-generated...">
                                            </td>

                                            <!-- Subject Status -->
                                            <td class="px-4 py-3 text-center">
                                                <span class="text-sm font-medium" :class="student.subjects_count > 0 ? 'text-green-600 dark:text-green-400' : 'text-gray-400 dark:text-gray-500'"
                                                    x-text="student.subjects_count + ' entered'">
                                                </span>
                                            </td>

                                            <!-- Report Status -->
                                            <td class="px-4 py-3 text-center">
                                                <span x-show="student.has_report" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                    <i class="fas fa-check-circle mr-1"></i>Done
                                                </span>
                                                <span x-show="!student.has_report" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                                                    <i class="fas fa-clock mr-1"></i>Pending
                                                </span>
                                            </td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <!-- Bottom Actions -->
                        <div class="p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex flex-wrap gap-3 justify-end">
                            <button @click="saveAllMarks()" :disabled="saving"
                                class="font-semibold py-2.5 px-6 rounded-lg transition shadow-md flex items-center"
                                :class="saving ? 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-300 cursor-not-allowed border border-gray-300 dark:border-gray-500' : 'bg-green-600 hover:bg-green-700 text-white'">
                                <i class="fas mr-2" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                                <span x-text="saving ? 'Saving...' : 'Save All Marks'"></span>
                            </button>
                            <button @click="compileReports()" :disabled="compiling"
                                class="font-semibold py-2.5 px-6 rounded-lg transition shadow-md flex items-center"
                                :class="compiling ? 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-300 cursor-not-allowed border border-gray-300 dark:border-gray-500' : 'bg-indigo-600 hover:bg-indigo-700 text-white'">
                                <i class="fas mr-2" :class="compiling ? 'fa-spinner fa-spin' : 'fa-magic'"></i>
                                <span x-text="compiling ? 'Compiling...' : 'Compile Term Reports'"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty State (no context selected yet) -->
                <div x-show="students.length === 0 && !loading" x-transition>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-12 text-center">
                        <div class="w-20 h-20 bg-green-50 dark:bg-green-900/20 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-clipboard-list text-3xl text-green-500"></i>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Ready to Compile</h3>
                        <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                            Select an Academic Year, Term, Class, and Subject from the filters above, then click <strong>Load Students</strong> to begin entering marks.
                        </p>
                    </div>
                </div>

                <!-- Toast Notification -->
                <div x-show="toast.show" x-transition:enter="transition ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0"
                     x-transition:leave-end="opacity-0 translate-y-4"
                     class="fixed bottom-6 right-6 z-50 max-w-sm">
                    <div class="rounded-xl shadow-2xl p-4 flex items-start space-x-3 border"
                         :class="toast.type === 'success'
                            ? 'bg-green-50 dark:bg-green-900/50 border-green-200 dark:border-green-700'
                            : 'bg-red-50 dark:bg-red-900/50 border-red-200 dark:border-red-700'">
                        <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center"
                             :class="toast.type === 'success' ? 'bg-green-100 dark:bg-green-800' : 'bg-red-100 dark:bg-red-800'">
                            <i class="fas" :class="toast.type === 'success' ? 'fa-check text-green-600' : 'fa-exclamation text-red-600'"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold" :class="toast.type === 'success' ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200'" x-text="toast.title"></p>
                            <p class="text-xs mt-0.5" :class="toast.type === 'success' ? 'text-green-600 dark:text-green-300' : 'text-red-600 dark:text-red-300'" x-text="toast.message"></p>
                        </div>
                        <button @click="toast.show = false" class="text-gray-400 hover:text-gray-600 dark:text-gray-500 dark:hover:text-gray-300">
                            <i class="fas fa-times text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function compilationApp() {
    return {
        // Filter state
        selectedYearId: '<?php echo $academic_context['year_id'] ?? ''; ?>',
        selectedTermId: '',
        selectedClassId: '',
        selectedSubjectId: '',
        defaultTermId: '<?php echo $academic_context['term_id'] ?? ''; ?>',

        terms: [],
        subjects: [],
        students: [],
        gradingScales: <?php echo json_encode($grading_scales); ?>,

        loading: false,
        saving: false,
        compiling: false,

        toast: { show: false, type: 'success', title: '', message: '' },

        init() {
            // If year is pre-selected, load terms
            if (this.selectedYearId) {
                this.fetchTerms(this.selectedYearId);
            }
        },

        canLoad() {
            return this.selectedYearId && this.selectedTermId && this.selectedClassId && this.selectedSubjectId;
        },

        // --- Filter handlers ---
        onYearChange() {
            this.selectedTermId = '';
            this.selectedSubjectId = '';
            this.subjects = [];
            this.students = [];
            this.terms = [];
            if (this.selectedYearId) {
                this.fetchTerms(this.selectedYearId);
            }
        },

        onTermChange() {
            // No additional fetch needed for term
        },

        onClassChange() {
            this.selectedSubjectId = '';
            this.subjects = [];
            this.students = [];
            if (this.selectedClassId) {
                this.fetchSubjects(this.selectedClassId);
            }
        },

        fetchTerms(yearId) {
            fetch(`../../api/reports/get_terms.php?year_id=${yearId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.terms = data.terms;
                        // Auto-select default term if it matches
                        if (this.defaultTermId) {
                            const match = this.terms.find(t => t.id == this.defaultTermId);
                            if (match) {
                                this.selectedTermId = this.defaultTermId;
                            }
                        }
                    }
                })
                .catch(() => {
                    this.showToast('error', 'Error', 'Failed to load terms.');
                });
        },

        fetchSubjects(classId) {
            // Fetch subjects assigned to this class (via class_teachers)
            fetch(`../../academic/exams/get_subjects.php?class_id=${classId}`)
                .then(res => res.json())
                .then(data => {
                    this.subjects = data;
                })
                .catch(() => {
                    this.showToast('error', 'Error', 'Failed to load subjects.');
                });
        },

        loadStudents() {
            if (!this.canLoad()) return;

            this.loading = true;
            this.students = [];

            const params = new URLSearchParams({
                class_id: this.selectedClassId,
                year_id: this.selectedYearId,
                term_id: this.selectedTermId
            });

            fetch(`../../api/reports/get_students.php?${params.toString()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.students = data.students.map(s => ({
                            id: s.id,
                            name: s.name,
                            student_id: s.student_id,
                            ca: null,
                            exam: null,
                            total: null,
                            grade: '',
                            remarks: '',
                            subjects_count: s.subjects_count || 0,
                            has_report: s.has_report || false
                        }));
                        // Now load existing marks for this subject
                        this.loadExistingMarks();
                    } else {
                        this.showToast('error', 'Error', data.message || 'Failed to load students.');
                    }
                })
                .catch(() => {
                    this.showToast('error', 'Error', 'Network error loading students.');
                })
                .finally(() => {
                    this.loading = false;
                });
        },

        loadExistingMarks() {
            // Fetch existing academic records for these students in this subject context
            const params = new URLSearchParams({
                class_id: this.selectedClassId,
                year_id: this.selectedYearId,
                term_id: this.selectedTermId,
                subject_id: this.selectedSubjectId
            });

            fetch(`../../api/reports/get_subject_marks.php?${params.toString()}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.records) {
                        const recordsMap = {};
                        data.records.forEach(r => {
                            recordsMap[r.student_id] = r;
                        });

                        this.students.forEach(s => {
                            if (recordsMap[s.id]) {
                                const r = recordsMap[s.id];
                                s.ca = parseFloat(r.continuous_assessment) || null;
                                s.exam = parseFloat(r.exam_score) || null;
                                s.remarks = r.remarks || '';
                                this.recalculate(s);
                            }
                        });
                    }
                })
                .catch(() => {
                    // Silently fail – marks simply won't be pre-filled
                });
        },

        // --- Calculation ---
        recalculate(student) {
            const ca = parseFloat(student.ca) || 0;
            const exam = parseFloat(student.exam) || 0;
            let total = ca + exam;
            if (total > 100) total = 100;

            student.total = (student.ca !== null && student.ca !== '' || student.exam !== null && student.exam !== '') ? total : null;
            if (student.total !== null) {
                const gradeInfo = this.getGradeAndInterpretation(student.total);
                student.grade = gradeInfo.grade;
                student.remarks = gradeInfo.interpretation;
            } else {
                student.grade = '';
                student.remarks = '';
            }
        },

        getGradeAndInterpretation(score) {
            for (const scale of this.gradingScales) {
                if (score >= parseFloat(scale.min_score) && score <= parseFloat(scale.max_score)) {
                    return { grade: scale.grade, interpretation: scale.interpretation || '' };
                }
            }
            return { grade: 'F9', interpretation: 'Fail' };
        },

        getGrade(score) {
            return this.getGradeAndInterpretation(score).grade;
        },

        // --- Stats ---
        filledCount() {
            return this.students.filter(s => s.ca !== null && s.exam !== null && (s.ca > 0 || s.exam > 0)).length;
        },

        classAverage() {
            const filled = this.students.filter(s => s.total !== null && s.total > 0);
            if (filled.length === 0) return '—';
            const avg = filled.reduce((sum, s) => sum + s.total, 0) / filled.length;
            return avg.toFixed(1);
        },

        highestScore() {
            const filled = this.students.filter(s => s.total !== null);
            if (filled.length === 0) return '—';
            return Math.max(...filled.map(s => s.total)).toFixed(1);
        },

        // --- Color helpers ---
        totalColorClass(score) {
            if (score === null) return 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500';
            if (score >= 80) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
            if (score >= 70) return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
            if (score >= 60) return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-400';
            if (score >= 50) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
            return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400';
        },

        gradeColorClass(grade) {
            if (!grade) return 'bg-gray-100 text-gray-400 dark:bg-gray-700 dark:text-gray-500';
            if (grade.startsWith('A')) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
            if (grade.startsWith('B')) return 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
            if (grade.startsWith('C')) return 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300';
            if (grade.startsWith('D') || grade.startsWith('E')) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
            return 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
        },

        // --- Save Marks ---
        saveAllMarks() {
            if (this.saving) return;
            this.saving = true;

            const records = this.students
                .filter(s => s.ca !== null || s.exam !== null)
                .map(s => ({
                    student_id: s.id,
                    continuous_assessment: parseFloat(s.ca) || 0,
                    exam_score: parseFloat(s.exam) || 0,
                    remarks: s.remarks || ''
                }));

            if (records.length === 0) {
                this.showToast('error', 'No Data', 'Please enter marks for at least one student.');
                this.saving = false;
                return;
            }

            fetch('../../api/reports/save_subject_marks.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    academic_year_id: parseInt(this.selectedYearId),
                    academic_term_id: parseInt(this.selectedTermId),
                    class_id: parseInt(this.selectedClassId),
                    subject_id: parseInt(this.selectedSubjectId),
                    records: records
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.showToast('success', 'Marks Saved', data.message || `${data.saved} record(s) saved successfully.`);
                    // Refresh student list to get updated subject counts
                    this.loadStudents();
                } else {
                    this.showToast('error', 'Save Failed', data.message || 'An error occurred while saving.');
                }
            })
            .catch(() => {
                this.showToast('error', 'Network Error', 'Could not reach the server. Please try again.');
            })
            .finally(() => {
                this.saving = false;
            });
        },

        // --- Compile Reports ---
        compileReports() {
            if (this.compiling) return;

            const studentIds = this.students.map(s => s.id);
            if (studentIds.length === 0) {
                this.showToast('error', 'No Students', 'Load students first before compiling reports.');
                return;
            }

            if (!confirm('This will generate/update term report cards for all students in this class. Continue?')) {
                return;
            }

            this.compiling = true;

            fetch('../../api/reports/generate_bulk.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    academic_year_id: parseInt(this.selectedYearId),
                    academic_term_id: parseInt(this.selectedTermId),
                    class_id: parseInt(this.selectedClassId),
                    student_ids: studentIds,
                    regenerate: true
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    this.showToast('success', 'Reports Compiled',
                        `${data.generated} report(s) generated, ${data.skipped} skipped.`);
                    // Refresh to update report status badges
                    this.loadStudents();
                } else {
                    this.showToast('error', 'Compilation Failed', data.message || 'An error occurred.');
                }
            })
            .catch(() => {
                this.showToast('error', 'Network Error', 'Could not reach the server. Please try again.');
            })
            .finally(() => {
                this.compiling = false;
            });
        },

        // --- Toast ---
        showToast(type, title, message) {
            this.toast = { show: true, type, title, message };
            setTimeout(() => { this.toast.show = false; }, 5000);
        }
    };
}
</script>
