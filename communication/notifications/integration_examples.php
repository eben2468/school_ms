<?php
/**
 * Integration Examples for Notification System
 * This file shows how to integrate notifications into various parts of the school management system
 */

require_once '../../config/database.php';
require_once 'NotificationHelper.php';

// Initialize notification helper
$database = new Database();
$db = $database->getConnection();
$notificationHelper = new NotificationHelper($db);

/**
 * Example 1: Student Enrollment Notification
 * Call this when a new student is enrolled
 */
function notifyStudentEnrollment($student_data, $created_by_id) {
    global $notificationHelper;
    
    // Create notification for administrators
    $notificationHelper->notifyStudentEnrollment(
        $student_data['name'],
        $student_data['class_name'],
        $student_data['id'],
        $created_by_id
    );
    
    // Also notify class teachers
    $notificationHelper->createForRoles(['teacher'], [
        'title' => 'New Student in Your Class',
        'message' => "Student {$student_data['name']} has been enrolled in {$student_data['class_name']}",
        'type' => 'academic',
        'priority' => 'medium',
        'icon' => 'fas fa-user-plus',
        'action_url' => "/students/view.php?id={$student_data['id']}",
        'action_text' => 'View Student',
        'created_by' => $created_by_id
    ]);
}

/**
 * Example 2: Fee Payment Notifications
 * Call this when a fee payment is received
 */
function notifyFeePaymentReceived($payment_data, $created_by_id) {
    global $notificationHelper;
    
    // Notify finance team
    $notificationHelper->notifyFeePayment(
        $payment_data['student_name'],
        $payment_data['amount'],
        $payment_data['fee_type'],
        $payment_data['payment_id'],
        $created_by_id
    );
    
    // Notify parent/student
    if ($payment_data['parent_user_id']) {
        $notificationHelper->createNotification([
            'user_id' => $payment_data['parent_user_id'],
            'title' => 'Payment Confirmation',
            'message' => "Payment of ₵{$payment_data['amount']} for {$payment_data['fee_type']} has been received.",
            'type' => 'finance',
            'priority' => 'medium',
            'icon' => 'fas fa-check-circle',
            'action_url' => "/finance/receipt.php?id={$payment_data['payment_id']}",
            'action_text' => 'View Receipt',
            'created_by' => $created_by_id
        ]);
    }
}

/**
 * Example 3: Assignment Submission Notifications
 * Call this when students submit assignments
 */
function notifyAssignmentSubmission($assignment_data, $submission_count, $created_by_id) {
    global $notificationHelper;
    
    // Notify teacher
    $notificationHelper->notifyAssignmentSubmission(
        $submission_count,
        $assignment_data['name'],
        $assignment_data['id'],
        $created_by_id
    );
    
    // If all students have submitted, notify administrators
    if ($submission_count >= $assignment_data['total_students']) {
        $notificationHelper->createForRoles(['school_admin', 'principal'], [
            'title' => 'Assignment Completed',
            'message' => "All students have submitted {$assignment_data['name']}",
            'type' => 'academic',
            'priority' => 'low',
            'icon' => 'fas fa-check-double',
            'action_url' => "/academics/assignments/view.php?id={$assignment_data['id']}",
            'action_text' => 'View Assignment',
            'created_by' => $created_by_id
        ]);
    }
}

/**
 * Example 4: Attendance Alert Notifications
 * Call this during attendance processing
 */
function checkAndNotifyAttendanceAlerts($student_data, $created_by_id) {
    global $notificationHelper;
    
    if ($student_data['attendance_percentage'] < 75) {
        // Notify parent
        if ($student_data['parent_user_id']) {
            $notificationHelper->notifyLowAttendance(
                $student_data['name'],
                $student_data['attendance_percentage'],
                $student_data['id'],
                $student_data['parent_user_id'],
                $created_by_id
            );
        }
        
        // Notify class teacher and administrators
        $notificationHelper->createForRoles(['teacher', 'school_admin'], [
            'title' => 'Low Attendance Alert',
            'message' => "{$student_data['name']} has {$student_data['attendance_percentage']}% attendance",
            'type' => 'attendance',
            'priority' => 'high',
            'icon' => 'fas fa-exclamation-triangle',
            'action_url' => "/attendance/student.php?id={$student_data['id']}",
            'action_text' => 'View Attendance',
            'created_by' => $created_by_id
        ]);
    }
}

/**
 * Example 5: Grade Publication Notifications
 * Call this when grades are published
 */
function notifyGradePublication($exam_data, $created_by_id) {
    global $notificationHelper;
    
    // Notify all students and parents
    $notificationHelper->createForRoles(['student', 'parent'], [
        'title' => 'Grades Published',
        'message' => "Grades for {$exam_data['subject_name']} - {$exam_data['exam_name']} are now available",
        'type' => 'grades',
        'priority' => 'medium',
        'icon' => 'fas fa-graduation-cap',
        'action_url' => "/academics/grades/view.php?exam_id={$exam_data['id']}",
        'action_text' => 'View Grades',
        'created_by' => $created_by_id
    ]);
}

