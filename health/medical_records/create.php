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
    
    if ($student_id && $visit_date) {
        try {
            $query = "INSERT INTO health_records 
                        (student_id, record_date, visit_date, visit_time, record_type, 
                         temperature_f, temperature, blood_pressure, pulse_rate, 
                         complaint, description, symptoms, treatment, medication, medications, 
                         status, follow_up_date, notes, recorded_by, created_by, created_at) 
                      VALUES 
                        (:student_id, :record_date, :visit_date, :visit_time, :record_type, 
                         :temperature_f, :temperature, :blood_pressure, :pulse_rate, 
                         :complaint, :description, :symptoms, :treatment, :medication, :medications, 
                         :status, :follow_up_date, :notes, :recorded_by, :created_by, NOW())";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':record_date', $visit_date); // record_date gets same as visit_date
            $stmt->bindParam(':visit_date', $visit_date);
            $stmt->bindParam(':visit_time', $visit_time);
            $stmt->bindParam(':record_type', $record_type);
            $stmt->bindParam(':temperature_f', $temperature_f);
            $stmt->bindParam(':temperature', $temperature_f); // temperature gets same as temperature_f
            $stmt->bindParam(':blood_pressure', $blood_pressure);
            $stmt->bindParam(':pulse_rate', $pulse_rate);
            $stmt->bindParam(':complaint', $complaint);
            $stmt->bindParam(':description', $complaint); // description gets same as complaint
            $stmt->bindParam(':symptoms', $symptoms);
            $stmt->bindParam(':treatment', $treatment);
            $stmt->bindParam(':medication', $medication);
            $stmt->bindParam(':medications', $medication); // medications gets same as medication
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':follow_up_date', $follow_up_date);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':recorded_by', $_SESSION['user_id']);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            
            $stmt->execute();
            $success = "Clinic visit record created successfully!";
        } catch (PDOException $e) {
            $error = "Error logging clinic visit: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get students for dropdown
$students_query = "SELECT u.id, u.name, sp.student_id FROM users u 
                   LEFT JOIN student_profiles sp ON u.id = sp.user_id 
                   WHERE u.role = 'student' AND u.status = 'active' 
                   ORDER BY u.name";
$students_stmt = $db->query($students_query);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Log Clinic Visit";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Health', 'url' => '../index.php'],
    ['title' => 'Medical Records', 'url' => 'index.php'],
    ['title' => 'Log Visit']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-4xl mx-auto">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Log Student Clinic Visit</h1>
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

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            
                            <!-- Student, Date & Time -->
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                                    <label for="visit_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Date *</label>
                                    <input type="date" id="visit_date" name="visit_date" value="<?php echo date('Y-m-d'); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="visit_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Time *</label>
                                    <input type="time" id="visit_time" name="visit_time" value="<?php echo date('H:i'); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Vitals Section -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-heartbeat text-red-500 mr-2"></i>Vital Signs (Optional)</h3>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                                    <div>
                                        <label for="temperature_f" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Temperature (°F)</label>
                                        <input type="number" id="temperature_f" name="temperature_f" step="0.1" min="90" max="110" placeholder="98.6"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>
                                    
                                    <div>
                                        <label for="blood_pressure" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blood Pressure</label>
                                        <input type="text" id="blood_pressure" name="blood_pressure" placeholder="120/80"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="pulse_rate" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Pulse Rate (bpm)</label>
                                        <input type="number" id="pulse_rate" name="pulse_rate" min="40" max="200" placeholder="72"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>
                            </div>

                            <!-- Clinical Information -->
                            <div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><i class="fas fa-notes-medical text-blue-500 mr-2"></i>Visit Details</h3>
                                <div class="space-y-4">
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label for="record_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Visit Category *</label>
                                            <select id="record_type" name="record_type" required
                                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                                <option value="illness">Illness / Sickness</option>
                                                <option value="injury">Injury / First Aid</option>
                                                <option value="checkup">Routine Checkup</option>
                                                <option value="vaccination">Vaccination</option>
                                                <option value="allergy">Allergic Reaction</option>
                                            </select>
                                        </div>

                                        <div>
                                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                                            <select id="status" name="status" required
                                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                                <option value="active">Active (Needs observation / treatment)</option>
                                                <option value="resolved">Resolved (Sent back to class)</option>
                                                <option value="referred">Referred (Sent to hospital / clinic / home)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label for="complaint" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Chief Complaint *</label>
                                        <input type="text" id="complaint" name="complaint" required placeholder="Reason for the visit (e.g., headache, stomachache)"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div>
                                        <label for="symptoms" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Symptoms & Observations</label>
                                        <textarea id="symptoms" name="symptoms" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="Detailed description of student's symptoms and observations"></textarea>
                                    </div>

                                    <div>
                                        <label for="treatment" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Treatment Administered</label>
                                        <textarea id="treatment" name="treatment" rows="2"
                                                  class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                                  placeholder="What treatment was given (e.g., rested, applied ice pack, wound cleaned)"></textarea>
                                    </div>

                                    <div>
                                        <label for="medication" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Medication Given / Prescribed</label>
                                        <input type="text" id="medication" name="medication" placeholder="Medication name and dosage (e.g., Paracetamol 500mg)"
                                               class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <label for="follow_up_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Follow-up Date (If needed)</label>
                                            <input type="date" id="follow_up_date" name="follow_up_date"
                                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        </div>

                                        <div>
                                            <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Notes / Remarks</label>
                                            <input type="text" id="notes" name="notes" placeholder="Any additional notes or notifications sent to parents"
                                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg font-medium">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                    <i class="fas fa-save mr-2"></i>Log Clinic Visit
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
