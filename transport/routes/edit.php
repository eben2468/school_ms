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

$stmt = $db->prepare("SELECT * FROM transport_routes WHERE id = :id");
$stmt->bindParam(':id', $id);
$stmt->execute();
$route = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$route) {
    header("Location: index.php");
    exit();
}

$route_name             = $route['route_name'];
$route_code             = $route['route_code'];
$start_point            = $route['start_point'];
$end_point              = $route['end_point'];
$distance_km            = $route['distance_km'];
$estimated_time_minutes = $route['estimated_time_minutes'];
$status                 = $route['status'];
$description            = $route['description'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $route_name             = filter_input(INPUT_POST, 'route_name', FILTER_SANITIZE_STRING);
    $route_code             = filter_input(INPUT_POST, 'route_code', FILTER_SANITIZE_STRING);
    $start_point            = filter_input(INPUT_POST, 'start_point', FILTER_SANITIZE_STRING);
    $end_point              = filter_input(INPUT_POST, 'end_point', FILTER_SANITIZE_STRING);
    $distance_km            = filter_input(INPUT_POST, 'distance_km', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $estimated_time_minutes = filter_input(INPUT_POST, 'estimated_time_minutes', FILTER_SANITIZE_NUMBER_INT);
    $status                 = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $description            = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);

    if (empty($route_name) || empty($route_code) || empty($start_point) || empty($end_point)) {
        $error = "Route name, code, start point, and end point are required.";
    } else {
        try {
            $query = "UPDATE transport_routes SET
                        route_name = :route_name,
                        route_code = :route_code,
                        start_point = :start_point,
                        end_point = :end_point,
                        distance_km = :distance_km,
                        estimated_time_minutes = :estimated_time_minutes,
                        status = :status,
                        description = :description
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindValue(':route_name', $route_name);
            $stmt->bindValue(':route_code', $route_code);
            $stmt->bindValue(':start_point', $start_point);
            $stmt->bindValue(':end_point', $end_point);
            $stmt->bindValue(':distance_km', $distance_km ?: null);
            $stmt->bindValue(':estimated_time_minutes', $estimated_time_minutes ?: null);
            $stmt->bindValue(':status', $status);
            $stmt->bindValue(':description', $description);
            $stmt->bindValue(':id', $id);
            $stmt->execute();
            $success = "Route updated successfully.";
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = "A route with this code already exists.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

$title = "Edit Route";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Routes', 'url' => 'index.php'],
    ['title' => 'Edit Route']
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
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Edit Route</h1>
                <div class="flex space-x-4">
                    <a href="view.php?id=<?php echo $id; ?>" class="text-gray-600 hover:text-gray-800 dark:text-gray-300">
                        <i class="fas fa-eye mr-2"></i>View
                    </a>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Routes
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
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Route Information</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Update the details of this transport route.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="route_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Route Name *</label>
                            <input type="text" id="route_name" name="route_name" required
                                value="<?php echo htmlspecialchars($route_name ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="route_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Route Code *</label>
                            <input type="text" id="route_code" name="route_code" required
                                value="<?php echo htmlspecialchars($route_code ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="start_point" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Point *</label>
                            <input type="text" id="start_point" name="start_point" required
                                value="<?php echo htmlspecialchars($start_point ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="end_point" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Point *</label>
                            <input type="text" id="end_point" name="end_point" required
                                value="<?php echo htmlspecialchars($end_point ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="distance_km" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Distance (km)</label>
                            <input type="number" step="0.1" min="0" id="distance_km" name="distance_km"
                                value="<?php echo htmlspecialchars($distance_km ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="estimated_time_minutes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Estimated Time (minutes)</label>
                            <input type="number" min="0" id="estimated_time_minutes" name="estimated_time_minutes"
                                value="<?php echo htmlspecialchars($estimated_time_minutes ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <?php foreach (['active', 'inactive'] as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo ($status === $st) ? 'selected' : ''; ?>><?php echo ucfirst($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea id="description" name="description" rows="3"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($description ?? ''); ?></textarea>
                    </div>

                    <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex space-x-3">
                            <a href="index.php" class="px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium">Cancel</a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
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