/**
 * Example 6: Event Reminder Notifications
 * Call this for upcoming events (can be scheduled)
 */
function notifyUpcomingEvents($event_data, $created_by_id) {
    global $notificationHelper;
    
    // Notify all users about upcoming events
    $notificationHelper->notifyEventReminder(
        $event_data['name'],
        $event_data['date'],
        $event_data['id'],
        $created_by_id
    );
}

/**
 * Example 7: Library Book Due Notifications
 * Call this for overdue book reminders
 */
function notifyLibraryBookDue($book_data, $user_id, $created_by_id) {
    global $notificationHelper;
    
    $notificationHelper->notifyLibraryBookDue(
        $book_data['title'],
        $book_data['due_date'],
        $user_id,
        $created_by_id
    );
}

/**
 * Example 8: System Maintenance Notifications
 * Call this to notify about scheduled maintenance
 */
function notifySystemMaintenance($maintenance_data, $created_by_id) {
    global $notificationHelper;
    
    $notificationHelper->notifySystemMaintenance(
        $maintenance_data['date'],
        $maintenance_data['start_time'],
        $maintenance_data['end_time'],
        $created_by_id
    );
}

/**
 * Example 9: General Announcement Notifications
 * Call this when creating announcements
 */
function notifyAnnouncement($announcement_data, $created_by_id) {
    global $notificationHelper;
    
    $notificationHelper->notifyAnnouncement(
        $announcement_data['title'],
        $announcement_data['summary'],
        $announcement_data['id'],
        $created_by_id
    );
}

/**
 * Example 10: Custom Notification
 * For any custom scenarios not covered by templates
 */
function createCustomNotification($notification_data) {
    global $notificationHelper;
    
    return $notificationHelper->createNotification($notification_data);
}

/**
 * Example Usage in Your Application Files:
 * 
 * // In student enrollment form processing:
 * notifyStudentEnrollment($student_data, $_SESSION['user_id']);
 * 
 * // In fee payment processing:
 * notifyFeePaymentReceived($payment_data, $_SESSION['user_id']);
 * 
 * // In assignment submission:
 * notifyAssignmentSubmission($assignment_data, $submission_count, $_SESSION['user_id']);
 * 
 * // In attendance processing:
 * checkAndNotifyAttendanceAlerts($student_data, $_SESSION['user_id']);
 * 
 * // In grade publication:
 * notifyGradePublication($exam_data, $_SESSION['user_id']);
 * 
 * // For scheduled tasks (cron jobs):
 * notifyUpcomingEvents($event_data, 1); // System user ID
 * notifyLibraryBookDue($book_data, $user_id, 1);
 */

// Example of how to integrate into existing forms:
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demo_notification'])) {
    session_start();
    
    // Demo notification creation
    $demo_result = $notificationHelper->createNotification([
        'user_id' => $_SESSION['user_id'] ?? null,
        'title' => 'Demo Notification',
        'message' => 'This is a demonstration of the notification system. It was created at ' . date('Y-m-d H:i:s'),
        'type' => 'system',
        'priority' => 'medium',
        'icon' => 'fas fa-star',
        'action_url' => '/notifications.php',
        'action_text' => 'View All Notifications',
        'created_by' => $_SESSION['user_id'] ?? null
    ]);
    
    if ($demo_result) {
        echo json_encode(['success' => true, 'message' => 'Demo notification created successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to create demo notification']);
    }
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Notification System Integration Examples</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto">
        <h1 class="text-3xl font-bold mb-6">Notification System Integration Examples</h1>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <h2 class="text-xl font-semibold mb-4">Test Notification Creation</h2>
            <button onclick="createDemoNotification()" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">
                Create Demo Notification
            </button>
            <div id="result" class="mt-4"></div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-6">
            <h2 class="text-xl font-semibold mb-4">Integration Instructions</h2>
            <p class="mb-4">To integrate notifications into your application:</p>
            <ol class="list-decimal list-inside space-y-2">
                <li>Include the NotificationHelper class in your PHP files</li>
                <li>Initialize the helper with your database connection</li>
                <li>Call the appropriate notification methods when events occur</li>
                <li>Use the examples above as templates for your specific needs</li>
            </ol>
        </div>
    </div>
    
    <script>
    function createDemoNotification() {
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'demo_notification=1'
        })
        .then(response => response.json())
        .then(data => {
            const result = document.getElementById('result');
            if (data.success) {
                result.innerHTML = '<div class="text-green-600">✅ ' + data.message + '</div>';
            } else {
                result.innerHTML = '<div class="text-red-600">❌ ' + data.message + '</div>';
            }
        })
        .catch(error => {
            document.getElementById('result').innerHTML = '<div class="text-red-600">❌ Error: ' + error + '</div>';
        });
    }
    </script>
</body>
</html>
