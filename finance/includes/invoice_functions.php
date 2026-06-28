<?php
/**
 * Finance Management Module - Invoice Helper Functions
 */

require_once __DIR__ . '/finance_functions.php';

if (!function_exists('updateInvoiceStatus')) {
    function updateInvoiceStatus($invoice_id, $db) {
        try {
            // Calculate total payments
            $stmt = $db->prepare("SELECT SUM(amount) FROM finance_payments WHERE invoice_id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $payments_sum = (float)$stmt->fetchColumn();

            // Calculate total penalties
            $stmt = $db->prepare("SELECT SUM(amount) FROM finance_student_penalties WHERE invoice_id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $penalties_sum = (float)$stmt->fetchColumn();

            // Fetch invoice details
            $stmt = $db->prepare("SELECT total_amount, discount_amount, due_date, status FROM finance_invoices WHERE id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) return false;

            $total_due = $invoice['total_amount'] + $penalties_sum - $invoice['discount_amount'];
            $new_status = 'pending';

            if ($payments_sum >= $total_due && $total_due > 0) {
                $new_status = 'paid';
            } elseif ($payments_sum > 0) {
                $new_status = 'partially_paid';
            } else {
                $today = date('Y-m-d');
                if ($invoice['due_date'] < $today) {
                    $new_status = 'overdue';
                } else {
                    $new_status = 'pending';
                }
            }

            if ($invoice['status'] === 'cancelled') {
                $new_status = 'cancelled';
            }

            $stmt = $db->prepare("UPDATE finance_invoices 
                                  SET amount_paid = :amount_paid, penalty_amount = :penalty_amount, status = :status 
                                  WHERE id = :invoice_id");
            return $stmt->execute([
                ':amount_paid' => $payments_sum,
                ':penalty_amount' => $penalties_sum,
                ':status' => $new_status,
                ':invoice_id' => $invoice_id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating invoice status: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('applyDiscountsToInvoice')) {
    function applyDiscountsToInvoice($invoice_id, $student_id, $academic_year_id, $db) {
        try {
            // Get all active discounts assigned to this student
            $stmt = $db->prepare("SELECT d.* FROM finance_student_discounts sd
                                  JOIN finance_discounts d ON sd.discount_id = d.id
                                  WHERE sd.student_id = :student_id 
                                    AND sd.academic_year_id = :academic_year_id 
                                    AND d.status = 'active'");
            $stmt->execute([
                ':student_id' => $student_id,
                ':academic_year_id' => $academic_year_id
            ]);
            $discounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($discounts)) return 0;

            // Fetch invoice amount
            $stmt = $db->prepare("SELECT total_amount FROM finance_invoices WHERE id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $total_amount = (float)$stmt->fetchColumn();

            $discount_total = 0.00;
            foreach ($discounts as $discount) {
                if ($discount['type'] === 'percentage') {
                    $discount_total += $total_amount * ((float)$discount['value'] / 100);
                } else {
                    $discount_total += (float)$discount['value'];
                }
            }

            // Cap discount at total invoice amount
            if ($discount_total > $total_amount) {
                $discount_total = $total_amount;
            }

            $stmt = $db->prepare("UPDATE finance_invoices SET discount_amount = :discount_amount WHERE id = :invoice_id");
            $stmt->execute([
                ':discount_amount' => $discount_total,
                ':invoice_id' => $invoice_id
            ]);

            return $discount_total;
        } catch (PDOException $e) {
            error_log("Error applying discounts: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('generateStudentInvoice')) {
    function generateStudentInvoice($student_id, $academic_year_id, $term_id, $db) {
        try {
            // 1. Get student class and profile
            $stmt = $db->prepare("SELECT sc.class_id, sp.student_type 
                                  FROM student_classes sc
                                  JOIN student_profiles sp ON sc.student_id = sp.user_id
                                  WHERE sc.student_id = :student_id 
                                    AND sc.status = 'active'
                                  LIMIT 1");
            $stmt->execute([
                ':student_id' => $student_id
            ]);
            $student_info = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$student_info) return false;

            $class_id = $student_info['class_id'];
            $student_type = $student_info['student_type'] ?: 'day';

            // 2. Fetch fee structures for this class, academic year, term, and student type
            $stmt = $db->prepare("SELECT * FROM finance_fee_structures 
                                  WHERE academic_year_id = :academic_year_id 
                                    AND term_id = :term_id 
                                    AND class_id = :class_id 
                                    AND (student_type = 'all' OR student_type = :student_type)");
            $stmt->execute([
                ':academic_year_id' => $academic_year_id,
                ':term_id' => $term_id,
                ':class_id' => $class_id,
                ':student_type' => $student_type
            ]);
            $fee_structures = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($fee_structures)) return false;

            // Check if invoice already exists
            $stmt = $db->prepare("SELECT id FROM finance_invoices 
                                  WHERE student_id = :student_id 
                                    AND academic_year_id = :academic_year_id 
                                    AND term_id = :term_id 
                                    AND status != 'cancelled'");
            $stmt->execute([
                ':student_id' => $student_id,
                ':academic_year_id' => $academic_year_id,
                ':term_id' => $term_id
            ]);
            if ($stmt->fetch()) {
                return false; // Already has active invoice for this term
            }

            // 3. Generate invoice number
            $invoice_number = generateInvoiceNumber($db);
            $due_date = date('Y-m-d', strtotime('+30 days'));

            // 4. Calculate total amount
            $total_amount = 0.00;
            foreach ($fee_structures as $fee) {
                $total_amount += (float)$fee['amount'];
            }

            // 5. Insert invoice
            $db->beginTransaction();

            $stmt = $db->prepare("INSERT INTO finance_invoices (invoice_number, student_id, academic_year_id, term_id, total_amount, due_date, status) 
                                  VALUES (:invoice_number, :student_id, :academic_year_id, :term_id, :total_amount, :due_date, 'pending')");
            $stmt->execute([
                ':invoice_number' => $invoice_number,
                ':student_id' => $student_id,
                ':academic_year_id' => $academic_year_id,
                ':term_id' => $term_id,
                ':total_amount' => $total_amount,
                ':due_date' => $due_date
            ]);
            $invoice_id = $db->lastInsertId();

            // 6. Insert items
            $item_stmt = $db->prepare("INSERT INTO finance_invoice_items (invoice_id, category_id, description, amount) 
                                       VALUES (:invoice_id, :category_id, :description, :amount)");
            
            // Get category names
            $cat_stmt = $db->prepare("SELECT id, name FROM finance_fee_categories");
            $cat_stmt->execute();
            $categories = $cat_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            foreach ($fee_structures as $fee) {
                $category_name = $categories[$fee['category_id']] ?? 'Fee Charge';
                $item_stmt->execute([
                    ':invoice_id' => $invoice_id,
                    ':category_id' => $fee['category_id'],
                    ':description' => $category_name,
                    ':amount' => $fee['amount']
                ]);
            }

            $db->commit();

            // Apply discounts if any
            applyDiscountsToInvoice($invoice_id, $student_id, $academic_year_id, $db);
            updateInvoiceStatus($invoice_id, $db);

            logFinanceAudit('Generate Invoice', 'Invoices', $invoice_id, "Generated invoice $invoice_number for student $student_id, total amount: $total_amount", $db);

            return $invoice_id;
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error generating student invoice: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('generateClassInvoices')) {
    function generateClassInvoices($class_id, $academic_year_id, $term_id, $db) {
        try {
            // Find all active students in class
            $stmt = $db->prepare("SELECT student_id FROM student_classes 
                                  WHERE class_id = :class_id 
                                    AND status = 'active'");
            $stmt->execute([
                ':class_id' => $class_id
            ]);
            $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($students as $student_id) {
                $res = generateStudentInvoice($student_id, $academic_year_id, $term_id, $db);
                if ($res) $count++;
            }
            return $count;
        } catch (PDOException $e) {
            error_log("Error generating class invoices: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('generateSchoolInvoices')) {
    function generateSchoolInvoices($academic_year_id, $term_id, $db) {
        try {
            // Find all active students
            $stmt = $db->prepare("SELECT student_id FROM student_classes 
                                  WHERE status = 'active'");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($students as $student_id) {
                $res = generateStudentInvoice($student_id, $academic_year_id, $term_id, $db);
                if ($res) $count++;
            }
            return $count;
        } catch (PDOException $e) {
            error_log("Error generating school invoices: " . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('getInvoiceDetails')) {
    function getInvoiceDetails($invoice_id, $db) {
        try {
            // 1. Fetch main invoice with student details
            $stmt = $db->prepare("SELECT i.*, u.name as student_name, sp.student_id as student_reg_no, 
                                         ay.year_name, t.term_name, c.name as class_name,
                                         (i.total_amount + i.penalty_amount - i.discount_amount) as grand_total
                                  FROM finance_invoices i
                                  JOIN users u ON i.student_id = u.id
                                  JOIN student_profiles sp ON u.id = sp.user_id
                                  JOIN academic_years ay ON i.academic_year_id = ay.id
                                  JOIN academic_terms t ON i.term_id = t.id
                                  LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                                  LEFT JOIN classes c ON sc.class_id = c.id
                                  WHERE i.id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$invoice) return null;

            // 2. Fetch items
            $stmt = $db->prepare("SELECT ii.*, fc.name as category_name 
                                  FROM finance_invoice_items ii
                                  JOIN finance_fee_categories fc ON ii.category_id = fc.id
                                  WHERE ii.invoice_id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Fetch payments
            $stmt = $db->prepare("SELECT * FROM finance_payments 
                                  WHERE invoice_id = :invoice_id 
                                  ORDER BY payment_date DESC");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice['payments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Fetch penalties applied
            $stmt = $db->prepare("SELECT sp.*, p.name as penalty_name 
                                  FROM finance_student_penalties sp
                                  JOIN finance_penalties p ON sp.penalty_id = p.id
                                  WHERE sp.invoice_id = :invoice_id");
            $stmt->execute([':invoice_id' => $invoice_id]);
            $invoice['penalties'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $invoice;
        } catch (PDOException $e) {
            error_log("Error fetching invoice details: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('markOverdueInvoices')) {
    function markOverdueInvoices($db) {
        try {
            $today = date('Y-m-d');
            $stmt = $db->prepare("UPDATE finance_invoices 
                                  SET status = 'overdue' 
                                  WHERE status IN ('pending', 'partially_paid') 
                                    AND due_date < :today");
            return $stmt->execute([':today' => $today]);
        } catch (PDOException $e) {
            error_log("Error marking overdue invoices: " . $e->getMessage());
            return false;
        }
    }
}
