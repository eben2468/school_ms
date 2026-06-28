<?php
/**
 * Finance Management Module - Report Helper Functions
 */

if (!function_exists('getRevenueReport')) {
    function getRevenueReport($period = 'monthly', $start_date = null, $end_date = null, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        $params = [];
        // NB: finance_payments has no status column — a payment row only exists
        // once money has actually been collected, so no status filter is needed.
        $where = "WHERE 1=1";

        if ($start_date && $end_date) {
            $where .= " AND payment_date BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $start_date . ' 00:00:00';
            $params[':end_date'] = $end_date . ' 23:59:59';
        }
        
        try {
            if ($period === 'daily') {
                $sql = "SELECT DATE(payment_date) as label, SUM(amount) as value 
                        FROM finance_payments 
                        $where 
                        GROUP BY DATE(payment_date) 
                        ORDER BY label ASC LIMIT 30";
            } else if ($period === 'weekly') {
                $sql = "SELECT YEARWEEK(payment_date, 1) as label, SUM(amount) as value 
                        FROM finance_payments 
                        $where 
                        GROUP BY YEARWEEK(payment_date, 1) 
                        ORDER BY label ASC LIMIT 12";
            } else { // default monthly
                $sql = "SELECT DATE_FORMAT(payment_date, '%Y-%m') as label, SUM(amount) as value 
                        FROM finance_payments 
                        $where 
                        GROUP BY DATE_FORMAT(payment_date, '%Y-%m') 
                        ORDER BY label ASC LIMIT 12";
            }
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting revenue report: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getStudentReport')) {
    function getStudentReport($type = 'owing', $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        try {
            if ($type === 'paid') {
                $sql = "SELECT i.*, u.name as student_name, sp.student_id as student_reg_no, c.name as class_name 
                        FROM finance_invoices i
                        JOIN users u ON i.student_id = u.id
                        JOIN student_profiles sp ON u.id = sp.user_id
                        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                        LEFT JOIN classes c ON sc.class_id = c.id
                        WHERE i.status = 'paid'
                        ORDER BY i.id DESC";
            } else if ($type === 'owing') {
                $sql = "SELECT i.*, u.name as student_name, sp.student_id as student_reg_no, c.name as class_name,
                               (i.total_amount + i.penalty_amount - i.discount_amount - i.amount_paid) as outstanding_balance
                        FROM finance_invoices i
                        JOIN users u ON i.student_id = u.id
                        JOIN student_profiles sp ON u.id = sp.user_id
                        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                        LEFT JOIN classes c ON sc.class_id = c.id
                        WHERE i.status IN ('pending', 'partially_paid', 'overdue')
                          AND (i.total_amount + i.penalty_amount - i.discount_amount - i.amount_paid) > 0
                        ORDER BY outstanding_balance DESC";
            } else { // 'all'
                $sql = "SELECT i.*, u.name as student_name, sp.student_id as student_reg_no, c.name as class_name 
                        FROM finance_invoices i
                        JOIN users u ON i.student_id = u.id
                        JOIN student_profiles sp ON u.id = sp.user_id
                        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                        LEFT JOIN classes c ON sc.class_id = c.id
                        ORDER BY i.id DESC";
            }
            
            $stmt = $db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting student report: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getExpenseReport')) {
    function getExpenseReport($start_date = null, $end_date = null, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        $params = [];
        $where = "WHERE status = 'approved'";
        
        if ($start_date && $end_date) {
            $where .= " AND expense_date BETWEEN :start_date AND :end_date";
            $params[':start_date'] = $start_date;
            $params[':end_date'] = $end_date;
        }
        
        try {
            $sql = "SELECT category as label, SUM(amount) as value 
                    FROM finance_expenses 
                    $where 
                    GROUP BY category 
                    ORDER BY value DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting expense report: " . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('getIncomeVsExpenseReport')) {
    function getIncomeVsExpenseReport($start_date = null, $end_date = null, $db = null) {
        if (!$db) {
            $database = new Database();
            $db = $database->getConnection();
        }
        
        $params = [];
        $where_fee = "";
        $where_inc = "";
        $where_exp = "WHERE status = 'approved'";
        
        if ($start_date && $end_date) {
            $where_fee = "AND payment_date BETWEEN :start_date_fee AND :end_date_fee";
            $where_inc = "WHERE income_date BETWEEN :start_date_inc AND :end_date_inc";
            $where_exp = "WHERE status = 'approved' AND expense_date BETWEEN :start_date_exp AND :end_date_exp";
            
            $params[':start_date_fee'] = $start_date . ' 00:00:00';
            $params[':end_date_fee'] = $end_date . ' 23:59:59';
            $params[':start_date_inc'] = $start_date;
            $params[':end_date_inc'] = $end_date;
            $params[':start_date_exp'] = $start_date;
            $params[':end_date_exp'] = $end_date;
        }
        
        try {
            // 1. Get Fee Payments
            $fee_sql = "SELECT SUM(amount) FROM finance_payments WHERE id IS NOT NULL $where_fee";
            $fee_stmt = $db->prepare($fee_sql);
            // Bind corresponding params
            $fee_params = [];
            if ($start_date) {
                $fee_params[':start_date_fee'] = $params[':start_date_fee'];
                $fee_params[':end_date_fee'] = $params[':end_date_fee'];
            }
            $fee_stmt->execute($fee_params);
            $fee_total = (float)$fee_stmt->fetchColumn();
            
            // 2. Get Other Income
            $inc_sql = "SELECT SUM(amount) FROM finance_income $where_inc";
            $inc_stmt = $db->prepare($inc_sql);
            $inc_params = [];
            if ($start_date) {
                $inc_params[':start_date_inc'] = $params[':start_date_inc'];
                $inc_params[':end_date_inc'] = $params[':end_date_inc'];
            }
            $inc_stmt->execute($inc_params);
            $other_income = (float)$inc_stmt->fetchColumn();
            
            $total_income = $fee_total + $other_income;
            
            // 3. Get Expenses
            $exp_sql = "SELECT SUM(amount) FROM finance_expenses $where_exp";
            $exp_stmt = $db->prepare($exp_sql);
            $exp_params = [];
            if ($start_date) {
                $exp_params[':start_date_exp'] = $params[':start_date_exp'];
                $exp_params[':end_date_exp'] = $params[':end_date_exp'];
            }
            $exp_stmt->execute($exp_params);
            $total_expense = (float)$exp_stmt->fetchColumn();
            
            return [
                'fee_income' => $fee_total,
                'other_income' => $other_income,
                'total_income' => $total_income,
                'total_expense' => $total_expense,
                'net_profit' => $total_income - $total_expense
            ];
        } catch (PDOException $e) {
            error_log("Error getting income vs expense report: " . $e->getMessage());
            return [
                'fee_income' => 0,
                'other_income' => 0,
                'total_income' => 0,
                'total_expense' => 0,
                'net_profit' => 0
            ];
        }
    }
}

if (!function_exists('exportToCSV')) {
    function exportToCSV($data, $filename = 'export.csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        $output = fopen('php://output', 'w');
        
        if (!empty($data)) {
            // Write headers
            fputcsv($output, array_keys($data[0]));
            
            // Write data rows
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        
        fclose($output);
        exit();
    }
}
