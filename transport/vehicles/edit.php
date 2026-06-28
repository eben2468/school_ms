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

// Load the vehicle.
$stmt = $db->prepare("SELECT * FROM transport_vehicles WHERE id = :id");
$stmt->bindParam(':id', $id);
$stmt->execute();
$vehicle = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$vehicle) {
    header("Location: index.php");
    exit();
}

// Routes for the (optional) route assignment dropdown.
$routes = $db->query("SELECT id, route_name, route_code FROM transport_routes ORDER BY route_name")->fetchAll(PDO::FETCH_ASSOC);

// Current field values (overwritten by POST on submit).
$vehicle_number      = $vehicle['vehicle_number'];
$vehicle_type        = $vehicle['vehicle_type'];
$make_model          = $vehicle['make_model'];
$year                = $vehicle['year'];
$capacity            = $vehicle['capacity'];
$driver_name         = $vehicle['driver_name'];
$driver_phone        = $vehicle['driver_phone'];
$driver_license      = $vehicle['driver_license'];
$insurance_number    = $vehicle['insurance_number'];
$insurance_expiry    = $vehicle['insurance_expiry'];
$registration_expiry = $vehicle['registration_expiry'];
$route_id            = $vehicle['route_id'];
$status              = $vehicle['status'];
$notes               = $vehicle['notes'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_number      = filter_input(INPUT_POST, 'vehicle_number', FILTER_SANITIZE_STRING);
    $vehicle_type        = filter_input(INPUT_POST, 'vehicle_type', FILTER_SANITIZE_STRING);
    $make_model          = filter_input(INPUT_POST, 'make_model', FILTER_SANITIZE_STRING);
    $year                = filter_input(INPUT_POST, 'year', FILTER_SANITIZE_NUMBER_INT);
    $capacity            = filter_input(INPUT_POST, 'capacity', FILTER_SANITIZE_NUMBER_INT);
    $driver_name         = filter_input(INPUT_POST, 'driver_name', FILTER_SANITIZE_STRING);
    $driver_phone        = filter_input(INPUT_POST, 'driver_phone', FILTER_SANITIZE_STRING);
    $driver_license      = filter_input(INPUT_POST, 'driver_license', FILTER_SANITIZE_STRING);
    $insurance_number    = filter_input(INPUT_POST, 'insurance_number', FILTER_SANITIZE_STRING);
    $insurance_expiry    = filter_input(INPUT_POST, 'insurance_expiry', FILTER_SANITIZE_STRING);
    $registration_expiry = filter_input(INPUT_POST, 'registration_expiry', FILTER_SANITIZE_STRING);
    $route_id            = filter_input(INPUT_POST, 'route_id', FILTER_SANITIZE_NUMBER_INT);
    $status              = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $notes               = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

    if (empty($vehicle_number) || empty($vehicle_type) || empty($capacity)) {
        $error = "Vehicle number, type, and capacity are required.";
    } elseif ($capacity < 1) {
        $error = "Vehicle capacity must be at least 1.";
    } else {
        try {
            $query = "UPDATE transport_vehicles SET
                        vehicle_number = :vehicle_number,
                        vehicle_type = :vehicle_type,
                        make_model = :make_model,
                        year = :year,
                        capacity = :capacity,
                        driver_name = :driver_name,
                        driver_phone = :driver_phone,
                        driver_license = :driver_license,
                        insurance_number = :insurance_number,
                        insurance_expiry = :insurance_expiry,
                        registration_expiry = :registration_expiry,
                        route_id = :route_id,
                        status = :status,
                        notes = :notes
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':vehicle_number', $vehicle_number);
            $stmt->bindValue(':vehicle_type', $vehicle_type);
            $stmt->bindValue(':make_model', $make_model);
            $stmt->bindValue(':year', $year ?: null);
            $stmt->bindValue(':capacity', $capacity);
            $stmt->bindValue(':driver_name', $driver_name);
            $stmt->bindValue(':driver_phone', $driver_phone);
            $stmt->bindValue(':driver_license', $driver_license);
            $stmt->bindValue(':insurance_number', $insurance_number);
            $stmt->bindValue(':insurance_expiry', $insurance_expiry ?: null);
            $stmt->bindValue(':registration_expiry', $registration_expiry ?: null);
            $stmt->bindValue(':route_id', $route_id ?: null);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':notes', $notes);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $success = "Vehicle updated successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "A vehicle with this number already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Vehicle types: keep within the DB enum (bus/van/car) but include the current
// value if it ever differs, so editing never silently drops it.
$vehicle_types = ['bus', 'van', 'car'];
if ($vehicle_type && !in_array(strtolower($vehicle_type), $vehicle_types, true)) {
    $vehicle_types[] = $vehicle_type;
}

$title = "Edit Vehicle";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Vehicles', 'url' => 'index.php'],
    ['title' => 'Edit Vehicle']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Edit Vehicle</h1>
                <div class="flex flex-wrap items-center gap-4 no-stack">
                    <a href="view.php?id=<?php echo $id; ?>" class="inline-flex items-center whitespace-nowrap text-gray-600 hover:text-gray-800 dark:text-gray-300">
                        <i class="fas fa-eye mr-2"></i>View
                    </a>
                    <a href="index.php" class="inline-flex items-center whitespace-nowrap text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Vehicles
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Vehicle Information</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Update the details of this transport vehicle.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="vehicle_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vehicle Number *</label>
                            <input type="text" id="vehicle_number" name="vehicle_number" required
                                value="<?php echo htmlspecialchars($vehicle_number ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="vehicle_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Vehicle Type *</label>
                            <select id="vehicle_type" name="vehicle_type" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach ($vehicle_types as $type): ?>
                                    <option value="<?php echo htmlspecialchars($type); ?>" <?php echo (strtolower($vehicle_type) === strtolower($type)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(ucfirst($type)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="capacity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Seating Capacity *</label>
                            <input type="number" id="capacity" name="capacity" required min="1"
                                value="<?php echo htmlspecialchars($capacity ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="make_model" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Make & Model</label>
                            <input type="text" id="make_model" name="make_model"
                                value="<?php echo htmlspecialchars($make_model ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="year" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Year</label>
                            <input type="number" id="year" name="year" min="1990" max="<?php echo date('Y') + 1; ?>"
                                value="<?php echo htmlspecialchars($year ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach (['active', 'maintenance', 'inactive'] as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo ($status === $st) ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="route_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assigned Route</label>
                        <select id="route_id" name="route_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">— No route —</option>
                            <?php foreach ($routes as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo ((int)$route_id === (int)$r['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['route_name'] . ' (' . $r['route_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Driver Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="driver_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Driver Name</label>
                                <input type="text" id="driver_name" name="driver_name"
                                    value="<?php echo htmlspecialchars($driver_name ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="driver_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Driver Phone</label>
                                <input type="tel" id="driver_phone" name="driver_phone"
                                    value="<?php echo htmlspecialchars($driver_phone ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="driver_license" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">License Number</label>
                                <input type="text" id="driver_license" name="driver_license"
                                    value="<?php echo htmlspecialchars($driver_license ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Insurance & Registration</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="insurance_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Insurance Number</label>
                                <input type="text" id="insurance_number" name="insurance_number"
                                    value="<?php echo htmlspecialchars($insurance_number ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="insurance_expiry" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Insurance Expiry</label>
                                <input type="date" id="insurance_expiry" name="insurance_expiry"
                                    value="<?php echo htmlspecialchars($insurance_expiry ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div>
                                <label for="registration_expiry" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Registration Expiry</label>
                                <input type="date" id="registration_expiry" name="registration_expiry"
                                    value="<?php echo htmlspecialchars($registration_expiry ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Additional Notes</label>
                        <textarea id="notes" name="notes" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
                    </div>

                    <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-3 no-stack">
                            <a href="index.php" class="inline-flex items-center whitespace-nowrap px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium">Cancel</a>
                            <button type="submit" class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
