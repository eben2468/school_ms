<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get active academic years for initial load
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get classes for initial load
$classes_sql = "SELECT * FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes = $db->query($classes_sql)->fetchAll(PDO::FETCH_ASSOC);

$title = "Generate Term Reports";
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
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-700 via-indigo-700 to-purple-800 rounded-xl p-6 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Generate Term Reports</h1>
                                <p class="text-indigo-100 text-lg">Create, calculate, and compile comprehensive student terminal report cards.</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-20 h-20 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-invoice text-4xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Generate Reports</span>
                </div>

                <!-- Toast Notifications Container -->
                <div id="toast-container" class="fixed bottom-5 right-5 z-50 space-y-2"></div>

                <!-- Filter Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-filter mr-2 text-indigo-500"></i> Select Academic Context
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <!-- Academic Year -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                            <select id="year-select" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Academic Year</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Term -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Term</label>
                            <select id="term-select" disabled class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed dark:bg-gray-700 dark:text-white dark:disabled:bg-gray-800">
                                <option value="">Select Term (Choose Year First)</option>
                            </select>
                        </div>

                        <!-- Class -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select id="class-select" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Students Section Container -->
                <div id="students-container" class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between pb-6 border-b border-gray-200 dark:border-gray-700 mb-6">
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white">Student Enrollment List</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Select students to compile terminal performance records.</p>
                        </div>
                        <div class="mt-4 md:mt-0 flex items-center space-x-4">
                            <label class="inline-flex items-center text-sm font-medium text-gray-700 dark:text-gray-300 cursor-pointer">
                                <input type="checkbox" id="regenerate-toggle" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 mr-2 h-4 w-4">
                                Regenerate existing reports
                            </label>
                            <span class="text-gray-300 dark:text-gray-600">|</span>
                            <div class="text-sm text-gray-600 dark:text-gray-400 font-semibold">
                                <span id="selected-count" class="text-blue-600 dark:text-blue-400">0</span> selected
                            </div>
                        </div>
                    </div>

                    <!-- Table Toolbar -->
                    <div class="flex flex-wrap gap-3 mb-6 p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg justify-between items-center">
                        <div class="flex gap-2">
                            <button type="button" id="btn-select-all" class="text-sm bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 px-3 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                Select All
                            </button>
                            <button type="button" id="btn-select-eligible" class="text-sm bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-blue-600 dark:text-blue-400 px-3 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                Select Only Eligible
                            </button>
                            <button type="button" id="btn-deselect-all" class="text-sm bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 px-3 py-1.5 rounded hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                Deselect All
                            </button>
                        </div>
                    </div>

                    <!-- Students Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="w-12 px-6 py-3 text-left">
                                        <input type="checkbox" id="header-select-all" disabled class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name & ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Performance Average</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subjects Record</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Report Status</th>
                                </tr>
                            </thead>
                            <tbody id="students-table-body" class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                <!-- Populated dynamically -->
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-info-circle text-3xl mb-2 text-gray-300"></i>
                                        <p class="font-bold">Context Required</p>
                                        <p class="text-xs">Please select an Academic Year, Term, and Class to load students.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700 mt-6">
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Report cards are automatically calculated using student exams and Continuous Assessment (CA) scores.
                        </p>
                        <button type="button" id="btn-generate" disabled class="bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-6 rounded-lg transition disabled:cursor-not-allowed flex items-center shadow-md">
                            <i class="fas fa-magic mr-2"></i> Compile Report Cards
                        </button>
                    </div>
                </div>

                <!-- Empty State is now handled inside the table body -->

                <!-- Loading State Skeletons -->
                <div id="loading-state" class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 hidden">
                    <div class="animate-pulse space-y-6">
                        <div class="h-8 bg-gray-200 dark:bg-gray-700 rounded w-1/3"></div>
                        <div class="space-y-3">
                            <div class="h-10 bg-gray-100 dark:bg-gray-700 rounded"></div>
                            <div class="h-14 bg-gray-150 dark:bg-gray-700 rounded"></div>
                            <div class="h-14 bg-gray-150 dark:bg-gray-700 rounded"></div>
                            <div class="h-14 bg-gray-150 dark:bg-gray-700 rounded"></div>
                        </div>
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

