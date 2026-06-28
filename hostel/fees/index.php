<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../finance/includes/finance_functions.php';
require_once '../../finance/includes/invoice_functions.php';

$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// ─── Mark overdue invoices ───────────────────────────────────────────────────
markOverdueInvoices($db);

// ─── Ensure Boarding Fee category exists ────────────────────────────────────
$cat_stmt = $db->query("SELECT id FROM finance_fee_categories WHERE name LIKE '%Boarding%' OR name LIKE '%Hostel%' ORDER BY id LIMIT 1");
$cat_row  = $cat_stmt->fetch(PDO::FETCH_ASSOC);
$boarding_cat_id = $cat_row ? (int)$cat_row['id'] : null;

// ─── Academic context ────────────────────────────────────────────────────────
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$academic_terms = $db->query("SELECT * FROM academic_terms ORDER BY academic_year_id DESC, term_number")->fetchAll(PDO::FETCH_ASSOC);

// Detect current year/term
$current_year_stmt = $db->query("SELECT id FROM academic_years WHERE status = 'active' LIMIT 1");
$current_year_row  = $current_year_stmt->fetch(PDO::FETCH_ASSOC);
$current_year_id   = $current_year_row ? (int)$current_year_row['id'] : ($academic_years[0]['id'] ?? null);

$current_term_stmt = $db->query("SELECT id FROM academic_terms WHERE status = 'active' LIMIT 1");
$current_term_row  = $current_term_stmt->fetch(PDO::FETCH_ASSOC);
$current_term_id   = $current_term_row ? (int)$current_term_row['id'] : null;

// Filters coming from GET
$filter_year_id = isset($_GET['year_id'])  ? (int)$_GET['year_id']  : $current_year_id;
$filter_term_id = isset($_GET['term_id'])  ? (int)$_GET['term_id']  : ($current_term_id ?: '');
$search         = trim($_GET['search']    ?? '');
$status_filter  = trim($_GET['status']    ?? '');
$block_filter   = (int)($_GET['block_id'] ?? 0);

