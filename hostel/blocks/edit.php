<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$block_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$block_id) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

// Handle block updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $block_type = filter_input(INPUT_POST, 'block_type', FILTER_SANITIZE_STRING);
    $warden_id = filter_input(INPUT_POST, 'warden_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

    if ($name) {
        try {
            $query = "UPDATE hostel_blocks 
                      SET name = :name, description = :description, block_type = :block_type, warden_id = :warden_id, status = :status 
                      WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':block_type', $block_type);
            $stmt->bindParam(':warden_id', $warden_id);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':id', $block_id);
            
            if ($stmt->execute()) {
                $success = "Hostel block updated successfully!";
            } else {
                $error = "Failed to update block.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Block Name is required.";
    }
}

// Fetch block details
$query = "SELECT * FROM hostel_blocks WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $block_id);
$stmt->execute();
$block = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$block) {
    header("Location: index.php");
    exit();
}

// Get wardens for dropdown
$wardens_query = "SELECT id, name FROM users WHERE role IN ('hostel_warden', 'super_admin', 'school_admin') ORDER BY name";
$wardens_stmt = $db->query($wardens_query);
$wardens = $wardens_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Edit Block - " . htmlspecialchars($block['name']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Blocks', 'url' => 'index.php'],
    ['title' => 'Edit Block']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-8 flex-grow">
        <div class="max-w-4xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Hostel Block</h1>
                <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
            </div>

            <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Form Card -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                <div class="p-6">
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Block Name *</label>
                                <input type="text" id="name" name="name" required value="<?php echo htmlspecialchars($block['name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <div>
                                <label for="block_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Block Type</label>
                                <select id="block_type" name="block_type"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="boys" <?php echo $block['block_type'] === 'boys' ? 'selected' : ''; ?>>Boys</option>
                                    <option value="girls" <?php echo $block['block_type'] === 'girls' ? 'selected' : ''; ?>>Girls</option>
                                    <option value="mixed" <?php echo $block['block_type'] === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                                    <option value="staff" <?php echo $block['block_type'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                </select>
                            </div>

                            <div>
                                <label for="warden_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Assign Warden</label>
                                <select id="warden_id" name="warden_id"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Warden</option>
                                    <?php foreach ($wardens as $warden): ?>
                                        <option value="<?php echo $warden['id']; ?>" <?php echo $block['warden_id'] == $warden['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($warden['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                <select id="status" name="status"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="active" <?php echo $block['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $block['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="maintenance" <?php echo $block['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                            <textarea id="description" name="description" rows="3"
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                      placeholder="Enter block description..."><?php echo htmlspecialchars($block['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg transition">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
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
