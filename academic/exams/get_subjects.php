<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$class_id = isset($_GET['class_id']) ? $_GET['class_id'] : null;

if (!$class_id) {
    http_response_code(400);
    exit();
}

// Get subjects assigned to the selected class
$query = "SELECT DISTINCT s.id, s.name
          FROM subjects s
          JOIN class_teachers ct ON s.id = ct.subject_id
          WHERE ct.class_id = :class_id
          ORDER BY s.name";

$stmt = $db->prepare($query);
$stmt->bindParam(':class_id', $class_id);
$stmt->execute();

$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no subjects found through class_teachers, fall back to all subjects
if (empty($subjects)) {
    $query = "SELECT id, name FROM subjects ORDER BY name";
    $stmt = $db->query($query);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode($subjects);