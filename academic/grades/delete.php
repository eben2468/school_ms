<?php
session_start();
// Only leadership may delete grade records (matches the index action visibility)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if ($id) {
    try {
        $stmt = $db->prepare("DELETE FROM student_academic_records WHERE id = :id");
        $stmt->execute([':id' => $id]);
        header("Location: index.php?msg=" . urlencode("Grade record deleted successfully."));
        exit();
    } catch (PDOException $e) {
        header("Location: index.php?err=" . urlencode("Could not delete grade record."));
        exit();
    }
}
header("Location: index.php");
exit();
