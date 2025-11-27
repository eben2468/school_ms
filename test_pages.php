<?php
// Simple test script to check if pages are accessible
$pages_to_test = [
    'profile.php',
    'logout.php',
    'bulk_import.php',
    'academic/assignments/view.php?id=1',
    'academic/assignments/edit.php?id=1',
    'reports/class.php',
    'library/books/create.php',
    'library/reports.php',
    'library/manage.php',
    'library/borrow.php',
    'transport/routes/create.php',
    'transport/vehicles/create.php',
    'transport/assignments/index.php'
];

echo "<h1>Page Accessibility Test</h1>";
echo "<p>Testing if pages exist and are accessible...</p>";

foreach ($pages_to_test as $page) {
    $file_path = __DIR__ . '/' . $page;
    $file_path = str_replace('?id=1', '', $file_path); // Remove query parameters for file check
    
    if (file_exists($file_path)) {
        echo "<div style='color: green;'>✓ {$page} - File exists</div>";
    } else {
        echo "<div style='color: red;'>✗ {$page} - File missing</div>";
    }
}

echo "<h2>Database Connection Test</h2>";
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    echo "<div style='color: green;'>✓ Database connection successful</div>";
    
    // Test some basic queries
    $tables_to_check = [
        'assignments' => 'SELECT COUNT(*) as count FROM assignments',
        'exams' => 'SELECT COUNT(*) as count FROM exams',
        'book_loans' => 'SELECT COUNT(*) as count FROM book_loans',
        'library_books' => 'SELECT COUNT(*) as count FROM library_books',
        'transport_routes' => 'SELECT COUNT(*) as count FROM transport_routes',
        'transport_vehicles' => 'SELECT COUNT(*) as count FROM transport_vehicles'
    ];
    
    foreach ($tables_to_check as $table => $query) {
        try {
            $stmt = $db->query($query);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<div style='color: green;'>✓ {$table} table - {$result['count']} records</div>";
        } catch (PDOException $e) {
            echo "<div style='color: red;'>✗ {$table} table - Error: " . $e->getMessage() . "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Database connection failed: " . $e->getMessage() . "</div>";
}

echo "<h2>Column Existence Test</h2>";
try {
    // Test for specific columns that were causing issues
    $column_tests = [
        'assignments' => ['status'],
        'exams' => ['title', 'exam_date', 'duration'],
        'book_loans' => ['borrower_id', 'loan_date'],
        'library_books' => ['description', 'publisher', 'publication_year', 'language', 'location'],
        'transport_vehicles' => ['make_model', 'year', 'insurance_number', 'insurance_expiry', 'registration_expiry', 'notes'],
        'transport_routes' => ['description']
    ];
    
    foreach ($column_tests as $table => $columns) {
        foreach ($columns as $column) {
            try {
                $stmt = $db->query("SELECT {$column} FROM {$table} LIMIT 1");
                echo "<div style='color: green;'>✓ {$table}.{$column} - Column exists</div>";
            } catch (PDOException $e) {
                echo "<div style='color: red;'>✗ {$table}.{$column} - Column missing</div>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<div style='color: red;'>✗ Column test failed: " . $e->getMessage() . "</div>";
}
?>
