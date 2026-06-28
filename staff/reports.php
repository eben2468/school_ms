<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Staff Reports & Analytics";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php
$months = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
$current_year = (int)date('Y');
$current_month = (int)date('m');

$report_cards = [
    ['type'=>'attendance','title'=>'Attendance Summary','desc'=>'Monthly attendance rates, tardiness, and absences per staff member.','icon'=>'fa-calendar-check','color'=>'blue','needs'=>'period'],
    ['type'=>'performance','title'=>'Performance Evaluations','desc'=>'Average ratings, category breakdowns, and top performing staff.','icon'=>'fa-star','color'=>'green','needs'=>'none'],
    ['type'=>'payroll','title'=>'Payroll & Compensation','desc'=>'Salary distribution by department and full disbursement audit trail.','icon'=>'fa-money-bill-wave','color'=>'purple','needs'=>'period'],
    ['type'=>'leaves','title'=>'Leave Report','desc'=>'Leave requests, durations, balances, and approval status overview.','icon'=>'fa-bed','color'=>'amber','needs'=>'status'],
    ['type'=>'qualifications','title'=>'Qualifications & Expiries','desc'=>'Active credentials, certifications, and upcoming renewal deadlines.','icon'=>'fa-certificate','color'=>'orange','needs'=>'none'],
];
$color_map = [
    'blue'   => ['bg'=>'bg-blue-100 dark:bg-blue-900/30','text'=>'text-blue-600 dark:text-blue-400','ring'=>'ring-blue-500'],
    'green'  => ['bg'=>'bg-green-100 dark:bg-green-900/30','text'=>'text-green-600 dark:text-green-400','ring'=>'ring-green-500'],
    'purple' => ['bg'=>'bg-purple-100 dark:bg-purple-900/30','text'=>'text-purple-600 dark:text-purple-400','ring'=>'ring-purple-500'],
    'amber'  => ['bg'=>'bg-amber-100 dark:bg-amber-900/30','text'=>'text-amber-600 dark:text-amber-400','ring'=>'ring-amber-500'],
    'orange' => ['bg'=>'bg-orange-100 dark:bg-orange-900/30','text'=>'text-orange-600 dark:text-orange-400','ring'=>'ring-orange-500'],
];
?>
<style>[x-cloak]{display:none !important;}</style>
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;"
     x-data="staffReports()">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-4 sm:p-6 lg:p-8 flex-1">
            <div class="w-full">

                <!-- Page Header -->
                <div class="mb-6 sm:mb-8">
                    <div class="page-header-gradient rounded-2xl p-6 sm:p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Reports &amp; Analytics</h1>
                                <p class="text-blue-100 text-base sm:text-lg">Generate professional, print-ready reports from staff data</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-28 h-28 lg:w-32 lg:h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-chart-bar text-5xl lg:text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 p-4 sm:p-5 mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-end gap-4">
                        <div class="flex items-center gap-2 text-gray-700 dark:text-gray-200 font-semibold sm:mb-2.5">
                            <i class="fas fa-sliders-h text-blue-500"></i> Report Filters
                        </div>
                        <div class="flex-1 grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Month <span class="text-gray-400">(payroll/attendance)</span></label>
                                <select x-model="month" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php foreach ($months as $mn => $mname): ?>
                                    <option value="<?php echo $mn; ?>" <?php echo $mn===$current_month?'selected':''; ?>><?php echo $mname; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Year</label>
                                <select x-model="year" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $y===$current_year?'selected':''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Leave Status <span class="text-gray-400">(leaves)</span></label>
                                <select x-model="status" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 dark:text-white px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="all">All</option>
                                    <option value="pending">Pending</option>
                                    <option value="approved">Approved</option>
                                    <option value="rejected">Rejected</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Report Categories -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 sm:gap-6 mb-8">
                    <?php foreach ($report_cards as $card): $c = $color_map[$card['color']]; ?>
                    <div @click="generate('<?php echo $card['type']; ?>')"
                         :class="active === '<?php echo $card['type']; ?>' ? 'ring-2 <?php echo $c['ring']; ?> shadow-xl' : 'border-gray-100 dark:border-gray-700'"
                         class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border p-6 flex flex-col h-full hover:shadow-xl hover:-translate-y-0.5 transition-all cursor-pointer">
                        <div class="w-12 h-12 <?php echo $c['bg']; ?> <?php echo $c['text']; ?> rounded-lg flex items-center justify-center mb-4">
                            <i class="fas <?php echo $card['icon']; ?> text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2"><?php echo htmlspecialchars($card['title']); ?></h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4 flex-1"><?php echo htmlspecialchars($card['desc']); ?></p>
                        <div class="<?php echo $c['text']; ?> text-sm font-medium flex items-center mt-auto">
                            Generate Report <i class="fas fa-arrow-right ml-2"></i>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Report Viewer -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 flex flex-col sm:flex-row gap-3 justify-between sm:items-center">
                        <h3 class="font-bold text-gray-800 dark:text-white flex items-center gap-2">
                            <i class="fas fa-file-alt text-blue-500"></i> Report Viewer
                            <span x-show="active" x-text="activeLabel" class="text-xs font-normal text-gray-500 bg-gray-100 dark:bg-gray-700 dark:text-gray-300 px-2 py-0.5 rounded-full"></span>
                        </h3>
                        <div class="flex items-center gap-2" x-show="active">
                            <button @click="openNewTab()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 transition-colors">
                                <i class="fas fa-external-link-alt"></i> Open in New Tab
                            </button>
                            <button @click="printReport()" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-lg bg-blue-600 hover:bg-blue-700 text-white transition-colors">
                                <i class="fas fa-print"></i> Print / PDF
                            </button>
                        </div>
                    </div>
                    <div class="w-full h-[600px] bg-gray-100 dark:bg-gray-900 relative">
                        <iframe id="report-frame" class="w-full h-full border-0" src="about:blank"></iframe>
                        <div x-show="!active" class="absolute inset-0 flex flex-col items-center justify-center text-gray-400 pointer-events-none">
                            <i class="fas fa-file-alt text-6xl mb-4 opacity-50"></i>
                            <p>Select a report above to generate and preview it here.</p>
                        </div>
                        <div x-show="loading" x-cloak class="absolute inset-0 flex flex-col items-center justify-center bg-white/70 dark:bg-gray-900/70 text-gray-500">
                            <i class="fas fa-spinner fa-spin text-4xl mb-3 text-blue-500"></i>
                            <p>Generating report&hellip;</p>
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

