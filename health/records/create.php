<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $record_date = filter_input(INPUT_POST, 'record_date', FILTER_SANITIZE_STRING);
    $height_cm = filter_input(INPUT_POST, 'height_cm', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $weight_kg = filter_input(INPUT_POST, 'weight_kg', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $blood_pressure = filter_input(INPUT_POST, 'blood_pressure', FILTER_SANITIZE_STRING);
    $temperature_f = filter_input(INPUT_POST, 'temperature_f', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $pulse_rate = filter_input(INPUT_POST, 'pulse_rate', FILTER_SANITIZE_NUMBER_INT);
    $medical_conditions = filter_input(INPUT_POST, 'medical_conditions', FILTER_SANITIZE_STRING);
    $allergies = filter_input(INPUT_POST, 'allergies', FILTER_SANITIZE_STRING);
    $medications = filter_input(INPUT_POST, 'medications', FILTER_SANITIZE_STRING);
    $vaccination_status = filter_input(INPUT_POST, 'vaccination_status', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if ($student_id && $record_date) {
        try {
            $query = "INSERT INTO health_records (student_id, record_date, height_cm, weight_kg, blood_pressure, temperature_f, pulse_rate, medical_conditions, allergies, medications, vaccination_status, notes, recorded_by, created_by, created_at) 
                     VALUES (:student_id, :record_date, :height_cm, :weight_kg, :blood_pressure, :temperature_f, :pulse_rate, :medical_conditions, :allergies, :medications, :vaccination_status, :notes, :recorded_by, :created_by, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':record_date', $record_date);
            $stmt->bindParam(':height_cm', $height_cm);
            $stmt->bindParam(':weight_kg', $weight_kg);
            $stmt->bindParam(':blood_pressure', $blood_pressure);
            $stmt->bindParam(':temperature_f', $temperature_f);
            $stmt->bindParam(':pulse_rate', $pulse_rate);
            $stmt->bindParam(':medical_conditions', $medical_conditions);
            $stmt->bindParam(':allergies', $allergies);
            $stmt->bindParam(':medications', $medications);
            $stmt->bindParam(':vaccination_status', $vaccination_status);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':recorded_by', $_SESSION['user_id']);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();
            
            // Sync medical conditions with student_profiles
            if (!empty($medical_conditions)) {
                $sync_query = "UPDATE student_profiles SET medical_conditions = :medical_conditions WHERE user_id = :student_id";
                $sync_stmt = $db->prepare($sync_query);
                $sync_stmt->bindParam(':medical_conditions', $medical_conditions);
                $sync_stmt->bindParam(':student_id', $student_id);
                $sync_stmt->execute();
            }
            
            $success = "Health record created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating record: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get students
$students_query = "SELECT u.id, u.name, sp.student_id FROM users u 
                   LEFT JOIN student_profiles sp ON u.id = sp.user_id 
                   WHERE u.role = 'student' AND u.status = 'active' 
                   ORDER BY u.name";
$students_stmt = $db->query($students_query);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Health Record";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Health', 'url' => '../index.php'],
    ['title' => 'Records', 'url' => 'index.php'],
    ['title' => 'Create Record']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 64px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Health Record</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
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

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <!-- Student and Date -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student *</label>
                                    <select id="student_id" name="student_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                                <?php if ($student['student_id']): ?>
                                                    (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="record_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Record Date *</label>
                                    <input type="date" id="record_date" name="record_date" value="<?php echo date('Y-m-d'); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Vital Signs -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Vital Signs</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                    <div>
                                        <label for="height_cm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Height (cm)</label>
                                        <input type="number" id="height_cm" name="height_cm" step="0.1" min="0"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="weight_kg" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Weight (kg)</label>
                                        <input type="number" id="weight_kg" name="weight_kg" step="0.1" min="0"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="blood_pressure" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blood Pressure</label>
                                        <input type="text" id="blood_pressure" name="blood_pressure" placeholder="120/80"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="temperature_f" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temperature (°F)</label>
                                        <input type="number" id="temperature_f" name="temperature_f" step="0.1" min="90" max="110"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="pulse_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pulse Rate (bpm)</label>
                                        <input type="number" id="pulse_rate" name="pulse_rate" min="40" max="200"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <!-- Medical Information -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Medical Information</h3>
                                <div class="space-y-4">
                                    <div>
                                        <label for="medical_conditions" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Medical Conditions</label>
                                        <textarea id="medical_conditions" name="medical_conditions" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                  placeholder="Any existing medical conditions"></textarea>
                                    </div>

                                    <div>
                                        <label for="allergies" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Allergies</label>
                                        <textarea id="allergies" name="allergies" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                  placeholder="Known allergies"></textarea>
                                    </div>

                                    <div>
                                        <label for="medications" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Medications</label>
                                        <textarea id="medications" name="medications" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                  placeholder="Current medications and dosages"></textarea>
                                    </div>

                                    <div>
                                        <label for="vaccination_status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vaccination Status</label>
                                        <textarea id="vaccination_status" name="vaccination_status" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                  placeholder="Vaccination history and status"></textarea>
                                    </div>

                                    <div>
                                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Notes</label>
                                        <textarea id="notes" name="notes" rows="3"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                  placeholder="Any additional observations or notes"></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Record
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

<script>
// Calculate BMI when height and weight are entered
function calculateBMI() {
    const height = parseFloat(document.getElementById('height_cm').value);
    const weight = parseFloat(document.getElementById('weight_kg').value);
    
    if (height && weight) {
        const heightM = height / 100;
        const bmi = weight / (heightM * heightM);
        
        // You could display BMI here if needed
        console.log('BMI:', bmi.toFixed(1));
    }
}

document.getElementById('height_cm').addEventListener('input', calculateBMI);
document.getElementById('weight_kg').addEventListener('input', calculateBMI);
</script>
