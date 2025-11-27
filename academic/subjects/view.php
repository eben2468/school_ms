<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$id) {
    header("Location: index.php");
    exit();
}

// Get subject data
$query = "SELECT * FROM subjects WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    header("Location: index.php");
    exit();
}

$title = "View Subject";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800"><?php echo htmlspecialchars($subject['name']); ?></h1>
                <div class="space-x-2">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Subjects
                    </a>
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="edit.php?id=<?php echo $id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-edit mr-2"></i> Edit Subject
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Subject Details -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <dl class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Subject Code</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($subject['code']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Created At</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo date('F j, Y', strtotime($subject['created_at'])); ?></dd>
                        </div>
                        <div class="md:col-span-2">
                            <dt class="text-sm font-medium text-gray-500">Description</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($subject['description'] ?: 'No description provided.'); ?></dd>
                        </div>
                    </dl>
                </div>
            </div>

            <!-- Class Assignments -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Class Assignments</h2>
                </div>
                <div class="p-6">
                    <?php
                    $query = "SELECT c.name as class_name, c.grade_level, u.name as teacher_name,
                            COALESCE(COUNT(DISTINCT sa.student_id), 0) as total_students
                            FROM class_teachers ct 
                            JOIN classes c ON ct.class_id = c.id 
                            JOIN users u ON ct.teacher_id = u.id 
                            LEFT JOIN student_classes sa ON c.id = sa.class_id
                            WHERE ct.subject_id = :subject_id
                            GROUP BY c.id, c.name, c.grade_level, u.name";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':subject_id', $id);
                    $stmt->execute();
                    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (empty($assignments)): ?>
                    <p class="text-gray-500">This subject is not currently assigned to any classes.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Class</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Grade Level</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Teacher</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total Students</th>
                                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($assignments as $assignment): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['grade_level']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($assignment['teacher_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo $assignment['total_students']; ?> students</td>
                                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <a href="../assignments/index.php?subject_id=<?php echo $id; ?>&class=<?php echo $assignment['class_name']; ?>" 
                                           class="text-blue-600 hover:text-blue-900 mr-3">View Assignments</a>
                                        <a href="../exams/index.php?subject_id=<?php echo $id; ?>&class=<?php echo $assignment['class_name']; ?>" 
                                           class="text-indigo-600 hover:text-indigo-900">View Exams</a>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Activities -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-800">Recent Activities</h2>
                </div>
                <div class="p-6">
                    <?php
                    $query = "SELECT 'assignment' as type, a.title, a.created_at, u.name as teacher_name
                            FROM assignments a
                            JOIN users u ON a.teacher_id = u.id
                            WHERE a.subject_id = :subject_id
                            UNION ALL
                            SELECT 'exam' as type, e.title as title, e.created_at, COALESCE(u.name, 'Unknown Teacher') as teacher_name
                            FROM exams e
                            LEFT JOIN class_teachers ct ON e.class_id = ct.class_id AND e.subject_id = ct.subject_id
                            LEFT JOIN users u ON ct.teacher_id = u.id
                            WHERE e.subject_id = :subject_id
                            ORDER BY created_at DESC
                            LIMIT 10";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':subject_id', $id);
                    $stmt->execute();
                    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (empty($activities)): ?>
                    <p class="text-gray-500">No recent activities found for this subject.</p>
                    <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($activities as $activity): ?>
                        <div class="flex items-start">
                            <div class="flex-shrink-0">
                                <i class="fas <?php echo $activity['type'] === 'assignment' ? 'fa-book' : 'fa-file-alt'; ?> 
                                   text-<?php echo $activity['type'] === 'assignment' ? 'blue' : 'purple'; ?>-500 text-lg"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-900">
                                    New <?php echo ucfirst($activity['type']); ?>: <?php echo htmlspecialchars($activity['title']); ?>
                                </p>
                                <p class="text-sm text-gray-500">
                                    By <?php echo htmlspecialchars($activity['teacher_name']); ?> on 
                                    <?php echo date('F j, Y', strtotime($activity['created_at'])); ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>