<script>
    function staffReports() {
        return {
            active: '',
            loading: false,
            month: <?php echo $current_month; ?>,
            year: <?php echo $current_year; ?>,
            status: 'all',
            labels: {
                attendance: 'Attendance Summary',
                performance: 'Performance Evaluations',
                payroll: 'Payroll & Compensation',
                leaves: 'Leave Report',
                qualifications: 'Qualifications & Expiries'
            },
            get activeLabel() { return this.labels[this.active] || ''; },
            buildUrl(type) {
                let url = 'api.php?action=generate_report&type=' + encodeURIComponent(type);
                if (type === 'payroll' || type === 'attendance') {
                    url += '&month=' + this.month + '&year=' + this.year;
                }
                if (type === 'leaves') {
                    url += '&status=' + this.status;
                }
                return url;
            },
            generate(type) {
                this.active = type;
                this.loading = true;
                const frame = document.getElementById('report-frame');
                frame.onload = () => { this.loading = false; };
                frame.src = this.buildUrl(type);
            },
            openNewTab() {
                if (this.active) window.open(this.buildUrl(this.active), '_blank');
            },
            printReport() {
                const frame = document.getElementById('report-frame');
                if (this.active && frame.contentWindow) frame.contentWindow.print();
            }
        };
    }
</script>