// ─── Handle POST: Charge all active boarders ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'charge_boarders') {

        if (!$boarding_cat_id) {
            $error = "No Boarding/Hostel fee category found. Please create one in Finance → Fee Categories first.";
        } else {
            $post_year_id = (int)($_POST['year_id'] ?? 0);
            $post_term_id = (int)($_POST['term_id'] ?? 0);
            $post_amount  = floatval($_POST['boarding_amount'] ?? 0);

            if (!$post_year_id || !$post_term_id || $post_amount <= 0) {
                $error = "Please provide a valid academic year, term, and boarding fee amount.";
            } else {
                // Get all active boarders (students with an active hostel allocation)
                $boarders_stmt = $db->query(
                    "SELECT DISTINCT ha.student_id
                     FROM hostel_allocations ha
                     WHERE ha.status = 'active'"
                );
                $boarders = $boarders_stmt->fetchAll(PDO::FETCH_COLUMN);

                $charged = 0; $skipped = 0; $errors_list = [];

                foreach ($boarders as $student_id) {
                    try {
                        // Check if this student already has an invoice for this year/term
                        $inv_stmt = $db->prepare(
                            "SELECT fi.id FROM finance_invoices fi
                             WHERE fi.student_id = :sid
                               AND fi.academic_year_id = :yid
                               AND fi.term_id = :tid
                               AND fi.status != 'cancelled'
                             LIMIT 1"
                        );
                        $inv_stmt->execute([':sid' => $student_id, ':yid' => $post_year_id, ':tid' => $post_term_id]);
                        $existing_inv = $inv_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($existing_inv) {
                            $invoice_id = $existing_inv['id'];

                            // Check if boarding item already on this invoice
                            $item_check = $db->prepare(
                                "SELECT id FROM finance_invoice_items
                                 WHERE invoice_id = :iid AND category_id = :cid LIMIT 1"
                            );
                            $item_check->execute([':iid' => $invoice_id, ':cid' => $boarding_cat_id]);
                            if ($item_check->fetch()) {
                                $skipped++;
                                continue; // Already charged boarding for this term
                            }

                            // Add boarding line item to existing invoice
                            $item_stmt = $db->prepare(
                                "INSERT INTO finance_invoice_items (invoice_id, category_id, description, amount)
                                 VALUES (:iid, :cid, 'Hostel Boarding Fee', :amount)"
                            );
                            $item_stmt->execute([':iid' => $invoice_id, ':cid' => $boarding_cat_id, ':amount' => $post_amount]);

                            // Update invoice total_amount
                            $db->prepare(
                                "UPDATE finance_invoices SET total_amount = total_amount + :amount WHERE id = :iid"
                            )->execute([':amount' => $post_amount, ':iid' => $invoice_id]);

                            updateInvoiceStatus($invoice_id, $db);
                            $charged++;

                        } else {
                            // Create a new invoice for this student with only the boarding fee
                            $invoice_number = generateInvoiceNumber($db);
                            $due_date = date('Y-m-d', strtotime('+30 days'));

                            $db->beginTransaction();
                            $inv_insert = $db->prepare(
                                "INSERT INTO finance_invoices
                                 (invoice_number, student_id, academic_year_id, term_id, total_amount, due_date, status)
                                 VALUES (:inv_no, :sid, :yid, :tid, :amount, :due, 'pending')"
                            );
                            $inv_insert->execute([
                                ':inv_no' => $invoice_number,
                                ':sid'    => $student_id,
                                ':yid'    => $post_year_id,
                                ':tid'    => $post_term_id,
                                ':amount' => $post_amount,
                                ':due'    => $due_date,
                            ]);
                            $invoice_id = $db->lastInsertId();

                            $item_stmt = $db->prepare(
                                "INSERT INTO finance_invoice_items (invoice_id, category_id, description, amount)
                                 VALUES (:iid, :cid, 'Hostel Boarding Fee', :amount)"
                            );
                            $item_stmt->execute([':iid' => $invoice_id, ':cid' => $boarding_cat_id, ':amount' => $post_amount]);

                            $db->commit();

                            applyDiscountsToInvoice($invoice_id, $student_id, $post_year_id, $db);
                            updateInvoiceStatus($invoice_id, $db);
                            logFinanceAudit('Generate Hostel Invoice', 'Hostel Fees', $invoice_id,
                                "Hostel boarding invoice $invoice_number for student $student_id", $db);
                            $charged++;
                        }
                    } catch (Exception $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        $errors_list[] = "Student ID $student_id: " . $e->getMessage();
                    }
                }

                if ($charged > 0) {
                    $success = "✅ Boarding fee charged to $charged student(s).";
                    if ($skipped) $success .= " $skipped already charged (skipped).";
                } elseif ($skipped > 0) {
                    $success = "ℹ️ All active boarders were already charged for this term ($skipped skipped).";
                } else {
                    $error = "No active boarders found in the hostel allocations, or all already charged.";
                }
                if (!empty($errors_list)) {
                    $error .= " Errors: " . implode('; ', $errors_list);
                }
            }
        }
    }
}

// ─── Build invoice list query ─────────────────────────────────────────────────
$invoices = [];
$stats = ['total_invoiced' => 0, 'total_paid' => 0, 'total_outstanding' => 0, 'total_boarders' => 0, 'unpaid_count' => 0];

// Count currently active boarders
$boarders_count_stmt = $db->query("SELECT COUNT(DISTINCT student_id) FROM hostel_allocations WHERE status = 'active'");
$stats['total_boarders'] = (int)$boarders_count_stmt->fetchColumn();

