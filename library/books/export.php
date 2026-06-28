<?php
session_start();
require_once '../../includes/access_control.php';
requireModuleRole('library');

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Same filters as the library listing, so "Export" reflects what the user sees.
$search              = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$category_filter     = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING) ?: '';
$availability_filter = filter_input(INPUT_GET, 'availability', FILTER_SANITIZE_STRING) ?: '';

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(title LIKE :search OR author LIKE :search OR isbn LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($category_filter) {
    $where_conditions[] = "category = :category";
    $params[':category'] = $category_filter;
}
if ($availability_filter === 'available') {
    $where_conditions[] = "copies_available > 0";
} elseif ($availability_filter === 'borrowed') {
    $where_conditions[] = "copies_available = 0";
}
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$query = "SELECT title, author, isbn, category, publisher, publication_year,
                 total_copies, copies_available
          FROM library_books
          $where_clause
          ORDER BY title";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stream as CSV download.
$filename = 'library_books_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel renders accented characters correctly.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['Title', 'Author', 'ISBN', 'Category', 'Publisher', 'Year', 'Total Copies', 'Available', 'On Loan', 'Status']);

foreach ($books as $b) {
    $total     = (int)($b['total_copies'] ?? 0);
    $available = (int)$b['copies_available'];
    $on_loan   = max(0, $total - $available);
    $status    = $available > 0 ? 'Available' : 'Out of Stock';
    fputcsv($out, [
        $b['title'],
        $b['author'],
        $b['isbn'],
        $b['category'],
        $b['publisher'],
        $b['publication_year'],
        $total,
        $available,
        $on_loan,
        $status,
    ]);
}

fclose($out);
exit();
