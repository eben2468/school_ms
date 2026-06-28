<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicle_number = filter_input(INPUT_POST, 'vehicle_number', FILTER_SANITIZE_STRING);
    $vehicle_type = filter_input(INPUT_POST, 'vehicle_type', FILTER_SANITIZE_STRING);
    $make_model = filter_input(INPUT_POST, 'make_model', FILTER_SANITIZE_STRING);
    $year = filter_input(INPUT_POST, 'year', FILTER_SANITIZE_NUMBER_INT);
    $capacity = filter_input(INPUT_POST, 'capacity', FILTER_SANITIZE_NUMBER_INT);
    $driver_name = filter_input(INPUT_POST, 'driver_name', FILTER_SANITIZE_STRING);
    $driver_phone = filter_input(INPUT_POST, 'driver_phone', FILTER_SANITIZE_STRING);
    $driver_license = filter_input(INPUT_POST, 'driver_license', FILTER_SANITIZE_STRING);
    $insurance_number = filter_input(INPUT_POST, 'insurance_number', FILTER_SANITIZE_STRING);
    $insurance_expiry = filter_input(INPUT_POST, 'insurance_expiry', FILTER_SANITIZE_STRING);
    $registration_expiry = filter_input(INPUT_POST, 'registration_expiry', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if (empty($vehicle_number) || empty($vehicle_type) || empty($capacity)) {
        $error = "Vehicle number, type, and capacity are required.";
    } elseif ($capacity < 1) {
        $error = "Vehicle capacity must be at least 1.";
    } else {
        try {
            $query = "INSERT INTO transport_vehicles (vehicle_number, vehicle_type, make_model, year, capacity, driver_name, driver_phone, driver_license, insurance_number, insurance_expiry, registration_expiry, notes) 
                     VALUES (:vehicle_number, :vehicle_type, :make_model, :year, :capacity, :driver_name, :driver_phone, :driver_license, :insurance_number, :insurance_expiry, :registration_expiry, :notes)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':vehicle_number', $vehicle_number);
            $stmt->bindParam(':vehicle_type', $vehicle_type);
            $stmt->bindParam(':make_model', $make_model);
            $stmt->bindParam(':year', $year);
            $stmt->bindParam(':capacity', $capacity);
            $stmt->bindParam(':driver_name', $driver_name);
            $stmt->bindParam(':driver_phone', $driver_phone);
            $stmt->bindParam(':driver_license', $driver_license);
            $stmt->bindParam(':insurance_number', $insurance_number);
            $stmt->bindParam(':insurance_expiry', $insurance_expiry);
            $stmt->bindParam(':registration_expiry', $registration_expiry);
            $stmt->bindParam(':notes', $notes);
            
            if ($stmt->execute()) {
                $success = "Vehicle added successfully.";
                // Clear form data
                $vehicle_number = $vehicle_type = $make_model = $driver_name = $driver_phone = $driver_license = '';
                $insurance_number = $insurance_expiry = $registration_expiry = $notes = '';
                $year = $capacity = '';
            } else {
                $error = "Failed to add vehicle. Please try again.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "A vehicle with this number already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

$vehicle_types = ['Bus', 'Van', 'Car', 'Minibus', 'Other'];
?>

<?php
$title = "Add New Vehicle";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Vehicles', 'url' => 'index.php'],
    ['title' => 'Add Vehicle']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Add New Vehicle</h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Vehicles
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Vehicle Information</h2>
                    <p class="text-gray-600 text-sm mt-1">Enter the details of the new transport vehicle.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <!-- Vehicle Basic Info -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="vehicle_number" class="block text-sm font-medium text-gray-700 mb-2">Vehicle Number *</label>
                            <input type="text" id="vehicle_number" name="vehicle_number" required
                                value="<?php echo htmlspecialchars($vehicle_number ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., ABC-123">
                        </div>

                        <div>
                            <label for="vehicle_type" class="block text-sm font-medium text-gray-700 mb-2">Vehicle Type *</label>
                            <select id="vehicle_type" name="vehicle_type" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select type...</option>
                                <?php foreach ($vehicle_types as $type): ?>
                                    <option value="<?php echo $type; ?>" 
                                        <?php echo (isset($vehicle_type) && $vehicle_type === $type) ? 'selected' : ''; ?>>
                                        <?php echo $type; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="capacity" class="block text-sm font-medium text-gray-700 mb-2">Seating Capacity *</label>
                            <input type="number" id="capacity" name="capacity" required min="1"
                                value="<?php echo htmlspecialchars($capacity ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="50">
                        </div>
                    </div>

                    <!-- Vehicle Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="make_model" class="block text-sm font-medium text-gray-700 mb-2">Make & Model</label>
                            <input type="text" id="make_model" name="make_model"
                                value="<?php echo htmlspecialchars($make_model ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., Toyota Hiace">
                        </div>

                        <div>
                            <label for="year" class="block text-sm font-medium text-gray-700 mb-2">Year</label>
                            <input type="number" id="year" name="year" min="1990" max="<?php echo date('Y') + 1; ?>"
                                value="<?php echo htmlspecialchars($year ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="<?php echo date('Y'); ?>">
                        </div>
                    </div>

                    <!-- Driver Information -->
                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Driver Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="driver_name" class="block text-sm font-medium text-gray-700 mb-2">Driver Name</label>
                                <input type="text" id="driver_name" name="driver_name"
                                    value="<?php echo htmlspecialchars($driver_name ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Full name">
                            </div>

                            <div>
                                <label for="driver_phone" class="block text-sm font-medium text-gray-700 mb-2">Driver Phone</label>
                                <input type="tel" id="driver_phone" name="driver_phone"
                                    value="<?php echo htmlspecialchars($driver_phone ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="+1234567890">
                            </div>

                            <div>
                                <label for="driver_license" class="block text-sm font-medium text-gray-700 mb-2">License Number</label>
                                <input type="text" id="driver_license" name="driver_license"
                                    value="<?php echo htmlspecialchars($driver_license ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="License number">
                            </div>
                        </div>
                    </div>

                    <!-- Insurance & Registration -->
                    <div class="border-t border-gray-200 pt-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Insurance & Registration</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="insurance_number" class="block text-sm font-medium text-gray-700 mb-2">Insurance Number</label>
                                <input type="text" id="insurance_number" name="insurance_number"
                                    value="<?php echo htmlspecialchars($insurance_number ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                    placeholder="Insurance policy number">
                            </div>

                            <div>
                                <label for="insurance_expiry" class="block text-sm font-medium text-gray-700 mb-2">Insurance Expiry</label>
                                <input type="date" id="insurance_expiry" name="insurance_expiry"
                                    value="<?php echo htmlspecialchars($insurance_expiry ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <div>
                                <label for="registration_expiry" class="block text-sm font-medium text-gray-700 mb-2">Registration Expiry</label>
                                <input type="date" id="registration_expiry" name="registration_expiry"
                                    value="<?php echo htmlspecialchars($registration_expiry ?? ''); ?>"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">Additional Notes</label>
                        <textarea id="notes" name="notes" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Any additional information about the vehicle..."><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-6 border-t border-gray-200">
                        <div class="flex flex-wrap items-center gap-3 no-stack">
                            <a href="index.php"
                               class="inline-flex items-center whitespace-nowrap px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-plus mr-2"></i>Add Vehicle
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Vehicle Management Tips -->
            <div class="bg-green-50 rounded-lg p-6 mt-6">
                <h3 class="text-lg font-semibold text-green-900 mb-3">
                    <i class="fas fa-lightbulb mr-2"></i>Vehicle Management Tips
                </h3>
                <ul class="text-green-800 space-y-2 text-sm">
                    <li><i class="fas fa-check mr-2"></i>Keep track of insurance and registration expiry dates</li>
                    <li><i class="fas fa-check mr-2"></i>Maintain regular vehicle inspection schedules</li>
                    <li><i class="fas fa-check mr-2"></i>Ensure drivers have valid licenses and proper training</li>
                    <li><i class="fas fa-check mr-2"></i>Document any maintenance or repair history</li>
                    <li><i class="fas fa-check mr-2"></i>Set up reminders for renewal dates</li>
                </ul>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