if ($boarding_cat_id) {
    $where_conditions = [
        "fii.category_id = :boarding_cat_id",
        "fi.status != 'cancelled'",
    ];
    $params = [':boarding_cat_id' => $boarding_cat_id];

    if ($filter_year_id) {
        $where_conditions[] = "fi.academic_year_id = :year_id";
        $params[':year_id'] = $filter_year_id;
    }
    if ($filter_term_id) {
        $where_conditions[] = "fi.term_id = :term_id";
        $params[':term_id'] = $filter_term_id;
    }
    if ($search) {
        $where_conditions[] = "(u.name LIKE :search OR fi.invoice_number LIKE :search OR sp.student_id LIKE :search)";
        $params[':search'] = "%$search%";
    }
    if ($status_filter) {
        $where_conditions[] = "fi.status = :status";
        $params[':status'] = $status_filter;
    }
    if ($block_filter) {
        $where_conditions[] = "ha.block_id = :block_id";
        $params[':block_id'] = $block_filter;
    }

    $where_clause = "WHERE " . implode(" AND ", $where_conditions);

    $query = "SELECT fi.*,
                     fii.amount as boarding_amount,
                     u.name as student_name,
                     sp.student_id as student_number,
                     c.name as class_name,
                     ay.year_name,
                     t.term_name,
                     hb.name as block_name,
                     hr.room_number,
                     (fi.total_amount + fi.penalty_amount - fi.discount_amount) as grand_total
              FROM finance_invoice_items fii
              JOIN finance_invoices fi  ON fii.invoice_id = fi.id
              JOIN users u              ON fi.student_id = u.id
              LEFT JOIN student_profiles sp ON u.id = sp.user_id
              LEFT JOIN student_classes sc  ON u.id = sc.student_id AND sc.status = 'active'
              LEFT JOIN classes c           ON sc.class_id = c.id
              JOIN academic_years ay        ON fi.academic_year_id = ay.id
              JOIN academic_terms t         ON fi.term_id = t.id
              LEFT JOIN hostel_allocations ha ON ha.student_id = fi.student_id AND ha.status = 'active'
              LEFT JOIN hostel_rooms hr       ON ha.room_id = hr.id
              LEFT JOIN hostel_blocks hb      ON hr.block_id = hb.id
              $where_clause
              ORDER BY fi.created_at DESC";

    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Stats query ─────────────────────────────────────────────────────────
    // (no block filter on stats so totals always reflect the year/term)
    $stats_params = [':boarding_cat_id' => $boarding_cat_id];
    $stats_where  = ["fii.category_id = :boarding_cat_id", "fi.status != 'cancelled'"];
    if ($filter_year_id) { $stats_where[] = "fi.academic_year_id = :year_id"; $stats_params[':year_id'] = $filter_year_id; }
    if ($filter_term_id) { $stats_where[] = "fi.term_id = :term_id";          $stats_params[':term_id'] = $filter_term_id; }
    $stats_where_clause = "WHERE " . implode(" AND ", $stats_where);

    $stats_query = "
        SELECT
            SUM(fii.amount) as total_invoiced,
            SUM(fi.amount_paid) as total_paid,
            COUNT(DISTINCT fi.id) as total_invoices,
            SUM(CASE WHEN fi.status != 'paid' THEN 1 ELSE 0 END) as unpaid_count
        FROM finance_invoice_items fii
        JOIN finance_invoices fi ON fii.invoice_id = fi.id
        $stats_where_clause
    ";
    $stats_stmt = $db->prepare($stats_query);
    foreach ($stats_params as $key => $value) {
        $stats_stmt->bindValue($key, $value);
    }
    $stats_stmt->execute();
    $stats_row = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    if ($stats_row) {
        $stats['total_invoiced']    = floatval($stats_row['total_invoiced']);
        $stats['total_paid']        = floatval($stats_row['total_paid']);
        $stats['total_outstanding'] = $stats['total_invoiced'] - $stats['total_paid'];
        $stats['unpaid_count']      = (int)$stats_row['unpaid_count'];
    }
}

