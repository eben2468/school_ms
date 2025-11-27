<?php
/**
 * NotificationHelper - Utility class for creating and managing notifications
 */
class NotificationHelper {
    private $db;
    
    public function __construct($database_connection) {
        $this->db = $database_connection;
    }
    
    /**
     * Create a notification using a template
     */
    public function createFromTemplate($template_name, $variables = [], $user_id = null, $created_by = null) {
        try {
            // Get template
            $template_query = "SELECT * FROM notification_templates WHERE name = :name AND is_active = TRUE";
            $template_stmt = $this->db->prepare($template_query);
            $template_stmt->bindParam(':name', $template_name);
            $template_stmt->execute();
            $template = $template_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                throw new Exception("Template '$template_name' not found or inactive");
            }
            
            // Replace variables in title and message
            $title = $this->replaceVariables($template['title_template'], $variables);
            $message = $this->replaceVariables($template['message_template'], $variables);
            $action_url = $template['action_url_template'] ? $this->replaceVariables($template['action_url_template'], $variables) : null;
            
            // Create notification
            return $this->createNotification([
                'user_id' => $user_id,
                'title' => $title,
                'message' => $message,
                'type' => $template['type'],
                'priority' => $template['priority'],
                'icon' => $template['icon'],
                'action_url' => $action_url,
                'action_text' => $template['action_text'],
                'created_by' => $created_by,
                'metadata' => json_encode($variables)
            ]);
            
        } catch (Exception $e) {
            error_log("NotificationHelper Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create a direct notification
     */
    public function createNotification($data) {
        try {
            $this->db->beginTransaction();
            
            $insert_query = "
                INSERT INTO notifications 
                (user_id, title, message, type, priority, icon, action_url, action_text, expires_at, metadata, created_by)
                VALUES (:user_id, :title, :message, :type, :priority, :icon, :action_url, :action_text, :expires_at, :metadata, :created_by)
            ";
            
            $insert_stmt = $this->db->prepare($insert_query);
            $insert_stmt->execute([
                ':user_id' => $data['user_id'] ?? null,
                ':title' => $data['title'],
                ':message' => $data['message'],
                ':type' => $data['type'] ?? 'general',
                ':priority' => $data['priority'] ?? 'medium',
                ':icon' => $data['icon'] ?? 'fas fa-bell',
                ':action_url' => $data['action_url'] ?? null,
                ':action_text' => $data['action_text'] ?? null,
                ':expires_at' => $data['expires_at'] ?? null,
                ':metadata' => $data['metadata'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ]);
            
            $notification_id = $this->db->lastInsertId();
            
            // Log the creation
            $this->logNotificationAction($notification_id, 'created', $data['created_by'] ?? null);
            
            $this->db->commit();
            return $notification_id;
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("NotificationHelper Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create notifications for multiple users
     */
    public function createBulkNotifications($user_ids, $notification_data) {
        $created_count = 0;
        
        foreach ($user_ids as $user_id) {
            $notification_data['user_id'] = $user_id;
            if ($this->createNotification($notification_data)) {
                $created_count++;
            }
        }
        
        return $created_count;
    }
    
    /**
     * Create notification for all users with specific roles
     */
    public function createForRoles($roles, $notification_data) {
        try {
            $roles_placeholder = str_repeat('?,', count($roles) - 1) . '?';
            $users_query = "SELECT id FROM users WHERE role IN ($roles_placeholder)";
            $users_stmt = $this->db->prepare($users_query);
            $users_stmt->execute($roles);
            $user_ids = $users_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return $this->createBulkNotifications($user_ids, $notification_data);
            
        } catch (Exception $e) {
            error_log("NotificationHelper Error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Create global notification (visible to all users)
     */
    public function createGlobalNotification($notification_data) {
        $notification_data['user_id'] = null;
        return $this->createNotification($notification_data);
    }
    
    /**
     * Replace variables in template strings
     */
    private function replaceVariables($template, $variables) {
        foreach ($variables as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }
    
    /**
     * Log notification action
     */
    private function logNotificationAction($notification_id, $action, $user_id) {
        try {
            $log_query = "
                INSERT INTO notification_logs (notification_id, action, user_id, ip_address, user_agent)
                VALUES (:notification_id, :action, :user_id, :ip_address, :user_agent)
            ";
            $log_stmt = $this->db->prepare($log_query);
            $log_stmt->execute([
                ':notification_id' => $notification_id,
                ':action' => $action,
                ':user_id' => $user_id,
                ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("NotificationHelper Log Error: " . $e->getMessage());
        }
    }
    
    /**
     * Quick notification methods for common scenarios
     */
    
    public function notifyStudentEnrollment($student_name, $class_name, $student_id, $created_by = null) {
        return $this->createFromTemplate('student_enrollment', [
            'student_name' => $student_name,
            'class_name' => $class_name,
            'student_id' => $student_id
        ], null, $created_by);
    }
    
    public function notifyFeePayment($student_name, $amount, $fee_type, $payment_id, $created_by = null) {
        return $this->createFromTemplate('fee_payment_received', [
            'student_name' => $student_name,
            'amount' => $amount,
            'fee_type' => $fee_type,
            'payment_id' => $payment_id
        ], null, $created_by);
    }
    
    public function notifyOverdueFee($student_name, $amount, $fee_type, $student_id, $user_id = null, $created_by = null) {
        return $this->createFromTemplate('fee_payment_overdue', [
            'student_name' => $student_name,
            'amount' => $amount,
            'fee_type' => $fee_type,
            'student_id' => $student_id
        ], $user_id, $created_by);
    }
    
    public function notifyAssignmentSubmission($student_count, $assignment_name, $assignment_id, $created_by = null) {
        return $this->createFromTemplate('assignment_submitted', [
            'student_count' => $student_count,
            'assignment_name' => $assignment_name,
            'assignment_id' => $assignment_id
        ], null, $created_by);
    }
    
    public function notifyLowAttendance($student_name, $attendance_percentage, $student_id, $user_id = null, $created_by = null) {
        return $this->createFromTemplate('attendance_alert', [
            'student_name' => $student_name,
            'attendance_percentage' => $attendance_percentage,
            'student_id' => $student_id
        ], $user_id, $created_by);
    }
    
    public function notifyGradesPublished($subject_name, $exam_name, $exam_id, $created_by = null) {
        return $this->createFromTemplate('grade_published', [
            'subject_name' => $subject_name,
            'exam_name' => $exam_name,
            'exam_id' => $exam_id
        ], null, $created_by);
    }
    
    public function notifyEventReminder($event_name, $event_date, $event_id, $created_by = null) {
        return $this->createFromTemplate('event_reminder', [
            'event_name' => $event_name,
            'event_date' => $event_date,
            'event_id' => $event_id
        ], null, $created_by);
    }
    
    public function notifyLibraryBookDue($book_title, $due_date, $user_id, $created_by = null) {
        return $this->createFromTemplate('library_book_due', [
            'book_title' => $book_title,
            'due_date' => $due_date
        ], $user_id, $created_by);
    }
    
    public function notifySystemMaintenance($maintenance_date, $start_time, $end_time, $created_by = null) {
        return $this->createFromTemplate('system_maintenance', [
            'maintenance_date' => $maintenance_date,
            'start_time' => $start_time,
            'end_time' => $end_time
        ], null, $created_by);
    }
    
    public function notifyAnnouncement($announcement_title, $announcement_summary, $announcement_id, $created_by = null) {
        return $this->createFromTemplate('announcement', [
            'announcement_title' => $announcement_title,
            'announcement_summary' => $announcement_summary,
            'announcement_id' => $announcement_id
        ], null, $created_by);
    }
}
?>
