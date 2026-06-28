<?php
/**
 * Finance Management Module - Payment Processing Helper Functions
 */

require_once __DIR__ . '/finance_functions.php';
require_once __DIR__ . '/invoice_functions.php';

if (!function_exists('recordPayment')) {
    function recordPayment($invoice_id, $amount, $method, $reference, $notes, $db) {
        try {
            $db->beginTransaction();

            // 1. Generate receipt number
            $receipt_number = generateReceiptNumber($db);
            
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $user_id = $_SESSION['user_id'] ?? 1; // Default to system user if session not active

            // Auto-generate reference if empty
            if (empty(trim($reference))) {
                $prefix_map = [
                    'cash' => 'CASH',
                    'mobile_money' => 'MOMO',
                    'bank_transfer' => 'BANK',
                    'online' => 'ONLINE'
                ];
                $prefix = $prefix_map[$method] ?? 'REF';
                $reference = $prefix . '-' . strtoupper(date('Ymd')) . '-' . substr(uniqid(), -6);
            }

            // 2. Insert payment record
            $stmt = $db->prepare("INSERT INTO finance_payments (invoice_id, amount, payment_method, reference_number, receipt_number, recorded_by, notes, payment_date) 
                                  VALUES (:invoice_id, :amount, :method, :reference, :receipt_number, :recorded_by, :notes, NOW())");
            $stmt->execute([
                ':invoice_id' => $invoice_id,
                ':amount' => $amount,
                ':method' => $method,
                ':reference' => $reference,
                ':receipt_number' => $receipt_number,
                ':recorded_by' => $user_id,
                ':notes' => $notes
            ]);
            $payment_id = $db->lastInsertId();

            // 3. Insert receipt record
            $stmt = $db->prepare("INSERT INTO finance_receipts (receipt_number, payment_id, generated_by) 
                                  VALUES (:receipt_number, :payment_id, :generated_by)");
            $stmt->execute([
                ':receipt_number' => $receipt_number,
                ':payment_id' => $payment_id,
                ':generated_by' => $user_id
            ]);

            $db->commit();

            // 4. Update invoice status (calculated fields: amount_paid, status)
            updateInvoiceStatus($invoice_id, $db);

            logFinanceAudit('Record Payment', 'Payments', $payment_id, "Recorded payment of $amount via $method against invoice ID $invoice_id. Receipt: $receipt_number", $db);

            return [
                'success' => true,
                'payment_id' => $payment_id,
                'receipt_number' => $receipt_number
            ];
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error recording payment: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}

if (!function_exists('processManualPayment')) {
    function processManualPayment($invoice_id, $amount, $method, $reference, $notes, $db) {
        // Just directly call recordPayment
        return recordPayment($invoice_id, $amount, $method, $reference, $notes, $db);
    }
}

/**
 * Online gateways (Paystack, Flutterwave, Stripe)
 */

if (!function_exists('initializePaystackPayment')) {
    function initializePaystackPayment($invoice_id, $amount, $email, $db) {
        // Simulation / Skeleton for integration
        $reference = 'PAY-' . uniqid();
        try {
            $stmt = $db->prepare("INSERT INTO finance_payment_gateway_log (invoice_id, gateway, reference, amount, status) 
                                  VALUES (:invoice_id, 'paystack', :reference, :amount, 'initiated')");
            $stmt->execute([
                ':invoice_id' => $invoice_id,
                ':reference' => $reference,
                ':amount' => $amount
            ]);
            return [
                'success' => true,
                'reference' => $reference,
                'payment_url' => 'collect_payment.php?simulate_success=1&reference=' . $reference . '&invoice_id=' . $invoice_id . '&amount=' . $amount
            ];
        } catch (PDOException $e) {
            error_log("Paystack init error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('verifyPaystackPayment')) {
    function verifyPaystackPayment($reference, $db) {
        try {
            $stmt = $db->prepare("SELECT * FROM finance_payment_gateway_log WHERE reference = :reference LIMIT 1");
            $stmt->execute([':reference' => $reference]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) return false;
            if ($log['status'] === 'success') return true;

            // In simulation, we mark as successful
            $stmt = $db->prepare("UPDATE finance_payment_gateway_log SET status = 'success' WHERE reference = :reference");
            $stmt->execute([':reference' => $reference]);

            recordPayment($log['invoice_id'], $log['amount'], 'online', $reference, 'Paid online via Paystack', $db);
            return true;
        } catch (PDOException $e) {
            error_log("Paystack verify error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('initializeFlutterwavePayment')) {
    function initializeFlutterwavePayment($invoice_id, $amount, $email, $db) {
        $reference = 'FLW-' . uniqid();
        try {
            $stmt = $db->prepare("INSERT INTO finance_payment_gateway_log (invoice_id, gateway, reference, amount, status) 
                                  VALUES (:invoice_id, 'flutterwave', :reference, :amount, 'initiated')");
            $stmt->execute([
                ':invoice_id' => $invoice_id,
                ':reference' => $reference,
                ':amount' => $amount
            ]);
            return [
                'success' => true,
                'reference' => $reference,
                'payment_url' => 'collect_payment.php?simulate_success=1&reference=' . $reference . '&invoice_id=' . $invoice_id . '&amount=' . $amount
            ];
        } catch (PDOException $e) {
            error_log("Flutterwave init error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('verifyFlutterwavePayment')) {
    function verifyFlutterwavePayment($reference, $db) {
        try {
            $stmt = $db->prepare("SELECT * FROM finance_payment_gateway_log WHERE reference = :reference LIMIT 1");
            $stmt->execute([':reference' => $reference]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) return false;
            if ($log['status'] === 'success') return true;

            $stmt = $db->prepare("UPDATE finance_payment_gateway_log SET status = 'success' WHERE reference = :reference");
            $stmt->execute([':reference' => $reference]);

            recordPayment($log['invoice_id'], $log['amount'], 'online', $reference, 'Paid online via Flutterwave', $db);
            return true;
        } catch (PDOException $e) {
            error_log("Flutterwave verify error: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('initializeStripePayment')) {
    function initializeStripePayment($invoice_id, $amount, $email, $db) {
        $reference = 'STR-' . uniqid();
        try {
            $stmt = $db->prepare("INSERT INTO finance_payment_gateway_log (invoice_id, gateway, reference, amount, status) 
                                  VALUES (:invoice_id, 'stripe', :reference, :amount, 'initiated')");
            $stmt->execute([
                ':invoice_id' => $invoice_id,
                ':reference' => $reference,
                ':amount' => $amount
            ]);
            return [
                'success' => true,
                'reference' => $reference,
                'payment_url' => 'collect_payment.php?simulate_success=1&reference=' . $reference . '&invoice_id=' . $invoice_id . '&amount=' . $amount
            ];
        } catch (PDOException $e) {
            error_log("Stripe init error: " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

if (!function_exists('verifyStripePayment')) {
    function verifyStripePayment($reference, $db) {
        try {
            $stmt = $db->prepare("SELECT * FROM finance_payment_gateway_log WHERE reference = :reference LIMIT 1");
            $stmt->execute([':reference' => $reference]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$log) return false;
            if ($log['status'] === 'success') return true;

            $stmt = $db->prepare("UPDATE finance_payment_gateway_log SET status = 'success' WHERE reference = :reference");
            $stmt->execute([':reference' => $reference]);

            recordPayment($log['invoice_id'], $log['amount'], 'online', $reference, 'Paid online via Stripe', $db);
            return true;
        } catch (PDOException $e) {
            error_log("Stripe verify error: " . $e->getMessage());
            return false;
        }
    }
}
