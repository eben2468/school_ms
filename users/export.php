<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    http_response_code(403);
    exit('Access denied');
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get export parameters
$format = $_GET['format'] ?? 'csv';
$search = $_GET['search'] ?? '';
$role_filter = $_GET['role'] ?? '';

// Build query conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(name LIKE :search OR email LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($role_filter) {
    $where_conditions[] = "role = :role";
    $params[':role'] = $role_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch ALL users (no pagination for export)
$query = "SELECT id, name, email, role, status, created_at FROM users $where_clause ORDER BY created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate filename with timestamp
$timestamp = date('Y-m-d_H-i-s');
$filter_suffix = '';
if ($search) {
    $filter_suffix .= '_search-' . preg_replace('/[^a-zA-Z0-9]/', '', $search);
}
if ($role_filter) {
    $filter_suffix .= '_role-' . $role_filter;
}

if ($format === 'csv') {
    $filename = "users_export_{$timestamp}{$filter_suffix}.csv";
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Create file pointer connected to the output stream
    $output = fopen('php://output', 'w');
    
    // Add CSV headers
    fputcsv($output, [
        'ID',
        'Name', 
        'Email', 
        'Role', 
        'Status', 
        'Created Date',
        'Created Time'
    ]);
    
    // Add data rows
    foreach ($users as $user) {
        fputcsv($output, [
            $user['id'],
            $user['name'],
            $user['email'],
            formatRoleName($user['role']),
            ucfirst($user['status']),
            date('Y-m-d', strtotime($user['created_at'])),
            date('H:i:s', strtotime($user['created_at']))
        ]);
    }
    
    fclose($output);
    exit();
    
} elseif ($format === 'excel') {
    $filename = "users_export_{$timestamp}{$filter_suffix}.xls";
    
    // Set headers for Excel download
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Start Excel content
    echo '<table border="1">';
    echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
    echo '<td>ID</td>';
    echo '<td>Name</td>';
    echo '<td>Email</td>';
    echo '<td>Role</td>';
    echo '<td>Status</td>';
    echo '<td>Created Date</td>';
    echo '<td>Created Time</td>';
    echo '</tr>';
    
    foreach ($users as $user) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($user['id']) . '</td>';
        echo '<td>' . htmlspecialchars($user['name']) . '</td>';
        echo '<td>' . htmlspecialchars($user['email']) . '</td>';
        echo '<td>' . htmlspecialchars(formatRoleName($user['role'])) . '</td>';
        echo '<td>' . htmlspecialchars(ucfirst($user['status'])) . '</td>';
        echo '<td>' . date('Y-m-d', strtotime($user['created_at'])) . '</td>';
        echo '<td>' . date('H:i:s', strtotime($user['created_at'])) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    exit();
    
} elseif ($format === 'json') {
    $filename = "users_export_{$timestamp}{$filter_suffix}.json";
    
    // Set headers for JSON download
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    // Prepare data for JSON
    $export_data = [
        'export_info' => [
            'exported_at' => date('Y-m-d H:i:s'),
            'exported_by' => $_SESSION['user_id'],
            'total_records' => count($users),
            'filters_applied' => [
                'search' => $search ?: null,
                'role' => $role_filter ?: null
            ]
        ],
        'users' => []
    ];
    
    foreach ($users as $user) {
        $export_data['users'][] = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'role_display' => formatRoleName($user['role']),
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'created_date' => date('Y-m-d', strtotime($user['created_at'])),
            'created_time' => date('H:i:s', strtotime($user['created_at']))
        ];
    }
    
    echo json_encode($export_data, JSON_PRETTY_PRINT);
    exit();
    
} else {
    http_response_code(400);
    exit('Invalid format specified');
}
?>
