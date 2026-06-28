<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'librarian'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Only allow deletion via POST to avoid accidental/crawled GET deletions.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit();
}

$book_id = filter_input(INPUT_POST, 'book_id', FILTER_SANITIZE_NUMBER_INT);
if (!$book_id) {
    header("Location: index.php?error=" . urlencode("No book specified."));
    exit();
}

// Confirm the book exists.
$stmt = $db->prepare("SELECT id, title FROM library_books WHERE id = :id");
$stmt->execute([':id' => $book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$book) {
    header("Location: index.php?error=" . urlencode("Book not found."));
    exit();
}

// Block deletion while copies are still out on loan (borrowed/overdue), so we
// never wipe a live loan for a book a borrower physically still holds.
$active_stmt = $db->prepare("SELECT COUNT(*) FROM book_loans WHERE book_id = :id AND status IN ('borrowed', 'overdue')");
$active_stmt->execute([':id' => $book_id]);
if ((int)$active_stmt->fetchColumn() > 0) {
    header("Location: index.php?error=" . urlencode('Cannot delete "' . $book['title'] . '": it has copies currently on loan. All copies must be returned first.'));
    exit();
}

try {
    // Returned-loan history and any related fines cascade automatically
    // (book_loans.book_id and library_fines.loan_id are ON DELETE CASCADE).
    $del = $db->prepare("DELETE FROM library_books WHERE id = :id");
    $del->execute([':id' => $book_id]);
    header("Location: index.php?deleted=" . urlencode($book['title']));
    exit();
} catch (PDOException $e) {
    error_log("Library book delete error: " . $e->getMessage());
    header("Location: index.php?error=" . urlencode("Could not delete the book. Please try again."));
    exit();
}
