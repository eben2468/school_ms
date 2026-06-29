<?php
/**
 * Paystack payment verification endpoint.
 * --------------------------------------------------------------------------
 * Called from the browser after a successful Paystack inline checkout. It is
 * the trust boundary: the client only sends a reference + invoice id, and this
 * endpoint independently verifies the transaction with Paystack (secret key),
 * re-checks that the signed-in user is allowed to pay that invoice, then records
 * the payment for the AMOUNT PAYSTACK ACTUALLY CONFIRMS (never a client value).
 * Idempotent: a reference already recorded is reported as success, not doubled.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in.']);
    exit();
}

require_once __DIR__ . '/../../includes/csrf.php';
csrf_require(); // token auto-attached to same-origin fetch by footer.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/paystack_helper.php';
require_once __DIR__ . '/../includes/payment_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? '';

// --- Input ---------------------------------------------------------------
$input      = json_decode(file_get_contents('php://input'), true) ?: [];
$reference  = trim((string)($input['reference'] ?? ''));
$invoice_id = (int)($input['invoice_id'] ?? 0);

if ($reference === '' || $invoice_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing payment reference or invoice.']);
    exit();
}

if (!isPaystackEnabled()) {
    echo json_encode(['success' => false, 'message' => 'Online payment is not enabled.']);
    exit();
}

try {
    // --- Load the invoice -------------------------------------------------
    $stmt = $db->prepare("SELECT id, student_id, total_amount, penalty_amount, discount_amount, amount_paid, status
                          FROM finance_invoices WHERE id = :id");
    $stmt->execute([':id' => $invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
        exit();
    }

    // --- Authorisation: who may pay this invoice? -------------------------
    $authorised = false;
    if (in_array($role, ['super_admin', 'school_admin', 'principal', 'accountant'], true)) {
        $authorised = true; // staff collecting a payment
    } elseif ($role === 'student') {
        $authorised = ((int)$invoice['student_id'] === (int)$user_id); // own invoice
    } elseif ($role === 'parent') {
        // Two parent-child mappings exist in the system; accept either.
        $chk = $db->prepare("SELECT 1 FROM parent_students WHERE parent_id = :p AND student_id = :s LIMIT 1");
        $chk->execute([':p' => $user_id, ':s' => $invoice['student_id']]);
        $authorised = (bool)$chk->fetchColumn();
        if (!$authorised) {
            try {
                $chk2 = $db->prepare("SELECT 1 FROM student_profiles WHERE parent_id = :p AND user_id = :s LIMIT 1");
                $chk2->execute([':p' => $user_id, ':s' => $invoice['student_id']]);
                $authorised = (bool)$chk2->fetchColumn();
            } catch (PDOException $e) { /* table/column may differ on some tenants */ }
        }
    }
    if (!$authorised) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not allowed to pay this invoice.']);
        exit();
    }

    // --- Idempotency: already recorded? -----------------------------------
    $dupe = $db->prepare("SELECT receipt_number FROM finance_payments WHERE reference_number = :ref LIMIT 1");
    $dupe->execute([':ref' => $reference]);
    if ($existing = $dupe->fetch(PDO::FETCH_ASSOC)) {
        echo json_encode([
            'success' => true,
            'message' => 'Payment already recorded.',
            'receipt_number' => $existing['receipt_number'],
            'duplicate' => true,
        ]);
        exit();
    }

    // --- Verify with Paystack (secret key, server-side) -------------------
    $verify = paystackVerifyTransaction($reference);
    if (empty($verify['success'])) {
        echo json_encode(['success' => false, 'message' => $verify['message'] ?? 'Verification failed.']);
        exit();
    }
    $data = $verify['data'];

    // Confirm currency matches the school's configured currency.
    $config = getPaystackConfig();
    if (strtoupper($data['currency'] ?? '') !== $config['currency']) {
        echo json_encode(['success' => false, 'message' => 'Payment currency mismatch. Please contact the school.']);
        exit();
    }

    // Record the amount Paystack actually captured (minor unit -> major unit).
    $amountPaid = ((int)($data['amount'] ?? 0)) / 100;
    if ($amountPaid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid amount on the verified transaction.']);
        exit();
    }

    $payer = $data['customer']['email'] ?? ($_SESSION['email'] ?? '');
    $notes = 'Paid online via Paystack (ref: ' . $reference . ', payer: ' . $payer . ').';

    $result = recordPayment($invoice_id, $amountPaid, 'online', $reference, $notes, $db);
    if (empty($result['success'])) {
        echo json_encode(['success' => false, 'message' => 'Payment verified but could not be recorded: ' . ($result['message'] ?? '')]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Payment successful.',
        'receipt_number' => $result['receipt_number'] ?? null,
        'amount' => $amountPaid,
    ]);
} catch (Throwable $e) {
    error_log('Paystack verify endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A server error occurred. If you were charged, contact the school with reference ' . $reference . '.',
    ]);
}
