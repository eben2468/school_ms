<?php
/**
 * Draft AI endpoint
 * Receives composition parameters and returns an AI-assisted draft as JSON.
 * Used by the Draft AI assistant in the Communication composition interfaces.
 */
session_start();
header('Content-Type: application/json');

// Only staff roles that can author communications may use the assistant.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You are not authorized to use Draft AI.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../includes/ai_helper.php';

$type = $_POST['content_type'] ?? 'general';

$params = [
    'topic'      => trim($_POST['topic'] ?? ''),
    'tone'       => trim($_POST['tone'] ?? 'formal'),
    'audience'   => trim($_POST['audience'] ?? ''),
    'key_points' => trim($_POST['key_points'] ?? ''),
    'length'     => trim($_POST['length'] ?? 'medium'),
];

if ($params['topic'] === '') {
    echo json_encode(['success' => false, 'message' => 'Please describe what the message is about.']);
    exit();
}

try {
    $result = generateAIDraft($type, $params);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log("Draft AI error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Draft AI could not generate a draft right now.']);
}