<!-- Bulk Progress Modal -->
<div id="progress-modal" class="fixed inset-0 z-50 overflow-y-auto hidden flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full border border-gray-200 dark:border-gray-700 p-6 overflow-hidden transform transition-all">
        <div class="text-center mb-6">
            <div class="relative w-20 h-20 mx-auto mb-4 flex items-center justify-center">
                <!-- Spinner -->
                <svg class="animate-spin h-16 w-16 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <div class="absolute inset-0 flex items-center justify-center">
                    <i class="fas fa-hourglass-half text-blue-500 text-lg"></i>
                </div>
            </div>
            <h3 class="text-lg font-bold text-gray-900 dark:text-white" id="modal-title">Generating Report Cards</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" id="modal-subtitle">Processing requests, please do not close this page.</p>
        </div>

        <!-- Progress Bar -->
        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 mb-6 overflow-hidden">
            <div id="modal-progress-bar" class="bg-gradient-to-r from-blue-600 to-indigo-600 h-full rounded-full transition-all duration-300" style="width: 0%;"></div>
        </div>

        <div class="space-y-3 max-h-40 overflow-y-auto border border-gray-155 dark:border-gray-700 rounded-lg p-3 bg-gray-50 dark:bg-gray-900 text-left text-xs font-mono text-gray-600 dark:text-gray-400" id="modal-logs">
            <!-- Dynamic logs -->
        </div>

        <!-- Results Block (Hidden initially) -->
        <div id="modal-results" class="mt-6 border-t pt-4 border-gray-200 dark:border-gray-700 hidden text-center">
            <div class="grid grid-cols-3 gap-2 mb-6">
                <div class="p-2 bg-green-50 dark:bg-green-950/20 rounded">
                    <div class="text-lg font-bold text-green-600" id="res-generated">0</div>
                    <div class="text-2xs text-gray-500 uppercase font-semibold">Compiled</div>
                </div>
                <div class="p-2 bg-yellow-50 dark:bg-yellow-950/20 rounded">
                    <div class="text-lg font-bold text-yellow-600" id="res-skipped">0</div>
                    <div class="text-2xs text-gray-500 uppercase font-semibold">Skipped</div>
                </div>
                <div class="p-2 bg-red-50 dark:bg-red-950/20 rounded">
                    <div class="text-lg font-bold text-red-600" id="res-failed">0</div>
                    <div class="text-2xs text-gray-500 uppercase font-semibold">Failed</div>
                </div>
            </div>
            <div class="flex space-x-3">
                <a href="index.php" id="btn-view-reports" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 rounded-lg text-sm transition">
                    View Reports <i class="fas fa-eye ml-1"></i>
                </a>
                <button type="button" id="btn-close-modal" class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold py-2 px-4 rounded-lg text-sm hover:bg-gray-200 dark:hover:bg-gray-600 transition">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // DOM Elements
    const yearSelect = document.getElementById('year-select');
    const termSelect = document.getElementById('term-select');
    const classSelect = document.getElementById('class-select');
    const studentsContainer = document.getElementById('students-container');
    const emptyState = document.getElementById('empty-state');
    const loadingState = document.getElementById('loading-state');
    const tableBody = document.getElementById('students-table-body');
    
    const headerCheckbox = document.getElementById('header-select-all');
    const selectedCountSpan = document.getElementById('selected-count');
    const generateBtn = document.getElementById('btn-generate');
    const regenerateToggle = document.getElementById('regenerate-toggle');
    
    // Toolbar buttons
    const btnSelectAll = document.getElementById('btn-select-all');
    const btnSelectEligible = document.getElementById('btn-select-eligible');
    const btnDeselectAll = document.getElementById('btn-deselect-all');
    
    // Modal Elements
    const progressModal = document.getElementById('progress-modal');
    const modalTitle = document.getElementById('modal-title');
    const modalSubtitle = document.getElementById('modal-subtitle');
    const progressBar = document.getElementById('modal-progress-bar');
    const modalLogs = document.getElementById('modal-logs');
    const modalResults = document.getElementById('modal-results');
    
    const resGenerated = document.getElementById('res-generated');
    const resSkipped = document.getElementById('res-skipped');
    const resFailed = document.getElementById('res-failed');
    const btnCloseModal = document.getElementById('btn-close-modal');
    const btnViewReports = document.getElementById('btn-view-reports');

    let studentsList = [];

    // Toast function
    function showToast(message, type = 'success') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        const bgClass = type === 'success' ? 'bg-green-600' : 'bg-red-600';
        toast.className = `${bgClass} text-white px-5 py-3 rounded-lg shadow-lg flex items-center transition duration-300 opacity-0 transform translate-y-2`;
        toast.innerHTML = `
            <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
            <span class="text-sm font-semibold">${message}</span>
        `;
        container.appendChild(toast);
        setTimeout(() => {
            toast.classList.remove('opacity-0', 'translate-y-2');
        }, 10);

        setTimeout(() => {
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // Step 1: Academic Year Change -> Fetch Terms
    yearSelect.addEventListener('change', function() {
        const yearId = this.value;
        termSelect.disabled = true;
        termSelect.innerHTML = '<option value="">Loading Terms...</option>';
        hideStudentsList();

        if (!yearId) {
            termSelect.innerHTML = '<option value="">Select Term (Choose Year First)</option>';
            return;
        }

        fetch(`../../api/reports/get_terms.php?year_id=${yearId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let html = '<option value="">Select Term</option>';
                    data.terms.forEach(term => {
                        const activeLabel = term.status === 'active' ? ' (Active)' : '';
                        html += `<option value="${term.id}">${term.term_name}${activeLabel}</option>`;
                    });
                    termSelect.innerHTML = html;
                    termSelect.disabled = false;
                    
                    // Auto-select active term if any
                    const activeTerm = data.terms.find(t => t.status === 'active');
                    if (activeTerm) {
                        termSelect.value = activeTerm.id;
                        // Trigger load if class is also selected
                        triggerLoad();
                    }
                } else {
                    termSelect.innerHTML = '<option value="">Error loading terms</option>';
                    showToast(data.message || 'Error fetching terms', 'error');
                }
            })
            .catch(err => {
                termSelect.innerHTML = '<option value="">Error loading terms</option>';
                showToast('Failed to contact server', 'error');
            });
    });

    // Class selection change -> Load students
    classSelect.addEventListener('change', triggerLoad);
    termSelect.addEventListener('change', triggerLoad);

    function triggerLoad() {
        const yearId = yearSelect.value;
        const termId = termSelect.value;
        const classId = classSelect.value;

        if (yearId && termId && classId) {
            loadStudents(yearId, termId, classId);
        } else {
            hideStudentsList();
        }
    }

    function hideStudentsList() {
        studentsContainer.classList.remove('hidden');
        loadingState.classList.add('hidden');
        if (emptyState) emptyState.classList.add('hidden');
        tableBody.innerHTML = `
            <tr>
                <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                    <i class="fas fa-info-circle text-3xl mb-2 text-gray-300"></i>
                    <p class="font-bold">Context Required</p>
                    <p class="text-xs">Please select an Academic Year, Term, and Class to load students.</p>
                </td>
            </tr>
        `;
        generateBtn.disabled = true;
        headerCheckbox.disabled = true;
        headerCheckbox.checked = false;
        selectedCountSpan.textContent = '0';
    }

    // Load Students List via AJAX
    function loadStudents(yearId, termId, classId) {
        if (emptyState) emptyState.classList.add('hidden');
        studentsContainer.classList.remove('hidden');
        loadingState.classList.remove('hidden');

        fetch(`../../api/reports/get_students.php?class_id=${classId}&year_id=${yearId}&term_id=${termId}`)
            .then(res => res.json())
            .then(data => {
                loadingState.classList.add('hidden');
                if (data.success) {
                    studentsList = data.students;
                    renderStudentsTable();
                } else {
                    tableBody.innerHTML = `
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-red-500">
                                <i class="fas fa-exclamation-circle text-3xl mb-2"></i>
                                <p class="font-bold">Error Loading Students</p>
                                <p class="text-xs">${data.message || 'Unknown error occurred'}</p>
                            </td>
                        </tr>
                    `;
                    showToast(data.message || 'Error loading students', 'error');
                }
            })
            .catch(err => {
                loadingState.classList.add('hidden');
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-red-500">
                            <i class="fas fa-wifi text-3xl mb-2"></i>
                            <p class="font-bold">Network Connection Failed</p>
                            <p class="text-xs">Failed to connect to server.</p>
                        </td>
                    </tr>
                `;
                showToast('Server connection failed', 'error');
            });
    }

    // Render Student list in HTML
    function renderStudentsTable() {
        if (studentsList.length === 0) {
            tableBody.innerHTML = `
                <tr>
                    <td colspan="5" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-user-slash text-3xl mb-2 text-gray-300"></i>
                        <p class="font-bold">No Students Enrolled</p>
                        <p class="text-xs">There are no active students mapped to this class.</p>
                    </td>
                </tr>
            `;
            studentsContainer.classList.remove('hidden');
            return;
        }

        let html = '';
        studentsList.forEach(student => {
            const hasRecords = student.subjects_count > 0;
            const hasReport = student.has_report;
            
            // Checkbox status
            const checkboxDisabled = !hasRecords ? 'disabled' : '';
            const rowClass = !hasRecords ? 'opacity-60 bg-gray-50/50 dark:bg-gray-800/50' : 'hover:bg-gray-50 dark:hover:bg-gray-700/30';
            
            // Badges
            let statusBadge = '';
            if (hasReport) {
                statusBadge = '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300"><i class="fas fa-check-circle mr-1"></i> Generated</span>';
            } else if (hasRecords) {
                statusBadge = '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300"><i class="fas fa-info-circle mr-1"></i> Ready</span>';
            } else {
                statusBadge = '<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400"><i class="fas fa-exclamation-triangle mr-1"></i> No Records</span>';
            }

            const avgText = hasRecords ? `${student.average_score}%` : '<span class="text-gray-400">-</span>';
            const subText = hasRecords ? `${student.subjects_count} subjects` : '<span class="text-gray-400">0 subjects</span>';

            html += `
                <tr class="${rowClass}">
                    <td class="px-6 py-4 whitespace-nowrap">
                        <input type="checkbox" class="student-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500 h-4 w-4 cursor-pointer" 
                               value="${student.id}" data-eligible="${hasRecords}" data-generated="${hasReport}" ${checkboxDisabled}>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="w-9 h-9 bg-indigo-100 dark:bg-indigo-900/50 rounded-full flex items-center justify-center text-indigo-600 dark:text-indigo-300 font-bold text-sm">
                                ${student.name.charAt(0)}
                            </div>
                            <div class="ml-3">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white">${student.name}</div>
                                <div class="text-2xs text-gray-500 dark:text-gray-400 font-mono">${student.student_id || 'NO_ID'}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                        ${avgText}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                        ${subText}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        ${statusBadge}
                    </td>
                </tr>
            `;
        });

        tableBody.innerHTML = html;
        studentsContainer.classList.remove('hidden');
        
        // Re-attach event listeners
        document.querySelectorAll('.student-checkbox').forEach(cb => {
            cb.addEventListener('change', updateControls);
        });
        
        headerCheckbox.checked = false;
        headerCheckbox.disabled = false;
        updateControls();
    }

    // Selection helper tools
    function updateControls() {
        const checkboxes = document.querySelectorAll('.student-checkbox:not(:disabled)');
        const checked = document.querySelectorAll('.student-checkbox:checked');
        
        selectedCountSpan.textContent = checked.length;
        generateBtn.disabled = checked.length === 0;

        if (checkboxes.length > 0 && checked.length === checkboxes.length) {
            headerCheckbox.checked = true;
            headerCheckbox.indeterminate = false;
        } else if (checked.length > 0) {
            headerCheckbox.checked = false;
            headerCheckbox.indeterminate = true;
        } else {
            headerCheckbox.checked = false;
            headerCheckbox.indeterminate = false;
        }
    }

    headerCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        document.querySelectorAll('.student-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = isChecked;
        });
        updateControls();
    });

    btnSelectAll.addEventListener('click', function() {
        document.querySelectorAll('.student-checkbox:not(:disabled)').forEach(cb => {
            cb.checked = true;
        });
        updateControls();
    });

    btnDeselectAll.addEventListener('click', function() {
        document.querySelectorAll('.student-checkbox').forEach(cb => {
            cb.checked = false;
        });
        updateControls();
    });

    btnSelectEligible.addEventListener('click', function() {
        document.querySelectorAll('.student-checkbox').forEach(cb => {
            const isEligible = cb.getAttribute('data-eligible') === 'true';
            const isGenerated = cb.getAttribute('data-generated') === 'true';
            
            // Eligible means: has academic records, and (if regenerate is toggled off, hasn't been generated yet)
            const shouldSelect = isEligible && (regenerateToggle.checked || !isGenerated);
            cb.checked = shouldSelect;
        });
        updateControls();
    });

    regenerateToggle.addEventListener('change', function() {
        // If they toggle regenerate, they might want to re-evaluate selections
        showToast(`Regeneration mode: ${this.checked ? 'Enabled' : 'Disabled'}. Reports can now ${this.checked ? '' : 'not '}be overwritten.`, 'success');
    });

    // Bulk action click
    generateBtn.addEventListener('click', function() {
        const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
        const selectedIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));

        if (selectedIds.length === 0) return;

        // Open modal
        progressBar.style.width = '0%';
        modalLogs.innerHTML = '';
        modalResults.classList.add('hidden');
        progressModal.classList.remove('hidden');
        
        modalTitle.textContent = "Compiling Academic Performance";
        modalSubtitle.textContent = `Processing reports for ${selectedIds.length} students...`;
        
        logProgress(`Initiating bulk generation loop for ${selectedIds.length} students...`);

        const requestBody = {
            academic_year_id: parseInt(yearSelect.value),
            academic_term_id: parseInt(termSelect.value),
            class_id: parseInt(classSelect.value),
            student_ids: selectedIds,
            regenerate: regenerateToggle.checked
        };

        // We make a single backend call. The backend generate_bulk evaluates them as a single operation.
        // Let's execute the fetch POST:
        fetch('../../api/reports/generate_bulk.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        })
        .then(res => {
            progressBar.style.width = '50%';
            return res.json();
        })
        .then(data => {
            progressBar.style.width = '100%';
            if (data.success) {
                logProgress(`Successfully processed report compilation loop!`);
                logProgress(`Generated: ${data.generated} reports`);
                logProgress(`Skipped: ${data.skipped} reports`);
                
                if (data.errors && data.errors.length > 0) {
                    data.errors.forEach(err => logProgress(`[Warning] ${err}`));
                }

                // Show Results UI
                resGenerated.textContent = data.generated;
                resSkipped.textContent = data.skipped;
                resFailed.textContent = data.errors ? data.errors.length : 0;
                
                modalTitle.textContent = "Compilation Complete";
                modalSubtitle.textContent = "Report card calculations finished successfully.";
                
                btnViewReports.href = `index.php?class_id=${classSelect.value}&year_id=${yearSelect.value}&term_id=${termSelect.value}`;
                
                showToast(`Finished compiling! ${data.generated} created.`, 'success');
            } else {
                logProgress(`[ERROR] Generation failed: ${data.message}`);
                modalTitle.textContent = "Compilation Failed";
                modalSubtitle.textContent = "An error occurred on the server.";
                showToast(data.message || 'Bulk generation failed', 'error');
            }
            
            modalResults.classList.remove('hidden');
            // Reload the table status
            loadStudents(yearSelect.value, termSelect.value, classSelect.value);
        })
        .catch(err => {
            progressBar.style.width = '100%';
            logProgress(`[FATAL ERROR] Failed to connect: ${err.message}`);
            modalTitle.textContent = "Connection Error";
            modalSubtitle.textContent = "Could not complete request.";
            modalResults.classList.remove('hidden');
            showToast('Network error during compilation', 'error');
        });
    });

    function logProgress(msg) {
        const time = new Date().toLocaleTimeString();
        modalLogs.innerHTML += `<div>[${time}] ${msg}</div>`;
        modalLogs.scrollTop = modalLogs.scrollHeight;
    }

    btnCloseModal.addEventListener('click', function() {
        progressModal.classList.add('hidden');
    });
});
</script>

<style>
/* Visible styling for disabled Compile button state */
#btn-generate:disabled {
    background-color: #e5e7eb !important;
    color: #9ca3af !important;
    cursor: not-allowed !important;
    box-shadow: none !important;
}
.dark #btn-generate:disabled {
    background-color: #374151 !important;
    color: #4b5563 !important;
}
</style>
