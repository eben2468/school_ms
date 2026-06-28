<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $share_id = $input['share_id'] ?? null;
    
    if (!$share_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing share ID']);
        exit();
    }
    
    // Retrieve share to verify ownership
    $query = "SELECT ds.*, d.uploaded_by 
              FROM document_shares ds
              JOIN documents d ON ds.document_id = d.id
              WHERE ds.id = :share_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':share_id', $share_id, PDO::PARAM_INT);
    $stmt->execute();
    $share = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$share) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Share record not found']);
        exit();
    }
    
    // Only the user who shared the file or the file owner can revoke it
    if ($share['shared_by'] != $_SESSION['user_id'] && $share['uploaded_by'] != $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You do not have permission to revoke this share']);
        exit();
    }
    
    // Delete the share record
    $delete_query = "DELETE FROM document_shares WHERE id = :share_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':share_id', $share_id, PDO::PARAM_INT);
    
    if ($delete_stmt->execute()) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete share record']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