// Blocks for filter dropdown
$blocks = $db->query("SELECT id, name FROM hostel_blocks ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$title = "Hostel Boarding Fees";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-grow">
        <div class="max-w-7xl mx-auto">

            <!-- ── Hero Header ─────────────────────────────────────────────── -->
            <div class="mb-8" style="margin-top:30px;">
                <div class="bg-gradient-to-r from-teal-600 via-emerald-600 to-green-700 rounded-2xl p-8 text-white shadow-xl relative overflow-hidden">
                    <div class="absolute inset-0 opacity-10" style="background-image:radial-gradient(circle at 20% 50%,#fff 1px,transparent 1px),radial-gradient(circle at 80% 20%,#fff 1px,transparent 1px);background-size:40px 40px;"></div>
                    <div class="relative flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-extrabold mb-1">Hostel Boarding Fees</h1>
                            <p class="text-emerald-100 text-base">View and manage boarding invoices linked from the finance module</p>
                            <div class="mt-4 flex flex-wrap gap-4">
                                <div class="bg-white/15 backdrop-blur-sm rounded-xl px-4 py-2 text-sm font-semibold">
                                    <i class="fas fa-users mr-2"></i><?php echo $stats['total_boarders']; ?> Active Boarders
                                </div>
                                <div class="bg-white/15 backdrop-blur-sm rounded-xl px-4 py-2 text-sm font-semibold">
                                    <i class="fas fa-file-invoice mr-2"></i><?php echo count($invoices); ?> Invoices Shown
                                </div>
                                <?php if ($stats['unpaid_count']): ?>
                                <div class="bg-red-400/30 backdrop-blur-sm rounded-xl px-4 py-2 text-sm font-semibold">
                                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $stats['unpaid_count']; ?> Unpaid
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hidden md:flex w-28 h-28 bg-white/10 rounded-full items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-hand-holding-usd text-5xl text-white/80"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Nav + Charge action ──────────────────────────────────────── -->
            <div class="flex flex-wrap justify-between items-center mb-6 gap-3">
                <a href="../index.php" class="text-teal-700 hover:text-teal-900 font-medium flex items-center gap-2 transition">
                    <i class="fas fa-arrow-left"></i> Back to Hostel
                </a>
                <div class="flex flex-wrap gap-2">
                    <?php if ($boarding_cat_id): ?>
                    <button onclick="document.getElementById('chargeModal').classList.remove('hidden')"
                            class="text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg transition flex items-center gap-2"
                            style="background-color: #0f766e;"
                            onmouseover="this.style.backgroundColor='#115e59'" onmouseout="this.style.backgroundColor='#0f766e'">
                        <i class="fas fa-bolt"></i> Charge All Boarders
                    </button>
                    <?php else: ?>
                    <a href="../../finance/fee_categories.php"
                       class="bg-amber-500 hover:bg-amber-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-exclamation-triangle"></i> Create Boarding Category First
                    </a>
                    <?php endif; ?>
                    <a href="../../finance/invoices.php"
                       class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-semibold px-5 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-external-link-alt"></i> Open Finance Module
                    </a>
                </div>
            </div>

            <!-- ── Success / Error ─────────────────────────────────────────── -->
            <?php if ($success): ?>
            <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 rounded-xl mb-6 flex items-start gap-3">
                <i class="fas fa-check-circle text-emerald-500 mt-0.5"></i>
                <span class="font-medium"><?php echo htmlspecialchars($success); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 rounded-xl mb-6 flex items-start gap-3">
                <i class="fas fa-exclamation-circle text-rose-500 mt-0.5"></i>
                <span class="font-medium"><?php echo htmlspecialchars($error); ?></span>
            </div>
            <?php endif; ?>

            <?php if (!$boarding_cat_id): ?>
            <!-- No boarding category notice -->
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-8 text-center mb-8">
                <div class="w-16 h-16 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-tag text-amber-500 text-2xl"></i>
                </div>
                <h3 class="text-xl font-bold text-amber-800 mb-2">No Boarding Fee Category Found</h3>
                <p class="text-amber-700 mb-4">You need to create a fee category named <strong>"Boarding"</strong> or <strong>"Hostel"</strong> in the Finance module before charges can be tracked here.</p>
                <a href="../../finance/fee_categories.php" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-6 py-2.5 rounded-xl shadow transition">
                    <i class="fas fa-plus mr-2"></i>Create Fee Category
                </a>
            </div>
            <?php endif; ?>

            <!-- ── Stats Cards ─────────────────────────────────────────────── -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
                <!-- Total Billed -->
                <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 flex items-center gap-4 hover:shadow-lg transition">
                    <div class="w-14 h-14 bg-blue-50 rounded-2xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-file-invoice-dollar text-blue-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Billed</p>
                        <p class="text-2xl font-extrabold text-gray-800">₵<?php echo number_format($stats['total_invoiced'], 2); ?></p>
                    </div>
                </div>
                <!-- Total Collected -->
                <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 flex items-center gap-4 hover:shadow-lg transition">
                    <div class="w-14 h-14 bg-emerald-50 rounded-2xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-check-double text-emerald-600 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Total Collected</p>
                        <p class="text-2xl font-extrabold text-emerald-600">₵<?php echo number_format($stats['total_paid'], 2); ?></p>
                    </div>
                </div>
                <!-- Outstanding -->
                <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 flex items-center gap-4 hover:shadow-lg transition">
                    <div class="w-14 h-14 bg-rose-50 rounded-2xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-rose-500 text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Outstanding</p>
                        <p class="text-2xl font-extrabold text-rose-600">₵<?php echo number_format($stats['total_outstanding'], 2); ?></p>
                    </div>
                </div>
                <!-- Collection Rate -->
                <?php
                $rate = $stats['total_invoiced'] > 0 ? ($stats['total_paid'] / $stats['total_invoiced']) * 100 : 0;
                $rate_color = $rate >= 75 ? 'text-emerald-600' : ($rate >= 40 ? 'text-amber-600' : 'text-rose-600');
                $rate_bg    = $rate >= 75 ? 'bg-emerald-50' : ($rate >= 40 ? 'bg-amber-50' : 'bg-rose-50');
                $rate_icon_color = $rate >= 75 ? 'text-emerald-600' : ($rate >= 40 ? 'text-amber-500' : 'text-rose-500');
                ?>
                <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 flex items-center gap-4 hover:shadow-lg transition">
                    <div class="w-14 h-14 <?php echo $rate_bg; ?> rounded-2xl flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-chart-pie <?php echo $rate_icon_color; ?> text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Collection Rate</p>
                        <p class="text-2xl font-extrabold <?php echo $rate_color; ?>"><?php echo number_format($rate, 1); ?>%</p>
                    </div>
                </div>
            </div>

            <!-- ── Progress Bar ────────────────────────────────────────────── -->
            <?php if ($stats['total_invoiced'] > 0): ?>
            <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 mb-6">
                <div class="flex justify-between text-sm font-semibold text-gray-600 mb-2">
                    <span>Collection Progress</span>
                    <span><?php echo number_format($rate, 1); ?>% collected</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                    <div class="h-3 rounded-full bg-gradient-to-r from-teal-500 to-emerald-500 transition-all duration-700"
                         style="width: <?php echo min(100, $rate); ?>%"></div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>₵<?php echo number_format($stats['total_paid'], 2); ?> collected</span>
                    <span>₵<?php echo number_format($stats['total_outstanding'], 2); ?> remaining</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Filter Form ─────────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-md border border-gray-100 p-5 mb-6">
                <form method="GET" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3 items-end">
                    <!-- Search -->
                    <div class="lg:col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Search Student / Invoice</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Name, ID or invoice #..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                    </div>
                    <!-- Year -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Academic Year</label>
                        <select name="year_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                            <option value="">All Years</option>
                            <?php foreach ($academic_years as $yr): ?>
                            <option value="<?php echo $yr['id']; ?>" <?php echo $filter_year_id == $yr['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($yr['year_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Term -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Term</label>
                        <select name="term_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                            <option value="">All Terms</option>
                            <?php foreach ($academic_terms as $tm): ?>
                            <option value="<?php echo $tm['id']; ?>" <?php echo $filter_term_id == $tm['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($tm['term_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Status -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 mb-1">Status</label>
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-teal-400">
                            <option value="">All Statuses</option>
                            <option value="pending"        <?php echo $status_filter === 'pending'        ? 'selected' : ''; ?>>Pending</option>
                            <option value="partially_paid" <?php echo $status_filter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                            <option value="paid"           <?php echo $status_filter === 'paid'           ? 'selected' : ''; ?>>Paid</option>
                            <option value="overdue"        <?php echo $status_filter === 'overdue'        ? 'selected' : ''; ?>>Overdue</option>
                        </select>
                    </div>
                    <!-- Filter btn -->
                    <div class="flex gap-2">
                        <button type="submit" 
                                class="flex-1 text-white font-semibold px-4 py-2 rounded-xl text-sm transition shadow"
                                style="background-color: #1f2937;"
                                onmouseover="this.style.backgroundColor='#111827'" onmouseout="this.style.backgroundColor='#1f2937'">
                            <i class="fas fa-filter mr-1"></i> Filter
                        </button>
                        <a href="index.php" class="px-3 py-2 text-gray-500 hover:text-gray-800 border border-gray-300 rounded-xl text-sm transition" title="Clear">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div>

            <!-- ── Invoice Table ───────────────────────────────────────────── -->
            <div class="bg-white rounded-2xl shadow-md border border-gray-100 overflow-hidden">
                <?php if (empty($invoices)): ?>
                <div class="text-center py-16">
                    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-receipt text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-700 mb-1">No boarding fee invoices found</h3>
                    <p class="text-gray-400 text-sm mb-6">
                        <?php if (!$boarding_cat_id): ?>
                            Create a Boarding/Hostel fee category first.
                        <?php elseif ($search || $status_filter): ?>
                            No results match your search criteria. Try different filters.
                        <?php else: ?>
                            Use the <strong>"Charge All Boarders"</strong> button to generate boarding invoices for active hostel students.
                        <?php endif; ?>
                    </p>
                    <?php if ($boarding_cat_id && !$search && !$status_filter): ?>
                    <button onclick="document.getElementById('chargeModal').classList.remove('hidden')"
                            class="text-white font-semibold px-6 py-2.5 rounded-xl shadow transition"
                            style="background-color: #0f766e;"
                            onmouseover="this.style.backgroundColor='#115e59'" onmouseout="this.style.backgroundColor='#0f766e'">
                        <i class="fas fa-bolt mr-2"></i>Charge All Boarders
                    </button>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="bg-gray-50 border-b border-gray-200">
                            <tr class="text-xs font-bold text-gray-400 uppercase tracking-wider">
                                <th class="px-5 py-3">Invoice #</th>
                                <th class="px-5 py-3">Student</th>
                                <th class="px-5 py-3">Room / Block</th>
                                <th class="px-5 py-3">Term</th>
                                <th class="px-5 py-3 text-right">Boarding Fee</th>
                                <th class="px-5 py-3 text-right">Total Paid</th>
                                <th class="px-5 py-3 text-right">Outstanding</th>
                                <th class="px-5 py-3">Status</th>
                                <th class="px-5 py-3 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($invoices as $inv):
                                $outstanding = floatval($inv['grand_total']) - floatval($inv['amount_paid']);
                                $status_badges = [
                                    'paid'           => 'bg-emerald-100 text-emerald-800',
                                    'partially_paid' => 'bg-blue-100 text-blue-800',
                                    'overdue'        => 'bg-rose-100 text-rose-800',
                                    'pending'        => 'bg-amber-100 text-amber-800',
                                    'cancelled'      => 'bg-gray-100 text-gray-500',
                                ];
                                $badge_class = $status_badges[$inv['status']] ?? 'bg-gray-100 text-gray-600';
                            ?>
                            <tr class="hover:bg-teal-50/40 transition">
                                <td class="px-5 py-3.5 font-bold text-gray-700">
                                    <?php echo htmlspecialchars($inv['invoice_number']); ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-semibold text-gray-800"><?php echo htmlspecialchars($inv['student_name']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($inv['student_number'] ?? '—'); ?><?php if ($inv['class_name']): ?> · <?php echo htmlspecialchars($inv['class_name']); ?><?php endif; ?></div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <?php if ($inv['block_name']): ?>
                                    <div class="font-medium text-gray-700"><?php echo htmlspecialchars($inv['block_name']); ?></div>
                                    <div class="text-xs text-gray-400">Room <?php echo htmlspecialchars($inv['room_number'] ?? '—'); ?></div>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-xs italic">No active room</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-medium text-gray-700"><?php echo htmlspecialchars($inv['term_name']); ?></div>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($inv['year_name']); ?></div>
                                </td>
                                <td class="px-5 py-3.5 text-right font-bold text-gray-800">
                                    ₵<?php echo number_format($inv['boarding_amount'], 2); ?>
                                </td>
                                <td class="px-5 py-3.5 text-right font-semibold text-emerald-600">
                                    ₵<?php echo number_format($inv['amount_paid'], 2); ?>
                                </td>
                                <td class="px-5 py-3.5 text-right font-semibold <?php echo $outstanding > 0 ? 'text-rose-600' : 'text-gray-400'; ?>">
                                    <?php echo $outstanding > 0 ? '₵' . number_format($outstanding, 2) : '—'; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="px-2.5 py-1 rounded-full text-xs font-bold <?php echo $badge_class; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $inv['status'])); ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                        <?php if (!in_array($inv['status'], ['paid', 'cancelled'])): ?>
                                        <a href="../../finance/collect_payment.php?invoice_id=<?php echo $inv['id']; ?>"
                                           class="text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow transition whitespace-nowrap"
                                           style="background-color: #0f766e;"
                                           onmouseover="this.style.backgroundColor='#115e59'" onmouseout="this.style.backgroundColor='#0f766e'"
                                           title="Collect Payment">
                                            <i class="fas fa-hand-holding-usd mr-1"></i>Pay
                                        </a>
                                        <?php endif; ?>
                                        <a href="../../finance/print_invoice.php?invoice_id=<?php echo $inv['id']; ?>"
                                           target="_blank"
                                           class="bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold px-3 py-1.5 rounded-lg transition whitespace-nowrap"
                                           title="Print Invoice">
                                            <i class="fas fa-print mr-1"></i>Print
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Table footer summary -->
                <div class="px-5 py-4 bg-gray-50 border-t border-gray-200 flex flex-wrap justify-between items-center gap-3 text-sm text-gray-500">
                    <span><?php echo count($invoices); ?> record(s) shown</span>
                    <div class="flex gap-6">
                        <span>Billed: <strong class="text-gray-800">₵<?php echo number_format($stats['total_invoiced'], 2); ?></strong></span>
                        <span>Collected: <strong class="text-emerald-600">₵<?php echo number_format($stats['total_paid'], 2); ?></strong></span>
                        <span>Outstanding: <strong class="text-rose-600">₵<?php echo number_format($stats['total_outstanding'], 2); ?></strong></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
        </main>
        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- ── Charge All Boarders Modal ──────────────────────────────────────────────── -->
<div id="chargeModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="document.getElementById('chargeModal').classList.add('hidden')"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md border border-gray-100 z-10">
            <form method="POST">
                <input type="hidden" name="action" value="charge_boarders">
                <div class="p-6">
                    <!-- Modal Header -->
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-12 h-12 bg-teal-100 rounded-xl flex items-center justify-center">
                            <i class="fas fa-bolt text-teal-600 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-bold text-gray-900">Charge All Active Boarders</h3>
                            <p class="text-xs text-gray-400">This will charge all <?php echo $stats['total_boarders']; ?> active hostel students</p>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <!-- Academic Year -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Academic Year <span class="text-red-500">*</span></label>
                            <select name="year_id" id="modal_year_id" onchange="filterModalTerms()" required
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                <?php foreach ($academic_years as $yr): ?>
                                <option value="<?php echo $yr['id']; ?>" <?php echo $yr['id'] == $filter_year_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($yr['year_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Term -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Term <span class="text-red-500">*</span></label>
                            <select name="term_id" id="modal_term_id" required
                                    class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
                                <?php foreach ($academic_terms as $tm): ?>
                                <option value="<?php echo $tm['id']; ?>"
                                        data-year-id="<?php echo $tm['academic_year_id']; ?>"
                                        <?php echo $tm['id'] == $filter_term_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($tm['term_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Boarding Amount -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-1.5">Boarding Fee Amount (₵) <span class="text-red-500">*</span></label>
                            <input type="number" name="boarding_amount" min="1" step="0.01" required
                                   placeholder="e.g. 500.00"
                                   class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-teal-500 focus:outline-none">
                            <p class="text-xs text-gray-400 mt-1">This amount will be added as a <em>Hostel Boarding Fee</em> line item to each boarder's invoice for the selected term.</p>
                        </div>
                    </div>

                    <!-- Info box -->
                    <div class="mt-4 bg-teal-50 border border-teal-200 rounded-xl p-3 text-xs text-teal-700">
                        <i class="fas fa-info-circle mr-1"></i>
                        If a student already has an invoice for the selected term, the boarding fee will be <strong>added</strong> as a new line item.
                        If no invoice exists, a new one will be created. Students already charged boarding this term are <strong>skipped</strong>.
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" onclick="document.getElementById('chargeModal').classList.add('hidden')"
                            class="px-5 py-2 text-gray-600 hover:text-gray-900 font-semibold transition">Cancel</button>
                    <button type="submit"
                            class="text-white font-bold px-6 py-2 rounded-xl shadow transition"
                            style="background-color: #0f766e;"
                            onmouseover="this.style.backgroundColor='#115e59'" onmouseout="this.style.backgroundColor='#0f766e'">
                        <i class="fas fa-bolt mr-2"></i>Charge Boarders
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function filterModalTerms() {
    const yearId   = document.getElementById('modal_year_id').value;
    const termSel  = document.getElementById('modal_term_id');
    let first = null;
    Array.from(termSel.options).forEach(opt => {
        const show = opt.getAttribute('data-year-id') === yearId;
        opt.style.display = show ? '' : 'none';
        if (show && !first) first = opt;
    });
    if (first) termSel.value = first.value;
}
// Run on load
document.addEventListener('DOMContentLoaded', filterModalTerms);
</script>