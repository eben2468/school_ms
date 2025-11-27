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
    $route_name = filter_input(INPUT_POST, 'route_name', FILTER_SANITIZE_STRING);
    $route_code = filter_input(INPUT_POST, 'route_code', FILTER_SANITIZE_STRING);
    $start_point = filter_input(INPUT_POST, 'start_point', FILTER_SANITIZE_STRING);
    $end_point = filter_input(INPUT_POST, 'end_point', FILTER_SANITIZE_STRING);
    $distance_km = filter_input(INPUT_POST, 'distance_km', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $estimated_time_minutes = filter_input(INPUT_POST, 'estimated_time_minutes', FILTER_SANITIZE_NUMBER_INT);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    
    if (empty($route_name) || empty($route_code) || empty($start_point) || empty($end_point)) {
        $error = "Route name, code, start point, and end point are required.";
    } else {
        try {
            $query = "INSERT INTO transport_routes (route_name, route_code, start_point, end_point, distance_km, estimated_time_minutes, description) 
                     VALUES (:route_name, :route_code, :start_point, :end_point, :distance_km, :estimated_time_minutes, :description)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':route_name', $route_name);
            $stmt->bindParam(':route_code', $route_code);
            $stmt->bindParam(':start_point', $start_point);
            $stmt->bindParam(':end_point', $end_point);
            $stmt->bindParam(':distance_km', $distance_km);
            $stmt->bindParam(':estimated_time_minutes', $estimated_time_minutes);
            $stmt->bindParam(':description', $description);
            
            if ($stmt->execute()) {
                $success = "Transport route created successfully.";
                // Clear form data
                $route_name = $route_code = $start_point = $end_point = $description = '';
                $distance_km = $estimated_time_minutes = '';
            } else {
                $error = "Failed to create route. Please try again.";
            }
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "A route with this code already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}
?>

<?php
$title = "Create Transport Route";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Routes', 'url' => 'index.php'],
    ['title' => 'Create Route']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Create Transport Route</h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Routes
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
                    <h2 class="text-xl font-semibold text-gray-900">Route Information</h2>
                    <p class="text-gray-600 text-sm mt-1">Enter the details of the new transport route.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <!-- Basic Information -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="route_name" class="block text-sm font-medium text-gray-700 mb-2">Route Name *</label>
                            <input type="text" id="route_name" name="route_name" required
                                value="<?php echo htmlspecialchars($route_name ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., Downtown to School">
                        </div>

                        <div>
                            <label for="route_code" class="block text-sm font-medium text-gray-700 mb-2">Route Code *</label>
                            <input type="text" id="route_code" name="route_code" required
                                value="<?php echo htmlspecialchars($route_code ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., RT001">
                        </div>
                    </div>

                    <!-- Route Points -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="start_point" class="block text-sm font-medium text-gray-700 mb-2">Start Point *</label>
                            <input type="text" id="start_point" name="start_point" required
                                value="<?php echo htmlspecialchars($start_point ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Starting location">
                        </div>

                        <div>
                            <label for="end_point" class="block text-sm font-medium text-gray-700 mb-2">End Point *</label>
                            <input type="text" id="end_point" name="end_point" required
                                value="<?php echo htmlspecialchars($end_point ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="Destination location">
                        </div>
                    </div>

                    <!-- Route Details -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="distance_km" class="block text-sm font-medium text-gray-700 mb-2">Distance (km)</label>
                            <input type="number" id="distance_km" name="distance_km" step="0.1" min="0"
                                value="<?php echo htmlspecialchars($distance_km ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="0.0">
                        </div>

                        <div>
                            <label for="estimated_time_minutes" class="block text-sm font-medium text-gray-700 mb-2">Estimated Time (minutes)</label>
                            <input type="number" id="estimated_time_minutes" name="estimated_time_minutes" min="0"
                                value="<?php echo htmlspecialchars($estimated_time_minutes ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="30">
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="description" name="description" rows="4"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Additional route information, stops, or special instructions..."><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-6 border-t border-gray-200">
                        <div class="flex space-x-3">
                            <a href="index.php" 
                               class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                                Cancel
                            </a>
                            <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-plus mr-2"></i>Create Route
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Route Planning Tips -->
            <div class="bg-blue-50 rounded-lg p-6 mt-6">
                <h3 class="text-lg font-semibold text-blue-900 mb-3">
                    <i class="fas fa-lightbulb mr-2"></i>Route Planning Tips
                </h3>
                <ul class="text-blue-800 space-y-2 text-sm">
                    <li><i class="fas fa-check mr-2"></i>Use unique route codes for easy identification (e.g., RT001, RT002)</li>
                    <li><i class="fas fa-check mr-2"></i>Include major landmarks in start and end points for clarity</li>
                    <li><i class="fas fa-check mr-2"></i>Consider traffic patterns when estimating travel time</li>
                    <li><i class="fas fa-check mr-2"></i>Add intermediate stops in the description if needed</li>
                    <li><i class="fas fa-check mr-2"></i>Regularly review and update route information</li>
                </ul>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
