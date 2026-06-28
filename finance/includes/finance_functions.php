<?php
/**
 * Finance Management Module - Core Utility Functions
 */

if (!function_exists('getFinanceSettings')) {
    function getFinanceSettings($db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        try {
            $stmt = $db->query("SELECT * FROM school_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            return $settings ?: [];
        } catch (PDOException $e) {
            error_log("Error fetching finance settings: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('formatFinanceCurrency')) {
    function formatFinanceCurrency($amount, $db = null) {
        if (!function_exists('getSchoolSetting')) {
            require_once dirname(dirname(__DIR__)) . '/includes/settings_helper.php';
        }
        
        $currency = 'GHS';
        $symbol = '₵';
        
        if (function_exists('getSchoolSetting')) {
            $currency = getSchoolSetting('currency', 'GHS');
            $symbol = getSchoolSetting('currency_symbol', '₵');
        }
        
        return $symbol . ' ' . number_format((float)$amount, 2);
    }
}

if (!function_exists('generateInvoiceNumber')) {
    function generateInvoiceNumber($db) {
        $year = date('Y');
        $prefix = "INV-" . $year . "-";
        
        try {
            $query = "SELECT invoice_number FROM finance_invoices WHERE invoice_number LIKE :pattern ORDER BY id DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $pattern = $prefix . '%';
            $stmt->execute([':pattern' => $pattern]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $lastNumber = $result['invoice_number'];
                $sequence = (int)substr($lastNumber, strlen($prefix));
                $nextSequence = $sequence + 1;
            } else {
                $nextSequence = 1;
            }
            
            return $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            error_log("Error generating invoice number: " . $e->getMessage());
            return $prefix . rand(10000, 99999);
        }
    }
}

if (!function_exists('generateReceiptNumber')) {
    function generateReceiptNumber($db) {
        $year = date('Y');
        $prefix = "RCT-" . $year . "-";
        
        try {
            $query = "SELECT receipt_number FROM finance_payments WHERE receipt_number LIKE :pattern ORDER BY id DESC LIMIT 1";
            $stmt = $db->prepare($query);
            $pattern = $prefix . '%';
            $stmt->execute([':pattern' => $pattern]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $lastNumber = $result['receipt_number'];
                $sequence = (int)substr($lastNumber, strlen($prefix));
                $nextSequence = $sequence + 1;
            } else {
                $nextSequence = 1;
            }
            
            return $prefix . str_pad($nextSequence, 5, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            error_log("Error generating receipt number: " . $e->getMessage());
            return $prefix . rand(10000, 99999);
        }
    }
}

if (!function_exists('getStudentBalance')) {
    function getStudentBalance($student_id, $academic_year_id = null, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        try {
            $sql = "SELECT SUM(total_amount + penalty_amount - discount_amount - amount_paid) as balance 
                    FROM finance_invoices 
                    WHERE student_id = :student_id AND status != 'cancelled'";
            
            $params = [':student_id' => $student_id];
            if ($academic_year_id) {
                $sql .= " AND academic_year_id = :academic_year_id";
                $params[':academic_year_id'] = $academic_year_id;
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return (float)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error getting student balance: " . $e->getMessage());
            return 0.00;
        }
    }
}

if (!function_exists('getStudentInvoices')) {
    function getStudentInvoices($student_id, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        try {
            $stmt = $db->prepare("SELECT i.*, ay.year_name, t.term_name 
                                  FROM finance_invoices i
                                  JOIN academic_years ay ON i.academic_year_id = ay.id
                                  JOIN academic_terms t ON i.term_id = t.id
                                  WHERE i.student_id = :student_id
                                  ORDER BY i.id DESC");
            $stmt->execute([':student_id' => $student_id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching student invoices: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('logFinanceAudit')) {
    function logFinanceAudit($action, $module, $record_id, $details, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        $user_id = $_SESSION['user_id'] ?? 0;
        if (!$user_id) {
            return false;
        }
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $details_str = is_array($details) ? json_encode($details) : $details;
        
        try {
            $stmt = $db->prepare("INSERT INTO finance_audit_log (user_id, action, module, record_id, details, ip_address) 
                                  VALUES (:user_id, :action, :module, :record_id, :details, :ip_address)");
            return $stmt->execute([
                ':user_id' => $user_id,
                ':action' => $action,
                ':module' => $module,
                ':record_id' => $record_id,
                ':details' => $details_str,
                ':ip_address' => $ip_address
            ]);
        } catch (PDOException $e) {
            error_log("Error logging finance audit: " . $e->getMessage());
            return false;
        }
    }
}
