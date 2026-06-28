<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$id) {
    header("Location: index.php");
    exit();
}

// Vehicle + its route.
$stmt = $db->prepare("SELECT v.*, r.route_name, r.route_code
                      FROM transport_vehicles v
                      LEFT JOIN transport_routes r ON v.route_id = r.id
                      WHERE v.id = :id");
$stmt->bindParam(':id', $id);
$stmt->execute();
$vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$vehicle) {
    header("Location: index.php");
    exit();
}

// Current/active assignments for this vehicle.
$a_stmt = $db->prepare("SELECT a.*, r.route_name, r.route_code, d.name AS driver_name
                        FROM transport_assignments a
                        LEFT JOIN transport_routes r ON a.route_id = r.id
                        LEFT JOIN transport_drivers d ON a.driver_id = d.id
                        WHERE a.vehicle_id = :id
                        ORDER BY a.status = 'active' DESC, a.effective_date DESC");
$a_stmt->execute([':id' => $id]);
$assignments = $a_stmt->fetchAll(PDO::FETCH_ASSOC);

// Maintenance history for this vehicle.
$m_stmt = $db->prepare("SELECT * FROM transport_maintenance WHERE vehicle_id = :id ORDER BY maintenance_date DESC");
$m_stmt->execute([':id' => $id]);
$maintenance = $m_stmt->fetchAll(PDO::FETCH_ASSOC);
$maintenance_total = 0;
foreach ($maintenance as $m) { $maintenance_total += (float)$m['cost']; }

$currency = function_exists('getSchoolSetting') ? getSchoolSetting('currency_symbol', 'GHS ') : 'GHS ';

$status_badge = [
    'active'      => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    'maintenance' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    'inactive'    => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
];
$m_status_badge = [
    'completed'   => 'bg-green-100 text-green-800',
    'in_progress' => 'bg-blue-100 text-blue-800',
    'scheduled'   => 'bg-yellow-100 text-yellow-800',
    'cancelled'   => 'bg-gray-100 text-gray-800',
];

$title = "Vehicle Details";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Vehicles', 'url' => 'index.php'],
    ['title' => 'Vehicle Details']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';

function fld($v) { return ($v === null || $v === '') ? '<span class="text-gray-400 italic">Not set</span>' : htmlspecialchars($v); }
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
                <div>
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">
                        <i class="fas fa-bus text-blue-600 mr-2"></i><?php echo htmlspecialchars($vehicle['vehicle_number']); ?>
                    </h1>
                    <span class="inline-flex items-center px-3 py-1 mt-2 rounded-full text-xs font-semibold <?php echo $status_badge[$vehicle['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                        <?php echo ucfirst($vehicle['status']); ?>
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="edit.php?id=<?php echo $id; ?>" class="inline-flex items-center whitespace-nowrap bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-edit mr-2"></i>Edit
                    </a>
                    <a href="index.php" class="inline-flex items-center whitespace-nowrap text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Vehicles
                    </a>
                </div>
            </div>

            <!-- Vehicle Information -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Vehicle Information</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Type</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo ucfirst($vehicle['vehicle_type']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Make &amp; Model</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo fld($vehicle['make_model']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Year</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo fld($vehicle['year']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Seating Capacity</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo (int)$vehicle['capacity']; ?> seats</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Assigned Route</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white">
                            <?php echo $vehicle['route_name'] ? htmlspecialchars($vehicle['route_name'] . ' (' . $vehicle['route_code'] . ')') : '<span class="text-gray-400 italic">Unassigned</span>'; ?>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Added On</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($vehicle['created_at'])); ?></dd>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Driver -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-user mr-2 text-blue-500"></i>Driver</h2>
                    </div>
                    <div class="p-6 space-y-3">
                        <div class="flex justify-between"><span class="text-gray-500">Name</span><span class="text-gray-900 dark:text-white"><?php echo fld($vehicle['driver_name']); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Phone</span><span class="text-gray-900 dark:text-white"><?php echo fld($vehicle['driver_phone']); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">License</span><span class="text-gray-900 dark:text-white"><?php echo fld($vehicle['driver_license']); ?></span></div>
                    </div>
                </div>

                <!-- Insurance & Registration -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-shield-alt mr-2 text-green-500"></i>Insurance &amp; Registration</h2>
                    </div>
                    <div class="p-6 space-y-3">
                        <div class="flex justify-between"><span class="text-gray-500">Insurance No.</span><span class="text-gray-900 dark:text-white"><?php echo fld($vehicle['insurance_number']); ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Insurance Expiry</span><span class="text-gray-900 dark:text-white"><?php echo $vehicle['insurance_expiry'] ? date('M j, Y', strtotime($vehicle['insurance_expiry'])) : '<span class="text-gray-400 italic">Not set</span>'; ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Registration Expiry</span><span class="text-gray-900 dark:text-white"><?php echo $vehicle['registration_expiry'] ? date('M j, Y', strtotime($vehicle['registration_expiry'])) : '<span class="text-gray-400 italic">Not set</span>'; ?></span></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($vehicle['notes'])): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6 p-6">
                <h2 class="text-sm font-medium text-gray-500 mb-2">Notes</h2>
                <p class="text-gray-900 dark:text-white whitespace-pre-line"><?php echo htmlspecialchars($vehicle['notes']); ?></p>
            </div>
            <?php endif; ?>

            <!-- Assignments -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-route mr-2 text-purple-500"></i>Route Assignments</h2>
                </div>
                <div class="p-6">
                    <?php if (empty($assignments)): ?>
                        <p class="text-gray-500 text-center py-4">No route assignments for this vehicle.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Route</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Departure</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Return</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Effective</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($assignments as $a): ?>
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars(($a['route_name'] ?? '—') . ($a['route_code'] ? ' (' . $a['route_code'] . ')' : '')); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo fld($a['driver_name']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $a['departure_time'] ? date('g:i A', strtotime($a['departure_time'])) : '—'; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $a['return_time'] ? date('g:i A', strtotime($a['return_time'])) : '—'; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($a['effective_date'])); ?></td>
                                    <td class="px-4 py-3 text-sm"><span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $a['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Maintenance -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-tools mr-2 text-orange-500"></i>Maintenance History</h2>
                    <span class="text-sm text-gray-500">Total: <strong><?php echo htmlspecialchars($currency) . number_format($maintenance_total, 2); ?></strong></span>
                </div>
                <div class="p-6">
                    <?php if (empty($maintenance)): ?>
                        <p class="text-gray-500 text-center py-4">No maintenance records for this vehicle.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Performed By</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Cost</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($maintenance as $m): ?>
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($m['maintenance_date'])); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo ucfirst($m['maintenance_type']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($m['description']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($m['performed_by']); ?></td>
                                    <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-white"><?php echo htmlspecialchars($currency) . number_format((float)$m['cost'], 2); ?></td>
                                    <td class="px-4 py-3 text-sm"><span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $m_status_badge[$m['status']] ?? 'bg-gray-100 text-gray-800'; ?>"><?php echo ucfirst(str_replace('_', ' ', $m['status'])); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
