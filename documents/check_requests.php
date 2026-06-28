<?php
require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$stmt = $db->query("SELECT tr.id, tr.student_id, u.name as student_name, tr.status, tr.generated_file_path FROM transcript_requests tr JOIN users u ON tr.student_id = u.id");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Request ID: " . $row['id'] . " | Student: " . $row['student_name'] . " | Status: " . $row['status'] . " | File: " . $row['generated_file_path'] . "\n";
}
?>
