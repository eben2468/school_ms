<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$record_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$record_id) {
    header("Location: index.php");
    exit();
}

// Fetch existing record
$query = "SELECT hr.*, u.name as student_name FROM health_records hr
          JOIN users u ON hr.student_id = u.id
          WHERE hr.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $record_id);
$stmt->execute();
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
    
    if ($record_date) {
        try {
            $query = "UPDATE health_records SET 
                        record_date = :record_date, 
                        height_cm = :height_cm, 
                        weight_kg = :weight_kg, 
                        blood_pressure = :blood_pressure, 
                        temperature_f = :temperature_f,
                        temperature = :temperature_f,
                        pulse_rate = :pulse_rate, 
                        medical_conditions = :medical_conditions, 
                        allergies = :allergies, 
                        medications = :medications, 
                        medication = :medications,
                        vaccination_status = :vaccination_status, 
                        notes = :notes
                      WHERE id = :id";
            $stmt = $db->prepare($query);
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
            $stmt->bindParam(':id', $record_id);
            $stmt->execute();
            
            // Sync medical conditions with student_profiles
            if (!empty($medical_conditions)) {
                $sync_query = "UPDATE student_profiles SET medical_conditions = :medical_conditions WHERE user_id = :student_id";
                $sync_stmt = $db->prepare($sync_query);
                $sync_stmt->bindParam(':medical_conditions', $medical_conditions);
                $sync_stmt->bindParam(':student_id', $record['student_id']);
                $sync_stmt->execute();
            }
            
            $success = "Health record updated successfully!";
            // Refresh record info
            $record['record_date'] = $record_date;
            $record['height_cm'] = $height_cm;
            $record['weight_kg'] = $weight_kg;
            $record['blood_pressure'] = $blood_pressure;
            $record['temperature_f'] = $temperature_f;
            $record['pulse_rate'] = $pulse_rate;
            $record['medical_conditions'] = $medical_conditions;
            $record['allergies'] = $allergies;
            $record['medications'] = $medications;
            $record['vaccination_status'] = $vaccination_status;
            $record['notes'] = $notes;
            
        } catch (PDOException $e) {
            $error = "Error updating record: " . $e->getMessage();
        }
    } else {
        $error = "Record Date is a required field.";
    }
}

$title = "Edit Health Record";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Health Record</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Editing assessment for <?php echo htmlspecialchars($record['student_name']); ?></p>
                    </div>
                    <a href="view.php?id=<?php echo $record['id']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Details
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

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Student</label>
                                    <input type="text" value="<?php echo htmlspecialchars($record['student_name']); ?>" disabled 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                </div>

                                <div>
                                    <label for="record_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Record Date *</label>
                                    <input type="date" id="record_date" name="record_date" value="<?php echo htmlspecialchars($record['record_date']); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Vital Signs</h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                                    <div>
                                        <label for="height_cm" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Height (cm)</label>
                                        <input type="number" id="height_cm" name="height_cm" step="0.1" min="0" value="<?php echo htmlspecialchars($record['height_cm']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="weight_kg" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Weight (kg)</label>
                                        <input type="number" id="weight_kg" name="weight_kg" step="0.1" min="0" value="<?php echo htmlspecialchars($record['weight_kg']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="blood_pressure" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blood Pressure</label>
                                        <input type="text" id="blood_pressure" name="blood_pressure" placeholder="120/80" value="<?php echo htmlspecialchars($record['blood_pressure']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="temperature_f" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temperature (°F)</label>
                                        <input type="number" id="temperature_f" name="temperature_f" step="0.1" min="90" max="110" value="<?php echo htmlspecialchars($record['temperature_f']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="pulse_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pulse Rate (bpm)</label>
                                        <input type="number" id="pulse_rate" name="pulse_rate" min="40" max="200" value="<?php echo htmlspecialchars($record['pulse_rate']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-file-medical text-blue-500 mr-2"></i>Medical Information</h3>
                                <div class="space-y-4">
                                    <div>
                                        <label for="medical_conditions" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Medical Conditions</label>
                                        <textarea id="medical_conditions" name="medical_conditions" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Any existing medical conditions"><?php echo htmlspecialchars($record['medical_conditions']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="allergies" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Allergies</label>
                                        <textarea id="allergies" name="allergies" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Known allergies"><?php echo htmlspecialchars($record['allergies']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="medications" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Medications</label>
                                        <textarea id="medications" name="medications" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Current medications and dosages"><?php echo htmlspecialchars($record['medications']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="vaccination_status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Vaccination Status</label>
                                        <textarea id="vaccination_status" name="vaccination_status" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Vaccination history and status"><?php echo htmlspecialchars($record['vaccination_status']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Notes</label>
                                        <textarea id="notes" name="notes" rows="3"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Any additional observations or notes"><?php echo htmlspecialchars($record['notes']); ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="view.php?id=<?php echo $record['id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
