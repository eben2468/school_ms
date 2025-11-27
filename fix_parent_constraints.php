<?php
/**
 * Database Repair Script for Parent-Student Relationships
 * This script fixes foreign key constraint issues in student_profiles table
 */

require_once 'config/database.php';

// Initialize results array
$results = [];
$errors = [];

try {
    // Check for invalid parent_id references
    $check_query = "
        SELECT sp.id, sp.user_id, sp.parent_id, u.name as student_name
        FROM student_profiles sp
        LEFT JOIN users u ON sp.user_id = u.id
        WHERE sp.parent_id IS NOT NULL 
        AND sp.parent_id NOT IN (
            SELECT id FROM users WHERE role = 'parent' AND status = 'active'
        )
    ";
    
    $stmt = $db->prepare($check_query);
    $stmt->execute();
    $invalid_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($invalid_records)) {
        $results[] = "✅ No invalid parent references found. Database is clean!";
    } else {
        $results[] = "⚠️ Found " . count($invalid_records) . " student records with invalid parent references:";
        
        foreach ($invalid_records as $record) {
            $results[] = "   - Student: {$record['student_name']} (ID: {$record['user_id']}) has invalid parent_id: {$record['parent_id']}";
        }
        
        // Option 1: Set invalid parent_id to NULL
        $fix_query = "
            UPDATE student_profiles 
            SET parent_id = NULL 
            WHERE parent_id IS NOT NULL 
            AND parent_id NOT IN (
                SELECT id FROM users WHERE role = 'parent' AND status = 'active'
            )
        ";
        
        $fix_stmt = $db->prepare($fix_query);
        $affected_rows = $fix_stmt->execute();
        
        if ($fix_stmt->rowCount() > 0) {
            $results[] = "✅ Fixed " . $fix_stmt->rowCount() . " records by setting invalid parent_id to NULL";
        }
    }
    
    // Check for students without any parent information
    $orphan_query = "
        SELECT sp.id, sp.user_id, u.name as student_name
        FROM student_profiles sp
        LEFT JOIN users u ON sp.user_id = u.id
        WHERE sp.parent_id IS NULL 
        AND (sp.guardian_name IS NULL OR sp.guardian_name = '')
        AND (sp.guardian_phone IS NULL OR sp.guardian_phone = '')
    ";
    
    $orphan_stmt = $db->prepare($orphan_query);
    $orphan_stmt->execute();
    $orphan_records = $orphan_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($orphan_records)) {
        $results[] = "⚠️ Found " . count($orphan_records) . " students without any parent/guardian information:";
        foreach ($orphan_records as $record) {
            $results[] = "   - Student: {$record['student_name']} (ID: {$record['user_id']})";
        }
        $results[] = "💡 Consider adding guardian information for these students.";
    }
    
    // Show available parents
    $parent_query = "SELECT id, name, email FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name";
    $parent_stmt = $db->prepare($parent_query);
    $parent_stmt->execute();
    $available_parents = $parent_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results[] = "\n📋 Available Parent Users (" . count($available_parents) . " total):";
    if (empty($available_parents)) {
        $results[] = "   ❌ No parent users found! Create parent users first.";
        $results[] = "   💡 Go to Users > Add New User and create users with role 'parent'";
    } else {
        foreach ($available_parents as $parent) {
            $results[] = "   - {$parent['name']} ({$parent['email']}) - ID: {$parent['id']}";
        }
    }
    
    // Check database constraints
    $constraint_query = "
        SELECT 
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE 
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'student_profiles'
        AND CONSTRAINT_NAME LIKE '%parent%'
    ";
    
    $constraint_stmt = $db->prepare($constraint_query);
    $constraint_stmt->execute();
    $constraints = $constraint_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($constraints)) {
        $results[] = "\n🔗 Database Constraints:";
        foreach ($constraints as $constraint) {
            $results[] = "   - {$constraint['CONSTRAINT_NAME']}: References {$constraint['REFERENCED_TABLE_NAME']}.{$constraint['REFERENCED_COLUMN_NAME']}";
        }
    }
    
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Repair - Parent Constraints</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-4xl mx-auto">
            <div class="bg-white rounded-lg shadow-lg p-6">
                <h1 class="text-2xl font-bold text-gray-800 mb-6">
                    <i class="fas fa-tools text-blue-600 mr-2"></i>
                    Database Repair - Parent Constraints
                </h1>
                
                <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <h3 class="font-bold">Errors:</h3>
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <div class="bg-gray-50 rounded-lg p-4 mb-6">
                    <h3 class="font-semibold text-gray-800 mb-3">Repair Results:</h3>
                    <div class="space-y-2">
                        <?php foreach ($results as $result): ?>
                        <div class="text-sm font-mono <?php echo strpos($result, '✅') !== false ? 'text-green-600' : (strpos($result, '⚠️') !== false ? 'text-orange-600' : (strpos($result, '❌') !== false ? 'text-red-600' : 'text-gray-700')); ?>">
                            <?php echo htmlspecialchars($result); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                    <h3 class="font-semibold text-blue-800 mb-2">
                        <i class="fas fa-info-circle mr-2"></i>Next Steps:
                    </h3>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li>• If no parent users exist, create them in <strong>Users > Add New User</strong></li>
                        <li>• Set the role to "parent" and status to "active"</li>
                        <li>• Then try enrolling students again</li>
                        <li>• You can leave parent selection empty and use guardian information instead</li>
                    </ul>
                </div>
                
                <div class="flex space-x-4">
                    <a href="students/enroll.php" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-user-plus mr-2"></i>Enroll Student
                    </a>
                    <a href="users/create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-user-plus mr-2"></i>Create Parent User
                    </a>
                    <a href="dashboard.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-home mr-2"></i>Dashboard
                    </a>
                    <button onclick="location.reload()" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-refresh mr-2"></i>Run Again
                    </button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
