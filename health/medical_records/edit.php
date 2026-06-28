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

// Fetch record
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
    $visit_date = filter_input(INPUT_POST, 'visit_date', FILTER_SANITIZE_STRING);
    $visit_time = filter_input(INPUT_POST, 'visit_time', FILTER_SANITIZE_STRING);
    $record_type = filter_input(INPUT_POST, 'record_type', FILTER_SANITIZE_STRING) ?: 'illness';
    
    $temperature_f = filter_input(INPUT_POST, 'temperature_f', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $blood_pressure = filter_input(INPUT_POST, 'blood_pressure', FILTER_SANITIZE_STRING);
    $pulse_rate = filter_input(INPUT_POST, 'pulse_rate', FILTER_SANITIZE_NUMBER_INT);
    
    $complaint = filter_input(INPUT_POST, 'complaint', FILTER_SANITIZE_STRING);
    $symptoms = filter_input(INPUT_POST, 'symptoms', FILTER_SANITIZE_STRING);
    $treatment = filter_input(INPUT_POST, 'treatment', FILTER_SANITIZE_STRING);
    $medication = filter_input(INPUT_POST, 'medication', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'active';
    
    $follow_up_date = filter_input(INPUT_POST, 'follow_up_date', FILTER_SANITIZE_STRING) ?: null;
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if ($visit_date) {
        try {
            $query = "UPDATE health_records SET 
                        record_date = :record_date,
                        visit_date = :visit_date, 
                        visit_time = :visit_time, 
                        record_type = :record_type, 
                        temperature_f = :temperature_f, 
                        temperature = :temperature,
                        blood_pressure = :blood_pressure, 
                        pulse_rate = :pulse_rate, 
                        complaint = :complaint, 
                        description = :description,
                        symptoms = :symptoms, 
                        treatment = :treatment, 
                        medication = :medication, 
                        medications = :medications,
                        status = :status, 
                        follow_up_date = :follow_up_date, 
                        notes = :notes
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':record_date', $visit_date);
            $stmt->bindParam(':visit_date', $visit_date);
            $stmt->bindParam(':visit_time', $visit_time);
            $stmt->bindParam(':record_type', $record_type);
            $stmt->bindParam(':temperature_f', $temperature_f);
            $stmt->bindParam(':temperature', $temperature_f);
            $stmt->bindParam(':blood_pressure', $blood_pressure);
            $stmt->bindParam(':pulse_rate', $pulse_rate);
            $stmt->bindParam(':complaint', $complaint);
            $stmt->bindParam(':description', $complaint);
            $stmt->bindParam(':symptoms', $symptoms);
            $stmt->bindParam(':treatment', $treatment);
            $stmt->bindParam(':medication', $medication);
            $stmt->bindParam(':medications', $medication);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':follow_up_date', $follow_up_date);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':id', $record_id);
            
            $stmt->execute();
            
            $success = "Clinic visit record updated successfully!";
            
            // Refresh record info
            $record['visit_date'] = $visit_date;
            $record['visit_time'] = $visit_time;
            $record['record_type'] = $record_type;
            $record['temperature_f'] = $temperature_f;
            $record['blood_pressure'] = $blood_pressure;
            $record['pulse_rate'] = $pulse_rate;
            $record['complaint'] = $complaint;
            $record['symptoms'] = $symptoms;
            $record['treatment'] = $treatment;
            $record['medication'] = $medication;
            $record['status'] = $status;
            $record['follow_up_date'] = $follow_up_date;
            $record['notes'] = $notes;
            
        } catch (PDOException $e) {
            $error = "Error updating visit record: " . $e->getMessage();
        }
    } else {
        $error = "Visit Date is a required field.";
    }
}

$title = "Edit Clinic Visit";
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
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Clinic Visit</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Editing visit log for <?php echo htmlspecialchars($record['student_name']); ?></p>
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
                            
                            <!-- Student and date/time -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Student</label>
                                    <input type="text" value="<?php echo htmlspecialchars($record['student_name']); ?>" disabled 
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300">
                                </div>

                                <div>
                                    <label for="visit_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Date *</label>
                                    <input type="date" id="visit_date" name="visit_date" value="<?php echo htmlspecialchars($record['visit_date']); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="visit_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Time *</label>
                                    <input type="time" id="visit_time" name="visit_time" value="<?php echo htmlspecialchars(date('H:i', strtotime($record['visit_time'] ?? '00:00:00'))); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Vitals Section -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Vital Signs</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                                    <div>
                                        <label for="temperature_f" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temperature (°F)</label>
                                        <input type="number" id="temperature_f" name="temperature_f" step="0.1" min="90" max="110" value="<?php echo htmlspecialchars($record['temperature_f']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label for="blood_pressure" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blood Pressure</label>
                                        <input type="text" id="blood_pressure" name="blood_pressure" placeholder="120/80" value="<?php echo htmlspecialchars($record['blood_pressure']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="pulse_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pulse Rate (bpm)</label>
                                        <input type="number" id="pulse_rate" name="pulse_rate" min="40" max="200" value="<?php echo htmlspecialchars($record['pulse_rate']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <!-- Visit Details -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-notes-medical text-blue-500 mr-2"></i>Visit Details</h3>
                                <div class="space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label for="record_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Category *</label>
                                            <select id="record_type" name="record_type" required
                                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                                <option value="illness" <?php echo $record['record_type'] === 'illness' ? 'selected' : ''; ?>>Illness / Sickness</option>
                                                <option value="injury" <?php echo $record['record_type'] === 'injury' ? 'selected' : ''; ?>>Injury / First Aid</option>
                                                <option value="checkup" <?php echo $record['record_type'] === 'checkup' ? 'selected' : ''; ?>>Routine Checkup</option>
                                                <option value="vaccination" <?php echo $record['record_type'] === 'vaccination' ? 'selected' : ''; ?>>Vaccination</option>
                                                <option value="allergy" <?php echo $record['record_type'] === 'allergy' ? 'selected' : ''; ?>>Allergic Reaction</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                                            <select id="status" name="status" required
                                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                                <option value="active" <?php echo $record['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                                <option value="resolved" <?php echo $record['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                                <option value="referred" <?php echo $record['status'] === 'referred' ? 'selected' : ''; ?>>Referred</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="complaint" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Chief Complaint *</label>
                                        <input type="text" id="complaint" name="complaint" value="<?php echo htmlspecialchars($record['complaint']); ?>" required
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="symptoms" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Symptoms & Observations</label>
                                        <textarea id="symptoms" name="symptoms" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Detailed description of symptoms"><?php echo htmlspecialchars($record['symptoms']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="treatment" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Treatment Administered</label>
                                        <textarea id="treatment" name="treatment" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Treatment given"><?php echo htmlspecialchars($record['treatment']); ?></textarea>
                                    </div>

                                    <div>
                                        <label for="medication" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Medication Given / Prescribed</label>
                                        <input type="text" id="medication" name="medication" value="<?php echo htmlspecialchars($record['medication']); ?>"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label for="follow_up_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Date</label>
                                            <input type="date" id="follow_up_date" name="follow_up_date" value="<?php echo htmlspecialchars($record['follow_up_date'] ?? ''); ?>"
                                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        </div>

                                        <div>
                                            <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Remarks / Notes</label>
                                            <input type="text" id="notes" name="notes" value="<?php echo htmlspecialchars($record['notes']); ?>"
                                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="view.php?id=<?php echo $record['id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg font-medium">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
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
