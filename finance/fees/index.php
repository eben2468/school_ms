<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['accountant', 'super_admin', 'school_admin'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Fees Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Fees Management</h1>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    <i class="fas fa-plus mr-2"></i> Add New Fee
                </a>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <form class="flex flex-col md:flex-row gap-4">
                        <div class="flex-grow">
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 mb-1">Academic Year</label>
                            <select id="academic_year" name="academic_year" class="w-full px-4 py-2 border rounded-lg">
                                <?php
                                $current_year = date('Y');
                                for ($i = 0; $i < 3; $i++) {
                                    $year = $current_year - $i;
                                    $academic_year = $year . '-' . ($year + 1);
                                    echo "<option value='$academic_year'>$academic_year</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="flex-grow">
                            <label for="fee_type" class="block text-sm font-medium text-gray-700 mb-1">Fee Type</label>
                            <select id="fee_type" name="fee_type" class="w-full px-4 py-2 border rounded-lg">
                                <option value="">All Types</option>
                                <option value="tuition">Tuition</option>
                                <option value="library">Library</option>
                                <option value="laboratory">Laboratory</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="flex-grow">
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Payment Status</label>
                            <select id="status" name="status" class="w-full px-4 py-2 border rounded-lg">
                                <option value="">All Status</option>
                                <option value="paid">Paid</option>
                                <option value="unpaid">Unpaid</option>
                                <option value="partial">Partial</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-indigo-500 hover:bg-indigo-600 text-white px-6 py-2 rounded-lg">
                                <i class="fas fa-filter mr-2"></i> Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Fees Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Fee Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $query = "SELECT f.*, u.name as student_name 
                                    FROM fees f 
                                    JOIN users u ON f.student_id = u.id 
                                    WHERE (:academic_year IS NULL OR f.academic_year = :academic_year)
                                    AND (:fee_type IS NULL OR f.fee_type = :fee_type)
                                    AND (:status IS NULL OR f.status = :status)
                                    ORDER BY f.due_date DESC";
                            
                            $stmt = $db->prepare($query);
                            $academic_year = isset($_GET['academic_year']) ? $_GET['academic_year'] : null;
                            $fee_type = isset($_GET['fee_type']) ? $_GET['fee_type'] : null;
                            $status = isset($_GET['status']) ? $_GET['status'] : null;
                            
                            $stmt->bindParam(':academic_year', $academic_year);
                            $stmt->bindParam(':fee_type', $fee_type);
                            $stmt->bindParam(':status', $status);
                            $stmt->execute();

                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                            ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($row['student_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap capitalize"><?php echo htmlspecialchars($row['fee_type']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">$<?php echo number_format($row['amount'], 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap"><?php echo date('M d, Y', strtotime($row['due_date'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php
                                        switch($row['status']) {
                                            case 'paid':
                                                echo 'bg-green-100 text-green-800';
                                                break;
                                            case 'unpaid':
                                                echo 'bg-red-100 text-red-800';
                                                break;
                                            case 'partial':
                                                echo 'bg-yellow-100 text-yellow-800';
                                                break;
                                        }
                                        ?>">
                                        <?php echo ucfirst($row['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <a href="view.php?id=<?php echo $row['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                    <a href="edit.php?id=<?php echo $row['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                    <a href="../payments/create.php?fee_id=<?php echo $row['id']; ?>" class="text-green-600 hover:text-green-900">Record Payment</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                    <div class="flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            Showing <span class="font-medium">1</span> to <span class="font-medium">10</span> of <span class="font-medium">20</span> results
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    Previous
                                </a>
                                <a href="#" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>
                                <a href="#" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">2</a>
                                <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                    Next
                                </a>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>