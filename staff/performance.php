<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$staff_roles = ['teacher','librarian','accountant','nurse','counselor','transport_officer','hostel_warden','canteen_manager','hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

// Handle POST: Create Evaluation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_evaluation'])) {
    $staff_id = filter_input(INPUT_POST, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
    $evaluation_period = filter_input(INPUT_POST, 'evaluation_period', FILTER_SANITIZE_STRING);
    $academic_year_id = filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_NUMBER_INT);
    
    // Resolve academic year ID to its label
    $year_stmt = $db->prepare("SELECT year_name FROM academic_years WHERE id = :id LIMIT 1");
    $year_stmt->execute([':id' => $academic_year_id]);
    $year_row = $year_stmt->fetch(PDO::FETCH_ASSOC);
    $academic_year = $year_row ? $year_row['year_name'] : '';
    $evaluation_date = filter_input(INPUT_POST, 'evaluation_date', FILTER_SANITIZE_STRING);
    $status = in_array($_POST['status'] ?? 'draft', ['draft', 'submitted']) ? $_POST['status'] : 'draft';
    
    // Ratings
    $teaching_quality = filter_input(INPUT_POST, 'teaching_quality', FILTER_SANITIZE_NUMBER_INT);
    $punctuality = filter_input(INPUT_POST, 'punctuality', FILTER_SANITIZE_NUMBER_INT);
    $communication = filter_input(INPUT_POST, 'communication', FILTER_SANITIZE_NUMBER_INT);
    $professionalism = filter_input(INPUT_POST, 'professionalism', FILTER_SANITIZE_NUMBER_INT);
    $teamwork = filter_input(INPUT_POST, 'teamwork', FILTER_SANITIZE_NUMBER_INT);
    $innovation = filter_input(INPUT_POST, 'innovation', FILTER_SANITIZE_NUMBER_INT);
    
    // Calculate overall
    $ratings = [$teaching_quality, $punctuality, $communication, $professionalism, $teamwork, $innovation];
    $valid_ratings = array_filter($ratings, function($v) { return $v > 0; });
    $overall_rating = count($valid_ratings) > 0 ? round(array_sum($valid_ratings) / count($valid_ratings), 1) : 0;
    
    // Text feedback
    $strengths = $_POST['strengths'] ?? '';
    $areas_for_improvement = $_POST['areas_for_improvement'] ?? '';
    $goals = $_POST['goals'] ?? '';
    $comments = $_POST['comments'] ?? '';
    
    try {
        $stmt = $db->prepare("
            INSERT INTO staff_evaluations 
            (staff_id, evaluator_id, evaluated_at, evaluation_period, academic_year,
            teaching_quality, punctuality, communication, professionalism, teamwork, innovation, overall_rating,
            strengths, areas_for_improvement, goals, comments, status)
            VALUES 
            (:staff_id, :evaluator_id, :evaluation_date, :evaluation_period, :academic_year,
            :tq, :punct, :comm, :prof, :team, :innov, :overall,
            :strengths, :areas, :goals, :comments, :status)
        ");
        
        $stmt->execute([
            ':staff_id' => $staff_id,
            ':evaluator_id' => $_SESSION['user_id'],
            ':evaluation_date' => $evaluation_date,
            ':evaluation_period' => $evaluation_period,
            ':academic_year' => $academic_year,
            ':tq' => $teaching_quality,
            ':punct' => $punctuality,
            ':comm' => $communication,
            ':prof' => $professionalism,
            ':team' => $teamwork,
            ':innov' => $innovation,
            ':overall' => $overall_rating,
            ':strengths' => $strengths,
            ':areas' => $areas_for_improvement,
            ':goals' => $goals,
            ':comments' => $comments,
            ':status' => $status
        ]);
        
        $success_msg = "Evaluation saved successfully.";
    } catch (PDOException $e) {
        $error_msg = "Error saving evaluation: " . $e->getMessage();
    }
}

// Fetch active staff for dropdown
$staff_stmt = $db->query("SELECT id, name FROM users WHERE role IN ($staff_roles_in) AND status = 'active' ORDER BY name");
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch academic years for dropdown
$years_stmt = $db->query("SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC");
$academic_years_list = $years_stmt->fetchAll(PDO::FETCH_ASSOC);
$active_year_id = null;
foreach ($academic_years_list as $yr) {
    if ($yr['status'] === 'active') {
        $active_year_id = $yr['id'];
        break;
    }
}

// Fetch all academic terms grouped by year (for JS filtering)
$terms_stmt = $db->query("SELECT id, term_name, academic_year_id FROM academic_terms ORDER BY academic_year_id, id");
$all_terms = $terms_stmt->fetchAll(PDO::FETCH_ASSOC);
// Build a JS-friendly map: { year_id: [{id, name}, ...] }
$terms_by_year = [];
foreach ($all_terms as $t) {
    $terms_by_year[$t['academic_year_id']][] = ['id' => $t['id'], 'name' => $t['term_name']];
}

// Fetch Evaluation History
$history_stmt = $db->query("
    SELECT e.*, u.name as staff_name, ev.name as evaluator_name
    FROM staff_evaluations e
    JOIN users u ON e.staff_id = u.id
    JOIN users ev ON e.evaluator_id = ev.id
    ORDER BY e.evaluated_at DESC, e.id DESC
");
$history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total_evals,
        AVG(overall_rating) as avg_rating,
        AVG(teaching_quality) as avg_teaching,
        AVG(punctuality) as avg_punctuality,
        AVG(communication) as avg_communication,
        AVG(professionalism) as avg_professionalism,
        AVG(teamwork) as avg_teamwork,
        AVG(innovation) as avg_innovation
    FROM staff_evaluations 
    WHERE status = 'submitted'
")->fetch(PDO::FETCH_ASSOC);

// Top performers
$top_performers = $db->query("
    SELECT u.name, e.overall_rating, e.evaluation_period
    FROM staff_evaluations e
    JOIN users u ON e.staff_id = u.id
    WHERE e.status = 'submitted'
    ORDER BY e.overall_rating DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

$title = "Performance Evaluation";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{ activeView: 'overview' }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Performance Evaluation</h1>
                                <p class="text-blue-100 text-lg">Manage staff reviews and performance tracking</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-chart-line text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_msg)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error_msg)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
                <?php endif; ?>

                <!-- Tab Navigation -->
                <div class="flex space-x-1 mb-6 bg-gray-200 dark:bg-gray-800 p-1 rounded-xl w-fit">
                    <button @click="activeView = 'overview'" :class="{'bg-white dark:bg-gray-700 shadow': activeView === 'overview', 'text-gray-600 dark:text-gray-400': activeView !== 'overview'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-chart-pie mr-2"></i>Overview
                    </button>
                    <button @click="activeView = 'create'" :class="{'bg-white dark:bg-gray-700 shadow': activeView === 'create', 'text-gray-600 dark:text-gray-400': activeView !== 'create'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-plus-circle mr-2"></i>New Evaluation
                    </button>
                    <button @click="activeView = 'history'" :class="{'bg-white dark:bg-gray-700 shadow': activeView === 'history', 'text-gray-600 dark:text-gray-400': activeView !== 'history'}" class="px-5 py-2 rounded-lg font-medium transition-all text-sm flex items-center">
                        <i class="fas fa-history mr-2"></i>History
                    </button>
                </div>

                <!-- View: Overview -->
                <div x-show="activeView === 'overview'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-100 dark:border-gray-700">
                            <p class="text-sm font-medium text-gray-500 mb-1">Total Evaluations</p>
                            <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $stats['total_evals'] ?? 0; ?></h3>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-100 dark:border-gray-700">
                            <p class="text-sm font-medium text-gray-500 mb-1">Avg Overall Rating</p>
                            <h3 class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['avg_rating'] ?? 0, 1); ?> <span class="text-sm text-gray-400">/ 5</span></h3>
                        </div>
                    </div>

                    <!-- Top Performers (full width) -->
                    <div class="w-full bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-100 dark:border-gray-700 mb-8">
                        <h4 class="font-bold text-gray-800 dark:text-white mb-4">Top Performers</h4>
                        <ul class="space-y-3">
                            <?php if(empty($top_performers)): ?>
                                <li class="text-sm text-gray-500">No data available</li>
                            <?php else: foreach($top_performers as $top): ?>
                                <li class="flex items-center justify-between text-sm">
                                    <div class="flex items-center min-w-0">
                                        <div class="w-8 h-8 rounded-full bg-green-100 text-green-600 flex items-center justify-center font-bold mr-3 flex-shrink-0">
                                            <?php echo strtoupper(substr($top['name'],0,1)); ?>
                                        </div>
                                        <span class="font-medium text-gray-800 dark:text-gray-200 truncate"><?php echo htmlspecialchars($top['name']); ?></span>
                                        <span class="text-gray-400 ml-2 text-xs whitespace-nowrap"><?php echo htmlspecialchars($top['evaluation_period']); ?></span>
                                    </div>
                                    <span class="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-bold whitespace-nowrap ml-3"><?php echo $top['overall_rating']; ?> / 5</span>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-100 dark:border-gray-700 mb-8">
                        <h4 class="font-bold text-gray-800 dark:text-white mb-6">Average Ratings by Criterion</h4>
                        <div class="relative h-64 w-full">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- View: Create New -->
                <div x-show="activeView === 'create'" style="display: none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <form action="" method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-100 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">New Performance Evaluation</h2>
                        </div>
                        
                        <div class="p-6 lg:p-8 space-y-8">
                            <!-- Basic Info -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Staff Member *</label>
                                    <select name="staff_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Staff...</option>
                                        <?php foreach($staff_list as $st): ?>
                                            <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Date of Evaluation *</label>
                                    <input type="date" name="evaluation_date" required value="<?php echo date('Y-m-d'); ?>" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year *</label>
                                    <select name="academic_year" id="evalAcademicYear" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Academic Year...</option>
                                        <?php foreach($academic_years_list as $yr): ?>
                                            <option value="<?php echo $yr['id']; ?>" <?php echo ($yr['id'] == $active_year_id) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($yr['year_name']); ?>
                                                <?php echo ($yr['status'] === 'active') ? ' (Current)' : ''; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Evaluation Period *</label>
                                    <select name="evaluation_period" id="evalPeriod" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Term...</option>
                                    </select>
                                </div>
                            </div>
                            
                            <hr class="border-gray-200 dark:border-gray-700">

                            <!-- Rating Criteria -->
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Ratings (1 = Poor, 5 = Excellent)</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-6">
                                <?php 
                                $criteria = [
                                    ['name' => 'teaching_quality', 'label' => 'Teaching / Core Quality', 'icon' => 'fa-chalkboard-teacher'],
                                    ['name' => 'punctuality', 'label' => 'Punctuality & Reliability', 'icon' => 'fa-clock'],
                                    ['name' => 'communication', 'label' => 'Communication Skills', 'icon' => 'fa-comments'],
                                    ['name' => 'professionalism', 'label' => 'Professionalism', 'icon' => 'fa-user-tie'],
                                    ['name' => 'teamwork', 'label' => 'Teamwork & Collaboration', 'icon' => 'fa-users'],
                                    ['name' => 'innovation', 'label' => 'Innovation & Creativity', 'icon' => 'fa-lightbulb']
                                ];
                                foreach($criteria as $c):
                                ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-800/50 rounded-xl border border-gray-100 dark:border-gray-700" x-data="{ rating: 0, hover: 0 }">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 flex items-center justify-center mr-3">
                                            <i class="fas <?php echo $c['icon']; ?>"></i>
                                        </div>
                                        <span class="font-semibold text-gray-700 dark:text-gray-300"><?php echo $c['label']; ?></span>
                                    </div>
                                    <div class="flex space-x-1 cursor-pointer">
                                        <input type="hidden" name="<?php echo $c['name']; ?>" :value="rating">
                                        <template x-for="i in 5">
                                            <i class="fa-star text-2xl transition-colors" 
                                               :class="(hover >= i || (!hover && rating >= i)) ? 'fas text-yellow-400' : 'far text-gray-300 dark:text-gray-600'"
                                               @mouseover="hover = i" 
                                               @mouseleave="hover = 0" 
                                               @click="rating = i"></i>
                                        </template>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <hr class="border-gray-200 dark:border-gray-700">

                            <!-- Feedback Textareas -->
                            <div class="space-y-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Strengths</label>
                                    <textarea name="strengths" rows="3" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Highlight key strengths and achievements..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Areas for Improvement</label>
                                    <textarea name="areas_for_improvement" rows="3" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Identify areas needing development..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Goals for Next Period</label>
                                    <textarea name="goals" rows="3" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Set measurable goals..."></textarea>
                                </div>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Additional Comments</label>
                                    <textarea name="comments" rows="2" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="p-6 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex justify-end space-x-4">
                            <button type="submit" name="create_evaluation" value="save" class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition" onclick="document.getElementById('eval_status').value='draft'">
                                Save Draft
                            </button>
                            <input type="hidden" name="status" id="eval_status" value="submitted">
                            <button type="submit" name="create_evaluation" value="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-medium shadow transition-colors" onclick="document.getElementById('eval_status').value='submitted'">
                                Submit Evaluation
                            </button>
                        </div>
                    </form>
                </div>

                <!-- View: History -->
                <div x-show="activeView === 'history'" style="display: none;" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4" x-transition:enter-end="opacity-100 translate-y-0">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm uppercase">
                                    <tr>
                                        <th class="px-6 py-4 font-semibold">Staff Member</th>
                                        <th class="px-6 py-4 font-semibold">Period</th>
                                        <th class="px-6 py-4 font-semibold">Overall</th>
                                        <th class="px-6 py-4 font-semibold">Status</th>
                                        <th class="px-6 py-4 font-semibold">Evaluator</th>
                                        <th class="px-6 py-4 font-semibold">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($history)): ?>
                                    <tr><td colspan="6" class="px-6 py-8 text-center text-gray-500">No evaluations found.</td></tr>
                                    <?php else: foreach($history as $h): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition cursor-pointer">
                                        <td class="px-6 py-4 font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($h['staff_name']); ?></td>
                                        <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($h['evaluation_period']); ?><br>
                                            <span class="text-xs text-gray-400"><?php echo htmlspecialchars($h['academic_year']); ?></span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <span class="bg-blue-100 text-blue-800 font-bold px-2.5 py-1 rounded-full text-sm">
                                                <?php echo $h['overall_rating']; ?> / 5
                                            </span>
                                        </td>
                                        <td class="px-6 py-4">
                                            <?php if($h['status'] === 'submitted'): ?>
                                                <span class="bg-green-100 text-green-800 px-2 py-1 rounded text-xs font-semibold">Submitted</span>
                                            <?php else: ?>
                                                <span class="bg-gray-100 text-gray-800 px-2 py-1 rounded text-xs font-semibold">Draft</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($h['evaluator_name']); ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-400"><?php echo date('M d, Y', strtotime($h['evaluated_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </main>
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('performanceChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Teaching', 'Punctuality', 'Communication', 'Professionalism', 'Teamwork', 'Innovation'],
                datasets: [{
                    label: 'Average Score',
                    data: [
                        <?php echo $stats['avg_teaching'] ?? 0; ?>,
                        <?php echo $stats['avg_punctuality'] ?? 0; ?>,
                        <?php echo $stats['avg_communication'] ?? 0; ?>,
                        <?php echo $stats['avg_professionalism'] ?? 0; ?>,
                        <?php echo $stats['avg_teamwork'] ?? 0; ?>,
                        <?php echo $stats['avg_innovation'] ?? 0; ?>
                    ],
                    backgroundColor: 'rgba(59, 130, 246, 0.5)',
                    borderColor: 'rgba(59, 130, 246, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: { stepSize: 1 }
                    }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var termsByYear = <?php echo json_encode($terms_by_year); ?>;
    var yearSelect = document.getElementById('evalAcademicYear');
    var termSelect = document.getElementById('evalPeriod');

    function populateTerms() {
        var yearId = yearSelect.value;
        termSelect.innerHTML = '<option value="">Select Term...</option>';
        if (yearId && termsByYear[yearId]) {
            termsByYear[yearId].forEach(function(term) {
                var opt = document.createElement('option');
                opt.value = term.name;
                opt.textContent = term.name;
                termSelect.appendChild(opt);
            });
        }
    }

    if (yearSelect && termSelect) {
        yearSelect.addEventListener('change', populateTerms);
        // Populate on page load for the pre-selected year
        populateTerms();
    }
});
</script>
